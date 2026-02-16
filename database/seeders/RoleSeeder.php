<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Guard default
        $guard = 'web';

        // Membuat Role akses departemen berdasarkan pola nama permission (Fitur Fix Anda)
        $this->createRoleWithPermissions('users-access', '%users%', $guard);
        $this->createRoleWithPermissions('roles-access', '%roles%', $guard);
        $this->createRoleWithPermissions('permission-access', '%permissions%', $guard);
        $this->createRoleWithPermissions('categories-access', '%categories%', $guard);
        $this->createRoleWithPermissions('products-access', '%products%', $guard);
        $this->createRoleWithPermissions('customers-access', '%customers%', $guard);
        $this->createRoleWithPermissions('transactions-access', '%transactions%', $guard);
        $this->createRoleWithPermissions('reports-access', '%reports%', $guard);
        $this->createRoleWithPermissions('profits-access', '%profits%', $guard);
        $this->createRoleWithPermissions('payment-settings-access', '%payment-settings%', $guard);

        // Create super-admin role
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => $guard]);

        // Create cashier role with basic permissions for public registration
        $cashierRole = Role::firstOrCreate(['name' => 'cashier', 'guard_name' => $guard]);
        
        // Daftar Permission untuk Kasir (Disesuaikan dengan kunci baru di web.php)
        $cashierPermissions = Permission::whereIn('name', [
            'dashboard.index',      // Akses masuk dashboard
            'dashboard-access',     // Legacy support
            'transactions.index',   // Akses menu kasir
            'transactions-access',  // Legacy support
            'shifts.index',         // Buka/Tutup shift (Penting untuk operasional)
            'customers-access',
            'customers-create',
        ])->get();

        $cashierRole->syncPermissions($cashierPermissions);
    }

    /**
     * Helper untuk membuat role dan memberikan permission berdasarkan pola nama
     */
    private function createRoleWithPermissions($roleName, $permissionNamePattern, $guard = 'web')
    {
        $permissions = Permission::where('name', 'like', $permissionNamePattern)->get();
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);
        $role->syncPermissions($permissions);
    }
}