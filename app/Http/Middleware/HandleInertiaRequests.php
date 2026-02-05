<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $lowStock = 0;
        $expired = 0;
        $permissions = []; // Default kosong

        if ($request->user()) {
            // 1. Notifikasi Stok & Expired
            $lowStock = DB::table('products')->where('stock', '<=', 5)->count();
            
            if (Schema::hasColumn('products', 'expired_date')) {
                $expired = DB::table('products')
                    ->whereNotNull('expired_date')
                    ->whereDate('expired_date', '<=', now()->addDays(7))
                    ->count();
            }

            // 2. AMBIL PERMISSIONS DAN UBAH KE FORMAT KEY-VALUE
            // Format yang diharapkan Utils/Permission.jsx: { 'name.permission': true }
            $permissions = $request->user()->getAllPermissions()->pluck('name')->mapWithKeys(function ($name) {
                return [$name => true];
            })->toArray();
        }

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user(),
                'permissions' => $permissions, // Data sudah dalam format { 'key': true }
                'super' => $request->user() ? $request->user()->isSuperAdmin() : false,
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