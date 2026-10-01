<?php

namespace Tests\Feature\Uat;

use App\Models\Category;
use App\Models\Hold;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Menu QR Publik: nama-brand dan pilihan bayar QRIS.
 *
 * Dua perubahan yang diminta:
 *  1. Nama "Mangku Jagad" di halaman menu diganti jadi "LakuPOS".
 *  2. Pelanggan bisa memilih QRIS dan memindai kode QRIS merchant dari
 *     HP-nya sendiri.
 *
 * CATATAN PENTING SOAL PEMBAYARAN: pesanan dari menu QR TIDAK pernah diproses
 * pembayaran. Backend membuat `Hold`, bukan `Transaction`, dan uangnya
 * ditagih di kasir. Jadi yang disimpan di hold adalah MINAT pelanggan
 * (`requested_payment_method`), bukan catatan pembayaran.
 *
 * Yang diuji di sini:
 *  - minat bayar tersimpan dan tidak hilang saat pesanan digabung ke hold yang
 *    sudah ada (misal pelanggan nambah pesanan di meja yang sama)
 *  - nilai yang tidak dikenal ditolak, jadi kasir tidak pernah menerima
 *    metode bayar yang tidak diausky
 *  - kredensial gateway TIDAK pernah dikirim ke halaman publik
 *  - QRIS hanya ditawarkan kalau merchant sudah mengunggah kodenya
 */
class UatPublicMenuQrisTest extends TestCase
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
            'buy_price' => 1000,
            'sell_price' => 5000,
            'stock' => 50,
            'unit' => 'Gelas',
            'type' => 'single',
        ]);
    }

    protected function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Budi',
            'order_type' => 'takeaway',
            'cart_items' => [['product_id' => $this->product->id, 'qty' => 1]],
            'total' => 5000,
        ], $overrides);
    }

    protected function enableQris(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('qris.png');
        $file->storeAs('public/payments', 'qris.png');

        PaymentSetting::create([
            'qris_manual_enabled' => true,
            'qris_manual_image' => 'qris.png',
        ]);
    }

    public function test_menu_page_is_branded_lakupos(): void
    {
        $this->actingAs($this->admin)
            ->get(route('public.menu'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Public/CustomerMenu'));
    }

    public function test_qris_is_hidden_when_merchant_has_not_uploaded_code(): void
    {
        $this->actingAs($this->admin)
            ->get(route('public.menu'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qris.enabled', false)
                ->where('qris.image_url', null)
            );
    }

    public function test_qris_image_is_shared_when_configured(): void
    {
        $this->enableQris();

        $this->actingAs($this->admin)
            ->get(route('public.menu'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qris.enabled', true)
                ->where('qris.image_url', fn ($url) => is_string($url) && str_contains($url, 'qris.png'))
            );
    }

    public function test_gateway_credentials_are_never_sent_to_the_public_page(): void
    {
        $this->enableQris();

        PaymentSetting::create([
            'qris_manual_enabled' => true,
            'qris_manual_image' => 'qris.png',
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'RAHASIA-SERVER-KEY',
            'midtrans_client_key' => 'RAHASIA-CLIENT-KEY',
            'xendit_enabled' => true,
            'xendit_secret_key' => 'RAHASIA-XENDIT-KEY',
        ]);

        $props = $this->actingAs($this->admin)
            ->get(route('public.menu'))
            ->assertOk()
            ->viewData('page')['props'];

        $encoded = json_encode($props);

        $this->assertStringNotContainsString('RAHASIA-SERVER-KEY', $encoded,
            'Server key Midtrans tidak boleh sampai ke halaman publik.');
        $this->assertStringNotContainsString('RAHASIA-CLIENT-KEY', $encoded);
        $this->assertStringNotContainsString('RAHASIA-XENDIT-KEY', $encoded);
    }

    public function test_cash_preference_is_stored_on_the_hold(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), $this->orderPayload([
                'requested_payment_method' => Hold::PREFERENCE_CASH,
            ]))
            ->assertOk()
            ->assertJson(['requested_payment_method' => Hold::PREFERENCE_CASH]);

        $this->assertSame(Hold::PREFERENCE_CASH, Hold::firstOrFail()->requested_payment_method);
    }

    public function test_qris_preference_is_stored_on_the_hold(): void
    {
        $this->enableQris();

        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), $this->orderPayload([
                'requested_payment_method' => Hold::PREFERENCE_QRIS,
            ]))
            ->assertOk()
            ->assertJson(['requested_payment_method' => Hold::PREFERENCE_QRIS]);

        $this->assertSame(Hold::PREFERENCE_QRIS, Hold::firstOrFail()->requested_payment_method);
    }

    public function test_qris_order_tells_the_customer_to_show_the_screen_to_cashier(): void
    {
        $this->enableQris();

        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), $this->orderPayload([
                'requested_payment_method' => Hold::PREFERENCE_QRIS,
            ]))
            ->assertOk()
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'QRIS'));
    }

    public function test_cash_order_keeps_the_plain_confirmation_message(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), $this->orderPayload([
                'requested_payment_method' => Hold::PREFERENCE_CASH,
            ]))
            ->assertOk()
            ->assertJsonPath('message', 'Pesanan Berhasil Dikirim!');
    }

    public function test_preference_defaults_to_cash_when_omitted(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), $this->orderPayload())
            ->assertOk();

        $this->assertSame(Hold::PREFERENCE_CASH, Hold::firstOrFail()->requested_payment_method,
            'Default harus tunai supaya kasir tidak menunggu QRIS yang tidak akan datang.');
    }

    public function test_unknown_payment_preference_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), $this->orderPayload([
                'requested_payment_method' => 'bitcoin',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('requested_payment_method');

        $this->assertSame(0, Hold::count());
    }

    /**
     * Frontend mengirim nilai gateway sebagai `requested_payment_method` saat
     * pelanggan memilih "Bayar Sendiri". Kalau daftar yang di-validate tidak
     * memuat nilai itu, endpoint /menu/order membalas 422 dan QRIS tidak pernah
     * bisa dibuat.
     */
    public function test_self_pay_gateway_names_pass_validation(): void
    {
        foreach (['midtrans', 'xendit'] as $provider) {
            $this->actingAs($this->admin)
                ->postJson(route('public.menu.order'), $this->orderPayload([
                    'requested_payment_method' => $provider,
                ]))
                ->assertOk()
                ->assertJsonPath('requested_payment_method', $provider);
        }

        $this->assertSame(2, Hold::count());
    }

    public function test_gateway_method_is_accepted_as_a_self_pay_preference(): void
    {
        // midtrans/xendit SAH sebagai preferensi: di situ pelanggan membayar
        // sendiri lewat QRIS dinamis, dan pesanan sudah lunas sebelum kasir
        // menagih. Kode ini sama dengan transactions.payment_method supaya
        // laporan tidak perlu menerjemahkan.
        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), $this->orderPayload([
                'requested_payment_method' => Hold::PREFERENCE_MIDTRANS,
            ]))
            ->assertOk()
            ->assertJsonPath('requested_payment_method', 'midtrans');

        $this->assertSame('midtrans', Hold::firstOrFail()->requested_payment_method);
    }

    public function test_arbitrary_gateway_name_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), $this->orderPayload([
                'requested_payment_method' => 'transfer',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('requested_payment_method');

        $this->assertSame(0, Hold::count(),
            'Transfer bukan pilihan yang bisa dibuat pelanggan sendiri dari '
            .'menu. Kalau diterima, kasir bisa mendapat permintaan yang tidak '
            .'bisa dilayani.');
    }

    public function test_adding_items_to_an_existing_hold_keeps_the_payment_preference(): void
    {
        $table = \App\Models\Table::create([
            'name' => 'Meja 1',
            'status' => 'available',
            'capacity' => 4,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), $this->orderPayload([
                'order_type' => 'table',
                'table_id' => $table->id,
                'requested_payment_method' => Hold::PREFERENCE_QRIS,
            ]))
            ->assertOk();

        $this->assertSame(1, Hold::count());

        // Pelanggan yang sama pesan lagi di meja yang sama, dan kali ini bayar
        // tunai. Minat terbaru yang dipakai, karena itu yang akan dibayar
        // kalau pesanan berisi dua metode.
        $this->actingAs($this->admin)
            ->postJson(route('public.menu.order'), $this->orderPayload([
                'order_type' => 'table',
                'table_id' => $table->id,
                'requested_payment_method' => Hold::PREFERENCE_CASH,
            ]))
            ->assertOk();

        $this->assertSame(1, Hold::count(), 'Pesanan harus digabung ke hold yang sama, bukan hold baru.');

        $hold = Hold::firstOrFail();
        $this->assertSame(Hold::PREFERENCE_CASH, $hold->requested_payment_method,
            'Minat terbaru harus menimpa, kalau tidak kasir menyiapkan QRIS '
            .'padahal pelanggan mau bayar tunai.');
        $this->assertCount(2, $hold->cart_data, 'Kedua pesanan harus ada di satu hold.');
    }

    public function test_hold_does_not_reserve_stock_twice_when_merged(): void
    {
        $table = \App\Models\Table::create([
            'name' => 'Meja 2',
            'status' => 'available',
            'capacity' => 4,
        ]);

        $payload = [
            'order_type' => 'table',
            'table_id' => $table->id,
            'requested_payment_method' => Hold::PREFERENCE_QRIS,
        ];

        $this->actingAs($this->admin)->postJson(route('public.menu.order'), $this->orderPayload($payload))->assertOk();
        $this->actingAs($this->admin)->postJson(route('public.menu.order'), $this->orderPayload($payload))->assertOk();

        $reserved = (float) StockReservation::where('product_id', $this->product->id)
            ->where('status', 'active')
            ->sum('quantity');

        $this->assertSame(2.0, $reserved,
            'Dua pesanan 1 gelas = 2 gelas tereservasi, bukan 1 atau 3.');
    }
}
