<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockBatchAllocation;
use App\Models\StockMovement;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CogsService
{
    /**
     * Calculate HPP and allocate the physical cost layers used by the sale.
     *
     * Cash allocations are consumed immediately. Gateway allocations are
     * reserved while payment is pending and finalized by markTransactionPaid().
     * Every layer change is recorded so a refund can restore the exact batches.
     *
     * @return float
     */
    public function calculate(
        $productId,
        $qtySold,
        $method,
        $scannedSerial = null,
        ?Transaction $transaction = null,
        bool $paymentPending = false,
    ) {
        $product = Product::with('recipes.ingredient')->find($productId);
        $quantity = max(0, (float) $qtySold);
        if (! $product || $quantity <= 0) {
            return 0.0;
        }

        $method = $this->normalizeMethod($method);
        $product->loadMissing('recipes.ingredient');

        if ($product->recipes->isNotEmpty()) {
            $recipeCost = 0.0;
            foreach ($product->recipes as $recipe) {
                if (! $recipe->ingredient || (float) $recipe->qty_needed <= 0) {
                    continue;
                }

                $recipeCost += $this->allocateResource(
                    'ingredient',
                    (int) $recipe->ingredient_id,
                    $quantity * (float) $recipe->qty_needed,
                    $method,
                    null,
                    $transaction,
                    $paymentPending,
                );
            }

            // `cost_price` hanya perkiraan tampilan (hasil "Hitung HPP" saat
            // resep disimpan). HPP yang dilaporkan WAJIB memakai biaya batch
            // yang benar-benar dialokasikan, kalau tidak laba meleset begitu
            // harga bahan berubah tanpa ada yang menekan sinkron ulang.
            return (float) $recipeCost;
        }

        return $this->allocateResource(
            'product',
            (int) $product->id,
            $quantity,
            $method,
            $scannedSerial,
            $transaction,
            $paymentPending,
        );
    }

    /**
     * Finalize allocations that were reserved for a pending gateway payment.
     */
    public function markTransactionPaid(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $reserved = StockBatchAllocation::where('transaction_id', $transaction->id)
                ->where('movement_type', 'sale')
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->get();

            if ($reserved->isNotEmpty()) {
                StockBatchAllocation::whereKey($reserved->modelKeys())
                    ->update(['status' => 'consumed', 'updated_at' => now()]);

                return;
            }

            $alreadyConsumed = StockBatchAllocation::where('transaction_id', $transaction->id)
                ->where('movement_type', 'sale')
                ->where('status', 'consumed')
                ->exists();
            if ($alreadyConsumed) {
                return;
            }

            // A late gateway callback can arrive after the pending allocation
            // was released. Re-reserve the same layers when they are available.
            $released = StockBatchAllocation::where('transaction_id', $transaction->id)
                ->where('movement_type', 'sale')
                ->where('status', 'released')
                ->lockForUpdate()
                ->get();

            foreach ($released as $allocation) {
                $batch = $allocation->stock_batch_id
                    ? StockBatch::whereKey($allocation->stock_batch_id)->lockForUpdate()->first()
                    : null;
                if (! $batch || (float) $batch->qty_remaining < (float) $allocation->quantity) {
                    continue;
                }

                $batch->update([
                    'qty_remaining' => (float) $batch->qty_remaining - (float) $allocation->quantity,
                ]);
                $reference = 'sale-'.Str::uuid();
                StockBatchAllocation::create([
                    'transaction_id' => $transaction->id,
                    'stock_batch_id' => $batch->id,
                    'product_id' => $allocation->product_id,
                    'ingredient_id' => $allocation->ingredient_id,
                    'resource_type' => $allocation->resource_type,
                    'quantity' => $allocation->quantity,
                    'unit_cost' => $allocation->unit_cost,
                    'total_cost' => $allocation->total_cost,
                    'method' => $allocation->method,
                    'movement_type' => 'sale',
                    'status' => 'consumed',
                    'original_allocation_id' => $allocation->id,
                    'reference' => $reference,
                ]);
                $this->recordMovement(
                    $transaction,
                    $allocation->resource_type,
                    (int) ($allocation->product_id ?: $allocation->ingredient_id),
                    'out',
                    (float) $allocation->quantity,
                    (float) $allocation->unit_cost,
                    $reference,
                );
            }
        });
    }

    /**
     * Release pending layers when a gateway payment fails or is cancelled.
     */
    public function releaseTransaction(Transaction $transaction): void
    {
        $this->restoreAllocations($transaction, 'release', 'released');
    }

    /**
     * Restore consumed layers when a paid transaction is refunded.
     */
    public function reverseTransaction(Transaction $transaction): void
    {
        $this->restoreAllocations($transaction, 'refund', 'reversed');
    }

    private function allocateResource(
        string $resourceType,
        int $resourceId,
        float $quantity,
        string $method,
        ?string $scannedSerial,
        ?Transaction $transaction,
        bool $paymentPending,
    ): float {
        $remaining = $quantity;
        $totalCost = 0.0;
        $defaultCost = $this->defaultCost($resourceType, $resourceId);
        $field = $resourceType === 'ingredient' ? 'ingredient_id' : 'product_id';

        $query = StockBatch::where($field, $resourceId)
            ->where('qty_remaining', '>', 0);
        if ($method === 'SPECIFIC') {
            $batches = filled($scannedSerial)
                ? $query->where('serial_number', $scannedSerial)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                : collect();
        } else {
            $direction = $method === 'LIFO' ? 'desc' : 'asc';
            $batches = $query
                ->orderBy('created_at', $direction)
                ->orderBy('id', $direction)
                ->lockForUpdate()
                ->get();
        }

        $averageCost = null;
        if ($method === 'AVERAGE') {
            $totalQuantity = (float) $batches->sum('qty_remaining');
            if ($totalQuantity > 0) {
                $averageCost = (float) $batches->sum(
                    fn (StockBatch $batch): float => (float) $batch->qty_remaining * (float) $batch->buy_price
                ) / $totalQuantity;
            }
        }

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $batch->qty_remaining, $remaining);
            if ($take <= 0) {
                continue;
            }

            $unitCost = $averageCost ?? (float) $batch->buy_price;
            $batch->update([
                'qty_remaining' => (float) $batch->qty_remaining - $take,
            ]);

            $reference = 'sale-'.Str::uuid();
            StockBatchAllocation::create([
                'transaction_id' => $transaction?->id,
                'stock_batch_id' => $batch->id,
                'product_id' => $resourceType === 'product' ? $resourceId : null,
                'ingredient_id' => $resourceType === 'ingredient' ? $resourceId : null,
                'resource_type' => $resourceType,
                'quantity' => $take,
                'unit_cost' => $unitCost,
                'total_cost' => $take * $unitCost,
                'method' => $method,
                'movement_type' => 'sale',
                'status' => $paymentPending ? 'reserved' : 'consumed',
                'reference' => $reference,
            ]);
            $this->recordMovement($transaction, $resourceType, $resourceId, 'out', $take, $unitCost, $reference);

            $totalCost += $take * $unitCost;
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $totalCost += $remaining * $defaultCost;
        }

        return (float) $totalCost;
    }

    private function restoreAllocations(Transaction $transaction, string $movementType, string $finalStatus): void
    {
        DB::transaction(function () use ($transaction, $movementType, $finalStatus): void {
            $allocations = StockBatchAllocation::where('transaction_id', $transaction->id)
                ->where('movement_type', 'sale')
                ->whereIn('status', $movementType === 'release' ? ['reserved'] : ['consumed'])
                ->lockForUpdate()
                ->get();

            foreach ($allocations as $allocation) {
                $batch = $allocation->stock_batch_id
                    ? StockBatch::whereKey($allocation->stock_batch_id)->lockForUpdate()->first()
                    : null;
                if ($batch) {
                    $batch->update([
                        'qty_remaining' => (float) $batch->qty_remaining + (float) $allocation->quantity,
                    ]);
                }

                $reference = $movementType.'-'.Str::uuid();
                StockBatchAllocation::create([
                    'transaction_id' => $transaction->id,
                    'stock_batch_id' => $allocation->stock_batch_id,
                    'product_id' => $allocation->product_id,
                    'ingredient_id' => $allocation->ingredient_id,
                    'resource_type' => $allocation->resource_type,
                    'quantity' => $allocation->quantity,
                    'unit_cost' => $allocation->unit_cost,
                    'total_cost' => $allocation->total_cost,
                    'method' => $allocation->method,
                    'movement_type' => $movementType,
                    'status' => $finalStatus,
                    'original_allocation_id' => $allocation->id,
                    'reference' => $reference,
                ]);
                $this->recordMovement(
                    $transaction,
                    $allocation->resource_type,
                    (int) ($allocation->product_id ?: $allocation->ingredient_id),
                    'in',
                    (float) $allocation->quantity,
                    (float) $allocation->unit_cost,
                    $reference,
                );
                $allocation->update(['status' => $finalStatus]);
            }
        });
    }

    private function recordMovement(
        ?Transaction $transaction,
        string $resourceType,
        int $resourceId,
        string $type,
        float $quantity,
        float $unitCost,
        string $reference,
    ): void {
        if (! $transaction?->cashier_id) {
            return;
        }

        StockMovement::create([
            $resourceType === 'ingredient' ? 'ingredient_id' : 'product_id' => $resourceId,
            'user_id' => $transaction->cashier_id,
            'type' => $type,
            'qty' => $quantity,
            'price' => round($unitCost),
            'reference' => $reference,
        ]);
    }

    private function defaultCost(string $resourceType, int $resourceId): float
    {
        if ($resourceType === 'ingredient') {
            return (float) (Ingredient::whereKey($resourceId)->value('buy_price') ?? 0);
        }

        return (float) (Product::whereKey($resourceId)->value('buy_price') ?? 0);
    }

    private function normalizeMethod(string|int|null $method): string
    {
        $method = strtoupper(trim((string) $method));

        return in_array($method, ['AVERAGE', 'FIFO', 'LIFO', 'SPECIFIC'], true)
            ? $method
            : 'AVERAGE';
    }
}
