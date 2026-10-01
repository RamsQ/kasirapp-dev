<?php

namespace Tests\Feature\Crm;

use App\Models\CrmCampaign;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\User;
use App\Services\CrmCampaignService;
use App\Services\CrmVoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT: placeholder pesan harus bisa diisi, dan placeholder yang tidak bisa
 * diisi harus tertahan sebelum pesan dikirim ke pelanggan.
 *
 * MASALAH ASAL
 *
 * Dua kesalahan yang tidak pernah menimbulkan error, hanya terlihat setelah pesan
 * sampai di WhatsApp pelanggan sungguhan:
 *
 *  1. Placeholder salah ketik. `{namaa}` tidak ada di tabel penggantian,
 *     jadi teks itu terkirim apa adanya. Tidak ada delivery yang gagal --
 *     pesannya terkirim, hanya isinya salah baca.
 *
 *  2. Placeholder voucher tanpa voucher. Mode "Tanpa Voucher" membuat
 *     `{kode_voucher}` jadi string kosong, jadi pelanggan menerima
 *     "Kode: " dengan nol di belakangnya. Itu lebih buruk daripada error:
 *     pesan terlihat rapi, tapi tidak bisa dipakai.
 */
class CrmMessagePlaceholderTest extends TestCase
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
            'rules' => [],
            'is_active' => true,
        ]);
    }

    protected function customer(string $nama = 'Budi'): Customer
    {
        // Nomor dibuat unik per panggilan. `no_telp` punya batasan unique, dan
        // beberapa test memanggil `render()` berkali-kali dalam satu database.
        static $urut = 0;
        $urut++;

        return Customer::create([
            'name' => $nama,
            'no_telp' => '0812345678'.str_pad((string) $urut, 2, '0', STR_PAD_LEFT),
            'address' => 'Jl. Uji',
            'marketing_opt_in' => true,
            'loyalty_points' => 120,
            'loyalty_tier' => 'gold',
        ]);
    }

    /**
     * Pesan hasil render untuk satu pelanggan.
     *
     * Sengaja lewat `sample()`, bukan memanggil `renderMessage` langsung:
     * `sample()` adalah jalur publik yang sama dengan yang dipakai halaman
     * pratinjau, jadi test ini ikut menguji apa yang benar-benar dilihat
     * operator sebelum mengirim.
     */
    protected function render(string $pesan, string $voucherMode = CrmVoucherService::MODE_NONE): string
    {
        $this->customer();

        $campaign = CrmCampaign::create([
            'name' => 'Uji Placeholder',
            'segment_id' => $this->segment()->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => $pesan,
            'voucher_mode' => $voucherMode,
            'voucher_name' => 'Diskon 10rb',
            'voucher_type' => 'fixed',
            'voucher_value' => 10000,
            'voucher_prefix' => 'PROMO',
            'shared_voucher_code' => 'PROMO2026',
            'is_dry_run' => true,
        ]);

        $contoh = app(CrmCampaignService::class)->sample($campaign, 5);

        $this->assertNotEmpty($contoh, 'Pratinjau tidak menghasilkan apa pun.');

        return (string) $contoh[0]['message'];
    }

    public function test_kode_voucher_menjadi_kode_sungguhan(): void
    {
        $hasil = $this->render(
            'Halo {nama}, pakai kode {kode_voucher}',
            CrmVoucherService::MODE_SHARED
        );

        $this->assertSame('Halo Budi, pakai kode PROMO2026', $hasil);
    }

    public function test_nama_voucher_terisi(): void
    {
        $hasil = $this->render('Voucher Anda: {nama_voucher}', CrmVoucherService::MODE_SHARED);

        $this->assertSame('Voucher Anda: Diskon 10rb', $hasil);
    }

    public function test_placeholder_bahasa_indonesia_dan_inggris_hasilnya_sama(): void
    {
        $indonesia = $this->render('{nama} punya {poin} poin', CrmVoucherService::MODE_SHARED);
        $inggris = $this->render('{name} punya {points} poin', CrmVoucherService::MODE_SHARED);

        $this->assertSame($inggris, $indonesia,
            'Kedua penulisan harus menghasilkan teks yang sama persis.');
    }

    public function test_semua_placeholder_yang_dijanjikan_terisi(): void
    {
        foreach (CrmCampaignService::placeholders() as $token => $expectedKey) {
            $hasil = $this->render('['.$token.']', CrmVoucherService::MODE_SHARED);

            $this->assertStringNotContainsString($token, $hasil,
                "Placeholder {$token} tidak terisi sama sekali.");
            $this->assertNotSame('[]', $hasil,
                "Placeholder {$token} berubah jadi kosong.");
        }
    }

    public function test_placeholder_tidak_dikenal_terdeteksi(): void
    {
        $takDikenal = CrmCampaignService::unknownPlaceholders(
            'Halo {nama}, kode {kode_voucher}, salah ketik {namaa} dan {promo}'
        );

        $this->assertContains('{namaa}', $takDikenal);
        $this->assertContains('{promo}', $takDikenal);
        $this->assertNotContains('{nama}', $takDikenal);
        $this->assertNotContains('{kode_voucher}', $takDikenal);
    }

    public function test_placeholder_kosong_tidak_dianggap_tak_dikenal(): void
    {
        $this->assertSame([], CrmCampaignService::unknownPlaceholders('Halo {nama}, apa kabar?'),
            'Kurung kurawal yang bukan placeholder tidak boleh dianggap error.');
    }

    /**
     * @dataProvider placeholderVoucher
     */
    public function test_placeholder_voucher_terdaftar(string $token): void
    {
        $this->assertContains($token, CrmCampaignService::voucherPlaceholders(),
            "{$token} butuh voucher dan harus ikut daftar ini.");
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function placeholderVoucher(): array
    {
        return [
            ['{kode_voucher}'],
            ['{voucher_code}'],
            ['{nama_voucher}'],
            ['{voucher_name}'],
        ];
    }

    public function test_pesan_dengan_placeholder_salah_ditolak_saat_disimpan(): void
    {
        $this->actingAs($this->admin)
            ->post(route('crm.campaigns.store'), [
                'name' => 'Typo',
                'segment_id' => $this->segment()->id,
                'type' => 'manual',
                'channel' => 'whatsapp',
                'message' => 'Halo {nama}, kode {kodea}',
                'is_dry_run' => true,
            ])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, CrmCampaign::count(),
            'Campaign dengan placeholder salah ketik tidak boleh tersimpan.');
    }

    public function test_placeholder_voucher_tanpa_mode_voucher_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->post(route('crm.campaigns.store'), [
                'name' => 'Voucher Kosong',
                'segment_id' => $this->segment()->id,
                'type' => 'manual',
                'channel' => 'whatsapp',
                'message' => 'Halo {nama}, kode Anda: {kode_voucher}',
                'voucher_mode' => 'none',
                'is_dry_run' => true,
            ])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, CrmCampaign::count());
    }

    public function test_pesan_lengkap_dengan_voucher_diterima(): void
    {
        $this->actingAs($this->admin)
            ->post(route('crm.campaigns.store'), [
                'name' => 'Promo Lengkap',
                'segment_id' => $this->segment()->id,
                'type' => 'manual',
                'channel' => 'whatsapp',
                'message' => 'Halo {nama}, tier {tier}, {poin} poin. Kode: {kode_voucher}',
                'voucher_mode' => 'shared',
                'voucher_name' => 'Diskon 10rb',
                'voucher_type' => 'fixed',
                'voucher_value' => 10000,
                'shared_voucher_code' => 'PROMO2026',
                'is_dry_run' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CrmCampaign::count());
    }

    public function test_pesan_tanpa_placeholder_apa_pun_selalu_diterima(): void
    {
        $this->actingAs($this->admin)
            ->post(route('crm.campaigns.store'), [
                'name' => 'Pesan Biasa',
                'segment_id' => $this->segment()->id,
                'type' => 'manual',
                'channel' => 'copy',
                'message' => 'Halo, ada promo spesial untuk Anda. { 100% diskon! }',
                'is_dry_run' => true,
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * `voucher_prefix` dan `voucher_name` nullable, jadi tidak mengirimkannya
     * itu request yang sah.
     *
     * Sebelumnya `normalizeCampaignVoucher` membaca keduanya tanpa
     * penjaga, jadi request seperti ini membalas HTTP 500 --
     * "Undefined array key". Formulir di UI kebetulan selalu mengirim
     * keduanya, jadi bug ini tidak pernah terlihat dari layar, tapi
     * request dari luar akan gagal.
     */
    public function test_voucher_tanpa_prefix_dan_nama_tetap_bisa_disimpan(): void
    {
        $this->actingAs($this->admin)
            ->post(route('crm.campaigns.store'), [
                'name' => 'Voucher Tanpa Prefix',
                'segment_id' => $this->segment()->id,
                'type' => 'manual',
                'channel' => 'copy',
                'message' => 'Halo {nama}, kode {kode_voucher}',
                'voucher_mode' => 'shared',
                'voucher_type' => 'fixed',
                'voucher_value' => 10000,
                'shared_voucher_code' => 'PROMO2026',
                'is_dry_run' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CrmCampaign::count());

        $campaign = CrmCampaign::first();
        $this->assertSame('PROMO', $campaign->voucher_prefix,
            'Awalan kode harus punya nilai cadangan, bukan null.');
        $this->assertSame('Voucher Voucher Tanpa Prefix', $campaign->voucher_name,
            'Nama voucher harus dibuat dari nama campaign kalau tidak diisi.');
    }
}
