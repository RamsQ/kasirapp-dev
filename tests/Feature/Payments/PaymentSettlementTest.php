<?php

namespace Tests\Feature\Payments;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerVoucher;
use App\Models\PaymentEvent;
use App\Models\PaymentSetting;
use App\Models\PaymentSettlement;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Services\Payments\PaymentSettlementService;
use App\Services\Payments\XenditGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PaymentSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_midtrans_settlement_is_audited_and_idempotent(): void
    {
        $setting = PaymentSetting::create([
            'default_gateway' => 'midtrans',
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'midtrans-secret',
            'midtrans_client_key' => 'midtrans-client',
        ]);
        [$transaction, $product] = $this->createPendingTransaction('midtrans');

        $service = app(PaymentSettlementService::class);
        $service->begin($transaction, 'midtrans');

        $payload = [
            'order_id' => $transaction->invoice,
            'transaction_status' => 'settlement',
            'gross_amount' => (string) $transaction->grand_total,
        ];
        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['transaction_status'].$payload['gross_amount'].$setting->midtrans_server_key,
        );

        $this->postJson(route('payment.notification'), $payload)
            ->assertOk()
            ->assertJsonPath('result.status', 'paid');

        $this->assertSame('paid', $transaction->fresh()->payment_status);
        $this->assertNotNull($transaction->fresh()->paid_at);
        $this->assertSame(3.0, (float) $product->fresh()->stock);
        $this->assertDatabaseHas('payment_settlements', [
            'transaction_id' => $transaction->id,
            'provider' => 'midtrans',
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('payment_events', [
            'transaction_id' => $transaction->id,
            'provider' => 'midtrans',
            'status' => 'settlement',
            'signature_valid' => true,
            'processed' => true,
        ]);

        // Same provider callback must be safe to retry.
        $this->postJson(route('payment.notification'), $payload)->assertOk();
        $this->assertSame(3.0, (float) $product->fresh()->stock);
        $this->assertSame(2, PaymentEvent::where('transaction_id', $transaction->id)->count());
    }

    public function test_invalid_midtrans_signature_is_rejected_without_changing_stock(): void
    {
        PaymentSetting::create([
            'default_gateway' => 'midtrans',
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'midtrans-secret',
            'midtrans_client_key' => 'midtrans-client',
        ]);
        [$transaction, $product] = $this->createPendingTransaction('midtrans');
        app(PaymentSettlementService::class)->begin($transaction, 'midtrans');

        $this->postJson(route('payment.notification'), [
            'order_id' => $transaction->invoice,
            'transaction_status' => 'settlement',
            'gross_amount' => (string) $transaction->grand_total,
            'signature_key' => 'invalid',
        ])->assertForbidden();

        $this->assertSame('pending', $transaction->fresh()->payment_status);
        $this->assertSame(5.0, (float) $product->fresh()->stock);
        $this->assertDatabaseHas('payment_events', [
            'transaction_id' => $transaction->id,
            'signature_valid' => false,
            'processed' => false,
        ]);
    }

    public function test_xendit_callback_requires_token_and_can_settle_payment(): void
    {
        PaymentSetting::create([
            'default_gateway' => 'xendit',
            'xendit_enabled' => true,
            'xendit_secret_key' => 'xendit-secret',
            'xendit_callback_token' => 'xendit-callback-token',
            'xendit_public_key' => 'xendit-public',
        ]);
        [$transaction, $product] = $this->createPendingTransaction('xendit');
        $transaction->update(['payment_reference' => 'xendit-invoice-123']);
        $service = app(PaymentSettlementService::class);
        $service->begin($transaction, 'xendit');

        $payload = [
            'id' => 'xendit-invoice-123',
            'external_id' => $transaction->invoice,
            'status' => 'PAID',
            'amount' => $transaction->grand_total,
        ];

        $this->withHeader('x-callback-token', 'wrong-token')
            ->postJson(route('payment.xendit.notification'), $payload)
            ->assertForbidden();
        $this->assertSame('pending', $transaction->fresh()->payment_status);
        $this->assertSame(5.0, (float) $product->fresh()->stock);

        $this->withHeader('x-callback-token', 'xendit-callback-token')
            ->postJson(route('payment.xendit.notification'), $payload)
            ->assertOk()
            ->assertJsonPath('result.status', 'paid');

        $this->assertSame('paid', $transaction->fresh()->payment_status);
        $this->assertSame(3.0, (float) $product->fresh()->stock);
        $this->assertDatabaseHas('payment_events', [
            'transaction_id' => $transaction->id,
            'provider' => 'xendit',
            'status' => 'PAID',
            'signature_valid' => true,
        ]);
    }

    public function test_settlement_awards_customer_points_and_consumes_reserved_voucher(): void
    {
        PaymentSetting::create([
            'default_gateway' => 'xendit',
            'xendit_enabled' => true,
            'xendit_secret_key' => 'xendit-secret',
            'xendit_callback_token' => 'xendit-callback-token',
            'xendit_public_key' => 'xendit-public',
        ]);
        [$transaction] = $this->createPendingTransaction('xendit');
        $customer = Customer::create([
            'name' => 'Gateway Loyalty Customer',
            'no_telp' => 628123451,
            'address' => 'Alamat Gateway',
            'loyalty_enabled' => true,
        ]);
        $voucher = CustomerVoucher::create([
            'customer_id' => $customer->id,
            'code' => 'GATEWAY10',
            'name' => 'Gateway Voucher',
            'type' => 'fixed',
            'value' => 5000,
        ]);
        $transaction->update([
            'customer_id' => $customer->id,
            'taxable_amount' => 40000,
            'voucher_code' => 'GATEWAY10',
            'voucher_discount' => 5000,
        ]);
        app(LoyaltyService::class)->reserveVoucher($customer, 'GATEWAY10', 40000, $transaction);
        $transaction->update(['payment_reference' => 'xendit-loyalty-123']);
        app(PaymentSettlementService::class)->begin($transaction, 'xendit');

        $this->withHeader('x-callback-token', 'xendit-callback-token')
            ->postJson(route('payment.xendit.notification'), [
                'id' => 'xendit-loyalty-123',
                'external_id' => $transaction->invoice,
                'status' => 'PAID',
                'amount' => $transaction->grand_total,
            ])
            ->assertOk();

        $this->assertSame(400, (int) $customer->fresh()->loyalty_points);
        $this->assertSame('used', $voucher->fresh()->status);
        $this->assertDatabaseHas('loyalty_point_ledger', [
            'transaction_id' => $transaction->id,
            'type' => 'earn',
        ]);
    }

    public function test_reconciliation_queries_provider_and_marks_settlement_paid(): void
    {
        PaymentSetting::create([
            'default_gateway' => 'xendit',
            'xendit_enabled' => true,
            'xendit_secret_key' => 'xendit-secret',
            'xendit_callback_token' => 'xendit-callback-token',
            'xendit_public_key' => 'xendit-public',
        ]);
        [$transaction] = $this->createPendingTransaction('xendit');
        $transaction->update(['payment_reference' => 'xendit-invoice-456']);
        $service = app(PaymentSettlementService::class);
        $service->begin($transaction, 'xendit');

        Http::fake([
            'https://api.xendit.co/v2/invoices/xendit-invoice-456' => Http::response([
                'id' => 'xendit-invoice-456',
                'external_id' => $transaction->invoice,
                'status' => 'SETTLED',
                'amount' => $transaction->grand_total,
            ], 200),
        ]);

        $result = $service->reconcilePending();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['paid']);
        $this->assertSame('paid', $transaction->fresh()->payment_status);
        $this->assertDatabaseHas('payment_events', [
            'transaction_id' => $transaction->id,
            'provider' => 'xendit',
            'status' => 'paid',
        ]);
        $this->assertSame(1, PaymentSettlement::where('transaction_id', $transaction->id)->value('attempt_count'));
    }

    public function test_xendit_invoice_registers_callback_url(): void
    {
        $setting = PaymentSetting::create([
            'default_gateway' => 'xendit',
            'xendit_enabled' => true,
            'xendit_secret_key' => 'xendit-secret',
            'xendit_callback_token' => 'xendit-callback-token',
            'xendit_public_key' => 'xendit-public',
        ]);
        [$transaction] = $this->createPendingTransaction('xendit');
        Http::fake([
            'https://api.xendit.co/v2/invoices' => Http::response([
                'id' => 'xendit-invoice-789',
                'invoice_url' => 'https://checkout.xendit.test/invoice',
            ], 200),
        ]);

        $response = app(XenditGateway::class)->createInvoice($transaction, $setting->xenditConfig());

        $this->assertSame('xendit-invoice-789', $response['reference']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.xendit.co/v2/invoices'
            && ($request['callback_url'] ?? null) === route('payment.xendit.notification'));
    }

    public function test_pending_cancellation_keeps_audit_record_and_releases_state(): void
    {
        [$transaction] = $this->createPendingTransaction('midtrans');
        $service = app(PaymentSettlementService::class);
        $service->begin($transaction, 'midtrans');

        $service->cancelPending($transaction, 'Cashier closed payment window');

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'payment_status' => 'failed',
        ]);
        $this->assertDatabaseCount('transaction_details', 1);
        $this->assertDatabaseHas('payment_settlements', [
            'transaction_id' => $transaction->id,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('payment_events', [
            'transaction_id' => $transaction->id,
            'provider' => 'midtrans',
            'status' => 'cancel',
        ]);
    }

    public function test_settlement_center_page_is_available_to_settings_users(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'settings.index',
            'guard_name' => 'web',
        ]);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        [$transaction] = $this->createPendingTransaction('midtrans');
        app(PaymentSettlementService::class)->begin($transaction, 'midtrans');

        $this->actingAs($user)
            ->get(route('settings.payment-settlements.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Settings/PaymentSettlements')
                ->where('summary.total', 1)
                ->where('settlements.data.0.transaction.invoice', $transaction->invoice));
    }

    protected function createPendingTransaction(string $method): array
    {
        $category = Category::create(['name' => 'Settlement '.uniqid()]);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'Produk Settlement',
            'buy_price' => 10000,
            'sell_price' => 20000,
            'stock' => 5,
            'unit' => 'Pcs',
        ]);
        $cashier = User::factory()->create();
        $transaction = Transaction::create([
            'cashier_id' => $cashier->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(10)),
            'customer_name' => 'Settlement Customer',
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 40000,
            'payment_method' => $method,
            'payment_status' => 'pending',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id,
            'qty' => 2,
            'price' => 40000,
            'buy_price' => 10000,
        ]);

        return [$transaction, $product];
    }
}
