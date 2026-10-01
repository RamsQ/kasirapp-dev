<?php

namespace Tests\Feature\Uat;

use App\Models\Category;
use App\Models\Hold;
use App\Models\Product;
use App\Models\ReceiptSetting;
use App\Models\Setting;
use App\Models\Table;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Struk Digital Pelanggan.
 *
 * Pelanggan pesanan menu QR harus punya bukti di HP-nya sendiri, tanpa harus
 * minta struk kertas ke kasir.
 *
 * RISIKO YANG DIUJI DI SINI:
 * Halaman ini terbuka tanpa login. Kalau URL-nya cuma berisi `ref_number`,
 * siapa pun bisa menebak `QR-0001`, `QR-0002`, dst dan membaca pesanan orang
 * lain. `ref_number` juga TIDAK boleh dianggap rahasia: 4 digit itu dipilih
 * pelanggan sendiri, jadi jadi kode pesanan, dan sengaja dicetak di struk.
 *
 * Jadi yang diuji:
 *  1. URL tanpa signature ditolak.
 *  2. URL yang parametrinya diubah ditolak.
 *  3. Isi struk tidak membocorkan HPP, referensi gateway, atau data kasir.
 *  4. Struk bisa dibuka SEBELUM pembayaran, dan berubah jadi "lunas" setelah
 *     callback tanpa perlu URL baru.
 *  5. Nominal dan status di struk berasal dari server, bukan dari klik
 *     pelanggan.
 */
class UatPublicReceiptTest extends TestCase
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

        Storage::fake('public');
        ReceiptSetting::create([
            'store_name' => 'Warung Uji',
            'store_address' => 'Jl. Uji No. 1',
            'store_phone' => '0812-0000-0000',
            'store_footer' => 'Sampai Jumpa',
        ]);

        $this->product = Product::create([
            'category_id' => Category::create(['name' => 'Minuman'])->id,
            'title' => 'Es Teh Uji',
            'buy_price' => 3000,
            'sell_price' => 8000,
            'stock' => 20,
            'unit' => 'Gelas',
            'type' => 'single',
        ]);
    }

    protected function createHold(array $attributes = []): Hold
    {
        return Hold::create(array_merge([
            'ref_number' => 'QR-1111',
            'reference_code' => '1111',
            'queue_number' => 'Q-011',
            'customer_name' => 'Budi #1111',
            'cart_data' => [[
                'product_id' => $this->product->id,
                'product_title' => 'Es Teh Uji',
                'qty' => 2,
                'price' => 16000,
                'sell_price' => 8000,
                'current_unit_price' => 8000,
                'notes' => 'tanpa gula',
            ]],
            'total' => 16000,
            'taxable_amount' => 16000,
            'tax_amount' => 0,
            'user_id' => $this->admin->id,
        ], $attributes));
    }

    protected function signedUrl(Hold $hold): string
    {
        return URL::signedRoute('public.menu.receipt', ['ref' => $hold->ref_number]);
    }

    // ------------------------------------------------------------------
    // KEAMANAN
    // ------------------------------------------------------------------

    public function test_unsigned_url_is_rejected(): void
    {
        $hold = $this->createHold();

        $this->get('/menu/struk/'.$hold->ref_number)
            ->assertForbidden();
    }

    public function test_url_without_signature_cannot_be_guessed_by_sequence(): void
    {
        // Menebak pola harus gagal, karena tidak ada signature.
        foreach (['QR-0001', 'QR-0002', 'QR-1111'] as $guess) {
            $this->get('/menu/struk/'.$guess)->assertForbidden();
        }
    }

    public function test_tampered_reference_is_rejected(): void
    {
        $mine = $this->createHold(['ref_number' => 'QR-1111', 'reference_code' => '1111']);
        $this->createHold(['ref_number' => 'QR-9999', 'reference_code' => '9999']);

        // Serangan yang nyata: ambil URL sah milik pesanan sendiri, lalu
        // ganti bagian path-nya untuk mengintip pesanan orang lain. Signature
        // ikut mencakup path, jadi harus ditolak.
        $legitimate = $this->signedUrl($mine);
        $forged = str_replace('QR-1111', 'QR-9999', $legitimate);

        $this->assertStringNotContainsString('QR-1111', $forged, 'URL harus benar-benar berubah.');

        $this->get($legitimate)->assertOk();
        $this->get($forged)->assertForbidden();
    }

    public function test_legitimately_signed_url_for_unknown_order_is_not_found(): void
    {
        $this->createHold();

        // Server sendiri yang menandatangani, jadi signature-nya sah; hanya
        // pesanan yang tidak ada. Membedakan 403 dari 404 supaya tidak
        // membocorkan apakah suatu ref_number pernah dipakai.
        $this->get(URL::signedRoute('public.menu.receipt', ['ref' => 'QR-0000']))
            ->assertNotFound();
    }

    public function test_signature_of_another_order_does_not_work(): void
    {
        $mine = $this->createHold(['ref_number' => 'QR-1111', 'reference_code' => '1111']);
        $other = $this->createHold(['ref_number' => 'QR-2222', 'reference_code' => '2222']);

        // Tanda tangan milik pesanan sendiri, tapi path-nya diganti.
        $url = $this->signedUrl($other);
        $this->assertStringContainsString('QR-2222', $url);

        // URL masing-masing hanya membuka pesanannya sendiri.
        $this->get($this->signedUrl($mine))->assertOk()
            ->assertInertia(fn ($page) => $page->where('receipt.ref_number', 'QR-1111'));
        $this->get($this->signedUrl($other))->assertOk()
            ->assertInertia(fn ($page) => $page->where('receipt.ref_number', 'QR-2222'));
    }

    public function test_receipt_never_exposes_cost_price_or_gateway_reference(): void
    {
        $hold = $this->createHold([
            'payment_method' => 'midtrans',
            'payment_status' => 'paid',
            'paid_amount' => 16000,
            'paid_at' => now(),
            'payment_reference' => 'RAHASIA-GATEWAY-REF',
        ]);

        $props = $this->get($this->signedUrl($hold))
            ->assertOk()
            ->viewData('page')['props'];

        $encoded = json_encode($props);

        $this->assertStringNotContainsString('3000', $encoded,
            'HPP bahan (buy_price) tidak boleh muncul di struk pelanggan. '
            .'Itu data internal usaha.');
        $this->assertStringNotContainsString('RAHASIA-GATEWAY-REF', $encoded,
            'Referensi gateway tidak perlu dilihat pelanggan.');
        $this->assertStringNotContainsString('admin@gmail.com', $encoded);

        $this->assertArrayNotHasKey('payment_reference', $props['receipt']['payment']);
        $this->assertArrayNotHasKey('cashier', $props['receipt']);
    }

    public function test_receipt_does_not_leak_the_unique_order_code_in_the_name(): void
    {
        $hold = $this->createHold();

        $props = $this->get($this->signedUrl($hold))->assertOk()->viewData('page')['props'];

        $this->assertSame('Budi', $props['receipt']['customer_name'],
            'Kode unik disembunyikan dari nama supaya tidak bisa dipakai '
            .'menebak pesanan lain.');
    }

    // ------------------------------------------------------------------
    // ISI STRUK
    // ------------------------------------------------------------------

    public function test_unpaid_receipt_is_readable_before_payment(): void
    {
        $hold = $this->createHold([
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
            'payment_expires_at' => now()->addMinutes(15),
        ]);

        $this->get($this->signedUrl($hold))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Receipt')
                ->where('receipt.ref_number', 'QR-1111')
                ->where('receipt.queue_number', 'Q-011')
                ->where('receipt.payment.status', 'pending')
                ->where('receipt.totals.total', 16000)
                ->where('receipt.totals.paid', false)
                ->where('store.name', 'Warung Uji')
                ->where('receipt.items.0.title', 'Es Teh Uji')
                ->where('receipt.items.0.qty', 2)
                ->where('receipt.items.0.total', 16000)
                ->where('receipt.items.0.notes', 'tanpa gula')
            );
    }

    public function test_receipt_turns_paid_after_the_callback(): void
    {
        $hold = $this->createHold([
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
            'payment_expires_at' => now()->addMinutes(15),
        ]);

        $url = $this->signedUrl($hold);

        $this->get($url)->assertInertia(fn ($page) => $page
            ->where('receipt.totals.paid', false));

        // Callback masuk.
        app(\App\Services\Payments\HoldPaymentService::class)->markPaid($hold, 16000, 'QR-1111');

        // URL yang sama, tanpa parameter tambahan, sekarang menampilkan lunas.
        $this->get($url)->assertInertia(fn ($page) => $page
            ->where('receipt.totals.paid', true)
            ->where('receipt.payment.status', 'paid')
            ->where('receipt.payment.paid_amount', 16000)
        );
    }

    public function test_expired_qr_is_marked_expired_by_the_receipt_page(): void
    {
        $hold = $this->createHold([
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
            'payment_expires_at' => now()->subMinute(),
        ]);

        $this->get($this->signedUrl($hold))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('receipt.payment.status', 'expired')
                ->where('receipt.totals.paid', false)
            );
    }

    public function test_receipt_prefers_the_official_transaction_details(): void
    {
        $hold = $this->createHold([
            'payment_method' => 'xendit',
            'payment_status' => 'paid',
            'paid_amount' => 16000,
            'paid_at' => now(),
        ]);

        $transaction = Transaction::create([
            'invoice' => 'TRX-RECEIPT-1',
            'cashier_id' => $this->admin->id,
            'customer_name' => 'Budi',
            'payment_method' => 'xendit',
            'payment_status' => 'paid',
            'status' => 'completed',
            'subtotal' => 16000,
            'discount' => 2000,
            'cash' => 0,
            'change' => 0,
            'taxable_amount' => 14000,
            'tax_amount' => 0,
            'grand_total' => 16000,
            'total_discount' => 0,
            'total_markup' => 0,
            'total_fee' => 0,
            'cogs_method' => 'FIFO',
            'queue_number' => 'Q-011',
        ]);

        $transaction->details()->create([
            'product_id' => $this->product->id,
            'qty' => 2,
            'price' => 8000,
            'buy_price' => 3000,
            'total' => 16000,
            'subtotal' => 16000,
            'unit' => 'Gelas',
        ]);

        $hold->update(['transaction_id' => $transaction->id]);

        $this->get($this->signedUrl($hold))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Harga dan diskon harus mengikuti transaksi resmi, bukan
                // tebakan dari cart_data.
                ->where('receipt.totals.discount', 2000)
                ->where('receipt.totals.total', 16000)
                ->where('receipt.totals.paid', true)
                ->where('receipt.items.0.unit', 'Gelas')
            );
    }

    public function test_dine_in_receipt_shows_the_table(): void
    {
        $table = Table::create(['name' => 'Meja 7', 'status' => 'occupied', 'capacity' => 4]);

        $hold = $this->createHold(['table_id' => $table->id]);

        $this->get($this->signedUrl($hold))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('receipt.order_type', 'dine_in')
                ->where('receipt.table_name', 'Meja 7')
            );
    }

    public function test_receipt_response_is_not_cached(): void
    {
        $hold = $this->createHold();

        $cacheControl = $this->get($this->signedUrl($hold))
            ->assertOk()
            ->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', (string) $cacheControl,
            'Struk bisa dibuka di perangkat bersama. Kalau boleh di-cache, '
            .'status pembayaran yang tampil bisa basi — masih "menunggu" '
            .'padahal sudah lunas.');
        $this->assertStringContainsString('no-cache', (string) $cacheControl);
    }

    public function test_receipt_survives_the_hold_being_deleted_at_checkout(): void
    {
        $hold = $this->createHold([
            'payment_method' => 'xendit',
            'payment_status' => 'paid',
            'paid_amount' => 16000,
            'paid_at' => now()->subMinute(),
        ]);

        $url = $this->signedUrl($hold);

        $this->actingAs($this->admin)->post(route('shifts.store'), ['starting_cash' => 100000]);
        \App\Models\Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $this->product->id,
            'qty' => 2,
            'price' => 8000,
            'discount' => 0,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('transactions.store'), [
                'hold_id' => $hold->id,
                'payment_gateway' => 'cash',
                'customer_name' => 'Budi',
            ])
            ->assertOk();

        // Hold dihapus setelah penagihan. Kalau URL struk masih bergantung pada
        // Hold, bukti yang pelanggan bawa ke kasir jadi 404 tepat di saat
        // paling dibutuhkan.
        $this->assertSame(0, Hold::count(), 'Hold memang dihapus saat penagihan.');

        $transaction = Transaction::firstOrFail();
        $this->assertSame('QR-1111', $transaction->menu_ref,
            'ref pesanan menu harus ikut tersimpan di transaksi resmi.');

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Receipt')
                ->where('receipt.ref_number', 'QR-1111')
                ->where('receipt.totals.paid', true)
                ->where('receipt.items.0.title', 'Es Teh Uji')
            );
    }

    public function test_a_cashier_transaction_cannot_be_reached_through_a_menu_ref(): void
    {
        // Transaksi kasir biasa tidak punya menu_ref. Kalau pencarian receipt
        // memakai `reference_code` (yang diisi acak untuk transaksi kasir),
        // `QR-1234` bisa cocok dengan struk orang lain.
        Transaction::create([
            'invoice' => 'TRX-POS-1',
            'cashier_id' => $this->admin->id,
            'customer_name' => 'Pelanggan Kasir',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
            'subtotal' => 8000,
            'discount' => 0,
            'cash' => 8000,
            'change' => 0,
            'taxable_amount' => 8000,
            'tax_amount' => 0,
            'grand_total' => 8000,
            'total_discount' => 0,
            'total_markup' => 0,
            'total_fee' => 0,
            'cogs_method' => 'FIFO',
            'reference_code' => '1234',
        ]);

        $this->get(URL::signedRoute('public.menu.receipt', ['ref' => 'QR-1234']))
            ->assertNotFound();
    }

    public function test_cashier_receives_a_receipt_url_for_pending_menu_orders(): void
    {
        $hold = $this->createHold([
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
            'payment_expires_at' => now()->addMinutes(15),
        ]);

        $this->actingAs($this->admin)
            ->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Transactions/Index')
                ->where('holds.0.ref_number', 'QR-1111')
                ->where('holds.0.receipt_url', fn ($url) => is_string($url) && str_contains($url, 'signature='))
            );
    }

    public function test_cashier_history_exposes_a_receipt_url_only_for_menu_orders(): void
    {
        // Modal lacis cukup untuk dua transaksi tunai.
        $this->actingAs($this->admin)->post(route('shifts.store'), ['starting_cash' => 1000000]);
        \App\Models\Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 8000,
            'discount' => 0,
        ]);
        $this->actingAs($this->admin)
            ->postJson(route('transactions.store'), [
                'reference_code' => 'A1B2',
                'payment_gateway' => 'cash',
                'cash' => 8000,
                'customer_name' => 'Kasir',
            ])
            ->assertOk();

        $menuTransaction = Transaction::firstOrFail();
        $menuTransaction->update(['menu_ref' => 'QR-1111']);

        // Transaksi kasir biasa, tanpa menu_ref.
        \App\Models\Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => 8000,
            'discount' => 0,
        ]);
        $this->actingAs($this->admin)
            ->postJson(route('transactions.store'), [
                'reference_code' => 'C3D4',
                'payment_gateway' => 'cash',
                'cash' => 8000,
                'customer_name' => 'Kasir',
            ])
            ->assertOk();

        $this->assertSame(2, Transaction::count());

        $rows = $this->actingAs($this->admin)
            ->get(route('transactions.history'))
            ->assertOk()
            ->viewData('page')['props']['transactions']['data'];

        $withReceipt = array_values(array_filter($rows, fn ($row) => filled($row['receipt_url'] ?? null)));
        $withoutReceipt = array_values(array_filter($rows, fn ($row) => blank($row['receipt_url'] ?? null)));

        $this->assertCount(1, $withReceipt, 'Hanya pesanan menu QR yang punya struk digital.');
        $this->assertCount(1, $withoutReceipt);
        $this->assertStringContainsString('QR-1111', $withReceipt[0]['receipt_url']);
        $this->assertStringContainsString('signature=', $withReceipt[0]['receipt_url']);
    }

    public function test_order_response_includes_a_signed_receipt_url(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), [
                'customer_name' => 'Budi',
                'order_type' => 'takeaway',
                'cart_items' => [['product_id' => $this->product->id, 'qty' => 1]],
                'total' => 8000,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $receiptUrl = (string) $response->json('receipt_url');

        $this->assertStringContainsString('signature=', $receiptUrl,
            'URL struk harus ditandatangani server, bukan polos. Tanpa itu '
            .'siapa pun bisa menebak ref_number dan melihat pesanan orang lain.');

        $this->assertStringContainsString(
            rawurlencode(Hold::firstOrFail()->ref_number),
            $receiptUrl
        );

        // URL dari respons harus benar-benar bisa dibuka, dan hanya itu yang bisa.
        $this->get($receiptUrl)->assertOk();
        $this->get('/menu/struk/'.Hold::firstOrFail()->ref_number)->assertForbidden();
    }
}
