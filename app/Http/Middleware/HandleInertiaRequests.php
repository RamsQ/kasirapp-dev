<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $lowStock = 0;
        $expired = 0;
        $permissions = []; // Default kosong
        $isSuperAdmin = false;
        $user = $request->user();

        if ($user) {
            // 1. Notifikasi Stok & Expired (Fitur Fix)
            $lowStock = DB::table('products')->where('stock', '<=', 5)->count();
            
            if (Schema::hasColumn('products', 'expired_date')) {
                $expired = DB::table('products')
                    ->whereNotNull('expired_date')
                    ->whereDate('expired_date', '<=', now()->addDays(7))
                    ->count();
            }

            // 2. CEK STATUS SUPER ADMIN
            // Memastikan method isSuperAdmin() ada di Model User
            $isSuperAdmin = method_exists($user, 'isSuperAdmin') ? $user->isSuperAdmin() : $user->hasRole('super-admin');

            // 3. AMBIL PERMISSIONS DAN UBAH KE FORMAT KEY-VALUE
            if ($isSuperAdmin) {
                $permissions = Permission::all()->pluck('name')->mapWithKeys(function ($name) {
                    return [$name => true];
                })->toArray();
            } else {
                $permissions = $user->getAllPermissions()->pluck('name')->mapWithKeys(function ($name) {
                    return [$name => true];
                })->toArray();
            }

            // 4. Eager Load Roles untuk menghindari error undefined di Layout
            $user->load('roles');
        }

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user,
                'permissions' => $permissions, 
                'super' => $isSuperAdmin,
            ],

            'notifications' => [
                'low_stock_count' => (int) $lowStock,
                'expired_count'   => (int) $expired,
            ],

            'flash' => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
            ],
        ]);
    }
}