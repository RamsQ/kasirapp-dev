<?php

namespace Tests\Feature\Uat;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\Shift;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\CogsService;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * UAT Stok Pecahan: kolom stok HARUS bisa menyimpan pecahan.
 *
 * Feastock_batches dan products.stock dulu integer, sehingga:
 *   - batch qty_remaining 10 - 0,12 -> 10 (tidak berkurang)
 *   - master stock 20 - 0,12        -> 19,88 (berkurang)
 * Ledger batch dan master jadi selalu beda, dan inventory:reconcile
 * melaporkan selisih permanen. Untuk restoran yang jual per berat, ini
 * membuat aplikasi tidak bisa dipakai sama sekali.
 */
class UatFractionalStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['email' => 'admin@gmail.com']);
        $admin->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));

        Shift::create([
            'user_id' => $admin->id,
            'starting_cash' => 0,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Terapkan migrasi perbaikan di tengah test, seperti di produksi.
        (include database_path('migrations/2026_09_26_000003_fix_fractional_stock_columns_to_decimal.php'))->up();
    }

    public function test_stock_columns_accept_fractions(): void
    {
        $ingredient = Ingredient::create([
            'name' => 'Gula Pecahan', 'buy_price' => 15000, 'stock' => 0, 'unit' => 'Kg',
        ]);

        app(InventoryStockService::class)->recordStockIn('ingredient', $ingredient->id, 10, 15000, 1);

        // Kurangi 0,12 via jalur resmi (opname) dan cek keduanya turun.
        app(InventoryStockService::class)->setActualQuantity(
            'ingredient',
            $ingredient->id,
            9.88,
            1,
            'Uji stok pecahan'
        );

        $batch = StockBatch::where('ingredient_id', $ingredient->id)->firstOrFail();

        $this->assertEqualsWithDelta(9.88, (float) $batch->qty_remaining, 0.000001,
            'Batch HARUS menyimpan 9,88. Kalau integer, nilainya balik jadi 10.');
        $this->assertEqualsWithDelta(9.88, (float) $ingredient->fresh()->stock, 0.000001);
    }

    public function test_fractional_sale_depletes_the_batch_ledger(): void
    {
        $ingredient = Ingredient::create([
            'name' => 'Teh Pecahan', 'buy_price' => 8000, 'stock' => 0, 'unit' => 'Kg',
        ]);
        $service = app(InventoryStockService::class);
        $service->recordStockIn('ingredient', $ingredient->id, 2, 8000, 1);
        $service->recordStockIn('ingredient', $ingredient->id, 2, 10000, 1);

        $cake = Product::create([
            'category_id' => \App\Models\Category::create(['name' => 'FP'])->id,
            'title' => 'Kue Pecahan', 'buy_price' => 0, 'sell_price' => 15000,
            'stock' => 0, 'unit' => 'Pcs',
        ]);
        Recipe::create(['product_id' => $cake->id, 'ingredient_id' => $ingredient->id, 'qty_needed' => 0.015]);

        // Jual 4 kue x 0,015 Kg = 0,06 Kg
        $hpp = app(CogsService::class)->calculate($cake->id, 4, 'FIFO');
        app(\App\Services\StockIntelligenceService::class)->consumeForCheckout([
            ['product_id' => $cake->id, 'qty' => 4],
        ]);

        $batchTotal = (float) StockBatch::where('ingredient_id', $ingredient->id)->sum('qty_remaining');

        $this->assertEqualsWithDelta(3.94, $batchTotal, 0.000001,
            'Batch total harus 3,94 (4,00 - 0,06).');
        $this->assertEqualsWithDelta((float) $ingredient->fresh()->stock, $batchTotal, 0.000001,
            'Stok master dan total batch harus selalu sama.');
        $this->assertEqualsWithDelta(0.06 * 8000, (float) $hpp, 0.01,
            'FIFO memakai batch pertama (8.000).');
    }

    public function test_product_stock_can_hold_a_fraction(): void
    {
        $product = Product::create([
            'category_id' => \App\Models\Category::create(['name' => 'FP2'])->id,
            'title' => 'Nasi Setengah Kg', 'buy_price' => 3000, 'sell_price' => 9000,
            'stock' => 0, 'unit' => 'Kg',
        ]);

        $service = app(InventoryStockService::class);
        $service->recordStockIn('product', $product->id, 10, 3000, 1);

        // Sama seperti TransactionController: CogsService yang membakar batch,
        // consumeForCheckout yang mengurangi stok master.
        app(CogsService::class)->calculate($product->id, 0.5, 'FIFO');
        app(\App\Services\StockIntelligenceService::class)->consumeForCheckout([
            ['product_id' => $product->id, 'qty' => 0.5],
        ]);

        $this->assertEqualsWithDelta(9.5, (float) $product->fresh()->stock, 0.000001,
            'Produk harus bisa menyimpan 9,5 setelah menjual 0,5.');
        $this->assertEqualsWithDelta(
            9.5,
            (float) StockBatch::where('product_id', $product->id)->sum('qty_remaining'),
            0.000001,
            'Batch produk juga harus menyimpan pecahan.'
        );
    }

    public function test_many_small_consumptions_accumulate_without_drift(): void
    {
        $ingredient = Ingredient::create([
            'name' => 'Gula Berkali-kali', 'buy_price' => 15000, 'stock' => 0, 'unit' => 'Kg',
        ]);
        $service = app(InventoryStockService::class);
        $service->recordStockIn('ingredient', $ingredient->id, 1, 15000, 1);

        // 100 kali consumption 0,01 -> total 1,00 harus benar-benar habis.
        for ($i = 0; $i < 100; $i++) {
            $service->setActualQuantity(
                'ingredient',
                $ingredient->id,
                1 - (($i + 1) * 0.01),
                1,
                "Konsumsi #$i"
            );
        }

        $this->assertEqualsWithDelta(0.0, (float) $ingredient->fresh()->stock, 0.000001);
        $this->assertEqualsWithDelta(
            0.0,
            (float) StockBatch::where('ingredient_id', $ingredient->id)->sum('qty_remaining'),
            0.000001,
            'Seratus kali konsumsi 0,01 harus menghabiskan batch tepat, tanpa selisih.'
        );
    }

    public function test_reconcile_command_reports_no_drift_after_fractional_sales(): void
    {
        $ingredient = Ingredient::create([
            'name' => 'Bahan Cek Drift', 'buy_price' => 15000, 'stock' => 0, 'unit' => 'Kg',
        ]);
        app(InventoryStockService::class)->recordStockIn('ingredient', $ingredient->id, 10, 15000, 1);

        $drink = Product::create([
            'category_id' => \App\Models\Category::create(['name' => 'FP3'])->id,
            'title' => 'Minuman Cek Drift', 'buy_price' => 0, 'sell_price' => 6000,
            'stock' => 0, 'unit' => 'Pcs',
        ]);
        Recipe::create(['product_id' => $drink->id, 'ingredient_id' => $ingredient->id, 'qty_needed' => 0.012]);

        app(CogsService::class)->calculate($drink->id, 7, 'FIFO');
        app(\App\Services\StockIntelligenceService::class)->consumeForCheckout([
            ['product_id' => $drink->id, 'qty' => 7],
        ]);

        $this->assertEqualsWithDelta(
            (float) $ingredient->fresh()->stock,
            (float) StockBatch::where('ingredient_id', $ingredient->id)->sum('qty_remaining'),
            0.000001,
            'Setelah 7 x 0,012 = 0,084 Kg, master dan batch harus tetap sama.'
        );
    }
}
