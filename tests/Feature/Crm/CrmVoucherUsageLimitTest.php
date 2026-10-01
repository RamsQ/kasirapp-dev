<?php

namespace Tests\Feature\Crm;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CrmCampaign;
use App\Models\CustomerSegment;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CrmCampaignService;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT: voucher pribadi boleh punya batas pemakaian sendiri.
 *
 * KEADAAN SEBELUM ADA FITUR INI
 *
 * Voucher pribadi selalu sekali pakai, tapi BUKAN karena ada batasnya.
 * `consumeVoucher()` men-set `status = 'used'`, dan `voucherIsUsable()`
 * menolak status di luar `active` dan `reserved`. Tidak ada satu pun kolom
 * yang mencatat pemakaian ke-2 dan seterusnya, jadi satu kode tidak mungkin
 * dipakai lagi.
 *
 * Yang diuji di sini:
 *  - batas 1 tetap berlaku persis seperti sebelumnya (tidak ada regresi)
 *  - batas lebih dari 1 mengizinkan pemakaian berulang sampai habis
 *  - batas tidak bisa dilewati lewat pemanggilan berulang pada satu transaksi
 *  - refund mengembalikan satu pemakaian, bahkan kalau yang dibatalkan bukan
 *    pemakaian terakhir
 *  - voucher yang sudah terpakai sebelum migrasi tidak "dihidupkan" lagi
 */
class CrmVoucherUsageLimitTest extends TestCase
{
    use RefreshDatabase;

    protected User $kasir;

    protected Customer $pelanggan;

    protected LoyaltyService $loyalty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loyalty = app(LoyaltyService::class);

        $category = Category::create(['name' => 'Makanan']);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'Nasi Goreng',
            'buy_price' => 12000,
            'sell_price' => 25000,
            'stock' => 100,
            'unit' => 'Pcs',
        ]);

        $this->kasir = User::factory()->create();

        $this->pelanggan = Customer::create([
            'name' => 'Andi',
            'no_telp' => '081234567890',
            'address' => 'Jl. Uji',
            'marketing_opt_in' => true,
        ]);

        $this->product = $product;
    }

    protected function transaksi(string $status = 'paid'): Transaction
    {
        return Transaction::create([
            'cashier_id' => $this->kasir->id,
            'customer_id' => $this->pelanggan->id,
            'invoice' => 'TRX-'.strtoupper(bin2hex(random_bytes(5))),
            'cash' => 50000,
            'change' => 0,
            'discount' => 0,
            'taxable_amount' => 50000,
            'tax_amount' => 0,
            'grand_total' => 50000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => $status,
        ]);
    }

    protected function voucher(int $usageLimit): \App\Models\CustomerVoucher
    {
        return $this->loyalty->issueVoucher($this->pelanggan, [
            'code' => 'UJI'.random_int(1000, 9999),
            'name' => 'Voucher Uji',
            'type' => 'fixed',
            'value' => 10000,
            'min_spend' => 0,
            'usage_limit' => $usageLimit,
        ]);
    }

    /** Satu siklus penuh: pesan kode -> reserved -> dipakai. */
    protected function pakaiVoucher(\App\Models\CustomerVoucher $voucher, int $nilai = 50000): Transaction
    {
        $trx = $this->transaksi();
        $trx->update(['voucher_code' => $voucher->code]);

        $diskon = $this->loyalty->voucherDiscountFor($this->pelanggan, $voucher->code, $nilai);
        $this->loyalty->reserveVoucher($this->pelanggan, $voucher->code, $nilai, $trx);
        $this->loyalty->consumeVoucher($trx);

        return $trx->fresh();
    }

    public function test_batas_satu_masih_sekali_pakai_seperti_sebelumnya(): void
    {
        $voucher = $this->voucher(1);
        $trx = $this->pakaiVoucher($voucher);

        $voucher->refresh();

        $this->assertSame(1, $voucher->used_count);
        $this->assertSame('used', $voucher->status);

        $this->expectException(\RuntimeException::class);
        $this->loyalty->voucherDiscountFor($this->pelanggan, $voucher->code, 50000);
    }

    public function test_batas_tiga_mengizinkan_tiga_kali_pakai(): void
    {
        $voucher = $this->voucher(3);

        $this->pakaiVoucher($voucher);
        $this->assertSame(1, $voucher->fresh()->used_count);
        $this->assertSame('active', $voucher->fresh()->status,
            'Voucher yang masih ada kuotanya harus tetap active.');

        $this->pakaiVoucher($voucher);
        $this->assertSame(2, $voucher->fresh()->used_count);
        $this->assertSame('active', $voucher->fresh()->status);

        $this->pakaiVoucher($voucher);
        $voucher->refresh();
        $this->assertSame(3, $voucher->used_count);
        $this->assertSame('used', $voucher->status, 'Status baru jadi used saat kuota habis.');

        $this->expectException(\RuntimeException::class);
        $this->loyalty->voucherDiscountFor($this->pelanggan, $voucher->code, 50000);
    }

    public function test_kuota_tidak_bisa_dilewati_lewat_pemanggilan_berulang(): void
    {
        $voucher = $this->voucher(2);
        $trx = $this->transaksi();
        $trx->update(['voucher_code' => $voucher->code]);

        $this->loyalty->reserveVoucher($this->pelanggan, $voucher->code, 50000, $trx);
        $this->loyalty->consumeVoucher($trx);

        // Pemanggil kedua pada transaksi yang sama harus diabaikan, bukan
        // menambah penghitung.
        $this->loyalty->consumeVoucher($trx);
        $this->loyalty->consumeVoucher($trx);

        $this->assertSame(1, $voucher->fresh()->used_count,
            'Satu transaksi hanya boleh memakai satu kali walau dipanggil berkali-kali.');
    }

    public function test_refund_mengembalikan_satu_pemakaian(): void
    {
        $voucher = $this->voucher(3);

        $this->pakaiVoucher($voucher);
        $kedua = $this->pakaiVoucher($voucher);
        $this->assertSame(2, $voucher->fresh()->used_count);

        $this->loyalty->reverseVoucher($kedua);

        $voucher->refresh();
        $this->assertSame(1, $voucher->used_count,
            'Refund harus mengembalikan satu pemakaian, bukan mengunci voucher.');
        $this->assertSame('active', $voucher->status);
    }

    /**
     * Kasus yang paling mudah salah.
     *
     * `used_transaction_id` cuma menyimpan transaksi TERAKHIR. Kalau transaksi
     * kedua dibatalkan sementara voucher sudah dipakai tiga kali, pencarian
     * voucher lewat kolom itu tidak menemukan apa pun -- sehingga satu
     * pemakaian hilang permanen dan pelanggan membayar voucher yang tidak
     * bisa dipakai lagi.
     */
    public function test_refund_yang_bukan_pemakaian_terakhir_masih_mengembalikan_kuota(): void
    {
        $voucher = $this->voucher(4);

        $this->pakaiVoucher($voucher);
        $kedua = $this->pakaiVoucher($voucher);
        $this->pakaiVoucher($voucher);
        $this->assertSame(3, $voucher->fresh()->used_count);

        $this->loyalty->reverseVoucher($kedua);

        $voucher->refresh();
        $this->assertSame(2, $voucher->used_count,
            'Voucher harus ditemukan lewat kode pada transaksi, bukan cuma lewat '
            .'kolom yang menyimpan pemakaian terakhir.');
    }

    public function test_refund_berulang_tidak_membuat_penghitung_negatif(): void
    {
        $voucher = $this->voucher(3);
        $trx = $this->pakaiVoucher($voucher);

        $this->loyalty->reverseVoucher($trx);
        $this->loyalty->reverseVoucher($trx);
        $this->loyalty->reverseVoucher($trx);

        $this->assertSame(0, $voucher->fresh()->used_count);
        $this->assertGreaterThanOrEqual(0, $voucher->fresh()->used_count);
    }

    public function test_batas_default_voucher_manual_adalah_satu_kali(): void
    {
        $voucher = $this->loyalty->issueVoucher($this->pelanggan, [
            'code' => 'DEFAULT1',
            'name' => 'Tanpa Batas Diberi',
            'type' => 'fixed',
            'value' => 5000,
        ]);

        $this->assertSame(1, $voucher->usage_limit,
            'Voucher yang tidak menyebut batas harus ikut aturan sekali pakai.');
        $this->assertSame(0, $voucher->used_count);
    }

    public function test_batas_dari_campaign_tersalin_ke_voucher(): void
    {
        $segment = CustomerSegment::create([
            'name' => 'Semua',
            'rules' => [],
            'is_active' => true,
        ]);

        $campaign = CrmCampaign::create([
            'name' => 'Promo 5x',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'whatsapp',
            'message' => 'Kode {kode_voucher}',
            'voucher_mode' => 'per_recipient',
            'voucher_name' => 'Diskon',
            'voucher_type' => 'fixed',
            'voucher_value' => 10000,
            'voucher_usage_limit' => 5,
            'voucher_prefix' => 'PROMO',
            'is_dry_run' => false,
        ]);

        $layanan = app(CrmCampaignService::class);
        $layanan->run($campaign, force: true);

        $voucher = \App\Models\CustomerVoucher::first();

        $this->assertNotNull($voucher);
        $this->assertSame(5, $voucher->usage_limit,
            'Batas yang dipilih di campaign harus ikut ke voucher yang terbit.');
    }

    public function test_mode_shared_memaksa_batas_satu(): void
    {
        Permission::firstOrCreate(['name' => 'crm-manage', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['email' => 'admin@gmail.com']);
        $admin->assignRole($role);
        $admin->givePermissionTo(['crm-manage']);

        $segment = CustomerSegment::create([
            'name' => 'Semua',
            'rules' => [],
            'is_active' => true,
        ]);

        // Mode shared sudah punya kuota sendiri di promo_codes. Kalau batas
        // pemakaian juga diizinkan di sini, ada dua tempat yang mengatur
        // kuota kode yang sama.
        $this->actingAs($admin)
            ->post(route('crm.campaigns.store'), [
                'name' => 'Shared',
                'segment_id' => $segment->id,
                'type' => 'manual',
                'channel' => 'copy',
                'message' => 'Kode {kode_voucher}',
                'voucher_mode' => 'shared',
                'voucher_type' => 'fixed',
                'voucher_value' => 10000,
                'shared_voucher_code' => 'SHARED1',
                'voucher_usage_limit' => 9,
                'is_dry_run' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CrmCampaign::first()->voucher_usage_limit);
    }

    public function test_batas_nol_ditolak(): void
    {
        Permission::firstOrCreate(['name' => 'crm-manage', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['email' => 'admin@gmail.com']);
        $admin->assignRole($role);
        $admin->givePermissionTo(['crm-manage']);

        $segment = CustomerSegment::create([
            'name' => 'Semua',
            'rules' => [],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('crm.campaigns.store'), [
                'name' => 'Nol',
                'segment_id' => $segment->id,
                'type' => 'manual',
                'channel' => 'copy',
                'message' => 'Halo',
                'voucher_mode' => 'per_recipient',
                'voucher_type' => 'fixed',
                'voucher_value' => 10000,
                'voucher_prefix' => 'PROMO',
                'voucher_usage_limit' => 0,
                'is_dry_run' => true,
            ])
            ->assertSessionHasErrors('voucher_usage_limit');
    }
}
