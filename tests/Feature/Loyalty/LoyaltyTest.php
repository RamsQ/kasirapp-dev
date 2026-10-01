<?php

namespace Tests\Feature\Loyalty;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerVoucher;
use App\Models\LoyaltyRule;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LoyaltyTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_transaction_awards_points_once_and_refund_reverses_them(): void
    {
        $customer = $this->customer();
        $transaction = $this->paidTransaction($customer, 125000);
        $service = app(LoyaltyService::class);

        $service->recordPaidTransaction($transaction);
        $service->recordPaidTransaction($transaction->fresh());

        $this->assertSame(1250, (int) $customer->fresh()->loyalty_points);
        $this->assertSame('platinum', $customer->fresh()->loyalty_tier);
        $this->assertSame(125000, (int) $customer->fresh()->lifetime_spend);
        $this->assertDatabaseCount('loyalty_point_ledger', 1);
        $this->assertDatabaseHas('loyalty_point_ledger', [
            'transaction_id' => $transaction->id,
            'type' => 'earn',
            'points' => 1250,
            'balance_after' => 1250,
        ]);

        $transaction->update(['status' => 'refunded']);
        $service->reverseTransaction($transaction->fresh());
        $service->reverseTransaction($transaction->fresh());

        $this->assertSame(0, (int) $customer->fresh()->loyalty_points);
        $this->assertSame(0, (int) $customer->fresh()->lifetime_spend);
        $this->assertDatabaseCount('loyalty_point_ledger', 2);
        $this->assertDatabaseHas('loyalty_point_ledger', [
            'transaction_id' => $transaction->id,
            'type' => 'reversal',
            'points' => -1250,
            'balance_after' => 0,
        ]);
    }

    public function test_active_rule_overrides_global_points_when_minimum_spend_matches(): void
    {
        $customer = $this->customer();
        $transaction = $this->paidTransaction($customer, 125000);
        $rule = LoyaltyRule::create([
            'name' => 'Member Bronze',
            'min_spend' => 100000,
            'points' => 25,
            'priority' => 10,
            'is_active' => true,
        ]);

        app(LoyaltyService::class)->recordPaidTransaction($transaction);

        $this->assertSame(25, (int) $customer->fresh()->loyalty_points);
        $this->assertSame($rule->id, (int) $transaction->fresh()->loyalty_rule_id);
        $this->assertSame(25, (int) $transaction->fresh()->loyalty_points_awarded);
        $this->assertDatabaseHas('loyalty_point_ledger', [
            'transaction_id' => $transaction->id,
            'loyalty_rule_id' => $rule->id,
            'points' => 25,
        ]);
    }

    public function test_rule_can_require_a_product_and_respects_date_range(): void
    {
        $customer = $this->customer();
        $transaction = $this->paidTransaction($customer, 125000);
        $productId = (int) $transaction->details()->value('product_id');
        $expiredRule = LoyaltyRule::create([
            'name' => 'Produk Lama',
            'min_spend' => 50000,
            'points' => 50,
            'product_id' => $productId,
            'ends_at' => now()->subDay()->toDateString(),
        ]);
        $activeRule = LoyaltyRule::create([
            'name' => 'Produk Member',
            'min_spend' => 50000,
            'points' => 15,
            'product_id' => $productId,
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addDay()->toDateString(),
            'priority' => 1,
        ]);

        app(LoyaltyService::class)->recordPaidTransaction($transaction);

        $this->assertSame(15, (int) $customer->fresh()->loyalty_points);
        $this->assertSame($activeRule->id, (int) $transaction->fresh()->loyalty_rule_id);
        $this->assertNotSame($expiredRule->id, $transaction->fresh()->loyalty_rule_id);
    }

    public function test_cash_checkout_applies_voucher_before_tax_and_awards_points(): void
    {
        $cashier = User::factory()->create();
        $cashier->givePermissionTo(Permission::firstOrCreate([
            'name' => 'transactions-access',
            'guard_name' => 'web',
        ]));
        Shift::create([
            'user_id' => $cashier->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);
        $customer = $this->customer();
        $voucher = CustomerVoucher::create([
            'customer_id' => $customer->id,
            'code' => 'CHECKOUT10',
            'name' => 'Checkout Voucher',
            'type' => 'fixed',
            'value' => 10000,
        ]);
        $category = Category::create(['name' => 'Checkout Loyalty']);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'Produk Checkout Loyalty',
            'buy_price' => 40000,
            'sell_price' => 100000,
            'stock' => 5,
            'unit' => 'Pcs',
        ]);
        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 100000,
        ]);

        $this->actingAs($cashier)
            ->post(route('transactions.store'), [
                'customer_id' => $customer->id,
                'voucher_code' => 'checkout10',
                'cash' => 100000,
                'payment_gateway' => 'cash',
            ])
            ->assertRedirect();

        $transaction = Transaction::latest('id')->first();
        $this->assertSame(90000, (int) $transaction->grand_total);
        $this->assertSame(10000, (int) $transaction->discount);
        $this->assertSame(10000, (int) $transaction->voucher_discount);
        $this->assertSame('used', $voucher->fresh()->status);
        $this->assertSame(900, (int) $customer->fresh()->loyalty_points);
    }

    public function test_pending_transaction_does_not_earn_points(): void
    {
        $customer = $this->customer();
        $transaction = $this->paidTransaction($customer, 50000);
        $transaction->update(['payment_status' => 'pending']);

        app(LoyaltyService::class)->recordPaidTransaction($transaction);

        $this->assertSame(0, (int) $customer->fresh()->loyalty_points);
        $this->assertDatabaseCount('loyalty_point_ledger', 0);
    }

    public function test_global_loyalty_setting_can_pause_earning(): void
    {
        Setting::create([
            'cogs_method' => 'AVERAGE',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => false,
            'loyalty_points_per_100' => 1,
            'kitchen_display_enabled' => false,
            'kitchen_display_payment_status' => 'both',
        ]);
        $customer = $this->customer();
        $transaction = $this->paidTransaction($customer, 100000);

        app(LoyaltyService::class)->recordPaidTransaction($transaction);

        $this->assertSame(0, (int) $customer->fresh()->loyalty_points);
        $this->assertDatabaseCount('loyalty_point_ledger', 0);
    }

    public function test_manual_adjustment_is_recorded_in_ledger(): void
    {
        $customer = $this->customer();
        $service = app(LoyaltyService::class);

        $service->adjustPoints($customer, 75, 'Welcome bonus');
        $service->adjustPoints($customer->fresh(), -25, 'Koreksi bonus');

        $this->assertSame(50, (int) $customer->fresh()->loyalty_points);
        $this->assertDatabaseCount('loyalty_point_ledger', 2);
        $this->assertDatabaseHas('loyalty_point_ledger', [
            'type' => 'adjustment',
            'points' => 75,
            'balance_after' => 75,
        ]);
        $this->assertDatabaseHas('loyalty_point_ledger', [
            'type' => 'adjustment',
            'points' => -25,
            'balance_after' => 50,
        ]);
    }

    public function test_voucher_can_be_reserved_consumed_and_released(): void
    {
        $customer = $this->customer();
        $voucher = CustomerVoucher::create([
            'customer_id' => $customer->id,
            'code' => 'WELCOME10',
            'name' => 'Welcome Voucher',
            'type' => 'fixed',
            'value' => 10000,
            'min_spend' => 50000,
        ]);
        $service = app(LoyaltyService::class);
        $transaction = $this->paidTransaction($customer, 100000);
        $transaction->update(['payment_status' => 'pending']);

        $discount = $service->reserveVoucher($customer, 'welcome10', 100000, $transaction);

        $this->assertSame(10000, $discount);
        $this->assertSame('reserved', $voucher->fresh()->status);
        $this->assertSame($transaction->id, (int) $voucher->fresh()->reserved_transaction_id);

        $service->consumeVoucher($transaction);
        $this->assertSame('used', $voucher->fresh()->status);
        $this->assertSame($transaction->id, (int) $voucher->fresh()->used_transaction_id);
        $this->assertNull($voucher->fresh()->reserved_transaction_id);

        CustomerVoucher::create([
            'customer_id' => $customer->id,
            'code' => 'WELCOME20',
            'name' => 'Second Voucher',
            'type' => 'fixed',
            'value' => 5000,
        ]);
        $second = $this->paidTransaction($customer, 100000);
        $second->update(['payment_status' => 'pending']);
        $service->reserveVoucher($customer, 'WELCOME20', 100000, $second);
        $service->releaseVoucher($second);
        $this->assertSame('active', CustomerVoucher::where('code', 'WELCOME20')->value('status'));
        $this->assertNull(CustomerVoucher::where('code', 'WELCOME20')->value('reserved_transaction_id'));
    }

    public function test_customer_loyalty_page_and_voucher_preview_are_available(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'customers-access',
            'guard_name' => 'web',
        ]);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        $customer = $this->customer();
        CustomerVoucher::create([
            'customer_id' => $customer->id,
            'code' => 'CRM10',
            'name' => 'CRM Voucher',
            'type' => 'percentage',
            'value' => 10,
        ]);

        $this->actingAs($user)
            ->get(route('customers.loyalty', $customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Customers/Loyalty')
                ->where('customer.id', $customer->id)
                ->where('summary.tier.name', 'bronze')
                ->where('canManageLoyalty', false));

        $this->actingAs($user)
            ->getJson(route('customers.history', $customer))
            ->assertOk()
            ->assertJsonPath('customer.id', $customer->id);

        $this->actingAs($user)
            ->get(route('customers.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Customers/Index')
                ->where('topCustomers.0.id', $customer->id));

        $this->actingAs($user)
            ->getJson(route('customers.voucher-preview', $customer).'?code=CRM10&amount=100000')
            ->assertOk()
            ->assertJsonPath('discount', 10000);
    }

    public function test_settings_admin_can_manage_loyalty_rules(): void
    {
        $settingsIndex = Permission::firstOrCreate([
            'name' => 'settings.index',
            'guard_name' => 'web',
        ]);
        $settingsUpdate = Permission::firstOrCreate([
            'name' => 'settings.update',
            'guard_name' => 'web',
        ]);
        $user = User::factory()->create();
        $user->givePermissionTo([$settingsIndex, $settingsUpdate]);

        $this->actingAs($user)
            ->get(route('discounts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Discounts/Index')
                ->where('canManageLoyalty', true));

        $this->actingAs($user)
            ->post(route('discounts.loyalty-rules.store'), [
                'name' => 'Rule Admin',
                'min_spend' => 75000,
                'points' => 12,
                'priority' => 3,
                'is_active' => true,
            ])
            ->assertRedirect();

        $rule = LoyaltyRule::where('name', 'Rule Admin')->firstOrFail();
        $this->actingAs($user)
            ->put(route('discounts.loyalty-rules.update', $rule), [
                'name' => 'Rule Admin Updated',
                'min_spend' => 100000,
                'points' => 20,
                'priority' => 4,
                'is_active' => true,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('loyalty_rules', [
            'id' => $rule->id,
            'name' => 'Rule Admin Updated',
            'min_spend' => 100000,
            'points' => 20,
        ]);

        $this->actingAs($user)
            ->delete(route('discounts.loyalty-rules.destroy', $rule))
            ->assertRedirect();
        $this->assertDatabaseMissing('loyalty_rules', ['id' => $rule->id]);
    }

    protected function customer(): Customer
    {
        return Customer::create([
            'name' => 'Loyal Customer '.Str::random(4),
            'no_telp' => '628'.random_int(10000000, 99999999),
            'address' => 'Alamat Pengujian',
            'loyalty_enabled' => true,
        ]);
    }

    protected function paidTransaction(Customer $customer, int $amount): Transaction
    {
        $category = Category::create(['name' => 'Loyalty '.Str::random(4)]);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'Produk Loyalty',
            'buy_price' => 10000,
            'sell_price' => $amount,
            'stock' => 10,
            'unit' => 'Pcs',
        ]);

        $transaction = Transaction::create([
            'cashier_id' => User::factory()->create()->id,
            'customer_id' => $customer->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(10)),
            'customer_name' => $customer->name,
            'cash' => $amount,
            'change' => 0,
            'discount' => 0,
            'taxable_amount' => $amount,
            'tax_amount' => 0,
            'grand_total' => $amount,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id,
            'qty' => 1,
            'price' => $amount,
            'buy_price' => 10000,
        ]);

        return $transaction;
    }
}
