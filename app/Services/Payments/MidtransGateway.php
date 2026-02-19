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
        if (!($config['enabled'] ?? false) || empty($config['server_key'])) {
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
                'order_id'     => $transaction->invoice,
                'gross_amount' => (int) $transaction->grand_total,
            ],
            'item_details' => $this->formatItems($transaction),
            'customer_details' => [
                'first_name' => $transaction->customer_name ?: (optional($customer)->name ?? 'Pelanggan'),
                'email'      => optional($customer)->email ?? 'customer@mangkujagad.com',
                'phone'      => optional($customer)->phone ?? '',
            ],
            'enabled_payments' => ['qris', 'gopay', 'shopeepay', 'bank_transfer'],
            'callbacks' => [
                'finish' => route('transactions.print', $transaction->invoice),
            ],
            'expiry' => [
                'start_time' => now()->format('Y-m-d H:i:s O'),
                'unit'       => 'minutes',
                'duration'   => 15 // Masa berlaku link pembayaran
            ]
        ];

        // 4. Request ke Midtrans
        try {
            $response = Http::withBasicAuth($config['server_key'], '')
                ->timeout(10)
                ->post($endpoint, $payload);

            if ($response->failed()) {
                Log::error('Midtrans Charge Failed', [
                    'invoice' => $transaction->invoice,
                    'body'    => $response->body()
                ]);
                throw new PaymentGatewayException('Midtrans Error: ' . ($response->json('status_message') ?? 'Gagal menghubungi server payment.'));
            }

            return [
                'reference'   => $transaction->invoice,
                'payment_url' => $response->json('redirect_url'),
                'token'       => $response->json('token'),
                'raw'         => $response->json(),
            ];
        } catch (\Exception $e) {
            Log::error('Midtrans Gateway Exception: ' . $e->getMessage());
            throw new PaymentGatewayException('Gagal memproses pembayaran: ' . $e->getMessage());
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
                'id'       => $detail->product_id,
                'price'    => (int) ($detail->price / $detail->qty),
                'quantity' => (int) $detail->qty,
                'name'     => substr($detail->product_title ?? 'Product', 0, 50),
            ];
        }

        // Tambahkan diskon jika ada sebagai item negatif
        if ($transaction->discount > 0) {
            $items[] = [
                'id'       => 'DISCOUNT',
                'price'    => (int) -$transaction->discount,
                'quantity' => 1,
                'name'     => 'Total Diskon',
            ];
        }

        return $items;
    }
}