<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Guard default
        $guard = 'web';

        // Daftar Permission Lengkap (Disesuaikan untuk fitur POS, Stok, dan Resep)
        $permissions = [
            // --- DASHBOARD & SISTEM ---
            'dashboard.index',
            'dashboard-access',

            // --- MANAJEMEN USER & AKSES ---
            'users.index', 'users-access', 'users-create', 'users-update', 'users-delete',
            'roles-access', 'roles-create', 'roles-update', 'roles-delete',
            'permissions.index', 'permissions-access', 'permissions-create', 'permissions-update', 'permissions-delete',

            // --- MASTER DATA: KATALOG & PELANGGAN ---
            'categories-access', 'categories-create', 'categories-edit', 'categories-delete',
            'products.index', 'products-access', 'products-create', 'products-edit', 'products-delete',
            'customers-access', 'customers-create', 'customers-edit', 'customers-delete',
            'tables-access', 'tables-create', 'tables-edit', 'tables-delete',

            // --- OPERASIONAL KASIR, SHIFT & DISKON ---
            'transactions.index', 'transactions-access', 'transactions-create', 'transactions-delete',
            'transactions.history', 
            'shifts.index', 'shifts-access',
            'discounts.index', 'discounts-access', 'discounts-create', 'discounts-edit', 'discounts-delete',

            // --- INVENTORY: BAHAN, RESEP & STOK ---
            'ingredients.index', 'ingredients-access', 'ingredients.create', 'ingredients.edit', 'ingredients.delete',
            'recipes.index', 'recipes-access', 'recipes.create', 'recipes.edit', 'recipes.delete',
            'stock-access', 'stock_in.index', 'stock_in.create', 'stock_in.delete',
            'stock_opnames.index', 'stock_opnames.create', 'stock_opnames.delete',

            // --- LAPORAN & FINANCE ---
            'reports.index', 'reports-access', 
            'reports.sales.index', 'reports.products.index', 'reports.expired.index', 'reports.profits.index', 'reports.refund',
            'expired-access', 
            'finance-access', 'report.finance',
            'profits-access',

            // --- PENGATURAN SISTEM ---
            'settings.index', 'settings.update',
            'online_settings.index',
            'payment-settings-access', 'payment_settings.index', 'payment-settings-update',
            'receipt_settings.index', 'receipt_settings.update',
            'settings.bluetooth',
        ];

        // Looping untuk Insert atau Update data
        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission, 'guard_name' => $guard],
                ['name' => $permission, 'guard_name' => $guard]
            );
        }

        // --- AUTOMATISASI: SYNC KE SUPER-ADMIN ---
        // Mencari role super-admin dan memberikan semua permission yang baru saja dibuat.
        // Ini memastikan admin tidak terkunci aksesnya saat ada permission baru.
        $superAdmin = Role::where('name', 'super-admin')->first();
        if ($superAdmin) {
            $superAdmin->syncPermissions(Permission::all());
        }
    }
}