<?php

namespace App\Console\Commands;

use App\Services\StockIntelligenceService;
use Illuminate\Console\Command;

class ReleaseExpiredStockReservations extends Command
{
    protected $signature = 'stock:release-expired-reservations';

    protected $description = 'Lepas reservasi stok QR Order yang sudah kedaluwarsa';

    public function handle(StockIntelligenceService $stockIntelligence): int
    {
        $released = $stockIntelligence->releaseExpiredReservations();
        $this->info("{$released} reservasi stok kedaluwarsa dilepas.");

        return self::SUCCESS;
    }
}
