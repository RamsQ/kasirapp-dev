<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentSettlementService;
use Illuminate\Console\Command;

class ReconcilePaymentSettlements extends Command
{
    protected $signature = 'payments:reconcile {--limit=50 : Maximum settlement rows to check}';

    protected $description = 'Reconcile pending Midtrans/Xendit payments with provider status APIs';

    public function handle(PaymentSettlementService $settlements): int
    {
        $limit = max(1, min((int) $this->option('limit'), 500));
        $result = $settlements->reconcilePending($limit);

        $this->info(sprintf(
            'Checked %d | paid %d | failed %d | pending %d | errors %d',
            $result['checked'],
            $result['paid'],
            $result['failed'],
            $result['pending'],
            $result['errors'],
        ));

        return self::SUCCESS;
    }
}
