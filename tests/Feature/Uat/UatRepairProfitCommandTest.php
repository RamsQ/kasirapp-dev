<?php

namespace Tests\Feature\Uat;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\StockBatchAllocation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT repair:profit: perintah pemulihan HPP harus memakai sumber yang paling
 * bisa dipercaya dan tidak diam-diam mengarang angka.
 *
 * Versi lama memakai products.cost_price sebagai sumber pertama. Field itu
 * hanya perkiraan tampilan hasil tombol "Hitung HPP" dan bisa membeku di harga
 * bahan yang sudah lama, sehingga HPP yang dipulihkan meleset jauh.
 */
class UatRepairProfitCommandTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['email' => 'admin@gmail.com']);
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));

        Shift::create([
            'user_id' => $this->admin->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        Setting::create([
            'cogs_method' => 'FIFO',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => false,
            'kitchen_display_enabled' => false,
        ]);
    }

    protected function category(): Category
    {
        return Category::create(['name' => 'RP-'.uniqid()]);
    }

    protected function stockIn($model, string $type, float $qty, float $price): void
    {
        app(InventoryStockService::class)->recordStockIn($type, $model->id, $qty, $price, $this->admin->id);
    }

    /** Buat transaksi sungguhan (dengan ledger batch), lalu kosongkan HPP-nya. */
    protected function realSale(Product $product, int $qty, int $cash): Transaction
    {
        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id,
            'qty' => $qty,
            'price' => $product->sell_price * $qty,
        ]);

        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => $cash, 'change' => 0,
        ])->assertOk();

        $transaction = Transaction::latest('id')->firstOrFail();

        // simulating the "legacy" state: HPP kosong tapi ledger batch ada.
        $transaction->details()->update(['buy_price' => 0]);
        $transaction->profits()->delete();

        return $transaction;
    }

    // ============================================================

    public function test_uses_batch_ledger_instead_of_stale_cost_price(): void
    {
        $flour = Ingredient::create([
            'name' => 'Tepung Repair', 'buy_price' => 0, 'stock' => 0, 'unit' => 'Kg',
        ]);

        $cake = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Kue Repair',
            'buy_price' => 0,
            'cost_price' => 999999,   // perkiraan basi yang sengaja dibuat ekstrem
            'sell_price' => 25000,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);
        Recipe::create(['product_id' => $cake->id, 'ingredient_id' => $flour->id, 'qty_needed' => 0.2]);

        // Batch lama murah, batch baru mahal. Rata-rata = 15.000/Kg.
        $this->stockIn($flour, 'ingredient', 10, 10000);
        $this->stockIn($flour, 'ingredient', 10, 20000);

        $transaction = $this->realSale($cake, 5, 125000);

        $this->assertGreaterThan(0, StockBatchAllocation::where('transaction_id', $transaction->id)->count(),
            'Skenario ini harus punya ledger batch.');

        $this->artisan('repair:profit')->assertSuccessful();

        $detail = $transaction->details()->firstOrFail();

        // FIFO: 5 kue x 0,2 Kg = 1 Kg dari batch 10.000 = 10.000.
        $this->assertEqualsWithDelta(10000.0, (float) $detail->buy_price * (float) $detail->qty, 0.01,
            'Harus pakai ledger batch (10.000), bukan cost_price basi (999.999).');

        $summary = app(FinancialReportService::class)->summary(Transaction::whereDate('created_at', now()));
        $this->assertEqualsWithDelta(10000.0, (float) $summary['hpp'], 0.01);
    }

    public function test_falls_back_to_estimate_when_ledger_is_missing(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Produk Legacy',
            'buy_price' => 12000,
            'sell_price' => 25000,
            'stock' => 20,
            'unit' => 'Pcs',
        ]);

        // Transaksi lama dibuat sebelum sistem batch ada: tidak ada ledger.
        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'invoice' => 'TRX-LEGACY-1',
            'customer_name' => 'Lama',
            'cash' => 25000, 'change' => 0, 'discount' => 0,
            'taxable_amount' => 25000, 'tax_amount' => 0,
            'grand_total' => 25000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id, 'qty' => 1, 'price' => 25000, 'buy_price' => 0,
        ]);

        $this->artisan('repair:profit')->assertSuccessful();

        $this->assertEqualsWithDelta(
            12000.0,
            (float) $transaction->details()->firstOrFail()->buy_price,
            0.01,
            'Tanpa ledger, HPP diestimasi dari buy_price produk (12.000).'
        );
    }

    public function test_estimates_recipe_hpp_from_ingredient_prices_not_stale_cost_price(): void
    {
        $sugar = Ingredient::create([
            'name' => 'Gula Repair', 'buy_price' => 0, 'stock' => 0, 'unit' => 'Kg',
        ]);
        $cake = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Kue Estimasi',
            'buy_price' => 0,
            // cost_price dibekukan saat bahan masih mahal.
            'cost_price' => 100000,
            'sell_price' => 20000,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);
        Recipe::create(['product_id' => $cake->id, 'ingredient_id' => $sugar->id, 'qty_needed' => 0.1]);

        $this->stockIn($sugar, 'ingredient', 5, 5000);

        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'invoice' => 'TRX-LEGACY-2',
            'customer_name' => 'Lama',
            'cash' => 20000, 'change' => 0, 'discount' => 0,
            'taxable_amount' => 20000, 'tax_amount' => 0,
            'grand_total' => 20000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);
        $transaction->details()->create([
            'product_id' => $cake->id, 'qty' => 1, 'price' => 20000, 'buy_price' => 0,
        ]);

        $this->artisan('repair:profit')->assertSuccessful();

        $this->assertEqualsWithDelta(
            500.0,
            (float) $transaction->details()->firstOrFail()->buy_price,
            0.01,
            'Estimasi harus 0,1 Kg x 5.000 = 500, bukan cost_price basi 100.000.'
        );
    }

    public function test_estimates_bundle_hpp_from_components(): void
    {
        $rice = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Nasi Repair',
            'buy_price' => 5000, 'sell_price' => 8000, 'stock' => 20, 'unit' => 'Pcs',
        ]);
        $soup = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Soto Repair',
            'buy_price' => 7000, 'sell_price' => 12000, 'stock' => 20, 'unit' => 'Pcs',
        ]);
        $bundle = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Bundel Repair',
            'type' => 'bundle', 'buy_price' => 0, 'cost_price' => 0,
            'sell_price' => 18000, 'stock' => 0, 'unit' => 'Paket',
        ]);
        $bundle->bundle_items()->attach([
            ['item_id' => $rice->id, 'qty' => 1, 'product_unit_id' => null],
            ['item_id' => $soup->id, 'qty' => 1, 'product_unit_id' => null],
        ]);

        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'invoice' => 'TRX-LEGACY-3',
            'customer_name' => 'Lama',
            'cash' => 18000, 'change' => 0, 'discount' => 0,
            'taxable_amount' => 18000, 'tax_amount' => 0,
            'grand_total' => 18000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);
        $transaction->details()->create([
            'product_id' => $bundle->id, 'qty' => 1, 'price' => 18000, 'buy_price' => 0,
        ]);

        $this->artisan('repair:profit')->assertSuccessful();

        // Versi lama: cost_price dan buy_price bundel sama-sama 0, jadi HPP
        // tidak pernah bisa dipulihkan. Sekarang = 5.000 + 7.000.
        $this->assertEqualsWithDelta(
            12000.0,
            (float) $transaction->details()->firstOrFail()->buy_price,
            0.01,
            'Bundel harus diestimasi dari HPP komponennya (5.000 + 7.000).'
        );
    }

    public function test_profit_row_is_recalculated_and_tax_is_excluded(): void
    {
        Setting::first()->update(['tax_enabled' => true, 'tax_rate' => 10]);

        $product = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Pajak Repair',
            'buy_price' => 10000, 'sell_price' => 50000, 'stock' => 20, 'unit' => 'Pcs',
        ]);

        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'invoice' => 'TRX-LEGACY-TAX',
            'customer_name' => 'Lama',
            'cash' => 55000, 'change' => 0, 'discount' => 0,
            'taxable_amount' => 50000, 'tax_amount' => 5000,
            'grand_total' => 55000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id, 'qty' => 1, 'price' => 50000, 'buy_price' => 0,
        ]);

        $this->artisan('repair:profit')->assertSuccessful();

        $profit = (float) $transaction->profits()->firstOrFail()->total;

        // 50.000 - 10.000 HPP = 40.000. Pajak 5.000 TIDAK boleh jadi laba.
        $this->assertEqualsWithDelta(40000.0, $profit, 0.01,
            'Pajak adalah kewajiban, bukan pendapatan, jadi tidak masuk laba.');
    }

    public function test_dry_run_does_not_write_anything(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Dry Run Repair',
            'buy_price' => 12000, 'sell_price' => 25000, 'stock' => 20, 'unit' => 'Pcs',
        ]);

        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'invoice' => 'TRX-DRY-1',
            'customer_name' => 'Lama',
            'cash' => 25000, 'change' => 0, 'discount' => 0,
            'taxable_amount' => 25000, 'tax_amount' => 0,
            'grand_total' => 25000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id, 'qty' => 1, 'price' => 25000, 'buy_price' => 0,
        ]);

        $this->artisan('repair:profit --dry-run')->assertSuccessful();

        $this->assertEqualsWithDelta(
            0.0,
            (float) $transaction->details()->firstOrFail()->buy_price,
            0.01,
            'Dry run tidak boleh menulis ke database.'
        );
        $this->assertSame(0, $transaction->profits()->count());
    }

    public function test_refunded_transactions_are_left_alone(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Refund Repair',
            'buy_price' => 12000, 'sell_price' => 25000, 'stock' => 20, 'unit' => 'Pcs',
        ]);

        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'invoice' => 'TRX-REFUND-1',
            'customer_name' => 'Lama',
            'cash' => 25000, 'change' => 0, 'discount' => 0,
            'taxable_amount' => 25000, 'tax_amount' => 0,
            'grand_total' => 25000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'refunded',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id, 'qty' => 1, 'price' => 25000, 'buy_price' => 0,
        ]);

        $this->artisan('repair:profit')->assertSuccessful();

        $this->assertEqualsWithDelta(
            0.0,
            (float) $transaction->details()->firstOrFail()->buy_price,
            0.01,
            'Transaksi refund tidak boleh ditulis, karena tidak masuk laporan.'
        );
    }

    public function test_rows_with_correct_hpp_are_never_touched(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Sudah Benar',
            'buy_price' => 10000, 'sell_price' => 25000, 'stock' => 20, 'unit' => 'Pcs',
        ]);

        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'invoice' => 'TRX-OK-1',
            'customer_name' => 'Benar',
            'cash' => 25000, 'change' => 0, 'discount' => 0,
            'taxable_amount' => 25000, 'tax_amount' => 0,
            'grand_total' => 25000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id, 'qty' => 1, 'price' => 25000, 'buy_price' => 7777,
        ]);
        $transaction->profits()->create(['total' => 12345]);

        $this->artisan('repair:profit')->assertSuccessful();

        $this->assertEqualsWithDelta(
            7777.0,
            (float) $transaction->details()->firstOrFail()->buy_price,
            0.01,
            'HPP yang sudah benar tidak boleh ditimpa.'
        );
        $this->assertSame(1, $transaction->profits()->count(),
            'Transaksi tanpa HPP kosong tidak boleh ikut dihitung ulang.');
    }

    public function test_dry_run_reports_sources_without_writing(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Sumber Repair',
            'buy_price' => 9000, 'sell_price' => 20000, 'stock' => 10, 'unit' => 'Pcs',
        ]);

        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'invoice' => 'TRX-SRC-1',
            'customer_name' => 'Sumber',
            'cash' => 20000, 'change' => 0, 'discount' => 0,
            'taxable_amount' => 20000, 'tax_amount' => 0,
            'grand_total' => 20000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id, 'qty' => 1, 'price' => 20000, 'buy_price' => 0,
        ]);

        $this->artisan('repair:profit --dry-run')
            ->expectsOutputToContain('Ledger batch')
            ->expectsOutputToContain('Estimasi master data')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(0.0, (float) $transaction->details()->firstOrFail()->buy_price, 0.01);
    }
}
