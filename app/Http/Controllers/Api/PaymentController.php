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
     * FIX: Mengembalikan 404 jika transaksi dihapus (Batal X) agar polling di frontend BERHENTI.
     */
    public function checkStatus($invoice)
    {
        // Cari transaksi berdasarkan invoice
        $transaction = Transaction::where('invoice', $invoice)->first();

        // JIKA TRANSAKSI TIDAK ADA (Karena sudah dihapus saat kasir klik X)
        if (!$transaction) {
            return response()->json([
                'status'  => 'deleted',
                'message' => 'Invoice tidak ditemukan atau sudah dibatalkan'
            ], 404); // Response 404 akan memicu .catch() di Axios/Frontend untuk clearInterval
        }

        // Kembalikan status payment saat ini
        return response()->json([
            'invoice' => $transaction->invoice,
            'status'  => $transaction->payment_status, // 'paid', 'pending', atau 'failed'
        ], 200);
    }

    /**
     * WEBHOOK NOTIFICATION (Diketuk otomatis oleh server Midtrans)
     * Mengubah status database menjadi "paid" secara realtime dan potong stok
     */
    public function handleNotification(Request $request)
    {
        // --- FIX: Proteksi untuk Tombol "Tes URL" di Dashboard Midtrans ---
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
                // 1. CEK APAKAH SUDAH LUNAS (Cegah double processing)
                if ($transaction->payment_status === 'paid') {
                    return response()->json(['message' => 'Transaction already processed'], 200);
                }

                // 2. STATUS SUKSES (Settlement atau Capture)
                if ($transactionStatus == 'settlement' || $transactionStatus == 'capture') {
                    
                    DB::transaction(function () use ($transaction, $orderId) {
                        // A. Update Status Database ke PAID
                        $transaction->update([
                            'payment_status' => 'paid',
                            'cash'           => $transaction->grand_total,
                            'change'         => 0
                        ]);

                        // B. LOGIKA POTONG STOK (Sinkron dengan TransactionController)
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
                                // Jika produk biasa (bukan bundle), potong stok berdasarkan konversi satuan
                                $conversion = $detail->product_unit_id ? (ProductUnit::find($detail->product_unit_id)->conversion ?? 1) : 1;
                                DB::table('products')
                                    ->where('id', $detail->product_id)
                                    ->decrement('stock', $detail->qty * $conversion);
                            }
                        }
                        
                        Log::info("Payment Webhook Success & Stock Updated: " . $orderId);
                    });
                } 
                
                // 3. STATUS GAGAL / EXPIRED / BATAL DARI SISI MIDTRANS
                else if (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
                    $transaction->update(['payment_status' => 'failed']);
                    Log::warning("Payment Webhook Failed/Expired: " . $orderId);
                }
            }

            return response()->json(['message' => 'Webhook Berhasil Diproses'], 200);

        } catch (\Exception $e) {
            // Tetap log error namun berikan respon agar Midtrans tidak mengirim notifikasi berulang
            Log::error("Webhook Error: " . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}