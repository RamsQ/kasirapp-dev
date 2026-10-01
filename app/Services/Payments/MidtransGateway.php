<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentGatewayException;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MidtransGateway
{
    /**
     * Membuat transaksi (Charge) ke Midtrans Snap API
     */
    public function createCharge(Transaction $transaction, array $config): array
    {
        // 1. Validasi Konfigurasi
        if (! ($config['enabled'] ?? false) || empty($config['server_key'])) {
            throw new PaymentGatewayException('Midtrans tidak aktif atau server_key belum dikonfigurasi.');
        }

        // 2. Tentukan Endpoint
        $endpoint = ($config['is_production'] ?? false)
            ? 'https://app.midtrans.com/snap/v1/transactions'
            : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

        $customer = $transaction->customer;

        // 3. Susun Payload Snap
        $payload = [
            'transaction_details' => [
                'order_id' => $transaction->invoice,
                'gross_amount' => (int) $transaction->grand_total,
            ],
            'item_details' => $this->formatItems($transaction),
            'customer_details' => [
                'first_name' => $transaction->customer_name ?: (optional($customer)->name ?? 'Pelanggan'),
                'email' => optional($customer)->email ?? 'customer@mangkujagad.com',
                'phone' => optional($customer)->phone ?? '',
            ],
            'enabled_payments' => ['qris', 'gopay', 'shopeepay', 'bank_transfer'],
            'callbacks' => [
                'finish' => route('transactions.print', $transaction->invoice),
            ],
            'expiry' => [
                'start_time' => now()->format('Y-m-d H:i:s O'),
                'unit' => 'minutes',
                'duration' => 15, // Masa berlaku link pembayaran
            ],
        ];

        // 4. Request ke Midtrans
        try {
            $response = Http::withBasicAuth($config['server_key'], '')
                ->timeout(10)
                ->post($endpoint, $payload);

            if ($response->failed()) {
                Log::error('Midtrans Charge Failed', [
                    'invoice' => $transaction->invoice,
                    'body' => $response->body(),
                ]);
                throw new PaymentGatewayException('Midtrans Error: '.($response->json('status_message') ?? 'Gagal menghubungi server payment.'));
            }

            return [
                'reference' => $response->json('order_id', $transaction->invoice),
                'payment_url' => $response->json('redirect_url'),
                'token' => $response->json('token'),
                'raw' => $response->json(),
            ];
        } catch (\Exception $e) {
            Log::error('Midtrans Gateway Exception: '.$e->getMessage());
            throw new PaymentGatewayException('Gagal memproses pembayaran: '.$e->getMessage());
        }
    }

    /**
     * QRIS dinamis untuk pesanan mandiri dari menu QR.
     *
     * Berbeda dengan `createCharge()` yang butuh model Transaction, method ini
     * menerima nilai biasa. Ini penting karena pesanan menu QR disimpan sebagai
     * `Hold`: belum ada transaksi resmi sampai kasir menagih, tapi pelanggan
     * sudah harus bisa membayar sekarang.
     *
     * MENGAPA CORE API, BUKAN SNAP:
     * Snap (`/snap/v1/transactions`) hanya mengembalikan `token` dan
     * `redirect_url`. QR-nya dirender oleh halaman milik Midtrans, sehingga
     * pelanggan tidak bisa melihat QR di halaman menu kita dan harus pindah
     * situs dulu. Terverifikasi langsung terhadap sandbox: respons Snap hanya
     * punya dua key itu.
     *
     * Core API (`/v2/charge`) mengembalikan `qr_string` sebagai field top-level
     * berisi payload EMV QRIS yang bisa langsung dirender di sisi kita, sama
     * seperti yang Xendit berikan. Karena itu jalur pembayaran mandiri memakai
     * Core API, sementara penagihan di kasir tetap Snap seperti sebelumnya.
     *
     * @param  array<int, array{name:string, price:float, quantity:float}>  $items
     * @return array{reference: string, payment_url: string|null, token: string|null, qr_string: string|null, expires_at: string|null, raw: array<string, mixed>}
     */
    public function createQrisCharge(string $orderId, float $amount, array $items, array $config): array
    {
        if (! ($config['enabled'] ?? false) || empty($config['server_key'])) {
            throw new PaymentGatewayException('Midtrans tidak aktif atau server_key belum dikonfigurasi.');
        }

        if ($amount <= 0) {
            throw new PaymentGatewayException('Nominal QRIS harus lebih besar dari nol.');
        }

        $base = ($config['is_production'] ?? false)
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';

        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => (int) round($amount),
            ],
            // Midtrans menolak item_details kosong, jadi nominal diletakkan
            // sebagai satu baris generik kalau rincian tidak tersedia.
            'item_details' => $items !== [] ? array_map(fn (array $item): array => [
                'name' => mb_substr((string) $item['name'], 0, 40),
                'price' => (int) round((float) $item['price']),
                'quantity' => (int) max(1, (float) $item['quantity']),
            ], $items) : [
                ['name' => 'Pesanan Menu QR', 'price' => (int) round($amount), 'quantity' => 1],
            ],
            'customer_details' => [
                'first_name' => 'Pelanggan',
            ],
            // payment_type mengunci metode ke QRIS saja, supaya pelanggan tidak
            // bisa dialihkan ke kartu kredit.
            'payment_type' => 'qris',
            'enabled_payments' => ['qris'],
            'callback_url' => route('payment.notification'),
        ];

        try {
            $response = Http::withBasicAuth($config['server_key'], '')
                ->acceptJson()
                ->timeout(10)
                ->post($base.'/v2/charge', $payload);

            if ($response->failed()) {
                Log::error('Midtrans QRIS Charge Failed', [
                    'order_id' => $orderId,
                    'body' => $response->body(),
                ]);

                throw new PaymentGatewayException('Midtrans Error: '.($response->json('status_message') ?? 'Gagal menghubungi server payment.'));
            }

            $body = $response->json() ?: [];

            return [
                'reference' => (string) ($body['order_id'] ?: $orderId),
                'payment_url' => $body['redirect_url'] ?? null,
                'token' => $body['transaction_id'] ?? null,
                'qr_string' => $body['qr_string'] ?? null,
                // Midtrans mengunci masa berlaku QR-nya sendiri (default 15
                // menit). Mengirim masa berlaku yang lebih panjang dari yang
                // benar-benar ada akan membuat pelanggan mengejar QR yang sudah
                // tidak berlaku.
                'expires_at' => $body['expiry_time'] ?? null,
                'raw' => $body,
            ];
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Midtrans QRIS Gateway Exception: '.$exception->getMessage());

            throw new PaymentGatewayException('Gagal membuat QRIS: '.$exception->getMessage());
        }
    }

    /**
     * Fetch the current status from Midtrans for reconciliation.
     *
     * @return array{status: string, provider_reference: string|null, amount: float|null, raw: array<string, mixed>}
     */
    public function fetchStatus(string $reference, array $config): array
    {
        if (empty($config['server_key'])) {
            throw new PaymentGatewayException('Midtrans server key belum dikonfigurasi.');
        }

        $baseUrl = ($config['is_production'] ?? false)
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';

        try {
            $response = Http::withBasicAuth($config['server_key'], '')
                ->acceptJson()
                ->timeout(10)
                ->get($baseUrl.'/v2/'.rawurlencode($reference).'/status');

            if ($response->failed()) {
                throw new PaymentGatewayException('Status Midtrans tidak dapat diakses.');
            }

            $payload = $response->json() ?: [];
            $status = strtolower((string) ($payload['transaction_status'] ?? 'pending'));
            $fraudStatus = strtolower((string) ($payload['fraud_status'] ?? ''));
            if ($status === 'capture' && in_array($fraudStatus, ['challenge', 'review'], true)) {
                $status = 'pending';
            }

            return [
                'status' => match ($status) {
                    'settlement', 'capture' => 'paid',
                    'pending' => 'pending',
                    'expire', 'cancel', 'deny' => 'failed',
                    'refund', 'partial_refund' => 'refunded',
                    default => 'unknown',
                },
                'provider_reference' => $payload['order_id'] ?? $reference,
                'amount' => isset($payload['gross_amount']) ? (float) $payload['gross_amount'] : null,
                'raw' => $payload,
            ];
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Midtrans status check failed', [
                'reference' => $reference,
                'message' => $exception->getMessage(),
            ]);

            throw new PaymentGatewayException('Status Midtrans tidak dapat diakses.');
        }
    }

    /**
     * Memformat item detail agar muncul di struk digital Midtrans
     */
    private function formatItems(Transaction $transaction)
    {
        $items = [];
        foreach ($transaction->details as $detail) {
            $items[] = [
                'id' => $detail->product_id,
                'price' => (int) ($detail->price / $detail->qty),
                'quantity' => (int) $detail->qty,
                'name' => substr($detail->product?->title ?? 'Product', 0, 50),
            ];
        }

        // Tambahkan diskon jika ada sebagai item negatif
        if ($transaction->discount > 0) {
            $items[] = [
                'id' => 'DISCOUNT',
                'price' => (int) -$transaction->discount,
                'quantity' => 1,
                'name' => 'Total Diskon',
            ];
        }

        if ($transaction->tax_amount > 0) {
            $items[] = [
                'id' => 'TAX',
                'price' => (int) $transaction->tax_amount,
                'quantity' => 1,
                'name' => 'Pajak',
            ];
        }

        return $items;
    }
}
