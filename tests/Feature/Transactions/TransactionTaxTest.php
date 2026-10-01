<?php

namespace Tests\Feature\Transactions;

use App\Events\TransactionCreated;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TransactionTaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('transactions.index', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Event::fake([TransactionCreated::class]);
    }

    public function test_cash_checkout_persists_the_tax_snapshot_and_uses_tax_inclusive_change(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('transactions.index');
        Shift::create([
            'user_id' => $user->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);
        Setting::create([
            'cogs_method' => 'AVERAGE',
            'tax_enabled' => true,
            'tax_rate' => 11,
        ]);
        $category = Category::create([
            'name' => 'Kategori Pajak',
            'description' => 'Kategori pajak',
            'image' => 'category.png',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'TAX-001',
            'title' => 'Produk Pajak',
            'description' => 'Produk untuk uji pajak',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 20,
        ]);
        Cart::create([
            'cashier_id' => $user->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 10000,
            'notes' => 'Tidak pedas',
        ]);

        $response = $this->actingAs($user)->postJson(route('transactions.store'), [
            'grand_total' => 1,
            'tax_rate' => 99,
            'tax_amount' => 999999,
            'cash' => 20000,
            'change' => 99999,
            'payment_gateway' => 'cash',
            'table_name' => 'TAKE AWAY',
            'customer_name' => 'Pelanggan Pajak',
        ]);

        $response->assertOk()
            ->assertJsonPath('grand_total', 11100)
            ->assertJsonPath('taxable_amount', 10000)
            ->assertJsonPath('tax_rate', 11)
            ->assertJsonPath('tax_amount', 1100);

        $transaction = Transaction::latest('id')->first();

        $this->assertSame(10000, $transaction->taxable_amount);
        $this->assertSame(11.0, (float) $transaction->tax_rate);
        $this->assertSame(1100, $transaction->tax_amount);
        $this->assertSame(11100, $transaction->grand_total);
        $this->assertSame(8900, $transaction->change);
        $this->assertSame('completed', $transaction->kitchen_status);
        $this->assertSame('Tidak pedas', $transaction->details()->first()->notes);
    }

    public function test_cash_checkout_rejects_underpayment_after_tax(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('transactions.index');
        Shift::create([
            'user_id' => $user->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);
        Setting::create([
            'cogs_method' => 'AVERAGE',
            'tax_enabled' => true,
            'tax_rate' => 10,
        ]);
        $category = Category::create([
            'name' => 'Kategori Underpayment',
            'description' => 'Kategori underpayment',
            'image' => 'category.png',
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'TAX-002',
            'title' => 'Produk Underpayment',
            'description' => 'Produk untuk uji underpayment',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 20,
        ]);
        Cart::create([
            'cashier_id' => $user->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 10000,
            'notes' => 'Tidak pedas',
        ]);

        $this->actingAs($user)->postJson(route('transactions.store'), [
            'cash' => 10000,
            'payment_gateway' => 'cash',
            'table_name' => 'TAKE AWAY',
            'customer_name' => 'Pelanggan Kurang Bayar',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('transactions', 0);
    }
}
