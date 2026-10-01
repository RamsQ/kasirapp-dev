<?php

namespace App\Listeners;

use App\Events\TransactionCreated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Junges\Kafka\Facades\Kafka;
use Throwable;

class SendTransactionToKafka
{
    /**
     * Handle the event.
     */
    public function handle(TransactionCreated $event): void
    {
        // Kalau broker tidak dikonfigurasi (lingkungan test/CI), jangan coba
        // koneksi sama sekali. Producer rdkafka akan hang sampai flush timeout
        // di setiap transaksi dan flooded log dengan "brokers are down".
        if (blank(config('kafka.brokers'))) {
            return;
        }

        try {
            $transaction = $event->transaction->load(['details.product', 'customer']);

            // Parse tanggal dengan aman baik jika berbentuk String maupun Carbon
            $createdAt = $transaction->created_at;
            $formattedDate = $createdAt instanceof \DateTimeInterface
                ? $createdAt->toIso8601String()
                : Carbon::parse($createdAt)->toIso8601String();

            // Kirim stream data transaksi ke Kafka tanpa kompresi snappy
            Kafka::publish()
                ->onTopic('transaction-events')
                ->withConfigOptions([
                    'compression.codec' => 'none',
                ])
                ->withBody([
                    'invoice' => $transaction->invoice,
                    'grand_total' => $transaction->grand_total,
                    'discount' => $transaction->discount,
                    'customer_name' => $transaction->customer?->name ?? $transaction->customer_name ?? 'Guest',
                    'created_at' => $formattedDate,
                    'items' => $transaction->details->map(function ($item) {
                        return [
                            'product_id' => $item->product_id,
                            'product_name' => $item->product?->title,
                            'qty' => $item->qty,
                            'price' => $item->price,
                        ];
                    })->toArray(),
                ])
                ->send();

        } catch (Throwable $e) {
            // Catat error di storage/logs/laravel.log tanpa menggagalkan simpan transaksi
            Log::error('Kafka Publish Error: '.$e->getMessage());
        }
    }
}
