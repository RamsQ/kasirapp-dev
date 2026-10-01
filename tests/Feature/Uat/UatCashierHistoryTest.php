<?php

namespace Tests\Feature\Uat;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Riwayat Transaksi versi kasir.
 *
 * Dua hal yang dijaga di sini.
 *
 *  1. HALAMAN KASIR DAN HALAMAN SIDEBAR HARUS SINKRON.
 *
 *     Ada dua route: /transactions/history (di sidebar, penuh kartu) dan
 *     /transactions/riwayat (full-screen, dibuka dari kasir). Keduanya
 *     menampilkan "riwayat transaksi", jadi kalau sumber datanya berbeda,
 *     orang akan melihat dua jawaban berbeda untuk pertanyaan yang sama dan
 *     tidak akan tahu mana yang benar. Test ini membandingkan keduanya
 *     langsung, invoice demi invoice, untuk setiap preset filter.
 *
 *  2. AKSES KASIR TETAP SAMA.
 *
 *     Kasir biasa hanya boleh melihat transaksinya sendiri, bukan seluruh
 *     toko. Menambah halaman baru tidak boleh membuka akses ini secara tidak sengaja.
 */
class UatCashierHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions.index', 'transactions-access', 'transactions.print'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $kasirRole = Role::firstOrCreate(['name' => 'kasir', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['email' => 'admin@gmail.com', 'name' => 'Super Admin']);
        $this->admin->assignRole($superAdmin);
        $this->admin->givePermissionTo('transactions.index');

        $this->kasir = User::factory()->create(['email' => 'kasir@demo.test', 'name' => 'Kasir Uji']);
        $this->kasir->assignRole($kasirRole);
        $this->kasir->givePermissionTo('transactions.index');

        Setting::create([
            'cogs_method' => 'FIFO',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => false,
            'kitchen_display_enabled' => false,
        ]);

        $category = Category::create(['name' => 'Makanan']);
        $this->product = Product::create([
            'category_id' => $category->id,
            'title' => 'Nasi Goreng',
            'type' => 'single',
            'sell_price' => 25000,
            'buy_price' => 12000,
            'stock' => 100,
        ]);
    }

    protected function sale(User $cashier, int $grandTotal, string $paymentMethod, int $daysAgo, string $invoice): Transaction
    {
        $transaction = new Transaction([
            'cashier_id' => $cashier->id,
            'invoice' => $invoice,
            'cash' => $grandTotal,
            'change' => 0,
            'discount' => 0,
            'taxable_amount' => $grandTotal,
            'tax_amount' => 0,
            'grand_total' => $grandTotal,
            'payment_method' => $paymentMethod,
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);

        $transaction->forceFill([
            'created_at' => CarbonImmutable::now()->subDays($daysAgo),
        ])->save();

        TransactionDetail::create([
            'transaction_id' => $transaction->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => $grandTotal,
            'buy_price' => 12000,
        ]);

        return $transaction;
    }

    /** @return array<string,mixed> */
    protected function sidebar(array $query = []): array
    {
        return $this->actingAs($this->admin)
            ->get(route('transactions.history', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    /** @return array<string,mixed> */
    protected function kasir(array $query = []): array
    {
        return $this->actingAs($this->admin)
            ->get(route('transactions.history.cashier', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    // ------------------------------------------------------------------
    // Sinkronisasi
    // ------------------------------------------------------------------

    public function test_both_pages_return_the_same_invoices(): void
    {
        $this->sale($this->admin, 25000, 'cash', 0, 'SYNC-001');
        $this->sale($this->admin, 30000, 'qris_manual', 1, 'SYNC-002');
        $this->sale($this->admin, 45000, 'midtrans', 3, 'SYNC-003');

        $sidebar = array_column($this->sidebar()['transactions']['data'], 'invoice');
        $kasir = array_column($this->kasir()['transactions']['data'], 'invoice');

        $this->assertSame($sidebar, $kasir,
            'Halaman sidebar dan halaman kasir harus menampilkan invoice yang sama persis.');
    }

    public function test_both_pages_return_the_same_rows_under_every_filter(): void
    {
        $this->sale($this->admin, 25000, 'cash', 0, 'FILTER-A');
        $this->sale($this->admin, 30000, 'qris_manual', 2, 'FILTER-B');
        $this->sale($this->admin, 45000, 'midtrans', 5, 'FILTER-C');
        $this->sale($this->admin, 50000, 'xendit', 8, 'FILTER-D');

        $filters = [
            [],
            ['payment_method' => 'cash'],
            ['payment_method' => 'qris_manual'],
            ['invoice' => 'FILTER-B'],
            ['start_date' => CarbonImmutable::now()->subDays(3)->toDateString()],
            ['end_date' => CarbonImmutable::now()->subDays(5)->toDateString()],
            [
                'start_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
                'end_date' => CarbonImmutable::now()->toDateString(),
                'payment_method' => 'midtrans',
            ],
        ];

        foreach ($filters as $index => $filter) {
            $sidebar = $this->sidebar($filter);
            $kasir = $this->kasir($filter);

            $this->assertSame(
                array_column($sidebar['transactions']['data'], 'invoice'),
                array_column($kasir['transactions']['data'], 'invoice'),
                "Filter ke-{$index} menghasilkan daftar berbeda di kedua halaman."
            );

            $this->assertSame(
                $sidebar['transactions']['total'],
                $kasir['transactions']['total'],
                "Filter ke-{$index}: jumlah total berbeda antara halaman sidebar dan kasir."
            );

            $this->assertSame(
                $sidebar['filters'],
                $kasir['filters'],
                "Filter ke-{$index}: nilai filter yang dikembalikan berbeda."
            );
        }
    }

    public function test_both_pages_return_the_same_amounts_per_row(): void
    {
        $this->sale($this->admin, 27500, 'cash', 0, 'AMOUNT-1');
        $this->sale($this->admin, 31250, 'qris_manual', 1, 'AMOUNT-2');

        $sidebar = $this->sidebar()['transactions']['data'];
        $kasir = $this->kasir()['transactions']['data'];

        $ringkas = fn (array $rows) => array_map(fn ($row) => [
            $row['invoice'],
            $row['grand_total'],
            $row['taxable_amount'],
            $row['payment_method'],
            $row['payment_status'],
            $row['status'],
            $row['total_profit'],
            $row['total_items'],
            $row['receipt_url'],
        ], $rows);

        $this->assertSame($ringkas($sidebar), $ringkas($kasir),
            'Nominal, metode, status, profit, dan struk digital harus identik di kedua halaman.');
    }

    public function test_both_pages_share_the_same_pagination(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->sale($this->admin, 20000 + $i, 'cash', 0, sprintf('PAGE-%03d', $i));
        }

        $sidebar = $this->sidebar()['transactions'];
        $kasir = $this->kasir()['transactions'];

        $this->assertSame(14, $sidebar['total']);
        $this->assertSame($sidebar['total'], $kasir['total']);
        $this->assertSame($sidebar['last_page'], $kasir['last_page']);
        $this->assertCount(10, $sidebar['data']);
        $this->assertCount(10, $kasir['data']);
        $this->assertSame(
            $sidebar['data'][0]['invoice'],
            $kasir['data'][0]['invoice'],
            'Halaman pertama kedua versi harus dimulai dari transaksi yang sama.'
        );
    }

    public function test_excluding_unpaid_and_refunded_still_holds_on_both_pages(): void
    {
        $this->sale($this->admin, 25000, 'cash', 0, 'VALID-1');

        $pending = new Transaction([
            'cashier_id' => $this->admin->id,
            'invoice' => 'PENDING-1',
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'tax_amount' => 0,
            'grand_total' => 99000,
            'taxable_amount' => 99000,
            'payment_method' => 'qris_manual',
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
        $pending->forceFill(['created_at' => CarbonImmutable::now()])->save();

        $refunded = new Transaction([
            'cashier_id' => $this->admin->id,
            'invoice' => 'REFUNDED-1',
            'cash' => 77000,
            'change' => 0,
            'discount' => 0,
            'tax_amount' => 0,
            'grand_total' => 77000,
            'taxable_amount' => 77000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'refunded',
        ]);
        $refunded->forceFill(['created_at' => CarbonImmutable::now()])->save();

        foreach ([$this->sidebar(), $this->kasir()] as $props) {
            $invoices = array_column($props['transactions']['data'], 'invoice');

            $this->assertSame(['VALID-1'], $invoices,
                'Halaman baru tidak boleh membuat transaksi pending atau refunded ikut tampil.');
        }
    }

    // ------------------------------------------------------------------
    // Akses
    // ------------------------------------------------------------------

    public function test_cashier_only_sees_their_own_transactions_on_both_pages(): void
    {
        $this->sale($this->admin, 25000, 'cash', 0, 'ADMIN-1');
        $this->sale($this->kasir, 26000, 'cash', 1, 'KASIR-1');

        $sidebar = array_column(
            $this->actingAs($this->kasir)->get(route('transactions.history'))->viewData('page')['props']['transactions']['data'],
            'invoice'
        );
        $kasir = array_column(
            $this->actingAs($this->kasir)->get(route('transactions.history.cashier'))->viewData('page')['props']['transactions']['data'],
            'invoice'
        );

        $this->assertSame(['KASIR-1'], $sidebar, 'Kasir tidak boleh melihat transaksi admin.');
        $this->assertSame($sidebar, $kasir, 'Halaman kasir tidak boleh lebih longgar daripada halaman sidebar.');
    }

    public function test_super_admin_sees_everything_on_both_pages(): void
    {
        $this->sale($this->admin, 25000, 'cash', 0, 'ADMIN-1');
        $this->sale($this->kasir, 26000, 'cash', 1, 'KASIR-1');

        $this->assertCount(2, $this->sidebar()['transactions']['data']);
        $this->assertCount(2, $this->kasir()['transactions']['data']);
    }

    public function test_cashier_page_requires_login(): void
    {
        $this->get(route('transactions.history.cashier'))->assertRedirect(route('login'));
    }

    public function test_cashier_page_requires_the_transactions_permission(): void
    {
        $staff = User::factory()->create(['email' => 'staff@demo.test']);
        $staff->assignRole(Role::firstOrCreate(['name' => 'gudang', 'guard_name' => 'web']));

        $this->actingAs($staff)
            ->get(route('transactions.history.cashier'))
            ->assertForbidden();
    }
}
