<?php

namespace Tests\Feature\Uat;

use App\Models\Category;
use App\Models\Hold;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Pembayaran Mandiri dari Menu QR.
 *
 * KASUS: pelanggan memesan lewat QR di meja, lalu membayar sendiri dengan
 * QRIS Midtrans atau Xendit, tanpa lewat kasir untuk membayar.
 *
 * MASALAH ARSITEKTUR YANG DISELESAIKAN:
 * Semua kode gateway yang sudah ada butuh objek `Transaction`, sementara menu QR
 * menyimpan pesanan sebagai `Hold`. Kalau pesanan diubah jadi `Transaction`
 * sejak awal supaya bisa ditagih, dua hal yang sudah benar akan rusak:
 *   - stok dan HPP terpakai sebelum ada yang membayar
 *   - dua pesanan di meja yang sama tidak bisa digabung jadi satu tagihan
 *
 * SOLUSINYA: pembayaran dicatat di `Hold`, penjualan resmi tetap terjadi di
 * kasir. Test-test di bawah mengunci aturan itu, terutama:
 *   - HPP dan stok TIDAK boleh terpakai saat pelanggan membayar
 *   - kasir tidak boleh menagih ulang pesanan yang sudah dibayar
 *   - nominal callback wajib cocok, atau ditolak
 *   - callback ganda tidak boleh menghasilkan pencatatan ganda
 *   - isi pesanan terkunci begitu QR ditagihkan
 */
class UatPublicMenuSelfPayTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['email' => 'admin@gmail.com']);
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));

        Setting::create([
            'cogs_method' => 'FIFO',
            'tax_enabled' => false,
            'tax_rate' => null,
            'loyalty_enabled' => false,
            'kitchen_display_enabled' => false,
        ]);

        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'Minuman'])->id,
            'title' => 'Es Teh',
            'buy_price' => 2000,
            'sell_price' => 8000,
            'stock' => 50,
            'unit' => 'Gelas',
            'type' => 'single',
        ]);
    }

    protected function enableXendit(): PaymentSetting
    {
        return PaymentSetting::create([
            'xendit_enabled' => true,
            'xendit_secret_key' => 'xnd_secret_test',
            'xendit_callback_token' => 'callback-token-uji',
            'xendit_production' => false,
        ]);
    }

    protected function enableMidtrans(): PaymentSetting
    {
        return PaymentSetting::create([
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'mid_secret_test',
            'midtrans_client_key' => 'mid_client_test',
            'midtrans_production' => false,
        ]);
    }

    protected function createHold(float $total = 8000, string $ref = 'QR-1234'): Hold
    {
        return Hold::create([
            'ref_number' => $ref,
            'reference_code' => '1234',
            'queue_number' => 'Q-001',
            'customer_name' => 'Budi #1234',
            'cart_data' => [[
                'product_id' => $this->product->id,
                'product_title' => 'Es Teh',
                'qty' => 1,
                'price' => $total,
                'sell_price' => $total,
                'current_unit_price' => $total,
            ]],
            'total' => $total,
            'user_id' => $this->admin->id,
        ]);
    }

    /**
     * Keranjang kasir. POS Filling transaksi selalu membaca keranjang, jadi
     * `hold_id` saja tidak cukup: isi keranjang dulu seperti yang terjadi saat
     * kasir membuka pesanan dari menu QR.
     */
    protected function fillCashierCart(int $qty = 1): void
    {
        \App\Models\Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $this->product->id,
            'product_unit_id' => null,
            'qty' => $qty,
            'price' => (float) $this->product->sell_price,
            'discount' => 0,
        ]);
    }

    // ------------------------------------------------------------------
    // PEMBUATAN QRIS
    // ------------------------------------------------------------------

    public function test_xendit_qris_uses_the_qr_codes_endpoint_and_returns_qr_string(): void
    {
        $this->enableXendit();
        $this->createHold();

        Http::fake([
            'api.xendit.co/qr_codes' => Http::response([
                'id' => 'qr_123',
                'external_id' => 'QR-1234',
                'qr_string' => '00020101021226...',
                'amount' => 8000,
            ], 200),
        ]);

        $response = $this->postJson(route('payment.menu.start', 1), ['provider' => 'xendit'])->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('qr_string', '00020101021226...')
            ->assertJsonPath('amount', 8000);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($request->url(), '/qr_codes')
                && ($body['external_id'] ?? null) === 'QR-1234'
                && ($body['amount'] ?? null) === 8000
                // DYNAMIC: satu kode per pesanan, nominal terkunci.
                && ($body['type'] ?? null) === 'DYNAMIC';
        });

        $this->assertSame('pending', Hold::firstOrFail()->payment_status);
    }

    public function test_midtrans_qris_uses_core_api_and_returns_qr_string(): void
    {
        $this->enableMidtrans();
        $this->createHold();

        // Bentuk respons asli Core API /v2/charge, sudah diverifikasi langsung
        // terhadap sandbox Midtrans: `qr_string` ada di level TERATAS, bukan
        // di dalam `actions`.
        Http::fake([
            '*.midtrans.com/v2/charge' => Http::response([
                'status_code' => '201',
                'status_message' => 'Qris transaction is created',
                'transaction_id' => '44987bbf-5785-4ca1-a6ca-e8058a193731',
                'order_id' => 'QR-1234',
                'gross_amount' => '8000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'fraud_status' => 'accept',
                'qr_string' => '00020101021226620014COM.GO-JEK.WWW011993600914324647820220210',
                'expiry_time' => now()->addMinutes(15)->format('Y-m-d H:i:s'),
                'actions' => [
                    [
                        'name' => 'generate-qr-code',
                        'method' => 'GET',
                        'url' => 'https://api.sandbox.midtrans.com/v2/qris/44987bbf/qr-code',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson(route('payment.menu.start', 1), ['provider' => 'midtrans'])->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('qr_string', '00020101021226620014COM.GO-JEK.WWW011993600914324647820220210')
            // Referensi gateway TIDAK dikirim ke halaman publik. Yang tampil ke
            // pelanggan cukup kode pesanan miliknya sendiri; referensi gateway
            // disimpan di server untuk pencocokan callback.
            ->assertJsonPath('ref_number', 'QR-1234')
            ->assertJsonMissingPath('payment_reference');

        $this->assertSame('QR-1234', Hold::firstOrFail()->payment_reference,
            'Referensi dari gateway harus tersimpan supaya callback bisa dicocokkan.');

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            // Kalau payment_type tidak dikunci ke qris, pelanggan bisa dialihkan
            // ke kartu kredit, dan itu bukan yang ditawarkan.
            return ($body['payment_type'] ?? null) === 'qris'
                && ($body['enabled_payments'] ?? null) === ['qris']
                && ($body['transaction_details']['order_id'] ?? null) === 'QR-1234';
        });

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/snap/'),
            'Snap tidak pernah mengirim qr_string. Verifikasi terhadap sandbox '
            .'menunjukkan responsnya hanya berisi token dan redirect_url, jadi '
            .'pelanggan tidak akan pernah melihat QR di halaman menu.');
    }

    public function test_midtrans_expiry_follows_the_gateway_not_our_guess(): void
    {
        $this->enableMidtrans();
        $this->createHold();

        Http::fake([
            '*.midtrans.com/v2/charge' => Http::response([
                'transaction_id' => 'tx-1',
                'order_id' => 'QR-1234',
                'gross_amount' => '8000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'qr_string' => 'EMV-PAYLOAD',
                // Midtrans mengunci QR-nya sendiri. Kalau UI menjanjikan lebih
                // lama dari ini, pelanggan akan mengejar QR yang sudah mati.
                'expiry_time' => now()->addMinutes(15)->format('Y-m-d H:i:s'),
            ], 200),
        ]);

        $this->postJson(route('payment.menu.start', 1), ['provider' => 'midtrans'])->assertOk();

        $hold = Hold::firstOrFail();
        $minutes = (int) ceil(now()->diffInMinutes($hold->payment_expires_at, false));

        $this->assertGreaterThanOrEqual(14, $minutes);
        $this->assertLessThanOrEqual(16, $minutes,
            'Masa berlaku harus mengikuti yang dikirim gateway.');
    }

    public function test_midtrans_without_qr_string_falls_back_to_the_payment_page(): void
    {
        $this->enableMidtrans();
        $this->createHold();

        Http::fake([
            '*.midtrans.com/v2/charge' => Http::response([
                'transaction_id' => 'tx-1',
                'order_id' => 'QR-1234',
                'gross_amount' => '8000.00',
                'payment_type' => 'qris',
                'transaction_status' => 'pending',
                'redirect_url' => 'https://app.sandbox.midtrans.com/redirect/tx-1',
            ], 200),
        ]);

        $this->postJson(route('payment.menu.start', 1), ['provider' => 'midtrans'])
            ->assertOk()
            ->assertJsonPath('qr_string', null)
            ->assertJsonPath('payment_url', 'https://app.sandbox.midtrans.com/redirect/tx-1');
    }

    /**
     * Endpoint pembayaran mandiri dipanggil browser pelanggan yang tidak punya
     * sesi, jadi tidak ada token CSRF yang bisa dikirim. Kalau route-nya masih
     * dilindungi CSRF, jawabannya 419 dan QR tidak pernah tampil.
     */
    public function test_menu_payment_endpoints_work_without_a_csrf_token(): void
    {
        $this->enableXendit();
        $this->createHold();

        Http::fake([
            'api.xendit.co/qr_codes' => Http::response([
                'id' => 'qr_123', 'qr_string' => 'EMV-PAYLOAD', 'amount' => 8000,
            ], 200),
        ]);

        $this->postJson(route('payment.menu.start', 1), ['provider' => 'xendit'])->assertOk();
        $this->getJson(route('payment.menu.status', 1))->assertOk();
    }

    public function test_gateway_not_configured_is_rejected_without_calling_provider(): void
    {
        PaymentSetting::create(['xendit_secret_key' => 'ada-tapi-tidak-dinyalakan']);

        $this->createHold();

        Http::fake();

        $this->postJson(route('payment.menu.start', 1), ['provider' => 'xendit'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
    }

    public function test_missing_payment_settings_is_reported_as_unavailable(): void
    {
        $this->createHold();

        Http::fake();

        $this->postJson(route('payment.menu.start', 1), ['provider' => 'xendit'])
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $this->enableXendit();
        $this->createHold();

        $this->postJson(route('payment.menu.start', 1), ['provider' => 'bitcoin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('provider');
    }

    public function test_menu_only_offers_gateways_that_are_actually_ready(): void
    {
        $this->enableXendit();

        $names = collect($this->get(route('public.menu'))->viewData('page')['props']['paymentGateways'])
            ->pluck('name')
            ->all();

        $this->assertSame(['xendit'], $names,
            'Hanya gateway yang benar-benar terkonfigurasi yang boleh ditawarkan.');
    }

    public function test_no_gateway_means_no_self_pay_option(): void
    {
        PaymentSetting::create(['xendit_secret_key' => 'ada-tapi-tidak-dinyalakan']);

        $this->assertSame([], $this->get(route('public.menu'))->viewData('page')['props']['paymentGateways'],
            'Gateway yang belum aktif tidak boleh muncul sebagai pilihan bayar.');
    }

    /**
     * Tombol "Bayar Sendiri" hanya muncul kalau daftar gateway tidak kosong.
     * Dulu frontend menyaring `g.enabled` padahal backend tidak pernah mengirim
     * field itu, jadi tombolnya hilang padahal Midtrans sebenarnya siap.
     */
    public function test_ready_gateway_payload_carries_the_enabled_flag(): void
    {
        $this->enableMidtrans();

        $gateways = $this->get(route('public.menu'))->viewData('page')['props']['paymentGateways'];

        $this->assertCount(1, $gateways);
        $this->assertSame('midtrans', $gateways[0]['name']);
        $this->assertTrue($gateways[0]['enabled'],
            'Field enabled harus ikut dikirim. Kalau tidak, frontend yang '
            .'menyaring berdasarkan field ini akan menyembunyikan tombolnya.');
        $this->assertNotSame('', $gateways[0]['label'],
            'Label wajib diisi supaya tombol tidak tampil dengan teks kosong.');
    }

    public function test_gateway_credentials_are_never_exposed_on_the_menu_page(): void
    {
        $this->enableXendit();
        PaymentSetting::create([
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'RAHASIA-MID',
            'midtrans_client_key' => 'RAHASIA-MID-CLIENT',
        ]);

        $encoded = json_encode(
            $this->get(route('public.menu'))->viewData('page')['props']
        );

        $this->assertStringNotContainsString('RAHASIA-MID', $encoded);
        $this->assertStringNotContainsString('RAHASIA-MID-CLIENT', $encoded);
        $this->assertStringNotContainsString('xnd_secret_test', $encoded);
    }

    // ------------------------------------------------------------------
    // CALLBACK
    // ------------------------------------------------------------------

    public function test_xendit_successful_callback_marks_the_order_paid(): void
    {
        $this->enableXendit();
        $hold = $this->createHold(8000);
        $hold->forceFill([
            'payment_method' => 'xendit',
            'payment_status' => 'pending',
            'payment_reference' => 'qr_123',
        ])->save();

        $this->postJson(route('payment.xendit.notification'), [
            'id' => 'qr_123',
            'external_id' => 'QR-1234',
            'status' => 'SUCCEEDED',
            'amount' => 8000,
        ], ['x-callback-token' => 'callback-token-uji'])->assertOk();

        $hold->refresh();

        $this->assertSame('paid', $hold->payment_status);
        $this->assertSame(8000.0, (float) $hold->paid_amount);
        $this->assertNotNull($hold->paid_at);
    }

    public function test_midtrans_settlement_callback_marks_the_order_paid(): void
    {
        $setting = $this->enableMidtrans();
        $hold = $this->createHold(8000);
        $hold->forceFill([
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
            'payment_reference' => 'QR-1234',
        ])->save();

        $orderId = 'QR-1234';
        $status = 'settlement';
        $gross = '8000';
        $signature = hash('sha512', $orderId.$status.$gross.$setting->midtrans_server_key);

        $this->postJson(route('payment.notification'), [
            'order_id' => $orderId,
            'transaction_status' => $status,
            'gross_amount' => $gross,
            'signature_key' => $signature,
        ])->assertOk();

        $this->assertSame('paid', $hold->fresh()->payment_status);
    }

    public function test_midtrans_callback_with_wrong_signature_is_rejected(): void
    {
        $this->enableMidtrans();
        $hold = $this->createHold(8000);
        $hold->forceFill([
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
        ])->save();

        $this->postJson(route('payment.notification'), [
            'order_id' => 'QR-1234',
            'transaction_status' => 'settlement',
            'gross_amount' => '8000',
            'signature_key' => 'palsu',
        ])->assertStatus(403);

        $this->assertSame('pending', $hold->fresh()->payment_status,
            'Callback dengan tanda tangan palsu tidak boleh menandai lunas.');
    }

    public function test_callback_with_mismatched_amount_is_rejected(): void
    {
        $this->enableXendit();
        $hold = $this->createHold(8000);
        $hold->forceFill([
            'payment_method' => 'xendit',
            'payment_status' => 'pending',
        ])->save();

        // Pelanggan somehow membayar 100.000 untuk pesanan 8.000.
        $this->postJson(route('payment.xendit.notification'), [
            'id' => 'qr_123',
            'external_id' => 'QR-1234',
            'status' => 'SUCCEEDED',
            'amount' => 100000,
        ], ['x-callback-token' => 'callback-token-uji'])->assertStatus(422);

        $hold->refresh();

        $this->assertNotSame('paid', $hold->payment_status,
            'Nominal yang dibayar harus sama dengan yang ditagihkan, kalau tidak '
            .'Kas Bank di neraca akan melebihi pembayaran yang benar-benar masuk.');
    }

    public function test_pending_callback_does_not_mark_the_order_failed(): void
    {
        $this->enableXendit();
        $hold = $this->createHold();
        $hold->forceFill(['payment_method' => 'xendit', 'payment_status' => 'pending'])->save();

        // Gateway mengirim pending beberapa kali sebelum settled. Kalau
        // pending diperlakukan sebagai gagal, gateway akan mengulang terus.
        $this->postJson(route('payment.xendit.notification'), [
            'id' => 'qr_123',
            'external_id' => 'QR-1234',
            'status' => 'PENDING',
            'amount' => 8000,
        ], ['x-callback-token' => 'callback-token-uji'])->assertOk();

        $this->assertSame('pending', $hold->fresh()->payment_status);
    }

    public function test_expired_callback_marks_the_order_failed(): void
    {
        $this->enableXendit();
        $hold = $this->createHold();
        $hold->forceFill(['payment_method' => 'xendit', 'payment_status' => 'pending'])->save();

        $this->postJson(route('payment.xendit.notification'), [
            'id' => 'qr_123',
            'external_id' => 'QR-1234',
            'status' => 'EXPIRED',
            'amount' => 8000,
        ], ['x-callback-token' => 'callback-token-uji'])->assertOk();

        $this->assertSame('failed', $hold->fresh()->payment_status);
    }

    public function test_duplicate_paid_callback_does_not_double_count(): void
    {
        $this->enableXendit();
        $hold = $this->createHold(8000);
        $hold->forceFill(['payment_method' => 'xendit', 'payment_status' => 'pending'])->save();

        $payload = [
            'id' => 'qr_123',
            'external_id' => 'QR-1234',
            'status' => 'SUCCEEDED',
            'amount' => 8000,
        ];

        $this->postJson(route('payment.xendit.notification'), $payload, ['x-callback-token' => 'callback-token-uji'])->assertOk();
        $first = $hold->fresh()->paid_at;

        $this->postJson(route('payment.xendit.notification'), $payload, ['x-callback-token' => 'callback-token-uji'])->assertOk();

        $this->assertSame('paid', $hold->fresh()->payment_status);
        $this->assertTrue($first->equalTo($hold->fresh()->paid_at),
            'Callback kedua tidak boleh mengubah waktu pembayaran, kalau tidak '
            .'waktu pembayaran tidak boleh berubah karena callback kedua.');
    }

    public function test_expire_after_paid_does_not_revert_the_order(): void
    {
        $this->enableXendit();
        $hold = $this->createHold(8000);
        $hold->forceFill([
            'payment_method' => 'xendit',
            'payment_status' => 'pending',
        ])->save();

        $this->postJson(route('payment.xendit.notification'), [
            'id' => 'qr_123', 'external_id' => 'QR-1234', 'status' => 'SUCCEEDED', 'amount' => 8000,
        ], ['x-callback-token' => 'callback-token-uji'])->assertOk();

        // Gateway mengirim "expire" terlambat setelah pembayaran berhasil.
        $this->postJson(route('payment.xendit.notification'), [
            'id' => 'qr_123', 'external_id' => 'QR-1234', 'status' => 'EXPIRED', 'amount' => 8000,
        ], ['x-callback-token' => 'callback-token-uji'])->assertOk();

        $this->assertSame('paid', $hold->fresh()->payment_status,
            'Pesanan yang sudah lunas tidak boleh dikembalikan menjadi gagal '
            .'karena callback kedaluwarsa yang datang terlambat.');
    }

    // ------------------------------------------------------------------
    // ATURAN KEUANGAN
    // ------------------------------------------------------------------

    public function test_paying_does_not_consume_stock_or_record_profit(): void
    {
        $this->enableXendit();
        $this->createHold();

        Http::fake([
            'api.xendit.co/qr_codes' => Http::response([
                'id' => 'qr_123', 'qr_string' => 'EMV', 'amount' => 8000,
            ], 200),
        ]);

        $this->postJson(route('payment.menu.start', 1), ['provider' => 'xendit'])->assertOk();

        $this->postJson(route('payment.xendit.notification'), [
            'id' => 'qr_123', 'external_id' => 'QR-1234', 'status' => 'SUCCEEDED', 'amount' => 8000,
        ], ['x-callback-token' => 'callback-token-uji'])->assertOk();

        $this->assertSame(0, Transaction::count(),
            'Pembayaran di menu QR belum boleh menjadi penjualan resmi. '
            .'Penjualan dicatat saat kasir menagih.');
        $this->assertSame(50.0, (float) $this->product->fresh()->stock,
            'Stok tidak boleh berkurang hanya karena pelanggan membayar di menu.');
    }

    public function test_cashier_checkout_records_the_sale_as_already_paid(): void
    {
        $this->enableXendit();

        $hold = $this->createHold(8000);
        $hold->forceFill([
            'payment_method' => 'xendit',
            'payment_status' => 'paid',
            'paid_amount' => 8000,
            'paid_at' => now()->subMinutes(2),
        ])->save();

        $this->actingAs($this->admin)->post(route('shifts.store'), ['starting_cash' => 100000])->assertRedirect();
        $this->fillCashierCart();

        // Kasir memilih Midtrans, padahal pelanggan sudah bayar via Xendit.
        // Kalau pilihan kasir diikuti, sistem akan membuat QR kedua.
        $this->withoutExceptionHandling()
            ->actingAs($this->admin)
            ->postJson(route('transactions.store'), [
                'hold_id' => $hold->id,
                'payment_gateway' => 'midtrans',
                'customer_name' => 'Budi',
            ])
            ->assertOk();

        $transaction = Transaction::firstOrFail();

        $this->assertSame('paid', $transaction->payment_status,
            'Transaksi harus langsung lunas karena uangnya sudah masuk.');
        $this->assertSame('xendit', $transaction->payment_method,
            'Metode harus mengikuti pembayaran yang benar-benar diterima, bukan '
            .'pilihan kasir, supaya Kas Bank neraca menerima uangnya di kolom tepat.');
        $this->assertNotNull($transaction->paid_at);
        $this->assertSame(49.0, (float) $this->product->fresh()->stock,
            'Di titik inilah stok dan HPP baru boleh terpakai.');

        // Kontrak data untuk struk.
        //
        // Struk thermal dulu menandai "BELUM LUNAS" berdasarkan `!cash`, karena
        // mengira cash > 0 berarti sudah dibayar. Untuk pembayaran non-tunai
        // `cash` memang 0, jadi order yang SUDAH LUNAS akan tercetak belum
        // lunas. Kombinasi cash=0 + payment_status=paid inilah yang harus
        // sampai ke halaman struk, jadi diuji di sini.
        $this->assertSame(0.0, (float) $transaction->cash,
            'Tidak ada uang tunai yang diterima, jadi cash harus 0.');
        $this->assertSame('paid', $transaction->payment_status,
            'Struk menentukan lunas dari payment_status, bukan dari cash. '
            .'Kalau statusnya tidak terisi, struk akan salah mencetak '
            .'"BELUM LUNAS" untuk order yang sudah dibayar.');
    }

    public function test_receipt_page_receives_the_fields_the_receipt_needs(): void
    {
        $this->enableXendit();

        $hold = $this->createHold(8000);
        $hold->forceFill([
            'payment_method' => 'xendit',
            'payment_status' => 'paid',
            'paid_amount' => 8000,
            'paid_at' => now()->subMinute(),
        ])->save();

        $this->actingAs($this->admin)->post(route('shifts.store'), ['starting_cash' => 100000]);
        $this->fillCashierCart();

        $this->actingAs($this->admin)
            ->postJson(route('transactions.store'), [
                'hold_id' => $hold->id,
                'payment_gateway' => 'cash',
                'customer_name' => 'Budi',
            ])
            ->assertOk();

        $transaction = Transaction::firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('transactions.print', $transaction->invoice))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Transactions/Print')
                // Struk thermal memakai dua field ini untuk menentukan
                // "BELUM LUNAS" dan "KEMBALI". Kalau salah satu tidak
                // terkirim, struk untuk order QRIS jadi salah cetak.
                ->where('transaction.payment_status', 'paid')
                ->where('transaction.payment_method', 'xendit')
                ->where('transaction.cash', 0)
                ->has('transaction.paid_at')
            );
    }

    public function test_checkout_does_not_create_a_second_gateway_charge(): void
    {
        $this->enableXendit();

        $hold = $this->createHold(8000);
        $hold->forceFill([
            'payment_method' => 'xendit',
            'payment_status' => 'paid',
            'paid_amount' => 8000,
            'paid_at' => now(),
        ])->save();

        $this->actingAs($this->admin)->post(route('shifts.store'), ['starting_cash' => 100000]);
        $this->fillCashierCart();

        Http::fake();

        $this->actingAs($this->admin)
            ->postJson(route('transactions.store'), [
                'hold_id' => $hold->id,
                'payment_gateway' => 'xendit',
                'customer_name' => 'Budi',
            ])
            ->assertOk();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // KUNCI NOMINAL
    // ------------------------------------------------------------------

    public function test_order_cannot_be_changed_while_a_qr_is_pending(): void
    {
        $this->enableXendit();

        $table = \App\Models\Table::create(['name' => 'Meja 1', 'status' => 'available', 'capacity' => 4]);

        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), [
                'customer_name' => 'Budi',
                'order_type' => 'table',
                'table_id' => $table->id,
                'cart_items' => [['product_id' => $this->product->id, 'qty' => 1]],
                'total' => 8000,
            ])
            ->assertOk();

        $hold = Hold::firstOrFail();
        $hold->forceFill(['payment_status' => 'pending', 'payment_method' => 'xendit'])->save();

        // Pelanggan nambah pesanan padahal QR-nya sudah ditagihkan.
        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), [
                'customer_name' => 'Budi',
                'order_type' => 'table',
                'table_id' => $table->id,
                'cart_items' => [['product_id' => $this->product->id, 'qty' => 2]],
                'total' => 24000,
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame(8000.0, (float) $hold->fresh()->total,
            'Nominal yang sudah ditagihkan tidak boleh berubah.');
    }

    public function test_order_cannot_be_changed_after_it_is_paid(): void
    {
        $table = \App\Models\Table::create(['name' => 'Meja 2', 'status' => 'available', 'capacity' => 4]);

        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), [
                'customer_name' => 'Budi',
                'order_type' => 'table',
                'table_id' => $table->id,
                'cart_items' => [['product_id' => $this->product->id, 'qty' => 1]],
                'total' => 8000,
            ])
            ->assertOk();

        Hold::firstOrFail()->forceFill(['payment_status' => 'paid', 'paid_amount' => 8000])->save();

        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), [
                'customer_name' => 'Budi',
                'order_type' => 'table',
                'table_id' => $table->id,
                'cart_items' => [['product_id' => $this->product->id, 'qty' => 2]],
                'total' => 24000,
            ])
            ->assertStatus(409);
    }

    public function test_expired_qr_is_reported_as_expired_by_the_status_endpoint(): void
    {
        $this->enableXendit();

        $hold = $this->createHold();
        $hold->forceFill([
            'payment_method' => 'xendit',
            'payment_status' => 'pending',
            'payment_expires_at' => now()->subMinute(),
        ])->save();

        $this->getJson(route('payment.menu.status', $hold->id))
            ->assertOk()
            ->assertJsonPath('status', 'expired');
    }
}
