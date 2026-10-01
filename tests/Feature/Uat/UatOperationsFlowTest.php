<?php

namespace Tests\Feature\Uat;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Hold;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockReservation;
use App\Models\Table;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialReportService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Operasional: shift & rekonsiliasi kas, kas keluar, order QR meja,
 * dan pengaruh pengeluaran terhadap laba.
 */
class UatOperationsFlowTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'shifts.index', 'expenses-access'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['email' => 'uat-ops@example.com']);
        $this->admin->assignRole($role);
        $this->admin->givePermissionTo(['transactions-access', 'shifts.index', 'expenses-access']);
    }

    protected function category(): Category
    {
        return Category::create(['name' => 'OPS-'.uniqid()]);
    }

    protected function openShift(int $startingCash = 0): Shift
    {
        return Shift::create([
            'user_id' => $this->admin->id,
            'starting_cash' => $startingCash,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    public function test_cashier_cannot_open_two_shifts_at_once(): void
    {
        $this->openShift(50000);

        $this->actingAs($this->admin)
            ->post(route('shifts.store'), ['starting_cash' => 100000])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHas('error');

        $this->assertSame(1, Shift::where('status', 'open')->count());
        $this->assertSame(50000, (int) Shift::where('status', 'open')->value('starting_cash'));
    }

    public function test_shift_close_reconciles_cash_sales_and_petty_cash(): void
    {
        $shift = $this->openShift(100000);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Kopi',
            'buy_price' => 5000,
            'sell_price' => 20000,
            'stock' => 50,
        ]);

        foreach ([2, 3] as $qty) {
            Cart::create(['cashier_id' => $this->admin->id, 'product_id' => $product->id, 'qty' => $qty, 'price' => $qty * 20000]);
            $this->actingAs($this->admin)->postJson(route('transactions.store'), [
                'payment_gateway' => 'cash',
                'cash' => $qty * 20000,
                'change' => 0,
            ])->assertOk();
        }

        // Kas keluar dari laci kasir.
        $this->actingAs($this->admin)
            ->from(route('transactions.index'))
            ->post(route('expenses.store'), ['name' => 'Beli es', 'amount' => 15000])
            ->assertRedirect();

        $expense = Expense::where('source', 'Kas Laci')->firstOrFail();
        $this->assertSame(15000, (int) $expense->amount);
        // Prefix mencantumkan source asalnya, supaya expense yang tercatat
        // di laci bisa dibedakan dari setoran bank atau modal keluar.
        $this->assertStringStartsWith('['.Expense::SOURCE_CASH.']', $expense->name);

        // Harap: 100.000 modal + 100.000 penjualan tunai - 15.000 kas keluar = 185.000
        $this->actingAs($this->admin)
            ->post(route('shifts.close'), ['total_cash_physical' => 185000])
            ->assertRedirect(route('shifts.print', $shift->id));

        $shift->refresh();
        $this->assertSame('closed', $shift->status);
        $this->assertSame(100000, (int) $shift->total_cash_sales);
        $this->assertSame(15000, (int) $shift->total_expense);
        $this->assertSame(185000, (int) $shift->total_cash_expected);
        $this->assertSame(185000, (int) $shift->total_cash_actual);
        $this->assertSame(0, (int) $shift->difference);
        $this->assertNotNull($shift->closed_at);
    }

    public function test_shift_shortage_is_recorded_as_negative_difference(): void
    {
        $shift = $this->openShift(50000);

        $this->actingAs($this->admin)
            ->post(route('shifts.close'), ['total_cash_physical' => 45000])
            ->assertRedirect();

        $shift->refresh();
        $this->assertSame(50000, (int) $shift->total_cash_expected);
        $this->assertSame(45000, (int) $shift->total_cash_actual);
        $this->assertSame(-5000, (int) $shift->difference, 'Selisih kurang harus negatif.');
    }

    public function test_refunded_transaction_is_excluded_from_shift_cash(): void
    {
        $shift = $this->openShift(0);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Teh',
            'buy_price' => 2000,
            'sell_price' => 10000,
            'stock' => 20,
        ]);

        Cart::create(['cashier_id' => $this->admin->id, 'product_id' => $product->id, 'qty' => 2, 'price' => 20000]);
        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 20000, 'change' => 0,
        ])->assertOk();

        $transaction = Transaction::latest('id')->firstOrFail();
        $this->assertSame(20000, (int) $transaction->shift_id === $shift->id ? 20000 : 0);

        $this->actingAs($this->admin)
            ->from(route('transactions.index'))
            ->post(route('transactions.refund', $transaction->id), ['password' => 'password'])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('shifts.close'), ['total_cash_physical' => 0])
            ->assertRedirect();

        $shift->refresh();
        $this->assertSame(0, (int) $shift->total_cash_sales, 'Transaksi refund tidak boleh dihitung sebagai penjualan shift.');
    }

    public function test_operating_expense_reduces_net_profit_but_capital_withdrawal_does_not(): void
    {
        $this->openShift(0);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Item',
            'buy_price' => 10000,
            'sell_price' => 50000,
            'stock' => 20,
        ]);

        Cart::create(['cashier_id' => $this->admin->id, 'product_id' => $product->id, 'qty' => 2, 'price' => 100000]);
        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash', 'cash' => 100000, 'change' => 0,
        ])->assertOk();

        // Beban operasional: 15.000
        Expense::create([
            'name' => 'Beban Listrik',
            'amount' => 15000,
            'date' => now()->toDateString(),
            'category' => 'Operasional',
            'user_id' => $this->admin->id,
        ]);
        // Pelunasan hutang: bukan beban operasional
        Expense::create([
            'name' => 'Pelunasan Hutang Supplier',
            'amount' => 5000,
            'date' => now()->toDateString(),
            'category' => 'Pelunasan Hutang',
            'user_id' => $this->admin->id,
        ]);
        // Modal keluar: penarikan pemilik, bukan beban
        Expense::create([
            'name' => 'Ambil Modal',
            'amount' => 25000,
            'date' => now()->toDateString(),
            'category' => 'Operasional',
            'source' => 'Modal Luar',
            'user_id' => $this->admin->id,
        ]);

        $summary = app(FinancialReportService::class)->summary(
            Transaction::whereDate('created_at', now()),
            Expense::whereDate('date', now())
        );

        $this->assertSame(100000, (int) $summary['net_revenue']);
        $this->assertSame(20000, (int) $summary['hpp']);
        $this->assertSame(80000, (int) $summary['gross_profit_before_platform']);
        $this->assertSame(15000, (int) $summary['operating_expenses'], 'Hanya beban operasional yang dihitung.');
        $this->assertSame(65000, (int) $summary['net_profit']);
    }

    public function test_public_order_creates_hold_reserves_stock_and_occupies_table(): void
    {
        $table = Table::create(['name' => 'Meja 1', 'status' => 'available']);

        $product = Product::create([
            'category_id' => $this->category()->id,
            'title' => 'Nasi Goreng',
            'buy_price' => 12000,
            'sell_price' => 28000,
            'stock' => 10,
            'barcode' => 'BRG-001',
        ]);

        $response = $this->postJson(route('public.menu.order', ['table_id' => $table->id]), [
            'cart_items' => [['product_id' => $product->id, 'qty' => 2, 'notes' => 'pedas']],
            'customer_name' => 'Rina',
            'table_id' => $table->id,
            'total' => 56000,
        ])->assertOk();

        $response->assertJsonPath('success', true);
        $this->assertSame(56000, (int) $response->json('grand_total'));

        $hold = Hold::firstOrFail();
        $this->assertSame('Q-001', $hold->queue_number);
        $this->assertStringContainsString('Rina', $hold->customer_name);
        $this->assertStringContainsString('#', $hold->customer_name);

        // Stok BELUM berkurang, hanya direservasi.
        $this->assertEqualsWithDelta(10.0, (float) $product->fresh()->stock, 0.001);
        $this->assertSame(1, StockReservation::where('hold_id', $hold->id)->where('status', 'active')->count());
        $this->assertEqualsWithDelta(2.0, (float) StockReservation::where('hold_id', $hold->id)->value('quantity'), 0.001);

        $this->assertSame('occupied', $table->fresh()->status);
    }

    public function test_second_public_order_on_same_table_merges_into_existing_hold(): void
    {
        $table = Table::create(['name' => 'Meja 2', 'status' => 'available']);

        $nasi = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Nasi',
            'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 50, 'barcode' => 'N-1',
        ]);
        $ayam = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Ayam',
            'buy_price' => 15000, 'sell_price' => 25000, 'stock' => 50, 'barcode' => 'A-1',
        ]);

        $this->postJson(route('public.menu.order', ['table_id' => $table->id]), [
            'cart_items' => [['product_id' => $nasi->id, 'qty' => 2]],
            'customer_name' => 'Rina',
            'table_id' => $table->id,
            'total' => 20000,
        ])->assertOk();

        $this->postJson(route('public.menu.order', ['table_id' => $table->id]), [
            'cart_items' => [['product_id' => $ayam->id, 'qty' => 1]],
            'customer_name' => 'Rina',
            'table_id' => $table->id,
            'total' => 25000,
        ])->assertOk();

        $this->assertSame(1, Hold::count(), 'Meja yang sama harus jadi satu hold, bukan dua.');

        $hold = Hold::firstOrFail();
        $this->assertSame(45000, (int) $hold->total);
        $this->assertCount(2, $hold->cart_data);

        // Reservasi digabung: 2 nasi + 1 ayam, tidak dobel.
        $this->assertSame(2, StockReservation::where('hold_id', $hold->id)->where('status', 'active')->count());
    }

    public function test_public_order_beyond_stock_is_rejected(): void
    {
        $table = Table::create(['name' => 'Meja 3', 'status' => 'available']);
        $product = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Langka',
            'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 2, 'barcode' => 'L-1',
        ]);

        $this->postJson(route('public.menu.order', ['table_id' => $table->id]), [
            'cart_items' => [['product_id' => $product->id, 'qty' => 5]],
            'customer_name' => 'Budi',
            'table_id' => $table->id,
            'total' => 50000,
        ])->assertStatus(422);

        $this->assertSame(0, Hold::count(), 'Pesan gagal tidak boleh meninggalkan hold.');
        $this->assertEqualsWithDelta(2.0, (float) $product->fresh()->stock, 0.001);
        $this->assertSame('available', $table->fresh()->status);
    }

    public function test_paying_a_hold_consumes_stock_and_frees_the_table(): void
    {
        $table = Table::create(['name' => 'Meja 4', 'status' => 'available']);
        $product = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Soto',
            'buy_price' => 9000, 'sell_price' => 20000, 'stock' => 10, 'barcode' => 'S-1',
        ]);
        $this->openShift(0);

        $this->postJson(route('public.menu.order', ['table_id' => $table->id]), [
            'cart_items' => [['product_id' => $product->id, 'qty' => 3]],
            'customer_name' => 'Tono',
            'table_id' => $table->id,
            'total' => 60000,
        ])->assertOk();

        $hold = Hold::firstOrFail();
        $this->assertEqualsWithDelta(10.0, (float) $product->fresh()->stock, 0.001);

        // Alur nyata: kasir lanjutkan pesanan dari hold ke keranjangnya.
        $this->actingAs($this->admin)
            ->from(route('transactions.index'))
            ->post(route('transactions.resume', $hold->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, Cart::where('cashier_id', $this->admin->id)->count(), 'Keranjang harus terisi dari hold.');
        $this->assertEqualsWithDelta(3.0, (float) Cart::where('cashier_id', $this->admin->id)->sum('qty'), 0.001);

        $this->actingAs($this->admin)->postJson(route('transactions.store'), [
            'payment_gateway' => 'cash',
            'cash' => 60000,
            'change' => 0,
            'hold_id' => $hold->id,
            'table_name' => $table->name,
        ])->assertOk();

        $this->assertEqualsWithDelta(7.0, (float) $product->fresh()->stock, 0.001, 'Stok harus benar-benar berkurang saat dibayar.');
        $this->assertSame('available', $table->fresh()->status);
        $this->assertSame(0, StockReservation::where('status', 'active')->count());
        $this->assertSame(0, Hold::count(), 'Hold lunas harus dihapus.');
    }

    public function test_hold_cannot_oversell_stock_reserved_by_another_table(): void
    {
        $tableA = Table::create(['name' => 'Meja A', 'status' => 'available']);
        $tableB = Table::create(['name' => 'Meja B', 'status' => 'available']);
        $product = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Scarf',
            'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 5, 'barcode' => 'X-1',
        ]);

        $this->postJson(route('public.menu.order', ['table_id' => $tableA->id]), [
            'cart_items' => [['product_id' => $product->id, 'qty' => 4]],
            'customer_name' => 'A',
            'table_id' => $tableA->id,
            'total' => 40000,
        ])->assertOk();

        // Tersisa 1 setelah reservasi Meja A; Meja B minta 3 harus ditolak.
        $this->postJson(route('public.menu.order', ['table_id' => $tableB->id]), [
            'cart_items' => [['product_id' => $product->id, 'qty' => 3]],
            'customer_name' => 'B',
            'table_id' => $tableB->id,
            'total' => 30000,
        ])->assertStatus(422);

        $this->assertSame(1, Hold::count());
        $this->assertEqualsWithDelta(5.0, (float) $product->fresh()->stock, 0.001);
    }

    public function test_hold_cashier_can_discard_and_stock_reservation_releases(): void
    {
        $table = Table::create(['name' => 'Meja 5', 'status' => 'available']);
        $product = Product::create([
            'category_id' => $this->category()->id, 'title' => 'Juice',
            'buy_price' => 4000, 'sell_price' => 15000, 'stock' => 10, 'barcode' => 'J-1',
        ]);

        $this->postJson(route('public.menu.order', ['table_id' => $table->id]), [
            'cart_items' => [['product_id' => $product->id, 'qty' => 2]],
            'customer_name' => 'Dita',
            'table_id' => $table->id,
            'total' => 30000,
        ])->assertOk();

        $hold = Hold::firstOrFail();
        $this->assertSame(1, StockReservation::where('status', 'active')->count());

        $this->actingAs($this->admin)
            ->delete(route('transactions.destroyHold', $hold->id))
            ->assertRedirect();

        $this->assertSame(0, Hold::count());
        $this->assertSame(0, StockReservation::where('status', 'active')->count());
        $this->assertSame('available', $table->fresh()->status);
        $this->assertEqualsWithDelta(10.0, (float) $product->fresh()->stock, 0.001);
    }
}
