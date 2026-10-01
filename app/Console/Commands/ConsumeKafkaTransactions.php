<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Junges\Kafka\Facades\Kafka;
use Junges\Kafka\Message\ConsumedMessage;

class ConsumeKafkaTransactions extends Command
{
    protected $signature = 'kafka:consume-transactions';

    protected $description = 'Mendengarkan event stream transaksi dari topik Kafka';

    public function handle()
    {
        $this->info('Mulai mendengarkan stream topik: transaction-events...');

        $consumer = Kafka::consumer(['transaction-events'])
            ->withHandler(function (ConsumedMessage $message) {
                $data = $message->getBody();

                $this->info('========================================');
                $this->info('EVENT TRANSAKSI MASUK (REAL-TIME)');
                $this->line('Invoice     : '.($data['invoice'] ?? '-'));
                $this->line('Customer    : '.($data['customer_name'] ?? '-'));
                $this->line('Grand Total : Rp '.number_format($data['grand_total'] ?? 0, 0, ',', '.'));
                $this->line('Total Item  : '.count($data['items'] ?? []).' produk');
                $this->info("========================================\n");
            })
            ->build();

        $consumer->consume();
    }
}
