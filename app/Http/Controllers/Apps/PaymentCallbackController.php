<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\ProductUnit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentCallbackController extends Controller
{
    public function midtrans(Request $request)
    {
        $serverKey = config('services.midtrans.server_key'); // Pastikan server_key ada di config/services.php
        $hashed = hash("sha512", $request->order_id . $request->status_code . $request->gross_amount . $serverKey);

        if ($hashed !== $request->signature_key) {
            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $transaction = Transaction::where('invoice', $request->order_id)->first();

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        $transactionStatus = $request->transaction_status;
        $type = $request->payment_type;

        if ($transactionStatus == 'capture' || $transactionStatus == 'settlement') {
            
            // CEK: Jika status sudah paid, jangan kurangi stok lagi (mencegah double hit)
            if ($transaction->payment_status === 'paid') {
                return response()->json(['message' => 'Already processed'], 200);
            }

            DB::transaction(function () use ($transaction) {
                // 1. Update Status Pembayaran
                $transaction->update(['payment_status' => 'paid']);

                // 2. POTONG STOK (Karena tadi di TransactionController kita skip stok untuk gateway)
                foreach ($transaction->details as $detail) {
                    $product = $detail->product;

                    if ($product->recipes->count() > 0) {
                        foreach ($product->recipes as $recipe) {
                            DB::table('ingredients')->where('id', $recipe->ingredient_id)
                                ->decrement('stock', (float)$recipe->qty_needed * (float)$detail->qty);
                        }
                    } else if ($product->type !== 'bundle') {
                        $conversion = $detail->product_unit_id ? (ProductUnit::find($detail->product_unit_id)->conversion ?? 1) : 1;
                        DB::table('products')->where('id', $detail->product_id)->decrement('stock', $detail->qty * $conversion);
                    }
                }
            });
        } elseif ($transactionStatus == 'expire' || $transactionStatus == 'cancel' || $transactionStatus == 'deny') {
            $transaction->update(['payment_status' => 'failed']);
        }

        return response()->json(['message' => 'OK'], 200);
    }
}