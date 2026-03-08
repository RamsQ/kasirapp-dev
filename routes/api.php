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
 * Grouping ini menangani alur pembayaran otomatis.
 */
Route::prefix('payment')->group(function () {
    
    // 1. POLLING STATUS
    // Digunakan oleh React di Kasir untuk mengecek apakah user sudah bayar QRIS.
    // Frontend akan memanggil ini setiap 3 detik.
    Route::get('/check/{invoice}', [PaymentController::class, 'checkStatus'])
        ->name('payment.check');
    
    // 2. WEBHOOK (NOTIFICATION)
    // URL yang didaftarkan di Dashboard Midtrans.
    // Midtrans akan "mengetuk" URL ini secara otomatis saat pembayaran lunas.
    // Pastikan fungsi 'handleNotification' ada di PaymentController.
    Route::post('/notification', [PaymentController::class, 'handleNotification'])
        ->name('payment.notification');
        
});

/**
 * ADDITIONAL API
 * Tempat menambahkan API tambahan jika dibutuhkan di masa depan
 */