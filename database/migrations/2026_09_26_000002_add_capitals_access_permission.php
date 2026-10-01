<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Menambahkan permission capitals.index.
 *
 * Tabel `capitals` sudah dibaca laporan neraca, tapi tidak punya route maupun
 * halaman sehingga setiap baris "Modal" selalu Rp 0. Permission ini membuka
 * pencatatan setoran modal pemilik.
 */
return new class extends Migration
{
    private const PERMISSION = 'capitals.index';

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
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        if (! $permissionId) {
            $permissionId = DB::table($permissionTable)->insertGetId([
                'name' => self::PERMISSION,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable($rolesTable) && Schema::hasTable($rolePermissionTable)) {
            $superAdminRoleId = DB::table($rolesTable)
                ->where('name', 'super-admin')
                ->where('guard_name', 'web')
                ->value('id');

            if ($superAdminRoleId) {
                DB::table($rolePermissionTable)->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $superAdminRoleId,
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
            ->where('name', self::PERMISSION)
            ->value('id');

        if (! $permissionId) {
            return;
        }

        if (Schema::hasTable($rolePermissionTable)) {
            DB::table($rolePermissionTable)
                ->where('permission_id', $permissionId)
                ->delete();
        }

        DB::table($permissionTable)
            ->where('id', $permissionId)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
