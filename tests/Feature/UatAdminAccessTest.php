<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Setelah database dibersihkan untuk UAT, pastikan satu-satunya akun
 * superadmin benar-benar bisa login dan membuka semua modul inti.
 */
class UatAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_single_superadmin_can_log_in_and_reach_core_modules(): void
    {
        $admin = User::create([
            'name' => 'Super Administrator',
            'email' => 'admin@gmail.com',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('super-admin');

        $this->post(route('login'), [
            'email' => 'admin@gmail.com',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);

        $pages = [
            ['dashboard', 'Dashboard/Index'],
            ['transactions.index', 'Dashboard/Transactions/Index'],
            ['products.index', 'Dashboard/Products/Index'],
            ['customers.index', 'Dashboard/Customers/Index'],
            ['crm.campaigns.index', 'Dashboard/Crm/Campaigns'],
            ['settings.payment-settlements.index', 'Dashboard/Settings/PaymentSettlements'],
            ['ai.index', 'Dashboard/Ai/Consultation'],
            ['discounts.index', 'Dashboard/Discounts/Index'],
        ];

        foreach ($pages as [$routeName, $component]) {
            $this->get(route($routeName))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    public function test_core_data_starts_empty(): void
    {
        $this->assertSame(0, \App\Models\Product::count());
        $this->assertSame(0, \App\Models\Category::count());
        $this->assertSame(0, \App\Models\Customer::count());
        $this->assertSame(0, \App\Models\Transaction::count());
        $this->assertSame(0, \App\Models\StockBatch::count());
        $this->assertSame(0, \App\Models\CrmCampaign::count());
    }

    public function test_cashier_can_complete_a_sale_on_an_empty_database(): void
    {
        $cashier = User::factory()->create();
        $cashier->givePermissionTo('transactions-access');
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);
        \App\Models\Shift::create([
            'user_id' => $cashier->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $category = \App\Models\Category::create(['name' => 'Menu UAT']);
        $product = \App\Models\Product::create([
            'category_id' => $category->id,
            'title' => 'Nasi Goreng',
            'buy_price' => 15000,
            'sell_price' => 30000,
            'stock' => 10,
        ]);

        \App\Models\Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 2,
            'price' => 60000,
        ]);

        $this->actingAs($cashier)
            ->post(route('transactions.store'), [
                'payment_gateway' => 'cash',
                'cash' => 60000,
                'change' => 0,
            ])
            ->assertRedirect();

        $transaction = \App\Models\Transaction::latest('id')->firstOrFail();

        $this->assertSame('paid', $transaction->payment_status);
        $this->assertSame(60000, (int) $transaction->grand_total);

        // HPP harus benar: 2 x 15.000
        $this->assertSame(30000, (int) $transaction->details()->sum(
            \Illuminate\Support\Facades\DB::raw('buy_price * qty')
        ));
    }
}
