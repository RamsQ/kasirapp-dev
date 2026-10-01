<?php

namespace Tests\Feature\Uat;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Recipe;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * UAT POS: alur penjualan nyata dengan kombinasi fitur yang paling rawan
 * salah hitung -- satuan konversi, resep bahan baku, bundel, diskon, dan pajak.
 *
 * Yang diuji di sini: angka yang muncul di struk dan laporan.
 */
class UatPosCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);

        $this->cashier = User::factory()->create();
        $this->cashier->givePermissionTo('transactions-access');
        Shift::create([
            'user_id' => $this->cashier->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    protected function category(string $name = 'Menu UAT'): Category
    {
        return Category::create(['name' => $name.'-'.uniqid()]);
    }

    protected function sell(array $payload = []): Transaction
    {
        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), array_merge([
                'payment_gateway' => 'cash',
                'change' => 0,
            ], $payload))
            ->assertRedirect();

        return Transaction::latest('id')->firstOrFail();
    }

    protected function cartItem(Product $product, int $qty, ?ProductUnit $unit = null, ?int $unitPrice = null): Cart
    {
        return Cart::create([
            'cashier_id' => $this->cashier->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit?->id,
            'qty' => $qty,
            // price = TOTAL BARIS, sama seperti updateCart()
            'price' => ($unitPrice ?? $product->sell_price) * $qty,
        ]);
    }

    public function test_unit_conversion_moves_the_right_amount_of_stock(): void
    {
        $category = $this->category();
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'Susu',
            'buy_price' => 5000,
            'sell_price' => 20000,
            'stock' => 100,
            'unit' => 'Pcs',
        ]);

        // 1 Box = 12 Pcs
        $box = ProductUnit::create([
            'product_id' => $product->id,
            'unit_name' => 'Box',
            'conversion' => 12,
            'sell_price' => 200000,
        ]);

        $this->cartItem($product, 2, $box, 200000);
        $transaction = $this->sell(['cash' => 400000]);

        // 2 box = 24 pcs, bukan 2 pcs.
        $this->assertEqualsWithDelta(76.0, (float) $product->fresh()->stock, 0.001, 'Stok harus berkurang 24, bukan 2.');

        $detail = $transaction->details()->firstOrFail();
        $this->assertNotNull($detail->product_unit_id);
        $this->assertSame(400000, (int) $transaction->grand_total);
    }

    public function test_recipe_product_costs_its_ingredients_not_its_own_buy_price(): void
    {
        $category = $this->category();

        $flour = Ingredient::create([
            'name' => 'Tepung',
            'buy_price' => 3000,
            'stock' => 1000,
            'unit' => 'gram',
        ]);
        $sugar = Ingredient::create([
            'name' => 'Gula',
            'buy_price' => 5000,
            'stock' => 1000,
            'unit' => 'gram',
        ]);

        // Menu tanpa buy_price sendiri -> HPP harus dari resep.
        $cake = Product::create([
            'category_id' => $category->id,
            'title' => 'Kue Lapis',
            'buy_price' => 0,
            'sell_price' => 25000,
            'stock' => 20,
            'unit' => 'Pcs',
        ]);
        Recipe::create(['product_id' => $cake->id, 'ingredient_id' => $flour->id, 'qty_needed' => 100]);
        Recipe::create(['product_id' => $cake->id, 'ingredient_id' => $sugar->id, 'qty_needed' => 50]);

        $this->cartItem($cake, 2);
        $transaction = $this->sell(['cash' => 50000]);

        // Resep: 100g*x3000 + 50g*x5000 = 300.000 + 250.000 = 550.000 per pc
        // 2 pcs = 1.100.000
        $hpp = (int) $transaction->details()->sum(
            \Illuminate\Support\Facades\DB::raw('buy_price * qty')
        );
        $this->assertSame(1100000, $hpp, 'HPP harus dihitung dari bahan resep.');

        // Bahan ikut berkurang.
        $this->assertSame(800.0, (float) $flour->fresh()->stock);
        $this->assertSame(900.0, (float) $sugar->fresh()->stock);
    }

    public function test_bundle_deducts_all_components(): void
    {
        $category = $this->category();
        $rice = Product::create(['category_id' => $category->id, 'title' => 'Nasi', 'buy_price' => 5000, 'sell_price' => 8000, 'stock' => 50]);
        $soup = Product::create(['category_id' => $category->id, 'title' => 'Soto', 'buy_price' => 7000, 'sell_price' => 12000, 'stock' => 50]);

        $bundle = Product::create([
            'category_id' => $category->id,
            'title' => 'Paket Hemat',
            'type' => 'bundle',
            'buy_price' => 0,
            'sell_price' => 18000,
            'stock' => 20,
        ]);
        $bundle->bundle_items()->attach([
            ['item_id' => $rice->id, 'qty' => 1, 'product_unit_id' => null],
            ['item_id' => $soup->id, 'qty' => 1, 'product_unit_id' => null],
        ]);

        $this->cartItem($bundle, 3);
        $transaction = $this->sell(['cash' => 54000]);

        $this->assertEqualsWithDelta(47.0, (float) $rice->fresh()->stock, 0.001);
        $this->assertEqualsWithDelta(47.0, (float) $soup->fresh()->stock, 0.001);
        $this->assertSame(54000, (int) $transaction->grand_total);

        // HPP paket = komponen (5.000 + 7.000) x 3 = 36.000
        $hpp = (int) $transaction->details()->sum(
            \Illuminate\Support\Facades\DB::raw('buy_price * qty')
        );
        $this->assertSame(36000, $hpp);
    }

    public function test_discount_percentage_applies_after_subtotal(): void
    {
        $category = $this->category();
        $product = Product::create(['category_id' => $category->id, 'title' => 'Item', 'buy_price' => 5000, 'sell_price' => 50000, 'stock' => 20]);

        Discount::create([
            'name' => 'Diskon 10%',
            'type' => 'percentage',
            'value' => 10,
            'min_transaction' => 0,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->cartItem($product, 2); // 100.000
        $transaction = $this->sell(['cash' => 90000]);

        $this->assertSame(10000, (int) $transaction->discount);
        $this->assertSame(90000, (int) $transaction->taxable_amount);
        $this->assertSame(90000, (int) $transaction->grand_total);
    }

    public function test_tax_is_calculated_after_discount(): void
    {
        $category = $this->category();
        $product = Product::create(['category_id' => $category->id, 'title' => 'Item', 'buy_price' => 5000, 'sell_price' => 100000, 'stock' => 20]);

        \App\Models\Setting::create([
            'cogs_method' => 'AVERAGE',
            'tax_enabled' => true,
            'tax_rate' => 10,
            'loyalty_enabled' => true,
            'loyalty_points_per_100' => 1,
            'kitchen_display_enabled' => false,
        ]);

        Discount::create([
            'name' => 'Potong 20rb',
            'type' => 'fixed',
            'value' => 20000,
            'min_transaction' => 0,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $this->cartItem($product, 1); // 100.000
        $transaction = $this->sell(['cash' => 100000]);

        // 100.000 - 20.000 = 80.000, pajak 10% = 8.000, total 88.000
        $this->assertSame(20000, (int) $transaction->discount);
        $this->assertSame(80000, (int) $transaction->taxable_amount);
        $this->assertSame(8000, (int) $transaction->tax_amount);
        $this->assertSame(88000, (int) $transaction->grand_total);
    }

    public function test_purchase_with_voucher_and_discount_and_tax_combines_correctly(): void
    {
        $category = $this->category();
        $product = Product::create(['category_id' => $category->id, 'title' => 'Item', 'buy_price' => 20000, 'sell_price' => 100000, 'stock' => 20]);

        \App\Models\Setting::create([
            'cogs_method' => 'AVERAGE',
            'tax_enabled' => true,
            'tax_rate' => 10,
            'loyalty_enabled' => true,
            'loyalty_points_per_100' => 1,
            'kitchen_display_enabled' => false,
        ]);

        Discount::create([
            'name' => 'Diskon otomatis 10rb',
            'type' => 'fixed',
            'value' => 10000,
            'min_transaction' => 0,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'name' => 'Budi',
            'no_telp' => '08120001',
            'address' => 'Alamat',
            'marketing_opt_in' => true,
        ]);
        \App\Models\CustomerVoucher::create([
            'customer_id' => $customer->id,
            'code' => 'HEMAT15',
            'name' => 'Voucher 15rb',
            'type' => 'fixed',
            'value' => 15000,
            'min_spend' => 0,
        ]);

        $this->cartItem($product, 1);
        $transaction = $this->sell([
            'customer_id' => $customer->id,
            'voucher_code' => 'HEMAT15',
            'cash' => 85000,
        ]);

        // 100.000 - 10.000 (diskon) - 15.000 (voucher) = 75.000
        $this->assertSame(25000, (int) $transaction->discount);
        $this->assertSame(15000, (int) $transaction->voucher_discount);
        $this->assertSame(75000, (int) $transaction->taxable_amount);
        $this->assertSame(7500, (int) $transaction->tax_amount);
        $this->assertSame(82500, (int) $transaction->grand_total);

        // Poin dari nilai SETELAH diskon: 75.000/100 = 750
        $this->assertSame(750, (int) $customer->fresh()->loyalty_points);
    }

    public function test_underpayment_cash_is_rejected(): void
    {
        $category = $this->category();
        $product = Product::create(['category_id' => $category->id, 'title' => 'Item', 'buy_price' => 5000, 'sell_price' => 50000, 'stock' => 20]);

        $this->cartItem($product, 1);

        $this->actingAs($this->cashier)
            ->postJson(route('transactions.store'), [
                'payment_gateway' => 'cash',
                'cash' => 10000,
                'change' => 0,
            ])
            ->assertUnprocessable();

        $this->assertSame(0, Transaction::count());
        $this->assertEqualsWithDelta(20.0, (float) $product->fresh()->stock, 0.001, 'Stok tidak boleh berkurang saat transaksi gagal.');
    }

    public function test_financial_report_matches_the_sale_exactly(): void
    {
        $category = $this->category();
        $product = Product::create(['category_id' => $category->id, 'title' => 'Item', 'buy_price' => 25000, 'sell_price' => 60000, 'stock' => 50]);

        $this->cartItem($product, 3);
        $transaction = $this->sell(['cash' => 180000]);

        $financials = app(FinancialReportService::class)->summary(
            Transaction::whereDate('created_at', now())
        );

        $this->assertSame(180000, (int) $financials['net_revenue']);
        $this->assertSame(75000, (int) $financials['hpp']);
        $this->assertSame(105000, (int) $financials['gross_profit_before_platform']);
        $this->assertSame(0, (int) $financials['operating_expenses']);
        $this->assertSame(105000, (int) $financials['net_profit']);
        $this->assertSame((int) $transaction->grand_total, (int) $financials['net_revenue']);
    }
}
