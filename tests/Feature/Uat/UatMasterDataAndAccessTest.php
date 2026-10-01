<?php

namespace Tests\Feature\Uat;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Table;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Master Data & Akses: semua halaman harus bisa dibuka tanpa 500,
 * data master harus bisa dibuat/diubah/dihapus, dan hak akses harus ditegakkan.
 *
 * Uji halaman di sini menangkap error Inertia/prop yang tidak terlihat
 * sampai kasir benar-benar membuka menu tersebut.
 */
class UatMasterDataAndAccessTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create([
            'email' => 'uat-master@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->admin->assignRole($role);

        foreach ([
            'dashboard', 'transactions-access', 'categories.index', 'products.index',
            'ingredients.index', 'customers.index', 'tables.index', 'discounts.index',
            'shifts.index', 'reports.view', 'settings.index', 'users.index', 'capitals.index',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin->givePermissionTo([
            'dashboard', 'transactions-access', 'categories.index', 'products.index',
            'ingredients.index', 'customers.index', 'tables.index', 'discounts.index',
            'shifts.index', 'reports.view', 'settings.index', 'users.index', 'capitals.index',
        ]);
    }

    /**
     * Halaman yang wajib terbuka untuk operasional harian.
     * Senarai ini diambil langsung dari route::list, bukan diketik manual.
     */
    public function test_every_operational_page_renders_for_super_admin(): void
    {
        $pages = [
            'dashboard' => [],
            'transactions.index' => [],
            'transactions.history' => [],
            'categories.index' => [],
            'categories.create' => [],
            'products.index' => [],
            'products.create' => [],
            'stock_in.index' => [],
            'stock_opnames.index' => [],
            'ingredients.index' => [],
            'recipes.index' => [],
            'customers.index' => [],
            'customers.create' => [],
            'crm.campaigns.index' => [],
            'discounts.index' => [],
            'discounts.create' => [],
            'tables.index' => [],
            'shifts.index' => [],
            'kitchen.index' => [],
            'report.finance' => [],
            'capitals.index' => [],
            'reports.sales.index' => [],
            'reports.profits.index' => [],
            'reports.refund' => [],
            'reports.products.index' => [],
            'reports.expired.index' => [],
            'settings.index' => [],
            'settings.receipt.index' => [],
            'report-settings.index' => [],
            'online_settings.index' => [],
            'settings.payments.edit' => [],
            'settings.payment-settlements.index' => [],
            'settings.loyalty-rules.index' => ['expectRedirect' => true],
            'users.index' => [],
            'users.create' => [],
            'roles.index' => [],
            'permissions.index' => [],
            'ai.index' => [],
            'profile.edit' => [],
        ];

        $failures = [];

        foreach ($pages as $name => $params) {
            // Beberapa URL lama memang sengaja redirect, bukan 200.
            $expectRedirect = ($params['expectRedirect'] ?? false) === true;
            unset($params['expectRedirect']);

            try {
                $response = $this->actingAs($this->admin)->get(route($name, $params));

                $expectRedirect
                    ? $response->assertRedirect()
                    : $response->assertSuccessful();
            } catch (\Throwable $e) {
                $failures[] = $name.': '.mb_substr($e->getMessage(), 0, 160);
            }
        }

        $this->assertSame([], $failures, "Halaman gagal dibuka:\n".implode("\n", $failures));
    }

    public function test_legacy_loyalty_rules_url_redirects_to_discounts_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('settings.loyalty-rules.index'))
            ->assertRedirect(route('discounts.index'));
    }

    /**
     * Halaman detail harus membuka form edit, bukan 500.
     */
    public function test_detail_pages_open_the_edit_form(): void
    {
        $category = Category::create(['name' => 'Makanan']);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'Nasi Goreng',
            'buy_price' => 12000,
            'sell_price' => 28000,
            'stock' => 50,
            'unit' => 'Pcs',
        ]);
        $customer = Customer::create([
            'name' => 'Uji Detail',
            'no_telp' => '081200002222',
            'address' => 'Jl. Detail',
        ]);

        $this->actingAs($this->admin)->get(route('categories.edit', $category->id))->assertOk();
        $this->actingAs($this->admin)->get(route('products.edit', $product->id))->assertOk();
        $this->actingAs($this->admin)->get(route('customers.edit', $customer->id))->assertOk();
    }

    public function test_removed_show_routes_do_not_exist(): void
    {
        // Route show sengaja dihapus karena controller tidak punya method show().
        // Kalau suatu saat Route::resource() dikembalikan penuh, test ini gagal
        // dan reminded bahwa method show() harus diimplementasikan dulu.
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('categories.show'),
            'Route categories.show tidak boleh ada tanpa CategoryController::show().'
        );
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('products.show'),
            'Route products.show tidak boleh ada tanpa ProductController::show().'
        );
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('customers.show'),
            'Route customers.show tidak boleh ada tanpa CustomerController::show().'
        );
    }

    public function test_cashier_without_permission_gets_blocked_from_admin_pages(): void
    {
        $cashier = User::factory()->create();
        $cashier->givePermissionTo('transactions-access');

        $blocked = [
            'users.index' => [],
            'roles.index' => [],
            'permissions.index' => [],
            'settings.index' => [],
            'crm.campaigns.index' => [],
            'settings.payment-settlements.index' => [],
        ];

        foreach ($blocked as $name => $params) {
            $this->actingAs($cashier)
                ->get(route($name, $params))
                ->assertForbidden();
        }

        // Halaman yang memang boleh diakses kasir tetap terbuka.
        $this->actingAs($cashier)->get(route('transactions.index'))->assertOk();
    }

    public function test_guest_is_redirected_to_login_for_all_admin_pages(): void
    {
        $pages = ['dashboard', 'transactions.index', 'users.index', 'settings.index', 'crm.campaigns.index'];

        foreach ($pages as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }

    public function test_category_crud_round_trip(): void
    {
        $this->actingAs($this->admin)
            ->post(route('categories.store'), ['name' => 'Minuman'])
            ->assertRedirect();

        $category = Category::where('name', 'Minuman')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('categories.update', $category->id), ['name' => 'Minuman Dingin'])
            ->assertRedirect();
        $this->assertSame('Minuman Dingin', $category->fresh()->name);

        $this->actingAs($this->admin)
            ->delete(route('categories.destroy', $category->id))
            ->assertRedirect();
        $this->assertNull(Category::find($category->id));
    }

    public function test_category_name_must_be_unique_and_required(): void
    {
        Category::create(['name' => 'Sudah Ada']);

        $this->actingAs($this->admin)
            ->post(route('categories.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->admin)
            ->post(route('categories.store'), ['name' => 'Sudah Ada'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Category::where('name', 'Sudah Ada')->count());
    }

    public function test_product_rejects_negative_prices_and_stock(): void
    {
        $category = Category::create(['name' => 'Kategori Uji']);

        $this->actingAs($this->admin)
            ->post(route('products.store'), [
                'category_id' => $category->id,
                'title' => 'Produk Cacat',
                'buy_price' => -5000,
                'sell_price' => -1000,
                'stock' => -5,
                'unit' => 'Pcs',
                'type' => 'single',
            ])
            ->assertSessionHasErrors(['buy_price', 'sell_price', 'stock']);

        $this->assertSame(0, Product::where('title', 'Produk Cacat')->count());

        // Harga negatif akan merusak HPP dan laporan laba, jadi wajib ditolak.
        $this->actingAs($this->admin)
            ->post(route('products.store'), [
                'category_id' => $category->id,
                'title' => 'Harga Minus',
                'buy_price' => 10000,
                'sell_price' => -1,
                'stock' => 5,
                'unit' => 'Pcs',
                'type' => 'single',
            ])
            ->assertSessionHasErrors('sell_price');
    }

    public function test_ingredient_crud_round_trip(): void
    {
        $this->actingAs($this->admin)
            ->post(route('ingredients.store'), [
                'name' => 'Beras',
                'buy_price' => 14000,
                'stock' => 100,
                'unit' => 'Kg',
                'min_stock' => 10,
            ])
            ->assertRedirect();

        $ingredient = Ingredient::where('name', 'Beras')->firstOrFail();
        $this->assertSame(14000, (int) $ingredient->buy_price);

        $this->actingAs($this->admin)
            ->put(route('ingredients.update', $ingredient->id), [
                'name' => 'Beras Premium',
                'buy_price' => 16000,
                'stock' => 80,
                'unit' => 'Kg',
                'min_stock' => 10,
            ])
            ->assertRedirect();
        $this->assertSame('Beras Premium', $ingredient->fresh()->name);
        $this->assertSame(16000, (int) $ingredient->fresh()->buy_price);

        $this->actingAs($this->admin)
            ->delete(route('ingredients.destroy', $ingredient->id))
            ->assertRedirect();
        $this->assertNull(Ingredient::find($ingredient->id));
    }

    public function test_table_crud_and_status(): void
    {
        $this->actingAs($this->admin)
            ->post(route('tables.store'), ['name' => 'VIP-1', 'status' => 'available'])
            ->assertRedirect();

        $table = Table::where('name', 'VIP-1')->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('tables.update', $table->id), ['name' => 'VIP-01', 'status' => 'reserved'])
            ->assertRedirect();
        $this->assertSame('VIP-01', $table->fresh()->name);

        $this->actingAs($this->admin)
            ->delete(route('tables.destroy', $table->id))
            ->assertRedirect();
        $this->assertNull(Table::find($table->id));
    }

    public function test_customer_phone_is_stored_as_string_with_leading_zero(): void
    {
        $this->actingAs($this->admin)
            ->post(route('customers.store'), [
                'name' => 'Budi Santoso',
                'no_telp' => '081234567890',
                'address' => 'Jl. Merdeka 1',
            ])
            ->assertRedirect();

        $customer = Customer::where('name', 'Budi Santoso')->firstOrFail();

        // Nomor telepon harus tetap string agar nol di depan tidak hilang.
        $this->assertSame('081234567890', (string) $customer->no_telp);
        $this->assertStringStartsWith('0', (string) $customer->no_telp);
    }

    public function test_customer_requires_name_and_unique_phone(): void
    {
        Customer::create(['name' => 'Sudah Ada', 'no_telp' => '081200000000', 'address' => 'X']);

        $this->actingAs($this->admin)
            ->post(route('customers.store'), ['name' => '', 'no_telp' => '081200000000', 'address' => 'Y'])
            ->assertSessionHasErrors('name');
    }

    public function test_recipe_sync_updates_cost_price(): void
    {
        $category = Category::create(['name' => 'Menu Resep']);
        $menu = Product::create([
            'category_id' => $category->id,
            'title' => 'Kue Lapis',
            'buy_price' => 0,
            'sell_price' => 25000,
            'stock' => 20,
            'unit' => 'Pcs',
        ]);

        $flour = Ingredient::create(['name' => 'Tepung', 'buy_price' => 3000, 'stock' => 1000, 'unit' => 'gram']);
        $sugar = Ingredient::create(['name' => 'Gula', 'buy_price' => 5000, 'stock' => 1000, 'unit' => 'gram']);

        $this->actingAs($this->admin)
            ->post(route('recipes.store'), [
                'product_id' => $menu->id,
                'ingredients' => [
                    ['ingredient_id' => $flour->id, 'qty_needed' => 0.1],
                    ['ingredient_id' => $sugar->id, 'qty_needed' => 0.05],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(2, $menu->recipes()->count());
        // 0.1 x 3000 + 0.05 x 5000 = 300 + 250 = 550
        $this->assertEqualsWithDelta(550.0, (float) $menu->fresh()->cost_price, 0.01);
    }

    public function test_recipe_replaces_previous_recipe_not_appends(): void
    {
        $category = Category::create(['name' => 'Menu Resep 2']);
        $menu = Product::create([
            'category_id' => $category->id,
            'title' => 'Es Krim',
            'buy_price' => 0,
            'sell_price' => 15000,
            'stock' => 20,
            'unit' => 'Pcs',
        ]);

        // Takaran memakai satuan bahan dan harganya masuk akal terhadap harga
        // jual menu Rp 15.000, supaya tidak kena guard HPP > harga jual.
        $susu = Ingredient::create(['name' => 'Susu', 'buy_price' => 26, 'stock' => 1000, 'unit' => 'ml']);
        $cokelat = Ingredient::create(['name' => 'Cokelat', 'buy_price' => 300, 'stock' => 1000, 'unit' => 'ml']);

        $this->actingAs($this->admin)->post(route('recipes.store'), [
            'product_id' => $menu->id,
            'ingredients' => [['ingredient_id' => $susu->id, 'qty_needed' => 100]],
        ])->assertRedirect();
        $this->assertSame(1, $menu->recipes()->count());

        $this->actingAs($this->admin)->post(route('recipes.store'), [
            'product_id' => $menu->id,
            'ingredients' => [['ingredient_id' => $cokelat->id, 'qty_needed' => 50]],
        ])->assertRedirect();

        $this->assertSame(1, $menu->fresh()->recipes()->count(), 'Simpan ulang harus mengganti resep lama, bukan menambah.');
        $this->assertSame($cokelat->id, $menu->fresh()->recipes()->first()->ingredient_id);

        // CATATAN SATUAN: HPP resep = qty_needed x buy_price tanpa konversi
        // satuan. recipes sekarang punya kolom `unit` yang diisi dari satuan
        // bahan, jadi kasir tidak perlu menebak lagi. 50 ml x 300 = 15.000.
        $this->assertSame('ml', $menu->fresh()->recipes()->first()->unit,
            'Satuan bahan harus ikut tersimpan di baris resep.');
        $this->assertEqualsWithDelta(15000.0, (float) $menu->fresh()->cost_price, 0.01);
    }

    public function test_user_cannot_delete_themselves(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('users.destroy', $this->admin->id));

        $this->assertNotNull(User::find($this->admin->id), 'Admin tidak boleh menghapus akunnya sendiri.');
    }

    public function test_system_role_super_admin_cannot_be_deleted(): void
    {
        $role = Role::where('name', 'super-admin')->firstOrFail();

        $this->actingAs($this->admin)
            ->from(route('roles.index'))
            ->delete(route('roles.destroy', $role->id))
            ->assertRedirect(route('roles.index'))
            ->assertSessionHas('error');

        $this->assertNotNull(
            Role::find($role->id),
            'Role super-admin wajib bertahan; menghapusnya akan mengunci refund dan seluruh middleware izin.'
        );
    }

    public function test_role_in_use_by_a_user_cannot_be_deleted(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);

        $this->actingAs($this->admin)
            ->post(route('roles.store'), [
                'name' => 'kasir-uji',
                'selectedPermission' => [$permission->id],
            ])
            ->assertRedirect();

        $role = Role::where('name', 'kasir-uji')->firstOrFail();
        $cashier = User::factory()->create();
        $cashier->assignRole($role);

        $this->actingAs($this->admin)
            ->from(route('roles.index'))
            ->delete(route('roles.destroy', $role->id))
            ->assertRedirect(route('roles.index'))
            ->assertSessionHas('error');

        $this->assertNotNull(Role::find($role->id), 'Role yang masih dipakai tidak boleh hilang diam-diam.');
        $this->assertTrue($cashier->fresh()->hasRole('kasir-uji'));
    }

    public function test_unused_role_can_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('roles.store'), ['name' => 'role-kosong', 'selectedPermission' => []])
            ->assertRedirect();

        $role = Role::where('name', 'role-kosong')->firstOrFail();

        $this->actingAs($this->admin)
            ->from(route('roles.index'))
            ->delete(route('roles.destroy', $role->id))
            ->assertRedirect(route('roles.index'));

        $this->assertNull(Role::find($role->id));
    }
}
