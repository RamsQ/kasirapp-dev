<?php

namespace Tests\Feature\Uat;

use App\Models\Capital;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Hold;
use App\Models\PaymentEvent;
use App\Models\PaymentSettlement;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Riwayat Pembayaran.
 *
 * `payment_events` adalah satu-satunya jejak audit pembayaran di aplikasi ini,
 * dan sudah dipakai `PaymentSettlementService` untuk memutuskan transaksi
 * lunas atau belum. Tapi jejaknya tidak pernah ditampilkan ke kasir.
 *
 * MASALAH YANG DISELESAIKAN:
 *  1. Riwayat pembayaran tidak ada sama sekali di halaman transaksi.
 *  2. Jalur pembayaran mandiri dari menu QR sama sekali tidak menulis event,
 *     jadi transaksi yang dibayar QRIS sebelum datang ke kasir — justru yang
 *     paling perlu ditelusuri — punya riwayat kosong.
 *  3. Hold dihapus saat penagihan. Kalau event ikut terhapus, jejaknya hilang
 *     tepat saat dibutuhkan.
 *
 * YANG DIJAGA DI SINI:
 *  - Status di UI harus sama dengan yang dipakai rekonsiliasi, jadi halaman
 *    ini TIDAK menghitung status sendiri dari kolom transaksi.
 *  - Event yang ditolak karena nominal tidak cocok harus terlihat, dengan
 *    alasannya. Kalau tidak, selisih uang di laci tidak bisa ditelusuri.
 *  - Tanda tangan tidak valid harus ditandai mencolok; itu satu-satunya tanda
 *    ada yang menyamar sebagai gateway.
 *  - Event dari sisi server (tanpa tanda tangan) tidak boleh ditampilkan
 *    sebagai "tidak valid", karena itu menyesatkan.
 */
class UatPaymentHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

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

        Shift::create([
            'user_id' => $this->admin->id,
            'starting_cash' => 1000000,
            'status' => 'open',
            'opened_at' => now()->subHour(),
        ]);

        Capital::create([
            'source' => 'Setoran Modal',
            'amount' => 1000000,
            'date' => now()->subDay()->toDateString(),
        ]);
    }

    protected ?int $categoryId = null;

    protected function product(int $sellPrice = 10000, int $stock = 50): \App\Models\Product
    {
        // Sengaja memakai property instance, bukan `static`. Database setiap
        // test di-rollback, jadi id kategori dari test sebelumnya sudah tidak
        // ada lagi kalau disimpan di static.
        $this->categoryId ??= Category::firstOrCreate(['name' => 'Minuman'])->id;

        return \App\Models\Product::create([
            'category_id' => $this->categoryId,
            'title' => 'Menu Uji',
            'buy_price' => 4000,
            'sell_price' => $sellPrice,
            'stock' => $stock,
            'unit' => 'Gelas',
            'type' => 'single',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function historyRow(): array
    {
        $rows = $this->actingAs($this->admin)
            ->get(route('transactions.history'))
            ->assertOk()
            ->viewData('page')['props']['transactions']['data'];

        return $rows[0] ?? [];
    }

    // ------------------------------------------------------------------
    // RIWAYAT UNTUK TRANSAKSI KASIR (SETTLEMENT LAMA)
    // ------------------------------------------------------------------

    public function test_gateway_callbacks_appear_in_the_transaction_history(): void
    {
        $transaction = $this->gatewayTransaction('midtrans', 'paid');

        PaymentEvent::create([
            'transaction_id' => $transaction->id,
            'provider' => 'midtrans',
            'provider_reference' => 'TRX-1',
            'status' => 'created',
            'amount' => 10000,
            // Dipanggil dari sisi server saat QR dibuat: tidak ada tanda
            // tangan yang perlu diverifikasi.
            'signature_valid' => null,
            'processed' => true,
            'received_at' => now()->subMinutes(5),
        ]);
        PaymentEvent::create([
            'transaction_id' => $transaction->id,
            'provider' => 'midtrans',
            'provider_reference' => 'TRX-1',
            'status' => 'pending',
            'amount' => 10000,
            'signature_valid' => true,
            'processed' => true,
            'received_at' => now()->subMinutes(4),
        ]);
        PaymentEvent::create([
            'transaction_id' => $transaction->id,
            'provider' => 'midtrans',
            'provider_reference' => 'TRX-1',
            'status' => 'settlement',
            'amount' => 10000,
            'signature_valid' => true,
            'processed' => true,
            'received_at' => now()->subMinutes(3),
        ]);

        $row = $this->historyRow();
        $events = $row['payment_timeline'];

        $this->assertCount(3, $events, 'Semua callback harus terlihat, bukan hanya yang terakhir.');
        $this->assertSame(
            ['Tagihan dibuat', 'Menunggu pembayaran', 'LUNAS'],
            array_column($events, 'status_label'),
            'Urutan harus kronologis, dari yang paling lama.'
        );
        // "Tagihan dibuat" dibuat di sisi server, jadi tidak punya tanda
        // tangan. Nilainya harus null, kalau tidak akan terlihat sama dengan
        // tanda tangan palsu.
        $this->assertSame(
            ['pending', 'pending', 'success'],
            array_column($events, 'tone')
        );
        $this->assertSame(
            ['Midtrans', 'Midtrans', 'Midtrans'],
            array_column($events, 'provider_label')
        );
    }

    public function test_summary_reports_provider_status_and_attempts(): void
    {
        $transaction = $this->gatewayTransaction('xendit', 'paid');

        PaymentSettlement::create([
            'transaction_id' => $transaction->id,
            'provider' => 'xendit',
            'provider_reference' => 'xnd_1',
            'status' => 'paid',
            'amount' => 10000,
            'settled_amount' => 10000,
            'attempt_count' => 3,
            'settled_at' => now(),
        ]);

        $summary = $this->historyRow()['payment_summary'];

        $this->assertSame('xendit', $summary['provider']);
        $this->assertSame('Xendit', $summary['provider_label']);
        $this->assertSame('LUNAS', $summary['status_label']);
        $this->assertSame('success', $summary['tone']);
        $this->assertSame(3, $summary['attempts']);
        $this->assertNotNull($summary['settled_at']);
    }

    public function test_invalid_signature_is_surfaced_with_its_own_tone(): void
    {
        $transaction = $this->gatewayTransaction('midtrans', 'paid');

        PaymentEvent::create([
            'transaction_id' => $transaction->id,
            'provider' => 'midtrans',
            'provider_reference' => 'PALSU',
            'status' => 'settlement',
            'amount' => 10000,
            // Tanda tangan tidak cocok: ada yang mencoba menyamar.
            'signature_valid' => false,
            'processed' => false,
            'processing_error' => 'Signature Midtrans tidak valid.',
            'received_at' => now(),
        ]);

        $event = $this->historyRow()['payment_timeline'][0];

        $this->assertFalse($event['signature_valid'],
            'Tanda tangan tidak valid harus diteruskan apa adanya, bukan '
            .'dibuat null supaya tidak terlihat.');
        $this->assertFalse($event['processed']);
        $this->assertSame('Signature Midtrans tidak valid.', $event['error']);
        $this->assertSame('failed', $event['tone'],
            'Callback palsu tidak boleh digambar hijau "LUNAS" di sebelah '
            .'tulisan tanda tangan tidak valid. Dua hal yang bertentangan '
            .'dalam satu baris, dan kasir bisa mempercayai yang hijau.');
    }

    public function test_server_side_event_is_not_shown_as_invalid_signature(): void
    {
        $transaction = $this->gatewayTransaction('midtrans', 'paid');

        // Event "tagihan dibuat" dibuat di sisi server, jadi tidak ada tanda
        // tangan sama sekali. Kalau ditampilkan sebagai "tidak valid", kasir
        // akan curiga ada penyalahgunaan yang sebenarnya tidak terjadi.
        PaymentEvent::create([
            'transaction_id' => $transaction->id,
            'provider' => 'midtrans',
            'provider_reference' => 'TRX-1',
            'status' => 'created',
            'amount' => 10000,
            'signature_valid' => null,
            'processed' => true,
            'received_at' => now(),
        ]);

        $event = $this->historyRow()['payment_timeline'][0];

        $this->assertNull($event['signature_valid'],
            'Harus tetap null supaya frontend tahu tidak ada tanda tangan untuk diverifikasi.');
        $this->assertNotSame('failed', $event['tone']);
    }

    public function test_cash_transaction_has_an_empty_timeline_not_a_fake_one(): void
    {
        $product = $this->product();
        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 10000,
            'discount' => 0,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('transactions.store'), [
                'payment_gateway' => 'cash',
                'cash' => 10000,
                'customer_name' => 'Kasir',
            ])
            ->assertOk();

        $row = $this->historyRow();

        $this->assertSame([], $row['payment_timeline'],
            'Transaksi tunai tidak punya callback gateway. Memalsukan event '
            .'agar kolomnya tidak kosong akan menyesatkan.');
        $this->assertSame('cash', $row['payment_summary']['provider']);
    }

    // ------------------------------------------------------------------
    // JALUR PEMBAYARAN MANDIRI DARI MENU QR
    // ------------------------------------------------------------------

    public function test_self_paid_menu_order_records_its_own_payment_events(): void
    {
        $this->enableXendit();
        $product = $this->product(8000);

        $hold = Hold::create([
            'ref_number' => 'QR-4321',
            'reference_code' => '4321',
            'queue_number' => 'Q-001',
            'customer_name' => 'Budi #4321',
            'cart_data' => [[
                'product_id' => $product->id,
                'product_title' => 'Menu Uji',
                'qty' => 1,
                'price' => 8000,
                'sell_price' => 8000,
                'current_unit_price' => 8000,
            ]],
            'total' => 8000,
            'taxable_amount' => 8000,
            'tax_amount' => 0,
            'user_id' => $this->admin->id,
            'requested_payment_method' => Hold::PREFERENCE_XENDIT,
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'api.xendit.co/qr_codes' => \Illuminate\Support\Facades\Http::response([
                'id' => 'qr_1', 'qr_string' => 'EMV', 'amount' => 8000,
            ], 200),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('payment.menu.start', $hold->id), ['provider' => 'xendit'])
            ->assertOk();

        $this->assertSame(1, PaymentEvent::where('hold_id', $hold->id)->count(),
            'Pembayaran mandiri harus menulis jejak audit sendiri. Kalau tidak, '
            .'transaksi yang dibayar QRIS sebelum datang ke kasir tidak punya '
            .'riwayat sama sekali.');

        $event = PaymentEvent::where('hold_id', $hold->id)->first();
        $this->assertSame('created', $event->status);
        $this->assertNull($event->signature_valid);
        $this->assertTrue($event->processed);
    }

    public function test_rejected_amount_is_recorded_with_its_reason(): void
    {
        $this->enableXendit();
        $hold = $this->holdFor(8000, 'QR-5555');

        // Pelanggan somehow membayar 100.000 untuk pesanan 8.000.
        $this->actingAs($this->admin)
            ->postJson(route('payment.xendit.notification'), [
                'id' => 'qr_x', 'external_id' => 'QR-5555', 'status' => 'SUCCEEDED', 'amount' => 100000,
            ], ['x-callback-token' => 'token-uji'])
            ->assertStatus(422);

        $event = PaymentEvent::where('hold_id', $hold->id)
            ->where('status', 'rejected')
            ->first();

        $this->assertNotNull($event,
            'Penolakan karena nominal tidak cocok harus tercatat. Kalau tidak, '
            .'selisih uang di laci tidak bisa ditelusuri.');
        $this->assertStringContainsString('tidak cocok dengan total pesanan', (string) $event->processing_error,
            'Alasan penolakan harus tersimpan utuh, bukan hanya kode status, '
            .'supaya kasir tahu apa yang harus dicocokkan dengan laci.');
        $this->assertFalse($event->processed);
    }

    public function test_payment_events_move_to_the_transaction_at_checkout(): void
    {
        $this->enableXendit();
        $product = $this->product(8000);
        $hold = $this->holdFor(8000, 'QR-6666');

        $hold->forceFill([
            'payment_method' => 'xendit',
            'payment_status' => 'paid',
            'paid_amount' => 8000,
            'paid_at' => now()->subMinute(),
        ])->save();

        PaymentEvent::create([
            'hold_id' => $hold->id,
            'provider' => 'xendit',
            'provider_reference' => 'qr_9',
            'status' => 'paid',
            'amount' => 8000,
            'signature_valid' => true,
            'processed' => true,
            'received_at' => now()->subMinute(),
        ]);

        Cart::create([
            'cashier_id' => $this->admin->id,
            'product_id' => $product->id,
            'qty' => 1,
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

        $this->assertSame(0, Hold::count(), 'Hold dihapus saat penagihan.');

        $transaction = Transaction::firstOrFail();

        $row = $this->historyRow();

        $this->assertCount(1, $row['payment_timeline'],
            'Jejak pembayaran harus pindah ke transaksi. Kalau ikut terhapus '
            .'bersama hold, transaksi yang dibayar sendiri lewat QRIS akan '
            .'punya riwayat kosong tepat saat paling perlu ditelusuri.');
        $this->assertSame('LUNAS', $row['payment_timeline'][0]['status_label']);
        $this->assertSame('xendit', $row['payment_summary']['provider']);
        $this->assertSame('Xendit', $row['payment_summary']['provider_label']);
        $this->assertSame($transaction->id, PaymentEvent::first()->transaction_id);
        $this->assertNull(PaymentEvent::first()->hold_id,
            'hold_id dikosongkan supaya event tidak terhitung dua kali.');
    }

    protected function enableXendit(): void
    {
        \App\Models\PaymentSetting::create([
            'xendit_enabled' => true,
            'xendit_secret_key' => 'xnd_secret',
            'xendit_callback_token' => 'token-uji',
        ]);
    }

    protected function holdFor(float $total, string $ref): Hold
    {
        $product = $this->product((int) $total);

        return Hold::create([
            'ref_number' => $ref,
            'reference_code' => substr($ref, -4),
            'queue_number' => 'Q-002',
            'customer_name' => 'Budi #'.substr($ref, -4),
            'cart_data' => [[
                'product_id' => $product->id,
                'product_title' => 'Menu Uji',
                'qty' => 1,
                'price' => $total,
                'sell_price' => $total,
                'current_unit_price' => $total,
            ]],
            'total' => $total,
            'taxable_amount' => $total,
            'tax_amount' => 0,
            'user_id' => $this->admin->id,
            'requested_payment_method' => Hold::PREFERENCE_XENDIT,
        ]);
    }

    protected function gatewayTransaction(string $provider, string $status): Transaction
    {
        $product = $this->product();

        return Transaction::create([
            'invoice' => 'TRX-UJI',
            'cashier_id' => $this->admin->id,
            'customer_name' => 'Uji',
            'payment_method' => $provider,
            'payment_status' => $status,
            'status' => 'completed',
            'subtotal' => 10000,
            'discount' => 0,
            'cash' => 0,
            'change' => 0,
            'taxable_amount' => 10000,
            'tax_amount' => 0,
            'grand_total' => 10000,
            'total_discount' => 0,
            'total_markup' => 0,
            'total_fee' => 0,
            'cogs_method' => 'FIFO',
            'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }
}
