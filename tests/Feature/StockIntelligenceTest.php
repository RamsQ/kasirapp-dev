<?php

namespace Tests\Feature;

use App\Exceptions\StockUnavailableException;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Hold;
use App\Models\Ingredient;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Recipe;
use App\Models\Shift;
use App\Models\StockReservation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StockIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StockIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_reduces_available_stock_without_decrementing_master_stock(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct(stock: 5);
        $hold = $this->createHold($user);
        $service = app(StockIntelligenceService::class);

        $service->reserveForHold($hold, [[
            'product_id' => $product->id,
            'qty' => 2,
        ]]);

        $this->assertDatabaseHas('stock_reservations', [
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 'active',
        ]);
        $this->assertSame(5.0, (float) $product->fresh()->stock);
        $this->assertSame(3.0, $service->availabilityForProduct($product)['available_quantity']);

        $secondHold = $this->createHold($user);
        $this->expectException(StockUnavailableException::class);
        $service->reserveForHold($secondHold, [[
            'product_id' => $product->id,
            'qty' => 4,
        ]]);
    }

    public function test_recipe_and_unit_requirements_are_reserved_against_the_real_resource(): void
    {
        $user = User::factory()->create();
        $ingredient = Ingredient::create([
            'name' => 'Beras',
            'unit' => 'kg',
            'stock' => 10,
            'min_stock' => 2,
            'buy_price' => 10000,
        ]);
        $recipeProduct = $this->createProduct(stock: 0, costPrice: 5000);
        Recipe::create([
            'product_id' => $recipeProduct->id,
            'ingredient_id' => $ingredient->id,
            'qty_needed' => 2,
        ]);
        $recipeHold = $this->createHold($user);
        $service = app(StockIntelligenceService::class);

        $service->reserveForHold($recipeHold, [[
            'product_id' => $recipeProduct->id,
            'qty' => 3,
        ]]);

        $this->assertDatabaseHas('stock_reservations', [
            'hold_id' => $recipeHold->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 6,
            'status' => 'active',
        ]);
        $this->assertSame(2.0, $service->availabilityForProduct($recipeProduct)['available_quantity']);

        $unitProduct = $this->createProduct(stock: 10);
        $unit = ProductUnit::create([
            'product_id' => $unitProduct->id,
            'unit_name' => 'Box',
            'conversion' => 2,
            'sell_price' => 25000,
        ]);
        $unitHold = $this->createHold($user);
        $service->reserveForHold($unitHold, [[
            'product_id' => $unitProduct->id,
            'product_unit_id' => $unit->id,
            'qty' => 2,
        ]]);

        $this->assertDatabaseHas('stock_reservations', [
            'hold_id' => $unitHold->id,
            'product_id' => $unitProduct->id,
            'quantity' => 4,
            'status' => 'active',
        ]);
    }

    public function test_bundle_components_and_invalid_units_are_validated(): void
    {
        $user = User::factory()->create();
        $component = $this->createProduct(stock: 5);
        $bundle = $this->createProduct(stock: 0);
        $bundle->update(['type' => 'bundle']);
        DB::table('product_bundles')->insert([
            'product_id' => $bundle->id,
            'item_id' => $component->id,
            'qty' => 2,
        ]);

        $hold = $this->createHold($user);
        $service = app(StockIntelligenceService::class);
        $this->assertSame(2.5, $service->availabilityForProduct($bundle)['available_quantity']);

        $service->reserveForHold($hold, [[
            'product_id' => $bundle->id,
            'qty' => 2,
        ]]);
        $this->assertDatabaseHas('stock_reservations', [
            'hold_id' => $hold->id,
            'product_id' => $component->id,
            'quantity' => 4,
            'status' => 'active',
        ]);

        $otherProduct = $this->createProduct(stock: 5);
        $foreignUnit = ProductUnit::create([
            'product_id' => $otherProduct->id,
            'unit_name' => 'Box',
            'conversion' => 2,
            'sell_price' => 10000,
        ]);

        $this->expectException(StockUnavailableException::class);
        $service->reserveForHold($this->createHold($user), [[
            'product_id' => $component->id,
            'product_unit_id' => $foreignUnit->id,
            'qty' => 1,
        ]]);
    }

    public function test_expired_reservations_do_not_block_stock_and_can_be_released(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct(stock: 2);
        $hold = $this->createHold($user);
        StockReservation::create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->subMinute(),
        ]);

        $service = app(StockIntelligenceService::class);
        $this->assertSame(2.0, $service->availabilityForProduct($product)['available_quantity']);
        $this->assertSame(1, $service->releaseExpiredReservations());
        $reservationId = StockReservation::where('hold_id', $hold->id)->value('id');
        $this->assertDatabaseHas('stock_reservations', [
            'id' => $reservationId,
            'status' => 'expired',
        ]);
    }

    public function test_public_qr_order_creates_an_active_reservation_and_rejects_overselling(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct(stock: 2);

        $firstResponse = $this->postJson(route('public.menu.order'), [
            'order_type' => 'takeaway',
            'customer_name' => 'QR Customer',
            'cart_items' => [[
                'product_id' => $product->id,
                'qty' => 2,
            ]],
            'total' => $product->sell_price * 2,
        ]);

        $firstResponse->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('stock_reservations', [
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 'active',
        ]);

        $secondResponse = $this->postJson(route('public.menu.order'), [
            'order_type' => 'takeaway',
            'customer_name' => 'QR Customer 2',
            'cart_items' => [[
                'product_id' => $product->id,
                'qty' => 1,
            ]],
            'total' => $product->sell_price,
        ]);

        $secondResponse->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(1, Hold::count());
        $this->assertNotNull($user);
    }

    public function test_gateway_checkout_keeps_hold_reservation_until_payment_is_cancelled(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'transactions-access',
            'guard_name' => 'web',
        ]);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        Shift::create([
            'user_id' => $user->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        PaymentSetting::create([
            'default_gateway' => 'midtrans',
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'server-key',
            'midtrans_client_key' => 'client-key',
        ]);
        Http::fake([
            'https://app.sandbox.midtrans.com/*' => Http::response([
                'order_id' => 'TRX-STOCK',
                'redirect_url' => 'https://pay.midtrans.test/invoice',
                'token' => 'snap-token',
            ], 200),
        ]);

        $product = $this->createProduct(stock: 2);
        $hold = Hold::create([
            'ref_number' => 'QR-GATEWAY',
            'reference_code' => '9001',
            'cart_data' => [[
                'product_id' => $product->id,
                'qty' => 2,
                'price' => $product->sell_price * 2,
            ]],
            'total' => $product->sell_price * 2,
            'user_id' => $user->id,
        ]);
        app(StockIntelligenceService::class)->reserveForHold($hold, $hold->cart_data);
        Cart::create([
            'cashier_id' => $user->id,
            'product_id' => $product->id,
            'qty' => 2,
            'price' => $product->sell_price * 2,
        ]);

        $response = $this->actingAs($user)->post(route('transactions.store'), [
            'hold_id' => $hold->id,
            'payment_gateway' => 'midtrans',
            'cash' => 0,
        ]);
        $response->assertRedirect();

        $transaction = Transaction::latest('id')->first();
        $this->assertNotNull($transaction);
        $this->assertSame('pending', $transaction->payment_status);
        $this->assertDatabaseHas('holds', [
            'id' => $hold->id,
            'transaction_id' => $transaction->id,
        ]);
        $this->assertDatabaseHas('stock_reservations', [
            'hold_id' => $hold->id,
            'status' => 'active',
        ]);
        $this->assertSame(2.0, (float) $product->fresh()->stock);

        $this->postJson(route('transactions.cancel_gateway'), [
            'invoice' => $transaction->invoice,
        ])->assertOk();

        $this->assertDatabaseMissing('holds', ['id' => $hold->id]);
        $this->assertDatabaseMissing('stock_reservations', [
            'hold_id' => $hold->id,
            'status' => 'active',
        ]);
    }

    public function test_midtrans_settlement_consumes_reserved_stock_once(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'transactions-access',
            'guard_name' => 'web',
        ]);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        Shift::create([
            'user_id' => $user->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);
        PaymentSetting::create([
            'default_gateway' => 'midtrans',
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'server-key',
            'midtrans_client_key' => 'client-key',
        ]);
        Http::fake([
            'https://app.sandbox.midtrans.com/*' => Http::response([
                'order_id' => 'TRX-STOCK-SETTLEMENT',
                'redirect_url' => 'https://pay.midtrans.test/invoice',
                'token' => 'snap-token',
            ], 200),
        ]);

        $product = $this->createProduct(stock: 2);
        $hold = Hold::create([
            'ref_number' => 'QR-SETTLEMENT',
            'reference_code' => '9002',
            'cart_data' => [[
                'product_id' => $product->id,
                'qty' => 2,
                'price' => $product->sell_price * 2,
            ]],
            'total' => $product->sell_price * 2,
            'user_id' => $user->id,
        ]);
        app(StockIntelligenceService::class)->reserveForHold($hold, $hold->cart_data);
        Cart::create([
            'cashier_id' => $user->id,
            'product_id' => $product->id,
            'qty' => 2,
            'price' => $product->sell_price * 2,
        ]);

        $this->actingAs($user)->post(route('transactions.store'), [
            'hold_id' => $hold->id,
            'payment_gateway' => 'midtrans',
            'cash' => 0,
        ])->assertRedirect();

        $transaction = Transaction::latest('id')->firstOrFail();
        $payload = [
            'transaction_id' => $transaction->invoice,
            'order_id' => $transaction->invoice,
            'transaction_status' => 'settlement',
            'gross_amount' => (string) $transaction->grand_total,
        ];
        Http::fake([
            'https://api.sandbox.midtrans.com/*' => Http::response([
                'order_id' => $transaction->invoice,
                'transaction_status' => 'settlement',
                'fraud_status' => 'trusted',
                'gross_amount' => $transaction->grand_total,
            ], 200),
        ]);
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['transaction_status'].$payload['gross_amount'].'server-key'
        );

        $this->postJson(route('payment.notification'), $payload)->assertOk();
        $this->assertSame('paid', $transaction->fresh()->payment_status);
        $this->assertSame(0.0, (float) $product->fresh()->stock);
        $this->assertDatabaseMissing('holds', ['id' => $hold->id]);

        // Duplicate notification must be idempotent.
        $this->postJson(route('payment.notification'), $payload)->assertOk();
        $this->assertSame(0.0, (float) $product->fresh()->stock);
    }

    protected function createProduct(float $stock, float $costPrice = 0): Product
    {
        $category = Category::create([
            'name' => 'Kategori '.uniqid(),
            'description' => 'Kategori pengujian',
        ]);

        return Product::create([
            'category_id' => $category->id,
            'title' => 'Produk '.uniqid(),
            'buy_price' => 10000,
            'sell_price' => 20000,
            'stock' => $stock,
            'cost_price' => $costPrice,
            'unit' => 'Pcs',
        ]);
    }

    protected function createHold(User $user): Hold
    {
        return Hold::create([
            'ref_number' => 'HOLD-'.uniqid(),
            'cart_data' => [],
            'total' => 0,
            'user_id' => $user->id,
        ]);
    }
}
