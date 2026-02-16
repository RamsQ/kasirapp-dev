<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Buat atau Update User Admin
        $user = User::updateOrCreate(
            ['email' => 'admin@gmail.com'], // Cari berdasarkan email ini
            [
                'name'     => 'Super Administrator',
                'password' => Hash::make('password'), // Password default: password
            ]
        );

        // 2. Ambil Role super-admin (Pastikan RoleSeeder sudah dijalankan)
        $role = Role::where('name', 'super-admin')->first();

        if ($role) {
            // Berikan role ke user
            $user->assignRole($role);
        }
    }
}