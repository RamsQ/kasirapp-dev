<?php

namespace Tests\Feature\Uat;

use App\Models\Capital;
use App\Models\Expense;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Float Laci Berputar.
 *
 * KASUS YANG DIPAKAI:
 *   Shift 1 buka dengan modal laci Rp 50.000, laku Rp 100.000, tutup dengan
 *   Rp 150.000 dihitung. Shift 2 buka lagi dengan sisa yang sudah dihitung.
 *
 * MASALAH ASAL: neraca menjumlahkan `starting_cash` dari SEMUA shift. Padahal
 * float itu berputar, bukan tambahan baru. Uang Rp 100.000 yang tertinggal di
 * laci sudah termasuk hasil hitungan shift 1, tapi kalau shift 2 diisi lagi
 * Rp 50.000, float itu terhitung dua kali sementara Modal Disetor cuma
 * tercatat sekali. Efeknya kas neraca naik tanpa batas.
 *
 * YANG DIPERBAIKAN:
 *  1. Basis kas di laci = hasil HITUNGAN shift yang terakhir ditutup, lalu
 *     ditambah penjualan dan dikurangi pengeluaran setelah waktu itu.
 *  2. Ada "Setor ke Bank": uang pindah dari laci ke rekening, jadi mengurangi
 *     Kas di Laci dan menambah Kas Bank tanpa mengubah laba.
 */
class UatShiftFloatTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['email' => 'admin@gmail.com']);
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));

        Setting::create([
            'cogs_method' => 'FIFO',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => false,
            'kitchen_display_enabled' => false,
        ]);
    }

    protected function openShift(float $startingCash, string $openedAt): Shift
    {
        // opened_at bukan fillable DAN kolomnya NOT NULL, jadi harus diisi
        // sebelum insert, bukan sesudahnya.
        $shift = new Shift([
            'user_id' => $this->admin->id,
            'starting_cash' => $startingCash,
            'status' => 'open',
        ]);
        $shift->forceFill(['opened_at' => $openedAt])->save();

        return $shift;
    }

    protected function closeShift(Shift $shift, float $countedCash, string $closedAt): void
    {
        $shift->update([
            'status' => 'closed',
            'total_cash_actual' => $countedCash,
            'total_cash_expected' => $shift->starting_cash,
        ]);

        $shift->forceFill(['closed_at' => $closedAt])->save();
    }

    protected function cashSale(Shift $shift, float $amount, string $at): Transaction
    {
        // Rincian item sengaja tidak dibuat: tes ini hanya butuh arus kas dan
        // omzet, dan menambah produk berarti harus menyiapkan batch dulu.
        $transaction = Transaction::create([
            'invoice' => 'INV-'.uniqid(),
            'shift_id' => $shift->id,
            'cashier_id' => $this->admin->id,
            'customer_name' => 'Uji',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
            'subtotal' => $amount,
            'discount' => 0,
            'tax_amount' => 0,
            'cash' => $amount,
            'change' => 0,
            'total_markup' => 0,
            'total_fee' => 0,
            'grand_total' => $amount,
            'taxable_amount' => $amount,
            'total_discount' => 0,
            'cogs_method' => 'FIFO',
        ]);

        // created_at bukan fillable. Kalau tidak ditulis terpisah, semua
        // transaksi dapat waktu "sekarang" dan pengelompokan per tanggal
        // jadi tidak berarti apa-apa.
        $transaction->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $transaction;
    }

    protected function expense(array $attributes): Expense
    {
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $expense = Expense::create(array_merge([
            'user_id' => $this->admin->id,
            'name' => 'Uji',
            'category' => 'Operasional',
            'amount' => 0,
            'source' => Expense::SOURCE_CASH,
        ], $attributes));

        if ($createdAt) {
            // created_at bukan fillable.
            $expense->forceFill(['created_at' => $createdAt])->save();
        }

        return $expense;
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

    public function test_float_carrying_forward_is_not_counted_twice(): void
    {
        // Modal awal laci Rp 50.000, lalu modality dicatat sebagai setoran modal.
        Capital::create([
            'source' => 'Setoran Modal',
            'amount' => 50000,
            'date' => now()->subDays(10)->toDateString(),
        ]);

        $day1 = $this->openShift(50000, now()->subDays(3)->setTime(8, 0));
        $this->cashSale($day1, 100000, now()->subDays(3)->setTime(12, 0));
        $this->closeShift($day1, 150000, now()->subDays(3)->setTime(20, 0));

        // Shift 2 mulai dari sisa yang sudah dihitung: Rp 150.000.
        $day2 = $this->openShift(150000, now()->subDays(2)->setTime(8, 0));
        $this->cashSale($day2, 100000, now()->subDays(2)->setTime(12, 0));
        $this->closeShift($day2, 250000, now()->subDays(2)->setTime(20, 0));

        // Shift 3 lagi.
        $day3 = $this->openShift(250000, now()->subDay()->setTime(8, 0));
        $this->cashSale($day3, 100000, now()->subDay()->setTime(12, 0));

        $sheet = $this->balanceSheet();

        $this->assertSame(350000, $sheet['cash_in_drawer'],
            'Kas harus sama dengan hasil hitungan shift terakhir (350.000), '
            .'bukan penjumlahan float semua shift (450.000).');
        $this->assertSame(50000, $sheet['external_capital'],
            'Modal hanya disetor sekali (Rp 50.000) dan tidak bertambah '
            .'setiap shift, karena float itu berputar.');
        $this->assertSame(300000.0, (float) $sheet['retained_earnings'],
            'Laba ditahan = 3 x 100.000 penjualan.');
        $this->assertSame(350000, $sheet['total_equity']);
        $this->assertSame(0, $sheet['balance_difference'],
            'Float berputar tidak boleh membuat neraca bergeser.');
    }

    public function test_wrong_float_is_caught_at_shift_close_not_in_the_balance_sheet(): void
    {
        Capital::create([
            'source' => 'Setoran Modal',
            'amount' => 50000,
            'date' => now()->subDays(10)->toDateString(),
        ]);

        $day1 = $this->openShift(50000, now()->subDays(3)->setTime(8, 0));
        $this->cashSale($day1, 100000, now()->subDays(3)->setTime(12, 0));
        $this->closeShift($day1, 150000, now()->subDays(3)->setTime(20, 0));

        // Kasir salah: isi lagi Rp 50.000 padahal di laci ada Rp 150.000.
        $day2 = $this->openShift(50000, now()->subDays(2)->setTime(8, 0));
        $this->cashSale($day2, 100000, now()->subDays(2)->setTime(12, 0));

        $this->actingAs($this->admin)
            ->post(route('shifts.close'), ['total_cash_physical' => 250000])
            ->assertRedirect();

        $day2->refresh();

        $this->assertSame(150000.0, (float) $day2->total_cash_expected,
            'Ekspektasi ikut nurseba dari float yang diketik: 50.000 + 100.000.');
        $this->assertSame(100000.0, (float) $day2->difference,
            'Selisih shift harus menunjukkan kas lebih Rp 100.000. Ini '
            .'alarm-nya, di level shift, bukan di neraca.');

        // Neraca sendiri tetap benar karena berdasar pada HITUNGAN fisik,
        // bukan pada nominal yang diketik kasir.
        $sheet = $this->balanceSheet();
        $this->assertSame(250000, $sheet['cash_in_drawer']);
        $this->assertSame(0, $sheet['balance_difference'],
            'Neraca memakai hasil hitungan fisik, jadi nominal float yang salah '
            .'tidak mengacaukan pembukuan.');
    }

    public function test_bank_deposit_moves_cash_from_drawer_to_bank(): void
    {
        Capital::create([
            'source' => 'Setoran Modal',
            'amount' => 50000,
            'date' => now()->subDays(10)->toDateString(),
        ]);

        $shift = $this->openShift(50000, now()->subDay()->setTime(8, 0));
        $this->cashSale($shift, 100000, now()->subDay()->setTime(12, 0));

        $this->expense([
            'name' => 'Setor sore',
            'amount' => 100000,
            'source' => Expense::SOURCE_BANK_DEPOSIT,
            'date' => now()->toDateString(),
        ]);

        $sheet = $this->balanceSheet();

        $this->assertSame(50000, $sheet['cash_in_drawer'],
            'Setor 100.000 dari laci: 50.000 + 100.000 - 100.000 = 50.000.');
        $this->assertSame(100000, $sheet['bank_and_digital'],
            'Uang yang disetor harus muncul di Kas Bank.');
        $this->assertSame(0, $sheet['balance_difference'],
            'Pindah antar aset tidak boleh mengubah total aset.');
    }

    public function test_bank_deposit_never_reduces_profit(): void
    {
        $soldAt = now()->subDay()->setTime(12, 0);

        $shift = $this->openShift(50000, now()->subDay()->setTime(8, 0));
        $this->cashSale($shift, 100000, $soldAt);

        $this->expense([
            'name' => 'Setor sore',
            'amount' => 100000,
            'source' => Expense::SOURCE_BANK_DEPOSIT,
            'date' => $soldAt->toDateString(),
        ]);

        $summary = app(\App\Services\FinancialReportService::class)->summary(
            Transaction::whereDate('created_at', $soldAt),
            Expense::whereDate('date', $soldAt)
        );

        $this->assertSame(0.0, (float) $summary['operating_expenses'],
            'Setor ke bank bukan beban. Kalau dipotong, laba ikut anjlok '
            .'padahal tidak ada biaya yang terjadi.');
        $this->assertSame(100000.0, (float) $summary['net_profit'],
            'Laba harus tetap 100.000 dari penjualan.');
    }

    public function test_shift_close_expectation_accounts_for_bank_deposit(): void
    {
        $shift = $this->openShift(50000, now()->subDay()->setTime(8, 0));
        $this->cashSale($shift, 100000, now()->subDay()->setTime(12, 0));

        $this->expense([
            'name' => 'Setor siang',
            'amount' => 80000,
            'source' => Expense::SOURCE_BANK_DEPOSIT,
            'date' => now()->toDateString(),
            'created_at' => now()->subDay()->setTime(15, 0),
        ]);

        $this->actingAs($this->admin)
            ->post(route('shifts.close'), ['total_cash_physical' => 70000])
            ->assertRedirect();

        $shift->refresh();

        $this->assertSame(70000.0, (float) $shift->total_cash_expected,
            '50.000 + 100.000 - 80.000 setor = 70.000.');
        $this->assertSame(0.0, (float) $shift->difference,
            'Kalau setor tidak ikut dihitung, selisih shift selalu negatif.');
    }

    public function test_bank_deposit_keeps_total_assets_unchanged(): void
    {
        Capital::create([
            'source' => 'Setoran Modal',
            'amount' => 50000,
            'date' => now()->subDays(10)->toDateString(),
        ]);

        $shift = $this->openShift(50000, now()->subDay()->setTime(8, 0));
        $this->cashSale($shift, 100000, now()->subDay()->setTime(12, 0));

        $before = $this->balanceSheet();
        $this->assertSame(150000, $before['total_assets']);

        $this->expense([
            'name' => 'Setor',
            'amount' => 100000,
            'source' => Expense::SOURCE_BANK_DEPOSIT,
            'date' => now()->toDateString(),
        ]);

        $after = $this->balanceSheet();

        $this->assertSame($before['total_assets'], $after['total_assets'],
            'Setor ke bank cuma memindahkan uang antar aset, total tidak berubah.');
        $this->assertSame(0, $after['balance_difference']);
    }

    public function test_pos_page_exposes_previous_close_for_float_prefill(): void
    {
        $day1 = $this->openShift(50000, now()->subDays(2)->setTime(8, 0));
        $this->cashSale($day1, 100000, now()->subDays(2)->setTime(12, 0));
        $this->closeShift($day1, 137500, now()->subDays(2)->setTime(20, 0));

        $this->actingAs($this->admin)
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Transactions/Index')
                ->where('lastClosedShift.total_cash_actual', 137500,
                    'Kasir perlu melihat sisa laci dari shift sebelumnya, '
                    .'bukan mengisi modal tetap dari nol.')
            );
    }

    public function test_pos_page_has_no_previous_close_on_first_ever_shift(): void
    {
        $this->actingAs($this->admin)
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Transactions/Index')
                ->where('lastClosedShift', null)
            );
    }

    public function test_deposit_is_rejected_when_source_is_unknown(): void
    {
        $this->actingAs($this->admin)
            ->post(route('expenses.store'), [
                'name' => 'Setor',
                'amount' => 100000,
                'source' => 'Rekening BCA',
            ])
            ->assertSessionHasErrors('source');

        $this->assertSame(0, Expense::count());
    }

    public function test_deposit_is_accepted_from_the_cashier_route(): void
    {
        $this->actingAs($this->admin)
            ->post(route('expenses.store'), [
                'name' => 'Setor sore',
                'amount' => 75000,
                'source' => Expense::SOURCE_BANK_DEPOSIT,
            ])
            ->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();

        $this->assertSame(Expense::SOURCE_BANK_DEPOSIT, $expense->source);
        $this->assertSame(75000, (int) $expense->amount);
    }

    public function test_cashier_route_defaults_to_drawer_when_source_omitted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('expenses.store'), [
                'name' => 'Bayar listrik',
                'amount' => 60000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Expense::SOURCE_CASH, Expense::firstOrFail()->source,
            'Default harus Kas Laci supaya pengeluaran biasa tidak hilang dari sisi kas.');
    }
}
