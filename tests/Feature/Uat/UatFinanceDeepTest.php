<?php

namespace Tests\Feature\Uat;

use App\Models\Cart;
use App\Models\Category;
use App\Models\OnlineSetting;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialReportService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Keuangan: jalur HPP dan laba yang belum pernah diuji sebelumnya.
 *
 * Fokusnya bukan "apakah halaman buka", tapi apakah angkanya benar untuk
 * kombinasi yang jarang dipakai: multi-satuan, platform online, pajak, diskon,
 * dan bundel yang komponennya pakai satuan lain.
 */
class UatFinanceDeepTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $this->admin->givePermissionTo('transactions-access');

        Shift::create([
            'user_id' => $this->admin->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        Setting::create([
            'cogs_method' => 'AVERAGE',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => false,
            'kitchen_display_enabled' => false,
        ]);
    }

    protected function category(): Category
    {
        return Category::create(['name' => 'FIN-'.uniqid()]);
    }

    protected function stockIn($model, string $type, float $qty, float $price): void
    {
        $this->actingAs($this->admin)->post(route('stock_in.store'), [
            'entries' => [['id' => $model->id, 'type' => $type, 'qty_in' => $qty, 'buy_price' => $price]],
        ])->assertRedirect();
    }

    protected function report(): array
    {
        return app(FinancialReportService::class)->summary(
            Transaction::whereDate('created_at', now())
        );
    }

    // ============================================================
    // 1. MULTI-SATUAN: HPP harus mengikuti konversi
    // ============================================================

    public function test_multi_unit_hpp_scales_with_conversion(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Susu UHT',
            'buy_price' => 0,
            'sell_price' => 6000,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);

        // 1 Box = 12 Pcs, harga jual box Rp 72.000
        $box = ProductUnit::create([
            'product_id' => $product->id,
            'unit_name' => 'Box',
            'conversion' => 12,
            'sell_price' => 72000,
        ]);

        // Modal per Pcs Rp 5.000
        $this->stockIn($product, 'products', 60, 5000);

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id,
            'product_unit_id' => $box->id,
            'qty' => 2,
            'price' => 144000,
        ]);

        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash',
            'cash' => 144000,
            'change' => 0,
        ])->assertOk();

        $summary = $this->report();

        // 2 Box x 12 Pcs = 24 Pcs x 5.000 = Rp 120.000
        $this->assertSame(120000, (int) $summary['hpp'],
            'HPP multi-satuan harus dikali conversion (2 x 12 x 5.000).');
        $this->assertSame(144000, (int) $summary['net_revenue']);
        $this->assertSame(24000, (int) $summary['net_profit'], 'Laba = 144.000 - 120.000.');
    }

    public function test_multi_unit_detail_records_unit_and_hpp_per_sale_unit(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Kopi Kemasan',
            'buy_price' => 0, 'sell_price' => 10000, 'stock' => 0, 'unit' => 'Pcs',
        ]);
        $box = ProductUnit::create([
            'product_id' => $product->id, 'unit_name' => 'Box',
            'conversion' => 10, 'sell_price' => 90000,
        ]);

        $this->stockIn($product, 'products', 40, 7000);

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id,
            'product_unit_id' => $box->id,
            'qty' => 1,
            'price' => 90000,
        ]);

        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 90000, 'change' => 0,
        ])->assertOk();

        $transaction = Transaction::latest('id')->firstOrFail();
        $detail = $transaction->details()->firstOrFail();

        $this->assertSame('Box', $detail->unit, 'Detail harus mencatat satuan yang dijual.');
        $this->assertNotNull($detail->product_unit_id);
        // 10 Pcs x 7.000 = 70.000 HPP untuk 1 Box.
        $this->assertEqualsWithDelta(70000.0, (float) $detail->buy_price * (float) $detail->qty, 0.01);
    }

    public function test_refund_of_multi_unit_sale_returns_the_right_stock(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Teh Kotak',
            'buy_price' => 0, 'sell_price' => 5000, 'stock' => 0, 'unit' => 'Pcs',
        ]);
        $box = ProductUnit::create([
            'product_id' => $product->id, 'unit_name' => 'Box',
            'conversion' => 12, 'sell_price' => 55000,
        ]);

        $this->stockIn($product, 'products', 60, 4000);

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id,
            'product_unit_id' => $box->id,
            'qty' => 3, 'price' => 165000,
        ]);

        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 165000, 'change' => 0,
        ])->assertOk();

        $transaction = Transaction::latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(24.0, (float) $product->fresh()->stock, 0.001, '3 Box = 36 Pcs.');

        $this->actingAs($this->admin)
            ->from(route('transactions.index'))
            ->post(route('transactions.refund', $transaction->id), ['password' => 'password'])
            ->assertRedirect();

        $this->assertEqualsWithDelta(60.0, (float) $product->fresh()->stock, 0.001,
            'Refund harus mengembalikan 36 Pcs, bukan 3.');
        $this->assertSame(0, (int) $this->report()['hpp'], 'HPP laporan harus nol setelah refund.');
    }

    // ============================================================
    // 2. PLATFORM ONLINE: markup dan fee
    // ============================================================

    public function test_online_markup_and_fee_are_stored_and_subtracted_once(): void
    {
        $platform = OnlineSetting::create([
            'name' => 'GoFood',
            'markup_percent' => 10,
            'additional_fee' => 2000,
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Nasi Padang',
            'buy_price' => 0, 'sell_price' => 30000, 'stock' => 0, 'unit' => 'Pcs',
        ]);

        $this->stockIn($product, 'products', 20, 15000);

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id,
            'online_platform_id' => $platform->id,
            'platform_markup' => 3000,
            'platform_fee' => 2000,
            'qty' => 1,
            'price' => 35000,
        ]);

        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 35000, 'change' => 0,
        ])->assertOk();

        $transaction = Transaction::latest('id')->firstOrFail();
        $summary = $this->report();

        // Markup dan fee harus TERSIMPAN di transaksi, kalau tidak komisi
        // platform tidak pernah dipotong dari laporan.
        $this->assertSame(3000, (int) $transaction->total_markup,
            'Markup harus tersimpan, kalau tidak komisi platform hilang dari laporan.');
        $this->assertSame(2000, (int) $transaction->total_fee);

        $this->assertSame(35000, (int) $summary['net_revenue'],
            'Pendapatan harus harga yang dibayar pelanggan (termasuk markup+fee).');
        $this->assertSame(15000, (int) $summary['hpp']);
        $this->assertSame(5000, (int) $summary['app_expenses'],
            'Komisi platform = markup 3.000 + fee 2.000.');
        $this->assertSame(20000, (int) $summary['gross_profit_before_platform']);
        $this->assertSame(15000, (int) $summary['net_profit'],
            'Laba = 35.000 - 15.000 - 5.000. Markup tidak boleh dipotong dua kali.');
    }

    public function test_client_cannot_invent_its_own_platform_fee(): void
    {
        $platform = OnlineSetting::create([
            'name' => 'GrabFood',
            'markup_percent' => 10,
            'additional_fee' => 2000,
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Mie Bakso',
            'buy_price' => 0, 'sell_price' => 25000, 'stock' => 0, 'unit' => 'Pcs',
        ]);
        $this->stockIn($product, 'products', 20, 10000);

        // Klien mencoba mengarang fee, markup, dan harga di payload.
        $this->actingAs($this->admin)
            ->post(route('transactions.addToCart'), [
                'product_id' => $product->id,
                'online_platform_id' => $platform->id,
                'platform_markup' => 999999,
                'platform_fee' => 999999,
                'price' => 1,
            ])
            ->assertRedirect();

        $cart = Cart::where('cashier_id', $this->admin->id)->firstOrFail();

        $this->assertSame(2500.0, (float) $cart->platform_markup,
            'Markup harus dihitung server (10% dari 25.000), bukan dari kiriman klien.');
        $this->assertSame(2000.0, (float) $cart->platform_fee);
        $this->assertSame(29500.0, (float) $cart->price,
            'Harga harus dihitung server: 25.000 + 2.500 + 2.000.');

        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 40000, 'change' => 0,
        ])->assertOk();

        $transaction = Transaction::latest('id')->firstOrFail();
        $summary = $this->report();

        $this->assertSame(2500, (int) $transaction->total_markup);
        $this->assertSame(2000, (int) $transaction->total_fee);
        $this->assertSame(29500, (int) $summary['net_revenue']);
        $this->assertSame(4500, (int) $summary['app_expenses']);
    }

    public function test_deactivating_a_platform_stops_its_commission_at_checkout(): void
    {
        $platform = OnlineSetting::create([
            'name' => 'Platform Dinonaktifkan',
            'markup_percent' => 20,
            'additional_fee' => 3000,
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Roti Bakar',
            'buy_price' => 0, 'sell_price' => 20000, 'stock' => 0, 'unit' => 'Pcs',
        ]);
        $this->stockIn($product, 'products', 10, 8000);

        // Item masuk keranjang saat platform masih aktif.
        $this->actingAs($this->admin)
            ->post(route('transactions.addToCart'), [
                'product_id' => $product->id,
                'online_platform_id' => $platform->id,
            ])
            ->assertRedirect();

        $cart = Cart::where('cashier_id', $this->admin->id)->firstOrFail();
        $this->assertSame(4000.0, (float) $cart->platform_markup, 'Aktif: markup 20%.');

        // Pemilik mematikan platform sebelum checkout.
        $platform->update(['is_active' => false]);

        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 50000, 'change' => 0,
        ])->assertOk();

        $transaction = Transaction::latest('id')->firstOrFail();
        $summary = $this->report();

        // Kalau komisi tetap 4.000, berarti platform yang sudah dimatikan
        // masih memotong laba.
        $this->assertSame(0, (int) $transaction->total_markup,
            'Platform yang sudah dimatikan tidak boleh tetap menambah komisi.');
        $this->assertSame(0, (int) $transaction->total_fee);
        $this->assertSame(20000, (int) $summary['net_revenue'],
            'Harga kembali ke harga normal toko, bukan harga platform.');
    }

    // ============================================================
    // 3. PAJAK: tidak boleh memengaruhi HPP
    // ============================================================

    public function test_tax_does_not_change_hpp(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Es Jeruk',
            'buy_price' => 0, 'sell_price' => 20000, 'stock' => 0, 'unit' => 'Pcs',
        ]);
        $this->stockIn($product, 'products', 60, 9000);

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id, 'qty' => 2, 'price' => 40000,
        ]);
        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 40000, 'change' => 0,
        ])->assertOk();

        $withoutTax = $this->report();

        // Nyalakan pajak 10%, lalu buat transaksi PEMBATASAN kedua.
        Setting::first()->update(['tax_enabled' => true, 'tax_rate' => 10]);

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id, 'qty' => 2, 'price' => 40000,
        ]);
        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 44000, 'change' => 0,
        ])->assertOk();

        $taxed = Transaction::latest('id')->firstOrFail();
        // 2 gelas x Rp 20.000 = Rp 40.000 dasar pajak, 10% = Rp 4.000.
        $this->assertSame(4000, (int) $taxed->tax_amount, 'Pajak 10% dari 40.000.');
        $this->assertSame(44000, (int) $taxed->grand_total, 'Total tagihan naik pajak.');

        $withTax = $this->report();

        // HPP transaksi kedua harus sama dengan yang pertama.
        $hppSecondSale = (int) $withTax['hpp'] - (int) $withoutTax['hpp'];
        $this->assertSame((int) $withoutTax['hpp'], $hppSecondSale,
            'Pajak adalah kewajiban setoran, tidak boleh mengubah HPP per penjualan.');
        $this->assertSame(80000, (int) $withTax['net_revenue'],
            'Omzet bersih kumulatif tetap 2 x 40.000 (tanpa pajak).');
        $this->assertSame(84000, (int) $withTax['billed_revenue'],
            'Total tagihan = 40.000 + 44.000, jadi naik sebesar pajak.');
        $this->assertSame(4000, (int) $withTax['tax_collected']);
    }

    public function test_tax_collected_appears_as_tax_payable_on_the_balance_sheet(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Paket GST',
            'buy_price' => 0, 'sell_price' => 100000, 'stock' => 0, 'unit' => 'Pcs',
        ]);
        $this->stockIn($product, 'products', 5, 40000);
        Setting::first()->update(['tax_enabled' => true, 'tax_rate' => 11]);

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id, 'qty' => 1, 'price' => 100000,
        ]);
        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 111000, 'change' => 0,
        ])->assertOk();

        $this->actingAs($this->admin)
            ->get(route('report.finance', [
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('report.balanceSheet.tax_payable', 11000,
                    'Pajak yang terkumpul adalah utang ke negara, harus muncul di neraca.')
                ->where('report.balanceSheet.total_liabilities', 11000)
            );
    }

    // ============================================================
    // 4. DISKON: tidak boleh memengaruhi HPP
    // ============================================================

    public function test_discount_reduces_revenue_but_never_hpp(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Ayam Bakar',
            'buy_price' => 0, 'sell_price' => 40000, 'stock' => 0, 'unit' => 'Pcs',
        ]);
        $this->stockIn($product, 'products', 10, 25000);

        \App\Models\Discount::create([
            'name' => 'Diskon 25%',
            'type' => 'percentage',
            'value' => 25,
            'min_transaction' => 0,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id, 'qty' => 2, 'price' => 80000,
        ]);
        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 60000, 'change' => 0,
        ])->assertOk();

        $summary = $this->report();

        $this->assertSame(60000, (int) $summary['net_revenue'], 'Omzet setelah diskon 25%.');
        $this->assertSame(50000, (int) $summary['hpp'],
            'HPP tetap 2 x 25.000. Diskon adalah pengurang pendapatan, bukan modal.');
        $this->assertSame(10000, (int) $summary['net_profit']);
    }

    public function test_discount_larger_than_subtotal_does_not_produce_negative_hpp(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Item Murah',
            'buy_price' => 0, 'sell_price' => 10000, 'stock' => 0, 'unit' => 'Pcs',
        ]);
        $this->stockIn($product, 'products', 10, 7000);

        // Potong 50.000 dari transaksi 10.000 (lebih besar dari subtotal).
        \App\Models\Discount::create([
            'name' => 'Potong Rp 50.000',
            'type' => 'fixed',
            'value' => 50000,
            'min_transaction' => 0,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id, 'qty' => 1, 'price' => 10000,
        ]);

        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 0, 'change' => 0,
        ])->assertOk();

        $summary = $this->report();

        $this->assertSame(0, (int) $summary['net_revenue'],
            'Diskon tidak boleh membuat omzet negatif.');
        $this->assertSame(7000, (int) $summary['hpp'],
            'HPP tetap positif meski pelanggan bayar nol.');
        $this->assertSame(-7000, (int) $summary['net_profit'],
            'Laba negatif itu benar: barang sudah keluar tapi tidak dibayar.');
    }

    // ============================================================
    // 5. BUNDEL DENGAN KONVERSI SATUAN DI DALAMNYA
    // ============================================================

    public function test_bundle_component_with_unit_conversion_costs_correctly(): void
    {
        $milk = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Susu Bundle',
            'buy_price' => 0, 'sell_price' => 6000, 'stock' => 0, 'unit' => 'Pcs',
        ]);
        $box = ProductUnit::create([
            'product_id' => $milk->id, 'unit_name' => 'Box',
            'conversion' => 12, 'sell_price' => 66000,
        ]);
        $this->stockIn($milk, 'products', 60, 5000);

        $nasi = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Nasi Bundle',
            'buy_price' => 0, 'sell_price' => 15000, 'stock' => 0, 'unit' => 'Pcs',
        ]);
        $this->stockIn($nasi, 'products', 30, 12000);

        $bundle = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Paket Sarapan',
            'type' => 'bundle',
            'buy_price' => 0, 'sell_price' => 75000,
            'stock' => 0, 'unit' => 'Paket',
        ]);

        // Komponen susu diambil per Box (1 Box per paket).
        $bundle->bundle_items()->attach([
            ['item_id' => $milk->id, 'qty' => 1, 'product_unit_id' => $box->id],
            ['item_id' => $nasi->id, 'qty' => 1, 'product_unit_id' => null],
        ]);

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $bundle->id, 'qty' => 2, 'price' => 150000,
        ]);

        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 150000, 'change' => 0,
        ])->assertOk();

        $summary = $this->report();

        // 2 paket x (1 Box susu = 12 Pcs x 5.000 + 1 nasi x 12.000)
        // = 2 x (60.000 + 12.000) = Rp 144.000
        $this->assertSame(144000, (int) $summary['hpp'],
            'Bundel harus memakai konversi satuan komponennya.');
        // 2 paket x 1 Box susu (12 Pcs) = 24 Pcs, dari stok 60 tersisa 36.
        $this->assertEqualsWithDelta(36.0, (float) $milk->fresh()->stock, 0.001, '2 Box = 24 Pcs.');
        $this->assertEqualsWithDelta(28.0, (float) $nasi->fresh()->stock, 0.001, '2 nasi dari 30.');
    }

    // ============================================================
    // 6. HPP TIDAK BOLEH BERUBAH OLEH TRANSAKSI LAIN
    // ============================================================

    public function test_historical_hpp_is_frozen_when_new_stock_arrives(): void
    {
        // Pakai FIFO supaya Layer yang dipakai mudah diprediksi.
        Setting::first()->update(['cogs_method' => 'FIFO']);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Kopi Historis',
            'buy_price' => 0, 'sell_price' => 30000, 'stock' => 0, 'unit' => 'Pcs',
        ]);

        // Batch pertama murah.
        $this->stockIn($product, 'products', 10, 10000);
        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id, 'qty' => 5, 'price' => 150000,
        ]);
        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 150000, 'change' => 0,
        ])->assertOk();

        $this->assertSame(50000, (int) $this->report()['hpp'],
            '5 x 10.000 = 50.000 dari batch termurah.');

        // Datang dua batch mahal. HPP transaksi LAMA tidak boleh berubah.
        $this->stockIn($product, 'products', 10, 40000);
        $this->stockIn($product, 'products', 10, 40000);
        $this->assertSame(50000, (int) $this->report()['hpp'],
            'HPP transaksi yang sudah terjadi tidak boleh berubah karena stock in baru.');

        // Penjualan berikutnya: FIFO memakai sisa batch murah dulu (5 left).
        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id, 'qty' => 5, 'price' => 150000,
        ]);
        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 150000, 'change' => 0,
        ])->assertOk();

        $summary = $this->report();
        // FIFO: sisa 5 unit masih @ 10.000 = 50.000. Total 50.000 + 50.000.
        $this->assertSame(100000, (int) $summary['hpp'],
            'Batch 10.000 masih tersisa jadi FIFO memakainya lagi, bukan yang 40.000.');
    }
}
