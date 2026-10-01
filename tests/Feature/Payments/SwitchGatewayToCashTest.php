<?php

namespace Tests\Feature\Payments;

use App\Models\Cart;
use App\Models\Category;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Skenario nyata: pelanggan mau QRIS, lalu berubah pikiran dan bayar cash.
 * Stok harus UTUH setelah batal, supaya kasir bisa langsung cash tanpa
 * kehilangan barang.
 */
class SwitchGatewayToCashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);
    }

    public function test_cancelling_pending_gateway_frees_stock_and_keeps_cart(): void
    {
        Http::fake([
            'https://app.sandbox.midtrans.com/*' => Http::response([
                'order_id' => 'TRX-SWITCH',
                'redirect_url' => 'https://pay.midtrans.test/invoice',
                'token' => 'snap-token',
            ], 200),
        ]);

        PaymentSetting::create([
            'default_gateway' => 'midtrans',
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'server-key',
            'midtrans_client_key' => 'client-key',
        ]);

        $cashier = User::factory()->create();
        $cashier->givePermissionTo('transactions-access');
        Shift::create([
            'user_id' => $cashier->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $category = Category::create(['name' => 'Kategori Ganti']);
        $product = Product::create([
            'category_id' => $category->id,
            'barcode' => 'SWITCH-1',
            'title' => 'Produk Ganti Bayar',
            'buy_price' => 20000,
            'sell_price' => 50000,
            'stock' => 10,
        ]);

        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 2,
            'price' => 100000,
        ]);

        $stockBefore = (float) $product->fresh()->stock;

        // 1. Kasir pilih QRIS -> transaksi pending.
        $this->actingAs($cashier)
            ->post(route('transactions.store'), [
                'payment_gateway' => 'midtrans',
                'cash' => 0,
                'change' => 0,
            ])
            ->assertRedirect();

        $pending = Transaction::latest('id')->firstOrFail();
        $this->assertSame('pending', $pending->payment_status);
        $this->assertSame('midtrans', $pending->payment_method);

        // Keranjang sengaja TIDAK dihapus untuk gateway -- kasir masih butuh
        // barangnya kalau berubah pikiran.
        $this->assertSame(1, Cart::where('cashier_id', $cashier->id)->count(), 'Keranjang harus utuh.');

        // 2. Pelanggan menutup popup / berubah pikiran -> kasir batalkan.
        $this->actingAs($cashier)
            ->postJson(route('transactions.cancel_gateway'), ['invoice' => $pending->invoice])
            ->assertOk()
            ->assertJson(['success' => true]);

        $cancelled = $pending->fresh();
        $this->assertSame('failed', $cancelled->payment_status);

        // 3. Stok harus kembali utuh supaya bisa langsung dibayar cash.
        $this->assertSame(
            $stockBefore,
            (float) $product->fresh()->stock,
            'Stok harus kembali utuh setelah pembatalan.'
        );

        // 4. Keranjang masih ada -> kasir tinggal pilih Cash.
        $this->assertSame(1, Cart::where('cashier_id', $cashier->id)->count());

        // 5. Dan cash bisa langsung diproses untuk keranjang yang sama.
        $this->actingAs($cashier)
            ->post(route('transactions.store'), [
                'payment_gateway' => 'cash',
                'cash' => 100000,
                'change' => 0,
            ])
            ->assertRedirect();

        $paid = Transaction::latest('id')->firstOrFail();
        $this->assertSame('paid', $paid->payment_status);
        $this->assertSame('cash', $paid->payment_method);
        $this->assertSame(100000, (int) $paid->grand_total);
        $this->assertSame($stockBefore - 2, (float) $product->fresh()->stock);

        // Keranjang cash boleh dihapus setelah berhasil.
        $this->assertSame(0, Cart::where('cashier_id', $cashier->id)->count());
    }

    public function test_cancelling_an_already_paid_gateway_is_ignored(): void
    {
        // Kalau callback sempat sampai (status paid), pembatalan tidak boleh
        // membatalkan pembayaran yang sudah sukses.
        $settlement = app(PaymentSettlementService::class);

        $cashier = User::factory()->create();
        $transaction = Transaction::create([
            'cashier_id' => $cashier->id,
            'invoice' => 'TRX-ALREADY-PAID',
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'taxable_amount' => 50000,
            'tax_amount' => 0,
            'grand_total' => 50000,
            'payment_method' => 'midtrans',
            'payment_status' => 'paid',
            'status' => 'paid',
        ]);

        $settlement->cancelPending($transaction, 'kasir salah klik');

        $this->assertSame('paid', $transaction->fresh()->payment_status, 'Pembayaran sukses tidak boleh dibatalkan.');
    }

    public function test_late_callback_after_cash_switch_still_settles_the_gateway_transaction(): void
    {
        // Skenario paling berbahaya: kasir batalkan QRIS, pelanggan bayar cash,
        // lalu callback QRIS telat datang. Sistem harus MENYELESAIKAN transaksi
        // gateway-nya, bukan diam-diam membuang uang yang sudah masuk.
        $settlements = app(PaymentSettlementService::class);

        $cashier = User::factory()->create();

        $category = Category::create(['name' => 'Kategori Late']);
        $product = Product::create([
            'category_id' => $category->id,
            'barcode' => 'LATE-1',
            'title' => 'Produk Late Callback',
            'buy_price' => 20000,
            'sell_price' => 50000,
            'stock' => 10,
        ]);

        $transaction = Transaction::create([
            'cashier_id' => $cashier->id,
            'invoice' => 'TRX-LATE-CALLBACK',
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
        $transaction->paymentSettlement()->create([
            'provider' => 'midtrans',
            'provider_reference' => 'ref-late-1',
            'amount' => 50000,
            'status' => 'pending',
        ]);

        // Kasir membatalkan -> settlement jadi failed.
        $settlements->cancelPending($transaction, 'pelanggan ganti cash');
        $this->assertSame('failed', $transaction->fresh()->payment_status);

        // Callback telat bilang "paid" -> settlement harus tetap diproses.
        $result = $settlements->processStatus(
            transaction: $transaction->fresh(),
            provider: 'midtrans',
            providerStatus: 'settlement',
            providerReference: 'ref-late-1',
            amount: 50000.0,
            payload: ['late' => true],
            signatureValid: true,
            source: 'callback',
        );

        $this->assertSame('paid', $result['status']);
        $this->assertSame('paid', $transaction->fresh()->payment_status, 'Uang sudah masuk, harus tercatat.');
        $this->assertDatabaseHas('payment_settlements', [
            'transaction_id' => $transaction->id,
            'status' => 'paid',
        ]);
    }
}
