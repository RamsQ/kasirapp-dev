<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\PaymentSetting;
use App\Models\ProductUnit;
use Illuminate\Http\Request;
use Midtrans\Config;
use Midtrans\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function __construct()
    {
        // Mengambil setting dari database agar sinkron dengan dashboard admin
        $setting = PaymentSetting::first();

        if ($setting) {
            Config::$serverKey    = $setting->midtrans_server_key;
            Config::$isProduction = (bool) $setting->midtrans_production;
        } else {
            // Fallback ke ENV jika setting database belum ada
            Config::$serverKey    = env('MIDTRANS_SERVER_KEY');
            Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
        }

        Config::$isSanitized = true;
        Config::$is3ds       = true;
    }

    /**
     * CEK STATUS (Digunakan oleh React/Frontend untuk Polling)
     * Untuk fitur polling agar struk otomatis tercetak saat bayar lunas
     */
    public function checkStatus($invoice)
    {
        $transaction = Transaction::where('invoice', $invoice)->first();

        if (!$transaction) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invoice tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'invoice' => $transaction->invoice,
            'status'  => $transaction->payment_status, // 'paid' atau 'pending'
        ]);
    }

    /**
     * WEBHOOK NOTIFICATION (Diketuk otomatis oleh server Midtrans)
     * Mengubah status database menjadi "paid" secara realtime dan potong stok
     */
    public function handleNotification(Request $request)
    {
        // --- FIX: Proteksi untuk Tombol "Tes URL" di Dashboard Midtrans ---
        // Midtrans sering mengirimkan request kosong saat pengetesan URL.
        // Jika request kosong, kita langsung balas 200 OK agar tes berhasil.
        if (!$request->all()) {
            return response()->json(['message' => 'Payment Notification URL is active'], 200);
        }

        try {
            $notif = new Notification();
            $transactionStatus = $notif->transaction_status;
            $orderId = $notif->order_id;

            // Load transaksi beserta detail dan resep produknya (Eager Loading)
            $transaction = Transaction::with('details.product.recipes')->where('invoice', $orderId)->first();

            if ($transaction) {
                // 1. CEK APAKAH SUDAH LUNAS (Agar tidak potong stok 2x jika ada notifikasi ganda)
                if ($transaction->payment_status === 'paid') {
                    return response()->json(['message' => 'Transaction already processed'], 200);
                }

                // 2. STATUS SUKSES (Settlement atau Capture)
                if ($transactionStatus == 'settlement' || $transactionStatus == 'capture') {
                    
                    DB::transaction(function () use ($transaction, $orderId) {
                        // A. Update Status Database
                        $transaction->update([
                            'payment_status' => 'paid',
                            'cash'           => $transaction->grand_total,
                            'change'         => 0
                        ]);

                        // B. LOGIKA POTONG STOK (Sesuai dengan logika TransactionController)
                        foreach ($transaction->details as $detail) {
                            $product = $detail->product;

                            if ($product && $product->recipes->count() > 0) {
                                // Jika produk menggunakan Resep (Bahan Baku)
                                foreach ($product->recipes as $recipe) {
                                    DB::table('ingredients')
                                        ->where('id', $recipe->ingredient_id)
                                        ->decrement('stock', (float)$recipe->qty_needed * (float)$detail->qty);
                                }
                            } else if ($product && $product->type !== 'bundle') {
                                // Jika produk biasa (bukan bundle), potong stok produk langsung
                                $conversion = $detail->product_unit_id ? (ProductUnit::find($detail->product_unit_id)->conversion ?? 1) : 1;
                                DB::table('products')
                                    ->where('id', $detail->product_id)
                                    ->decrement('stock', $detail->qty * $conversion);
                            }
                        }
                        
                        Log::info("Payment Webhook Success & Stock Updated: " . $orderId);
                    });
                } 
                
                // 3. STATUS GAGAL / EXPIRED / BATAL
                else if (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
                    $transaction->update(['payment_status' => 'failed']);
                    Log::warning("Payment Webhook Failed/Expired: " . $orderId);
                }
            }

            return response()->json(['message' => 'Webhook Berhasil Diproses'], 200);

        } catch (\Exception $e) {
            // Jika ada error, kita tetap kirimkan info ke log namun pastikan sistem tetap membalas Midtrans
            Log::error("Webhook Error: " . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}