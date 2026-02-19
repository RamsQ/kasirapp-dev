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
     * * PERBAIKAN: Fungsi ini sekarang HANYA menghitung HPP dan mengurangi qty di Batch.
     * Pengurangan stok utama (Product Master) dilakukan di TransactionController
     * untuk mencegah pengurangan ganda (double decrement).
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
         */
        if ($productMaster->cost_price > 0) {
            $totalHpp = (float) ($qtySold * $productMaster->cost_price);
            
            // FIX: Bagian decrement stock di sini dihapus karena sudah dijalankan di Controller
            return (float) $totalHpp;
        }

        // 2. Ambil Harga Beli Default dari Master Produk (Cadangan jika batch kosong)
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

                    // Kurangi stok di level Batch (Penting untuk record HPP selanjutnya)
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
                    
                    // Kurangi stok di level Batch
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

                if ($batchData && $batchData->total_qty > 0) {
                    $averagePrice = (float) ($batchData->total_value / $batchData->total_qty);
                } else {
                    $averagePrice = $defaultCost;
                }

                $totalHpp = $qtySold * $averagePrice;

                // Sinkronisasi Batch fisik menggunakan logic FIFO
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

        // 3. Fallback: Jika stok di batch tidak cukup, sisa qty diambil dari harga default
        if ($remainingToReduce > 0) {
            $totalHpp += (float) ($remainingToReduce * $defaultCost);
        }

        // FIX: Bagian decrement stock di sini dihapus untuk mencegah pengurangan ganda.
        // Stok global produk master sekarang dikelola sepenuhnya oleh TransactionController.

        return (float) $totalHpp;
    }
}