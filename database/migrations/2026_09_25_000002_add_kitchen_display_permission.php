<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $tableNames = config('permission.table_names');
        $permissionTable = $tableNames['permissions'] ?? 'permissions';
        $rolesTable = $tableNames['roles'] ?? 'roles';
        $rolePermissionTable = $tableNames['role_has_permissions'] ?? 'role_has_permissions';

        if (! Schema::hasTable($permissionTable)) {
            return;
        }

        $permissionId = DB::table($permissionTable)
            ->where('name', 'kitchen-display.index')
            ->where('guard_name', 'web')
            ->value('id');

        if (! $permissionId) {
            $permissionId = DB::table($permissionTable)->insertGetId([
                'name' => 'kitchen-display.index',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable($rolesTable) && Schema::hasTable($rolePermissionTable)) {
            $roleIds = DB::table($rolesTable)
                ->where('guard_name', 'web')
                ->whereIn('name', ['super-admin', 'cashier'])
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table($rolePermissionTable)->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $permissionTable = $tableNames['permissions'] ?? 'permissions';
        $rolePermissionTable = $tableNames['role_has_permissions'] ?? 'role_has_permissions';

        if (! Schema::hasTable($permissionTable)) {
            return;
        }

        $permissionId = DB::table($permissionTable)
            ->where('name', 'kitchen-display.index')
            ->where('guard_name', 'web')
            ->value('id');

        if (! $permissionId) {
            return;
        }

        if (Schema::hasTable($rolePermissionTable)) {
            DB::table($rolePermissionTable)
                ->where('permission_id', $permissionId)
                ->delete();
        }

        DB::table($permissionTable)->where('id', $permissionId)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
