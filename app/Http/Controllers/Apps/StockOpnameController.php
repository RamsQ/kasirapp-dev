<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Ingredient;
use App\Models\StockOpname;
use App\Models\Expense;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use App\Exports\ProductOpnameTemplateExport;
use App\Exports\IngredientOpnameTemplateExport;
use App\Imports\StockOpnameImport;
use Maatwebsite\Excel\Facades\Excel;

class StockOpnameController extends Controller
{
    /**
     * Menampilkan daftar produk, bahan baku, dan riwayat opname.
     */
    public function index(Request $request)
    {
        // 1. Data Produk Jadi (Pagination khusus tab produk)
        $products = Product::where('type', 'single')
            ->select('id', 'title', 'stock', 'barcode', 'buy_price', 'unit')
            ->when($request->search && $request->tab === 'products', function($query) use ($request) {
                $query->where('title', 'like', '%' . $request->search . '%')
                      ->orWhere('barcode', 'like', '%' . $request->search . '%');
            })
            ->orderBy('title', 'asc')
            ->paginate(10, ['*'], 'products_page')
            ->withQueryString();

        // 2. Data Bahan Baku (Pagination khusus tab bahan baku)
        $ingredients = Ingredient::select('id', 'name', 'stock', 'buy_price', 'unit')
            ->when($request->search && $request->tab === 'ingredients', function($query) use ($request) {
                $query->where('name', 'like', '%' . $request->search . '%');
            })
            ->orderBy('name', 'asc')
            ->paginate(10, ['*'], 'ingredients_page')
            ->withQueryString();

        // 3. Riwayat Stock Opname (Pagination khusus tab riwayat)
        $history = StockOpname::with([
                'product', 
                'ingredient',
                'user' => function($query) {
                    $query->withTrashed();
                }
            ])
            ->when($request->start_date, function($query, $start_date) {
                $query->whereDate('created_at', '>=', $start_date);
            })
            ->when($request->end_date, function($query, $end_date) {
                $query->whereDate('created_at', '<=', $end_date);
            })
            ->latest()
            ->paginate(10, ['*'], 'history_page')
            ->withQueryString();

        return Inertia::render('Dashboard/StockOpname/Index', [
            'products'    => $products,
            'ingredients' => $ingredients,
            'history'     => $history,
            'filters'     => $request->all(['search', 'start_date', 'end_date', 'tab']),
        ]);
    }

    /**
     * Menyimpan penyesuaian stok masal (Bulk Opname).
     */
    public function store(Request $request)
    {
        $request->validate([
            'adjustments' => 'required|array',
            'adjustments.*.id'           => 'required',
            'adjustments.*.type'         => 'required|in:products,ingredients',
            'adjustments.*.stock_actual' => 'nullable|numeric|min:0',
            'adjustments.*.reason'       => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($request) {
            foreach ($request->adjustments as $item) {
                // Proses hanya jika stock_actual diisi angka
                if (isset($item['stock_actual']) && $item['stock_actual'] !== '') {
                    
                    $id = $item['id'];
                    $type = $item['type'];
                    $stockActual = (float)$item['stock_actual'];
                    
                    // Identifikasi Model & Data Foreign Key
                    if ($type === 'products') {
                        $model = Product::lockForUpdate()->findOrFail($id);
                        $dataKeys = ['product_id' => $id, 'ingredient_id' => null];
                        $itemName = $model->title;
                    } else {
                        $model = Ingredient::lockForUpdate()->findOrFail($id);
                        $dataKeys = ['product_id' => null, 'ingredient_id' => $id];
                        $itemName = $model->name;
                    }
                    
                    $stockSystem = (float)$model->stock;
                    $difference  = $stockActual - $stockSystem;

                    // Lewati jika tidak ada perubahan fisik vs sistem
                    if ($difference === 0) continue;

                    // A. Simpan Log Opname
                    StockOpname::create(array_merge($dataKeys, [
                        'user_id'      => auth()->id(),
                        'stock_system' => $stockSystem,
                        'stock_actual' => $stockActual,
                        'difference'   => $difference,
                        'reason'       => $item['reason'] ?? 'Bulk Opname (Tabel)',
                    ]));

                    // B. Otomatisasi Keuangan (Expense) jika terjadi penyusutan/kehilangan
                    if ($difference < 0) {
                        $qtyLost = abs($difference);
                        $totalLoss = (float)$model->buy_price * $qtyLost;

                        Expense::create([
                            'name'         => "Penyusutan " . ($type === 'products' ? "Produk" : "Bahan Baku"),
                            'account_name' => 'Beban Penurunan Nilai Persediaan',
                            'category'     => 'Kerugian Stok',
                            'amount'       => $totalLoss,
                            'description'  => "Otomatis (Opname): Penyusutan {$itemName} sebanyak {$qtyLost}. Alasan: " . ($item['reason'] ?? 'Tidak ada keterangan'),
                            'date'         => now(),
                            'user_id'      => auth()->id(),
                        ]);
                    }

                    // C. Update Stok Utama
                    $model->update(['stock' => $stockActual]);
                }
            }
        });

        return redirect()->route('stock_opnames.index')->with('success', 'Stok berhasil disesuaikan secara akurat!');
    }

    /**
     * Export Format Excel Khusus Produk
     */
    public function exportProductTemplate()
    {
        return Excel::download(new ProductOpnameTemplateExport, 'Format_Opname_Produk_'.date('Y-m-d').'.xlsx');
    }

    /**
     * Export Format Excel Khusus Bahan Baku
     */
    public function exportIngredientTemplate()
    {
        return Excel::download(new IngredientOpnameTemplateExport, 'Format_Opname_Bahan_'.date('Y-m-d').'.xlsx');
    }

    /**
     * Import Data Opname (Support Produk & Bahan)
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv|max:2048'
        ]);

        try {
            Excel::import(new StockOpnameImport, $request->file('file'));
            return redirect()->route('stock_opnames.index', ['tab' => 'history'])->with('success', 'Import Excel Opname Berhasil!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Gagal Import: ' . $e->getMessage());
        }
    }

    public function destroy(StockOpname $stock_opname)
    {
        $stock_opname->delete();
        return redirect()->route('stock_opnames.index')->with('success', 'Riwayat opname berhasil dihapus!');
    }
}