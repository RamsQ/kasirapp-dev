<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CallbackController extends Controller
{
    /**
     * Handle Midtrans Notification (Webhook)
     */
    public function notification(Request $request)
    {
        $payload = $request->getContent();
        $notification = json_decode($payload);

        // Validasi signature key (Opsional tapi disarankan demi keamanan)
        // signature = hash('sha512', order_id + status_code + gross_amount + server_key)

        $transactionStatus = $notification->transaction_status;
        $orderId = $notification->order_id; // Ini adalah nomor invoice kita

        $transaction = Transaction::with('details.product')->where('invoice', $orderId)->first();

        if (!$transaction) {
            return response()->json(['message' => 'Invoice tidak ditemukan'], 404);
        }

        // LOGIKA UTAMA: Hanya proses jika statusnya 'settlement' (LUNAS) 
        // dan status transaksi di database kita masih 'pending'
        if ($transactionStatus == 'settlement' && $transaction->payment_status == 'pending') {
            
            DB::transaction(function () use ($transaction) {
                // 1. Update Status Pembayaran di Database
                $transaction->update(['payment_status' => 'paid']);

                // 2. POTONG STOK SEKARANG (Karena baru benar-benar lunas)
                foreach ($transaction->details as $detail) {
                    $product = $detail->product;

                    // Jika produk pakai resep (Bahan Baku)
                    if ($product->recipes->count() > 0) {
                        foreach ($product->recipes as $recipe) {
                            DB::table('ingredients')->where('id', $recipe->ingredient_id)
                                ->decrement('stock', (float)$recipe->qty_needed * (float)$detail->qty);
                        }
                    } 
                    // Jika produk biasa (Bukan bundling)
                    else if ($product->type !== 'bundle') {
                        $conversion = 1;
                        if ($detail->product_unit_id) {
                            $unit = ProductUnit::find($detail->product_unit_id);
                            $conversion = $unit ? $unit->conversion : 1;
                        }
                        $product->decrement('stock', $detail->qty * $conversion);
                    }
                }

                // 3. Hapus Keranjang Kasir (Cari keranjang yang isinya sama dengan detail transaksi)
                Cart::where('cashier_id', $transaction->cashier_id)->delete();
            });

            return response()->json(['message' => 'Stok berhasil dipotong, transaksi lunas!'], 200);
        }

        // Jika transaksi expire atau cancel
        if (in_array($transactionStatus, ['expire', 'cancel', 'deny'])) {
            $transaction->update(['payment_status' => 'failed']);
        }

        return response()->json(['message' => 'Notification processed'], 200);
    }
}