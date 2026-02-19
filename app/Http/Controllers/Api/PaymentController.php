<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\PaymentSetting;
use Illuminate\Http\Request;
use Midtrans\Config;
use Midtrans\Notification;
use Illuminate\Support\Facades\Log;

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
     * CEK STATUS (Digunakan oleh React/Frontend)
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
     * Mengubah status database menjadi "paid" secara realtime
     */
    public function handleNotification(Request $request)
    {
        try {
            $notif = new Notification();
            $transactionStatus = $notif->transaction_status;
            $orderId = $notif->order_id;

            $transaction = Transaction::where('invoice', $orderId)->first();

            if ($transaction) {
                // Settlement/Capture berarti pembayaran sukses
                if ($transactionStatus == 'settlement' || $transactionStatus == 'capture') {
                    $transaction->update([
                        'payment_status' => 'paid',
                        'cash'           => $transaction->grand_total,
                        'change'         => 0
                    ]);
                    
                    Log::info("Payment Success for Invoice: " . $orderId);
                } 
                // Status gagal
                else if (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
                    $transaction->update(['payment_status' => 'failed']);
                    Log::warning("Payment Failed/Expired for Invoice: " . $orderId);
                }
            }

            return response()->json(['message' => 'Webhook Berhasil Diproses']);
        } catch (\Exception $e) {
            Log::error("Webhook Error: " . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}