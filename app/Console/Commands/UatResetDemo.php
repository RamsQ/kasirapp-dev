<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus HANYA data demo UAT.
 *
 * Sengaja tidak memakai truncate atau migrate:fresh: user admin, pengaturan
 * sistem, dan payment_settings (berisi kredensial Midtrans asli) tidak boleh
 * ikut terhapus.
 */
class UatResetDemo extends Command
{
    protected $signature = 'uat:reset-demo
                            {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Hapus hanya data demo UAT (produk, bahan, transaksi, pelanggan demo) tanpa menyentuh user, settings, dan payment settings.';

    public function handle(): int
    {
        $tables = $this->demoTables();

        $total = 0;
        foreach ($tables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $total += (int) DB::table($table)->count();
            }
        }

        if ($total === 0) {
            $this->info('Tidak ada data demo untuk dihapus.');

            return self::SUCCESS;
        }

        $this->warn('Data demo yang akan dihapus:');
        foreach ($tables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $count = DB::table($table)->count();
                if ($count > 0) {
                    $this->line("  - {$table}: {$count} baris");
                }
            }
        }

        $this->newLine();
        $this->line('TIDAK akan dihapus: users, roles, permissions, settings, payment_settings.');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Lanjutkan penghapusan data demo?', true)) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($tables) {
            // Urutan penting: child dulu, lalu parent.
            foreach ($tables as $table) {
                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                DB::table($table)->delete();
            }
        });

        $this->info('Data demo UAT berhasil dihapus.');
        $this->line('Jalankan `php artisan db:seed --class=UatDemoSeeder` untuk membuat ulang.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function demoTables(): array
    {
        return [
            'stock_batch_allocations',
            'profits',
            'loyalty_point_ledger',
            'crm_campaign_deliveries',
            'payment_events',
            'stock_reservations',
            'transaction_details',
            'holds',
            'transactions',
            'expenses',
            'capitals',
            'discounts',
            'recipes',
            'product_bundles',
            'product_units',
            'ingredients',
            'products',
            'categories',
            'stock_opnames',
            'stock_movements',
            'stock_batches',
            'shifts',
            'crm_campaigns',
            'customer_segments',
            'customer_vouchers',
            'promo_codes',
            'customers',
            'ai_sessions',
        ];
    }
}
