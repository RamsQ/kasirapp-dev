<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\LoyaltyService;
use Illuminate\Console\Command;

class BackfillLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:backfill {--limit=1000}';

    protected $description = 'Backfill loyalty points for paid customer transactions without duplicate ledger entries';

    public function handle(LoyaltyService $loyalty): int
    {
        $limit = max(1, min((int) $this->option('limit'), 10000));
        $processed = 0;

        Transaction::with('customer')
            ->whereNotNull('customer_id')
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'refunded')
            ->whereDoesntHave('loyaltyLedger', function ($query): void {
                $query->where('type', 'earn');
            })
            ->oldest('id')
            ->limit($limit)
            ->get()
            ->each(function (Transaction $transaction) use ($loyalty, &$processed): void {
                $loyalty->recordPaidTransaction($transaction);
                $processed++;
            });

        $this->info("{$processed} transaksi diproses.");

        return self::SUCCESS;
    }
}
