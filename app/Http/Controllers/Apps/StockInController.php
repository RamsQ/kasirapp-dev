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
     * Mengambil detail batch & kalkulasi aset.
     */
    public function getBatchDetail($id, $type)
    {
        if ($type === 'products') {
            $master = Product::select('id', 'buy_price', 'title as name', 'stock')->findOrFail($id);
            $batchQuery = StockBatch::where('product_id', $id);
        } else {
            $master = Ingredient::select('id', 'buy_price', 'name', 'stock')->findOrFail($id);
            $batchQuery = StockBatch::where('ingredient_id', $id);
        }

        $batches = $batchQuery->where('qty_remaining', '>', 0)->orderBy('created_at', 'asc')->get();
        $totalStock = (float) $master->stock;
        $totalAssetValue = $batches->sum(fn($b) => (float)$b->qty_remaining * (float)$b->buy_price);

        $mappedBatches = $batches->map(function ($batch) use ($totalStock) {
            $subtotalValue = (float)$batch->qty_remaining * (float)$batch->buy_price;
            return array_merge($batch->toArray(), [
                'subtotal' => $subtotalValue,
                'weight' => $totalStock > 0 ? round(($batch->qty_remaining / $totalStock) * 100, 2) : 0,
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
                $this->processStockUpdate($entry['id'], $entry['type'], (float)$entry['qty_in'], (float)$entry['buy_price']);
            }
            DB::commit();
            
            return redirect()->route('stock_in.index')->with('success', 'Stok Berhasil Diperbarui!');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Gagal: ' . $e->getMessage());
        }
    }

    /**
     * Import via Excel.
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
                
                $id = null; $type = null;
                if (str_starts_with($identifier, 'ING-')) {
                    $cleanId = str_replace('ING-', '', $identifier);
                    if (Ingredient::where('id', $cleanId)->exists()) { $id = $cleanId; $type = 'ingredients'; }
                } else {
                    $product = Product::where('barcode', $identifier)->first() ?: Product::find($identifier);
                    if ($product) { $id = $product->id; $type = 'products'; }
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
            return redirect()->back()->with('error', 'Gagal: ' . $e->getMessage());
        }
    }

    /**
     * Logika Core: Atomic Increment & Bypass Constraints.
     */
    private function processStockUpdate($id, $type, $qtyIn, $priceIn)
    {
        $reference = 'IN-' . strtoupper(uniqid());
        $userId = auth()->id();

        if ($type === 'products') {
            $tableName = 'products';
            $master = DB::table('products')->where('id', $id)->first();
            $logPayload = ['product_id' => $id, 'ingredient_id' => null]; 
        } else {
            $tableName = 'ingredients';
            $master = DB::table('ingredients')->where('id', $id)->first();
            $logPayload = ['product_id' => null, 'ingredient_id' => $id];
        }

        if (!$master) {
            throw new \Exception("Item dengan ID $id tidak ditemukan.");
        }

        $oldStock = (float) $master->stock;
        $oldPrice = (float) $master->buy_price;
        $newStock = $oldStock + $qtyIn;
        $newPrice = ($newStock > 0) ? (($oldStock * $oldPrice) + ($qtyIn * $priceIn)) / $newStock : $priceIn;

        // 1. UPDATE STOK UTAMA (Atomic via DB::raw)
        DB::table($tableName)->where('id', $id)->update([
            'stock'      => DB::raw("stock + $qtyIn"),
            'buy_price'  => $newPrice,
            'updated_at' => now()
        ]);

        // 2. SIMPAN RIWAYAT (Manual insert untuk bypass constraint NOT NULL)
        DB::table('stock_movements')->insert(array_merge($logPayload, [
            'user_id'    => $userId,
            'type'       => 'in',
            'qty'        => $qtyIn,
            'price'      => $priceIn,
            'reference'  => $reference,
            'created_at' => now(),
            'updated_at' => now()
        ]));

        // 3. SIMPAN BATCH
        DB::table('stock_batches')->insert(array_merge($logPayload, [
            'serial_number' => $reference,
            'qty_in'        => $qtyIn,
            'qty_remaining' => $qtyIn,
            'buy_price'     => $priceIn,
            'created_at'    => now(),
            'updated_at'    => now()
        ]));
    }

    /**
     * FUNGSI EXPORT & TEMPLATE (Sinkron dengan web.php)
     */
    public function export() 
    { 
        return Excel::download(new StockInHistoryExport, 'Riwayat_Stock_In.xlsx'); 
    }

    public function exportProductTemplate() 
    { 
        return Excel::download(new ProductTemplateExport, 'Template_Stock_In_Produk.xlsx'); 
    }

    public function exportIngredientTemplate() 
    { 
        return Excel::download(new IngredientTemplateExport, 'Template_Stock_In_Bahan.xlsx'); 
    }
}