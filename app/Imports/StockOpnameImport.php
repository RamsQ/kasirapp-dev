<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Ingredient;
use App\Models\StockOpname;
use App\Models\Expense;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Facades\DB;

class StockOpnameImport implements ToCollection
{
    public function collection(Collection $rows)
    {
        // Skip header (Baris pertama)
        $rows = $rows->slice(1);

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                /**
                 * SESUAIKAN INDEX KOLOM (Mulai dari 0):
                 * 0 = Identifier (Barcode / ID / ING-ID)
                 * 1 = Nama (Skip)
                 * 2 = Satuan (Skip)
                 * 3 = Stok Sistem (Skip)
                 * 4 = STOK FISIK / AKTUAL (TARGET UTAMA)
                 * 5 = Keterangan
                 */
                $identifier  = $row[0] ?? null; 
                $stockActual = $row[4] ?? null; 
                $reason      = $row[5] ?? 'Import Excel';

                // Jika kolom identitas atau stok fisik kosong, lewati baris ini
                if ($identifier === null || $stockActual === null || $stockActual === '') {
                    continue;
                }

                $model = null;
                $dataKeys = [];
                $type = '';

                // 1. DETEKSI APAKAH BAHAN BAKU (INGREDIENT)
                if (str_starts_with(strtoupper($identifier), 'ING-')) {
                    $id = str_replace(['ING-', 'ing-'], '', $identifier);
                    $model = Ingredient::lockForUpdate()->find($id);
                    $type = 'ingredients';
                    $dataKeys = ['product_id' => null, 'ingredient_id' => $id];
                } 
                // 2. ATAU PRODUK JADI
                else {
                    // Cari berdasarkan Barcode dulu
                    $model = Product::where('barcode', $identifier)->lockForUpdate()->first();
                    // Jika tidak ketemu barcode, coba cari berdasarkan ID langsung
                    if (!$model) {
                        $model = Product::lockForUpdate()->find($identifier);
                    }
                    $type = 'products';
                    if ($model) {
                        $dataKeys = ['product_id' => $model->id, 'ingredient_id' => null];
                    }
                }

                // 3. EKSEKUSI UPDATE JIKA MODEL DITEMUKAN
                if ($model) {
                    $stockSystem = (float) $model->stock;
                    $stockActual = (float) $stockActual;
                    $difference  = $stockActual - $stockSystem;

                    // Lewati jika tidak ada perbedaan angka (tidak perlu opname)
                    if ($difference === 0) continue;

                    // A. Simpan Log Riwayat Opname
                    StockOpname::create(array_merge($dataKeys, [
                        'user_id'      => auth()->id(),
                        'stock_system' => $stockSystem,
                        'stock_actual' => $stockActual,
                        'difference'   => $difference,
                        'reason'       => $reason,
                    ]));

                    // B. Catat Kerugian (Expense) jika stok berkurang secara misterius
                    if ($difference < 0) {
                        $qtyLost = abs($difference);
                        $totalLoss = (float)($model->buy_price ?? 0) * $qtyLost;

                        Expense::create([
                            'name'         => "Penyusutan " . ($type === 'products' ? "Produk" : "Bahan"),
                            'account_name' => 'Beban Penurunan Nilai Persediaan',
                            'category'     => 'Kerugian Stok',
                            'amount'       => $totalLoss,
                            'description'  => "Opname Import: " . ($model->title ?? $model->name) . " berkurang {$qtyLost}. Alasan: {$reason}",
                            'date'         => now(),
                            'user_id'      => auth()->id(),
                        ]);
                    }

                    // C. Update Stok Master
                    $model->update([
                        'stock' => $stockActual
                    ]);
                }
            }
        });
    }
}