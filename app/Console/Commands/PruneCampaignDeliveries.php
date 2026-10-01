<?php

namespace App\Console\Commands;

use App\Models\CrmCampaign;
use App\Models\CrmCampaignDelivery;
use Illuminate\Console\Command;

/**
 * Membersihkan log pengiriman campaign yang sudah lama.
 *
 * Tabel `crm_campaign_deliveries` menyimpan isi pesan lengkap tiap penerima,
 * jadi ukurannya tumbuh jauh lebih cepat daripada campaign-nya sendiri.
 * Data ini hanya berguna untuk audit jangka pendek.
 */
class PruneCampaignDeliveries extends Command
{
    protected $signature = 'crm:prune-old-campaigns {--days=90 : Simpan log pengiriman selama N hari}';

    protected $description = 'Hapus log pengiriman campaign yang sudah lama';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        // Hanya log dari campaign yang sudah selesai/ditutup, bukan yang
        // sedang berjalan.
        $campaignIds = CrmCampaign::query()
            ->whereIn('status', ['sent', 'failed', 'refunded', 'previewed', 'partial', 'expired'])
            ->where('updated_at', '<', $cutoff)
            ->pluck('id');

        $deleted = 0;

        $campaignIds->chunk(200)->each(function ($ids) use (&$deleted): void {
            $deleted += CrmCampaignDelivery::whereIn('campaign_id', $ids)->delete();
            CrmCampaign::whereIn('id', $ids)->delete();
        });

        $this->info("Log campaign dihapus: {$deleted} baris dari {$campaignIds->count()} campaign (retensi {$days} hari).");

        return self::SUCCESS;
    }
}
