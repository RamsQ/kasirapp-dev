<?php

namespace Tests\Feature\Uat;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialReportService;
use Database\Seeders\UatDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UAT Data Demo: seeder harus menghasilkan angka HPP yang bisa dipercaya dan
 * tidak merusak data yang sudah ada.
 *
 * Ini yang membuat checklist UAT berarti: kalau laporan di browser dan
 * output `uat:verify-demo` berbeda, berarti ada bug perhitungan.
 */
class UatDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function seedAdmin(): User
    {
        $admin = User::factory()->create([
            'email' => 'admin@gmail.com',
            'password' => bcrypt('password'),
        ]);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->assignRole('super-admin');

        return $admin;
    }

    public function test_seeded_data_has_hpp_that_matches_the_batch_ledger(): void
    {
        $this->seedAdmin();
        $this->seed(UatDemoSeeder::class);

        $transactions = Transaction::where('payment_status', 'paid')->where('status', '!=', 'refunded');
        $summary = app(FinancialReportService::class)->summary(clone $transactions);

        $ids = (clone $transactions)->select('transactions.id');
        $ledger = (float) DB::table('stock_batch_allocations')
            ->whereIn('transaction_id', $ids)
            ->where('movement_type', 'sale')
            ->where('status', 'consumed')
            ->sum('total_cost');

        $snapshot = (float) DB::table('transaction_details')
            ->whereIn('transaction_id', $ids)
            ->sum(DB::raw('COALESCE(buy_price, 0) * COALESCE(qty, 0)'));

        $this->assertGreaterThan(0, $ledger, 'Ledger batch harus punya alokasi penjualan.');
        $this->assertEqualsWithDelta(
            $ledger,
            $snapshot,
            0.5,
            'HPP di laporan harus PERSIS sama dengan biaya yang dialokasikan dari batch.'
        );
        $this->assertEqualsWithDelta($ledger, (float) $summary['hpp'], 0.5,
            'FinancialReportService harus menghasilkan HPP yang sama.');
    }

    public function test_seeded_transactions_do_not_have_zero_hpp(): void
    {
        $this->seedAdmin();
        $this->seed(UatDemoSeeder::class);

        // Detail tanpa buy_price akan membuat Laporan Keuangan menampilkan
        // HPP Rp 0 dan laba terlihat jauh lebih besar dari kenyataan.
        $zeroHpp = DB::table('transaction_details')
            ->where(function ($query) {
                $query->whereNull('buy_price')->orWhere('buy_price', 0);
            })
            ->count();

        $this->assertSame(0, $zeroHpp, 'Semua detail transaksi harus punya HPP per unit.');
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedAdmin();

        $this->seed(UatDemoSeeder::class);
        $first = [
            'products' => Product::count(),
            'ingredients' => Ingredient::count(),
            'transactions' => Transaction::count(),
        ];

        $this->seed(UatDemoSeeder::class);
        $second = [
            'products' => Product::count(),
            'ingredients' => Ingredient::count(),
            'transactions' => Transaction::count(),
        ];

        $this->assertSame($first, $second, 'Menjalankan seeder dua kali tidak boleh menambah data.');
    }

    public function test_seeder_creates_batches_at_different_prices_for_fifo_testing(): void
    {
        $this->seedAdmin();
        $this->seed(UatDemoSeeder::class);

        $flour = Ingredient::where('name', 'Tepung Terigu')->firstOrFail();
        $batches = StockBatch::where('ingredient_id', $flour->id)->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(2, $batches->count(),
            'Bahan harus punya minimal 2 batch supaya FIFO vs LIFO bisa dibedakan.');

        $prices = $batches->pluck('buy_price')->unique();
        $this->assertGreaterThanOrEqual(2, $prices->count(),
            'Harga antar batch harus berbeda, kalau tidak FIFO/LIFO tidak terlihat efeknya.');
    }

    public function test_seeder_does_not_touch_admin_or_settings(): void
    {
        $admin = $this->seedAdmin();

        $this->seed(UatDemoSeeder::class);
        $this->seed(UatDemoSeeder::class);

        $this->assertNotNull(User::where('email', 'admin@gmail.com')->first(),
            'User admin tidak boleh terhapus.');

        $this->assertSame(1, \App\Models\Setting::count(),
            'Baris settings tidak boleh diduplikasi.');
    }

    public function test_seeder_creates_recipes_bundles_and_multi_unit(): void
    {
        $this->seedAdmin();
        $this->seed(UatDemoSeeder::class);

        $this->assertGreaterThan(0, DB::table('recipes')->count(), 'Harus ada menu resep.');
        $this->assertGreaterThan(0, DB::table('product_bundles')->count(), 'Harus ada bundel.');
        $this->assertGreaterThan(0, DB::table('product_units')->count(), 'Harus ada multi-satuan.');

        // Menu resep harus punya cost_price yang terisi, kalau tidak
        // peringatan HPP di halaman Resep tidak punya apa pun untuk dicek.
        $recipes = Product::has('recipes')->get();
        $this->assertGreaterThan(0, $recipes->count());
        foreach ($recipes as $menu) {
            $this->assertGreaterThan(0, (float) $menu->cost_price,
                "Menu resep {$menu->title} harus punya cost_price.");
        }
    }

    public function test_reset_command_keeps_admin_and_settings(): void
    {
        $admin = $this->seedAdmin();
        $this->seed(UatDemoSeeder::class);

        $this->artisan('uat:reset-demo --force')->assertSuccessful();

        $this->assertNotNull(User::where('email', 'admin@gmail.com')->first(),
            'Reset demo tidak boleh menghapus admin.');
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Ingredient::count());
        $this->assertSame(0, Transaction::count());
        $this->assertNotNull($admin->fresh());
    }

    public function test_verify_command_passes_on_seeded_data(): void
    {
        $this->seedAdmin();
        $this->seed(UatDemoSeeder::class);

        $this->artisan('uat:verify-demo')->assertSuccessful();
    }

    public function test_stock_always_matches_batch_layers(): void
    {
        $this->seedAdmin();
        $this->seed(UatDemoSeeder::class);

        foreach (Product::all() as $product) {
            if ($product->type === 'bundle') {
                // Bundel tidak punya stok fisik sendiri.
                $this->assertEqualsWithDelta(0.0, (float) $product->stock, 0.000001,
                    "Bundel {$product->title} tidak boleh punya stok fisik.");

                continue;
            }

            $batch = (float) StockBatch::where('product_id', $product->id)->sum('qty_remaining');
            $this->assertEqualsWithDelta((float) $product->stock, $batch, 0.000001,
                "Stok {$product->title} harus sama dengan jumlah batch.");
        }

        foreach (Ingredient::all() as $ingredient) {
            $batch = (float) StockBatch::where('ingredient_id', $ingredient->id)->sum('qty_remaining');
            $this->assertEqualsWithDelta((float) $ingredient->stock, $batch, 0.000001,
                "Stok bahan {$ingredient->name} harus sama dengan jumlah batch.");
        }
    }

    public function test_seeded_categories_are_not_duplicated(): void
    {
        $this->seedAdmin();
        $this->seed(UatDemoSeeder::class);
        $this->seed(UatDemoSeeder::class);

        $this->assertSame(1, Category::where('name', 'Makanan')->count());
    }
}
