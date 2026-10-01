<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mengosongkan data operasional, menyisakan akun dan konfigurasi.
 *
 * Untuk mulai UAT dari nol tanpa kehilangan akses login dan tanpa mengetik
 * ulang kredensial Midtrans/Xendit.
 *
 * YANG TIDAK PERNAH DIHAPUS:
 *  - users beserta roles, permissions, dan pivot-nya. Menghapus tabel ini
 *    membuat tidak ada yang bisa login sama sekali, dan `PermissionSeeder`
 *    harus dijalankan ulang sebelum aplikasi bisa dipakai.
 *  - settings, payment_settings, receipt_settings, report_settings,
 *    online_settings. Kredensial gateway hanya ada di database, bukan di .env,
 *    jadi menghapusnya berarti harus mengetik ulang dari dashboard provider.
 *  - migrations. Tanpa baris ini, `php artisan migrate` tidak tahu bahwa
 *    skema sudah ada dan akan mencoba membuatnya lagi.
 *
 * CARA PAKAI:
 *  php artisan reset:operational            -> simulasi, tidak menghapus apa pun
 *  php artisan reset:operational --force     -> benar-benar menghapus
 *  php artisan reset:operational --force --clear-payments
 *                                           -> ikut kosongkan payment_settings
 */
class ResetOperationalData extends Command
{
    protected $signature = 'reset:operational
        {--force : Benar-benar menghapus data. Tanpa ini hanya simulasi.}
        {--clear-payments : Jugur juga kosongkan tabel konfigurasi (payment_settings, settings). Default: dipertahankan.}';

    protected $description = 'Kosongkan data operasional (transaksi, produk, stok, pelanggan) sambil mempertahankan akun dan konfigurasi.';

    /**
     * Tabel yang wajib bertahan supaya aplikasi tetap bisa dipakai.
     *
     * @var list<string>
     */
    private const KEEP_ALWAYS = [
        'users',
        'roles',
        'permissions',
        'role_has_permissions',
        'model_has_roles',
        'model_has_permissions',
    ];

    /**
     * Tabel konfigurasi. Dihapus hanya kalau --keep-payments=false.
     *
     * @var list<string>
     */
    private const KEEP_CONFIG = [
        'settings',
        'payment_settings',
        'receipt_settings',
        'report_settings',
        'online_settings',
    ];

    public function handle(): int
    {
        $this->warn('Database: '.config('database.connections.mysql.database')
            .' @ '.config('database.connections.mysql.host'));
        $this->newLine();

        // Default-nya MEMPERTAHANKAN konfigurasi. Flag-nya sengaja negatif
        // (`--clear-payments`), bukan positif, karena option boolean tanpa
        // nilai default mengembalikan false saat tidak diisi. Flag positif
        // yang namanya "--keep" justru akan bernilai false saat tidak diisi,
        // dan konfigurasi ikut terhapus.
        $keepConfig = ! $this->option('clear-payments');
        $keep = array_merge(self::KEEP_ALWAYS, $keepConfig ? self::KEEP_CONFIG : []);

        $tables = $this->allTables();
        $targets = array_values(array_filter($tables, fn (string $t) => ! in_array($t, $keep, true)));

        if ($targets === []) {
            $this->info('Tidak ada tabel yang perlu dikosongkan.');

            return self::SUCCESS;
        }

        $counts = [];
        $totalRows = 0;
        foreach ($targets as $table) {
            $count = (int) DB::table($table)->count();
            $counts[$table] = $count;
            $totalRows += $count;
        }

        // Tabel yang bergantung pada tabel lain dihapus urutannya tidak penting
        // karena FK check dimatikan. Yang penting tidak menyisakan tabel yang
        // tidak dikenal.
        $this->line('Akan dikosongkan '.count($targets)." tabel, total {$totalRows} baris:\n");
        foreach ($counts as $table => $count) {
            if ($count > 0) {
                $this->line(sprintf('  %-34s %7d', $table, $count));
            }
        }

        $this->newLine();
        $this->line('Akan dipertahankan: '.implode(', ', $keep));
        $this->newLine();

        if (! $this->option('force')) {
            $this->warn('SIMULASI. Tidak ada yang dihapus.');
            $this->warn('Jalankan ulang dengan --force untuk benar-benar mengosongkan.');

            return self::SUCCESS;
        }

        $this->alert('MENGHAPUS '.$totalRows.' BARIS. Tidak ada kembalian.');
        if (! $keepConfig) {
            $this->alert('Termasuk konfigurasi pembayaran. Kredensial Midtrans/Xendit akan hilang.');
        }

        if (! $this->confirm('Lanjutkan?', false)) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($targets as $table) {
                DB::statement('TRUNCATE TABLE `'.$table.'`');
            }
        } finally {
            // Dipaksa jalan walau truncate gagal di tengah, supaya tidak
            // meninggalkan database dengan FK check mati.
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        DB::statement('ALTER TABLE `users` AUTO_INCREMENT = 2');

        $this->newLine();
        $this->info('Selesai. '.$totalRows.' baris dihapus dari '.count($targets).' tabel.');
        $this->newLine();
        $this->line('Terverifikasi:');

        foreach (array_merge(self::KEEP_ALWAYS, $keepConfig ? self::KEEP_CONFIG : []) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $this->line(sprintf('  %-34s %7d baris', $table, (int) DB::table($table)->count()));
        }

        $this->newLine();
        $this->line('Akun yang masih bisa login:');

        foreach (DB::table('users')->get() as $user) {
            $this->line('  #'.$user->id.' '.$user->email);
        }

        $this->newLine();
        $this->info('Langkah berikutnya:');
        $this->line('  php artisan db:seed --class=UatDemoSeeder   # opsional, data demo UAT');
        $this->line('  php artisan uat:verify-demo               # cek angka');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function allTables(): array
    {
        $names = array_column(
            DB::select(
                'SELECT TABLE_NAME FROM information_schema.TABLES '
                .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = "BASE TABLE" ORDER BY TABLE_NAME'
            ),
            'TABLE_NAME'
        );

        return array_values(array_filter(
            $names,
            fn (string $t) => ! str_starts_with($t, 'migrations')
                && ! in_array($t, ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'], true)
        ));
    }
}
