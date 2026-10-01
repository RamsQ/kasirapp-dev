<?php

namespace Tests\Feature\Crm;

use App\Models\Category;
use App\Models\CrmCampaign;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\Product;
use App\Models\ReportSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CrmCampaignService;
use App\Services\CrmSegmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CrmCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_segment_builder_filters_tier_spend_and_transaction_history(): void
    {
        $customer = $this->customer('Gold Member', 'gold', 250000, true);
        $this->customer('Silver Member', 'silver', 100000, true);
        $this->customer('Opt Out', 'gold', 900000, false);
        $this->paidTransaction($customer, 250000);
        $segment = CustomerSegment::create([
            'name' => 'Gold Aktif',
            'rules' => [
                'tiers' => ['gold'],
                'min_lifetime_spend' => 200000,
                'min_transactions' => 1,
            ],
            'is_active' => true,
        ]);

        $this->assertSame(1, app(CrmSegmentService::class)->count($segment));
    }

    public function test_dry_run_campaign_renders_personalized_message_and_skips_opt_out(): void
    {
        $customer = $this->customer('Gold Member', 'gold', 250000, true, '081234567891', 'gold@example.com');
        $optedOut = $this->customer('Opt Out', 'gold', 900000, false);
        $segment = CustomerSegment::create([
            'name' => 'Gold',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);
        $campaign = CrmCampaign::create([
            'name' => 'Test Preview',
            'segment_id' => $segment->id,
            'type' => 'tier_promotion',
            'channel' => 'copy',
            'message' => 'Halo {name}, tier {tier} poin {points}.',
            'is_dry_run' => true,
            'status' => 'draft',
        ]);

        $result = app(CrmCampaignService::class)->run($campaign);

        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['preview']);
        $this->assertDatabaseHas('crm_campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'status' => 'preview',
        ]);
        $this->assertDatabaseMissing('crm_campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'customer_id' => $optedOut->id,
        ]);
        $this->assertSame('previewed', $campaign->fresh()->status);
    }

    public function test_campaign_center_requires_permission_and_manages_segments_and_campaigns(): void
    {
        $access = Permission::firstOrCreate(['name' => 'crm-access', 'guard_name' => 'web']);
        $manage = Permission::firstOrCreate(['name' => 'crm-manage', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo([$access, $manage]);

        $this->actingAs($user)
            ->get(route('crm.campaigns.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Crm/Campaigns')
                ->where('canManage', true));

        $this->actingAs($user)
            ->post(route('crm.segments.store'), [
                'name' => 'Segment Admin',
                'rules' => ['tiers' => ['silver']],
                'is_active' => true,
            ])
            ->assertRedirect();
        $segment = CustomerSegment::where('name', 'Segment Admin')->firstOrFail();

        $this->actingAs($user)
            ->post(route('crm.campaigns.store'), [
                'name' => 'Campaign Admin',
                'segment_id' => $segment->id,
                'type' => 'win_back',
                'channel' => 'copy',
                'message' => 'Halo {name}',
                'is_dry_run' => true,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('crm_campaigns', ['name' => 'Campaign Admin', 'status' => 'draft']);
    }

    public function test_customer_opt_out_excludes_future_campaigns(): void
    {
        $customer = $this->customer('Opt Out', 'bronze', 0, false);
        $this->assertNotNull($customer->crm_opt_out_at);
    }

    public function test_win_back_segment_targets_only_inactive_customers_with_history(): void
    {
        $sleeping = $this->customer('Sleeping', 'bronze', 50000, true, '081234567892', 'sleeping@example.com');
        $active = $this->customer('Active', 'bronze', 50000, true, '081234567893', 'active@example.com');
        $neverBought = $this->customer('Newcomer', 'bronze', 0, true, '081234567894', 'new@example.com');

        $this->paidTransaction($sleeping, 50000, now()->subDays(120));
        $this->paidTransaction($active, 50000, now()->subDays(2));

        $segment = CustomerSegment::create([
            'name' => 'Win Back 90 Hari',
            'rules' => ['inactive_days' => 90],
            'is_active' => true,
        ]);

        $matched = app(CrmSegmentService::class)->query($segment)->pluck('customers.id')->all();

        $this->assertContains($sleeping->id, $matched);
        $this->assertNotContains($active->id, $matched);
        $this->assertNotContains($neverBought->id, $matched);
    }

    public function test_live_whatsapp_campaign_persists_delivery_results(): void
    {
        ReportSetting::updateOrCreate(['id' => 1], [
            'is_active' => true,
            'method' => 'whatsapp',
            'target' => '081234567896',
            'send_at' => '21:00',
            'wa_api_key' => 'test-token',
        ]);

        Http::fake([
            'api.fonnte.com/*' => Http::response(['id' => 'msg-1'], 200),
        ]);

        $customer = $this->customer('Live Member', 'gold', 120000, true, '081234567895', 'live@example.com');
        $segment = CustomerSegment::create([
            'name' => 'Live Segment',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);
        $campaign = CrmCampaign::create([
            'name' => 'Live WA',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Halo {name}',
            'is_dry_run' => false,
            'status' => 'draft',
        ]);

        $result = app(CrmCampaignService::class)->run($campaign);

        $this->assertSame(1, $result['sent']);
        // Nomor dikirim dalam format internasional. Fonnte menolak `08...`
        // dan hanya menerima `62...`, jadi assertion di sini ikut menjaga
        // normalisasi tidak pernah dihapus diam-diam.
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'test-token')
            && $request['target'] === '6281234567895'
            && $request['message'] === 'Halo Live Member');
        $this->assertDatabaseHas('crm_campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'target' => '6281234567895',
        ]);
        $this->assertDatabaseHas('crm_campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'status' => 'sent',
            'provider_reference' => 'msg-1',
        ]);
        $this->assertSame('sent', $campaign->fresh()->status);
    }

    public function test_campaign_without_provider_token_fails_softly_and_is_retryable(): void
    {
        config(['services.crm.fonnte_token' => null]);
        ReportSetting::updateOrCreate(['id' => 1], [
            'is_active' => true,
            'method' => 'whatsapp',
            'target' => '081234567896',
            'send_at' => '21:00',
            'wa_api_key' => null,
        ]);

        $this->customer('No Token', 'gold', 120000, true, '081234567897', 'notoken@example.com');
        $segment = CustomerSegment::create([
            'name' => 'Token Segment',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);
        $campaign = CrmCampaign::create([
            'name' => 'No Token WA',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Halo {name}',
            'is_dry_run' => false,
            'status' => 'draft',
        ]);

        $result = app(CrmCampaignService::class)->run($campaign);

        $this->assertSame(1, $result['failed']);
        $this->assertSame('partial', $campaign->fresh()->status);
        $this->assertDatabaseHas('crm_campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'status' => 'failed',
        ]);
    }

    public function test_scheduled_campaign_is_processed_by_command_and_only_once(): void
    {
        $this->customer('Scheduled Member', 'gold', 120000, true, '081234567898', 'scheduled@example.com');
        $segment = CustomerSegment::create([
            'name' => 'Scheduled Segment',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);
        $campaign = CrmCampaign::create([
            'name' => 'Terjadwal',
            'segment_id' => $segment->id,
            'type' => 'birthday',
            'channel' => 'copy',
            'message' => 'Selamat ulang tahun {name}',
            'is_dry_run' => true,
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->artisan('crm:process-campaigns')->assertSuccessful();

        $this->assertSame('previewed', $campaign->fresh()->status);
        $this->assertDatabaseCount('crm_campaign_deliveries', 1);

        $this->artisan('crm:process-campaigns')->assertSuccessful();
        $this->assertDatabaseCount('crm_campaign_deliveries', 1);
    }

    public function test_retry_resends_only_failed_recipients(): void
    {
        // Token dari .env development dibuang eksplisit. Kalau tidak, test ini
        // ikut memakai FONNTE_TOKEN milik mesin-developer dan langkah
        // "provider belum siap" di bawah tidak terjadi -- sehingga test
        // gagal di laptop yang sudah punya token, dan lolos di laptop lain.
        config(['services.crm.fonnte_token' => null]);

        ReportSetting::updateOrCreate(['id' => 1], [
            'is_active' => true,
            'method' => 'whatsapp',
            'target' => '081234567896',
            'send_at' => '21:00',
            'wa_api_key' => null,
        ]);

        $first = $this->customer('Retry One', 'gold', 100000, true, '081234567899', 'one@example.com');
        $second = $this->customer('Retry Two', 'gold', 100000, true, '081234567901', 'two@example.com');

        $segment = CustomerSegment::create([
            'name' => 'Retry Segment',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);
        $campaign = CrmCampaign::create([
            'name' => 'Retry WA',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Halo {name}',
            'is_dry_run' => false,
            'status' => 'draft',
        ]);

        $firstRun = app(CrmCampaignService::class)->run($campaign);
        $this->assertSame(2, $firstRun['failed']);

        // Provider sudah siap: hanya penerima yang gagal yang dikirim ulang.
        $campaign->update(['status' => 'partial']);
        ReportSetting::updateOrCreate(['id' => 1], ['wa_api_key' => 'test-token']);
        Http::fake(['api.fonnte.com/*' => Http::response(['id' => 'msg-retry'], 200)]);

        $secondRun = app(CrmCampaignService::class)->run($campaign);

        $this->assertSame(2, $secondRun['sent']);
        $this->assertSame('sent', $campaign->fresh()->status);
        $this->assertDatabaseHas('crm_campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'customer_id' => $first->id,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('crm_campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'customer_id' => $second->id,
            'status' => 'sent',
        ]);
    }

    public function test_stale_processing_campaign_is_reclaimed(): void
    {
        $this->customer('Stale Member', 'gold', 100000, true, '081234567902', 'stale@example.com');
        $segment = CustomerSegment::create([
            'name' => 'Stale Segment',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);
        $campaign = CrmCampaign::create([
            'name' => 'Stuck Campaign',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'copy',
            'message' => 'Halo {name}',
            'is_dry_run' => true,
            'status' => 'processing',
            'last_run_at' => now()->subHour(),
        ]);

        $result = app(CrmCampaignService::class)->run($campaign);

        $this->assertSame(1, $result['preview']);
        $this->assertSame('previewed', $campaign->fresh()->status);
    }

    public function test_campaign_already_processing_is_not_run_concurrently(): void
    {
        $this->customer('Busy Member', 'gold', 100000, true, '081234567903', 'busy@example.com');
        $segment = CustomerSegment::create([
            'name' => 'Busy Segment',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);
        $campaign = CrmCampaign::create([
            'name' => 'Running Now',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'copy',
            'message' => 'Halo {name}',
            'is_dry_run' => true,
            'status' => 'processing',
            'last_run_at' => now(),
        ]);

        $result = app(CrmCampaignService::class)->run($campaign);

        $this->assertSame(0, $result['total']);
        $this->assertDatabaseCount('crm_campaign_deliveries', 0);
    }

    public function test_campaign_detail_shows_live_sample_without_running_or_sending(): void
    {
        $access = Permission::firstOrCreate(['name' => 'crm-access', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo($access);

        $customer = $this->customer('Budi Sample', 'gold', 250000, true, '081234567904', 'budi@example.com');
        $noEmail = $this->customer('Siti NoEmail', 'gold', 250000, true, '081234567905', 'siti@example.com');
        $noEmail->update(['email' => null]);

        $segment = CustomerSegment::create([
            'name' => 'Sample Segment',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);
        $campaign = CrmCampaign::create([
            'name' => 'Sample Preview',
            'segment_id' => $segment->id,
            'type' => 'tier_promotion',
            'channel' => 'email',
            'subject' => 'Promo',
            'message' => 'Halo {name}, poin kamu {points}.',
            'is_dry_run' => true,
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get(route('crm.campaigns.show', $campaign))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Crm/CampaignDetail')
                ->where('campaign.name', 'Sample Preview')
                ->has('sample', 2)
                ->has('deliveries.data', 0));

        // Pesan harus benar-benar terpersonalisasi, bukan placeholder mentah.
        $messages = app(CrmCampaignService::class)->sample($campaign, 10);
        $byCustomer = collect($messages)->keyBy('customer.id');

        $this->assertSame('Halo Budi Sample, poin kamu 0.', $byCustomer[$customer->id]['message']);
        $this->assertTrue($byCustomer[$customer->id]['deliverable']);
        $this->assertSame('budi@example.com', $byCustomer[$customer->id]['target']);

        // Pelanggan tanpa email harus ditandai dilewati, bukan dikirim.
        $this->assertFalse($byCustomer[$noEmail->id]['deliverable']);
        $this->assertStringContainsString('Email', $byCustomer[$noEmail->id]['reason']);

        // Halaman preview tidak boleh menulis data pengiriman.
        $this->assertDatabaseCount('crm_campaign_deliveries', 0);
    }

    public function test_opted_out_customer_is_excluded_from_segment_and_blocked_at_delivery(): void
    {
        $optedOut = $this->customer('Opted Out', 'gold', 250000, false, '081234567906', 'out@example.com');
        $segment = CustomerSegment::create([
            'name' => 'Opt Segment',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);
        $campaign = CrmCampaign::create([
            'name' => 'Opt Preview',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Halo {name}',
            'is_dry_run' => false,
            'status' => 'draft',
        ]);

        // Lapisan pertama: segment query tidak memuat pelanggan yang tidak opt-in.
        $this->assertEmpty(app(CrmCampaignService::class)->sample($campaign, 5));

        // Lapisan kedua (jaring pengaman): bila pelanggan opting-out di tengah run,
        // pengiriman tetap diblokir walau sudah lolos query.
        $optedOut->update(['crm_opt_out_at' => now(), 'marketing_opt_in' => true]);
        $this->assertNotNull(
            app(CrmCampaignService::class)->deliveryBlocker($campaign, $optedOut->fresh())
        );
    }

    public function test_campaign_detail_filters_deliveries(): void
    {
        $access = Permission::firstOrCreate(['name' => 'crm-access', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo($access);

        $customer = $this->customer('Filter Test', 'gold', 250000, true, '081234567907', 'filter@example.com');
        $segment = CustomerSegment::create([
            'name' => 'Filter Segment',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);
        $campaign = CrmCampaign::create([
            'name' => 'Filter Campaign',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'copy',
            'message' => 'Halo {name}',
            'is_dry_run' => true,
            'status' => 'draft',
        ]);

        app(CrmCampaignService::class)->run($campaign);

        $this->actingAs($user)
            ->get(route('crm.campaigns.show', ['campaign' => $campaign->id, 'status' => 'preview']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Crm/CampaignDetail')
                ->has('deliveries.data', 1)
                ->where('filters.status', 'preview'));

        $this->actingAs($user)
            ->get(route('crm.campaigns.show', ['campaign' => $campaign->id, 'status' => 'failed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('deliveries.data', 0));

        $this->actingAs($user)
            ->get(route('crm.campaigns.show', ['campaign' => $campaign->id, 'search' => 'Filter Test']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('deliveries.data', 1));

        $this->actingAs($user)
            ->get(route('crm.campaigns.show', ['campaign' => $campaign->id, 'search' => 'Tidak Ada']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('deliveries.data', 0));

        $this->assertDatabaseHas('crm_campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_inactive_segment_blocks_delivery_but_still_shows_potential_recipients(): void
    {
        $customer = $this->customer('Paused Member', 'gold', 250000, true, '081234567910', 'paused@example.com');
        $segment = CustomerSegment::create([
            'name' => 'Segment Paused',
            'rules' => ['tiers' => ['gold']],
            'is_active' => false,
        ]);
        $campaign = CrmCampaign::create([
            'name' => 'Paused Campaign',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'copy',
            'message' => 'Halo {name}',
            'is_dry_run' => true,
            'status' => 'draft',
        ]);

        $service = app(CrmCampaignService::class);
        $segments = app(CrmSegmentService::class);

        // Admin tetap bisa melihat Segment Paused punya 1 penerima.
        $this->assertSame(1, $segments->count($segment));
        // Tapi query pengiriman tidak boleh mengembalikan apa pun.
        $this->assertSame(0, $segments->query($segment)->count());

        $this->assertNotNull($service->runBlocker($campaign));
        $this->assertEmpty($service->sample($campaign, 10));

        $result = $service->run($campaign);
        $this->assertSame(0, $result['total']);
        $this->assertDatabaseCount('crm_campaign_deliveries', 0);
        $this->assertSame('draft', $campaign->fresh()->status, 'Status tidak boleh berubah jadi gagal.');

        // Aktifkan lagi -> langsung bisa jalan.
        $segment->update(['is_active' => true]);
        $this->assertNull($service->runBlocker($campaign));
        $reRun = $service->run($campaign);
        $this->assertSame(1, $reRun['preview']);
        $this->assertDatabaseHas('crm_campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_scheduled_campaign_with_inactive_segment_is_deferred_not_cancelled(): void
    {
        $this->customer('Deferred Member', 'gold', 250000, true, '081234567895', 'deferred@example.com');
        $segment = CustomerSegment::create([
            'name' => 'Segment Nonaktif',
            'rules' => ['tiers' => ['gold']],
            'is_active' => false,
        ]);
        $campaign = CrmCampaign::create([
            'name' => 'Campaign Ditunda',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'copy',
            'message' => 'Halo {name}',
            'is_dry_run' => true,
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->artisan('crm:process-campaigns')->assertSuccessful();

        // Tetap scheduled supaya otomatis jalan saat segment diaktifkan.
        $this->assertSame('scheduled', $campaign->fresh()->status);
        $this->assertDatabaseCount('crm_campaign_deliveries', 0);

        $segment->update(['is_active' => true]);
        $this->artisan('crm:process-campaigns')->assertSuccessful();

        $this->assertSame('previewed', $campaign->fresh()->status);
        $this->assertDatabaseCount('crm_campaign_deliveries', 1);
    }

    public function test_birthday_segment_selects_customers_by_birth_month_and_day(): void
    {
        $match = $this->customer('Birthday Girl', 'bronze', 0, true, '081234567911', 'bday@example.com');
        $this->customer('Other Month', 'bronze', 0, true, '081234567908', 'other@example.com');

        $match->update(['birthday' => now()->setDate(1995, 7, 14)]);
        $other = Customer::where('no_telp', '081234567908')->firstOrFail();
        $other->update(['birthday' => now()->setDate(1993, 12, 25)]);

        $segment = CustomerSegment::create([
            'name' => 'Birthday Juli',
            'rules' => ['birthday_month' => 7, 'birthday_day' => 14],
            'is_active' => true,
        ]);

        $matched = app(CrmSegmentService::class)->query($segment)->pluck('customers.id')->all();

        $this->assertContains($match->id, $matched);
        $this->assertNotContains($other->id, $matched);
    }

    public function test_segment_store_rejects_invalid_birthday_and_tier_values(): void
    {
        $manage = Permission::firstOrCreate(['name' => 'crm-manage', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo($manage);

        $this->actingAs($user)
            ->post(route('crm.segments.store'), [
                'name' => 'Segment Salah',
                'rules' => ['birthday_month' => 13],
            ])
            ->assertSessionHasErrors('rules.birthday_month');

        $this->actingAs($user)
            ->post(route('crm.segments.store'), [
                'name' => 'Segment Salah Tier',
                'rules' => ['tiers' => ['diamond']],
            ])
            ->assertSessionHasErrors('rules.tiers.0');

        $this->assertDatabaseCount('customer_segments', 0);
    }

    public function test_opt_out_and_opt_in_routes_toggle_campaign_eligibility(): void
    {
        $manage = Permission::firstOrCreate(['name' => 'customers-edit', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo($manage);
        $customer = $this->customer('Toggle', 'bronze', 0, true, '081234567912', 'toggle@example.com');

        $this->actingAs($user)
            ->post(route('customers.crm.opt-out', $customer), ['reason' => 'Permintaan pelanggan'])
            ->assertRedirect();

        $customer->refresh();
        $this->assertNotNull($customer->crm_opt_out_at);
        $this->assertFalse($customer->marketing_opt_in);
        $this->assertSame('Permintaan pelanggan', $customer->crm_opt_out_reason);

        $this->actingAs($user)
            ->post(route('customers.crm.opt-in', $customer))
            ->assertRedirect();

        $customer->refresh();
        $this->assertNull($customer->crm_opt_out_at);
        $this->assertTrue($customer->marketing_opt_in);
    }

    protected function customer(string $name, string $tier, float $spend, bool $optIn, string $phone = '081234567890', string $email = 'customer@example.com'): Customer
    {
        return Customer::create([
            'name' => $name,
            'no_telp' => $phone,
            'email' => $email,
            'address' => 'Alamat CRM',
            'marketing_opt_in' => $optIn,
            'crm_opt_out_at' => $optIn ? null : now(),
            'crm_opt_out_reason' => $optIn ? null : 'Test opt-out',
            'loyalty_tier' => $tier,
            'lifetime_spend' => $spend,
        ]);
    }

    protected function paidTransaction(Customer $customer, float $amount, ?\Carbon\Carbon $at = null): Transaction
    {
        $category = Category::create(['name' => 'CRM '.Str::random(4)]);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'Produk CRM',
            'buy_price' => 10000,
            'sell_price' => $amount,
            'stock' => 10,
            'unit' => 'Pcs',
        ]);
        $transaction = Transaction::create([
            'cashier_id' => User::factory()->create()->id,
            'customer_id' => $customer->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(10)),
            'cash' => $amount,
            'change' => 0,
            'discount' => 0,
            'taxable_amount' => $amount,
            'tax_amount' => 0,
            'grand_total' => $amount,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);
        if ($at) {
            $transaction->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
        $transaction->details()->create([
            'product_id' => $product->id,
            'qty' => 1,
            'price' => $amount,
        ]);

        return $transaction;
    }
}
