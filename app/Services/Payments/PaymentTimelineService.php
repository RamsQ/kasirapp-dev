<?php

namespace App\Services\Payments;

use App\Models\PaymentEvent;
use App\Models\Transaction;

/**
 * Menyusun riwayat pembayaran satu transaksi untuk ditampilkan.
 *
 * FUNGSI: menjawab pertanyaan kasir saat ada yang janggal di laci —
 * "sudah bayar?", "kapan callback-nya masuk?", "kenapa ditolak?",
 * "signature-nya valid?", "sudah berapa kali dicoba?".
 *
 * DATA-DAR-nya `payment_events`, satu-satunya jejak audit yang dipakai juga
 * oleh `PaymentSettlementService` saat rekonsiliasi. Kalau halaman ini
 * calculating status sendiri dari kolom `transactions.payment_status`, angka
 * yang tampil bisa berbeda dari yang dipakai rekonsiliasi — dan justru
 * perbedaan itulah yang dicari saat investigating.
 *
 * BENTUK KELUARAN sudah dinormalisasi (provider, status, waktu, nominal)
 * supaya frontend tidak perlu tahu detail tiap provider. Midtrans memakai
 * "settlement", Xendit memakai "SUCCEEDED", dan QRIS statis tidak punya
 * gateway sama sekali.
 */
class PaymentTimelineService
{
    /**
     * Status yang berarti uang benar-benar sudah masuk.
     */
    private const PAID_STATUSES = ['settlement', 'capture', 'succeeded', 'paid', 'completed'];

    /**
     * Status yang berarti tidak akan pernah dibayar.
     */
    private const DEAD_STATUSES = ['expire', 'expired', 'cancel', 'cancelled', 'deny', 'failed', 'voided', 'rejected'];

    /**
     * @return array<int, array{
     *     id: int,
     *     provider: string,
     *     provider_label: string,
     *     status: string,
     *     status_label: string,
     *     tone: 'success'|'pending'|'failed'|'neutral',
     *     amount: float|null,
     *     reference: string|null,
     *     signature_valid: bool|null,
     *     processed: bool,
     *     error: string|null,
     *     received_at: string|null,
     * }>
     */
    public function forTransaction(Transaction $transaction): array
    {
        return $transaction->paymentEvents
            ->sortBy(fn (PaymentEvent $event) => $event->received_at ?? $event->created_at)
            ->map(fn (PaymentEvent $event) => $this->presentEvent($event))
            ->values()
            ->all();
    }

    /**
     * Ringkasan untuk ditampilkan di baris tabel, tanpa daftar lengkap.
     *
     * @return array{provider: string|null, provider_label: string, status: string, status_label: string, tone: string, attempts: int, last_error: string|null, settled_at: string|null, failed_at: string|null}
     */
    public function summary(Transaction $transaction): array
    {
        $settlement = $transaction->paymentSettlement;
        $events = $transaction->paymentEvents;

        $provider = $settlement?->provider
            ?: $events->last()?->provider
            ?: $transaction->payment_method;

        $status = (string) ($settlement?->status ?: $transaction->payment_status);

        // Error terakhir yang tercatat, baik dari settlement maupun dari event.
        // Ini yang dicari kasir saat ada selisih uang: "kenapa ditolak?".
        $lastError = $settlement?->last_error;
        if (! filled($lastError)) {
            $withError = $events->filter(fn (PaymentEvent $e) => filled($e->processing_error))->last();
            $lastError = $withError?->processing_error;
        }

        return [
            'provider' => $provider,
            'provider_label' => $this->providerLabel((string) $provider),
            'status' => $status,
            'status_label' => $this->statusLabel($status, (string) $transaction->payment_status),
            'tone' => $this->tone($status, (string) $transaction->payment_status),
            'attempts' => (int) ($settlement?->attempt_count ?: $events->count()),
            'last_error' => $lastError,
            'settled_at' => $settlement?->settled_at?->toIso8601String()
                ?: $transaction->paid_at?->toIso8601String(),
            'failed_at' => $settlement?->failed_at?->toIso8601String()
                ?: $transaction->failed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     provider: string,
     *     provider_label: string,
     *     status: string,
     *     status_label: string,
     *     tone: 'success'|'pending'|'failed'|'neutral',
     *     amount: float|null,
     *     reference: string|null,
     *     signature_valid: bool|null,
     *     processed: bool,
     *     error: string|null,
     *     received_at: string|null,
     * }
     */
    private function presentEvent(PaymentEvent $event): array
    {
        $status = (string) $event->status;

        // null = event dibuat di sisi server (misal QR dibuat), jadi tidak ada
        // tanda tangan yang perlu diverifikasi. false = ada tanda tangan tapi
        // tidak cocok, itu yang perlu dicurigai.
        $signatureValid = $event->signature_valid === null ? null : (bool) $event->signature_valid;

        $tone = $this->tone($status, null);

        // Event yang TIDAK diterima tidak boleh ditampilkan sebagai keberhasilan,
        // apa pun status yang dilaporkan provider.
        //
        // Tanpa ini, callback `settlement` dengan tanda tangan palsu akan tampil
        // hijau "LUNAS" di sebelah tulisan "Tanda tangan tidak valid". Dua
        // hal yang saling bertentangan, dan kasir bisa saja mempercayai yang
        // hijau. Callback yang tidak lolos verifikasi memang sudah ditolak
        // `PaymentController`, jadi ini hanya soal bagaimana jejaknya digambar.
        if ($signatureValid === false || ! $event->processed) {
            $tone = 'failed';
        }

        return [
            'id' => $event->id,
            'provider' => (string) $event->provider,
            'provider_label' => $this->providerLabel((string) $event->provider),
            'status' => $status,
            'status_label' => $this->statusLabel($status, null),
            'tone' => $tone,
            'amount' => $event->amount !== null ? (float) $event->amount : null,
            'reference' => $event->provider_reference,
            'signature_valid' => $signatureValid,
            'processed' => (bool) $event->processed,
            'error' => $event->processing_error,
            'received_at' => ($event->received_at ?? $event->occurred_at)?->toIso8601String(),
        ];
    }

    private function providerLabel(string $provider): string
    {
        return match (strtolower($provider)) {
            'midtrans' => 'Midtrans',
            'xendit' => 'Xendit',
            'qris_manual' => 'QRIS Statis',
            'cash' => 'Tunai',
            'transfer' => 'Transfer',
            default => $provider !== '' ? strtoupper($provider) : 'Tidak Diketahui',
        };
    }

    private function statusLabel(string $status, ?string $transactionStatus): string
    {
        return match (strtolower($status)) {
            'created' => 'Tagihan dibuat',
            'pending', 'received' => 'Menunggu pembayaran',
            'settlement', 'capture' => 'LUNAS',
            'succeeded' => 'LUNAS',
            'paid' => 'LUNAS',
            'completed' => 'Selesai',
            'expire', 'expired' => 'Kedaluwarsa',
            'cancel', 'cancelled' => 'Dibatalkan',
            'deny' => 'Ditolak bank',
            'failed' => 'Gagal',
            'rejected' => 'Ditolak (nominal tidak cocok)',
            'refunded', 'partial_refund' => 'Dikembalikan',
            default => $transactionStatus !== null && strtolower($transactionStatus) === 'paid'
                ? 'LUNAS'
                : strtoupper($status),
        };
    }

    /**
     * @return 'success'|'pending'|'failed'|'neutral'
     */
    private function tone(string $status, ?string $transactionStatus): string
    {
        $lower = strtolower($status);

        if (in_array($lower, self::PAID_STATUSES, true)) {
            return 'success';
        }

        if (in_array($lower, self::DEAD_STATUSES, true)) {
            return 'failed';
        }

        if (in_array($lower, ['created', 'pending', 'received'], true)) {
            // Status transaksi boleh lebih tinggi dari status event terakhir:
            // QR sudah lunas tapi callback-nya belum sempat masuk.
            return $transactionStatus !== null && strtolower($transactionStatus) === 'paid'
                ? 'success'
                : 'pending';
        }

        return 'neutral';
    }
}
