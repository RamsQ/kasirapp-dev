<?php

namespace Tests\Feature\Uat;

use App\Models\Capital;
use App\Models\Expense;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\StockPurchaseRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Sumber Dana Expense.
 *
 * MASALAH ASAL: kolom `expenses.source` diisi user sebagai teks bebas, tapi
 * neraca hanya mengenali tiga nilai: "Kas Laci" (keluar dari laci),
 * "Modal Luar" (keluar dari laci, mengurangi modal), dan "Hutang Dagang"
 * (uang masuk, menambah hutang). Expense dengan nilai lain dipotong dari laba,
 * tapi TIDAK mengurangi kas maupun menambah hutang. Akibatnya selisih neraca
 * muncul sebesar nilai expense itu, tanpa ada yang menyadarinya.
 *
 * Dua lapis perlindungan yang diuji di sini:
 *  1. Input dibatasi ke daftar resmi, jadi sumber baru harus ridesa.
 *  2. Laporan memakai daftar PENGECUALIAN, bukan daftar putih, sehingga
 *     expense lama yang source-nya tidak dikenal tetap mengurangi kas.
 *
 * Lapis kedua sengaja memakai pengecualian: kalau ada expense yang luput
 * dicatat, lebih baik kas terlihat kurang daripada neraca terlihat balance
 * padahal uangnya hilang dari pembukuan.
 */
class UatExpenseSourceTest extends TestCase
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
            'starting_cash' => 10000000,
            'status' => 'open',
            'opened_at' => now()->subDays(5),
        ]);

        Capital::create([
            'source' => 'Setoran Modal',
            'amount' => 10000000,
            'date' => now()->subDays(5)->toDateString(),
        ]);

        Setting::create([
            'cogs_method' => 'FIFO',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => false,
            'kitchen_display_enabled' => false,
        ]);
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

    protected function recordExpense(array $attributes = []): Expense
    {
        return Expense::create(array_merge([
            'user_id' => $this->admin->id,
            'name' => 'Listrik',
            'category' => 'Operasional',
            'amount' => 500000,
            'date' => now()->toDateString(),
            'source' => Expense::SOURCE_CASH,
        ], $attributes));
    }

    public function test_petty_cash_expense_reduces_cash_and_equity_equally(): void
    {
        $this->recordExpense(['amount' => 500000, 'source' => Expense::SOURCE_CASH]);

        $sheet = $this->balanceSheet();

        $this->assertSame(9500000, $sheet['cash_in_drawer'], '10 juta - 500 ribu.');
        $this->assertSame(-500000.0, (float) $sheet['retained_earnings'],
            'Pengeluaran kas harus ikut memotong laba ditahan.');
        $this->assertSame(0, $sheet['balance_difference'],
            'Kas dan ekuitas harus turun sama besarnya.');
    }

    public function test_expense_with_unknown_source_still_reduces_cash(): void
    {
        // Sumber tidak dikenal, misalnya data lama atau ketik di aplikasi.
        $this->recordExpense(['amount' => 500000, 'source' => 'Operasional']);

        $sheet = $this->balanceSheet();

        $this->assertSame(9500000, $sheet['cash_in_drawer'],
            'Source yang tidak dikenal tetap pengeluaran kas. Kalau tidak, '
            .'uang hilang dari pembukuan tanpa ada yang mengurangi aset.');
        $this->assertSame(0, $sheet['balance_difference']);
    }

    public function test_source_with_only_spaces_still_reduces_cash(): void
    {
        // Data rusak: source terisi spasi. Dilihat sebagai "tidak dikenal",
        // jadi tetap keluar dari kas, bukan lolos dari pembukuan.
        $this->recordExpense(['amount' => 500000, 'source' => Expense::SOURCE_CASH]);
        DB::table('expenses')->where('name', 'Listrik')->update(['source' => '  ']);

        $sheet = $this->balanceSheet();

        $this->assertSame(9500000, $sheet['cash_in_drawer'],
            'Source berisi spasi (data rusak) tetap harus mengurangi kas.');
        $this->assertSame(0, $sheet['balance_difference']);
    }

    public function test_capital_withdrawal_reduces_cash_and_equity(): void
    {
        $this->recordExpense(['amount' => 2000000, 'source' => Expense::SOURCE_CAPITAL]);

        $sheet = $this->balanceSheet();

        $this->assertSame(8000000, $sheet['cash_in_drawer'],
            'Modal Luar uangnya benar-benar keluar dari laci.');
        $this->assertSame(8000000.0, (float) $sheet['external_capital'],
            'Sisi ekuitas modal juga harus ikut turun.');
        $this->assertSame(0, $sheet['balance_difference'],
            'Modal Luar mengurangi kas dan modal, jadi neraca tetap balance.');
    }

    public function test_borrowing_increases_cash_and_liability(): void
    {
        $this->recordExpense(['amount' => 3000000, 'source' => Expense::SOURCE_DEBT]);

        $sheet = $this->balanceSheet();

        $this->assertSame(13000000, $sheet['cash_in_drawer'],
            'Pinjaman itu uang masuk, jadi kas naik.');
        $this->assertSame(3000000, $sheet['accounts_payable'], 'Dan jadi hutang usaha.');
        $this->assertSame(0, $sheet['balance_difference'],
            'Kas naik sama besarnya dengan hutang, jadi neraca tetap balance.');
    }

    public function test_borrowing_then_repayment_cancels_out(): void
    {
        $this->recordExpense(['amount' => 3000000, 'source' => Expense::SOURCE_DEBT]);
        $this->recordExpense([
            'name' => 'Bayar hutang supplier',
            'amount' => 3000000,
            'category' => 'Pelunasan Hutang',
            'source' => Expense::SOURCE_CASH,
        ]);

        $sheet = $this->balanceSheet();

        $this->assertSame(10000000, $sheet['cash_in_drawer'], 'Kas kembali seperti awal.');
        $this->assertSame(0, $sheet['accounts_payable'], 'Hutang lunas.');
        $this->assertSame(0, $sheet['balance_difference']);
    }

    public function test_stock_purchase_is_never_counted_twice(): void
    {
        // Belanja stok punya source sendiri dan dicatat lewat payment_method.
        // Kalau ikut terhitung sebagai pengeluaran kas umum, kas akan
        // berkurang dua kali untuk satu pembelian.
        Expense::create([
            'user_id' => $this->admin->id,
            'name' => 'Pembelian Stok - Dibayar Tunai',
            'category' => StockPurchaseRecorder::CATEGORY,
            'source' => StockPurchaseRecorder::SOURCE,
            'payment_method' => StockPurchaseRecorder::PAID_CASH,
            'amount' => 2000000,
            'date' => now()->toDateString(),
        ]);

        $this->assertSame(
            0,
            Expense::cashOutflow()->count(),
            'Belanja stok tidak boleh masuk daftar pengeluaran kas umum.'
        );
    }

    public function test_cashier_expense_route_rejects_unknown_source(): void
    {
        // Endpoint kasir petty cash menerima pilihan source, tapi hanya dari
        // daftar resmi. Kalau bebas, kasir bisa menulis source apa saja dan
        // expense-nya akan hilang dari sisi kas di neraca.
        $this->actingAs($this->admin)
            ->post(route('expenses.store'), [
                'name' => 'Listrik',
                'amount' => 100000,
                'source' => 'Rekening BCA',
            ])
            ->assertSessionHasErrors('source');

        $this->assertSame(0, Expense::count());
    }

    public function test_cashier_expense_always_reduces_cash(): void
    {
        $this->actingAs($this->admin)
            ->post(route('expenses.store'), [
                'name' => 'Listrik',
                'amount' => 250000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(9750000, $this->balanceSheet()['cash_in_drawer']);
    }

    public function test_mixed_expenses_still_balance(): void
    {
        $this->recordExpense(['amount' => 500000, 'source' => Expense::SOURCE_CASH]);
        $this->recordExpense(['amount' => 300000, 'source' => 'Operasional']);
        $this->recordExpense(['amount' => 400000, 'source' => 'Diterima Guest']);
        $this->recordExpense(['amount' => 200000, 'source' => Expense::SOURCE_CAPITAL]);
        $this->recordExpense(['amount' => 1500000, 'source' => Expense::SOURCE_DEBT]);

        $sheet = $this->balanceSheet();

        // 10 juta + pinjaman 1,5 juta - keluar (500rb + 300rb + 400rb + 200rb)
        $this->assertSame(10100000, $sheet['cash_in_drawer']);
        $this->assertSame(1500000, $sheet['accounts_payable']);
        $this->assertSame(9800000.0, (float) $sheet['external_capital']);
        $this->assertSame(0, $sheet['balance_difference'],
            'Kombinasi semua jenis sumber dana harus tetap balance.');
    }
}
