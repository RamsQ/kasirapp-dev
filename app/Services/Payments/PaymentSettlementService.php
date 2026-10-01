<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentSettlementException;
use App\Models\Hold;
use App\Models\PaymentEvent;
use App\Models\PaymentSetting;
use App\Models\PaymentSettlement;
use App\Models\Table;
use App\Models\Transaction;
use App\Services\CogsService;
use App\Services\LoyaltyService;
use App\Services\StockIntelligenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PaymentSettlementService
{
    /** @var array<int, true> */
    private array $processingFailuresRegistered = [];

    public function __construct(
        private readonly MidtransGateway $midtransGateway,
        private readonly XenditGateway $xenditGateway,
        private readonly StockIntelligenceService $stockIntelligence,
        private readonly LoyaltyService $loyalty,
        private readonly CogsService $cogs,
    ) {}

    /**
     * Register a pending gateway payment before contacting the provider.
     */
    public function begin(Transaction $transaction, string $provider): PaymentSettlement
    {
        $provider = $this->normalizeProvider($provider);
        $settlement = PaymentSettlement::firstOrNew([
            'transaction_id' => $transaction->id,
        ]);

        if (! $settlement->exists || ! in_array($settlement->status, ['paid', 'refunded'], true)) {
            $settlement->fill([
                'provider' => $provider,
                'provider_reference' => $settlement->provider_reference
                    ?: ($transaction->payment_reference
                        ?: ($provider === PaymentSetting::GATEWAY_MIDTRANS ? $transaction->invoice : null)),
                'status' => 'pending',
                'amount' => $transaction->grand_total,
                'currency' => 'IDR',
                'next_retry_at' => now(),
                'last_error' => null,
            ]);
            $settlement->save();
        }

        return $settlement->fresh();
    }

    /**
     * Save the provider reference returned after payment creation.
     */
    public function attachProviderReference(
        Transaction $transaction,
        string $provider,
        ?string $reference,
        ?string $paymentUrl,
        array $metadata = [],
    ): PaymentSettlement {
        return DB::transaction(function () use ($transaction, $provider, $reference, $paymentUrl, $metadata): PaymentSettlement {
            $settlement = PaymentSettlement::where('transaction_id', $transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $isTerminal = in_array($settlement->status, ['paid', 'failed', 'refunded'], true);
            $settlement->update([
                'provider' => $this->normalizeProvider($provider),
                'provider_reference' => $reference ?: $settlement->provider_reference,
                'status' => $isTerminal ? $settlement->status : 'pending',
                'next_retry_at' => $isTerminal ? null : now(),
                'metadata' => array_merge($settlement->metadata ?: [], $metadata),
                'last_error' => null,
            ]);

            $transaction->update([
                'payment_reference' => $reference ?: $transaction->payment_reference,
                'payment_url' => $paymentUrl ?: $transaction->payment_url,
            ]);

            $this->recordEvent(
                transaction: $transaction,
                settlement: $settlement,
                provider: $this->normalizeProvider($provider),
                providerReference: $reference ?: $settlement->provider_reference,
                status: 'created',
                amount: (float) $transaction->grand_total,
                signatureValid: true,
                payload: ['payment_url_created' => filled($paymentUrl)],
                source: 'gateway_create',
            );

            return $settlement->fresh();
        });
    }

    /**
     * Mark a provider creation failure without deleting the transaction.
     */
    public function markGatewayFailure(Transaction $transaction, string $provider, string $message): void
    {
        $settlement = $this->begin($transaction, $provider);

        DB::transaction(function () use ($transaction, $settlement, $message): void {
            $lockedTransaction = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $lockedSettlement = PaymentSettlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            if ($lockedTransaction->payment_status === 'paid') {
                return;
            }

            $lockedTransaction->update([
                'payment_status' => 'failed',
                'failed_at' => now(),
            ]);
            $lockedSettlement->update([
                'status' => 'failed',
                'failed_at' => now(),
                'next_retry_at' => null,
                'last_error' => Str::limit($message, 1000),
            ]);
            $this->releaseHold(
                Hold::where('transaction_id', $lockedTransaction->id)->lockForUpdate()->first(),
            );
            $this->releaseCogs($lockedTransaction);
            $this->releaseLoyaltyVoucher($lockedTransaction);
        });
    }

    /**
     * Cancel a pending cashier-originated payment without deleting its audit
     * record. A later provider settlement callback can still reopen it.
     */
    public function cancelPending(Transaction $transaction, string $reason = 'Cancelled by cashier'): void
    {
        if ($transaction->payment_status === 'paid') {
            return;
        }

        $provider = $this->normalizeProvider($transaction->payment_method);
        $this->processStatus(
            transaction: $transaction,
            provider: $provider,
            providerStatus: 'cancel',
            providerReference: $transaction->payment_reference,
            amount: null,
            payload: ['reason' => $reason],
            signatureValid: true,
            source: 'cashier_cancel',
        );
    }

    /**
     * Record a callback/reconciliation event and apply the canonical state.
     * The event is written outside the state transaction so a stock conflict
     * remains auditable and can be retried later.
     *
     * @return array{status: string, changed: bool, duplicate: bool}
     */
    public function processStatus(
        Transaction $transaction,
        string $provider,
        string $providerStatus,
        ?string $providerReference = null,
        ?float $amount = null,
        array $payload = [],
        bool $signatureValid = true,
        string $source = 'callback',
        ?string $eventId = null,
    ): array {
        $provider = $this->normalizeProvider($provider);
        if ($transaction->payment_method && strtolower($transaction->payment_method) !== $provider) {
            $this->recordRejectedEvent(
                provider: $provider,
                providerStatus: $providerStatus,
                providerReference: $providerReference,
                payload: $payload,
                error: 'Provider callback tidak cocok dengan metode transaksi.',
                transaction: $transaction,
            );

            throw new PaymentSettlementException('Provider callback tidak cocok dengan metode transaksi.');
        }
        $status = $this->normalizeStatus($providerStatus);
        $settlement = PaymentSettlement::firstOrNew(['transaction_id' => $transaction->id]);

        if (! $settlement->exists) {
            $settlement->fill([
                'provider' => $provider,
                'provider_reference' => $providerReference,
                'status' => $status,
                'amount' => $amount ?? $transaction->grand_total,
                'currency' => 'IDR',
            ]);
            $settlement->save();
        }

        $event = $this->recordEvent(
            transaction: $transaction,
            settlement: $settlement,
            provider: $provider,
            providerReference: $providerReference ?: $settlement->provider_reference,
            status: $providerStatus,
            amount: $amount,
            signatureValid: $signatureValid,
            payload: $payload,
            source: $source,
            eventId: $eventId,
        );

        try {
            $result = DB::transaction(function () use (
                $transaction,
                $settlement,
                $provider,
                $providerReference,
                $amount,
                $status,
            ): array {
                $lockedTransaction = Transaction::with([
                    'details.product.recipes.ingredient',
                    'details.product.bundle_items.units',
                    'details.product_unit',
                ])
                    ->whereKey($transaction->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedSettlement = PaymentSettlement::whereKey($settlement->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($amount !== null && abs($amount - (float) $lockedTransaction->grand_total) > 0.009) {
                    throw new PaymentSettlementException('Nominal settlement tidak cocok dengan tagihan transaksi.');
                }

                $lockedSettlement->update([
                    'provider' => $provider,
                    'provider_reference' => $providerReference ?: $lockedSettlement->provider_reference,
                    'amount' => $amount ?? $lockedSettlement->amount,
                    'attempt_count' => $lockedSettlement->attempt_count + 1,
                    'last_checked_at' => now(),
                    'last_error' => null,
                ]);
                if ($providerReference) {
                    $lockedTransaction->update(['payment_reference' => $providerReference]);
                }

                if ($lockedTransaction->status === 'refunded') {
                    $lockedSettlement->update(['status' => 'refunded', 'next_retry_at' => null]);

                    return ['status' => 'refunded', 'changed' => false, 'duplicate' => true];
                }

                if ($lockedTransaction->payment_status === 'paid' && in_array($status, ['pending', 'unknown'], true)) {
                    $lockedSettlement->update(['status' => 'paid', 'next_retry_at' => null]);

                    return ['status' => 'paid', 'changed' => false, 'duplicate' => true];
                }

                if ($status === 'paid') {
                    return $this->applyPaid($lockedTransaction, $lockedSettlement);
                }

                if ($status === 'failed') {
                    return $this->applyFailed($lockedTransaction, $lockedSettlement);
                }

                if ($status === 'refunded') {
                    return $this->applyRefunded($lockedTransaction, $lockedSettlement);
                }

                // Settlement yang sudah `expired` tidak boleh dihidupkan
                // lagi oleh laporan "masih menunggu", karena itu akan
                // mengulang siklus retry tanpa batas. Statusnya dibiarkan
                // expired; kalau provider membalas paid/refunded, cabang di
                // atas sudah menanganinya.
                $wasExpired = $lockedSettlement->status === 'expired';

                $lockedSettlement->update([
                    'status' => $wasExpired ? 'expired' : 'pending',
                    'next_retry_at' => $wasExpired ? null : now()->addMinutes(5),
                ]);
                if ($lockedTransaction->payment_status !== 'paid') {
                    $lockedTransaction->update(['payment_status' => 'pending']);
                }

                return [
                    'status' => $wasExpired ? 'expired' : 'pending',
                    'changed' => ! $wasExpired,
                    'duplicate' => $wasExpired,
                ];
            });
        } catch (Throwable $exception) {
            $event->update([
                'processed' => false,
                'processing_error' => Str::limit($exception->getMessage(), 1000),
            ]);
            $this->registerProcessingFailure($settlement, $exception);
            $this->processingFailuresRegistered[(int) $settlement->id] = true;

            throw $exception;
        }

        $event->update([
            'processed' => true,
            'processing_error' => null,
        ]);
        unset($this->processingFailuresRegistered[(int) $settlement->id]);

        return $result;
    }

    /**
     * Reconcile one settlement using the provider status API.
     */
    public function reconcileOne(PaymentSettlement $settlement, ?PaymentSetting $setting = null): array
    {
        $settlement->loadMissing('transaction');
        $transaction = $settlement->transaction;
        if (! $transaction) {
            throw new PaymentSettlementException('Transaksi settlement tidak ditemukan.');
        }

        $setting ??= PaymentSetting::first();
        if (! $setting) {
            throw new PaymentSettlementException('Konfigurasi payment gateway belum tersedia.');
        }

        $reference = $settlement->provider_reference;
        if (! $reference && $settlement->provider === PaymentSetting::GATEWAY_MIDTRANS) {
            $reference = $transaction->invoice;
        }
        if (! $reference) {
            throw new PaymentSettlementException('Reference provider belum tersedia.');
        }

        $result = match ($settlement->provider) {
            PaymentSetting::GATEWAY_MIDTRANS => $this->midtransGateway->fetchStatus(
                $reference,
                $setting->midtransConfig(),
            ),
            PaymentSetting::GATEWAY_XENDIT => $this->xenditGateway->fetchStatus(
                $reference,
                $setting->xenditConfig(),
            ),
            default => throw new PaymentSettlementException('Provider settlement tidak didukung.'),
        };

        return $this->processStatus(
            transaction: $transaction,
            provider: $settlement->provider,
            providerStatus: $result['status'],
            providerReference: $result['provider_reference'] ?: $reference,
            amount: $result['amount'],
            payload: $result['raw'],
            signatureValid: true,
            source: 'reconciliation',
        );
    }

    public function ensureSettlements(int $limit = 100): void
    {
        $this->createMissingSettlements($limit);
    }

    /**
     * Berhenti meng-gecek settlement yang sudah menggantung terlalu lama.
     *
     * Tanpa ini, transaksi yang ditinggalkan pelanggan (mis. QRIS dibuka lalu
     * pergi tanpa bayar) akan dipanggil ke API provider selamanya -- satu kali
     * per jam, tanpa batas.
     *
     * Statusnya `expired`, BUKAN `failed`: uang belum tentu hilang, jadi
     * callback yang telat tetap boleh menyelesaikan transaksi seperti biasa.
     * Transaksi juga dibiarkan `pending` agar reservasi stok/HPP tidak
     * dilepas sebelum diketahui hasilnya.
     */
    public function expireStaleSettlements(int $limit = 200): int
    {
        $threshold = max(1, (int) config('services.settlement.expire_after_minutes', 1440));
        $maxAttempts = max(0, (int) config('services.settlement.max_attempts', 0));

        $stale = PaymentSettlement::query()
            ->stale()
            ->when($maxAttempts > 0, fn ($query) => $query->where('attempt_count', '>=', $maxAttempts))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $expired = 0;

        foreach ($stale as $settlement) {
            $transaction = $settlement->transaction;

            // Transaksi yang sudah beres tidak perlu ditandai expired.
            if ($transaction && in_array($transaction->payment_status, ['paid', 'failed', 'refunded'], true)) {
                $settlement->update([
                    'status' => $transaction->payment_status,
                    'next_retry_at' => null,
                ]);

                continue;
            }

            PaymentEvent::create([
                'transaction_id' => $settlement->transaction_id,
                'payment_settlement_id' => $settlement->id,
                'provider' => $settlement->provider,
                'event_id' => 'expire-'.$settlement->id.'-'.now()->getTimestamp(),
                'status' => 'expired',
                'provider_reference' => $settlement->provider_reference,
                'amount' => $settlement->amount,
                'signature_valid' => true,
                'processed' => true,
                'payload' => [
                    'source' => 'auto_expire',
                    'pending_minutes' => $settlement->pendingMinutes(),
                    'attempt_count' => $settlement->attempt_count,
                    'threshold_minutes' => $threshold,
                ],
                'occurred_at' => now(),
                'received_at' => now(),
            ]);

            $settlement->update([
                'status' => 'expired',
                'next_retry_at' => null,
            ]);

            $expired++;
        }

        if ($expired > 0) {
            Log::info('Settlement menggantung ditandai expired.', [
                'count' => $expired,
                'threshold_minutes' => $threshold,
            ]);
        }

        return $expired;
    }

    /**
     * Reconcile pending settlements, including legacy pending transactions
     * created before the settlement center existed.
     *
     * @return array{checked: int, paid: int, failed: int, pending: int, expired: int, errors: int}
     */
    public function reconcilePending(int $limit = 50, ?PaymentSetting $setting = null): array
    {
        $this->createMissingSettlements($limit);
        $this->expireStaleSettlements();

        $settlements = PaymentSettlement::query()
            ->with('transaction')
            ->whereIn('status', ['pending', 'unknown'])
            ->dueForReconciliation()
            ->orderBy('next_retry_at')
            ->limit($limit)
            ->get();

        $result = [
            'checked' => 0,
            'paid' => 0,
            'failed' => 0,
            'pending' => 0,
            'expired' => 0,
            'errors' => 0,
        ];

        foreach ($settlements as $settlement) {
            $result['checked']++;
            try {
                $status = $this->reconcileOne($settlement, $setting)['status'];
                match ($status) {
                    'paid' => $result['paid']++,
                    'failed' => $result['failed']++,
                    'pending' => $result['pending']++,
                    default => null,
                };
            } catch (Throwable $exception) {
                $result['errors']++;
                if (! isset($this->processingFailuresRegistered[(int) $settlement->id])) {
                    $this->registerProcessingFailure($settlement, $exception);
                }
                unset($this->processingFailuresRegistered[(int) $settlement->id]);
                Log::warning('Payment reconciliation failed', [
                    'transaction_id' => $settlement->transaction_id,
                    'provider' => $settlement->provider,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Store a rejected callback as an audit event without applying it.
     */
    public function recordRejectedEvent(
        string $provider,
        string $providerStatus,
        ?string $providerReference,
        array $payload,
        string $error,
        ?Transaction $transaction = null,
    ): PaymentEvent {
        $settlement = $transaction
            ? PaymentSettlement::where('transaction_id', $transaction->id)->first()
            : null;

        return $this->recordEvent(
            transaction: $transaction,
            settlement: $settlement,
            provider: $provider,
            providerReference: $providerReference,
            status: $providerStatus,
            amount: null,
            signatureValid: false,
            payload: $payload,
            source: 'rejected_callback',
            processingError: $error,
        );
    }

    private function applyPaid(Transaction $transaction, PaymentSettlement $settlement): array
    {
        if ($transaction->payment_status === 'paid') {
            $now = now();
            $transaction->update([
                'paid_at' => $transaction->paid_at ?: $now,
                'settled_at' => $transaction->settled_at ?: $now,
            ]);
            $settlement->update([
                'status' => 'paid',
                'settled_amount' => $settlement->settled_amount ?: $settlement->amount,
                'settled_at' => $settlement->settled_at ?: $now,
                'next_retry_at' => null,
            ]);
            $this->settleCogs($transaction);
            $this->settleLoyalty($transaction);

            return ['status' => 'paid', 'changed' => false, 'duplicate' => true];
        }

        $hold = Hold::where('transaction_id', $transaction->id)
            ->lockForUpdate()
            ->first();
        $this->stockIntelligence->consumeForCheckout($transaction->details, $hold?->id);

        $now = now();
        $transaction->update([
            'payment_status' => 'paid',
            'paid_at' => $transaction->paid_at ?: $now,
            'settled_at' => $transaction->settled_at ?: $now,
            'cash' => $transaction->grand_total,
            'change' => 0,
        ]);
        $settlement->update([
            'status' => 'paid',
            'settled_amount' => $settlement->settled_amount ?: $settlement->amount,
            'settled_at' => $settlement->settled_at ?: $now,
            'next_retry_at' => null,
        ]);
        $this->releaseHold($hold);
        $this->settleCogs($transaction);
        $this->settleLoyalty($transaction);

        return ['status' => 'paid', 'changed' => true, 'duplicate' => false];
    }

    private function applyFailed(Transaction $transaction, PaymentSettlement $settlement): array
    {
        if ($transaction->payment_status === 'paid') {
            return ['status' => 'paid', 'changed' => false, 'duplicate' => true];
        }

        $now = now();
        $transaction->update([
            'payment_status' => 'failed',
            'failed_at' => $transaction->failed_at ?: $now,
        ]);
        $settlement->update([
            'status' => 'failed',
            'failed_at' => $settlement->failed_at ?: $now,
            'next_retry_at' => null,
        ]);
        $this->releaseHold(
            Hold::where('transaction_id', $transaction->id)->lockForUpdate()->first(),
        );
        $this->releaseCogs($transaction);
        $this->releaseLoyaltyVoucher($transaction);

        return ['status' => 'failed', 'changed' => true, 'duplicate' => false];
    }

    private function applyRefunded(Transaction $transaction, PaymentSettlement $settlement): array
    {
        if ($transaction->status === 'refunded') {
            $this->reverseCogs($transaction);
            $this->releaseCogs($transaction);
            $this->reverseLoyalty($transaction);
            $this->releaseLoyaltyVoucher($transaction);
            $settlement->update(['status' => 'refunded', 'next_retry_at' => null]);

            return ['status' => 'refunded', 'changed' => false, 'duplicate' => true];
        }

        $wasPaid = $transaction->payment_status === 'paid';
        if ($wasPaid) {
            $this->stockIntelligence->restoreForTransaction($transaction);
            $this->reverseCogs($transaction);
            $this->reverseLoyalty($transaction);
        } else {
            $this->releaseCogs($transaction);
        }
        $this->releaseLoyaltyVoucher($transaction);

        $now = now();
        $transaction->update([
            'payment_status' => $wasPaid ? 'paid' : 'failed',
            'status' => 'refunded',
            'refunded_at' => $transaction->refunded_at ?: $now,
            'kitchen_status' => 'cancelled',
        ]);
        $transaction->profits()->delete();
        $settlement->update([
            'status' => 'refunded',
            'next_retry_at' => null,
        ]);
        $this->releaseHold(
            Hold::where('transaction_id', $transaction->id)->lockForUpdate()->first(),
        );

        return ['status' => 'refunded', 'changed' => true, 'duplicate' => false];
    }

    private function recordEvent(
        ?Transaction $transaction,
        ?PaymentSettlement $settlement,
        string $provider,
        ?string $providerReference,
        string $status,
        ?float $amount,
        bool $signatureValid,
        array $payload,
        string $source,
        ?string $eventId = null,
        ?string $processingError = null,
    ): PaymentEvent {
        return PaymentEvent::create([
            'transaction_id' => $transaction?->id,
            'payment_settlement_id' => $settlement?->id,
            'provider' => $provider,
            'event_id' => $eventId ?: $this->eventId($provider, $providerReference, $status, $payload),
            'provider_reference' => $providerReference,
            'status' => $status,
            'amount' => $amount,
            'signature_valid' => $signatureValid,
            'processed' => $processingError === null,
            'processing_error' => $processingError,
            'payload' => $this->sanitizePayload($payload) + ['source' => $source],
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
    }

    private function registerProcessingFailure(PaymentSettlement $settlement, Throwable $exception): void
    {
        $attempt = $settlement->attempt_count + 1;
        $delay = min(3600, 60 * (2 ** min($attempt - 1, 6)));

        PaymentSettlement::whereKey($settlement->id)->update([
            'attempt_count' => $attempt,
            'last_checked_at' => now(),
            'next_retry_at' => now()->addSeconds($delay),
            'last_error' => Str::limit($exception->getMessage(), 1000),
        ]);
    }

    private function createMissingSettlements(int $limit): void
    {
        Transaction::query()
            ->whereIn('payment_method', [PaymentSetting::GATEWAY_MIDTRANS, PaymentSetting::GATEWAY_XENDIT])
            ->whereDoesntHave('paymentSettlement')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->each(function (Transaction $transaction): void {
                if ($transaction->payment_status === 'pending') {
                    $this->begin($transaction, $transaction->payment_method);

                    return;
                }

                $status = match ($transaction->payment_status) {
                    'paid' => 'paid',
                    'refunded' => 'refunded',
                    default => 'failed',
                };
                PaymentSettlement::firstOrCreate([
                    'transaction_id' => $transaction->id,
                ], [
                    'provider' => $transaction->payment_method,
                    'provider_reference' => $transaction->payment_reference
                        ?: ($transaction->payment_method === PaymentSetting::GATEWAY_MIDTRANS ? $transaction->invoice : null),
                    'status' => $status,
                    'amount' => $transaction->grand_total,
                    'settled_amount' => $status === 'paid' ? $transaction->grand_total : null,
                    'settled_at' => $status === 'paid' ? ($transaction->settled_at ?: $transaction->paid_at) : null,
                    'failed_at' => $status === 'failed' ? $transaction->failed_at : null,
                    'next_retry_at' => null,
                ]);
            });
    }

    private function settleCogs(Transaction $transaction): void
    {
        try {
            $this->cogs->markTransactionPaid($transaction);
        } catch (Throwable $exception) {
            Log::warning('Batch allocation settlement failed', [
                'transaction_id' => $transaction->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function releaseCogs(Transaction $transaction): void
    {
        try {
            $this->cogs->releaseTransaction($transaction);
        } catch (Throwable $exception) {
            Log::warning('Batch allocation release failed', [
                'transaction_id' => $transaction->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function reverseCogs(Transaction $transaction): void
    {
        try {
            $this->cogs->reverseTransaction($transaction);
        } catch (Throwable $exception) {
            Log::warning('Batch allocation reversal failed', [
                'transaction_id' => $transaction->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function settleLoyalty(Transaction $transaction): void
    {
        try {
            $this->loyalty->consumeVoucher($transaction);
        } catch (Throwable $exception) {
            Log::warning('Voucher consume failed after settlement', [
                'transaction_id' => $transaction->id,
                'message' => $exception->getMessage(),
            ]);
        }
        try {
            $this->loyalty->recordPaidTransaction($transaction);
        } catch (Throwable $exception) {
            Log::warning('Loyalty points award failed after settlement', [
                'transaction_id' => $transaction->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function releaseLoyaltyVoucher(Transaction $transaction): void
    {
        try {
            $this->loyalty->releaseVoucher($transaction);
        } catch (Throwable $exception) {
            Log::warning('Voucher release failed', [
                'transaction_id' => $transaction->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function reverseLoyalty(Transaction $transaction): void
    {
        try {
            $this->loyalty->reverseTransaction($transaction);
        } catch (Throwable $exception) {
            Log::warning('Loyalty reversal failed after refund', [
                'transaction_id' => $transaction->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function releaseHold(?Hold $hold): void
    {
        if (! $hold) {
            return;
        }

        $this->stockIntelligence->releaseForHold($hold);
        if ($hold->table_id) {
            Table::where('id', $hold->table_id)->update(['status' => 'available']);
        }
        $hold->delete();
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (! in_array($provider, [PaymentSetting::GATEWAY_MIDTRANS, PaymentSetting::GATEWAY_XENDIT], true)) {
            throw new PaymentSettlementException('Provider settlement tidak didukung.');
        }

        return $provider;
    }

    private function normalizeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'paid', 'settlement', 'capture', 'settled' => 'paid',
            'failed', 'deny', 'expire', 'expired', 'cancel', 'cancelled' => 'failed',
            'refunded', 'refund', 'reversed' => 'refunded',
            'pending' => 'pending',
            default => 'unknown',
        };
    }

    private function eventId(string $provider, ?string $reference, string $status, array $payload): string
    {
        return hash('sha256', json_encode([
            'provider' => $provider,
            'reference' => $reference,
            'status' => $status,
            'payload' => $this->sanitizePayload($payload),
        ], JSON_THROW_ON_ERROR));
    }

    private function sanitizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/(signature|secret|token|password|authorization|api[_-]?key)/i', (string) $key)) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->sanitizePayload($value);
            }
        }

        return $payload;
    }
}
