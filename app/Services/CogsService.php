<?php

namespace App\Services;

use App\Models\StockBatch;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CogsService
{
    /**
     * Menghitung total HPP berdasarkan metode yang dipilih
     * method: FIFO, LIFO, AVERAGE, SPECIFIC
     */
    public function calculate($productId, $qtySold, $method, $scannedSerial = null)
    {
        $totalHpp = 0;
        $remainingToReduce = $qtySold;

        // 1. Validasi Produk
        $productMaster = Product::find($productId);
        if (!$productMaster) {
            return 0;
        }

        /**
         * [INTEGRASI RESEP]
         * Jika produk memiliki resep (cost_price > 0), gunakan nilai tersebut sebagai HPP.
         * Ini biasanya untuk menu makanan/minuman yang diolah dari bahan baku.
         */
        if ($productMaster->cost_price > 0) {
            $totalHpp = (float) ($qtySold * $productMaster->cost_price);
            
            // Tetap kurangi stok produk master (produk jadi)
            if ($productMaster) {
                $productMaster->decrement('stock', $qtySold);
            }
            
            return (float) $totalHpp;
        }

        // 2. Ambil Harga Beli Default dari Master Produk (Cadangan jika batch kosong/produk retail)
        $defaultCost = (float) ($productMaster->buy_price ?? 0);

        switch ($method) {
            case 'FIFO':
            case 'LIFO':
                $direction = ($method === 'FIFO') ? 'asc' : 'desc';
                
                $batches = StockBatch::where('product_id', $productId)
                            ->where('qty_remaining', '>', 0)
                            ->orderBy('created_at', $direction)
                            ->get();

                foreach ($batches as $batch) {
                    if ($remainingToReduce <= 0) break;

                    $take = min($batch->qty_remaining, $remainingToReduce);
                    $totalHpp += $take * (float) $batch->buy_price;

                    $batch->decrement('qty_remaining', $take);
                    $remainingToReduce -= $take;
                }
                break;

            case 'SPECIFIC':
                $batch = StockBatch::where('product_id', $productId)
                            ->where('serial_number', $scannedSerial)
                            ->where('qty_remaining', '>', 0)
                            ->first();

                if ($batch) {
                    $take = min($batch->qty_remaining, $remainingToReduce);
                    $totalHpp = $take * (float) $batch->buy_price;
                    $batch->decrement('qty_remaining', $take);
                    $remainingToReduce -= $take;
                }
                break;

            case 'AVERAGE':
                // Hitung rata-rata tertimbang berdasarkan Stock Batch yang tersedia
                $batchData = StockBatch::where('product_id', $productId)
                                ->where('qty_remaining', '>', 0)
                                ->select(
                                    DB::raw('SUM(qty_remaining * buy_price) as total_value'),
                                    DB::raw('SUM(qty_remaining) as total_qty')
                                )->first();

                // Jika ada data di batch, gunakan rata-rata batch. Jika tidak, gunakan harga master.
                if ($batchData && $batchData->total_qty > 0) {
                    $averagePrice = (float) ($batchData->total_value / $batchData->total_qty);
                } else {
                    $averagePrice = $defaultCost;
                }

                $totalHpp = $qtySold * $averagePrice;

                // Tetap kurangi fisik batch menggunakan logic FIFO agar data batch sinkron dengan stok global
                $batchesToReduce = StockBatch::where('product_id', $productId)
                                    ->where('qty_remaining', '>', 0)
                                    ->orderBy('created_at', 'asc')
                                    ->get();

                foreach ($batchesToReduce as $b) {
                    if ($remainingToReduce <= 0) break;
                    $take = min($b->qty_remaining, $remainingToReduce);
                    $b->decrement('qty_remaining', $take);
                    $remainingToReduce -= $take;
                }
                break;
        }

        // 3. Fallback: Jika stok di batch tidak cukup, sisa qty diambil dari stok Master
        if ($remainingToReduce > 0) {
            $totalHpp += (float) ($remainingToReduce * $defaultCost);
        }

        // 4. Sinkronisasi Stok Global
        if ($productMaster) {
            $productMaster->decrement('stock', $qtySold);
        }

        return (float) $totalHpp;
    }
}