<?php

namespace Tests\Feature\Uat;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Neraca: nilai persediaan harus jujur soal apa yang benar-benar ada.
 *
 * Label di UI menyebut "Nilai Inventori (Bahan & Produk)", jadi backend
 * wajib menghitung KEDUA jenis persediaan - dan tidak boleh menghitung
 * nilai menu resep dua kali.
 */
class UatBalanceSheetTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'reports.view', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        $this->admin->givePermissionTo('reports.view');
    }

    protected function category(): Category
    {
        return Category::create(['name' => 'NERACA-'.uniqid()]);
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

    public function test_inventory_value_includes_ingredients_not_only_products(): void
    {
        $category = $this->category();

        Product::create([
            'category_id' => $category->id, 'title' => 'Minuman Kalengan',
            'buy_price' => 10000, 'sell_price' => 15000, 'stock' => 20, 'unit' => 'Pcs',
        ]);
        Ingredient::create([
            'name' => 'Beras Mentah', 'buy_price' => 14000, 'stock' => 50, 'unit' => 'Kg',
        ]);
        Ingredient::create([
            'name' => 'Minyak Goreng', 'buy_price' => 35000, 'stock' => 10, 'unit' => 'Liter',
        ]);

        $sheet = $this->balanceSheet();

        // Produk  20 x 10.000  =  200.000
        // Beras   50 x 14.000  =  700.000
        // Minyak  10 x 35.000  =  350.000
        // Total                 = 1.250.000
        $this->assertSame(1250000, $sheet['inventory_value'],
            'Nilai inventori harus mencakup bahan baku, bukan hanya produk jadi.');
    }

    public function test_recipe_menu_is_not_counted_twice(): void
    {
        $category = $this->category();
        $flour = Ingredient::create([
            'name' => 'Tepung Terigu', 'buy_price' => 12000, 'stock' => 10, 'unit' => 'Kg',
        ]);

        $cake = Product::create([
            'category_id' => $category->id, 'title' => 'Kue Bolu',
            'buy_price' => 0, 'cost_price' => 30000, 'sell_price' => 45000,
            'stock' => 25, 'unit' => 'Pcs',
        ]);
        Recipe::create(['product_id' => $cake->id, 'ingredient_id' => $flour->id, 'qty_needed' => 0.2]);

        $sheet = $this->balanceSheet();

        // Menu resep bernilai 25 x 30.000 = 750.000, tapi nilainya sudah ada
        // di bahan (10 x 12.000 = 120.000). Menghitung keduanya = 870.000 dan
        // itu DOUBLE COUNTING.
        $this->assertSame(120000, $sheet['inventory_value'],
            'Nilai menu resep tidak boleh dihitung di atas nilai bahannya.');
    }

    public function test_bundle_is_not_counted_separately_from_its_components(): void
    {
        $category = $this->category();

        $rice = Product::create([
            'category_id' => $category->id, 'title' => 'Nasi',
            'buy_price' => 5000, 'sell_price' => 8000, 'stock' => 30, 'unit' => 'Pcs',
        ]);
        $soup = Product::create([
            'category_id' => $category->id, 'title' => 'Soto',
            'buy_price' => 7000, 'sell_price' => 12000, 'stock' => 30, 'unit' => 'Pcs',
        ]);

        // Bundel tidak pernah punya stok fisik sendiri.
        $bundle = Product::create([
            'category_id' => $category->id, 'title' => 'Paket Hemat',
            'type' => 'bundle', 'buy_price' => 0, 'sell_price' => 18000,
            'stock' => 0, 'unit' => 'Pcs',
        ]);
        $bundle->bundle_items()->attach([
            ['item_id' => $rice->id, 'qty' => 1, 'product_unit_id' => null],
            ['item_id' => $soup->id, 'qty' => 1, 'product_unit_id' => null],
        ]);

        $sheet = $this->balanceSheet();

        // Nasi 30 x 5.000 = 150.000, Soto 30 x 7.000 = 210.000. Bundel = 0.
        $this->assertSame(360000, $sheet['inventory_value']);
    }

    public function test_soft_deleted_products_are_excluded_from_inventory(): void
    {
        $category = $this->category();
        $product = Product::create([
            'category_id' => $category->id, 'title' => 'Produk Dihentikan',
            'buy_price' => 25000, 'sell_price' => 30000, 'stock' => 10, 'unit' => 'Pcs',
        ]);

        $this->assertSame(250000, $this->balanceSheet()['inventory_value']);

        $product->delete();
        $product->save();

        $this->assertSame(0, $this->balanceSheet()['inventory_value'],
            'Produk soft-deleted tidak boleh masuk nilai persediaan.');
    }

    public function test_balance_sheet_totals_stay_internally_consistent(): void
    {
        $category = $this->category();
        Product::create([
            'category_id' => $category->id, 'title' => 'Item Neraca',
            'buy_price' => 20000, 'sell_price' => 35000, 'stock' => 15, 'unit' => 'Pcs',
        ]);
        Ingredient::create([
            'name' => 'Bahan Neraca', 'buy_price' => 9000, 'stock' => 40, 'unit' => 'Kg',
        ]);

        $sheet = $this->balanceSheet();

        // 15 x 20.000 + 40 x 9.000 = 300.000 + 360.000 = 660.000
        $this->assertSame(660000, $sheet['inventory_value']);

        $this->assertSame(
            $sheet['cash_in_drawer'] + $sheet['external_capital'] + $sheet['inventory_value'],
            $sheet['total_assets'],
            'Total aset harus sama dengan kas + modal + persediaan.'
        );

        $this->assertSame(
            $sheet['accounts_payable'] + $sheet['tax_payable']
                + $sheet['external_capital'] + $sheet['retained_earnings'],
            $sheet['total_liabilities_equity'],
            'Total kewajiban + modal + laba ditahan harus konsisten.'
        );

        $this->assertSame(
            $sheet['total_assets'] - $sheet['total_liabilities_equity'],
            $sheet['balance_difference'],
            'Selisih neraca harus dihitung dari total yang sama.'
        );
    }

    public function test_finance_page_renders_with_ingredient_inventory(): void
    {
        Ingredient::create([
            'name' => 'Bahan Tampil', 'buy_price' => 5000, 'stock' => 12, 'unit' => 'Kg',
        ]);

        $this->actingAs($this->admin)
            ->get(route('report.finance', [
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => now()->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Reports/Finance')
                ->where('report.balanceSheet.inventory_value', 60000));
    }
}
