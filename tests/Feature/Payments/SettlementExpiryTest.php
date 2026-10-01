<?php

namespace Tests\Feature\Payments;

use App\Models\Category;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Settlement yang menggantung harus BERHENTI di-retry otomatis, tanpa
 * mengorbankan kemungkinan uangnya sebenarnya masuk (callback telat tetap
 * boleh menyelesaikan).
 */
class SettlementExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.settlement.expire_after_minutes' => 1440,
            'services.settlement.max_attempts' => 0,
        ]);

        PaymentSetting::create([
            'default_gateway' => 'midtrans',
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'server-key',
            'midtrans_client_key' => 'client-key',
        ]);
    }

    protected function pendingScenario(): array
    {
        $cashier = User::factory()->create();
        $category = Category::create(['name' => 'Kategori Expire']);
        $product = Product::create([
            'category_id' => $category->id,
            'barcode' => 'EXP-1',
            'title' => 'Produk Expire',
            'buy_price' => 20000,
            'sell_price' => 50000,
            'stock' => 10,
        ]);

        $transaction = Transaction::create([
            'cashier_id' => $cashier->id,
            'invoice' => 'TRX-EXPIRE',
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'taxable_amount' => 50000,
            'tax_amount' => 0,
            'grand_total' => 50000,
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
            'status' => 'paid',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 50000,
            'buy_price' => 20000,
        ]);

        $settlements = app(PaymentSettlementService::class);
        $settlements->begin($transaction, 'midtrans');
        $settlement = $transaction->paymentSettlement()->firstOrFail();
        $settlement->update(['provider_reference' => 'ref-expire-1', 'amount' => 50000]);

        return [$transaction, $settlement, $product];
    }

    public function test_stale_settlement_is_expired_and_stops_being_retried(): void
    {
        // Provider selalu bilang "masih menunggu".
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response([
                'order_id' => 'ref-expire-1',
                'transaction_status' => 'pending',
                'fraud_status' => '',
                'gross_amount' => '50000.00',
            ], 200),
        ]);

        [$transaction, $settlement] = $this->pendingScenario();
        $service = app(PaymentSettlementService::class);

        // Belum melewati batas -> masih retry normal.
        Carbon::setTestNow(now()->addHours(2));
        $service->reconcilePending(50);
        $this->assertSame('pending', $settlement->fresh()->status);
        $this->assertNotNull($settlement->fresh()->next_retry_at);

        // Lewat batas 24 jam -> ditandai expired, tidak boleh retry lagi.
        Carbon::setTestNow(now()->addDays(2));
        $service->reconcilePending(50);

        $expired = $settlement->fresh();
        $this->assertSame('expired', $expired->status);
        $this->assertNull($expired->next_retry_at, 'Retry otomatis harus dihentikan.');
        $this->assertDatabaseHas('payment_events', [
            'transaction_id' => $transaction->id,
            'status' => 'expired',
        ]);

        // 30 hari berikutnya TIDAK boleh ada request ke provider lagi.
        $before = count(Http::recorded());
        for ($day = 0; $day < 30; $day++) {
            Carbon::setTestNow(now()->addDay());
            $service->reconcilePending(50);
        }

        $this->assertCount(
            $before,
            Http::recorded(),
            'Settlement expired tidak boleh dipanggil ke provider lagi.'
        );
        $this->assertSame('expired', $settlement->fresh()->status);

        Carbon::setTestNow();
    }

    public function test_expired_settlement_is_not_revived_by_still_pending_report(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response([
                'order_id' => 'ref-expire-1',
                'transaction_status' => 'pending',
                'fraud_status' => '',
                'gross_amount' => '50000.00',
            ], 200),
        ]);

        [$transaction, $settlement] = $this->pendingScenario();
        $service = app(PaymentSettlementService::class);

        Carbon::setTestNow(now()->addDays(2));
        $service->reconcilePending(50);
        $this->assertSame('expired', $settlement->fresh()->status);

        // Cek manual: provider masih bilang pending. Status harus TETAP expired
        // supaya tidak masuk siklus retry lagi.
        $result = $service->reconcileOne($settlement->fresh());

        $this->assertSame('expired', $result['status']);
        $this->assertSame('expired', $settlement->fresh()->status);
        $this->assertNull($settlement->fresh()->next_retry_at);

        Carbon::setTestNow();
    }

    public function test_late_callback_still_settles_an_expired_settlement(): void
    {
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response([
                'order_id' => 'ref-expire-1',
                'transaction_status' => 'pending',
                'fraud_status' => '',
                'gross_amount' => '50000.00',
            ], 200),
        ]);

        [$transaction, $settlement, $product] = $this->pendingScenario();
        $service = app(PaymentSettlementService::class);

        Carbon::setTestNow(now()->addDays(2));
        $service->reconcilePending(50);
        $this->assertSame('expired', $settlement->fresh()->status);

        // Pelanggan ternyata bayar juga, callback-nya telat sampai.
        $result = $service->processStatus(
            transaction: $transaction->fresh(),
            provider: 'midtrans',
            providerStatus: 'settlement',
            providerReference: 'ref-expire-1',
            amount: 50000.0,
            payload: ['late' => true],
            signatureValid: true,
            source: 'callback',
        );

        // Uangnya harus tercatat.
        $this->assertSame('paid', $result['status']);
        $this->assertSame('paid', $transaction->fresh()->payment_status);
        $this->assertSame('paid', $settlement->fresh()->status);
        // Dan stok benar-benar keluar karena uangnya masuk.
        $this->assertSame(9.0, (float) $product->fresh()->stock);

        Carbon::setTestNow();
    }

    public function test_expiry_threshold_is_configurable(): void
    {
        config(['services.settlement.expire_after_minutes' => 30]);

        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response([
                'order_id' => 'ref-expire-1',
                'transaction_status' => 'pending',
                'fraud_status' => '',
                'gross_amount' => '50000.00',
            ], 200),
        ]);

        [$transaction, $settlement] = $this->pendingScenario();
        $service = app(PaymentSettlementService::class);

        Carbon::setTestNow(now()->addMinutes(45));
        $service->reconcilePending(50);

        $this->assertSame('expired', $settlement->fresh()->status);

        Carbon::setTestNow();
    }
}
