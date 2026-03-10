<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\{ReportSetting, Transaction, Profit, Expense, TransactionDetail};
use Illuminate\Support\Facades\{Mail, DB};
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class SendMonthlyReport extends Command
{
    /**
     * Nama perintah terminal. 
     * Gunakan flag --force untuk testing kapan saja.
     */
    protected $signature = 'report:monthly {--force}';
    protected $description = 'Generate rekap bulanan PDF dan kirim ke Email Owner';

    public function handle()
    {
        // 1. Ambil Setting
        $setting = ReportSetting::first();

        // 2. Cek apakah fitur bulanan aktif di dashboard
        if (!$setting || (!$this->option('force') && !$setting->is_monthly)) {
            $this->info('😴 Skip: Laporan bulanan dinonaktifkan di dashboard.');
            return;
        }

        $this->info('🚀 Memulai proses rekap bulanan...');

        // 3. Tentukan Periode (Bulan Lalu)
        // Jika dijalankan tgl 1 Maret, maka dia rekap data 1-28/29 Februari
        $startOfMonth = Carbon::now()->subMonth()->startOfMonth();
        $endOfMonth = Carbon::now()->subMonth()->endOfMonth();
        $periode = $startOfMonth->translatedFormat('F Y');

        // 4. Tarik Data Keuangan
        $revenue = Transaction::where('payment_status', 'paid')
                    ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                    ->sum('grand_total');

        $profit = Profit::whereHas('transaction', fn($q) => 
                    $q->where('payment_status', 'paid')->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                )->sum('total');

        $totalExpense = Expense::whereBetween('created_at', [$startOfMonth, $endOfMonth])->sum('amount');
        $count = Transaction::where('payment_status', 'paid')
                    ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                    ->count();

        // 5. Tarik Top 5 Produk Terlaris
        $topProducts = TransactionDetail::whereHas('transaction', function($q) use ($startOfMonth, $endOfMonth) {
                $q->where('payment_status', 'paid')->whereBetween('created_at', [$startOfMonth, $endOfMonth]);
            })
            ->select('product_id', DB::raw('SUM(qty) as total_qty'))
            ->with('product')
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->take(5)
            ->get();

        $data = [
            'periode'      => $periode,
            'revenue'      => $revenue,
            'profit'       => $profit,
            'expense'      => $totalExpense,
            'count'        => $count,
            'topProducts'  => $topProducts,
            'net_profit'   => $profit - $totalExpense
        ];

        // 6. Generate PDF menggunakan view (kita buat di langkah selanjutnya)
        $this->info('📄 Sedang membuat file PDF...');
        $pdf = Pdf::loadView('reports.monthly_pdf', $data);

        // 7. Kirim Email
        try {
            $emailTarget = $setting->target;
            
            Mail::send([], [], function ($message) use ($emailTarget, $pdf, $periode) {
                $message->to($emailTarget)
                    ->subject("📊 Laporan Bulanan Mangku POS - $periode")
                    ->html("Halo Owner,<br><br>Terlampir adalah laporan rekapitulasi penjualan unit bisnis Anda untuk periode <b>$periode</b>.<br><br>Salam,<br><b>Sistem Mangku POS</b>")
                    ->attachData($pdf->output(), "Laporan_Bulanan_{$periode}.pdf");
            });

            $this->info("✅ Berhasil! Laporan PDF telah dikirim ke: $emailTarget");
        } catch (\Exception $e) {
            $this->error('❌ Gagal kirim email: ' . $e->getMessage());
        }
    }
}