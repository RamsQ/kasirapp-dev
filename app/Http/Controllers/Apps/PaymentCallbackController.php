<?php

namespace App\Http\Controllers;

use App\Models\PaymentSetting;
use App\Models\Transaction;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentCallbackController extends Controller
{
    public function __construct(protected PaymentSettlementService $settlements) {}

    /**
     * Legacy Midtrans endpoint. The canonical settlement service owns state
     * transitions and stock consumption for every callback source.
     */
    public function midtrans(Request $request)
    {
        $payload = $request->json()->all() ?: $request->all();
        $orderId = (string) ($payload['order_id'] ?? $payload['transaction_id'] ?? '');
        $status = (string) ($payload['transaction_status'] ?? '');
        $transaction = $orderId ? Transaction::where('invoice', $orderId)->first() : null;
        $serverKey = (string) (config('services.midtrans.server_key') ?: PaymentSetting::first()?->midtrans_server_key);

        try {
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

                return response()->json(['message' => 'Invalid signature'], 403);
            }

            if (! $transaction) {
                return response()->json(['message' => 'Transaction not found'], 404);
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
                amount: array_key_exists('gross_amount', $payload) ? (float) $payload['gross_amount'] : null,
                payload: $payload,
                signatureValid: true,
                source: 'legacy_midtrans_callback',
            );

            return response()->json(['message' => 'OK', 'result' => $result], 200);
        } catch (Throwable $exception) {
            Log::error('Legacy Midtrans callback error', ['exception' => $exception]);

            return response()->json(['message' => 'Callback belum dapat diproses'], 500);
        }
    }
}
