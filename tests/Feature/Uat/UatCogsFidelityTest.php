<?php

namespace Tests\Feature\Uat;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Shift;
use App\Models\StockBatch;
use App\Models\StockBatchAllocation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialReportService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT HPP: membandingkan HPP yang DILAPORKAN dengan HPP yang benar-benar
 * DIBELANJAKAN dari batch layer.
 *
 * Ini pertanyaan inti: apakah laba di laporan sama dengan_REALITA_ modal
 * yang benar-benar terpakai? Kalau tidak, laba meleset.
 */
class UatCogsFidelityTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole($role);
        $this->admin->givePermissionTo('transactions-access');

        Shift::create([
            'user_id' => $this->admin->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        \App\Models\Setting::create([
            'cogs_method' => 'FIFO',
            'tax_enabled' => false,
            'tax_rate' => 0,
            'loyalty_enabled' => false,
            'kitchen_display_enabled' => false,
        ]);
    }

    protected function category(): Category
    {
        return Category::create(['name' => 'HPP-'.uniqid()]);
    }

    protected function stockIn($model, string $type, float $qty, float $price): void
    {
        $this->actingAs($this->admin)->post(route('stock_in.store'), [
            'entries' => [[
                'id' => $model->id,
                'type' => $type,
                'qty_in' => $qty,
                'buy_price' => $price,
            ]],
        ])->assertRedirect();
    }

    protected function sell(Product $product, int $qty, int $cash): Transaction
    {
        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id,
            'qty' => $qty,
            'price' => $product->sell_price * $qty,
        ]);

        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash',
            'cash' => $cash,
            'change' => 0,
        ])->assertOk();

        return Transaction::latest('id')->firstOrFail();
    }

    /**
     * HPP sejati = jumlah total_cost yang benar-benar dialokasikan ke batch.
     * Ini adalah kebenaran yang tidak bisa dibantah.
     */
    protected function actualAllocatedCogs(Transaction $transaction): float
    {
        return (float) StockBatchAllocation::where('transaction_id', $transaction->id)
            ->where('movement_type', 'sale')
            ->where('status', 'consumed')
            ->sum('total_cost');
    }

    protected function reportedCogs(Transaction $transaction): float
    {
        return (float) $transaction->details()->sum(DB::raw('buy_price * qty'));
    }

    // ============================================================
    // KASUS 1: produk biasa, cost_price = 0, FIFO
    // ============================================================

    public function test_fifo_sale_reports_the_oldest_batch_price_not_the_average(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Beras',
            'buy_price' => 0,
            'sell_price' => 30000,
            'stock' => 0,
            'unit' => 'Kg',
        ]);

        $this->stockIn($product, 'products', 10, 10000);
        $this->stockIn($product, 'products', 10, 30000);

        $product->refresh();
        $this->assertSame(20000, (int) $product->buy_price, 'Harga rata-rata tertimbang = 20.000.');

        $transaction = $this->sell($product, 10, 300000);

        // FIFO: batch TERLAMA (10.000) yang dipakai.
        $this->assertEqualsWithDelta(100000.0, $this->actualAllocatedCogs($transaction), 0.01,
            '10 unit x 10.000 = 100.000 harus benar-benar dialokasikan.');

        $this->assertEqualsWithDelta(
            100000.0,
            $this->reportedCogs($transaction),
            0.01,
            'Laporan harus memakai HPP FIFO yang sebenarnya (100.000), bukan rata-rata 20.000 -> 200.000.'
        );
    }

    public function test_lifo_sale_reports_the_newest_batch_price(): void
    {
        \App\Models\Setting::where('id', \App\Models\Setting::first()->id)->update(['cogs_method' => 'LIFO']);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Beras LIFO',
            'buy_price' => 0,
            'sell_price' => 40000,
            'stock' => 0,
            'unit' => 'Kg',
        ]);

        $this->stockIn($product, 'products', 10, 10000);
        $this->stockIn($product, 'products', 10, 30000);

        $transaction = $this->sell($product, 10, 400000);

        // LIFO: batch TERBARU (30.000) yang dipakai.
        $this->assertEqualsWithDelta(300000.0, $this->actualAllocatedCogs($transaction), 0.01);
        $this->assertEqualsWithDelta(300000.0, $this->reportedCogs($transaction), 0.01);
    }

    public function test_average_method_reports_the_weighted_average(): void
    {
        \App\Models\Setting::where('id', \App\Models\Setting::first()->id)->update(['cogs_method' => 'AVERAGE']);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Gula',
            'buy_price' => 0,
            'sell_price' => 30000,
            'stock' => 0,
            'unit' => 'Kg',
        ]);

        $this->stockIn($product, 'products', 10, 10000);
        $this->stockIn($product, 'products', 30, 30000);

        // Rata-rata tertimbang: (10*10.000 + 30*30.000) / 40 = 1.000.000 / 40 = 25.000
        $this->assertSame(25000, (int) $product->fresh()->buy_price);

        $transaction = $this->sell($product, 20, 600000);

        $this->assertEqualsWithDelta(500000.0, $this->actualAllocatedCogs($transaction), 0.01,
            '20 x 25.000 = 500.000');
        $this->assertEqualsWithDelta(500000.0, $this->reportedCogs($transaction), 0.01);
    }

    // ============================================================
    // KASUS 2: produk dengan cost_price > 0
    // ============================================================

    public function test_product_with_cost_price_still_reports_actual_batch_cost(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Menu dengan cost_price',
            'buy_price' => 0,
            'cost_price' => 20000,   // <- sengaja diisi, seperti hasil "Hitung HPP"
            'sell_price' => 40000,
            'stock' => 0,
            'unit' => 'Kg',
        ]);

        $this->stockIn($product, 'products', 10, 10000);
        $this->stockIn($product, 'products', 10, 30000);

        $transaction = $this->sell($product, 10, 400000);

        $this->assertEqualsWithDelta(100000.0, $this->actualAllocatedCogs($transaction), 0.01,
            'FIFO benar-benar membakar batch 10.000.');

        $this->assertEqualsWithDelta(
            100000.0,
            $this->reportedCogs($transaction),
            0.01,
            'cost_price = 20.000 tidak boleh menutupi HPP FIFO yang sebenarnya 100.000.'
        );
    }

    // ============================================================
    // KASUS 3: produk resep (jalur paling rawan)
    // ============================================================

    public function test_recipe_sale_reports_actual_ingredient_batch_cost(): void
    {
        $flour = Ingredient::create([
            'name' => 'Tepung',
            'buy_price' => 0,
            'stock' => 0,
            'unit' => 'gram',
        ]);

        $cake = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Kue Lapis',
            'buy_price' => 0,
            'cost_price' => 0,
            'sell_price' => 50000,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);
        Recipe::create(['product_id' => $cake->id, 'ingredient_id' => $flour->id, 'qty_needed' => 100]);

        // Harga naik setelah resep dibuat (meniru kenaikan harga bahan).
        $this->stockIn($flour, 'ingredients', 1000, 3000);
        $this->stockIn($flour, 'ingredients', 1000, 9000);

        $flour->refresh();
        $this->assertSame(6000, (int) $flour->buy_price, 'Rata-rata bahan = 6.000.');

        // Admin menekan "Hitung HPP" -> cost_price tersimpan.
        $this->actingAs($this->admin)
            ->post(route('recipes.sync_all'))
            ->assertRedirect();
        $this->assertEqualsWithDelta(600000.0, (float) $cake->fresh()->cost_price, 0.01,
            'cost_price = 100 x 6.000 = 600.000');

        $transaction = $this->sell($cake, 5, 250000);

        // FIFO: 5 kue x 100 gram = 500 gram dari batch TERLAMA (3.000).
        $this->assertEqualsWithDelta(1500000.0, $this->actualAllocatedCogs($transaction), 0.01,
            '500 gram x 3.000 = 1.500.000.');

        $this->assertEqualsWithDelta(
            1500000.0,
            $this->reportedCogs($transaction),
            0.01,
            'Laporan harus 1.500.000 (FIFO), bukan 5 x 600.000 = 3.000.000 dari cost_price basi.'
        );
    }

    public function test_recipe_hpp_is_not_affected_by_ingredients_purchased_after_last_sync(): void
    {
        $sugar = Ingredient::create([
            'name' => 'Gula',
            'buy_price' => 0,
            'stock' => 0,
            'unit' => 'gram',
        ]);

        $drink = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Es Gula',
            'buy_price' => 0,
            'cost_price' => 0,
            'sell_price' => 15000,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);
        Recipe::create(['product_id' => $drink->id, 'ingredient_id' => $sugar->id, 'qty_needed' => 50]);

        // Dua batch: 5.000 lalu 1.000 -> rata-rata 3.000
        $this->stockIn($sugar, 'ingredients', 1000, 5000);
        $this->stockIn($sugar, 'ingredients', 1000, 1000);
        $this->assertSame(3000, (int) $sugar->fresh()->buy_price);

        // Admin menekan "Hitung HPP" -> cost_price dibekukan di 50 x 3.000.
        $this->actingAs($this->admin)->post(route('recipes.sync_all'))->assertRedirect();
        $this->assertEqualsWithDelta(150000.0, (float) $drink->fresh()->cost_price, 0.01);

        $transaction = $this->sell($drink, 4, 60000);

        // FIFO: 4 x 50 gram = 200 gram dari batch LAMA (5.000) = 1.000.000
        $this->assertEqualsWithDelta(1000000.0, $this->actualAllocatedCogs($transaction), 0.01);

        // Jalur rusak akan melaporkan 4 x 150.000 = 600.000 (harga bahan naik
        // setelah sinkron, jadi cost_price sudah basi).
        $this->assertEqualsWithDelta(
            1000000.0,
            $this->reportedCogs($transaction),
            0.01,
            'cost_price basi 150.000 tidak boleh dipakai untuk HPP.'
        );
    }

    // ============================================================
    // KASUS 4: konsistensi silang dengan FinancialReportService
    // ============================================================

    public function test_profit_report_uses_the_same_hpp_as_the_batch_ledger(): void
    {
        $flour = Ingredient::create(['name' => 'Tepung 2', 'buy_price' => 0, 'stock' => 0, 'unit' => 'gram']);
        $bread = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Roti',
            'buy_price' => 0,
            'cost_price' => 0,
            'sell_price' => 25000,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);
        Recipe::create(['product_id' => $bread->id, 'ingredient_id' => $flour->id, 'qty_needed' => 200]);

        $this->stockIn($flour, 'ingredients', 500, 2000);
        $this->stockIn($flour, 'ingredients', 500, 8000);
        $this->actingAs($this->admin)->post(route('recipes.sync_all'))->assertRedirect();

        $transaction = $this->sell($bread, 3, 75000);

        $ledger = $this->actualAllocatedCogs($transaction);
        $summary = app(FinancialReportService::class)->summary(
            Transaction::whereDate('created_at', now())
        );

        $this->assertEqualsWithDelta(
            $ledger,
            (float) $summary['hpp'],
            0.01,
            'HPP di laporan laba harus identik dengan ledger batch.'
        );

        // 3 roti x 200g = 600g, FIFO MELEWATI ke batch kedua:
        //   500g x 2.000 = 1.000.000  +  100g x 8.000 = 800.000  = 1.800.000
        $this->assertEqualsWithDelta(1800000.0, (float) $summary['hpp'], 0.01);

        // Omzet 75.000 - HPP 1.800.000 = laba negatif. Itu bukti HPP bukan angka fiktif.
        $this->assertEqualsWithDelta(
            75000 - 1800000,
            (float) $summary['net_profit'],
            0.01,
            'Laba harus jujur, walau negatif.'
        );
    }

    public function test_refund_restores_exact_batches_and_zeroes_hpp(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Refund HPP',
            'buy_price' => 0,
            'cost_price' => 0,
            'sell_price' => 40000,
            'stock' => 0,
            'unit' => 'Kg',
        ]);

        $this->stockIn($product, 'products', 10, 10000);
        $this->stockIn($product, 'products', 10, 30000);

        $transaction = $this->sell($product, 10, 400000);
        $this->assertEqualsWithDelta(100000.0, $this->actualAllocatedCogs($transaction), 0.01);

        $this->actingAs($this->admin)
            ->from(route('transactions.index'))
            ->post(route('transactions.refund', $transaction->id), ['password' => 'password'])
            ->assertRedirect();

        // Batch lama harus kembali utuh 10, bukan ikut tergerus.
        $oldest = StockBatch::where('product_id', $product->id)->orderBy('id')->firstOrFail();
        $this->assertEqualsWithDelta(10.0, (float) $oldest->qty_remaining, 0.001,
            'Refund harus mengembalikan batch yang sama persis.');
        $this->assertEqualsWithDelta(20.0, (float) $product->fresh()->stock, 0.001);

        $summary = app(FinancialReportService::class)->summary(
            Transaction::whereDate('created_at', now())
        );
        $this->assertSame(0, (int) $summary['hpp'], 'Setelah refund, HPP laporan harus nol.');
        $this->assertSame(0, (int) $summary['net_revenue']);
    }

    public function test_ingredient_stock_in_refreshes_the_recipe_estimate(): void
    {
        $sugar = Ingredient::create(['name' => 'Gula 3', 'buy_price' => 0, 'stock' => 0, 'unit' => 'gram']);
        $tea = Ingredient::create(['name' => 'Teh 3', 'buy_price' => 0, 'stock' => 0, 'unit' => 'gram']);

        $drink = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Es Teh Manis',
            'buy_price' => 0,
            'cost_price' => 0,
            'sell_price' => 8000,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);
        Recipe::create(['product_id' => $drink->id, 'ingredient_id' => $sugar->id, 'qty_needed' => 20]);
        Recipe::create(['product_id' => $drink->id, 'ingredient_id' => $tea->id, 'qty_needed' => 10]);

        $this->stockIn($sugar, 'ingredients', 1000, 5000);
        $this->stockIn($tea, 'ingredients', 1000, 2000);

        // 20 x 5.000 + 10 x 2.000 = 120.000
        $this->assertEqualsWithDelta(120000.0, (float) $drink->fresh()->cost_price, 0.01,
            'Perkiraan HPP harus ikut terisi otomatis tanpa perlu tombol Hitung HPP.');

        // Harga gula naik -> perkiraan harus menyusul, tidak boleh basi.
        $this->stockIn($sugar, 'ingredients', 1000, 9000);
        $sugar->refresh();
        $this->assertSame(7000, (int) $sugar->buy_price, 'Rata-rata gula = (5.000 + 9.000) / 2.');

        // 20 x 7.000 + 10 x 2.000 = 160.000
        $this->assertEqualsWithDelta(160000.0, (float) $drink->fresh()->cost_price, 0.01,
            'Perkiraan HPP harus ikut ter-update agar tidak berbeda dari laporan laba.');
    }

    public function test_stock_in_of_unrelated_ingredient_does_not_touch_recipe(): void
    {
        $sugar = Ingredient::create(['name' => 'Gula 4', 'buy_price' => 0, 'stock' => 0, 'unit' => 'gram']);
        $unrelated = Ingredient::create(['name' => 'Bawang 4', 'buy_price' => 0, 'stock' => 0, 'unit' => 'gram']);

        $cake = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Kue 4',
            'buy_price' => 0,
            'cost_price' => 0,
            'sell_price' => 20000,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);
        Recipe::create(['product_id' => $cake->id, 'ingredient_id' => $sugar->id, 'qty_needed' => 30]);

        $this->stockIn($sugar, 'ingredients', 500, 4000);
        $this->assertEqualsWithDelta(120000.0, (float) $cake->fresh()->cost_price, 0.01);

        $this->stockIn($unrelated, 'ingredients', 500, 99000);
        $this->assertEqualsWithDelta(120000.0, (float) $cake->fresh()->cost_price, 0.01,
            'Bahan yang tidak dipakai menu tidak boleh mengubah HPP menu tersebut.');
    }

    public function test_recipe_page_exposes_the_data_needed_for_the_hpp_sanity_warning(): void
    {
        $flour = Ingredient::create([
            'name' => 'Tepung Sanity', 'buy_price' => 30, 'stock' => 1000, 'unit' => 'gram',
        ]);
        $cake = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Kue Sanity',
            'buy_price' => 0,
            'sell_price' => 8000,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);

        // Menu rugi yang disengaja (HPP > harga jual) tetap boleh disimpan
        // setelah dikonfirmasi eksplisit, dan di sanalah badge "RUGI" pada
        // halaman resep muncul. Tepung Rp 30/gram x 400 gram = Rp 12.000
        // untuk menu Rp 8.000, jadi rasio HPP 150%.
        $this->actingAs($this->admin)
            ->post(route('recipes.store'), [
                'product_id' => $cake->id,
                'ingredients' => [['ingredient_id' => $flour->id, 'qty_needed' => 400]],
                'confirm_loss_leader' => 1,
            ])
            ->assertRedirect();

        $this->assertSame(1, $cake->fresh()->recipes()->count());

        $this->actingAs($this->admin)
            ->get(route('recipes.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Daftar menu: perlu sell_price + cost_price untuk rasio HPP.
                ->component('Dashboard/Recipes/Index')
                ->where('products.data.0.title', 'Kue Sanity')
                ->where('products.data.0.sell_price', 8000)
                ->where('products.data.0.cost_price', 12000)
                // Form input: perlu sell_price untuk membandingkan pratinjau HPP.
                ->where('allProducts.0.sell_price', 8000)
                // Dropdown bahan: perlu satuan agar kasir tahu satuan qty.
                ->where('ingredients.0.name', 'Tepung Sanity')
                ->where('ingredients.0.unit', 'gram')
            );
    }

    public function test_sale_beyond_available_batches_falls_back_to_buy_price(): void
    {
        // Tidak ada batch sama sekali: HPP harus jatuh ke buy_price, bukan 0.
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Tanpa Batch',
            'buy_price' => 15000,
            'sell_price' => 40000,
            'stock' => 10,
            'unit' => 'Pcs',
        ]);

        $this->assertSame(0, StockBatch::where('product_id', $product->id)->count());

        $transaction = $this->sell($product, 2, 80000);

        $this->assertEqualsWithDelta(30000.0, $this->reportedCogs($transaction), 0.01,
            'Tanpa batch, HPP harus memakai buy_price (2 x 15.000), bukan nol.');
    }

    // ============================================================
    // KASUS 5: siklus HPP pada pembayaran gateway
    // ============================================================

    private function gatewaySale(Product $product, int $qty, string $method): Transaction
    {
        $attributes = $method === 'xendit'
            ? [
                'xendit_enabled' => true,
                'xendit_secret_key' => 'xendit-secret',
                'xendit_callback_token' => 'xendit-callback-token',
                'xendit_public_key' => 'xendit-public',
            ]
            : [
                'midtrans_enabled' => true,
                'midtrans_server_key' => 'test-secret',
                'midtrans_client_key' => 'test-client',
            ];

        \App\Models\PaymentSetting::create(array_merge(
            ['default_gateway' => $method],
            $attributes,
        ));

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id,
            'qty' => $qty,
            'price' => $product->sell_price * $qty,
        ]);

        // Midtrans dan Xendit memakai nama field respons yang berbeda.
        $body = $method === 'xendit'
            ? ['id' => 'xendit-invoice-123', 'invoice_url' => 'https://payment.test/xendit']
            : ['token' => 'fake-token-123', 'redirect_url' => 'https://payment.test/midtrans'];

        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response($body),
        ]);

        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => $method,
        ])->assertOk();

        $transaction = Transaction::latest('id')->firstOrFail();
        $this->assertSame('pending', $transaction->payment_status, 'Gateway harus mulai pending.');

        return $transaction;
    }

    public function test_pending_gateway_hpp_appears_in_report_only_after_settlement(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'QRIS Barang',
            'buy_price' => 0,
            'cost_price' => 0,
            'sell_price' => 50000,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);

        $this->stockIn($product, 'products', 10, 12000);
        $this->stockIn($product, 'products', 10, 28000);

        $transaction = $this->gatewaySale($product, 4, 'midtrans');

        // HPP ter-reserve tapi belum boleh muncul di laporan.
        $pending = app(FinancialReportService::class)->summary(Transaction::whereDate('created_at', now()));
        $this->assertSame(0, $pending['transaction_count'], 'Transaksi pending tidak boleh dihitung.');
        $this->assertSame(0.0, (float) $pending['hpp']);

        $this->assertSame(1, StockBatchAllocation::where('transaction_id', $transaction->id)
            ->where('status', 'reserved')->count(), 'Alokasi harus berstatus reserved.');

        // Callback lunas.
        $payload = [
            'order_id' => $transaction->invoice,
            'transaction_status' => 'settlement',
            'gross_amount' => (string) $transaction->grand_total,
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['transaction_status'].$payload['gross_amount'].'test-secret',
        );

        $this->postJson(route('payment.notification'), $payload)->assertOk();
        $this->assertSame('paid', $transaction->fresh()->payment_status);

        $settled = app(FinancialReportService::class)->summary(Transaction::whereDate('created_at', now()));
        $ledger = (float) StockBatchAllocation::where('transaction_id', $transaction->id)
            ->where('movement_type', 'sale')
            ->where('status', 'consumed')
            ->sum('total_cost');

        // FIFO: 4 x 12.000 = 48.000
        $this->assertEqualsWithDelta(48000.0, $ledger, 0.01);
        $this->assertEqualsWithDelta($ledger, (float) $settled['hpp'], 0.01,
            'HPP yang muncul di laporan harus persis sama dengan ledger batch.');
        $this->assertSame(1, $settled['transaction_count']);
        $this->assertEqualsWithDelta(200000 - 48000, (float) $settled['net_profit'], 0.01);
    }

    public function test_cancelled_gateway_never_reaches_the_profit_report(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'QRIS Batal',
            'buy_price' => 0,
            'cost_price' => 0,
            'sell_price' => 50000,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);

        $this->stockIn($product, 'products', 10, 12000);
        $transaction = $this->gatewaySale($product, 2, 'xendit');

        $this->actingAs($this->admin)
            ->postJson(route('transactions.cancel_gateway'), ['invoice' => $transaction->invoice])
            ->assertOk()
            ->assertJsonPath('success', true);

        $transaction->refresh();
        $this->assertNotSame('paid', $transaction->payment_status);

        $summary = app(FinancialReportService::class)->summary(Transaction::whereDate('created_at', now()));
        $this->assertSame(0, $summary['transaction_count']);
        $this->assertSame(0.0, (float) $summary['hpp'], 'Transaksi batal tidak boleh menyumbangkan HPP.');
        $this->assertSame(0.0, (float) $summary['net_revenue']);
    }
}
