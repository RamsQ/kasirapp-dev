<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Hold;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class KitchenDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('kitchen-display.index', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('kitchen-display.index');

        $category = Category::create([
            'name' => 'Kategori Kitchen',
            'description' => 'Kategori untuk KDS',
            'image' => 'category.png',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'KDS-001',
            'title' => 'Nasi Goreng Kitchen',
            'description' => 'Produk untuk Kitchen Display',
            'buy_price' => 10000,
            'sell_price' => 20000,
            'stock' => 20,
        ]);
    }

    public function test_kitchen_display_is_optional_and_lists_active_paid_and_unpaid_orders(): void
    {
        Setting::create([
            'cogs_method' => 'AVERAGE',
            'tax_enabled' => false,
            'tax_rate' => null,
            'kitchen_display_enabled' => true,
            'kitchen_display_payment_status' => 'both',
        ]);

        $paid = $this->createTransaction('paid');
        $pending = $this->createTransaction('pending');
        $hold = Hold::create([
            'ref_number' => 'HOLD-KDS-001',
            'reference_code' => 'KDS1',
            'queue_number' => 'Q-901',
            'customer_name' => 'Pelanggan Dapur',
            'cart_data' => [[
                'product_id' => $this->product->id,
                'product' => ['title' => $this->product->title],
                'qty' => 2,
                'price' => 40000,
                'notes' => 'Pedas',
            ]],
            'total' => 40000,
            'user_id' => $this->user->id,
            'kitchen_status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('kitchen.index'));

        $response->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->component('Dashboard/Kitchen/Index')
                ->where('enabled', true)
                ->where('paymentFilter', 'both')
                ->has('orders', 3)
        );

        $ordersResponse = $this->actingAs($this->user)
            ->get(route('kitchen.orders'))
            ->assertOk()
            ->assertJsonCount(3, 'orders');

        $this->assertEqualsCanonicalizing(
            ['transaction-'.$paid->id, 'transaction-'.$pending->id, 'hold-'.$hold->id],
            $ordersResponse->json('orders.*.key')
        );
    }

    public function test_kitchen_display_can_filter_only_unpaid_orders(): void
    {
        Setting::create([
            'cogs_method' => 'AVERAGE',
            'kitchen_display_enabled' => true,
            'kitchen_display_payment_status' => 'unpaid',
        ]);

        $this->createTransaction('paid');
        $pending = $this->createTransaction('pending');
        $hold = Hold::create([
            'ref_number' => 'HOLD-KDS-UNPAID',
            'cart_data' => [[
                'product_id' => $this->product->id,
                'product' => ['title' => $this->product->title],
                'qty' => 1,
                'price' => 20000,
            ]],
            'total' => 20000,
            'user_id' => $this->user->id,
            'kitchen_status' => 'preparing',
        ]);

        $response = $this->actingAs($this->user)->get(route('kitchen.orders'));

        $response->assertOk()->assertJsonCount(2, 'orders');
        $this->assertEqualsCanonicalizing(
            ['transaction-'.$pending->id, 'hold-'.$hold->id],
            $response->json('orders.*.key')
        );
    }

    public function test_public_order_note_reaches_kitchen_display(): void
    {
        Setting::create([
            'cogs_method' => 'AVERAGE',
            'kitchen_display_enabled' => true,
            'kitchen_display_payment_status' => 'unpaid',
        ]);

        $this->postJson(route('public.menu.order'), [
            'customer_name' => 'Pelanggan Catatan',
            'order_type' => 'takeaway',
            'cart_items' => [[
                'product_id' => $this->product->id,
                'qty' => 1,
                'notes' => 'Tidak pedas, sambal terpisah',
            ]],
            'total' => 20000,
        ])->assertOk();

        $hold = Hold::latest('id')->first();
        $this->assertSame('Tidak pedas, sambal terpisah', $hold->cart_data[0]['notes']);

        $response = $this->actingAs($this->user)->get(route('kitchen.orders'));
        $response->assertOk();
        $this->assertSame(
            'Tidak pedas, sambal terpisah',
            collect($response->json('orders'))->firstWhere('source_type', 'hold')['items'][0]['notes']
        );
    }

    public function test_kitchen_staff_can_advance_order_status(): void
    {
        Setting::create([
            'cogs_method' => 'AVERAGE',
            'kitchen_display_enabled' => true,
            'kitchen_display_payment_status' => 'both',
        ]);
        $transaction = $this->createTransaction('paid');

        $this->actingAs($this->user)
            ->post(route('kitchen.status', [
                'type' => 'transaction',
                'id' => $transaction->id,
            ]), ['status' => 'preparing'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order.kitchen_status', 'preparing');

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'kitchen_status' => 'preparing',
        ]);
    }

    public function test_kitchen_staff_cannot_move_an_order_backwards(): void
    {
        Setting::create([
            'cogs_method' => 'AVERAGE',
            'kitchen_display_enabled' => true,
            'kitchen_display_payment_status' => 'both',
        ]);
        $transaction = $this->createTransaction('paid');
        $transaction->update(['kitchen_status' => 'ready']);

        $this->actingAs($this->user)
            ->post(route('kitchen.status', [
                'type' => 'transaction',
                'id' => $transaction->id,
            ]), ['status' => 'pending'])
            ->assertStatus(422);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'kitchen_status' => 'ready',
        ]);
    }

    public function test_kitchen_display_does_not_expose_orders_when_disabled(): void
    {
        Setting::create([
            'cogs_method' => 'AVERAGE',
            'kitchen_display_enabled' => false,
            'kitchen_display_payment_status' => 'both',
        ]);
        $this->createTransaction('paid');

        $this->actingAs($this->user)
            ->get(route('kitchen.orders'))
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonCount(0, 'orders');
    }

    public function test_authorized_user_can_configure_kitchen_display_settings(): void
    {
        Permission::findOrCreate('settings.index', 'web');
        Permission::findOrCreate('settings.cgos', 'web');
        $this->user->givePermissionTo('settings.index', 'settings.cgos');

        $this->actingAs($this->user)
            ->post(route('settings.update'), [
                'cogs_method' => 'AVERAGE',
                'tax_enabled' => false,
                'tax_rate' => null,
                'kitchen_display_enabled' => true,
                'kitchen_display_payment_status' => 'unpaid',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('settings', [
            'kitchen_display_enabled' => true,
            'kitchen_display_payment_status' => 'unpaid',
        ]);
    }

    private function createTransaction(string $paymentStatus): Transaction
    {
        $transaction = Transaction::create([
            'cashier_id' => $this->user->id,
            'invoice' => 'TRX-KDS-'.strtoupper($paymentStatus),
            'reference_code' => strtoupper($paymentStatus),
            'customer_name' => 'Pelanggan '.$paymentStatus,
            'cash' => 20000,
            'change' => 0,
            'discount' => 0,
            'taxable_amount' => 20000,
            'tax_rate' => null,
            'tax_amount' => 0,
            'grand_total' => 20000,
            'payment_method' => 'cash',
            'payment_status' => $paymentStatus,
            'status' => 'paid',
            'kitchen_status' => 'pending',
            'table_name' => 'TAKE AWAY',
            'queue_number' => 'Q-'.$paymentStatus,
        ]);

        $transaction->details()->create([
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 20000,
            'notes' => null,
        ]);

        return $transaction;
    }
}
