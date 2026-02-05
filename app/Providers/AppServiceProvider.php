<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate; // <--- JANGAN LUPA IMPORT INI
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
        // Kode ini mengizinkan super-admin menembus semua validasi permission
        Gate::before(function ($user, $ability) {
            return $user->hasRole('super-admin') ? true : null;
        });
    }
}