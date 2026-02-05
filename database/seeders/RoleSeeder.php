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
    // Refactor the RoleSeeder to improve readability and avoid repetitive code
    public function run(): void
    {
        $this->createRoleWithPermissions('users-access', '%users%');
        $this->createRoleWithPermissions('roles-access', '%roles%');
        $this->createRoleWithPermissions('permission-access', '%permissions%');
        $this->createRoleWithPermissions('categories-access', '%categories%');
        $this->createRoleWithPermissions('products-access', '%products%');
        $this->createRoleWithPermissions('customers-access', '%customers%');
        $this->createRoleWithPermissions('transactions-access', '%transactions%');
        $this->createRoleWithPermissions('reports-access', '%reports%');
        $this->createRoleWithPermissions('profits-access', '%profits%');
        $this->createRoleWithPermissions('payment-settings-access', '%payment-settings%');

        Role::create(['name' => 'super-admin']);

        // Create cashier role with basic permissions for public registration
        $cashierRole        = Role::create(['name' => 'cashier']);
        $cashierPermissions = Permission::whereIn('name', [
            'dashboard-access',
            'transactions-access',
            'customers-access',
            'customers-create',
        ])->get();
        $cashierRole->givePermissionTo($cashierPermissions);
    }

    private function createRoleWithPermissions($roleName, $permissionNamePattern)
    {
        $permissions = Permission::where('name', 'like', $permissionNamePattern)->get();
        $role        = Role::create(['name' => $roleName]);
        $role->givePermissionTo($permissions);
    }
}
