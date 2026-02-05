<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\ProductUnit;
use App\Models\StockBatch; 
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;
use App\Imports\ProductsImport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::when($request->search, function ($query, $search) {
            $query->where('title', 'like', '%' . $search . '%')
                ->orWhere('barcode', 'like', '%' . $search . '%');
        })
        ->with(['category', 'units'])
        ->latest()
        ->paginate(10)
        ->withQueryString();

        return Inertia::render('Dashboard/Products/Index', [
            'products' => $products,
            'search'   => $request->search
        ]);
    }

    public function create()
    {
        $categories = Category::all();
        $products = Product::where('type', 'single')->with('units')->get();

        return Inertia::render('Dashboard/Products/Create', [
            'categories' => $categories,
            'products'   => $products
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'image'          => 'nullable|image|mimes:jpeg,jpg,png|max:2048',
            'barcode'        => ['nullable', Rule::unique('products')->whereNull('deleted_at')],
            'title'          => 'required',
            'category_id'    => 'required',
            'buy_price'      => 'required|numeric',
            'sell_price'     => 'required|numeric',
            'type'           => 'required|in:single,bundle',
            // [REPAIR] Stok dibuat nullable agar tidak wajib diisi saat Create
            'stock'          => 'nullable|numeric|min:0',
            'unit'           => 'required|string|max:20', 
            
            'units'               => 'nullable|array',
            'units.*.unit_name'  => 'required_with:units|string',
            'units.*.conversion' => 'required_with:units|numeric|min:0.01',
            'units.*.sell_price' => 'required_with:units|numeric',

            'bundle_items'                  => 'nullable|required_if:type,bundle|array',
            'bundle_items.*.item_id'        => 'required_if:type,bundle', 
            'bundle_items.*.qty'            => 'required_if:type,bundle|numeric|min:0.01',
            'bundle_items.*.product_unit_id'=> 'nullable|exists:product_units,id', 
        ]);

        DB::transaction(function () use ($request) {
            $imageName = null;
            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $image->storeAs('public/products', $image->hashName());
                $imageName = $image->hashName();
            }

            // [REPAIR] Pastikan nilai stok default 0 jika input dikosongkan
            $inputStock = ($request->type === 'bundle' || is_null($request->stock)) ? 0 : $request->stock;

            $product = Product::create([
                'image'        => $imageName,
                'barcode'      => $request->barcode,
                'title'        => $request->title,
                'description'  => $request->description ?? '-',
                'category_id'  => $request->category_id,
                'buy_price'    => $request->buy_price,
                'sell_price'   => $request->sell_price,
                'stock'        => $inputStock,
                'unit'         => $request->unit,
                'expired_date' => $request->expired_date,
                'type'         => $request->type,
            ]);

            // Simpan Batch Awal hanya jika stok lebih dari 0
            if ($request->type === 'single' && $inputStock > 0) {
                $product->stock_batches()->create([
                    'qty_in'        => $inputStock,
                    'qty_remaining' => $inputStock,
                    'buy_price'     => $request->buy_price,
                ]);
            }

            // Simpan Multi-Satuan
            if ($request->has('units')) {
                foreach ($request->units as $unit) {
                    $product->units()->create([
                        'unit_name'  => $unit['unit_name'],
                        'conversion' => $unit['conversion'],
                        'sell_price' => $unit['sell_price'],
                    ]);
                }
            }

            // Simpan Bundling Items
            if ($request->type === 'bundle' && $request->bundle_items) {
                foreach ($request->bundle_items as $item) {
                    if(!empty($item['item_id'])) {
                        $product->bundle_items()->attach($item['item_id'], [
                            'qty'             => $item['qty'],
                            'product_unit_id' => $item['product_unit_id'] ?? null 
                        ]);
                    }
                }
            }
        });

        return redirect()->route('products.index')->with('success', 'Produk Berhasil Disimpan!');
    }

    public function edit(Product $product)
    {
        $categories = Category::all();
        $products = Product::where('type', 'single')->where('id', '!=', $product->id)->with('units')->get();
        
        $product->load(['bundle_items.units', 'units', 'stock_batches' => function($query) {
            $query->where('qty_remaining', '>', 0)->orderBy('created_at', 'asc');
        }]);

        return Inertia::render('Dashboard/Products/Edit', [
            'product'    => $product,
            'categories' => $categories,
            'products'   => $products
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $request->validate([
            'barcode'      => ['nullable', Rule::unique('products')->ignore($product->id)->whereNull('deleted_at')],
            'title'        => 'required',
            'category_id'  => 'required',
            'buy_price'    => 'required|numeric',
            'sell_price'   => 'required|numeric',
            'type'         => 'required|in:single,bundle',
            // [REPAIR] Update stok juga nullable
            'stock'        => 'nullable|numeric|min:0',
            'unit'         => 'required|string|max:20', 
            
            'units'               => 'nullable|array',
            'units.*.unit_name'  => 'required_with:units|string',
            'units.*.conversion' => 'required_with:units|numeric|min:0.01',
            'units.*.sell_price' => 'required_with:units|numeric',
            
            'bundle_items'                   => 'nullable|required_if:type,bundle|array',
            'bundle_items.*.item_id'         => 'required_if:type,bundle',
            'bundle_items.*.product_unit_id' => 'nullable|exists:product_units,id',
        ]);

        DB::transaction(function () use ($request, $product) {
            if ($request->hasFile('image')) {
                if ($product->getRawOriginal('image')) Storage::delete('public/products/' . $product->getRawOriginal('image'));
                $image = $request->file('image');
                $image->storeAs('public/products', $image->hashName());
                $product->image = $image->hashName();
            }

            $oldStock = (float) $product->stock;
            // [REPAIR] Handle null stock input
            $newStock = $request->type === 'bundle' ? 0 : (float) ($request->stock ?? 0);

            if ($request->type === 'single' && $newStock > $oldStock) {
                $addedQty = $newStock - $oldStock;
                $product->stock_batches()->create([
                    'qty_in'        => $addedQty,
                    'qty_remaining' => $addedQty,
                    'buy_price'     => $request->buy_price, 
                ]);
            }

            $product->update([
                'image'        => $product->image,
                'barcode'      => $request->barcode,
                'title'        => $request->title,
                'description'  => $request->description ?? '-',
                'category_id'  => $request->category_id,
                'buy_price'    => $request->buy_price,
                'sell_price'   => $request->sell_price,
                'stock'        => $newStock,
                'unit'         => $request->unit,
                'expired_date' => $request->expired_date,
                'type'         => $request->type,
            ]);

            // Sync Multi-Satuan
            $product->units()->delete();
            if ($request->has('units')) {
                foreach ($request->units as $unit) {
                    if (!empty($unit['unit_name'])) {
                        $product->units()->create([
                            'unit_name'  => $unit['unit_name'],
                            'conversion' => $unit['conversion'],
                            'sell_price' => $unit['sell_price'],
                        ]);
                    }
                }
            }

            // Sync Bundling Items
            if ($request->type === 'bundle' && $request->bundle_items) {
                $syncData = [];
                foreach ($request->bundle_items as $item) {
                    if(!empty($item['item_id'])) {
                        $syncData[$item['item_id']] = [
                            'qty'             => $item['qty'],
                            'product_unit_id' => $item['product_unit_id'] ?? null
                        ];
                    }
                }
                $product->bundle_items()->sync($syncData);
            } else {
                $product->bundle_items()->detach();
            }
        });

        return redirect()->route('products.index')->with('success', 'Produk Berhasil Diperbarui!');
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        try {
            $product->delete();
            return redirect()->route('products.index')->with('success', 'Produk Berhasil Dihapus!');
        } catch (QueryException $e) {
            return back()->with('error', 'Gagal hapus: Produk terikat dengan data transaksi.');
        }
    }

    public function template()
    {
        try {
            $fileName = 'template_produk_' . now()->format('Y-m-d') . '.xlsx';
            $export = new class implements \Maatwebsite\Excel\Concerns\FromArray, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\ShouldAutoSize {
                public function headings(): array {
                    return ['barcode', 'title', 'category', 'description', 'buy_price', 'sell_price', 'stock', 'expired_date', 'unit'];
                }
                public function array(): array {
                    return [['8991001', 'Nama Barang', 'Makanan', 'Keterangan', '5000', '7000', '100', date('Y-m-d'), 'Pcs']];
                }
            };
            return Excel::download($export, $fileName);
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal: ' . $e->getMessage());
        }
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|mimes:xlsx,xls']);
        try {
            Excel::import(new ProductsImport, $request->file('file'));
            return back()->with('success', 'Data produk berhasil diimport!');
        } catch (\Exception $e) {
            return back()->with('error', 'Gagal import: ' . $e->getMessage());
        }
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'exists:products,id',
        ]);

        try {
            Product::destroy($request->ids);
            return back()->with('success', 'Produk terpilih berhasil dihapus!');
        } catch (\Exception $e) {
            return back()->with('error', 'Beberapa produk gagal dihapus.');
        }
    }
}