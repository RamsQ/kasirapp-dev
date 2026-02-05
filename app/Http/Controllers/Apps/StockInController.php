<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Models\StockBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use App\Exports\StockInHistoryExport;
use App\Exports\ProductTemplateExport;
use App\Exports\IngredientTemplateExport; 
use Maatwebsite\Excel\Facades\Excel;

class StockInController extends Controller
{
    /**
     * Menampilkan halaman utama Stock In.
     */
    public function index(Request $request)
    {
        $products = Product::when($request->search, function($query, $search) {
                $query->where('title', 'like', '%'. $search . '%')
                      ->orWhere('barcode', 'like', '%'. $search . '%');
            })
            ->select('id', 'title', 'barcode', 'stock', 'buy_price', 'unit')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $ingredients = Ingredient::when($request->search, function($query, $search) {
                $query->where('name', 'like', '%'. $search . '%');
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $history = StockMovement::with(['product', 'ingredient', 'user'])
            ->where('type', 'in')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Dashboard/Inventory/StockIn', [
            'auth' => [
                'user' => $request->user(),
                'permissions' => $request->user() ? $request->user()->getPermissionArray() : [],
            ],
            'products'    => $products,
            'ingredients' => $ingredients,
            'history'     => $history,
            'filters'     => $request->only(['search']),
        ]);
    }

    /**
     * [UPDATE] Mengambil detail batch & integrasi analisis HPP Average.
     * Mengembalikan data real total aset dan kontribusi tiap batch.
     */
    public function getBatchDetail($id, $type)
    {
        // 1. Identifikasi Master Data & Total Stok
        if ($type === 'products') {
            $master = Product::select('id', 'buy_price', 'title as name', 'stock')->findOrFail($id);
            $batchQuery = StockBatch::where('product_id', $id);
        } else {
            $master = Ingredient::select('id', 'buy_price', 'name', 'stock')->findOrFail($id);
            $batchQuery = StockBatch::where('ingredient_id', $id);
        }

        // 2. Ambil Semua Batch yang masih memiliki sisa stok (FIFO Order)
        $batches = $batchQuery->where('qty_remaining', '>', 0)
            ->orderBy('created_at', 'asc') 
            ->get();

        $totalStock = (float) $master->stock;

        // 3. Hitung Total Nilai Aset Real (Σ Sisa Stok Batch * Harga Beli Batch)
        $totalAssetValue = $batches->sum(function($batch) {
            return (float)$batch->qty_remaining * (float)$batch->buy_price;
        });

        // 4. Kalkulasi Kontribusi HPP per Batch untuk Narasi Rumus
        $mappedBatches = $batches->map(function ($batch) use ($totalStock) {
            $subtotalValue = (float)$batch->qty_remaining * (float)$batch->buy_price;
            $weight = $totalStock > 0 ? ($batch->qty_remaining / $totalStock) : 0;

            return array_merge($batch->toArray(), [
                'subtotal' => $subtotalValue,
                'weight' => round($weight * 100, 2), // Persentase kontribusi sisa stok
                'hpp_contribution' => $totalStock > 0 ? ($subtotalValue / $totalStock) : 0
            ]);
        });

        return response()->json([
            'item_name'         => $master->name,
            'average_cost'      => (float) $master->buy_price,
            'total_stock'       => $totalStock,
            'total_asset_value' => $totalAssetValue,
            'batches'           => $mappedBatches
        ]);
    }

    /**
     * Menyimpan data Stock In manual.
     */
    public function store(Request $request)
    {
        $request->validate([
            'entries' => 'required|array|min:1',
            'entries.*.id'        => 'required',
            'entries.*.type'      => 'required|in:products,ingredients',
            'entries.*.qty_in'    => 'required|numeric|min:0.01',
            'entries.*.buy_price' => 'required|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();
            foreach ($request->entries as $entry) {
                $this->processStockUpdate($entry['id'], $entry['type'], $entry['qty_in'], $entry['buy_price']);
            }
            DB::commit();
            return redirect()->route('stock_in.index')->with('success', 'Stok Berhasil Diperbarui!');
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Manual Stock In Error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Gagal: ' . $e->getMessage());
        }
    }

    /**
     * Import Excel dengan Dual-Search & Deteksi Tipe Otomatis.
     */
    public function parseExcel(Request $request)
    {
        $request->validate(['file' => 'required|mimes:xlsx,xls,csv|max:2048']);

        try {
            $data = Excel::toArray([], $request->file('file'));
            if (empty($data[0])) return redirect()->back()->with('error', 'File kosong.');

            DB::beginTransaction();
            $rows = collect($data[0])->skip(1); 
            $processedCount = 0;

            foreach ($rows as $row) {
                $rawIdentifier = $row[0] ?? null;
                if (!$rawIdentifier) continue;

                $identifier = is_numeric($rawIdentifier) ? number_format((float)$rawIdentifier, 0, '', '') : trim((string)$rawIdentifier);
                
                $price = isset($row[4]) ? (float)$row[4] : 0;
                $qty   = isset($row[5]) ? (float)$row[5] : 0;

                if ($qty <= 0) continue;

                $id = null;
                $type = null;

                if (str_starts_with($identifier, 'ING-')) {
                    $cleanId = str_replace('ING-', '', $identifier);
                    if (Ingredient::where('id', $cleanId)->exists()) {
                        $id = $cleanId;
                        $type = 'ingredients';
                    }
                } else {
                    $product = Product::where('barcode', $identifier)->first();
                    if ($product) {
                        $id = $product->id;
                        $type = 'products';
                    } else {
                        $productById = Product::find($identifier);
                        if ($productById) {
                            $id = $productById->id;
                            $type = 'products';
                        }
                    }
                }

                if ($id && $type) {
                    $this->processStockUpdate($id, $type, $qty, $price);
                    $processedCount++;
                }
            }
            DB::commit();
            return redirect()->route('stock_in.index')->with('success', "$processedCount data berhasil diperbarui.");
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Excel Import Error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Gagal: ' . $e->getMessage());
        }
    }

    /**
     * Logika Core: Update Master, Movement, dan Batch.
     */
    private function processStockUpdate($id, $type, $qtyIn, $priceIn)
    {
        $reference = 'IN-' . strtoupper(uniqid());
        $userId = auth()->id();

        if ($type === 'products') {
            $model = Product::findOrFail($id);
            $tableName = 'products';
            $dataKeys = ['product_id' => $id, 'ingredient_id' => null];
        } else {
            $model = Ingredient::findOrFail($id);
            $tableName = 'ingredients';
            $dataKeys = ['product_id' => null, 'ingredient_id' => $id];
        }

        $qtyIn = (float)$qtyIn;
        $priceIn = (float)$priceIn;
        $currentStock = (float)$model->stock;
        $currentPrice = (float)$model->buy_price;

        // Hitung Moving Average (HPP Rata-rata tertimbang)
        $newStock = $currentStock + $qtyIn;
        $newPrice = ($newStock > 0) 
            ? (($currentStock * $currentPrice) + ($qtyIn * $priceIn)) / $newStock 
            : $priceIn;

        StockMovement::create(array_merge($dataKeys, [
            'user_id'   => $userId,
            'type'      => 'in',
            'qty'       => $qtyIn,
            'price'     => $priceIn,
            'reference' => $reference,
        ]));

        StockBatch::create(array_merge($dataKeys, [
            'serial_number' => $reference,
            'qty_in'        => $qtyIn,
            'qty_remaining' => $qtyIn,
            'buy_price'     => $priceIn,
        ]));

        DB::table($tableName)->where('id', $id)->update([
            'stock'      => $newStock,
            'buy_price'  => $newPrice,
            'updated_at' => now()
        ]);
    }

    public function export() { return Excel::download(new StockInHistoryExport, 'Riwayat_Stock_In.xlsx'); }
    public function exportProductTemplate() { return Excel::download(new ProductTemplateExport, 'Template_Stock_In_Produk.xlsx'); }
    public function exportIngredientTemplate() { return Excel::download(new IngredientTemplateExport, 'Template_Stock_In_Bahan.xlsx'); }
}