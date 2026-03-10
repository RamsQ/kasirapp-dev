<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your closure based console
| commands. Each closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

// Command bawaan Laravel (Inspirasi)
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

/**
 * PENJADWALAN LAPORAN PENJUALAN OTOMATIS (FULL MANAGED)
 * -----------------------------------------------------------
 * Scheduler ini akan mengecek database (tabel report_settings) setiap menit.
 * Laporan hanya akan dikirim jika:
 * 1. is_active bernilai true (Dinyalakan oleh Owner)
 * 2. Jam sekarang (H:i) cocok dengan kolom send_at di database.
 */
Schedule::command('report:sales-daily')->everyMinute();

Schedule::command('report:monthly')->monthlyOn(1, '01:00');