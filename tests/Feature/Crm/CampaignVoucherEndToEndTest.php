<?php

namespace Tests\Feature\Crm;

use App\Models\Cart;
use App\Models\Category;
use App\Models\CrmCampaign;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CrmCampaignService;
use App\Services\CrmVoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Voucher yang diterbitkan campaign harus benar-benar bisa dipakai di kasir.
 * Ini alur yang belum pernah diuji ujung ke ujung.
 */
class CampaignVoucherEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_voucher_issued_by_campaign_can_be_redeemed_at_checkout(): void
    {
        Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);

        $cashier = User::factory()->create();
        $cashier->givePermissionTo('transactions-access');
        Shift::create([
            'user_id' => $cashier->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $category = Category::create(['name' => 'Kategori E2E']);
        $product = Product::create([
            'category_id' => $category->id,
            'barcode' => 'E2E-1',
            'title' => 'Produk E2E',
            'buy_price' => 20000,
            'sell_price' => 50000,
            'stock' => 20,
        ]);

        $segment = CustomerSegment::create([
            'name' => 'E2E Segment',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);

        // --- 1. Customer dapat voucher unik dari campaign (mode email, tanpa
        //        perlu token WhatsApp) ---
        $customer = Customer::create([
            'name' => 'E2E Customer',
            'no_telp' => '0812777000',
            'email' => 'e2e@example.com',
            'address' => 'Alamat',
            'marketing_opt_in' => true,
            'loyalty_tier' => 'gold',
        ]);

        $campaign = CrmCampaign::create([
            'name' => 'E2E Voucher',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'email',
            'subject' => 'Promo',
            'message' => 'Kode kamu {voucher_code}',
            'is_dry_run' => false,
            'status' => 'draft',
            'voucher_mode' => CrmVoucherService::MODE_PER_RECIPIENT,
            'voucher_name' => 'Diskon 10rb',
            'voucher_type' => 'fixed',
            'voucher_value' => 10000,
            'voucher_min_spend' => 0,
            'voucher_prefix' => 'E2E',
        ]);

        Mail::fake();
        $result = app(CrmCampaignService::class)->run($campaign);
        $this->assertSame(1, $result['sent']);

        $voucher = $customer->vouchers()->firstOrFail();
        $code = $voucher->code;
        $this->assertStringStartsWith('E2E-', $code);

        // Kode yang terkirim harus sama dengan voucher yang diterbitkan.
        $sentMessage = $campaign->deliveries()->first()->message;
        $this->assertStringContainsString($code, $sentMessage);

        // --- 2. Customer pakai kode itu di kasir ---
        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 50000,
        ]);

        $this->actingAs($cashier)
            ->post(route('transactions.store'), [
                'customer_id' => $customer->id,
                'voucher_code' => $code,
                'payment_gateway' => 'cash',
                'cash' => 50000,
                'change' => 0,
            ])
            ->assertRedirect();

        $transaction = Transaction::latest('id')->firstOrFail();

        $this->assertSame(10000, (int) $transaction->voucher_discount, 'Diskon voucher campaign harus terpakai.');
        $this->assertSame(40000, (int) $transaction->taxable_amount);
        $this->assertSame('used', $voucher->fresh()->status);
    }

    public function test_shared_promo_code_from_campaign_can_be_redeemed_at_checkout(): void
    {
        Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);

        $cashier = User::factory()->create();
        $cashier->givePermissionTo('transactions-access');
        Shift::create([
            'user_id' => $cashier->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $category = Category::create(['name' => 'Kategori Shared']);
        $product = Product::create([
            'category_id' => $category->id,
            'barcode' => 'SH-1',
            'title' => 'Produk Shared',
            'buy_price' => 20000,
            'sell_price' => 50000,
            'stock' => 20,
        ]);

        $segment = CustomerSegment::create([
            'name' => 'Shared Segment',
            'rules' => ['tiers' => ['gold']],
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'name' => 'Shared Customer',
            'no_telp' => '0812666000',
            'email' => 'shared@example.com',
            'address' => 'Alamat',
            'marketing_opt_in' => true,
            'loyalty_tier' => 'gold',
        ]);

        $campaign = CrmCampaign::create([
            'name' => 'Shared Promo',
            'segment_id' => $segment->id,
            'type' => 'manual',
            'channel' => 'email',
            'subject' => 'Promo',
            'message' => 'Pakai {voucher_code}',
            'is_dry_run' => false,
            'status' => 'draft',
            'voucher_mode' => CrmVoucherService::MODE_SHARED,
            'voucher_name' => 'Promo 15rb',
            'voucher_type' => 'fixed',
            'voucher_value' => 15000,
            'voucher_min_spend' => 0,
            'shared_voucher_code' => 'SHARED15',
        ]);

        Mail::fake();
        app(CrmCampaignService::class)->run($campaign);

        Cart::create([
            'cashier_id' => $cashier->id,
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 50000,
        ]);

        $this->actingAs($cashier)
            ->post(route('transactions.store'), [
                'customer_id' => $customer->id,
                'voucher_code' => 'shared15',
                'payment_gateway' => 'cash',
                'cash' => 50000,
                'change' => 0,
            ])
            ->assertRedirect();

        $transaction = Transaction::latest('id')->firstOrFail();

        $this->assertSame(15000, (int) $transaction->voucher_discount);
        $this->assertSame(35000, (int) $transaction->taxable_amount);
        $this->assertSame(1, \App\Models\PromoCode::where('code', 'SHARED15')->value('used_count'));
    }
}
