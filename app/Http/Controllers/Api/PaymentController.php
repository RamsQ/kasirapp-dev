<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentGatewayException;
use App\Exceptions\PaymentSettlementException;
use App\Exceptions\StockUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Hold;
use App\Models\PaymentSetting;
use App\Models\Transaction;
use App\Services\Payments\HoldPaymentService;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Midtrans\Config;
use Throwable;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentSettlementService $settlements,
        protected HoldPaymentService $holdPayments,
    ) {
        $setting = PaymentSetting::first();

        if ($setting) {
            Config::$serverKey = $setting->midtrans_server_key;
            Config::$isProduction = (bool) $setting->midtrans_production;
        } else {
            Config::$serverKey = env('MIDTRANS_SERVER_KEY');
            Config::$isProduction = env('MIDTRANS_IS_PRODUCTION', false);
        }

        Config::$isSanitized = true;
        Config::$is3ds = true;
    }

    /**
     * Read the local settlement state used by the cashier polling screen.
     */
    public function checkStatus($invoice)
    {
        $transaction = Transaction::with('paymentSettlement')
            ->where('invoice', $invoice)
            ->first();

        if (! $transaction) {
            return response()->json([
                'status' => 'deleted',
                'message' => 'Invoice tidak ditemukan atau sudah dibatalkan',
            ], 404);
        }

        $settlement = $transaction->paymentSettlement;

        return response()->json([
            'invoice' => $transaction->invoice,
            'status' => $transaction->payment_status,
            'settlement_status' => $settlement?->status,
            'last_checked_at' => $settlement?->last_checked_at?->toIso8601String(),
            'next_retry_at' => $settlement?->next_retry_at?->toIso8601String(),
            'paid_at' => $transaction->paid_at?->toIso8601String(),
            'settled_at' => $transaction->settled_at?->toIso8601String(),
            'failed_at' => $transaction->failed_at?->toIso8601String(),
            'last_error' => $settlement?->last_error,
        ], 200);
    }

    /**
     * Midtrans notification. Signature verification and state changes are
     * delegated to the canonical settlement service.
     */
    public function handleNotification(Request $request)
    {
        if (! $request->all()) {
            return response()->json(['message' => 'Payment Notification URL is active'], 200);
        }

        $payload = $this->payload($request);
        $orderId = (string) ($payload['order_id'] ?? $payload['transaction_id'] ?? '');
        $status = (string) ($payload['transaction_status'] ?? '');
        $amount = array_key_exists('gross_amount', $payload)
            ? (float) $payload['gross_amount']
            : null;
        $transaction = $orderId !== ''
            ? Transaction::where('invoice', $orderId)->first()
            : null;

        try {
            if ($orderId === '' || $status === '') {
                throw new PaymentSettlementException('Order ID dan status pembayaran wajib diisi.');
            }

            $serverKey = (string) (Config::$serverKey ?: '');
            $expected = hash(
                'sha512',
                $orderId.$status.(string) ($payload['gross_amount'] ?? '').$serverKey,
            );
            if ($serverKey === '' || ! hash_equals($expected, (string) ($payload['signature_key'] ?? ''))) {
                $this->settlements->recordRejectedEvent(
                    provider: PaymentSetting::GATEWAY_MIDTRANS,
                    providerStatus: $status,
                    providerReference: $orderId,
                    payload: $payload,
                    error: 'Signature Midtrans tidak valid.',
                    transaction: $transaction,
                );

                return response()->json(['message' => 'Signature pembayaran tidak valid'], 403);
            }

            if (! $transaction) {
                // Pesanan mandiri dari menu QR dibayar sebelum menjadi
                // transaksi, jadi order_id-nya merujuk ke pesanan menu, bukan
                // invoice kasir. Verifikasi tanda tangan di atas sudah lolos,
                // jadi aman diteruskan ke service pembayaran hold.
                $hold = $this->holdPayments->resolveByReference($orderId);
                if ($hold) {
                    return $this->settleHoldPayment(
                        $hold,
                        $status,
                        $amount,
                        $payload,
                        PaymentSetting::GATEWAY_MIDTRANS,
                    );
                }

                return response()->json(['message' => 'Invoice tidak ditemukan'], 404);
            }

            $statusForSettlement = $status;
            if (strtolower($status) === 'capture'
                && in_array(strtolower((string) ($payload['fraud_status'] ?? '')), ['challenge', 'review'], true)) {
                $statusForSettlement = 'pending';
            }

            $result = $this->settlements->processStatus(
                transaction: $transaction,
                provider: PaymentSetting::GATEWAY_MIDTRANS,
                providerStatus: $statusForSettlement,
                providerReference: $orderId,
                amount: $amount,
                payload: $payload,
                signatureValid: true,
                source: 'midtrans_callback',
            );

            return response()->json([
                'message' => 'Webhook berhasil diproses',
                'result' => $result,
            ], 200);
        } catch (PaymentSettlementException|StockUnavailableException $exception) {
            Log::warning('Payment callback ditolak: '.$exception->getMessage());

            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Log::error('Payment callback error', ['exception' => $exception]);

            return response()->json(['message' => 'Webhook belum dapat diproses'], 500);
        }
    }

    /**
     * Xendit invoice callback. Xendit authenticates callbacks with the
     * configured callback token in the x-callback-token header.
     */
    public function handleXenditNotification(Request $request)
    {
        $payload = $this->payload($request);
        $setting = PaymentSetting::first();
        $orderId = (string) ($payload['external_id'] ?? $payload['order_id'] ?? '');
        $status = (string) ($payload['status'] ?? '');
        $reference = (string) ($payload['id'] ?? '');
        $amount = array_key_exists('amount', $payload) ? (float) $payload['amount'] : null;
        $transaction = $orderId !== ''
            ? Transaction::where('invoice', $orderId)->first()
            : null;

        try {
            $expectedToken = (string) ($setting?->xendit_callback_token
                ?: config('services.xendit.callback_token', env('XENDIT_CALLBACK_TOKEN')));
            $providedToken = (string) $request->header('x-callback-token', '');

            if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
                $this->settlements->recordRejectedEvent(
                    provider: PaymentSetting::GATEWAY_XENDIT,
                    providerStatus: $status,
                    providerReference: $reference,
                    payload: $payload,
                    error: 'Xendit callback token tidak valid.',
                    transaction: $transaction,
                );

                return response()->json(['message' => 'Callback token tidak valid'], 403);
            }

            if (! $transaction) {
                // Sama seperti Midtrans: payment mandiri dari menu QR
                // merujuk ke pesanan menu, bukan invoice kasir.
                $hold = $this->holdPayments->resolveByReference($orderId);
                if ($hold) {
                    return $this->settleHoldPayment(
                        $hold,
                        $status,
                        $amount,
                        $payload,
                        PaymentSetting::GATEWAY_XENDIT,
                    );
                }

                return response()->json(['message' => 'Invoice tidak ditemukan'], 404);
            }
            if ($status === '') {
                throw new PaymentSettlementException('Status Xendit wajib diisi.');
            }

            $result = $this->settlements->processStatus(
                transaction: $transaction,
                provider: PaymentSetting::GATEWAY_XENDIT,
                providerStatus: $status,
                providerReference: $reference ?: null,
                amount: $amount,
                payload: $payload,
                signatureValid: true,
                source: 'xendit_callback',
            );

            return response()->json([
                'message' => 'Xendit callback berhasil diproses',
                'result' => $result,
            ], 200);
        } catch (PaymentSettlementException|StockUnavailableException $exception) {
            Log::warning('Xendit callback ditolak: '.$exception->getMessage());

            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Log::error('Xendit callback error', ['exception' => $exception]);

            return response()->json(['message' => 'Xendit callback belum dapat diproses'], 500);
        }
    }

    /**
     * Terapkan status gateway ke pesanan menu QR.
     *
     * Status SETENGAH PENTING harus dikembalikan dengan success, bukan error.
     * Midtrans mengirim `pending` beberapa kali sebelum `settlement`, dan
     * Xendit mengirim `PENDING` lalu `SUCCEEDED`. Kalau `pending` diperlakukan
     * sebagai kegagalan, gateway akan mencoba mengulang dan menghasilkan
     * banyak callback sia-sia.
     *
     * Yang benar-benar ditolak hanya `expire` dan `deny`, karena di situ
     * pelanggan tidak akan pernah membayar.
     *
     * @param  array<string, mixed>  $payload
     */
    private function settleHoldPayment(
        Hold $hold,
        string $providerStatus,
        ?float $amount,
        array $payload,
        string $provider,
    ) {
        $status = strtoupper($providerStatus);

        $isPending = in_array($status, ['PENDING', 'RECEIVED'], true);
        $isPaid = in_array($status, ['SETTLEMENT', 'CAPTURE', 'SUCCEEDED', 'COMPLETED'], true);
        $isDead = in_array($status, ['EXPIRE', 'EXPIRED', 'CANCEL', 'DENY', 'FAILED', 'VOIDED'], true);

        try {
            if ($isPaid) {
                $result = $this->holdPayments->markPaid(
                    $hold,
                    (float) ($amount ?? $this->holdPayments->payableTotal($hold)),
                    (string) ($payload['order_id'] ?? $payload['transaction_id'] ?? $payload['id'] ?? ''),
                    $payload,
                );
            } elseif ($isPending) {
                $result = 'pending';
            } elseif ($isDead) {
                $result = $this->holdPayments->markFailed(
                    $hold,
                    'Gateway membatalkan pembayaran ('.$status.').',
                    'failed',
                );
            } else {
                // Status yang tidak dikenali: jangan tandai gagal, tapi jangan
                // juga tandai lunas. Tunggu status berikutnya.
                $result = 'ignored:'.$status;
            }

            return response()->json([
                'message' => 'Webhook berhasil diproses',
                'result' => $result,
            ], 200);
        } catch (PaymentGatewayException|Throwable $exception) {
            Log::warning('Pembayaran menu QR ditolak: '.$exception->getMessage(), [
                'hold_id' => $hold->id,
                'provider' => $provider,
            ]);

            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function payload(Request $request): array
    {
        $payload = $request->json()->all();
        if ($payload === []) {
            $payload = $request->all();
        }

        return is_array($payload) ? $payload : [];
    }
}
