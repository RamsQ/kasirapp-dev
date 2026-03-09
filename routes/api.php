<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PaymentController;

/*
|--------------------------------------------------------------------------
| API Routes - POS PWA
|--------------------------------------------------------------------------
|
| Semua route di file ini otomatis mendapatkan prefix "api/".
| Route ini digunakan untuk komunikasi data antara Frontend (React) 
| dan Backend (Laravel) serta integrasi pihak ketiga (Midtrans).
|
*/

/**
 * AUTH USER DATA
 * Digunakan untuk mengecek status login user via token (Sanctum)
 */
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/**
 * PEMBAYARAN GATEWAY (MIDTRANS / XENDIT)
 * Grouping ini menangani alur pembayaran otomatis secara Real-time.
 */
Route::prefix('payment')->group(function () {
    
    /**
     * 1. POLLING STATUS (FRONTEND CHECK)
     * Digunakan oleh React di halaman Kasir untuk mengecek status transaksi.
     * Frontend akan memanggil ini secara berkala (Polling) setiap 3 detik.
     */
    Route::get('/check/{invoice}', [PaymentController::class, 'checkStatus'])
        ->name('payment.check');
    
    /**
     * 2. WEBHOOK / CALLBACK (SERVER TO SERVER)
     * URL utama yang didaftarkan pada Dashboard Midtrans (Settings > Configuration).
     * Midtrans akan mengirimkan data JSON ke sini setiap ada perubahan status (Settlement/Expire).
     * Rute ini bersifat publik karena diakses langsung oleh server Midtrans.
     */
    Route::post('/notification', [PaymentController::class, 'handleNotification'])
        ->name('payment.notification');
        
});

/**
 * ADDITIONAL API
 * Tempat menambahkan API tambahan jika dibutuhkan di masa depan.
 * Pastikan rute baru tetap mengikuti standar keamanan yang ada.
 */