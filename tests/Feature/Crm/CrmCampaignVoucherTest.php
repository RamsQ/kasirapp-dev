<?php

namespace Tests\Feature\Crm;

use App\Models\CrmCampaign;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\CustomerVoucher;
use App\Models\PromoCode;
use App\Models\ReportSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CrmCampaignService;
use App\Services\CrmVoucherService;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrmCampaignVoucherTest extends TestCase
{
    use RefreshDatabase;

    protected function customer(string $name, string $tier = 'gold', string $phone = '0812000000', string $email = 'voucher@example.com'): Customer
    {
        return Customer::create([
            'name' => $name,
            'no_telp' => $phone,
            'email' => $email,
            'address' => 'Alamat',
            'marketing_opt_in' => true,
            'loyalty_tier' => $tier,
            'lifetime_spend' => 500000,
        ]);
    }

    protected function segment(): CustomerSegment
    {
        return CustomerSegment::create([
            'name' => 'Segment Voucher',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);
    }

    protected function enableWhatsApp(): void
    {
        ReportSetting::updateOrCreate(['id' => 1], [
            'is_active' => true,
            'method' => 'whatsapp',
            'target' => '0812000',
            'send_at' => '21:00',
            'wa_api_key' => 'test-token',
        ]);
    }

    public function test_campaign_no_longer_exposes_personal_vouchers_of_other_customers(): void
    {
        $owner = $this->customer('Pemilik Voucher', 'gold', '0812000001', 'owner@example.com');
        CustomerVoucher::create([
            'customer_id' => $owner->id,
            'code' => 'RAHASIA',
            'name' => 'Voucher Pribadi',
            'type' => 'fixed',
            'value' => 10000,
            'status' => 'active',
        ]);

        $segment = $this->segment();
        $campaign = CrmCampaign::create([
            'name' => 'Tanpa Voucher',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'copy',
            'message' => 'Kode kamu {voucher_code}',
            'is_dry_run' => true,
            'status' => 'draft',
        ]);

        $message = app(CrmCampaignService::class)->sample($campaign, 5)[0]['message'];

        $this->assertStringNotContainsString('RAHASIA', $message);
        $this->assertDatabaseMissing('crm_campaigns', ['id' => $campaign->id, 'voucher_id' => DB::table('customer_vouchers')->value('id')]);
    }

    public function test_per_recipient_mode_issues_a_unique_voucher_for_each_recipient(): void
    {
        $this->enableWhatsApp();
        Http::fake(['api.fonnte.com/*' => Http::response(['id' => 'msg-1'], 200)]);

        $first = $this->customer('Penerima Satu', 'gold', '0812000002', 'satu@example.com');
        $second = $this->customer('Penerima Dua', 'gold', '0812000003', 'dua@example.com');

        $segment = $this->segment();
        $campaign = CrmCampaign::create([
            'name' => 'Voucher Unik',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Halo {name}, kode unik kamu {voucher_code}',
            'is_dry_run' => false,
            'status' => 'draft',
            'voucher_mode' => CrmVoucherService::MODE_PER_RECIPIENT,
            'voucher_name' => 'Diskon 10rb',
            'voucher_type' => 'fixed',
            'voucher_value' => 10000,
            'voucher_min_spend' => 50000,
            'voucher_expires_at' => now()->addMonth(),
            'voucher_prefix' => 'PROMO',
        ]);

        $result = app(CrmCampaignService::class)->run($campaign);

        $this->assertSame(2, $result['sent']);
        $this->assertSame(2, CustomerVoucher::where('source_campaign_id', $campaign->id)->count());

        $vouchers = CustomerVoucher::where('source_campaign_id', $campaign->id)->get();
        $codes = $vouchers->pluck('code');

        $this->assertCount(2, $codes->unique(), 'Kode harus berbeda per penerima.');
        $codes->each(fn ($code) => $this->assertStringStartsWith('PROMO-', $code));

        // Voucher terikat ke pemiliknya masing-masing.
        foreach ($vouchers as $voucher) {
            $this->assertNotNull(
                Customer::find($voucher->customer_id),
                'Voucher harus punya pemilik.'
            );
        }

        // Kode yang terkirim harus sama persis dengan voucher yang diterbitkan.
        $messages = $campaign->deliveries()->get()->pluck('message');
        foreach ($vouchers as $voucher) {
            $this->assertTrue(
                $messages->contains(fn ($message) => str_contains($message, $voucher->code)),
                'Kode voucher harus muncul di pesan yang dikirim.'
            );
        }
    }

    public function test_per_recipient_voucher_issuance_is_idempotent_on_retry(): void
    {
        $this->enableWhatsApp();
        Http::fake(['api.fonnte.com/*' => Http::response(['id' => 'msg-1'], 200)]);

        $this->customer('Idempotent', 'gold', '0812000004', 'idem@example.com');
        $segment = $this->segment();
        $campaign = CrmCampaign::create([
            'name' => 'Idempotent Voucher',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Kode {voucher_code}',
            'is_dry_run' => false,
            'status' => 'draft',
            'voucher_mode' => CrmVoucherService::MODE_PER_RECIPIENT,
            'voucher_value' => 5000,
            'voucher_type' => 'fixed',
        ]);

        $service = app(CrmCampaignService::class);
        $service->run($campaign);

        $firstCode = CustomerVoucher::where('source_campaign_id', $campaign->id)->value('code');

        // Paksa jalan ulang; penerima yang sudah sukses tidak boleh dapat voucher kedua.
        $campaign->update(['status' => 'partial']);
        $service->run($campaign);

        $this->assertSame(1, CustomerVoucher::where('source_campaign_id', $campaign->id)->count());
        $this->assertSame($firstCode, CustomerVoucher::where('source_campaign_id', $campaign->id)->value('code'));
    }

    public function test_dry_run_does_not_issue_real_vouchers(): void
    {
        $this->customer('Preview Saja', 'gold', '0812000005', 'preview@example.com');
        $segment = $this->segment();
        $campaign = CrmCampaign::create([
            'name' => 'Preview Voucher',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'copy',
            'message' => 'Kode contoh {voucher_code}',
            'is_dry_run' => true,
            'status' => 'draft',
            'voucher_mode' => CrmVoucherService::MODE_PER_RECIPIENT,
            'voucher_value' => 5000,
            'voucher_type' => 'fixed',
            'voucher_prefix' => 'PROMO',
        ]);

        $result = app(CrmCampaignService::class)->run($campaign);

        $this->assertSame(1, $result['preview']);
        $this->assertDatabaseCount('customer_vouchers', 0);
        $this->assertMatchesRegularExpression(
            '/Kode contoh PROMO-[A-Z0-9]{6}/',
            $campaign->deliveries()->first()->message
        );
    }

    public function test_shared_mode_uses_one_promo_code_for_every_recipient(): void
    {
        $this->enableWhatsApp();
        Http::fake(['api.fonnte.com/*' => Http::response(['id' => 'msg-1'], 200)]);

        $this->customer('Penerima A', 'gold', '0812000006', 'a@example.com');
        $this->customer('Penerima B', 'gold', '0812000007', 'b@example.com');

        $segment = $this->segment();
        $campaign = CrmCampaign::create([
            'name' => 'Promo Bersama',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Halo {name}, pakai kode {voucher_code}',
            'is_dry_run' => false,
            'status' => 'draft',
            'voucher_mode' => CrmVoucherService::MODE_SHARED,
            'voucher_name' => 'Promo Semua',
            'voucher_type' => 'fixed',
            'voucher_value' => 15000,
            'voucher_min_spend' => 100000,
            'shared_voucher_code' => 'PROMO2026',
        ]);

        $result = app(CrmCampaignService::class)->run($campaign);

        $this->assertSame(2, $result['sent']);
        $this->assertDatabaseCount('promo_codes', 1);
        $this->assertDatabaseCount('customer_vouchers', 0);

        $promo = PromoCode::where('code', 'PROMO2026')->firstOrFail();
        $this->assertEquals(15000, $promo->value);
        $this->assertEquals(100000, $promo->min_spend);
        $this->assertSame($campaign->id, $promo->crm_campaign_id);

        $messages = $campaign->deliveries()->pluck('message');
        $this->assertCount(2, $messages->filter(fn ($m) => str_contains($m, 'PROMO2026')));
    }

    public function test_shared_promo_code_is_accepted_at_checkout_for_any_customer(): void
    {
        $loyalty = app(LoyaltyService::class);

        PromoCode::create([
            'code' => 'PROMO2026',
            'name' => 'Promo Semua',
            'type' => 'fixed',
            'value' => 15000,
            'min_spend' => 100000,
            'is_active' => true,
        ]);

        $customer = $this->customer('Pengguna Promo', 'bronze', '0812000008', 'promo@example.com');

        // Berbeda dari voucher pribadi, kode promo boleh dipakai pelanggan mana pun.
        $this->assertSame(15000, $loyalty->voucherDiscountFor($customer, 'PROMO2026', 200000));
        $this->assertSame(15000, $loyalty->voucherDiscountFor($customer, 'promo2026', 200000));
    }

    public function test_shared_promo_code_respects_min_spend_and_expiry(): void
    {
        $loyalty = app(LoyaltyService::class);

        PromoCode::create([
            'code' => 'MINSPEND',
            'name' => 'Promo Minimum',
            'type' => 'fixed',
            'value' => 10000,
            'min_spend' => 100000,
            'is_active' => true,
        ]);
        PromoCode::create([
            'code' => 'EXPIRED',
            'name' => 'Promo Kedaluwarsa',
            'type' => 'fixed',
            'value' => 10000,
            'expires_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $customer = $this->customer('Uji Batas', 'bronze', '0812000009', 'batas@example.com');

        // Di bawah minimum belanja -> ditolak, sama seperti voucher pribadi.
        try {
            $loyalty->voucherDiscountFor($customer, 'MINSPEND', 50000);
            $this->fail('Kode promo di bawah min belanja seharusnya ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tidak valid', $e->getMessage());
        }

        $this->assertSame(10000, $loyalty->voucherDiscountFor($customer, 'MINSPEND', 200000));

        $this->expectException(\RuntimeException::class);
        $loyalty->voucherDiscountFor($customer, 'EXPIRED', 500000);
    }

    public function test_shared_promo_code_usage_counting_follows_reserve_consume_release(): void
    {
        $this->enableWhatsApp();
        Http::fake(['api.fonnte.com/*' => Http::response(['id' => 'msg-1'], 200)]);

        $customer = $this->customer('Pemakai Promo', 'bronze', '0812000010', 'pakai@example.com');
        $promo = PromoCode::create([
            'code' => 'KUOTA',
            'name' => 'Promo Kuota',
            'type' => 'fixed',
            'value' => 10000,
            'max_uses' => 2,
            'is_active' => true,
        ]);

        $loyalty = app(LoyaltyService::class);
        $cashier = User::factory()->create();

        $makeTransaction = fn (string $invoice) => Transaction::create([
            'cashier_id' => $cashier->id,
            'customer_id' => $customer->id,
            'invoice' => $invoice,
            'cash' => 200000,
            'change' => 0,
            'discount' => 0,
            'taxable_amount' => 200000,
            'tax_amount' => 0,
            'grand_total' => 190000,
            'voucher_code' => 'KUOTA',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);

        $first = $makeTransaction('TRX-PROMO-01');
        $loyalty->reserveVoucher($customer, 'KUOTA', 200000, $first);
        $this->assertSame(1, $promo->fresh()->reserved_count);
        $this->assertSame(0, $promo->fresh()->used_count);

        $loyalty->consumeVoucher($first);
        $this->assertSame(0, $promo->fresh()->reserved_count);
        $this->assertSame(1, $promo->fresh()->used_count);

        // Reservasi yang gagal dibayar harus mengembalikan kuota, sehingga
        // pemakaian ke-2 masih bisa masuk.
        $failed = $makeTransaction('TRX-PROMO-02');
        $loyalty->reserveVoucher($customer, 'KUOTA', 200000, $failed);
        $this->assertSame(1, $promo->fresh()->reserved_count);

        $loyalty->releaseVoucher($failed);
        $this->assertSame(0, $promo->fresh()->reserved_count);
        $this->assertSame(1, $promo->fresh()->used_count);

        $second = $makeTransaction('TRX-PROMO-03');
        $loyalty->reserveVoucher($customer, 'KUOTA', 200000, $second);
        $loyalty->consumeVoucher($second);
        $this->assertSame(2, $promo->fresh()->used_count);

        // Kuota max_uses=2 sudah habis, pemakaian berikutnya harus ditolak.
        $third = $makeTransaction('TRX-PROMO-04');
        $this->expectException(\RuntimeException::class);
        $loyalty->reserveVoucher($customer, 'KUOTA', 200000, $third);
    }

    public function test_personal_voucher_still_takes_priority_and_stays_customer_bound(): void
    {
        $loyalty = app(LoyaltyService::class);

        $owner = $this->customer('Pemilik', 'bronze', '0812000011', 'pemilik@example.com');
        $other = $this->customer('Orang Lain', 'bronze', '0812000012', 'lain@example.com');

        CustomerVoucher::create([
            'customer_id' => $owner->id,
            'code' => 'PRIBADI',
            'name' => 'Voucher Pribadi',
            'type' => 'fixed',
            'value' => 25000,
            'status' => 'active',
        ]);

        $this->assertSame(25000, $loyalty->voucherDiscountFor($owner, 'PRIBADI', 200000));

        // Orang lain tidak boleh memakai voucher pribadi ini.
        $this->expectException(\RuntimeException::class);
        $loyalty->voucherDiscountFor($other, 'PRIBADI', 200000);
    }

    public function test_campaign_validation_requires_voucher_value_and_shared_code(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'crm-manage', 'guard_name' => 'web'])
        );

        $segment = $this->segment();
        $base = [
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'copy',
            'message' => 'Halo {name}',
        ];

        $this->actingAs($user)
            ->post(route('crm.campaigns.store'), array_merge($base, [
                'name' => 'Tanpa Nilai',
                'voucher_mode' => 'per_recipient',
            ]))
            ->assertSessionHasErrors('voucher_value');

        $this->actingAs($user)
            ->post(route('crm.campaigns.store'), array_merge($base, [
                'name' => 'Shared Tanpa Kode',
                'voucher_mode' => 'shared',
                'voucher_value' => 10000,
            ]))
            ->assertSessionHasErrors('shared_voucher_code');

        $this->actingAs($user)
            ->post(route('crm.campaigns.store'), array_merge($base, [
                'name' => 'Kode Buruk',
                'voucher_mode' => 'shared',
                'voucher_value' => 10000,
                'shared_voucher_code' => 'ADA SPASI!',
            ]))
            ->assertSessionHasErrors('shared_voucher_code');
    }

    public function test_switching_mode_to_none_clears_stale_voucher_definition(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'crm-manage', 'guard_name' => 'web'])
        );

        $segment = $this->segment();
        $campaign = CrmCampaign::create([
            'name' => 'Awalnya Promo',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'copy',
            'message' => 'Halo {name}',
            'status' => 'draft',
            'voucher_mode' => 'shared',
            'voucher_name' => 'Promo Lama',
            'voucher_type' => 'fixed',
            'voucher_value' => 10000,
            'shared_voucher_code' => 'LAMA2025',
        ]);

        $this->actingAs($user)
            ->put(route('crm.campaigns.update', $campaign), [
                'name' => 'Awalnya Promo',
                'segment_id' => $segment->id,
                'type' => 'manual',
                'channel' => 'copy',
                'message' => 'Halo {name}',
                'voucher_mode' => 'none',
            ])
            ->assertSessionHasNoErrors();

        $campaign->refresh();
        $this->assertSame('none', $campaign->voucher_mode);
        $this->assertNull($campaign->shared_voucher_code);
        $this->assertNull($campaign->voucher_value);
    }
}
