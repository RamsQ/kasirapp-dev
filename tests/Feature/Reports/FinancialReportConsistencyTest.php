<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\OnlineSetting;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinancialReportConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('reports.index', 'web');
        Permission::findOrCreate('shifts.index', 'web');
        Permission::findOrCreate('transactions.index', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('reports.index');
        $this->user->givePermissionTo('shifts.index');
        $this->user->givePermissionTo('transactions.index');

        $category = Category::create([
            'name' => 'Kategori Laporan',
            'description' => 'Kategori pengujian laporan',
            'image' => 'category.png',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'FIN-001',
            'title' => 'Produk Laporan',
            'description' => 'Produk untuk pengujian laporan',
            'buy_price' => 4000,
            'sell_price' => 10000,
            'stock' => 50,
        ]);
    }

    public function test_dashboard_activity_summary_is_scoped_to_today(): void
    {
        $customer = Customer::create([
            'name' => 'Pelanggan Hari Ini',
            'no_telp' => '081200000000',
            'address' => 'Alamat pelanggan',
        ]);

        $old = $this->createTransaction('TRX-DASH-OLD', 'paid');
        $old->update([
            'customer_id' => $customer->id,
        ]);
        $old->forceFill(['created_at' => now()->subDays(10)])->save();
        $old->details()->create([
            'product_id' => $this->product->id,
            'qty' => 5,
            'price' => 50000,
            'buy_price' => 20000,
        ]);
        $old->profits()->create(['total' => 30000]);

        $today = $this->createTransaction('TRX-DASH-TODAY', 'paid');
        $today->update(['customer_id' => $customer->id]);
        $today->details()->create([
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 10000,
            'buy_price' => 4000,
        ]);
        $today->profits()->create(['total' => 6000]);

        Expense::create([
            'user_id' => $this->user->id,
            'name' => 'Beban hari ini',
            'category' => 'Operasional',
            'amount' => 1000,
            'date' => now()->toDateString(),
            'source' => 'Kas Laci',
        ]);

        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('totalRevenue', 10000)
                ->where('totalTaxCollected', 1100)
                ->where('totalProfit', 3500)
                ->where('totalTransactions', 1)
                ->where('todayBilledRevenue', 11100)
                ->where('todayCashRevenue', 11100)
                ->where('todayDigitalRevenue', 0)
                ->where('topProducts.0.qty', 1)
                ->where('recentTransactions.0.invoice', 'TRX-DASH-TODAY')
                ->where('topCustomers.0.orders', 1)
                ->where('topCustomers.0.total', 10000)
            );
    }

    public function test_canonical_financial_summary_is_shared_by_reports(): void
    {
        $paid = $this->createTransaction('TRX-PAID', 'paid');
        $paid->details()->create([
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 10000,
            'buy_price' => 4000,
        ]);
        $paid->profits()->create(['total' => 4500]);

        $pending = $this->createTransaction('TRX-PENDING', 'pending');
        $pending->details()->create([
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 99000,
            'buy_price' => 40000,
        ]);
        $pending->profits()->create(['total' => 59000]);

        $refunded = $this->createTransaction('TRX-REFUNDED', 'paid', 'refunded');
        $refunded->details()->create([
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 77000,
            'buy_price' => 30000,
        ]);
        $refunded->profits()->create(['total' => 47000]);

        Expense::create([
            'user_id' => $this->user->id,
            'name' => 'Beban listrik',
            'category' => 'Operasional',
            'amount' => 1000,
            'date' => now()->toDateString(),
            'source' => 'Kas Laci',
        ]);
        Expense::create([
            'user_id' => $this->user->id,
            'name' => 'Penarikan modal',
            'category' => 'Operasional',
            'amount' => 7000,
            'date' => now()->toDateString(),
            'source' => 'Modal Luar',
        ]);

        $financials = app(FinancialReportService::class)->summary(
            Transaction::query(),
            Expense::query()->where('category', '!=', 'Pelunasan Hutang')
        );

        $this->assertSame(11100.0, $financials['billed_revenue']);
        $this->assertSame(1100.0, $financials['tax_collected']);
        $this->assertSame(10000.0, $financials['net_revenue']);
        $this->assertSame(1500.0, $financials['app_expenses']);
        $this->assertSame(4000.0, $financials['hpp']);
        $this->assertSame(6000.0, $financials['gross_profit_before_platform']);
        $this->assertSame(4500.0, $financials['contribution_profit']);
        $this->assertSame(1000.0, $financials['operating_expenses']);
        $this->assertSame(3500.0, $financials['net_profit']);
        $this->assertSame(1, $financials['transaction_count']);
        $this->assertSame(0.0, $financials['profit_variance']);

        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('totalRevenue', 10000)
                ->where('totalTaxCollected', 1100)
                ->where('totalProfit', 3500)
                ->where('totalTransactions', 1)
            );

        $this->actingAs($this->user)
            ->get(route('reports.sales.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Reports/Sales')
                ->where('summary.revenue_total', 11100)
                ->where('summary.net_sales_total', 10000)
                ->where('summary.tax_total', 1100)
                ->where('summary.hpp', 4000)
                ->where('summary.profit_total', 4500)
                ->where('summary.operating_expense', 1000)
                ->where('summary.net_profit', 3500)
                ->where('summary.profit_variance', 0)
            );

        $this->actingAs($this->user)
            ->get(route('reports.profits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Reports/Profit')
                ->where('summary.gross_sales', 10000)
                ->where('summary.billed_sales', 11100)
                ->where('summary.tax_collected', 1100)
                ->where('summary.total_hpp', 4000)
                ->where('summary.gross_profit', 6000)
                ->where('summary.gross_margin', 4500)
                ->where('summary.operating_expense', 1000)
                ->where('summary.profit_total', 3500)
            );
    }

    public function test_shift_reports_exclude_pending_transactions(): void
    {
        $shift = Shift::create([
            'user_id' => $this->user->id,
            'starting_cash' => 0,
            'status' => 'closed',
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
        ]);

        $paid = $this->createTransaction('TRX-SHIFT-PAID', 'paid');
        $paid->update(['shift_id' => $shift->id, 'payment_method' => 'cash', 'grand_total' => 10000]);
        $pending = $this->createTransaction('TRX-SHIFT-PENDING', 'pending');
        $pending->update(['shift_id' => $shift->id, 'payment_method' => 'cash', 'grand_total' => 50000]);
        $refunded = $this->createTransaction('TRX-SHIFT-REFUNDED', 'paid', 'refunded');
        $refunded->update(['shift_id' => $shift->id, 'payment_method' => 'cash', 'grand_total' => 70000]);
        Expense::create([
            'user_id' => $this->user->id,
            'name' => 'Modal keluar',
            'amount' => 5000,
            'date' => now()->toDateString(),
            'source' => 'Modal Luar',
            'category' => 'Operasional',
        ]);

        $this->actingAs($this->user)
            ->get(route('shifts.print', $shift->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shift.total_cash_sales', 10000)
                ->where('shift.petty_cash_out', 0)
            );

        $this->actingAs($this->user)
            ->get(route('shifts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shifts.data.0.total_cash_sales', 10000)
            );
    }

    public function test_finance_report_uses_the_same_net_profit_formula(): void
    {
        $transaction = $this->createTransaction('TRX-FINANCE', 'paid');
        $transaction->details()->create([
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 10000,
            'buy_price' => 4000,
        ]);
        $transaction->profits()->create(['total' => 4500]);

        Expense::create([
            'user_id' => $this->user->id,
            'name' => 'Beban operasional',
            'category' => 'Operasional',
            'amount' => 1000,
            'date' => now()->toDateString(),
            'source' => 'Kas Laci',
        ]);

        $this->actingAs($this->user)
            ->get(route('report.finance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.revenue', 10000)
                ->where('report.taxCollected', 1100)
                ->where('report.hpp', 4000)
                ->where('report.grossProfit', 6000)
                ->where('report.netProfit', 3500)
            );
    }

    public function test_profit_report_uses_expenses_for_the_selected_period_instead_of_only_today(): void
    {
        $transaction = $this->createTransaction('TRX-OLD', 'paid');
        $transaction->details()->create([
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 10000,
            'buy_price' => 4000,
        ]);
        $transaction->profits()->create(['total' => 6000]);

        Expense::create([
            'user_id' => $this->user->id,
            'name' => 'Beban periode laporan',
            'category' => 'Operasional',
            'amount' => 1500,
            'date' => now()->subDays(2)->toDateString(),
            'source' => 'Kas Laci',
        ]);

        $this->actingAs($this->user)
            ->get(route('reports.profits.index', [
                'start_date' => now()->subDays(3)->toDateString(),
                'end_date' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.operating_expense', 1500)
                ->where('summary.profit_total', 3000)
            );
    }

    public function test_hold_rebuilds_cart_financial_fields_from_server_data(): void
    {
        $cart = \App\Models\Cart::create([
            'cashier_id' => $this->user->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 10000,
            'notes' => null,
        ]);

        $this->actingAs($this->user)
            ->post(route('transactions.hold'), [
                'customer_name' => 'Pelanggan Hold',
                'cart_items' => [[
                    'id' => $cart->id,
                    'product_id' => $this->product->id,
                    'qty' => 99,
                    'price' => 1,
                    'notes' => 'BONUS PROMO',
                ]],
                'total' => 1,
            ])
            ->assertRedirect();

        $hold = \App\Models\Hold::latest('id')->first();
        $this->assertSame(10000.0, (float) $hold->cart_data[0]['price']);
        $this->assertNull($hold->cart_data[0]['notes']);
        $this->assertSame(10000, (int) $hold->total);
    }

    public function test_online_platform_markup_and_fee_are_snapshotted_server_side(): void
    {
        $platform = OnlineSetting::create([
            'name' => 'Marketplace Test',
            'markup_percent' => 10,
            'additional_fee' => 2000,
            'is_active' => true,
        ]);
        Shift::create([
            'user_id' => $this->user->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->post(route('transactions.addToCart'), [
                'product_id' => $this->product->id,
                'online_platform_id' => $platform->id,
            ])
            ->assertRedirect();

        $cart = \App\Models\Cart::first();
        $this->assertSame($platform->id, $cart->online_platform_id);
        $this->assertSame(1000.0, (float) $cart->platform_markup);
        $this->assertSame(2000.0, (float) $cart->platform_fee);
        $this->assertSame(13000.0, (float) $cart->price);

        $this->actingAs($this->user)
            ->postJson(route('transactions.store'), [
                'cash' => 20000,
                'payment_gateway' => 'cash',
                'table_name' => 'TAKE AWAY',
                'total_markup' => 999999,
                'total_fee' => 999999,
            ])
            ->assertOk()
            ->assertJsonPath('grand_total', 13000);

        $transaction = Transaction::latest('id')->first();
        $this->assertSame(1000, (int) $transaction->total_markup);
        $this->assertSame(2000, (int) $transaction->total_fee);
        $this->assertSame('Marketplace Test', $transaction->online_platform);
        $this->assertSame(6000, (int) $transaction->profits()->value('total'));
    }

    public function test_profit_repair_rebuilds_multi_item_transactions_with_discounts_and_platform_fees(): void
    {
        $transaction = Transaction::create([
            'cashier_id' => $this->user->id,
            'invoice' => 'TRX-REPAIR',
            'cash' => 20000,
            'change' => 0,
            'discount' => 1000,
            'taxable_amount' => 19000,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'grand_total' => 19000,
            'payment_method' => 'midtrans',
            'payment_status' => 'paid',
            'status' => 'paid',
            'total_markup' => 1000,
            'total_fee' => 1000,
        ]);
        $transaction->details()->create([
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 10000,
            'buy_price' => 0,
        ]);
        $transaction->details()->create([
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 10000,
            'buy_price' => 0,
        ]);
        $transaction->profits()->create(['total' => 1]);
        $transaction->profits()->create(['total' => 1]);

        $this->artisan('repair:profit')->assertExitCode(0);

        $this->assertDatabaseCount('profits', 1);
        $this->assertSame(9000, (int) $transaction->profits()->value('total'));
        $this->assertSame(4000, (int) $transaction->details()->first()->buy_price);
    }

    public function test_transaction_specific_filters_do_not_charge_unallocated_period_expenses(): void
    {
        $transaction = $this->createTransaction('TRX-FILTER', 'paid');
        $transaction->details()->create([
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 10000,
            'buy_price' => 4000,
        ]);
        $transaction->profits()->create(['total' => 4500]);
        Expense::create([
            'user_id' => $this->user->id,
            'name' => 'Beban periode',
            'category' => 'Operasional',
            'amount' => 1000,
            'date' => now()->toDateString(),
            'source' => 'Kas Laci',
        ]);

        $this->actingAs($this->user)
            ->get(route('reports.profits.index', ['invoice' => 'TRX-FILTER']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.operating_expense', 0)
                ->where('summary.profit_total', 4500)
                ->where('summary.net_profit_basis', 'transaction')
            );
    }

    private function createTransaction(string $invoice, string $paymentStatus, string $status = 'paid'): Transaction
    {
        return Transaction::create([
            'cashier_id' => $this->user->id,
            'invoice' => $invoice,
            'cash' => 11100,
            'change' => 0,
            'discount' => 0,
            'taxable_amount' => 10000,
            'tax_rate' => 11,
            'tax_amount' => 1100,
            'grand_total' => 11100,
            'payment_method' => 'cash',
            'payment_status' => $paymentStatus,
            'status' => $status,
            'total_markup' => 500,
            'total_fee' => 1000,
        ]);
    }
}
