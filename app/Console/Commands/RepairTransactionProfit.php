<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\Profit;
use Illuminate\Support\Facades\DB;

class RepairTransactionProfit extends Command
{
    protected $signature = 'repair:profit';
    protected $description = 'Memperbaiki data HPP (buy_price) dan Profit pada transaksi lama yang masih nol';

    public function handle()
    {
        $this->info('Memulai perbaikan data profit...');

        // 1. Ambil semua detail transaksi yang buy_price-nya masih 0
        $details = TransactionDetail::with('product')
            ->where('buy_price', 0)
            ->get();

        $bar = $this->output->createProgressBar(count($details));
        $bar->start();

        foreach ($details as $detail) {
            if ($detail->product) {
                // Gunakan cost_price (resep) atau buy_price (master)
                $hppPerUnit = $detail->product->cost_price > 0 
                    ? $detail->product->cost_price 
                    : $detail->product->buy_price;

                if ($hppPerUnit > 0) {
                    // Update buy_price di detail transaksi
                    $detail->update(['buy_price' => $hppPerUnit]);

                    // Hitung ulang profit untuk item ini
                    $newProfit = (float)$detail->price - ((float)$hppPerUnit * $detail->qty);

                    // Update atau re-kalkulasi tabel Profits terkait transaksi ini
                    // Kita cari profit record yang berhubungan dengan transaksi ini
                    $profitRecord = Profit::where('transaction_id', $detail->transaction_id)->first();
                    
                    if ($profitRecord) {
                        // Karena satu transaksi bisa banyak item, kita hitung total profit baru untuk transaksi tersebut
                        $totalTransactionProfit = TransactionDetail::where('transaction_id', $detail->transaction_id)
                            ->select(DB::raw('SUM(price - (buy_price * qty)) as total'))
                            ->first()->total;

                        $profitRecord->update(['total' => $totalTransactionProfit]);
                    }
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Perbaikan selesai! Silakan cek Laporan Keuntungan Anda.');
    }
}