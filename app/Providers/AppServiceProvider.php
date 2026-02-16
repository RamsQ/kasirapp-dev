<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // --- LOGIC SUPER ADMIN (KARTU SAKTI) ---
        // Kita gunakan Gate::after atau Gate::before. 
        // Gate::before dijalankan SEBELUM pengecekan permission Spatie.
        Gate::before(function ($user, $ability) {
            // Kita cek menggunakan method isSuperAdmin() yang sudah kita buat di Model User tadi
            return $user->hasRole('super-admin') ? true : null;
        });
    }
}