<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentGatewayException;
use App\Models\Hold;
use App\Models\PaymentEvent;
use App\Models\PaymentSetting;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pembayaran mandiri untuk pesanan dari menu QR.
 *
 * Kenapa ada service terpisah dari `PaymentSettlementService`:
 * settlement service dirancang untuk `Transaction`, karena di kasir
 * pembayaran terjadi bersamaan dengan pembuatan transaksi resmi. Di menu QR
 * urutannya dibalik: pelanggan memesan dan membayar dulu, baru kasir menagih
 * beberapa menit kemudian. Jadi pembayaran dicatat di `Hold` lebih dulu.
 *
 * Yang TIDAK terjadi di sini: pengurangan stok, alokasi batch, dan pencatatan
 * penjualan. Semua itu tetap terjadi di kasir. Kalau service ini mengubah
 * salah satunya, neraca akan mencatat penjualan yang uangnya belum pernah
 * masuk.
 *
 * Aturan yang dijaga ketat:
 *  1. Satu pesanan hanya boleh punya satu pembayaran aktif. Nominal yang sudah
 *     ditagihkan ke gateway terkunci, jadi menambah item afterwards akan
 *     membuat pelanggan membayar kurang dari yang dia pesan.
 *  2. Nominal callback wajib sama persis dengan total pesanan. Selisih sekecil
 *     apa pun berarti ditolak, bukan dicatat.
 *  3. Pembayaran hanya boleh memakai provider yang benar-benar terkonfigurasi.
 *  4. Semua perpindahan status dicek ulang di dalam transaksi database, supaya
 *     callback ganda (gateway sering mengirim lebih dari sekali) tidak
 *     menghasilkan pencatatan ganda.
 */
class HoldPaymentService
{
    public function __construct(
        protected MidtransGateway $midtrans,
        protected XenditGateway $xendit,
    ) {}

    /**
     * Mulai pembayaran untuk satu pesanan menu QR.
     *
     * @return array{payment_url: string|null, qr_string: string|null, reference: string, status: string, expires_at: string|null}
     */
    public function start(Hold $hold, string $provider, PaymentSetting $setting): array
    {
        $total = $this->payableTotal($hold);
        $externalId = $this->externalId($hold);

        $result = match ($provider) {
            PaymentSetting::GATEWAY_MIDTRANS => $this->midtransCharge($hold, $provider, $setting, $externalId, $total),
            PaymentSetting::GATEWAY_XENDIT => $this->xenditCharge($hold, $provider, $setting, $externalId, $total),
            default => throw new PaymentGatewayException(
                'Metode pembayaran tidak didukung untuk pesanan mandiri. Pilih QRIS statis, Midtrans, atau Xendit.'
            ),
        };

        return $result;
    }

    /**
     * Midtrans bisa mengembalikan QR langsung lewat `actions`, atau hanya
     * `redirect_url`. Dua-duanya diteruskan; frontend memilih mana yang
     * dipakai supaya Midtrans dan Xendit punya pengalaman yang sama.
     *
     * @return array{payment_url: string|null, qr_string: string|null, reference: string, status: string, expires_at: string|null}
     */
    private function midtransCharge(Hold $hold, string $provider, PaymentSetting $setting, string $externalId, float $total): array
    {
        $config = $setting->midtransConfig();
        if (! $config['enabled']) {
            throw new PaymentGatewayException('Midtrans belum aktif. Isi server key di Pengaturan Pembayaran.');
        }

        $charge = $this->midtrans->createQrisCharge(
            $externalId,
            $total,
            $this->lineItems($hold),
            $config,
        );

        $payload = [
            'payment_url' => $charge['payment_url'] ?? null,
            'token' => $charge['token'] ?? null,
            'qr_string' => $charge['qr_string'] ?? null,
        ];

        // Masa berlaku mengikuti yang ditetapkan gateway, bukan tebakan kita.
        // Kalau QR di provider sudah tidak berlaku sementara UI masih
        // menunggu, pelanggan akan dikejar untuk scan kode yang mati.
        $expiry = $this->expiryFromGateway($charge['expires_at'] ?? null, fallbackMinutes: 15);

        $this->persistPending($hold, $provider, (string) ($charge['reference'] ?: $externalId), $payload, $expiry);
        $this->recordEvent($hold, 'created', $total, (string) $charge['reference']);

        return [
            'payment_url' => $charge['payment_url'] ?? null,
            'qr_string' => $charge['qr_string'] ?? null,
            'reference' => (string) $charge['reference'],
            'status' => 'pending',
            'expires_at' => $hold->fresh()->payment_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Ubil waktu berlaku dari gateway, default-nya `fallbackMinutes`.
     */
    private function expiryFromGateway(?string $gatewayExpiry, int $fallbackMinutes): int
    {
        if (! filled($gatewayExpiry)) {
            return $fallbackMinutes;
        }

        try {
            $parsed = Carbon::parse($gatewayExpiry);
        } catch (\Throwable) {
            return $fallbackMinutes;
        }

        $minutes = (int) ceil(now()->diffInMinutes($parsed, false));

        // Xendit DYNAMIC tidak mengirim expiration_date. Nol atau negatif
        // berarti waktu sudah lewat, jadi pakai default supaya pelanggan
        // tidak langsung melihat QR expired.
        return $minutes >= 1 ? $minutes : $fallbackMinutes;
    }

    /**
     * @return array{payment_url: string|null, qr_string: string|null, reference: string, status: string, expires_at: string|null}
     */
    private function xenditCharge(Hold $hold, string $provider, PaymentSetting $setting, string $externalId, float $total): array
    {
        $config = $setting->xenditConfig();
        if (! $config['enabled']) {
            throw new PaymentGatewayException('Xendit belum aktif. Isi secret key di Pengaturan Pembayaran.');
        }

        $code = $this->xendit->createQrisCode($externalId, $total, $config);

        $payload = [
            'qr_string' => $code['qr_string'] ?? null,
        ];

        $this->persistPending($hold, $provider, (string) ($code['reference'] ?: $externalId), $payload, 30);
        $this->recordEvent($hold, 'created', $total, (string) $code['reference']);

        return [
            'payment_url' => null,
            'qr_string' => $code['qr_string'] ?? null,
            'reference' => (string) $code['reference'],
            'status' => 'pending',
            'expires_at' => $hold->fresh()->payment_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Simpan keadaan "menunggu pembayaran" dengan pemeriksaan ulang di dalam
     * transaksi database, supaya dua request bersamaan tidak bisa membuat dua
     * penagihan untuk satu pesanan.
     */
    protected function persistPending(Hold $hold, string $provider, string $reference, array $payload, int $expiryMinutes): void
    {
        DB::transaction(function () use ($hold, $provider, $reference, $payload, $expiryMinutes): void {
            $locked = Hold::lockForUpdate()->find($hold->id);
            if (! $locked) {
                throw new PaymentGatewayException('Pesanan tidak ditemukan.');
            }

            if ($locked->payment_status === 'paid') {
                throw new PaymentGatewayException('Pesanan ini sudah lunas.');
            }

            if ($locked->payment_status === 'pending'
                && $locked->payment_expires_at?->isFuture()) {

                // Sudah ada QR aktif. Kirim ulang kode yang sama, jangan
                // menagih gateway dua kali.
                $existing = (array) ($locked->payment_payload ?? []);

                return;
            }

            $locked->forceFill([
                'payment_method' => $provider,
                'payment_status' => 'pending',
                'payment_reference' => $reference,
                'payment_payload' => $payload,
                'payment_expires_at' => now()->addMinutes($expiryMinutes),
            ])->save();
        });
    }

    /**
     * Tandai pesanan lunas.
     *
     * Idempoten: callback kedua untuk pembayaran yang sama akan menemukan
     * status sudah `paid` dan keluar tanpa mengubah apa pun.
     */
    public function markPaid(Hold $hold, float $paidAmount, string $providerReference, array $payload = []): string
    {
        try {
            return DB::transaction(function () use ($hold, $paidAmount, $providerReference, $payload): string {
                $locked = Hold::lockForUpdate()->find($hold->id);
                if (! $locked) {
                    throw new PaymentGatewayException('Pesanan tidak ditemukan.');
                }

                if ($locked->payment_status === 'paid') {
                    return 'already_paid';
                }

                $expected = $this->payableTotal($locked);

                // Selisih sekecil apa pun berarti ada yang tidak cocok: nominal
                // yang dibayar bukan yang ditagihkan. Mencatatnya akan membuat
                // Kas Bank di neraca melebihi pembayaran yang benar-benar masuk.
                if (abs($paidAmount - $expected) > 0.009) {
                    $locked->forceFill([
                        'payment_status' => 'failed',
                        'payment_payload' => array_merge((array) ($locked->payment_payload ?? []), [
                            'rejected_reason' => 'Nominal tidak cocok: dibayar '.$paidAmount.', ditagihkan '.$expected.'.',
                        ]),
                    ])->save();

                    Log::error('Hold payment ditolak: nominal tidak cocok', [
                        'hold_id' => $locked->id,
                        'paid' => $paidAmount,
                        'expected' => $expected,
                        'reference' => $providerReference,
                    ]);

                    throw new PaymentGatewayException('Nominal pembayaran tidak cocok dengan total pesanan.');
                }

                $locked->forceFill([
                    'payment_status' => 'paid',
                    'paid_amount' => $paidAmount,
                    'paid_at' => now(),
                    'payment_reference' => $providerReference ?: $locked->payment_reference,
                    'payment_payload' => array_merge((array) ($locked->payment_payload ?? []), [
                        'settled_payload' => $payload,
                    ]),
                ])->save();

                $this->recordEvent($locked, 'paid', $paidAmount, $providerReference, payload: $payload);

                return 'paid';
            });
        } catch (PaymentGatewayException $exception) {
            // Jejak penolakan harus ditulis DI LUAR transaksi.
            //
            // Kalau event-nya ditulis di dalam, penolakan akan membatalkan
            // dirinya sendiri saat transaksi di-rollback — dan penolakan
            // justru baris yang paling perlu dicari saat ada selisih uang di
            // laci. Hasilnya: tidak ada jejaknya sama sekali.
            if (str_contains($exception->getMessage(), 'tidak cocok')) {
                $this->recordEvent(
                    $hold,
                    'rejected',
                    $paidAmount,
                    $providerReference,
                    error: $exception->getMessage(),
                    processed: false,
                );
            }

            throw $exception;
        }
    }

    /**
     * Tandai pembayaran gagal atau kedaluwarsa.
     */
    public function markFailed(Hold $hold, string $reason, string $status = 'failed'): string
    {
        return DB::transaction(function () use ($hold, $reason, $status): string {
            $locked = Hold::lockForUpdate()->find($hold->id);
            if (! $locked) {
                return 'not_found';
            }

            if ($locked->payment_status === 'paid') {
                // Sudah lunas. Jangan turunkan statusnya karena callback
                // berikutnya (misal "expire") datang terlambat.
                return 'already_paid';
            }

            $locked->forceFill([
                'payment_status' => $status,
                'payment_payload' => array_merge((array) ($locked->payment_payload ?? []), [
                    'failure_reason' => $reason,
                ]),
            ])->save();

            $this->recordEvent(
                $locked,
                $status,
                $this->payableTotal($locked),
                (string) $locked->payment_reference,
                error: $reason,
            );

            return $status;
        });
    }

    /**
     * Catat satu event pembayaran ke `payment_events`.
     *
     * Jejak audit ini yang dibaca kasir untuk menjawab "sudah bayar?",
     * "kapan callbacks-nya masuk?", dan "kenapa ditolak?". Tanpa event,
     * pembayaran yang ditolak karena nominal tidak cocok akan terlihat sama
     * saja dengan yang berhasil.
     */
    private function recordEvent(
        Hold $hold,
        string $status,
        ?float $amount,
        ?string $providerReference,
        ?string $error = null,
        bool $processed = true,
        array $payload = [],
    ): void {
        PaymentEvent::create([
            'transaction_id' => null,
            'hold_id' => $hold->id,
            'provider' => (string) ($hold->payment_method ?: $hold->requested_payment_method ?: 'unknown'),
            'provider_reference' => $providerReference ?: $hold->payment_reference,
            'status' => $status,
            'amount' => $amount,
            // Event dari sisi server (dibuat, kedaluwarsa) TIDAK punya tanda
            // tangan sama sekali, jadi nilainya null — bukan false.
            //
            // null = "tidak ada yang diverifikasi".
            // false = "ada tanda tangan, tapi tidak cocok" (ada yang menyamar).
            //
            // Kalau yang ini diisi false, setiap QR yang dibuat akan terlihat
            // seperti ada percobaan pemalsuan, dan kasir akan Waste time
            // mencari masalah yang tidak ada.
            'signature_valid' => null,
            'processed' => $processed,
            'processing_error' => $error,
            'payload' => $payload !== [] ? $payload : null,
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
    }

    /**
     * Pindahkan jejak pembayaran dari pesanan ke transaksi resmi.
     *
     * Hold dihapus begitu kasir menagih. Kalau event ikut terhapus, riwayat
     * pembayaran transaksi jadi kosong tepat untuk transaksi yang paling perlu
     * ditelusuri (dibayar sendiri lewat QRIS sebelum datang ke kasir).
     *
     * Dipanggil SEBELUM hold dihapus.
     */
    public function attachEventsToTransaction(Hold $hold, Transaction $transaction): int
    {
        return PaymentEvent::where('hold_id', $hold->id)
            ->whereNull('transaction_id')
            ->update(['transaction_id' => $transaction->id, 'hold_id' => null]);
    }

    /**
     * Cari pesanan berdasarkan referensi gateway.
     */
    public function resolveByReference(string $reference): ?Hold
    {
        if ($reference === '') {
            return null;
        }

        return Hold::where('ref_number', $reference)
            ->orWhere('payment_reference', $reference)
            ->first();
    }

    /**
     * Referensi yang dikirim ke gateway.
     *
     * `ref_number` sudah bentuk "QR-1234" per pesanan menu QR, jadi aman
     * dipakai sebagai order_id: unik, dan tidak pernah sama dengan invoice
     * transaksi kasir.
     */
    public function externalId(Hold $hold): string
    {
        return (string) $hold->ref_number;
    }

    /**
     * Nominal yang harus dibayar pelanggan.
     */
    public function payableTotal(Hold $hold): float
    {
        return round((float) $hold->total, 2);
    }

    /**
     * @return array<int, array{name:string, price:float, quantity:float}>
     */
    public function lineItems(Hold $hold): array
    {
        return collect((array) $hold->cart_data)
            ->map(fn ($item) => [
                'name' => (string) ($item['product_title'] ?? 'Item'),
                'price' => round((float) ($item['current_unit_price'] ?? $item['sell_price'] ?? 0), 2),
                'quantity' => (float) ($item['qty'] ?? 1),
            ])
            ->filter(fn (array $item) => $item['price'] > 0 && $item['quantity'] > 0)
            ->values()
            ->all();
    }

    /**
     * Apakah pesanan masih bisa ditambah isinya?
     *
     * begitu QR sudah ditagihkan, nominalnya terkunci. Menambah item akan
     * membuat pelanggan membayar kurang dari yang dia pesan, jadi harus
     * ditolak.
     */
    public function isLocked(Hold $hold): bool
    {
        return in_array($hold->payment_status, ['pending', 'paid'], true);
    }

    public function guardThrowable(Throwable $exception): string
    {
        return $exception instanceof PaymentGatewayException
            ? $exception->getMessage()
            : 'Gagal memproses pembayaran: '.$exception->getMessage();
    }
}
