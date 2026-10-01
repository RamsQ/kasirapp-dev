<?php

namespace Tests\Feature\Uat;

use App\Models\Capital;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Neraca: struktur akun harus benar dan angkanya harus benar-benar balance.
 *
 * Dua bug yang diperbaiki di sini:
 *
 *  1. Uang NON-TUNAI (QRIS/Midtrans/Xendit/transfer) tidak pernah masuk aset.
 *     Variabel digitalRevenue dihitung tapi tidak dipakai di neraca, sehingga
 *     setiap penjualan digital hilang dari sisi aset.
 *
 *  2. Neraca mencampur arus periode (kas, laba) dengan stock sepanjang masa
 *     (utang, pajak, modal). Akibatnya "selisih neraca" tidak pernah berarti
 *     apa-apa. Neraca adalah foto pada satu tanggal, jadi semuanya kumulatif
 *     sampai tanggal akhir.
 */
class UatBalanceSheetStructureTest extends TestCase
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
            'starting_cash' => 1000000,
            'status' => 'open',
            'opened_at' => now()->subDays(10),
        ]);
    }

    /** @return array<string,mixed> */
    protected function balanceSheet(array $params = []): array
    {
        return $this->actingAs($this->admin)
            ->get(route('report.finance', array_merge([
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => now()->toDateString(),
            ], $params)))
            ->assertOk()
            ->viewData('page')['props']['report']['balanceSheet'];
    }

    protected function sell(Product $product, int $qty, string $method, string $daysAgo = '0'): Transaction
    {
        $total = $product->sell_price * $qty;

        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'invoice' => 'TRX-BS-'.strtoupper(substr(md5($product->id.$method.$daysAgo.$qty), 0, 8)),
            'customer_name' => 'Uji Neraca',
            'cash' => $method === 'cash' ? $total : 0,
            'change' => 0,
            'discount' => 0,
            'taxable_amount' => $total,
            'tax_amount' => 0,
            'grand_total' => $total,
            'payment_method' => $method,
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);

        $transaction->forceFill([
            'created_at' => now()->subDays((int) $daysAgo),
            'updated_at' => now()->subDays((int) $daysAgo),
        ])->save();

        TransactionDetail::create([
            'transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'qty' => $qty,
            'price' => $total,
            'buy_price' => $product->buy_price,
        ]);

        return $transaction;
    }

    protected function product(string $title, int $buy, int $sell, int $stock = 10): Product
    {
        return Product::create([
            'category_id' => Category::firstOrCreate(['name' => 'BS-'.$title])->id,
            'title' => $title,
            'buy_price' => $buy,
            'sell_price' => $sell,
            'stock' => $stock,
            'unit' => 'Pcs',
        ]);
    }

    public function test_digital_sales_appear_as_bank_asset(): void
    {
        $product = $this->product('Produk QRIS', 10000, 30000);

        $this->sell($product, 1, 'cash', '5');
        $this->sell($product, 2, 'qris_manual', '4');
        $this->sell($product, 3, 'midtrans', '3');

        $sheet = $this->balanceSheet();

        // setUp membuka shift dengan modal awal Rp 1.000.000, jadi kas laci
        // = modal awal + penjualan tunai. Penjualan digital TIDAK boleh
        // masuk ke sini.
        $this->assertSame(1000000 + 30000, $sheet['cash_in_drawer'],
            'Kas laci = modal awal shift + penjualan TUNAI saja.');
        $this->assertSame(150000, $sheet['bank_and_digital'],
            'Uang QRIS + Midtrans (5 x 30.000) harus masuk sebagai Kas Bank & QRIS.');

        $this->assertSame(
            $sheet['cash_in_drawer'] + $sheet['bank_and_digital']
                + $sheet['inventory_ingredients'] + $sheet['inventory_products'],
            $sheet['total_assets'],
            'Total Aktiva = Kas Laci + Kas Bank + Persediaan.'
        );
    }

    public function test_gateway_fee_is_deducted_from_bank_balance(): void
    {
        $product = $this->product('Produk Fee', 10000, 30000);

        $transaction = Transaction::create([
            'cashier_id' => $this->admin->id,
            'invoice' => 'TRX-BS-FEE',
            'customer_name' => 'Uji Fee',
            'cash' => 0, 'change' => 0, 'discount' => 0,
            'taxable_amount' => 50000, 'tax_amount' => 0,
            'grand_total' => 50000,
            'payment_method' => 'midtrans',
            'payment_status' => 'paid',
            'status' => 'paid',
            'total_markup' => 2000,
            'total_fee' => 3000,
        ]);
        TransactionDetail::create([
            'transaction_id' => $transaction->id,
            'product_id' => $product->id,
            'qty' => 1, 'price' => 50000, 'buy_price' => 10000,
        ]);

        $sheet = $this->balanceSheet();

        $this->assertSame(45000, $sheet['bank_and_digital'],
            'Biaya gateway (2.000 + 3.000) harus mengurangi saldo digital.');
    }

    public function test_inventory_is_split_between_ingredients_and_products(): void
    {
        $this->product('Produk Jadi', 10000, 30000, 10);
        Ingredient::create(['name' => 'Bahan BS', 'buy_price' => 5000, 'stock' => 8, 'unit' => 'Kg']);

        $sheet = $this->balanceSheet();

        $this->assertSame(100000, $sheet['inventory_products'], '10 x 10.000');
        $this->assertSame(40000, $sheet['inventory_ingredients'], '8 x 5.000');
        $this->assertSame(140000, $sheet['inventory_value']);
    }

    public function test_liabilities_and_equity_are_reported_separately(): void
    {
        Capital::create(['source' => 'Setoran', 'amount' => 5000000, 'date' => now()->toDateString()]);

        $product = $this->product('Produk Utang', 10000, 30000);
        $this->sell($product, 2, 'cash', '2');

        Expense::create([
            'user_id' => $this->admin->id, 'name' => 'Utang Supplier',
            'amount' => 750000, 'category' => 'Operasional', 'source' => 'Hutang Dagang',
            'date' => now()->toDateString(),
        ]);

        $sheet = $this->balanceSheet();

        $this->assertSame(750000, $sheet['accounts_payable']);
        $this->assertSame(750000, $sheet['total_liabilities'], 'Total Kewajiban = hutang usaha.');
        $this->assertSame(5000000, $sheet['external_capital']);
        $this->assertSame(
            $sheet['external_capital'] + $sheet['retained_earnings'],
            $sheet['total_equity'],
            'Total Ekuitas = modal + laba ditahan.'
        );
        $this->assertSame(
            $sheet['total_liabilities'] + $sheet['total_equity'],
            $sheet['total_liabilities_equity'],
        );
    }

    public function test_retained_earnings_is_cumulative_not_just_this_period(): void
    {
        $product = $this->product('Produx Akumulasi', 10000, 30000, 50);

        // 3 penjualan di bulan lalu, 1 penjualan hari ini.
        $this->sell($product, 1, 'cash', '40');
        $this->sell($product, 1, 'cash', '35');
        $this->sell($product, 1, 'cash', '30');
        $this->sell($product, 1, 'cash', '0');

        // Laporan laba rugi HANYA melihat hari ini.
        $period = $this->balanceSheet([
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        // Neraca harus akumulatif: 4 penjualan x (30.000 - 10.000) = 80.000.
        $this->assertSame(80000, $period['retained_earnings'],
            'Laba ditahan harus kumulatif, bukan laba periode saja.');
    }

    public function test_balance_sheet_balances_when_money_is_fully_accounted(): void
    {
        // Setoran modal 5 juta, semuanya dipakai sebagai modal awal shift.
        // Cada 1.000.000 kas di laci harus didukung 1.000.000 ekuitas.
        Shift::query()->delete();
        Shift::create([
            'user_id' => $this->admin->id,
            'starting_cash' => 5000000,
            'status' => 'open',
            'opened_at' => now()->subDays(10),
        ]);
        Capital::create(['source' => 'Setoran Modal', 'amount' => 5000000, 'date' => now()->toDateString()]);

        $sheet = $this->balanceSheet();

        $this->assertSame(5000000, $sheet['cash_in_drawer']);
        $this->assertSame(5000000, $sheet['total_equity'], 'Modal 5 juta = kas 5 juta.');
        $this->assertSame(
            0,
            $sheet['balance_difference'],
            'Setoran yang benar-benar ada di laci harus membuat neraca balance.'
        );
        $this->assertSame($sheet['total_assets'], $sheet['total_liabilities_equity']);
    }

    public function test_balance_difference_reveals_unaccounted_money(): void
    {
        // Modal dicatat 5 juta, tapi hanya 1 juta (modal awal shift di setUp)
        // yang benar-benar ada di laci. Sisanya jadi selisih.
        Capital::create(['source' => 'Setoran Bank', 'amount' => 5000000, 'date' => now()->toDateString()]);

        $sheet = $this->balanceSheet();

        $this->assertSame(1000000, $sheet['total_assets'],
            'Hanya modal awal shift yang punya aset fisik.');
        $this->assertSame(5000000, $sheet['total_equity']);
        $this->assertSame(
            -4000000,
            $sheet['balance_difference'],
            'Selisih harus menunjukkan 4 juta modal yang belum ada asetnya.'
        );
    }

    public function test_finance_page_exposes_every_account_name_component(): void
    {
        $this->actingAs($this->admin)
            ->get(route('report.finance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Reports/Finance')
                ->has('report.balanceSheet.cash_in_drawer')
                ->has('report.balanceSheet.bank_and_digital')
                ->has('report.balanceSheet.inventory_ingredients')
                ->has('report.balanceSheet.inventory_products')
                ->has('report.balanceSheet.inventory_value')
                ->has('report.balanceSheet.accounts_payable')
                ->has('report.balanceSheet.tax_payable')
                ->has('report.balanceSheet.total_liabilities')
                ->has('report.balanceSheet.external_capital')
                ->has('report.balanceSheet.retained_earnings')
                ->has('report.balanceSheet.total_equity')
                ->has('report.balanceSheet.total_assets')
                ->has('report.balanceSheet.total_liabilities_equity')
                ->has('report.balanceSheet.balance_difference')
                ->has('report.balanceSheet.net_assets')
            );
    }
}
