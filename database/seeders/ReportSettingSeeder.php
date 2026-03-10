<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ReportSetting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ReportSettingSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Buat Permission Hak Akses
        $permissions = [
            'report_settings.index',
            'report_settings.update',
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(['name' => $permission], ['guard_name' => 'web']);
        }

        // 2. Berikan ke Role Super Admin (sesuaikan nama rolenya)
        $role = Role::where('name', 'super-admin')->first();
        if ($role) {
            $role->givePermissionTo($permissions);
        }

        // 3. Buat Data Pengaturan Default (ID 1)
        ReportSetting::updateOrCreate(
            ['id' => 1],
            [
                'is_active'  => false,
                'method'     => 'whatsapp',
                'target'     => '08123456789',
                'send_at'    => '21:00',
                'wa_api_key' => null,
            ]
        );
    }
}
