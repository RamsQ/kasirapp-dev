<?php

namespace Tests\Feature\Uat;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\StockBatchAllocation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT verifikasi data demo: reservasi pembayaran yang masih pending.
 *
 * MASALAH: `uat:verify-demo` memberi saran yang SALAH kalau ada pembayaran
 * gateway yang belum selesai.
 *
 * Saat penjualan lewat gateway:
 *   - `products.stock` langsung turun (barang memang keluar)
 *   - `stock_batches.qty_remaining` TIDAK turun; alokasinya berstatus
 *     `reserved` sampai callback masuk, supaya refund nanti tahu batch mana
 *     yang harus dikembalikan
 *
 * Akibatnya selama pembayaran pending, `products.stock` lebih kecil dari
 * `sum(stock_batches.qty_remaining)`. Verify dulu melaporkan itu sebagai
 * "selisih" dan menyuruh operator menjalankan `inventory:reconcile`, padahal
 * reconcile akan MEMBEKUKAN selisih sementara itu jadi reality. Batch dan
 * master lalu tidak akan pernah cocok lagi.
 *
 * Test ini mengunci dua hal:
 *  1. Reservasi pending tidak dianggap selisih stok.
 *  2. Selisih neraca sebesar nilai barang yang di-reserve dijelaskan sebagai
 *     hal yang normal, bukan kesalahan pencatatan.
 */
class UatVerifyPendingPaymentTest extends TestCase
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
            'category_id' => Category::create(['name' => 'Snacking'])->id,
            'title' => 'Keripik Uji',
            'buy_price' => 7000,
            'sell_price' => 12000,
            'stock' => 0,
            'unit' => 'Pcs',
            'type' => 'single',
        ]);

        // Modal sengaja TIDAK dibuat di sini supaya HPP/pendapatan tetap 0
        // dan satu-satunya sumber selisih neraca adalah reservasi pending.
        \App\Models\Capital::create([
            'source' => 'Setoran Modal',
            'amount' => 560000,
            'date' => now()->subDay()->toDateString(),
        ]);
    }

    /**
     * Simulasikan penjualan gateway yang pembayarannya belum masuk, memakai
     * CogsService yang sungguhan. Baris dibuat manual justru berisiko salah
     * menggambarkan perilaku asli, dan arah selisihnya sudah pernah terbalas
     * karena itu.
     */
    protected function pendingGatewaySale(int $qty = 1): Transaction
    {
        $batch = StockBatch::create([
            'product_id' => $this->product->id,
            'buy_price' => 7000,
            'qty_in' => 80,
            'qty_remaining' => 80,
        ]);
        $this->product->update(['stock' => 80]);

        $transaction = Transaction::create([
            'invoice' => 'TRX-PENDING-1',
            'cashier_id' => $this->admin->id,
            'customer_name' => 'Uji',
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
            'status' => 'completed',
            'subtotal' => 12000 * $qty,
            'discount' => 0,
            'tax_amount' => 0,
            'cash' => 0,
            'change' => 0,
            'total_markup' => 0,
            'total_fee' => 0,
            'grand_total' => 12000 * $qty,
            'taxable_amount' => 12000 * $qty,
            'total_discount' => 0,
            'cogs_method' => 'FIFO',
        ]);

        // Parameter terakhir `true` = pembayaran masih pending. Ini jalur yang
        // dipakai POS saat kasir memilih Midtrans/Xendit.
        $hpp = app(\App\Services\CogsService::class)->calculate(
            $this->product->id,
            $qty,
            'FIFO',
            null,
            $transaction,
            true,
        );

        $transaction->details()->create([
            'product_id' => $this->product->id,
            'qty' => $qty,
            'price' => 12000,
            'buy_price' => $qty > 0 ? $hpp / $qty : 0,
            'total' => 12000 * $qty,
            'subtotal' => 12000 * $qty,
        ]);

        $this->assertNotNull($batch->fresh(), 'Batch harus utuh untuk pemeriksaan berikut.');

        return $transaction->fresh();
    }

    /**
     * Berapa yang benar-benar terjadi pada master dan batch saat pembayaran
     * masih pending. Ini yang diuji UAT, bukan asumsi.
     */
    protected function stockState(): array
    {
        return [
            'master' => (float) $this->product->fresh()->stock,
            'batch' => (float) StockBatch::where('product_id', $this->product->id)->sum('qty_remaining'),
            'reserved' => (float) StockBatchAllocation::where('transaction_id', 1)
                ->where('status', 'reserved')->sum('quantity'),
        ];
    }

    protected function verifyOutput(): string
    {
        Artisan::call('uat:verify-demo');

        return Artisan::output();
    }

    public function test_pending_gateway_sale_makes_master_and_batch_diverge(): void
    {
        $this->pendingGatewaySale(1);

        $state = $this->stockState();

        // Dokumentasi perilaku yang benar-benar terjadi, supaya test di bawah
        // tidak dibangun di atas asumsi yang salah.
        $this->assertSame(80.0, $state['master'], 'Master tidak turun dulu.');
        $this->assertSame(79.0, $state['batch'], 'Batch turun saat penjualan.');
        $this->assertSame(1.0, $state['reserved'], 'Alokasi ditandai reserved.');
    }

    public function test_reserved_stock_is_not_reported_as_drift(): void
    {
        $this->pendingGatewaySale(1);

        $exit = Artisan::call('uat:verify-demo');
        $output = Artisan::output();

        $this->assertStringContainsString('Semua stok cocok', $output,
            'Batch tertahan untuk pembayaran pending harus memperhitungkan '
            .'penyesuaiannya, bukan dilaporkan sebagai selisih.');
        $this->assertSame(0, $exit,
            'Reservasi pending bukan selisih stok. Kalau command ini gagal, '
            .'operator akan menjalankan inventory:reconcile dan membekukan '
            .'selisih sementara jadi reality.');
    }

    public function test_verify_explains_the_balance_difference_instead_of_blaming_recording(): void
    {
        $this->pendingGatewaySale(1);

        Artisan::call('uat:verify-demo');

        $output = Artisan::output();

        $this->assertStringContainsString('SELISIH DITJELASKAN', $output,
            'Selisih sebesar nilai barang yang sedang di-reserve harus dijelaskan.');
        $this->assertStringContainsString('Jangan jalankan', $output,
            'Command harus menyebutkan secara eksplisit bahwa reconcile tidak boleh dijalankan.');
    }

    public function test_a_paid_sale_consumes_the_batch_and_removes_the_difference(): void
    {
        $transaction = $this->pendingGatewaySale(1);

        // Pembayaran masuk: master ikut turun dan alokasi jadi consumed.
        app(\App\Services\CogsService::class)->markTransactionPaid($transaction->fresh());
        $this->product->update(['stock' => 79]);
        $transaction->update(['payment_status' => 'paid']);

        Artisan::call('uat:verify-demo');

        $output = Artisan::output();

        $this->assertStringNotContainsString('SELISIH DITJELASKAN', $output,
            'Kalau pembayaran sudah masuk, tidak ada lagi yang di-reserve, jadi '
            .'selisih harus hilang dan neraca balance.');
        $this->assertStringContainsString('Semua stok cocok', $output);
    }
}
