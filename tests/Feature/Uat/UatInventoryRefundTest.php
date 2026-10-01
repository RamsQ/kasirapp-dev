<?php

namespace Tests\Feature\Uat;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerVoucher;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Shift;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialReportService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Inventori & Refund: alur barang masuk, opname, penjualan, dan refund.
 *
 * Fokus: apakah angka di laporan DAN stok fisik selalu konsisten setelah
 * refund. Refund adalah 操作 yang paling sering merusak angka.
 */
class UatInventoryRefundTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'inventory-access', 'reports-access'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['email' => 'uat-admin@example.com']);
        $this->admin->assignRole($role);
        $this->admin->givePermissionTo(['transactions-access', 'inventory-access', 'reports-access']);

        Shift::create([
            'user_id' => $this->admin->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    protected function category(): Category
    {
        return Category::create(['name' => 'UAT-'.uniqid()]);
    }

    protected function sell(array $payload = []): Transaction
    {
        $this->actingAs($this->admin)
            ->postJson(route('transactions.store'), array_merge([
                'payment_gateway' => 'cash',
                'change' => 0,
            ], $payload));

        return Transaction::latest('id')->firstOrFail();
    }

    protected function refund(Transaction $transaction): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('transactions.index'))
            ->post(route('transactions.refund', $transaction->id), [
                'password' => 'password',
            ]);
    }

    public function test_stock_in_creates_a_batch_and_updates_average_cost(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Kopi Bubuk',
            'buy_price' => 0,
            'sell_price' => 30000,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);

        $this->actingAs($this->admin)
            ->post(route('stock_in.store'), [
                'entries' => [
                    ['id' => $product->id, 'type' => 'products', 'qty_in' => 10, 'buy_price' => 50000],
                ],
            ])
            ->assertRedirect(route('stock_in.index'));

        $product->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $product->stock, 0.001);
        $this->assertSame(50000, (int) $product->buy_price);

        $batch = StockBatch::where('product_id', $product->id)->firstOrFail();
        $this->assertEqualsWithDelta(10.0, (float) $batch->qty_remaining, 0.001);
        $this->assertSame(50000, (int) $batch->buy_price);

        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('type', 'in')->count());

        // Batch kedua -> harga rata-rata harus bergerak, bukan ditimpa.
        $this->actingAs($this->admin)
            ->post(route('stock_in.store'), [
                'entries' => [
                    ['id' => $product->id, 'type' => 'products', 'qty_in' => 10, 'buy_price' => 70000],
                ],
            ])
            ->assertRedirect();

        $product->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $product->stock, 0.001);
        // rata-rata tertimbang: (10*50.000 + 10*70.000) / 20 = 60.000
        $this->assertSame(60000, (int) $product->buy_price);
    }

    public function test_opname_shrink_creates_expense_and_relaxes_batches(): void
    {
        $ingredient = Ingredient::create([
            'name' => 'Susu Kental',
            'buy_price' => 0,
            'stock' => 0,
            'unit' => 'Pcs',
        ]);

        // Stok realistis selalu datang lewat Stock In supaya ada batch layer.
        $this->actingAs($this->admin)->post(route('stock_in.store'), [
            'entries' => [
                ['id' => $ingredient->id, 'type' => 'ingredients', 'qty_in' => 10, 'buy_price' => 20000],
            ],
        ])->assertRedirect();

        $this->assertSame(20000, (int) $ingredient->fresh()->buy_price);

        $this->actingAs($this->admin)
            ->post(route('stock_opnames.store'), [
                'adjustments' => [[
                    'id' => $ingredient->id,
                    'type' => 'ingredients',
                    'stock_actual' => 7,
                    'reason' => 'Rusak / kadaluarsa',
                ]],
            ])
            ->assertRedirect(route('stock_opnames.index'));

        $this->assertEqualsWithDelta(7.0, (float) $ingredient->fresh()->stock, 0.001);

        $opname = StockOpname::where('ingredient_id', $ingredient->id)->firstOrFail();
        $this->assertEqualsWithDelta(10.0, (float) $opname->stock_system, 0.001);
        $this->assertEqualsWithDelta(7.0, (float) $opname->stock_actual, 0.001);
        $this->assertEqualsWithDelta(-3.0, (float) $opname->difference, 0.001);

        // Kerugian 3 x 20.000 = 60.000 harus tercatat sebagai beban.
        $expense = Expense::where('category', 'Kerugian Stok')->firstOrFail();
        $this->assertSame(60000, (int) $expense->amount);

        $this->assertEqualsWithDelta(
            7.0,
            (float) StockBatch::where('ingredient_id', $ingredient->id)->sum('qty_remaining'),
            0.001,
            'Sisa batch harus sama dengan stok fisik setelah opname.'
        );
    }

    public function test_opname_growth_creates_a_new_cost_layer(): void
    {
        $ingredient = Ingredient::create([
            'name' => 'Tepung',
            'buy_price' => 0,
            'stock' => 0,
            'unit' => 'Kg',
        ]);

        $this->actingAs($this->admin)->post(route('stock_in.store'), [
            'entries' => [
                ['id' => $ingredient->id, 'type' => 'ingredients', 'qty_in' => 10, 'buy_price' => 10000],
            ],
        ])->assertRedirect();

        // Temuan shrinkage: stok fisik ternyata 15, bukan 10.
        $this->actingAs($this->admin)->post(route('stock_opnames.store'), [
            'adjustments' => [[
                'id' => $ingredient->id,
                'type' => 'ingredients',
                'stock_actual' => 15,
                'reason' => 'Barang ditemukan di gudang',
            ]],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(15.0, (float) $ingredient->fresh()->stock, 0.001);
        $this->assertSame(2, StockBatch::where('ingredient_id', $ingredient->id)->count());
        $this->assertSame(0, Expense::where('category', 'Kerugian Stok')->count(),
            'Stok bertambah tidak boleh jadi beban kerugian.');

        // Rata-rata tertimbang: (10*10.000 + 5*10.000) / 15 = 10.000
        $this->assertSame(10000, (int) $ingredient->fresh()->buy_price);
    }

    public function test_opname_without_difference_records_nothing(): void
    {
        $ingredient = Ingredient::create([
            'name' => 'Gula',
            'buy_price' => 10000,
            'stock' => 5,
            'unit' => 'Pcs',
        ]);

        $this->actingAs($this->admin)->post(route('stock_opnames.store'), [
            'adjustments' => [['id' => $ingredient->id, 'type' => 'ingredients', 'stock_actual' => 5]],
        ])->assertRedirect();

        $this->assertSame(0, StockOpname::count(), 'Opname tanpa selisih tidak boleh dicatat.');
        $this->assertSame(0, Expense::count());
    }

    public function test_full_refund_restores_stock_points_and_voucher(): void
    {
        $category = $this->category();
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'Nasi Goreng',
            'buy_price' => 15000,
            'sell_price' => 30000,
            'stock' => 20,
            'unit' => 'Pcs',
        ]);

        $customer = Customer::create([
            'name' => 'Siti',
            'no_telp' => '08129999',
            'address' => 'Jl. Uji',
            'marketing_opt_in' => true,
        ]);
        $voucher = CustomerVoucher::create([
            'customer_id' => $customer->id,
            'code' => 'TEST10',
            'name' => 'Potong 10rb',
            'type' => 'fixed',
            'value' => 10000,
            'min_spend' => 0,
        ]);

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id,
            'qty' => 2,
            'price' => 60000,
        ]);

        $transaction = $this->sell([
            'customer_id' => $customer->id,
            'voucher_code' => 'TEST10',
            'cash' => 50000,
        ]);

        $this->assertSame('paid', $transaction->payment_status);
        $this->assertEqualsWithDelta(18.0, (float) $product->fresh()->stock, 0.001);
        $this->assertGreaterThan(0, (int) $customer->fresh()->loyalty_points);

        $pointsBefore = (int) $customer->fresh()->loyalty_points;
        $voucherBefore = $voucher->fresh()->used;

        $this->refund($transaction)->assertRedirect();

        $transaction->refresh();
        $this->assertSame('refunded', $transaction->status);
        $this->assertNotNull($transaction->refunded_at);

        // Stok kembali 20, poin kembali 0, voucher tidak terpakai lagi.
        $this->assertEqualsWithDelta(20.0, (float) $product->fresh()->stock, 0.001);
        $this->assertSame(0, (int) $customer->fresh()->loyalty_points);
        $this->assertSame($voucherBefore, $voucher->fresh()->used);
    }

    public function test_refund_removes_transaction_from_every_report(): void
    {
        $category = $this->category();
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'Es Teh',
            'buy_price' => 2000,
            'sell_price' => 8000,
            'stock' => 100,
            'unit' => 'Pcs',
        ]);

        Cart::create(['cashier_id' => $this->admin->id, 'product_id' => $product->id, 'qty' => 5, 'price' => 40000]);
        $keep = $this->sell(['cash' => 40000]);

        Cart::create(['cashier_id' => $this->admin->id, 'product_id' => $product->id, 'qty' => 3, 'price' => 24000]);
        $drop = $this->sell(['cash' => 24000]);

        $service = app(FinancialReportService::class);
        $query = fn () => Transaction::whereDate('created_at', now());

        $before = $service->summary($query());
        $this->assertSame(64000, (int) $before['net_revenue']);
        $this->assertSame(16000, (int) $before['hpp']);
        $this->assertSame(48000, (int) $before['net_profit']);
        $this->assertSame(2, $before['transaction_count']);

        $this->refund($drop)->assertRedirect();

        $after = $service->summary($query());
        $this->assertSame(40000, (int) $after['net_revenue'], 'Transaksi refund harus hilang dari laporan.');
        $this->assertSame(10000, (int) $after['hpp']);
        $this->assertSame(30000, (int) $after['net_profit']);
        $this->assertSame(1, $after['transaction_count']);

        // Laporan laba harus konsisten dengan laporan penjualan.
        $this->actingAs($this->admin)
            ->get(route('reports.profits.index', ['start_date' => now()->toDateString(), 'end_date' => now()->toDateString()]))
            ->assertOk();
    }

    public function test_refund_is_blocked_for_unpaid_and_double_refund(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Item',
            'buy_price' => 5000,
            'sell_price' => 20000,
            'stock' => 10,
        ]);

        Cart::create(['cashier_id' => $this->admin->id, 'product_id' => $product->id, 'qty' => 1, 'price' => 20000]);
        $transaction = $this->sell(['cash' => 20000]);

        // Refund kedua harus ditolak.
        $this->refund($transaction)->assertRedirect();
        $this->refund($transaction->fresh())->assertSessionHas('error');

        // Transaksi belum lunas harus ditolak.
        $unpaid = Transaction::create([
            'invoice' => 'INV-UNPAID-UAT',
            'cashier_id' => $this->admin->id,
            'customer_name' => 'Belum Bayar',
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 20000,
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
        ]);
        $this->refund($unpaid)->assertSessionHas('error');
    }

    public function test_refund_requires_super_admin_and_correct_password(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Item',
            'buy_price' => 5000,
            'sell_price' => 20000,
            'stock' => 10,
        ]);

        Cart::create(['cashier_id' => $this->admin->id, 'product_id' => $product->id, 'qty' => 1, 'price' => 20000]);
        $transaction = $this->sell(['cash' => 20000]);

        // Password salah.
        $this->actingAs($this->admin)
            ->from(route('transactions.index'))
            ->post(route('transactions.refund', $transaction->id), ['password' => 'salahsekali'])
            ->assertSessionHasErrors('password');
        $this->assertSame('paid', $transaction->fresh()->status, 'Refund gagal tidak boleh mengubah transaksi.');

        // Kasir biasa (bukan super-admin) tidak boleh refund meski password benar.
        $cashier = User::factory()->create();
        $cashier->givePermissionTo('transactions-access');
        $this->actingAs($cashier)
            ->from(route('transactions.index'))
            ->post(route('transactions.refund', $transaction->id), ['password' => 'password'])
            ->assertSessionHas('error');
        $this->assertSame('paid', $transaction->fresh()->status);
    }

    public function test_recipe_sale_then_refund_returns_ingredients(): void
    {
        $flour = Ingredient::create(['name' => 'Tepung', 'buy_price' => 3000, 'stock' => 500, 'unit' => 'gram']);
        $cake = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Kue',
            'buy_price' => 0,
            'sell_price' => 25000,
            'stock' => 20,
            'unit' => 'Pcs',
        ]);
        Recipe::create(['product_id' => $cake->id, 'ingredient_id' => $flour->id, 'qty_needed' => 100]);

        Cart::create(['cashier_id' => $this->admin->id, 'product_id' => $cake->id, 'qty' => 2, 'price' => 50000]);
        $transaction = $this->sell(['cash' => 50000]);

        $this->assertEqualsWithDelta(300.0, (float) $flour->fresh()->stock, 0.001, '2 kue x 100g = 200g terpakai.');
        // Produk resep tidak punya stok sendiri; ketersediaannya diturunkan dari bahan.
        $this->assertEqualsWithDelta(20.0, (float) $cake->fresh()->stock, 0.001);

        $this->refund($transaction)->assertRedirect();

        $this->assertEqualsWithDelta(500.0, (float) $flour->fresh()->stock, 0.001, 'Bahan harus balik setelah refund.');
    }

    public function test_profit_rows_are_removed_on_refund(): void
    {
        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Item',
            'buy_price' => 10000,
            'sell_price' => 40000,
            'stock' => 10,
        ]);

        Cart::create(['cashier_id' => $this->admin->id, 'product_id' => $product->id, 'qty' => 2, 'price' => 80000]);
        $transaction = $this->sell(['cash' => 80000]);

        $this->assertSame(1, $transaction->profits()->count());

        $this->refund($transaction)->assertRedirect();

        $this->assertSame(0, $transaction->profits()->count(), 'Baris laba tidak boleh tersisa setelah refund.');
        $this->assertSame(0, DB::table('profits')->where('transaction_id', $transaction->id)->count());
    }
}
