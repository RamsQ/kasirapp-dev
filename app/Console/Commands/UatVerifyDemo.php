<?php

namespace App\Console\Commands;

use App\Models\Capital;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialReportService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Menghitung ulang angka-angka demo UAT dan mencocokkannya dengan ledger batch.
 *
 * Gunanya: kalau Laporan Keuangan di browser dan output perintah ini berbeda,
 * berarti ada bug perhitungan yang belum ketahuan. Kalau sama, angka yang
 * sedang kamu uji di UAT memang bisa dipercaya.
 */
class UatVerifyDemo extends Command
{
    protected $signature = 'uat:verify-demo';

    protected $description = 'Verifikasi angka HPP dan laba data demo UAT, termasuk mencocokkan laporan dengan ledger batch.';

    public function handle(): int
    {
        $this->info('=== DATA DEMO UAT ===');
        $this->line(sprintf(
            '  Produk: %d | Bahan: %d | Transaksi: %d | Modal: Rp %s',
            Product::count(),
            Ingredient::count(),
            Transaction::count(),
            number_format((float) Capital::sum('amount'), 0, ',', '.')
        ));
        $this->newLine();

        $transactions = Transaction::where('payment_status', 'paid')->where('status', '!=', 'refunded');

        // Beban operasional harus ikut dihitung, sama seperti di
        // ReportController. Tanpa ini laba terlihat lebih besar dari kenyataan.
        $expenses = \App\Models\Expense::query()
            ->where('category', '!=', 'Pelunasan Hutang')
            ->whereBetween('date', [now()->subYear()->toDateString(), now()->addDay()->toDateString()]);

        $summary = app(FinancialReportService::class)->summary(clone $transactions, $expenses);

        $this->info('=== LAPORAN KEUANGAN (seluruh periode) ===');
        $this->line(sprintf('  Transaksi lunas    : %d', $summary['transaction_count']));
        $this->line(sprintf('  Item terjual       : %d', $summary['items_sold']));
        $this->line(sprintf('  Omzet bersih       : Rp %s', $this->rp($summary['net_revenue'])));
        $this->line(sprintf('  HPP                : Rp %s', $this->rp($summary['hpp'])));
        $this->line(sprintf('  Laba kotor         : Rp %s', $this->rp($summary['gross_profit_before_platform'])));
        $this->line(sprintf('  Beban operasional  : Rp %s', $this->rp($summary['operating_expenses'])));
        $this->line(sprintf('  LABA BERSIH        : Rp %s', $this->rp($summary['net_profit'])));
        $this->newLine();

        // Ini inti verifikasinya: HPP di laporan harus PERSIS sama dengan
        // total_cost yang benar-benar dialokasikan ke batch. Kalau berbeda,
        // laporan menampilkan angka yang tidak berasal dari stok nyata.
        $transactionIds = (clone $transactions)->select('transactions.id');
        $ledger = (float) DB::table('stock_batch_allocations')
            ->whereIn('transaction_id', $transactionIds)
            ->where('movement_type', 'sale')
            ->where('status', 'consumed')
            ->sum('total_cost');

        $snapshot = (float) DB::table('transaction_details')
            ->whereIn('transaction_id', $transactionIds)
            ->sum(DB::raw('COALESCE(buy_price, 0) * COALESCE(qty, 0)'));

        $this->info('=== COCOK HPP DENGAN LEDGER BATCH ===');
        $this->line(sprintf('  Laporan (buy_price x qty) : Rp %s', $this->rp($snapshot)));
        $this->line(sprintf('  Ledger batch (total_cost)  : Rp %s', $this->rp($ledger)));

        $matches = abs($ledger - $snapshot) < 0.5;
        $this->line($matches
            ? '  COCOK: laporan memakai HPP yang benar-benar dialokasikan.'
            : '  TIDAK COCOK: selisih Rp '.$this->rp($snapshot - $ledger).' - ada bug perhitungan.');
        $this->newLine();

        $this->info('=== KONSISTENSI STOK vs BATCH ===');
        $drift = 0;

        // Unit yang sudah dialokasikan ke pembayaran gateway yang masih PENDING
        // sengaja tidak ikut mengurangi qty_remaining batch. Layer-nya di-reserve
        // dulu supaya refund masih tahu batch mana yang harus dikembalikan, dan
        // baru di-consume setelah callback masuk.
        //
        // Akibatnya `products.stock` (yang langsung turun saat penjualan) akan
        // lebih kecil dari `sum(stock_batches.qty_remaining)` selama ada
        // pembayaran yang belum selesai. Itu BUKAN selisih yang perlu
        // direkonsiliasi: menjalankan inventory:reconcile justru akan membekukan
        // selisih sementara ini jadi reality, lalu batch dan master selamanya
        // tidak akan cocok.
        $reserved = $this->reservedForPendingPayments();

        foreach (Product::withTrashed()->get() as $product) {
            $batch = (float) StockBatch::where('product_id', $product->id)->sum('qty_remaining');
            // Master turun saat penjualan; batch turun saat pembayaran
            // terkonfirmasi. Selama ada yang pending, master lebih kecil satu
            // unit dari batch. Jadi yang dibandingkan adalah batch dengan
            // master yang dikurangi unit yang sedang di-reserve.
            $expected = (float) $product->stock - (float) ($reserved['product'][$product->id] ?? 0);
            if (abs($batch - $expected) > 0.000001) {
                $drift++;
                $this->line(sprintf(
                    '  %s: batch %s vs master %s%s',
                    $product->title,
                    $this->qty($batch),
                    $this->qty((float) $product->stock),
                    isset($reserved['product'][$product->id])
                        ? ' (batch tertahan '.$this->qty((float) $reserved['product'][$product->id]).' unit)'
                        : ''
                ));
            }
        }
        foreach (Ingredient::get() as $ingredient) {
            $batch = (float) StockBatch::where('ingredient_id', $ingredient->id)->sum('qty_remaining');
            $expected = (float) $ingredient->stock - (float) ($reserved['ingredient'][$ingredient->id] ?? 0);
            if (abs($batch - $expected) > 0.000001) {
                $drift++;
                $this->line(sprintf(
                    '  %s (bahan): batch %s vs master %s%s',
                    $ingredient->name,
                    $this->qty($batch),
                    $this->qty((float) $ingredient->stock),
                    isset($reserved['ingredient'][$ingredient->id])
                        ? ' (batch tertahan '.$this->qty((float) $reserved['ingredient'][$ingredient->id]).' unit)'
                        : ''
                ));
            }
        }

        if ($reserved['total'] > 0) {
            $this->line(sprintf(
                '  Catatan: %s unit sedang menunggu pembayaran gateway, jadi batch-nya',
                $this->qty($reserved['total'])
            ));
            $this->line('  ditahan dulu. Stok master sudah turun, batch menyusul setelah');
            $this->line('  pembayaran masuk. Ini normal, jangan direkonsiliasi.');
        }

        $this->line($drift === 0
            ? '  Semua stok cocok dengan jumlah batch.'
            : "  Ada {$drift} selisih. Jalankan `php artisan inventory:reconcile`.");
        $this->newLine();

        $this->info('=== BATCH BAHAN (untuk uji FIFO / LIFO) ===');
        foreach (Ingredient::orderBy('name')->get() as $ingredient) {
            $batches = StockBatch::where('ingredient_id', $ingredient->id)
                ->orderBy('id')
                ->get(['qty_remaining', 'buy_price']);
            if ($batches->isEmpty()) {
                continue;
            }
            $parts = $batches->map(fn ($b) => $this->qty($b->qty_remaining).' @ Rp '.$this->rp($b->buy_price))->implode('  |  ');
            $this->line(sprintf('  %-22s %s', $ingredient->name, $parts));
        }
        $this->newLine();
        $this->line('Tips: FIFO memakai batch paling awal (kiri). LIFO memakai paling akhir (kanan).');
        $this->line('      Ganti metode di System Settings, lalu jual menu resep untuk melihat selisihnya.');
        $this->newLine();

        $this->info('=== NERACA (kumulatif sampai hari ini) ===');
        $sheet = (array) $this->financeBalanceSheet();

        $assets = [
            'Kas di Laci - Tunai' => $sheet['cash_in_drawer'] ?? 0,
            'Kas Bank & QRIS - Saldo Digital' => $sheet['bank_and_digital'] ?? 0,
            'Persediaan - Bahan Baku' => $sheet['inventory_ingredients'] ?? 0,
            'Persediaan - Produk Jadi' => $sheet['inventory_products'] ?? 0,
        ];
        foreach ($assets as $label => $value) {
            $this->line(sprintf('  %-38s Rp %s', $label, $this->rp((float) $value)));
        }
        $this->line(sprintf('  %-38s Rp %s', 'TOTAL AKTIVA', $this->rp((float) ($sheet['total_assets'] ?? 0))));
        $this->newLine();

        $this->line(sprintf('  %-38s Rp %s', 'Hutang Usaha', $this->rp((float) ($sheet['accounts_payable'] ?? 0))));
        $this->line(sprintf('  %-38s Rp %s', 'Hutang Pajak', $this->rp((float) ($sheet['tax_payable'] ?? 0))));
        $this->line(sprintf('  %-38s Rp %s', 'TOTAL KEWAJIBAN', $this->rp((float) ($sheet['total_liabilities'] ?? 0))));
        $this->line(sprintf('  %-38s Rp %s', 'Modal Disetor Pemilik', $this->rp((float) ($sheet['external_capital'] ?? 0))));
        $this->line(sprintf('  %-38s Rp %s', 'Laba Ditahan', $this->rp((float) ($sheet['retained_earnings'] ?? 0))));
        $this->line(sprintf('  %-38s Rp %s', 'TOTAL EKUITAS', $this->rp((float) ($sheet['total_equity'] ?? 0))));
        $this->line(sprintf('  %-38s Rp %s', 'TOTAL KEWAJIBAN + EKUITAS', $this->rp((float) ($sheet['total_liabilities_equity'] ?? 0))));
        $this->newLine();

        $difference = (float) ($sheet['balance_difference'] ?? 0);
        $this->line('  SELISIH PENCOCOKAN: Rp '.$this->rp($difference));

        // Selisih sebesar persediaan yang sedang di-reserve untuk pembayaran
        // gateway yang belum selesai adalah hal yang diharapkan, bukan cacat
        // pencatatan. Menjelaskan penyebabnya jauh lebih berguna daripada
        // mengirim operator ke `inventory:reconcile` yang justru akan
        // membekukan selisih sementara itu.
        $pendingValue = $this->pendingPaymentStockValue();

        if ($pendingValue > 0 && abs(abs($difference) - $pendingValue) < 0.5) {
            $this->line('  (SELISIH DITJELASKAN: ada '.$this->rp($pendingValue));
            $this->line('   persediaan yang sudah keluar untuk pesanan, tetapi');
            $this->line('   pembayarannya masih MENUNGGU konfirmasi gateway. Unitnya');
            $this->line('   di-reserve, jadi persediaan di neraca masih menghitung');
            $this->line('   barang yang sebenarnya sudah keluar.');
            $this->line('   Selisih ini hilang sendiri begitu pembayaran masuk, atau');
            $this->line('   menjadi permanen kalau pembayarannya gagal. Jangan jalankan');
            $this->line('   inventory:reconcile untuk yang ini.)');
        } else {
            $this->line('  (seharusnya nol. Kalau tidak, pencatatan belum lengkap:');
            $this->line('   - pembelian stok yang masuk lewat Stock In tanpa cara pembayaran;');
            $this->line('   - penjualan atau penyesuaian manual tanpa alokasi batch.');
            $this->line('   Ini alat bantu, bukan audit resmi.)');
        }
        $this->newLine();

        return ($matches && $drift === 0) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Jumlah unit yang dialokasikan ke pembayaran gateway yang belum selesai,
     * dikelompokkan per resource type.
     *
     * @return array{product: array<int, float>, ingredient: array<int, float>, total: float}
     */
    private function reservedForPendingPayments(): array
    {
        $rows = \Illuminate\Support\Facades\DB::table('stock_batch_allocations')
            ->where('movement_type', 'sale')
            ->where('status', 'reserved')
            ->groupBy('resource_type', 'product_id', 'ingredient_id')
            ->select('resource_type', 'product_id', 'ingredient_id', \Illuminate\Support\Facades\DB::raw('SUM(quantity) as total'))
            ->get();

        $out = ['product' => [], 'ingredient' => [], 'total' => 0.0];

        foreach ($rows as $row) {
            $total = (float) $row->total;
            $out['total'] += $total;

            if ($row->product_id && $row->resource_type === 'product') {
                $out['product'][$row->product_id] = ($out['product'][$row->product_id] ?? 0) + $total;
            }

            if ($row->ingredient_id && $row->resource_type === 'ingredient') {
                $out['ingredient'][$row->ingredient_id] = ($out['ingredient'][$row->ingredient_id] ?? 0) + $total;
            }
        }

        return $out;
    }

    /**
     * Nilai persediaan yang sedang di-reserve untuk pembayaran yang belum
     * selesai. Inilah penyebab paling sering selisih neraca ketika ada transaksi
     * gateway yang masih pending.
     */
    private function pendingPaymentStockValue(): float
    {
        $reserved = $this->reservedForPendingPayments();
        $value = 0.0;

        foreach ($reserved['product'] as $productId => $qty) {
            $price = (float) (Product::withTrashed()->whereKey($productId)->value('buy_price') ?? 0);
            $value += $qty * $price;
        }

        foreach ($reserved['ingredient'] as $ingredientId => $qty) {
            $price = (float) (Ingredient::whereKey($ingredientId)->value('buy_price') ?? 0);
            $value += $qty * $price;
        }

        return $value;
    }

    /**
     * Meminta neraca dari ReportController agar angka yang dicek di terminal
     * sama persis dengan yang tampil di browser.
     *
     * @return array<string,mixed>
     */
    private function financeBalanceSheet(): array
    {
        $admin = User::where('email', 'admin@gmail.com')->first() ?? User::query()->first();
        if (! $admin) {
            return [];
        }

        auth()->login($admin);

        $request = Request::create(route('report.finance', [
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->toDateString(),
        ]), 'GET');

        $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

        // Inertia menaruh payload-nya di view data pada key `page`.
        $view = $response->getOriginalContent();
        if (! $view instanceof \Illuminate\Contracts\View\View) {
            return [];
        }

        $props = $view->getData()['page']['props']['report']['balanceSheet'] ?? null;

        return is_array($props) ? $props : [];
    }

    private function rp(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
