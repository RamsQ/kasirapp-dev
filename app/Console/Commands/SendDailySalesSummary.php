<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ReportSetting;
use App\Models\Transaction;
use App\Models\Profit;
use App\Models\Expense;
use App\Models\TransactionDetail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class SendDailySalesSummary extends Command
{
    /**
     * Nama command yang dipanggil di terminal.
     * Gunakan flag --force untuk mengetes pengiriman manual.
     */
    protected $signature = 'report:sales-daily {--force}';
    protected $description = 'Kirim ringkasan penjualan harian ke WA Owner';

    public function handle()
    {
        // 1. Ambil setting laporan
        $setting = ReportSetting::first();
        if (!$setting || !$setting->is_active) {
            $this->info('Laporan non-aktif atau setting tidak ditemukan.');
            return;
        }

        // 2. Normalisasi Waktu (Penting!)
        // Kita bandingkan Jam:Menit saja, abaikan Detik.
        $now = now()->format('H:i');
        $targetTime = Carbon::parse($setting->send_at)->format('H:i');
        $isForce = $this->option('force');

        $this->comment("Mengecek Jadwal... [Sekarang: $now] vs [Target: $targetTime]");

        // 3. Logika Eksekusi
        if ($isForce || ($now === $targetTime)) {
            
            // Proteksi Spam: Jangan kirim lebih dari sekali di menit yang sama
            $cacheKey = 'wa_report_sent_' . $now;
            if (!$isForce && Cache::has($cacheKey)) {
                $this->info('✅ Laporan untuk menit ini sudah terkirim. Skip.');
                return;
            }

            $this->info('🚀 JAM COCOK! Memproses laporan...');
            $today = now()->toDateString();

            // --- AMBIL DATA ---
            
            // Hitung Keuangan
            $revenue = Transaction::where('payment_status', 'paid')->whereDate('created_at', $today)->sum('grand_total');
            $profit = Profit::whereHas('transaction', fn($q) => $q->where('payment_status', 'paid')->whereDate('created_at', $today))->sum('total');
            $count = Transaction::where('payment_status', 'paid')->whereDate('created_at', $today)->count();
            
            // Hitung Pengeluaran
            $totalExpense = Expense::whereDate('created_at', $today)->sum('amount');

            // Ambil 3 Produk Terlaris
            $topProducts = TransactionDetail::whereHas('transaction', function($q) use ($today) {
                $q->where('payment_status', 'paid')->whereDate('created_at', $today);
            })
            ->select('product_id', DB::raw('SUM(qty) as total_qty'))
            ->with('product')
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->take(3)
            ->get();

            // --- SUSUN PESAN ---
            $msg = "*LAPORAN PENJUALAN HARIAN*\n";
            $msg .= "📅 Tanggal: " . now()->format('d M Y') . "\n";
            $msg .= "--------------------------\n";
            $msg .= "💰 *RINGKASAN KEUANGAN*\n";
            $msg .= "• Total Trx: $count\n";
            $msg .= "• Omzet: Rp " . number_format($revenue) . "\n";
            $msg .= "• Laba: Rp " . number_format($profit) . "\n";
            $msg .= "• Pengeluaran: Rp " . number_format($totalExpense) . "\n";
            $msg .= "--------------------------\n";

            if($topProducts->count() > 0) {
                $msg .= "🔥 *3 PRODUK TERLARIS*\n";
                foreach($topProducts as $index => $item) {
                    $msg .= ($index+1) . ". " . ($item->product->title ?? 'Produk') . " (" . $item->total_qty . " pcs)\n";
                }
                $msg .= "--------------------------\n";
            }
            $msg .= "_Sent via POS System Auto-Report_";

            // --- KIRIM KE FONNTE ---
            try {
                $response = Http::withHeaders([
                    'Authorization' => $setting->wa_api_key ?? env('FONNTE_TOKEN')
                ])->post('https://api.fonnte.com/send', [
                    'target' => $setting->target,
                    'message' => $msg
                ]);

                if ($response->successful()) {
                    $this->info('✅ Berhasil terkirim ke WhatsApp.');
                    // Simpan cache selama 60 detik agar tidak double send
                    Cache::put($cacheKey, true, 60);
                } else {
                    $this->error('❌ Gagal kirim: ' . $response->body());
                }
            } catch (\Exception $e) {
                $this->error('❌ Error: ' . $e->getMessage());
            }

        } else {
            $this->info("😴 Belum waktunya. Menunggu jam $targetTime...");
        }
    }
}