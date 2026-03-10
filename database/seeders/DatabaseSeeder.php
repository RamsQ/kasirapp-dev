<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // --- BERSIHKAN CACHE PERMISSION SEBELUM SEEDING ---
        // Hal ini sangat penting agar tidak terjadi error 403 
        // akibat cache lama yang masih tersimpan di sistem Spatie.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        
        // Opsional: Menjalankan command clear lewat Artisan
        Artisan::call('permission:cache-reset');

        // --- EKSEKUSI SEMUA SEEDER (FITUR FIX ANDA) ---
        $this->call([
            PermissionSeeder::class,      // 1. Buat daftar hak akses (Kunci)
            RoleSeeder::class,            // 2. Buat grup/peran (Gantungan Kunci)
            UserSeeder::class,            // 3. Buat pengguna & tempelkan role (Pemegang Kunci)
            PaymentSettingSeeder::class,  // 4. Konfigurasi pembayaran (Fix)
            ReportSettingSeeder::class,
            //SampleDataSeeder::class,      // 5. Data dummy untuk testing (Fix)
        ]);

        $this->command->info('Database seeding completed and permission cache cleared!');
    }
}