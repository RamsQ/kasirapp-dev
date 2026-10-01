<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Hold;
use App\Models\PaymentSetting;
use App\Services\Payments\HoldPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pembayaran mandiri untuk pesanan menu QR.
 *
 * Berdiri sendiri dari `PaymentController` (yang melayani kasir) karena objek
 * yang dibayar berbeda: di sini yang dibayar adalah `Hold`, bukan `Transaction`.
 * Verifikasi tanda tangan callback TIDAK diulang di sini; callback tetap datang
 * ke `PaymentController` supaya hanya ada satu tempat yang memverifikasi.
 */
class PublicMenuPaymentController extends Controller
{
    public function __construct(protected HoldPaymentService $payments) {}

    /**
     * Buat QRIS untuk satu pesanan.
     */
    public function start(Request $request, Hold $hold)
    {
        $request->validate([
            'provider' => ['required', 'string', 'in:midtrans,xendit'],
        ]);

        // Pesanan sudah dibayar di kasir (sudah jadi transaksi) tidak boleh
        // dibayar lagi dari menu.
        if ($hold->transaction_id) {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan ini sudah diproses di kasir.',
            ], 409);
        }

        if ($hold->payment_status === 'paid') {
            return response()->json([
                'success' => true,
                'status' => 'paid',
                'paid_at' => $hold->paid_at?->toIso8601String(),
                'message' => 'Pesanan ini sudah lunas.',
            ], 200);
        }

        $setting = PaymentSetting::first();
        if (! $setting) {
            return response()->json([
                'success' => false,
                'message' => 'Pengaturan pembayaran belum diatur.',
            ], 503);
        }

        try {
            $result = $this->payments->start($hold, $request->provider, $setting);

            return response()->json([
                'success' => true,
                'ref_number' => $hold->ref_number,
                'queue_number' => $hold->queue_number,
                'provider' => $request->provider,
                'amount' => $this->payments->payableTotal($hold),
                'qr_string' => $result['qr_string'],
                'payment_url' => $result['payment_url'],
                'status' => $hold->fresh()->payment_status,
                'expires_at' => $result['expires_at'],
            ], 200);
        } catch (PaymentGatewayException $exception) {
            Log::warning('QRIS menu gagal dibuat: '.$exception->getMessage(), ['hold_id' => $hold->id]);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            Log::error('QRIS menu error', ['hold_id' => $hold->id, 'exception' => $exception]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat kode QRIS. Silakan coba lagi atau bayar di kasir.',
            ], 500);
        }
    }

    /**
     * Status pembayaran untuk polling halaman pelanggan.
     */
    public function status(Hold $hold)
    {
        if ($hold->transaction_id) {
            return response()->json([
                'success' => true,
                'status' => 'paid',
                'settled_at' => 'cashier',
                'message' => 'Pesanan sudah diproses di kasir.',
            ], 200);
        }

        // QR yang sudah lewat masa berlaku tidak akan pernah dibayar lagi.
        // Ditandai expired supaya pelanggan diarahkan ke kasir, bukan menunggu
        // forever di halaman polling.
        if ($hold->payment_status === 'pending' && $hold->payment_expires_at?->isPast()) {
            $this->payments->markFailed($hold, 'Masa berlaku QR habis.', 'expired');
        }

        return response()->json([
            'success' => true,
            'ref_number' => $hold->ref_number,
            'status' => $hold->fresh()->payment_status,
            'provider' => $hold->payment_method,
            'amount' => $hold->paid_amount ?: $this->payments->payableTotal($hold),
            'paid_at' => $hold->paid_at?->toIso8601String(),
            'expires_at' => $hold->payment_expires_at?->toIso8601String(),
        ], 200);
    }
}
