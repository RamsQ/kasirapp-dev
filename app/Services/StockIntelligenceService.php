<?php

namespace App\Services;

use App\Exceptions\StockUnavailableException;
use App\Models\Hold;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBatchAllocation;
use App\Models\StockReservation;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Shared stock intelligence and QR-order reservation rules.
 *
 * Reservations do not decrement master stock. They reduce the available
 * quantity until the hold is checked out or explicitly released.
 */
class StockIntelligenceService
{
    private const RESERVATION_MINUTES = 120;

    /**
     * Return availability for one product, including recipe and bundle
     * components and active reservations.
     *
     * @return array<string, mixed>
     */
    public function availabilityForProduct(Product $product, float $requestedQuantity = 1, ?int $excludeHoldId = null): array
    {
        $requestedQuantity = max(0, $requestedQuantity);
        $requirements = $this->requirementsForProduct($product, $requestedQuantity);
        $available = $this->availableQuantities($requirements, $excludeHoldId);
        $maximum = $this->maximumSellableQuantity($requirements, $available);
        $shortages = $this->shortages($requirements, $available);

        return [
            'is_available' => $maximum + 0.000001 >= $requestedQuantity && $shortages === [],
            'available_quantity' => max(0, round($maximum, 3)),
            'requested_quantity' => $requestedQuantity,
            'status' => $maximum <= 0 ? 'out' : ($maximum <= 5 ? 'low' : 'available'),
            'shortages' => $shortages,
            'requirements' => $requirements,
        ];
    }

    /**
     * Build the stock requirements for a cart/hold payload.
     *
     * @return array{products: array<int, array{product: Product, quantity: float}>, ingredients: array<int, array{ingredient: Ingredient, quantity: float}>}
     */
    public function requirementsForItems(iterable $items): array
    {
        $requirements = $this->emptyRequirements();

        foreach ($items as $item) {
            $productId = $this->itemValue($item, 'product_id');
            $product = Product::with(['bundle_items.units', 'recipes.ingredient'])->find($productId);
            if (! $product) {
                throw new StockUnavailableException('Produk pada pesanan tidak valid.');
            }

            $quantity = max(0, (float) $this->itemValue($item, 'qty'));
            if ($quantity <= 0) {
                continue;
            }

            $requirements = $this->mergeRequirements(
                $requirements,
                $this->requirementsForProduct(
                    $product,
                    $quantity,
                    $this->itemValue($item, 'product_unit_id')
                )
            );
        }

        return $requirements;
    }

    /**
     * Validate and reserve all resources for a hold in the current DB
     * transaction. Re-reserving a hold releases its previous active rows
     * first; a failed check rolls the surrounding transaction back.
     */
    public function reserveForHold(Hold $hold, iterable $items): void
    {
        $this->releaseForHold($hold);

        $requirements = $this->requirementsForItems($items);
        if ($requirements['products'] === [] && $requirements['ingredients'] === []) {
            throw new StockUnavailableException('Pesanan tidak memiliki produk yang valid.');
        }

        $available = $this->availableQuantities($requirements, (int) $hold->id, true);
        $this->assertRequirements($requirements, $available);

        $expiresAt = now()->addMinutes(self::RESERVATION_MINUTES);
        foreach ($requirements['products'] as $requirement) {
            StockReservation::create([
                'hold_id' => $hold->id,
                'product_id' => $requirement['product']->id,
                'quantity' => $requirement['quantity'],
                'status' => 'active',
                'expires_at' => $expiresAt,
            ]);
        }
        foreach ($requirements['ingredients'] as $requirement) {
            StockReservation::create([
                'hold_id' => $hold->id,
                'ingredient_id' => $requirement['ingredient']->id,
                'quantity' => $requirement['quantity'],
                'status' => 'active',
                'expires_at' => $expiresAt,
            ]);
        }
    }

    /**
     * Validate a cart before checkout without creating a reservation.
     */
    public function assertAvailableForItems(iterable $items, ?int $excludeHoldId = null): void
    {
        $requirements = $this->requirementsForItems($items);
        $available = $this->availableQuantities($requirements, $excludeHoldId, true);
        $this->assertRequirements($requirements, $available);
    }

    /**
     * Consume the physical stock for a paid checkout. The caller should
     * invoke this inside the same transaction that creates the transaction.
     */
    public function consumeForCheckout(iterable $items, ?int $excludeHoldId = null): void
    {
        $requirements = $this->requirementsForItems($items);
        if ($requirements['products'] === [] && $requirements['ingredients'] === []) {
            throw new StockUnavailableException('Pesanan tidak memiliki produk yang valid.');
        }

        $available = $this->availableQuantities($requirements, $excludeHoldId, true);
        $this->assertRequirements($requirements, $available);

        foreach ($requirements['products'] as $id => $requirement) {
            Product::whereKey($id)->decrement('stock', $requirement['quantity']);
        }
        foreach ($requirements['ingredients'] as $id => $requirement) {
            Ingredient::whereKey($id)->decrement('stock', $requirement['quantity']);
        }
    }

    /**
     * Restore the same physical resources during a refund. This deliberately
     * uses the transaction details so bundle components and recipes are
     * restored symmetrically with checkout.
     */
    public function restoreForRefund(iterable $items): void
    {
        $requirements = $this->requirementsForItems($items);

        foreach ($requirements['products'] as $id => $requirement) {
            Product::whereKey($id)->increment('stock', $requirement['quantity']);
        }
        foreach ($requirements['ingredients'] as $id => $requirement) {
            Ingredient::whereKey($id)->increment('stock', $requirement['quantity']);
        }
    }

    /**
     * Restore a paid transaction's physical stock exactly once. The atomic
     * claim prevents duplicate refunds from incrementing master stock twice.
     */
    public function restoreForTransaction(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $claimed = Transaction::whereKey($transaction->id)
                ->whereNull('stock_restored_at')
                ->update(['stock_restored_at' => now()]);
            if (! $claimed) {
                return;
            }

            $this->restoreForRefund($transaction->details);
        });
    }

    public function releaseForHold(Hold $hold): void
    {
        StockReservation::where('hold_id', $hold->id)
            ->where('status', 'active')
            ->update([
                'status' => 'released',
                'updated_at' => now(),
            ]);
    }

    public function releaseExpiredReservations(): int
    {
        return StockReservation::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);
    }

    /**
     * Return compact low-stock intelligence for the dashboard.
     *
     * @return array{products: \Illuminate\Support\Collection, ingredients: \Illuminate\Support\Collection}
     */
    public function lowStockSummary(int $productThreshold = 5, int $limit = 10): array
    {
        return [
            'products' => Product::where('stock', '<=', $productThreshold)
                ->orderBy('stock')
                ->limit($limit)
                ->get(['id', 'title', 'stock', 'unit']),
            'ingredients' => Ingredient::whereColumn('stock', '<=', 'min_stock')
                ->orderBy('stock')
                ->limit($limit)
                ->get(['id', 'name', 'stock', 'min_stock', 'unit']),
        ];
    }

    /**
     * @return array{products: array<int, array{product: Product, quantity: float}>, ingredients: array<int, array{ingredient: Ingredient, quantity: float}>}
     */
    private function requirementsForProduct(Product $product, float $quantity, $unitId = null, array $visited = []): array
    {
        $requirements = $this->emptyRequirements();
        if ($quantity <= 0 || isset($visited[$product->id])) {
            return $requirements;
        }
        $visited[$product->id] = true;
        $quantity *= $this->unitConversion($product, $unitId);

        if ($product->type === 'bundle') {
            $product->loadMissing('bundle_items.units');
            foreach ($product->bundle_items as $bundleItem) {
                $pivot = $bundleItem->pivot;
                $componentQuantity = $quantity
                    * (float) ($pivot->qty ?? 1)
                    * $this->unitConversion($bundleItem, $pivot->product_unit_id ?? null);
                $requirements = $this->mergeRequirements(
                    $requirements,
                    $this->requirementsForProduct($bundleItem, $componentQuantity, null, $visited)
                );
            }

            return $requirements;
        }

        $product->loadMissing('recipes.ingredient');
        if ($product->recipes->isNotEmpty()) {
            foreach ($product->recipes as $recipe) {
                if (! $recipe->ingredient || (float) $recipe->qty_needed <= 0) {
                    continue;
                }
                $ingredientId = (int) $recipe->ingredient_id;
                $requirements['ingredients'][$ingredientId] ??= [
                    'ingredient' => $recipe->ingredient,
                    'quantity' => 0.0,
                ];
                $requirements['ingredients'][$ingredientId]['quantity'] += $quantity * (float) $recipe->qty_needed;
            }

            return $requirements;
        }

        $productId = (int) $product->id;
        $requirements['products'][$productId] = [
            'product' => $product,
            'quantity' => $quantity,
        ];

        return $requirements;
    }

    /**
     * @return array{products: array<int, array{product: Product, quantity: float}>, ingredients: array<int, array{ingredient: Ingredient, quantity: float}>}
     */
    private function mergeRequirements(array $left, array $right): array
    {
        foreach ($right['products'] as $id => $requirement) {
            $left['products'][$id] ??= [
                'product' => $requirement['product'],
                'quantity' => 0.0,
            ];
            $left['products'][$id]['quantity'] += $requirement['quantity'];
        }
        foreach ($right['ingredients'] as $id => $requirement) {
            $left['ingredients'][$id] ??= [
                'ingredient' => $requirement['ingredient'],
                'quantity' => 0.0,
            ];
            $left['ingredients'][$id]['quantity'] += $requirement['quantity'];
        }

        return $left;
    }

    private function emptyRequirements(): array
    {
        return ['products' => [], 'ingredients' => []];
    }

    /**
     * @return array{products: array<int, float>, ingredients: array<int, float>}
     */
    private function availableQuantities(array $requirements, ?int $excludeHoldId = null, bool $lock = false): array
    {
        $available = ['products' => [], 'ingredients' => []];

        $productIds = array_keys($requirements['products']);
        sort($productIds, SORT_NUMERIC);
        foreach ($productIds as $id) {
            /** @var Product|null $product */
            $product = $lock
                ? Product::whereKey($id)->lockForUpdate()->first()
                : Product::find($id);
            $reserved = $this->reservedQuantity('product_id', $id, $excludeHoldId)
                + $this->pendingBatchQuantity('product_id', $id);
            $available['products'][$id] = max(0, (float) ($product?->stock ?? 0) - $reserved);
        }

        $ingredientIds = array_keys($requirements['ingredients']);
        sort($ingredientIds, SORT_NUMERIC);
        foreach ($ingredientIds as $id) {
            /** @var Ingredient|null $ingredient */
            $ingredient = $lock
                ? Ingredient::whereKey($id)->lockForUpdate()->first()
                : Ingredient::find($id);
            $reserved = $this->reservedQuantity('ingredient_id', $id, $excludeHoldId)
                + $this->pendingBatchQuantity('ingredient_id', $id);
            $available['ingredients'][$id] = max(0, (float) ($ingredient?->stock ?? 0) - $reserved);
        }

        return $available;
    }

    private function reservedQuantity(string $column, int $resourceId, ?int $excludeHoldId): float
    {
        return (float) StockReservation::where('status', 'active')
            ->where($column, $resourceId)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->when($excludeHoldId, fn ($query) => $query->where('hold_id', '!=', $excludeHoldId))
            ->sum('quantity');
    }

    private function pendingBatchQuantity(string $column, int $resourceId): float
    {
        return (float) StockBatchAllocation::query()
            ->where('movement_type', 'sale')
            ->where('status', 'reserved')
            ->where($column, $resourceId)
            ->where(function ($query) use ($column, $resourceId): void {
                $query->whereNull('transaction_id')
                    ->orWhereHas('transaction', function ($transactionQuery) use ($column, $resourceId): void {
                        $transactionQuery->whereDoesntHave('hold.stockReservations', function ($reservationQuery) use ($column, $resourceId): void {
                            $reservationQuery->where('status', 'active')
                                ->where($column, $resourceId)
                                ->where(function ($expiryQuery): void {
                                    $expiryQuery->whereNull('expires_at')->orWhere('expires_at', '>', now());
                                });
                        });
                    });
            })
            ->sum('quantity');
    }

    /**
     * @param  array{products: array<int, array{product: Product, quantity: float}>, ingredients: array<int, array{ingredient: Ingredient, quantity: float}>}  $requirements
     * @param  array{products: array<int, float>, ingredients: array<int, float>}  $available
     */
    private function maximumSellableQuantity(array $requirements, array $available): float
    {
        $maximum = INF;
        foreach ($requirements['products'] as $id => $requirement) {
            $maximum = min($maximum, $available['products'][$id] / max($requirement['quantity'], 0.000001));
        }
        foreach ($requirements['ingredients'] as $id => $requirement) {
            $maximum = min($maximum, $available['ingredients'][$id] / max($requirement['quantity'], 0.000001));
        }

        return is_infinite($maximum) ? 0 : $maximum;
    }

    /**
     * @param  array{products: array<int, array{product: Product, quantity: float}>, ingredients: array<int, array{ingredient: Ingredient, quantity: float}>}  $requirements
     * @param  array{products: array<int, float>, ingredients: array<int, float>}  $available
     * @return array<int, array{type: string, name: string, required: float, available: float}>
     */
    private function shortages(array $requirements, array $available): array
    {
        $shortages = [];
        foreach ($requirements['products'] as $id => $requirement) {
            if ($available['products'][$id] + 0.000001 < $requirement['quantity']) {
                $shortages[] = [
                    'type' => 'product',
                    'name' => $requirement['product']->title,
                    'required' => $requirement['quantity'],
                    'available' => $available['products'][$id],
                ];
            }
        }
        foreach ($requirements['ingredients'] as $id => $requirement) {
            if ($available['ingredients'][$id] + 0.000001 < $requirement['quantity']) {
                $shortages[] = [
                    'type' => 'ingredient',
                    'name' => $requirement['ingredient']->name,
                    'required' => $requirement['quantity'],
                    'available' => $available['ingredients'][$id],
                ];
            }
        }

        return $shortages;
    }

    /**
     * @param  array{products: array<int, array{product: Product, quantity: float}>, ingredients: array<int, array{ingredient: Ingredient, quantity: float}>}  $requirements
     * @param  array{products: array<int, float>, ingredients: array<int, float>}  $available
     */
    private function assertRequirements(array $requirements, array $available): void
    {
        $shortages = $this->shortages($requirements, $available);
        if ($shortages === []) {
            return;
        }

        $messages = array_map(
            fn (array $shortage): string => sprintf(
                '%s (butuh %s, tersedia %s)',
                $shortage['name'],
                $this->formatQuantity($shortage['required']),
                $this->formatQuantity($shortage['available'])
            ),
            $shortages
        );

        throw new StockUnavailableException('Stok tidak cukup: '.implode(', ', $messages).'.');
    }

    private function unitConversion(Product $product, $unitId): float
    {
        if (! $unitId) {
            return 1.0;
        }

        $unit = ProductUnit::where('product_id', $product->id)
            ->whereKey($unitId)
            ->first();

        if (! $unit || (float) $unit->conversion <= 0) {
            throw new StockUnavailableException('Satuan produk tidak valid.');
        }

        return (float) $unit->conversion;
    }

    private function itemValue(mixed $item, string $key): mixed
    {
        if (is_array($item)) {
            return $item[$key] ?? null;
        }
        if ($item instanceof Model) {
            return $item->getAttribute($key);
        }

        return null;
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, ',', '.'), '0'), ',');
    }
}
