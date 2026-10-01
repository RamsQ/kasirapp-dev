<?php

namespace Tests\Feature\Finance;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerVoucher;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Shift;
use App\Models\StockBatch;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Kode promo bersama (tabel promo_codes) adalah fitur baru dari CRM dan harus
 * mengikuti formula keuangan yang sama persis seperti voucher pribadi:
 *
 *   net revenue = taxable_amount
 *   HPP         = SUM(transaction_details.buy_price * qty)
 *   gross profit= net revenue - HPP
 *
 * Diskon promo hanya boleh menurunkan net revenue, tidak boleh menyentuh HPP.
 */
class SharedPromoFinancialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create();
        $this->cashier->givePermissionTo(Permission::firstOrCreate([
            'name' => 'transactions-access',
            'guard_name' => 'web',
        ]));
        Shift::create([
            'user_id' => $this->cashier->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    protected function customer(): Customer
    {
        return Customer::create([
            'name' => 'Pelanggan Promo',
            'no_telp' => '0812999000',
            'address' => 'Alamat',
            'marketing_opt_in' => true,
        ]);
    }

    /** Produk: HPP 40.000, harga jual 100.000, stock 5. */
    protected function product(): Product
    {
        $category = Category::create(['name' => 'Kategori Promo '.uniqid()]);

        return Product::create([
            'category_id' => $category->id,
            'title' => 'Produk Promo',
            'buy_price' => 40000,
            'sell_price' => 100000,
            'stock' => 5,
            'unit' => 'Pcs',
        ]);
    }

    /**
     * Cart menyimpan price sebagai TOTAL BARIS (harga satuan x qty),
     * bukan harga satuan. Lihat TransactionController::updateCart().
     */
    protected function addToCart(Product $product, int $qty): void
    {
        Cart::create([
            'cashier_id' => $this->cashier->id,
            'product_id' => $product->id,
            'qty' => $qty,
            'price' => $product->sell_price * $qty,
        ]);
    }

    protected function checkout(Customer $customer, string $code, int $cash): Transaction
    {
        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), [
                'customer_id' => $customer->id,
                'voucher_code' => $code,
                'cash' => $cash,
                'payment_gateway' => 'cash',
            ])
            ->assertRedirect();

        return Transaction::latest('id')->firstOrFail();
    }

    /** HPP dihitung dari detail baris, sama seperti FinancialReportService. */
    protected function hppOf(Transaction $transaction): int
    {
        return (int) TransactionDetail::where('transaction_id', $transaction->id)
            ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(buy_price, 0) * COALESCE(qty, 0)'));
    }

    public function test_shared_promo_lowers_net_revenue_but_never_hpp(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->addToCart($product, 2); // 2 x 100.000 = 200.000

        PromoCode::create([
            'code' => 'HEMAT20',
            'name' => 'Promo Hemat 20rb',
            'type' => 'fixed',
            'value' => 20000,
            'is_active' => true,
        ]);

        $transaction = $this->checkout($customer, 'HEMAT20', 200000);

        $this->assertSame(20000, (int) $transaction->voucher_discount);
        $this->assertSame(180000, (int) $transaction->taxable_amount);
        $this->assertSame(180000, (int) $transaction->grand_total);

        // HPP tetap 2 x 40.000 dan tidak terpotong diskon.
        $this->assertSame(80000, $this->hppOf($transaction));
        $this->assertSame(100000, (int) $transaction->taxable_amount - $this->hppOf($transaction));
    }

    public function test_shared_promo_percentage_discount_is_computed_on_subtotal(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->addToCart($product, 1);

        PromoCode::create([
            'code' => 'TEN',
            'name' => 'Promo 10 Persen',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);

        $transaction = $this->checkout($customer, 'TEN', 100000);

        $this->assertSame(10000, (int) $transaction->voucher_discount);
        $this->assertSame(90000, (int) $transaction->taxable_amount);
        $this->assertSame(40000, $this->hppOf($transaction));
    }

    public function test_shared_promo_never_produces_negative_revenue(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->addToCart($product, 1);

        PromoCode::create([
            'code' => 'GILAS',
            'name' => 'Voucher Giant',
            'type' => 'fixed',
            'value' => 999999,
            'is_active' => true,
        ]);

        $transaction = $this->checkout($customer, 'GILAS', 200000);

        $this->assertSame(100000, (int) $transaction->voucher_discount);
        $this->assertSame(0, (int) $transaction->taxable_amount);
        $this->assertSame(0, (int) $transaction->grand_total);
        // HPP tetap ada walau revenue nol: inilah yang membuat laporan rugi terlihat.
        $this->assertSame(40000, $this->hppOf($transaction));
    }

    public function test_shared_promo_points_are_awarded_on_discounted_revenue(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->addToCart($product, 1);

        PromoCode::create([
            'code' => 'POIN',
            'name' => 'Promo Poin',
            'type' => 'fixed',
            'value' => 20000,
            'is_active' => true,
        ]);

        $this->checkout($customer, 'POIN', 100000);

        // floor(80.000 / 100) = 800 poin
        $this->assertSame(800, (int) $customer->fresh()->loyalty_points);
    }

    public function test_shared_promo_usage_quota_is_consumed_by_transaction(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->addToCart($product, 1);

        $promo = PromoCode::create([
            'code' => 'KUOTA2',
            'name' => 'Promo Kuota 2',
            'type' => 'fixed',
            'value' => 10000,
            'max_uses' => 2,
            'is_active' => true,
        ]);

        $this->checkout($customer, 'KUOTA2', 100000);

        $promo->refresh();
        $this->assertSame(1, $promo->used_count);
        $this->assertSame(0, $promo->reserved_count);
    }

    public function test_shared_promo_and_personal_voucher_yield_identical_financials(): void
    {
        $build = function (string $kind): array {
            $customer = $this->customer();
            $product = $this->product();
            $this->addToCart($product, 2);

            if ($kind === 'voucher') {
                CustomerVoucher::create([
                    'customer_id' => $customer->id,
                    'code' => 'SAMA10',
                    'name' => 'Voucher',
                    'type' => 'fixed',
                    'value' => 20000,
                ]);
            } else {
                PromoCode::create([
                    'code' => 'SAMA10',
                    'name' => 'Promo',
                    'type' => 'fixed',
                    'value' => 20000,
                    'is_active' => true,
                ]);
            }

            $transaction = $this->checkout($customer, 'SAMA10', 200000);

            return [
                'voucher_discount' => (int) $transaction->voucher_discount,
                'taxable_amount' => (int) $transaction->taxable_amount,
                'grand_total' => (int) $transaction->grand_total,
                'hpp' => $this->hppOf($transaction),
                'points' => (int) $customer->fresh()->loyalty_points,
            ];
        };

        $this->assertSame($build('voucher'), $build('promo'));
    }

    public function test_financial_report_reflects_shared_promo_discount(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->addToCart($product, 2);

        PromoCode::create([
            'code' => 'LAPORAN',
            'name' => 'Promo Laporan',
            'type' => 'fixed',
            'value' => 20000,
            'is_active' => true,
        ]);

        $this->checkout($customer, 'LAPORAN', 200000);

        $financials = app(FinancialReportService::class)->summary(
            Transaction::whereDate('created_at', now())
        );

        $this->assertSame(180000, (int) $financials['net_revenue']);
        $this->assertSame(80000, (int) $financials['hpp']);
        $this->assertSame(100000, (int) $financials['gross_profit_before_platform']);
    }

    public function test_refund_restores_stock_and_returns_promo_quota(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->addToCart($product, 2);

        $promo = PromoCode::create([
            'code' => 'REFUND1',
            'name' => 'Promo Refund',
            'type' => 'fixed',
            'value' => 20000,
            'is_active' => true,
        ]);

        $transaction = $this->checkout($customer, 'REFUND1', 200000);
        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertSame(1, $promo->fresh()->used_count);

        $this->refund($transaction);

        $this->assertSame(5, (int) $product->fresh()->stock, 'Stok harus kembali tepat satu kali.');
        $this->assertSame(0, $promo->fresh()->used_count, 'Kuota promo harus dikembalikan.');
    }

    public function test_refund_returns_personal_voucher_to_usable_state(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->addToCart($product, 1);

        $voucher = CustomerVoucher::create([
            'customer_id' => $customer->id,
            'code' => 'BALIK10',
            'name' => 'Voucher Refund',
            'type' => 'fixed',
            'value' => 10000,
        ]);

        $transaction = $this->checkout($customer, 'BALIK10', 100000);
        $this->assertSame('used', $voucher->fresh()->status);

        $this->refund($transaction);

        $voucher->refresh();
        $this->assertSame('active', $voucher->status, 'Voucher harus bisa dipakai lagi setelah refund.');
        $this->assertNull($voucher->used_transaction_id);

        // Voucher yang dikembalikan harus benar-benar bisa dipakai lagi.
        $this->assertSame(10000, app(LoyaltyService::class)
            ->voucherDiscountFor($customer->fresh(), 'BALIK10', 200000));
    }

    /** Refund butuh password akun dan role super-admin. */
    protected function refund(Transaction $transaction): void
    {
        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $admin = User::factory()->create(['password' => bcrypt('rahasia123')]);
        $admin->assignRole('super-admin');

        $this->actingAs($admin)
            ->post(route('transactions.refund', $transaction->id), ['password' => 'rahasia123'])
            ->assertRedirect();

        $this->assertSame('refunded', $transaction->fresh()->status);
    }

    public function test_batch_hpp_survives_shared_promo_checkout(): void
    {
        $customer = $this->customer();
        $product = $this->product();

        $batch = StockBatch::create([
            'product_id' => $product->id,
            'qty_in' => 10,
            'qty_remaining' => 10,
            'buy_price' => 40000,
            'serial_number' => 'BATCH-PROMO-1',
        ]);

        $this->addToCart($product, 2);

        PromoCode::create([
            'code' => 'BATCHPROMO',
            'name' => 'Promo Batch',
            'type' => 'fixed',
            'value' => 20000,
            'is_active' => true,
        ]);

        $transaction = $this->checkout($customer, 'BATCHPROMO', 200000);

        $this->assertSame(80000, $this->hppOf($transaction));
        $this->assertSame(180000, (int) $transaction->taxable_amount);
        $this->assertSame(8, (int) $batch->fresh()->qty_remaining);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'out',
        ]);
    }

    public function test_hpp_uses_buy_price_even_when_discount_exceeds_margin(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->addToCart($product, 1);

        PromoCode::create([
            'code' => 'BESAR',
            'name' => 'Promo Besar',
            'type' => 'fixed',
            'value' => 50000,
            'is_active' => true,
        ]);

        $transaction = $this->checkout($customer, 'BESAR', 100000);

        // Revenue 50.000, HPP tetap 40.000 -> laba kotor 10.000.
        $this->assertSame(50000, (int) $transaction->taxable_amount);
        $this->assertSame(40000, $this->hppOf($transaction));
        $this->assertSame(10000, (int) $transaction->taxable_amount - $this->hppOf($transaction));
    }
}
