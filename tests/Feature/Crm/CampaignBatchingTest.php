<?php

namespace Tests\Feature\Crm;

use App\Models\CrmCampaign;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\ReportSetting;
use App\Services\CrmCampaignService;
use App\Services\CrmVoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Campaign besar tidak boleh diproses seluruhnya dalam satu request HTTP.
 * Kalau tidak, nginx/php-fpm (~60 detik) memutus proses di tengah jalan:
 * sebagian pesan terkirim, sebagian tidak, dan kasir melihat error.
 */
class CampaignBatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ReportSetting::updateOrCreate(['id' => 1], [
            'is_active' => true,
            'method' => 'email',
            'target' => 'ops@example.com',
            'send_at' => '21:00',
        ]);

        Mail::fake();
    }

    protected function makeCampaign(int $recipients): CrmCampaign
    {
        CustomerSegment::truncate();

        for ($i = 1; $i <= $recipients; $i++) {
            Customer::create([
                'name' => 'Pelanggan '.$i,
                'no_telp' => '0812'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'email' => 'p'.$i.'@example.com',
                'address' => 'Alamat',
                'marketing_opt_in' => true,
                'loyalty_tier' => 'gold',
            ]);
        }

        $segment = CustomerSegment::create([
            'name' => 'Batch Segment',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);

        return CrmCampaign::create([
            'name' => 'Kampanye Besar',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'email',
            'subject' => 'Promo',
            'message' => 'Halo {name}',
            'is_dry_run' => false,
            'status' => 'draft',
            'voucher_mode' => CrmVoucherService::MODE_NONE,
        ]);
    }

    public function test_batch_stops_at_the_limit_and_reports_remaining(): void
    {
        $campaign = $this->makeCampaign(60);
        $service = app(CrmCampaignService::class);

        $first = $service->run($campaign, force: true, limit: 25, maxSeconds: 300);

        $this->assertSame(25, $first['total'], 'Batch harus berhenti di batas.');
        $this->assertTrue($first['remaining'], 'Harus ada sisa untuk dilanjutkan.');
        $this->assertSame('processing', $campaign->fresh()->status);
        $this->assertSame(25, (int) $campaign->fresh()->sent_count);
    }

    public function test_successive_batches_finish_without_duplicate_sends(): void
    {
        $campaign = $this->makeCampaign(60);
        $service = app(CrmCampaignService::class);

        // Baris pertama: diproses tombol manual.
        $first = $service->run($campaign, force: true, limit: 25, maxSeconds: 300);
        $this->assertTrue($first['remaining']);

        // Sisa dilanjutkan scheduler dengan flag resume.
        $second = $service->run($campaign->fresh(), force: true, limit: 25, maxSeconds: 300, resume: true);
        $this->assertTrue($second['remaining']);

        $third = $service->run($campaign->fresh(), force: true, limit: 25, maxSeconds: 300, resume: true);
        $this->assertFalse($third['remaining'], 'Penerima terakhir harus menyelesaikan campaign.');
        $this->assertSame('sent', $third['status']);

        // Tepat 60 terkirim, tidak ada duplikat.
        $this->assertSame(60, (int) $campaign->fresh()->sent_count);
        $this->assertSame(60, \App\Models\CrmCampaignDelivery::where('campaign_id', $campaign->id)->count());
        $this->assertSame(60, \App\Models\CrmCampaignDelivery::where('campaign_id', $campaign->id)
            ->where('status', 'sent')->count());
    }

    public function test_batch_respects_time_budget_and_leaves_remainder(): void
    {
        $campaign = $this->makeCampaign(30);
        $service = app(CrmCampaignService::class);

        // maxSeconds = 0 harus menghentikan batch lebih dulu.
        $result = $service->run($campaign, force: true, limit: 100, maxSeconds: 0);

        $this->assertSame(0, $result['total'], 'Tidak ada yang boleh diproses tanpa sisa waktu.');
        $this->assertTrue($result['remaining']);
        $this->assertSame('processing', $campaign->fresh()->status);
    }

    public function test_manual_run_cannot_steal_a_campaign_being_processed(): void
    {
        $campaign = $this->makeCampaign(60);
        $service = app(CrmCampaignService::class);

        $service->run($campaign, force: true, limit: 25, maxSeconds: 300);
        $this->assertSame('processing', $campaign->fresh()->status);

        // Tombol manual dipindar tidak boleh mengambil campaign yang baru saja
        // diproses scheduler (belum dianggap macet).
        $stolen = $service->run($campaign->fresh(), force: true, limit: 25, maxSeconds: 300);

        $this->assertSame(0, $stolen['total']);
        $this->assertSame(25, (int) $campaign->fresh()->sent_count, 'Penghitung tidak boleh berubah.');
    }

    public function test_scheduler_command_drains_incomplete_campaign(): void
    {
        $campaign = $this->makeCampaign(60);

        // Sisakan sisa pekerjaan supaya scheduler harus melanjutkan.
        app(CrmCampaignService::class)->run($campaign, force: true, limit: 25, maxSeconds: 300);
        $this->assertSame('processing', $campaign->fresh()->status);

        $this->artisan('crm:process-campaigns --limit=25')->assertSuccessful();

        $fresh = $campaign->fresh();
        $this->assertNotSame('processing', $fresh->status, 'Scheduler harus menyelesaikan campaign.');
        $this->assertSame(60, (int) $fresh->sent_count);
    }
}
