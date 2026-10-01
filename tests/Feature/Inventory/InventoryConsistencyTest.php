<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingredient_stock_in_creates_movement_and_batch_without_product_id(): void
    {
        $user = User::factory()->create();
        $ingredient = Ingredient::create([
            'name' => 'Gula Pasir',
            'unit' => 'kg',
            'stock' => 2,
            'min_stock' => 1,
            'buy_price' => 10000,
        ]);
        $service = app(InventoryStockService::class);

        $service->recordStockIn('ingredient', $ingredient->id, 3, 15000, $user->id);

        $this->assertSame(5.0, (float) $ingredient->fresh()->stock);
        $this->assertSame((float) ((2 * 10000 + 3 * 15000) / 5), (float) $ingredient->fresh()->buy_price);
        $this->assertDatabaseHas('stock_movements', [
            'ingredient_id' => $ingredient->id,
            'type' => 'in',
            'qty' => 3,
            'price' => 15000,
        ]);
        $this->assertDatabaseHas('stock_batches', [
            'ingredient_id' => $ingredient->id,
            'product_id' => null,
            'qty_remaining' => 3,
            'buy_price' => 15000,
        ]);
    }

    public function test_opname_adjustment_keeps_master_stock_and_batches_in_sync(): void
    {
        $user = User::factory()->create();
        [$product, $oldest, $newest] = $this->productWithBatches();
        $service = app(InventoryStockService::class);

        $service->setActualQuantity('product', $product->id, 6, $user->id, 'Opname Loss');
        $this->assertSame(6.0, (float) $product->fresh()->stock);
        $this->assertSame(1.0, (float) $oldest->fresh()->qty_remaining);
        $this->assertSame(5.0, (float) $newest->fresh()->qty_remaining);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'qty' => 4,
        ]);

        $service->setActualQuantity('product', $product->id, 8, $user->id, 'Opname Gain', 25000);
        $this->assertSame(8.0, (float) $product->fresh()->stock);
        $this->assertDatabaseHas('stock_batches', [
            'product_id' => $product->id,
            'qty_in' => 2,
            'qty_remaining' => 2,
            'buy_price' => 25000,
        ]);
    }

    public function test_transaction_refund_restores_master_stock_only_once(): void
    {
        [$product] = $this->productWithBatches();
        $product->update(['stock' => 0]);
        $transaction = Transaction::create([
            'cashier_id' => User::factory()->create()->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(10)),
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 20000,
            'taxable_amount' => 20000,
            'tax_amount' => 0,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'refunded',
        ]);
        $transaction->details()->create([
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 20000,
        ]);
        $service = app(\App\Services\StockIntelligenceService::class);

        $service->restoreForTransaction($transaction);
        $service->restoreForTransaction($transaction->fresh());

        $this->assertSame(1.0, (float) $product->fresh()->stock);
    }

    /**
     * @return array{0: Product, 1: StockBatch, 2: StockBatch}
     */
    protected function productWithBatches(): array
    {
        $category = Category::create(['name' => 'Consistency '.Str::random(4)]);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'Produk Consistency',
            'buy_price' => 10000,
            'sell_price' => 20000,
            'stock' => 10,
            'unit' => 'Pcs',
        ]);
        $oldest = StockBatch::create([
            'product_id' => $product->id,
            'qty_in' => 5,
            'qty_remaining' => 5,
            'buy_price' => 10000,
        ]);
        $oldest->update(['created_at' => now()->subDay()]);
        $newest = StockBatch::create([
            'product_id' => $product->id,
            'qty_in' => 5,
            'qty_remaining' => 5,
            'buy_price' => 10000,
        ]);

        return [$product, $oldest, $newest];
    }
}
