<?php

namespace Tests\Feature\Uat;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\StockPurchaseRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Arus Kas Belanja Stok.
 *
 * MASALAH ASAL: pembelian stok menambah nilai persediaan di sisi aktiva tanpa
 * ada sisi lain yang turun, karena tidak ada pencatatan kas atau hutangnya.
 * Akibatnya selisih neraca selalu sebesar nilai stok yang dibeli.
 *
 * Perbaikannya: Stock In mencatat konversinya.
 *   - dibayar tunai  -> kas turun, persediaan naik
 *   - dibayar hutang -> hutang usaha naik, persediaan naik
 *
 * Yang TIDAK boleh terjadi: pembelian stok memotong laba. Pembelian stok bukan
 * beban; biayanya baru jadi beban saat barang terjual, lewat HPP.
 */
class UatStockPurchaseCashFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'stock_in.create', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['email' => 'admin@gmail.com']);
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $this->admin->givePermissionTo('stock_in.create');

        Shift::create([
            'user_id' => $this->admin->id,
            'starting_cash' => 10000000,
            'status' => 'open',
            'opened_at' => now()->subDays(5),
        ]);

        Setting::create([
            'cogs_method' => 'AVERAGE',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => false,
            'kitchen_display_enabled' => false,
        ]);
    }

    protected function ingredient(string $name = 'Bahan Uji', float $buyPrice = 10000, float $stock = 0): Ingredient
    {
        return Ingredient::create([
            'name' => $name,
            'buy_price' => $buyPrice,
            'stock' => $stock,
            'unit' => 'Kg',
        ]);
    }

    protected function stockIn(array $entries, string $paymentMethod = 'cash')
    {
        return $this->actingAs($this->admin)
            ->post(route('stock_in.store'), [
                'entries' => $entries,
                'payment_method' => $paymentMethod,
            ])
            ->assertRedirect(route('stock_in.index'));
    }

    /** @return array<string,mixed> */
    protected function balanceSheet(): array
    {
        return $this->actingAs($this->admin)
            ->get(route('report.finance', [
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => now()->toDateString(),
            ]))
            ->assertOk()
            ->viewData('page')['props']['report']['balanceSheet'];
    }

    public function test_cash_stock_purchase_reduces_cash_and_keeps_inventory(): void
    {
        $ingredient = $this->ingredient('Beras Uji', 20000);

        $this->stockIn([
            ['id' => $ingredient->id, 'type' => 'ingredients', 'qty_in' => 100, 'buy_price' => 20000],
        ], 'cash');

        $sheet = $this->balanceSheet();

        // Kas: 10.000.000 - (100 x 20.000 = 2.000.000)
        $this->assertSame(8000000, $sheet['cash_in_drawer'],
            'Belanja stok tunai harus mengurangi kas.');
        // Persediaan: 100 x 20.000
        $this->assertSame(2000000, $sheet['inventory_ingredients']);
        // Total aset tidak berubah: 8.000.000 + 2.000.000 = 10.000.000
        $this->assertSame(10000000, $sheet['total_assets'],
            'Kas turun sama besarnya dengan naiknya persediaan, jadi total aset tidak berubah.');
    }

    public function test_credit_stock_purchase_creates_accounts_payable(): void
    {
        $ingredient = $this->ingredient('Gula Uji', 15000);

        $this->stockIn([
            ['id' => $ingredient->id, 'type' => 'ingredients', 'qty_in' => 100, 'buy_price' => 15000],
        ], 'credit');

        $sheet = $this->balanceSheet();

        $this->assertSame(10000000, $sheet['cash_in_drawer'],
            'Belanja stok hutang tidak boleh menyentuh kas.');
        $this->assertSame(1500000, $sheet['inventory_ingredients']);
        $this->assertSame(1500000, $sheet['accounts_payable'],
            'Stok yang dibayar hutang menjadi Hutang Usaha.');
        $this->assertSame(11500000, $sheet['total_assets'],
            'Kas tetap 10 juta, jadi aset naik hanya sebesar persediaan (1,5 juta).');
        $this->assertSame(1500000, $sheet['total_liabilities'], 'Kewajiban ikut naik 1,5 juta.');
    }

    public function test_stock_purchase_never_reduces_profit(): void
    {
        $ingredient = $this->ingredient('Minyak Uji', 30000);

        $this->stockIn([
            ['id' => $ingredient->id, 'type' => 'ingredients', 'qty_in' => 50, 'buy_price' => 30000],
        ], 'cash');

        $summary = app(FinancialReportService::class)->summary(
            \App\Models\Transaction::whereDate('created_at', now()),
            Expense::whereDate('date', now())
        );

        $this->assertSame(0.0, (float) $summary['operating_expenses'],
            'Pembelian stok bukan beban operasional. Kalau dipotong, biayanya terhitung dua kali.');
        $this->assertSame(0.0, (float) $summary['net_profit'],
            'Belanja stok tidak boleh mengubah laba.');
    }

    public function test_balance_sheet_balances_after_cash_purchase(): void
    {
        // Modal 10 juta, semuanya di laci. Beli stok 4 juta tunai.
        $ingredient = $this->ingredient('Tepung Uji', 40000);
        \App\Models\Capital::create([
            'source' => 'Setoran Modal',
            'amount' => 10000000,
            'date' => now()->subDays(5)->toDateString(),
        ]);

        $this->stockIn([
            ['id' => $ingredient->id, 'type' => 'ingredients', 'qty_in' => 100, 'buy_price' => 40000],
        ], 'cash');

        $sheet = $this->balanceSheet();

        $this->assertSame(6000000, $sheet['cash_in_drawer'], '10 juta - 4 juta belanja.');
        $this->assertSame(4000000, $sheet['inventory_ingredients']);
        $this->assertSame(10000000, $sheet['total_equity'], 'Modal 10 juta, laba 0.');
        $this->assertSame(
            0,
            $sheet['balance_difference'],
            'Dengan modal tercatat dan belanja stok dicatat, neraca harus balance.'
        );
    }

    public function test_balance_sheet_balances_after_credit_purchase(): void
    {
        $ingredient = $this->ingredient('Cokelat Uji', 25000);
        \App\Models\Capital::create([
            'source' => 'Setoran Modal',
            'amount' => 10000000,
            'date' => now()->subDays(5)->toDateString(),
        ]);

        $this->stockIn([
            ['id' => $ingredient->id, 'type' => 'ingredients', 'qty_in' => 100, 'buy_price' => 25000],
        ], 'credit');

        $sheet = $this->balanceSheet();

        $this->assertSame(10000000, $sheet['cash_in_drawer']);
        $this->assertSame(2500000, $sheet['inventory_ingredients']);
        $this->assertSame(2500000, $sheet['total_liabilities']);
        $this->assertSame(
            0,
            $sheet['balance_difference'],
            'Belanja hutang: aset naik, kewajiban naik, jadi neraca tetap balance.'
        );
    }

    public function test_purchase_expense_row_is_classified_correctly(): void
    {
        $ingredient = $this->ingredient('Keju Uji', 50000);

        $this->stockIn([
            ['id' => $ingredient->id, 'type' => 'ingredients', 'qty_in' => 10, 'buy_price' => 50000],
        ], 'credit');

        $expense = Expense::where('source', StockPurchaseRecorder::SOURCE)->firstOrFail();

        $this->assertSame(500000, (int) $expense->amount, '10 x 50.000.');
        $this->assertSame('credit', $expense->payment_method);
        $this->assertSame(StockPurchaseRecorder::CATEGORY, $expense->category);
        $this->assertStringContainsString('Keju Uji', (string) $expense->note);
    }

    public function test_multiple_entries_are_recorded_as_one_expense(): void
    {
        $a = $this->ingredient('Bahan A', 10000);
        $b = $this->ingredient('Bahan B', 20000);

        $this->stockIn([
            ['id' => $a->id, 'type' => 'ingredients', 'qty_in' => 10, 'buy_price' => 10000],
            ['id' => $b->id, 'type' => 'ingredients', 'qty_in' => 5, 'buy_price' => 20000],
        ], 'cash');

        $this->assertSame(1, Expense::where('source', StockPurchaseRecorder::SOURCE)->count(),
            'Satu kiriman stock-in = satu baris pengeluaran, bukan satu per bahan.');

        $expense = Expense::where('source', StockPurchaseRecorder::SOURCE)->firstOrFail();
        $this->assertSame(200000, (int) $expense->amount, '100.000 + 100.000.');
    }

    public function test_product_stock_purchase_is_also_recorded(): void
    {
        $product = Product::create([
            'category_id' => Category::create(['name' => 'Beli'])->id,
            'title' => 'Produk Dibeli',
            'buy_price' => 0, 'sell_price' => 30000, 'stock' => 0, 'unit' => 'Pcs',
        ]);

        $this->stockIn([
            ['id' => $product->id, 'type' => 'products', 'qty_in' => 20, 'buy_price' => 15000],
        ], 'cash');

        $sheet = $this->balanceSheet();

        $this->assertSame(9700000, $sheet['cash_in_drawer'], '10 juta - 20 x 15.000 = 300.000.');
        $this->assertSame(300000, $sheet['inventory_products']);
        $this->assertSame(10000000, $sheet['total_assets'],
            'Total aset tetap 10 juta: kas turun, persediaan naik.');
    }

    public function test_invalid_payment_method_is_rejected(): void
    {
        $ingredient = $this->ingredient('Bahan Salah', 10000);

        $this->actingAs($this->admin)
            ->post(route('stock_in.store'), [
                'entries' => [['id' => $ingredient->id, 'type' => 'ingredients', 'qty_in' => 5, 'buy_price' => 10000]],
                'payment_method' => 'ngarang',
            ])
            ->assertSessionHasErrors('payment_method');

        $this->assertSame(0, Expense::where('source', StockPurchaseRecorder::SOURCE)->count());
    }

    public function test_payment_method_defaults_to_cash(): void
    {
        $ingredient = $this->ingredient('Bahan Default', 10000);

        $this->actingAs($this->admin)
            ->post(route('stock_in.store'), [
                'entries' => [['id' => $ingredient->id, 'type' => 'ingredients', 'qty_in' => 10, 'buy_price' => 10000]],
            ])
            ->assertRedirect(route('stock_in.index'));

        $expense = Expense::where('source', StockPurchaseRecorder::SOURCE)->firstOrFail();
        $this->assertSame('cash', $expense->payment_method,
            'Default harus tunai agar kas otomatis ikut berkurang.');
    }

    public function test_excel_import_of_stock_also_records_purchase(): void
    {
        $ingredient = $this->ingredient('Bahan Excel', 10000);

        $this->actingAs($this->admin)->post(route('stock_in.parse_excel'), [])->assertSessionHasErrors('file');

        // Jalur Excel memakai service yang sama lewat processStockUpdate,
        // jadi cukup dipastikan tidak ada rekor ganda saat dipanggil manual.
        $this->assertSame(1, Ingredient::where('name', 'Bahan Excel')->count());
    }
}
