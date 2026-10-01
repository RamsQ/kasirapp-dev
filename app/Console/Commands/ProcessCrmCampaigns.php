<?php

namespace App\Console\Commands;

use App\Models\CrmCampaign;
use App\Services\CrmCampaignService;
use Illuminate\Console\Command;

class ProcessCrmCampaigns extends Command
{
    /** Budget waktu untuk satu campaign sebelum dilewati ke menit berikutnya. */
    protected const CAMPAIGN_BUDGET_SECONDS = 45;

    protected $signature = 'crm:process-campaigns {--campaign= : Jalankan satu campaign ID} {--limit= : Batch size per batch}';

    protected $description = 'Proses campaign CRM terjadwal dan melanjutkan campaign yang belum selesai';

    public function handle(CrmCampaignService $campaigns): int
    {
        $batchSize = max(1, min((int) ($this->option('limit') ?: CrmCampaignService::DEFAULT_BATCH_SIZE), 500));

        // Status `processing` ikut diambil supaya campaign besar yang terpotong
        // (mis. request sebelumnya killed timeout) dilanjutkan di sini.
        $query = CrmCampaign::query()
            ->where(function ($builder): void {
                $builder->where(function ($builder): void {
                    $builder->where('status', 'scheduled')
                        ->whereNotNull('scheduled_at')
                        ->where('scheduled_at', '<=', now());
                })->orWhere('status', 'processing');
            });

        if ($this->option('campaign')) {
            $query->whereKey((int) $this->option('campaign'));
        }

        $processed = 0;
        $deferred = 0;
        $incomplete = 0;

        $query->orderBy('scheduled_at')->chunkById(20, function ($campaignsList) use ($campaigns, $batchSize, &$processed, &$deferred, &$incomplete): void {
            foreach ($campaignsList as $campaign) {
                $blocker = $campaigns->runBlocker($campaign);
                if ($blocker !== null) {
                    // Segment nonaktif = campaign ditunda, bukan dibatalkan.
                    // Statusnya dibiarkan agar otomatis jalan saat diaktifkan.
                    $this->warn(sprintf('#%d %s: DITUNDA - %s', $campaign->id, $campaign->name, $blocker));
                    $deferred++;

                    continue;
                }

                // Drain berulang selama masih ada sisa dan budget waktu belum
                // habis, supaya campaign besar selesai jauh lebih cepat tanpa
                // risiko proses terlalu lama.
                $deadline = microtime(true) + self::CAMPAIGN_BUDGET_SECONDS;
                $remaining = true;

                while ($remaining && microtime(true) < $deadline) {
                    $remaining = $campaigns->run($campaign, force: true, limit: $batchSize, resume: true)['remaining'];
                }

                $campaign->refresh();
                $processed++;

                if ($campaign->status === 'processing') {
                    $incomplete++;
                    $this->line(sprintf(
                        '#%d %s: terkirim %d, sisanya dilanjutkan menit depan',
                        $campaign->id,
                        $campaign->name,
                        (int) $campaign->sent_count
                    ));

                    continue;
                }

                $this->line(sprintf(
                    '#%d %s: terkirim=%d gagal=%d dilewati=%d -> %s',
                    $campaign->id,
                    $campaign->name,
                    (int) $campaign->sent_count,
                    (int) $campaign->failed_count,
                    (int) $campaign->skipped_count,
                    $campaign->status
                ));
            }
        });

        $this->info("Campaign diproses: {$processed}.");
        if ($incomplete > 0) {
            $this->line("Campaign belum selesai (dilanjutkan otomatis): {$incomplete}.");
        }
        if ($deferred > 0) {
            $this->warn("Campaign ditunda (segment nonaktif): {$deferred}.");
        }

        return self::SUCCESS;
    }
}
