<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT: nomor WhatsApp harus sampai ke provider dalam format yang diterima,
 * dan operator harus bisa menguji token sebelum menyentuh pelanggan.
 *
 * MASALAH ASAL
 *
 * Nomor pelanggan disimpan apa adanya dari form. Kasir mengetik
 * `0812-3456-7890`, aplikasi mengirimkannya apa adanya, dan Fonnte hanya
 * menerima `6281234567890`. Kegagalannya muncul sebagai `failed` di baris
 * delivery untuk setiap pelanggan — bukan sebagai satu error yang jelas.
 * Akibatnya campaign besar bisa setengah gagal dan pemicunya tidak kelihatan
 * di layar mana pun.
 *
 * Yang diuji di sini:
 *  - setiap bentuk penulisan nomor berakhir di format yang sama
 *  - nomor tidak bisa dikirim ditolak dengan alasan yang terbaca
 *  - nomor tolak tidak pernah sampai ke provider
 *  - tes koneksi mengirim pesan uji, bukan campaign
 *  - tes koneksi ada jejak lognya
 */
class CrmPhoneDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'crm-manage', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['email' => 'admin@gmail.com']);
        $this->admin->assignRole($role);
        $this->admin->givePermissionTo(['crm-manage']);
    }

    protected function segment(): CustomerSegment
    {
        return CustomerSegment::create([
            'name' => 'Semua Pelanggan',
            // Kolom `rules` NOT NULL di database. Isi kosong disengaja:
            // segment tanpa filter harus memuat semua pelanggan, sama seperti
            // segment yang dibuat lewat UI tanpa memilih aturan apa pun.
            'rules' => [],
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nomorIndonesia(): array
    {
        return [
            'nol depan polos' => ['081200000001', '6281200000001'],
            'pisah tanda hubung' => ['0812-0000-0001', '6281200000001'],
            'dengan plus' => ['+62 812 0000 0001', '6281200000001'],
            'sudah internasional' => ['6281200000001', '6281200000001'],
            'tanpa nol dan tanpa kode negara' => ['81200000001', '6281200000001'],
            'dengan kurung dan titik' => ['(0812) 0000.0001', '6281200000001'],
            'spasi di tengah' => ['0812 0000 0001', '6281200000001'],
            'nomor tetap' => ['0211234567', '62211234567'],
        ];
    }

    /**
     * @dataProvider nomorIndonesia
     */
    public function test_semua_bentuk_penulisan_nomor_berakhir_di_format_yang_sama(string $masuk, string $harapan): void
    {
        $customer = Customer::create([
            'name' => 'Budi',
            'no_telp' => $masuk,
            'marketing_opt_in' => true,
            'address' => 'Jl. Uji No. 1',
        ]);

        $campaign = \App\Models\CrmCampaign::create([
            'name' => 'Uji format',
            'segment_id' => $this->segment()->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Halo {name}',
            'is_dry_run' => false,
        ]);

        $target = app(\App\Services\CrmCampaignService::class)->targetFor($campaign, $customer);

        $this->assertSame($harapan, $target,
            "Nomor \"{$masuk}\" harus sampai ke provider sebagai {$harapan}.");
    }

    /**
     * @dataProvider nomorIndonesia
     */
    public function test_nomor_bervarious_bentuk_tidak_pernah_ditolak(string $masuk, string $harapan): void
    {
        $customer = Customer::create([
            'name' => 'Budi',
            'no_telp' => $masuk,
            'marketing_opt_in' => true,
            'address' => 'Jl. Uji No. 1',
        ]);

        $campaign = \App\Models\CrmCampaign::create([
            'name' => 'Uji blokir',
            'segment_id' => $this->segment()->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Halo',
            'is_dry_run' => false,
        ]);

        $blocker = app(\App\Services\CrmCampaignService::class)->deliveryBlocker($campaign, $customer);

        $this->assertNull($blocker, "Nomor \"{$masuk}\" seharusnya bisa dikirim.");
    }

    public function test_nomor_tidak_valid_ditolak_dengan_alasan_yang_bisa_dibaca(): void
    {
        $customer = Customer::create([
            'name' => 'Siti',
            'no_telp' => 'bukan-nomor',
            'marketing_opt_in' => true,
            'address' => 'Jl. Uji No. 1',
        ]);

        $campaign = \App\Models\CrmCampaign::create([
            'name' => 'Uji tolak',
            'segment_id' => $this->segment()->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Halo',
            'is_dry_run' => false,
        ]);

        $service = app(\App\Services\CrmCampaignService::class);
        $blocker = $service->deliveryBlocker($campaign, $customer);

        $this->assertNotNull($blocker, 'Nomor bukan-angka harus ditolak.');
        $this->assertStringContainsString('bukan-nomor', $blocker,
            'Alasan harus menyebut nomornya, supaya kasir tahu harus memperbaiki apa.');
        $this->assertNull($service->targetFor($campaign, $customer));
    }

    public function test_nomor_kosong_ditolak_sebagai_belum_diisi(): void
    {
        $customer = Customer::create([
            'name' => 'Rina',
            'no_telp' => '',
            'marketing_opt_in' => true,
            'address' => 'Jl. Uji No. 1',
        ]);

        $campaign = \App\Models\CrmCampaign::create([
            'name' => 'Uji kosong',
            'segment_id' => $this->segment()->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Halo',
            'is_dry_run' => false,
        ]);

        $blocker = app(\App\Services\CrmCampaignService::class)->deliveryBlocker($campaign, $customer);

        $this->assertSame('Nomor WhatsApp belum diisi.', $blocker);
    }

    public function test_nomor_tidak_valid_tidak_pernah_sampai_ke_provider(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response(['id' => 'msg-1'], 200)]);

        $customer = Customer::create([
            'name' => 'Siti',
            'no_telp' => 'bukan-nomor',
            'marketing_opt_in' => true,
            'address' => 'Jl. Uji No. 1',
        ]);

        $segment = $this->segment();
        $campaign = \App\Models\CrmCampaign::create([
            'name' => 'Jangan kirim',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Halo',
            'is_dry_run' => false,
        ]);

        app(\App\Services\CrmCampaignService::class)->run($campaign, force: true);

        Http::assertNothingSent();

        $this->assertDatabaseHas('crm_campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'status' => 'skipped',
        ]);
    }

    public function test_pesan_yang_dikirim_menggunakan_nomor_yang_sudah_dinormalisasi(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response(['id' => 'msg-1'], 200)]);
        config(['mail.default' => 'array']);

        \App\Models\ReportSetting::updateOrCreate(['id' => 1], ['wa_api_key' => 'token-uji']);

        $customer = Customer::create([
            'name' => 'Budi',
            'no_telp' => '0812-0000-0001',
            'marketing_opt_in' => true,
            'address' => 'Jl. Uji No. 1',
        ]);

        $campaign = \App\Models\CrmCampaign::create([
            'name' => 'Kirim nyata',
            'segment_id' => $this->segment()->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Halo {name}',
            'is_dry_run' => false,
        ]);

        app(\App\Services\CrmCampaignService::class)->run($campaign, force: true);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.fonnte.com/send'
                && $request['target'] === '6281200000001';
        });

        $this->assertDatabaseHas('crm_campaign_deliveries', [
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
            'status' => 'sent',
            'target' => '6281200000001',
        ]);
    }

    public function test_tes_koneksi_mengirim_pesan_ke_nomor_yang_dinormalisasi(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response(['id' => 'msg-uji'], 200)]);
        \App\Models\ReportSetting::updateOrCreate(['id' => 1], ['wa_api_key' => 'token-uji']);

        $this->actingAs($this->admin)
            ->post(route('crm.test-send'), [
                'phone' => '0812-3456-7890',
                'channel' => 'whatsapp',
            ])
            ->assertRedirect();

        Http::assertSent(function (Request $request): bool {
            return $request['target'] === '6281234567890';
        });

        // Pesan uji harus menandai dirinya sebagai uji, supaya penerima tidak
        // salah mengiranya sebagai promo atau tagihan.
        Http::assertSent(function (Request $request): bool {
            return str_contains((string) $request['message'], 'pesan uji');
        });
    }

    public function test_tes_koneksi_tidak_membuat_campaign_atau_voucher(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response(['id' => 'msg-uji'], 200)]);
        \App\Models\ReportSetting::updateOrCreate(['id' => 1], ['wa_api_key' => 'token-uji']);

        $this->actingAs($this->admin)
            ->post(route('crm.test-send'), [
                'phone' => '0812-3456-7890',
                'channel' => 'whatsapp',
            ]);

        $this->assertSame(0, \App\Models\CrmCampaign::count(),
            'Tes koneksi tidak boleh membuat campaign.');
        $this->assertSame(0, \App\Models\CrmCampaignDelivery::count(),
            'Tes koneksi tidak boleh masuk ke riwayat delivery, '
            .'supaya tidak tercampur dengan pengiriman campaign sungguhan.');
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('customer_vouchers')->count());
    }

    public function test_tes_koneksi_menolak_nomor_tidak_valid_tanpa_mengirim_apa_pun(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response([], 200)]);

        $this->actingAs($this->admin)
            ->from(route('crm.campaigns.index'))
            ->post(route('crm.test-send'), [
                'phone' => 'bukan-nomor',
                'channel' => 'whatsapp',
            ])
            ->assertRedirect(route('crm.campaigns.index'))
            ->assertSessionHasErrors('phone');

        Http::assertNothingSent();
    }

    public function test_tes_koneksi_menolak_email_tidak_valid(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->from(route('crm.campaigns.index'))
            ->post(route('crm.test-send'), [
                'phone' => 'bukan-email',
                'channel' => 'email',
            ])
            ->assertSessionHasErrors('phone');

        Http::assertNothingSent();
    }

    public function test_tes_koneksi_menampilkan_kegagalan_provider_apa_adanya(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response(['detail' => 'token invalid'], 401)]);
        \App\Models\ReportSetting::updateOrCreate(['id' => 1], ['wa_api_key' => 'token-salah']);

        $this->actingAs($this->admin)
            ->from(route('crm.campaigns.index'))
            ->post(route('crm.test-send'), [
                'phone' => '0812-3456-7890',
                'channel' => 'whatsapp',
            ])
            ->assertSessionHasErrors('phone');

        // Kegagalan harus sampai ke layar apa adanya, bukan jadi pesan
        // generik: operator perlu tahu itu masalah token, bukan masalah
        // nomor tujuannya.
        $pesan = mb_strtolower(collect(session('errors')->get('phone'))->implode(' '));
        $this->assertStringContainsString('gagal', $pesan);
        $this->assertStringContainsString('fonnte', $pesan);
    }

    public function test_tes_koneksi_menulis_jejak_log(): void
    {
        Http::fake(['api.fonnte.com/*' => Http::response(['id' => 'msg-uji'], 200)]);
        \App\Models\ReportSetting::updateOrCreate(['id' => 1], ['wa_api_key' => 'token-uji']);

        Log::spy();

        $this->actingAs($this->admin)
            ->post(route('crm.test-send'), [
                'phone' => '0812-3456-7890',
                'channel' => 'whatsapp',
            ]);

        // Jejak log penting: endpoint ini bisa mengirim pesan sungguhan ke
        // nomor mana pun yang diketik operator, jadi harus bisa ditelusuri
        // siapa mengetes ke mana.
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => $message === 'CRM test send'
                && ($context['target'] ?? null) === '6281234567890'
                && ($context['user_id'] ?? null) === $this->admin->id
                && ($context['status'] ?? null) === 'sent');
    }

    public function test_tes_koneksi_menuntut_izin_manajemen_campaign(): void
    {
        Http::fake();

        $biasa = User::factory()->create();
        $biasa->givePermissionTo(['crm-manage']);

        $tanpaIzin = User::factory()->create();
        $tanpaIzin->revokePermissionTo(['crm-manage']);
        $tanpaIzin->refresh();

        $this->actingAs($tanpaIzin)
            ->post(route('crm.test-send'), [
                'phone' => '0812-3456-7890',
                'channel' => 'whatsapp',
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_tes_koneksi_wajib_punya_nomor(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->from(route('crm.campaigns.index'))
            ->post(route('crm.test-send'), [
                'phone' => '',
                'channel' => 'whatsapp',
            ])
            ->assertSessionHasErrors('phone');

        Http::assertNothingSent();
    }
}
