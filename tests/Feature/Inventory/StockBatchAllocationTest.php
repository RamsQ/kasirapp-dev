<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Hold;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockBatchAllocation;
use App\Models\StockReservation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CogsService;
use App\Services\StockIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockBatchAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifo_allocation_is_recorded_and_refund_restores_the_exact_batch(): void
    {
        [$product, $oldest] = $this->productWithBatches();
        $transaction = $this->transaction($product, 'paid');
        $service = app(CogsService::class);

        $hpp = $service->calculate($product->id, 3, 'FIFO', null, $transaction);

        $this->assertSame(30000.0, (float) $hpp);
        $this->assertSame(2.0, (float) $oldest->fresh()->qty_remaining);
        $this->assertDatabaseHas('stock_batch_allocations', [
            'transaction_id' => $transaction->id,
            'stock_batch_id' => $oldest->id,
            'movement_type' => 'sale',
            'status' => 'consumed',
            'quantity' => 3,
            'total_cost' => 30000,
        ]);

        $service->reverseTransaction($transaction->fresh());
        $service->reverseTransaction($transaction->fresh());

        $this->assertSame(5.0, (float) $oldest->fresh()->qty_remaining);
        $this->assertDatabaseHas('stock_batch_allocations', [
            'transaction_id' => $transaction->id,
            'movement_type' => 'refund',
            'status' => 'reversed',
            'quantity' => 3,
        ]);
        $this->assertSame(1, StockBatchAllocation::where('transaction_id', $transaction->id)
            ->where('movement_type', 'sale')
            ->where('status', 'reversed')
            ->count());
    }

    public function test_average_and_lifo_use_the_configured_costing_method(): void
    {
        [$product, $oldest, $newest] = $this->productWithBatches();
        $averageTransaction = $this->transaction($product, 'paid');
        $averageService = app(CogsService::class);

        $averageHpp = $averageService->calculate($product->id, 3, 'AVERAGE', null, $averageTransaction);

        $this->assertSame(45000.0, (float) $averageHpp);
        $this->assertSame(2.0, (float) $oldest->fresh()->qty_remaining);
        $this->assertSame(5.0, (float) $newest->fresh()->qty_remaining);
        $this->assertDatabaseHas('stock_batch_allocations', [
            'transaction_id' => $averageTransaction->id,
            'unit_cost' => 15000,
            'method' => 'AVERAGE',
        ]);

        $lifoTransaction = $this->transaction($product, 'paid');
        $lifoHpp = $averageService->calculate($product->id, 2, 'LIFO', null, $lifoTransaction);

        $this->assertSame(40000.0, (float) $lifoHpp);
        $this->assertSame(3.0, (float) $newest->fresh()->qty_remaining);
    }

    public function test_pending_gateway_allocation_is_released_and_can_be_reallocated_on_late_callback(): void
    {
        [$product, $oldest] = $this->productWithBatches();
        $transaction = $this->transaction($product, 'pending');
        $service = app(CogsService::class);

        $service->calculate($product->id, 2, 'FIFO', null, $transaction, true);

        $this->assertSame(3.0, (float) $oldest->fresh()->qty_remaining);
        $this->assertDatabaseHas('stock_batch_allocations', [
            'transaction_id' => $transaction->id,
            'status' => 'reserved',
            'quantity' => 2,
        ]);

        $service->releaseTransaction($transaction->fresh());
        $this->assertSame(5.0, (float) $oldest->fresh()->qty_remaining);
        $this->assertDatabaseHas('stock_batch_allocations', [
            'transaction_id' => $transaction->id,
            'movement_type' => 'release',
            'status' => 'released',
        ]);

        // A late provider callback may still settle the transaction.
        $service->markTransactionPaid($transaction->fresh());
        $this->assertSame(3.0, (float) $oldest->fresh()->qty_remaining);
        $this->assertDatabaseHas('stock_batch_allocations', [
            'transaction_id' => $transaction->id,
            'movement_type' => 'sale',
            'status' => 'consumed',
            'quantity' => 2,
        ]);

        $service->reverseTransaction($transaction->fresh());
        $this->assertSame(5.0, (float) $oldest->fresh()->qty_remaining);
    }

    public function test_pending_batch_layers_reduce_available_master_stock_until_settlement(): void
    {
        [$product] = $this->productWithBatches();
        $transaction = $this->transaction($product, 'pending');
        $cogs = app(CogsService::class);
        $stock = app(StockIntelligenceService::class);

        $cogs->calculate($product->id, 2, 'FIFO', null, $transaction, true);

        $this->assertSame(8.0, (float) $stock->availabilityForProduct($product)['available_quantity']);
    }

    public function test_hold_reservation_and_pending_layer_are_not_double_counted(): void
    {
        [$product] = $this->productWithBatches();
        $hold = Hold::create([
            'ref_number' => 'HOLD-BATCH',
            'cart_data' => [],
            'total' => 0,
            'user_id' => User::factory()->create()->id,
        ]);
        StockReservation::create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 'active',
            'expires_at' => now()->addHour(),
        ]);
        $transaction = $this->transaction($product, 'pending');
        $hold->update(['transaction_id' => $transaction->id]);
        app(CogsService::class)->calculate($product->id, 2, 'FIFO', null, $transaction, true);

        $this->assertSame(8.0, (float) app(StockIntelligenceService::class)
            ->availabilityForProduct($product)['available_quantity']);
    }

    public function test_specific_serial_allocation_only_uses_the_selected_batch(): void
    {
        [$product, $oldest, $newest] = $this->productWithBatches();
        $transaction = $this->transaction($product, 'paid');
        $service = app(CogsService::class);

        $hpp = $service->calculate($product->id, 1, 'SPECIFIC', $newest->serial_number, $transaction);

        $this->assertSame(20000.0, (float) $hpp);
        $this->assertSame(5.0, (float) $oldest->fresh()->qty_remaining);
        $this->assertSame(4.0, (float) $newest->fresh()->qty_remaining);
        $this->assertDatabaseHas('stock_batch_allocations', [
            'transaction_id' => $transaction->id,
            'stock_batch_id' => $newest->id,
            'method' => 'SPECIFIC',
        ]);
    }

    /**
     * @return array{0: Product, 1: StockBatch, 2: StockBatch}
     */
    protected function productWithBatches(): array
    {
        $category = Category::create(['name' => 'Batch Category '.Str::random(4)]);
        $product = Product::create([
            'category_id' => $category->id,
            'title' => 'Produk Batch '.Str::random(4),
            'buy_price' => 10000,
            'sell_price' => 30000,
            'stock' => 10,
            'cost_price' => 0,
            'unit' => 'Pcs',
        ]);
        $oldest = StockBatch::create([
            'product_id' => $product->id,
            'qty_in' => 5,
            'qty_remaining' => 5,
            'buy_price' => 10000,
            'serial_number' => 'BATCH-OLD',
        ]);
        $oldest->update(['created_at' => now()->subDay()]);
        $newest = StockBatch::create([
            'product_id' => $product->id,
            'qty_in' => 5,
            'qty_remaining' => 5,
            'buy_price' => 20000,
            'serial_number' => 'BATCH-NEW',
        ]);

        return [$product, $oldest, $newest];
    }

    protected function transaction(Product $product, string $status): Transaction
    {
        return Transaction::create([
            'cashier_id' => User::factory()->create()->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(10)),
            'customer_name' => 'Batch Customer',
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 90000,
            'taxable_amount' => 90000,
            'tax_amount' => 0,
            'payment_method' => 'cash',
            'payment_status' => $status,
            'status' => $status === 'paid' ? 'paid' : 'pending',
        ]);
    }
}
