<?php

namespace Tests\Feature\Uat;

use App\Models\Capital;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Modal Usaha: setoran modal adalah sisi EKUITAS, bukan aset.
 *
 * Tabel `capitals` sudah ada dan dibaca neraca sejak lama, tapi tidak pernah
 * bisa diisi karena tidak ada route. persuaded diselesaikan, ada jebakan
 * akuntansi yang harus dihindari: kalau modal dijumlahkan ke total aset,
 * uang yang sama terhitung dua kali (sekali di kas, sekali sebagai modal).
 */
class UatCapitalTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'capitals.index', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $this->admin->givePermissionTo('capitals.index');
    }

    /** @return array<string,int> */
    protected function balanceSheet(): array
    {
        $response = $this->actingAs($this->admin)
            ->get(route('report.finance', [
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => now()->addDay()->toDateString(),
            ]))
            ->assertOk();

        return (array) $response->viewData('page')['props']['report']['balanceSheet'];
    }

    public function test_capital_can_be_recorded_and_removed(): void
    {
        $this->actingAs($this->admin)
            ->post(route('capitals.store'), [
                'source' => 'Setoran Modal Awal',
                'amount' => 25000000,
                'date' => now()->toDateString(),
            ])
            ->assertRedirect(route('capitals.index'));

        $capital = Capital::where('source', 'Setoran Modal Awal')->firstOrFail();
        $this->assertSame(25000000.0, (float) $capital->amount);

        $this->actingAs($this->admin)
            ->delete(route('capitals.destroy', $capital->id))
            ->assertRedirect(route('capitals.index'));
        $this->assertNull(Capital::find($capital->id));
    }

    public function test_negative_and_missing_capital_are_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('capitals.store'), [
                'source' => 'Setoran Modal Awal',
                'amount' => -5000000,
                'date' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->admin)
            ->post(route('capitals.store'), ['source' => '', 'amount' => 1000])
            ->assertSessionHasErrors(['source', 'date']);

        $this->assertSame(0, Capital::count());
    }

    public function test_capital_shows_on_the_equity_side_only(): void
    {
        // Pemilik menyetor 10 juta DANylogencingkannya ke laci kas sebagai
        // modal awal shift. Uang itu hanya boleh muncul sebagai aset SATU kali
        // (di kas), dan sebagai ekuitas SATU kali (di modal).
        Shift::create([
            'user_id' => $this->admin->id,
            'starting_cash' => 10000000,
            'status' => 'open',
            'opened_at' => now(),
        ]);
        Capital::create(['source' => 'Setoran Awal', 'amount' => 10000000, 'date' => now()->toDateString()]);

        $sheet = $this->balanceSheet();

        $this->assertSame(10000000, $sheet['external_capital'], 'Modal harus tercatat di sisi ekuitas.');
        $this->assertSame(10000000, $sheet['cash_in_drawer'], 'Uang modal awal ada di laci kas.');

        // Aset = kas + persediaan. Modal TIDAK ikut dihitung lagi.
        $this->assertSame(10000000, $sheet['total_assets'],
            'Total aset harus 10 juta (kas), bukan 20 juta. Modal bukan aset.');
        $this->assertSame(
            $sheet['cash_in_drawer'] + $sheet['inventory_value'],
            $sheet['total_assets'],
        );

        // Ekuitas memuat modal + laba.
        $this->assertSame(
            $sheet['accounts_payable'] + $sheet['tax_payable']
                + $sheet['external_capital'] + $sheet['retained_earnings'],
            $sheet['total_liabilities_equity'],
        );

        // Karena modal 10jt ada di kas dan di ekuitas, neraca harus balance.
        $this->assertSame(
            0,
            $sheet['balance_difference'],
            'Setoran modal yang benar-benar ada di kas harus membuat neraca balance.'
        );
    }

    public function test_capital_without_matching_cash_shows_the_real_gap(): void
    {
        // Setoran dicatat, tapi uangnya tidak pernah masuk laci kas.
        Capital::create(['source' => 'Setoran Bank', 'amount' => 5000000, 'date' => now()->toDateString()]);

        $sheet = $this->balanceSheet();

        $this->assertSame(5000000, $sheet['external_capital']);
        $this->assertSame(0, $sheet['total_assets'], 'Tidak ada uang fisik yang tercatat sebagai aset.');
        $this->assertSame(
            -5000000,
            $sheet['balance_difference'],
            'Selisih harus menunjukkan modal yang belum dibuktikan ada di kas.'
        );
    }

    public function test_capital_withdrawal_reduces_equity_but_not_profit(): void
    {
        Capital::create(['source' => 'Setoran Awal', 'amount' => 10000000, 'date' => now()->subDays(2)->toDateString()]);

        // Pemilik menarik 3 juta. Dicatat sebagai pengeluaran Modal Luar.
        Expense::create([
            'name' => 'Ambil Modal Pemilik',
            'amount' => 3000000,
            'date' => now()->toDateString(),
            'category' => 'Operasional',
            'source' => 'Modal Luar',
            'user_id' => $this->admin->id,
        ]);

        $sheet = $this->balanceSheet();

        $this->assertSame(7000000, $sheet['external_capital'], 'Modal bersih = setoran - penarikan.');
        $this->assertSame(0, $sheet['retained_earnings'],
            'Penarikan modal bukan beban, jadi tidak boleh mengurangi laba.');
    }

    public function test_capital_page_requires_permission(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)
            ->get(route('capitals.index'))
            ->assertForbidden();

        $this->actingAs($staff)
            ->post(route('capitals.store'), [
                'source' => 'X', 'amount' => 1000, 'date' => now()->toDateString(),
            ])
            ->assertForbidden();

        $this->assertSame(0, Capital::count());
    }

    public function test_capital_page_renders_with_summary(): void
    {
        Capital::create(['source' => 'Setoran Awal', 'amount' => 7500000, 'date' => now()->toDateString()]);

        $this->actingAs($this->admin)
            ->get(route('capitals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Capitals/Index')
                ->where('summary.total_in', 7500000)
                ->where('summary.count', 1)
                ->where('capitals.data.0.source', 'Setoran Awal')
            );
    }

    public function test_capital_does_not_inflate_inventory_or_revenue(): void
    {
        Product::create([
            'category_id' => Category::create(['name' => 'CAP'])->id,
            'title' => 'Barang', 'buy_price' => 10000, 'sell_price' => 20000,
            'stock' => 5, 'unit' => 'Pcs',
        ]);
        Capital::create(['source' => 'Setoran Awal', 'amount' => 99999999, 'date' => now()->toDateString()]);

        $sheet = $this->balanceSheet();

        // Setoran modal 99 juta tidak boleh menyelinap ke nilai persediaan
        // maupun ke pendapatan.
        $this->assertSame(50000, $sheet['inventory_value'],
            'Setoran modal tidak boleh masuk nilai persediaan.');

        $this->assertSame(0, $sheet['net_assets'] - ($sheet['cash_in_drawer'] + $sheet['inventory_value']),
            'Total bersih harus tetap kas + persediaan.');

        $this->assertSame(
            $sheet['external_capital'],
            99999999,
            'Setoran modal harus utuh di sisi ekuitas.'
        );
    }
}
