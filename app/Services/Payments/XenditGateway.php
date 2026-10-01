<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentGatewayException;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;

class XenditGateway
{
    public function createInvoice(Transaction $transaction, array $config): array
    {
        if (! ($config['enabled'] ?? false)) {
            throw new PaymentGatewayException('Xendit tidak aktif atau belum dikonfigurasi.');
        }

        $customer = $transaction->customer;

        $response = Http::withBasicAuth($config['secret_key'], '')
            ->post('https://api.xendit.co/v2/invoices', [
                'external_id' => $transaction->invoice,
                'amount' => (int) $transaction->grand_total,
                'description' => 'Pembayaran transaksi #'.$transaction->invoice,
                'customer' => [
                    'given_names' => optional($customer)->name ?? 'Customer',
                    'email' => optional($customer)->email ?? config('mail.from.address'),
                    'mobile_number' => optional($customer)->no_telp,
                ],
                'callback_url' => route('payment.xendit.notification'),
                'success_redirect_url' => route('transactions.print', $transaction->invoice),
                'failure_redirect_url' => route('transactions.print', $transaction->invoice),
            ]);

        if ($response->failed()) {
            throw new PaymentGatewayException(
                'Xendit error: '.$response->json('message', $response->body())
            );
        }

        return [
            'reference' => $response->json('id'),
            'payment_url' => $response->json('invoice_url'),
            'raw' => $response->json(),
        ];
    }

    /**
     * QRIS dinamis untuk pesanan mandiri dari menu QR.
     *
     * Endpoint `/qr_codes` (bukan `/v2/invoices`) yang dipakai di sini.
     * Bedanya penting: invoices menghasilkan halaman pembayaran/VA, sedangkan
     * qr_codes mengembalikan `qr_string` berisi payload EMV yang bisa dirender
     * langsung jadi QR di halaman kita. Pelanggan lalu memindainya dengan
     * aplikasi banknya, dan nominalnya terkunci di kode itu.
     *
     * Nilai biasa, bukan model Transaction, karena pesanan menu QR masih
     * berupa `Hold` saat pelanggan membayar.
     *
     * @return array{reference: string, qr_string: string|null, expires_at: string|null, raw: array<string, mixed>}
     */
    public function createQrisCode(string $externalId, float $amount, array $config): array
    {
        if (! ($config['enabled'] ?? false) || empty($config['secret_key'])) {
            throw new PaymentGatewayException('Xendit tidak aktif atau belum dikonfigurasi.');
        }

        if ($amount <= 0) {
            throw new PaymentGatewayException('Nominal QRIS harus lebih besar dari nol.');
        }

        $baseUrl = ($config['is_production'] ?? false)
            ? 'https://api.xendit.co'
            : 'https://api.xendit.co';

        try {
            $response = Http::withBasicAuth($config['secret_key'], '')
                ->acceptJson()
                ->timeout(10)
                ->post($baseUrl.'/qr_codes', [
                    'external_id' => $externalId,
                    // type DYNAMIC: satu kode per pesanan, jadi nominalnya
                    // terkunci di kode itu dan tidak bisa dipakai ulang.
                    // Kalau STATIC, kode tidak kedaluwarsa dan bisa dipakai
                    // berkali-kali untuk nominal yang sama, sehingga satu
                    // pembayaran tidak bisa dipastikan milik satu pesanan.
                    'type' => 'DYNAMIC',
                    'callback_url' => route('payment.xendit.notification'),
                    'amount' => (int) round($amount),
                ]);

            if ($response->failed()) {
                Log::error('Xendit QRIS Failed', [
                    'external_id' => $externalId,
                    'body' => $response->body(),
                ]);

                throw new PaymentGatewayException(
                    'Xendit error: '.($response->json('message') ?? 'Gagal membuat kode QRIS.')
                );
            }

            return [
                'reference' => (string) ($response->json('id') ?: $externalId),
                'qr_string' => $response->json('qr_string'),
                'expires_at' => $response->json('expiration_date'),
                'raw' => $response->json() ?: [],
            ];
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Xendit QRIS Gateway Exception: '.$exception->getMessage());

            throw new PaymentGatewayException('Gagal membuat QRIS: '.$exception->getMessage());
        }
    }

    /**
     * Fetch the current invoice status from Xendit for reconciliation.
     *
     * @return array{status: string, provider_reference: string|null, amount: float|null, raw: array<string, mixed>}
     */
    public function fetchStatus(string $reference, array $config): array
    {
        if (empty($config['secret_key'])) {
            throw new PaymentGatewayException('Xendit secret key belum dikonfigurasi.');
        }

        try {
            $response = Http::withBasicAuth($config['secret_key'], '')
                ->acceptJson()
                ->timeout(10)
                ->get('https://api.xendit.co/v2/invoices/'.rawurlencode($reference));

            if ($response->failed()) {
                throw new PaymentGatewayException('Status Xendit tidak dapat diakses.');
            }

            $payload = $response->json() ?: [];
            $status = strtoupper((string) ($payload['status'] ?? 'PENDING'));

            return [
                'status' => match ($status) {
                    'PAID', 'SETTLED' => 'paid',
                    'PENDING' => 'pending',
                    'EXPIRED' => 'failed',
                    'REFUNDED', 'REVERSED' => 'refunded',
                    default => 'unknown',
                },
                'provider_reference' => $payload['id'] ?? $reference,
                'amount' => isset($payload['amount']) ? (float) $payload['amount'] : null,
                'raw' => $payload,
            ];
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new PaymentGatewayException('Status Xendit tidak dapat diakses.');
        }
    }
}
