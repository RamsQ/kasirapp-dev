<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBatchAllocation;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Memulihkan HPP (buy_price) pada detail transaksi lama yang masih kosong,
 * lalu menghitung ulang baris laba.
 *
 * Urutan sumber HPP, dari yang paling bisa dipercaya:
 *
 *  1. LEDGER BATCH (pasti). StockBatchAllocation mencatat total_cost yang
 *     benar-benar dibakar dari batch saat penjualan. Kalau baris ini ada,
 *     itulah HPP yang sebenarnya, bukan estimasi.
 *
 *  2. ESTIMASI (kalau ledger tidak ada). Transaksi lama bisa jadi dibuat
 *     sebelum sistem batch ada, jadi tidak ada catatan layer yang dibakar.
 *     Estimasi dihitung ULANG dari master data saat ini:
 *       - menu resep  : jumlah (takaran x harga bahan SEKARANG)
 *       - bundel      : jumlah HPP komponennya
 *       - produk biasa: buy_price (rata-rata tertimbang yang diurus Stock In)
 *
 *     Penting: versi lama memakai products.cost_price sebagai sumber pertama.
 *     Field itu hanya perkiraan tampilan hasil tombol "Hitung HPP" dan bisa
 *     membeku di harga bahan yang sudah lama, sehingga HPP yang dipulihkan
 *     bisa meleset jauh dari kenyataan. Sekarang tidak dipakai lagi.
 *
 * Estimasi SELALU dicatat di log supaya angkanya bisa ditelusuri, dan
 * jumlah baris yang hanya estimasi dilaporkan di akhir eksekusi.
 */
class RepairTransactionProfit extends Command
{
    protected $signature = 'repair:profit
                            {--dry-run : Tampilkan rencana tanpa menulis ke database}
                            {--limit=0 : Batasi jumlah transaksi yang diproses (0 = semua)}';

    protected $description = 'Pulihkan HPP transaksi lama dari ledger batch, atau estimasi bila ledger tidak ada, lalu hitung ulang laba.';

    /** @var array<string, int> */
    private array $sources = ['ledger' => 0, 'estimated' => 0, 'unresolved' => 0];

    /** @var array<int, array<string, mixed>> */
    private array $estimatedRows = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Memperbaiki HPP dan laba pada transaksi lama...');
        if ($dryRun) {
            $this->warn('Mode DRY RUN - tidak ada data yang ditulis.');
        }
        $this->newLine();

        $details = TransactionDetail::where(function ($query): void {
            $query->whereNull('buy_price')->orWhere('buy_price', 0);
        })->pluck('transaction_id')->unique();

        if ($limit = (int) $this->option('limit')) {
            $details = $details->take($limit);
        }

        if ($details->isEmpty()) {
            $this->info('Tidak ada detail transaksi dengan HPP kosong. Tidak ada yang perlu diperbaiki.');

            return self::SUCCESS;
        }

        $this->line("  Transaksi diperiksa : {$details->count()}");
        $this->newLine();

        $bar = $this->output->createProgressBar($details->count());
        $bar->start();

        foreach ($details as $transactionId) {
            if ($dryRun) {
                $this->inspectTransaction((int) $transactionId, false);
            } else {
                DB::transaction(function () use ($transactionId): void {
                    $this->repairTransaction((int) $transactionId);
                });
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->reportSources($dryRun);

        if ($this->sources['estimated'] > 0) {
            $this->newLine();
            $this->line('  Rincian baris yang HPP-nya ESTIMASI (tidak ada ledger batch):');
            foreach (array_slice($this->estimatedRows, 0, 20) as $row) {
                $this->line(sprintf(
                    '    %s  %s  qty %s  ->  HPP @ %s',
                    $row['invoice'],
                    mb_substr((string) $row['product'], 0, 28),
                    $row['qty'],
                    number_format((float) $row['buy_price'], 0, ',', '.')
                ));
            }
            if (count($this->estimatedRows) > 20) {
                $this->line('    ... dan '.(count($this->estimatedRows) - 20).' baris lainnya.');
            }
            $this->line('  Full list: storage/logs/repair-profit.log');
        }

        $this->newLine();
        $this->info($dryRun
            ? 'Dry run selesai. Jalankan tanpa --dry-run untuk menerapkan.'
            : 'Selesai. Silakan cek Laporan Keuntungan.');

        return self::SUCCESS;
    }

    private function repairTransaction(int $transactionId): void
    {
        $transaction = Transaction::with(['details.product.recipes.ingredient', 'profits'])
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->find($transactionId);

        if (! $transaction) {
            return;
        }

        $this->backfillHpp($transaction, true);

        $transaction->refresh()->load('details');
        $this->recalculateProfit($transaction);
    }

    /**
     * Mode dry run: tidak menulis apa pun, hanya menghitung berapa baris
     * akan berubah dan dari sumber mana.
     */
    private function inspectTransaction(int $transactionId, bool $persist): void
    {
        $transaction = Transaction::with(['details.product.recipes.ingredient', 'profits'])
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->find($transactionId);

        if ($transaction) {
            $this->backfillHpp($transaction, $persist);
        }
    }

    private function backfillHpp(Transaction $transaction, bool $persist): void
    {
        $productCache = [];

        foreach ($transaction->details as $detail) {
            if ((float) $detail->buy_price > 0) {
                continue;
            }

            $qty = (float) $detail->qty;
            if ($qty <= 0) {
                continue;
            }

            $product = $detail->product;
            if (! $product) {
                $this->sources['unresolved']++;

                continue;
            }

            // Bundle dipecah jadi komponennya sekali saja.
            if ($product->type === 'bundle' && ! isset($productCache[$product->id])) {
                $product->loadMissing('bundle_items.units');
                $productCache[$product->id] = true;
            }

            $ledgerCost = $this->ledgerCostFor($transaction->id, $product);

            if ($ledgerCost > 0) {
                $this->sources['ledger']++;
                $this->record($transaction, $detail, $ledgerCost / $qty, $persist);

                continue;
            }

            $estimate = $this->estimateUnitCost($product, 1.0);
            if ($estimate > 0) {
                $this->sources['estimated']++;
                $this->estimatedRows[] = [
                    'invoice' => $transaction->invoice,
                    'product' => $product->title,
                    'qty' => $qty,
                    'buy_price' => $estimate,
                ];
                $this->record($transaction, $detail, $estimate, $persist);

                continue;
            }

            $this->sources['unresolved']++;
            $this->record($transaction, $detail, 0.0, $persist);
        }
    }

    /**
     * HPP yang benar-benar dialokasikan dari batch, mengikuti struktur yang
     * dipakai CogsService.
     *
     * Penting: ledger tidak pernah menunjuk ke PRODUK untuk menu resep. Batch
     * yang terbakar adalah batch BAHAN, jadi alokasi tersimpan dengan
     * ingredient_id terisi dan product_id null. Kalau hanya filter product_id,
     * setiap menu resep akan salah dikira "tidak ada ledger" dan jatuh ke
     * estimasi.
     */
    private function ledgerCostFor(int $transactionId, Product $product): float
    {
        $base = fn () => StockBatchAllocation::where('transaction_id', $transactionId)
            ->where('movement_type', 'sale')
            ->whereIn('status', ['consumed', 'reserved']);

        $product->loadMissing('recipes', 'bundle_items');

        if ($product->type === 'bundle') {
            $componentIds = $product->bundle_items->pluck('id')->all();
            if ($componentIds === []) {
                return 0.0;
            }

            return (float) $base()->whereIn('product_id', $componentIds)->sum('total_cost');
        }

        if ($product->recipes->isNotEmpty()) {
            $ingredientIds = $product->recipes->pluck('ingredient_id')->all();
            if ($ingredientIds === []) {
                return 0.0;
            }

            return (float) $base()->whereIn('ingredient_id', $ingredientIds)->sum('total_cost');
        }

        return (float) $base()->where('product_id', $product->id)->sum('total_cost');
    }

    /**
     * Estimasi HPP per unit dari master data yang ada sekarang.
     */
    private function estimateUnitCost(Product $product, float $quantity): float
    {
        $product->loadMissing('recipes.ingredient', 'bundle_items.units');

        if ($product->type === 'bundle') {
            $total = 0.0;
            foreach ($product->bundle_items as $component) {
                $conversion = $component->pivot->product_unit_id
                    ? (float) (ProductUnit::find($component->pivot->product_unit_id)?->conversion ?? 1)
                    : 1.0;

                $total += $this->estimateUnitCost(
                    $component,
                    $quantity * (float) $component->pivot->qty * $conversion
                );
            }

            return (float) $total;
        }

        if ($product->recipes->isNotEmpty()) {
            $total = 0.0;
            foreach ($product->recipes as $recipe) {
                if ((float) $recipe->qty_needed <= 0) {
                    continue;
                }
                $total += (float) $recipe->qty_needed * (float) ($recipe->ingredient->buy_price ?? 0);
            }

            return $quantity * $total;
        }

        return $quantity * (float) ($product->buy_price ?? 0);
    }

    private function record(Transaction $transaction, TransactionDetail $detail, float $unitCost, bool $persist): void
    {
        if (! $persist) {
            return;
        }

        $detail->update(['buy_price' => round($unitCost, 2)]);
    }

    private function recalculateProfit(Transaction $transaction): void
    {
        $details = $transaction->details()->get();
        if ($details->isEmpty()) {
            return;
        }

        $subtotal = (float) $details->sum('price');
        $platformCost = (float) $transaction->total_markup + (float) $transaction->total_fee;
        $totalProfit = 0.0;

        foreach ($details as $detail) {
            $proportion = $subtotal > 0 ? (float) $detail->price / $subtotal : 0;
            $lineHpp = (float) $detail->buy_price * (float) $detail->qty;

            // Harga di baris detail belum termasuk pajak, jadi pajak tidak
            // boleh masuk ke laba. Pajak adalah kewajiban ke negara, bukan
            // pendapatan usaha.
            $totalProfit += (float) $detail->price
                - ((float) $transaction->discount * $proportion)
                - ($platformCost * $proportion)
                - $lineHpp;
        }

        // Profit lama kadang satu baris per item. Tabelnya tidak punya kunci
        // baris item, jadi cukup satu baris agregat per transaksi.
        $transaction->profits()->delete();
        $transaction->profits()->create(['total' => $totalProfit]);
    }

    private function reportSources(bool $dryRun): void
    {
        $this->info('SUMBER HPP');
        $this->line(sprintf('  Ledger batch (pasti) : %d baris', $this->sources['ledger']));
        $this->line(sprintf('  Estimasi master data : %d baris', $this->sources['estimated']));
        $this->line(sprintf('  Tidak ditemukan      : %d baris', $this->sources['unresolved']));
        $this->newLine();

        if ($this->sources['unresolved'] > 0) {
            $this->warn('  Baris "tidak ditemukan" berarti produknya sudah hilang atau tidak punya');
            $this->warn('  harga beli maupun resep. HPP-nya tetap 0 dan perlu diisi manual.');
        }

        if ($this->sources['estimated'] > 0 && ! $dryRun) {
            Log::info('repair:profit mengisi HPP estimasi', [
                'estimated_rows' => $this->estimatedRows,
                'note' => 'HPP ini dihitung dari master data saat ini, bukan dari layer batch asli.',
            ]);
        }
    }
}
