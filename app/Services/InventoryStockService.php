<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class InventoryStockService
{
    public function recordStockIn(
        string $resourceType,
        int $resourceId,
        float $quantity,
        float $unitCost,
        int $userId,
    ): StockBatch {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Jumlah stock in harus lebih besar dari nol.');
        }

        return DB::transaction(function () use ($resourceType, $resourceId, $quantity, $unitCost, $userId): StockBatch {
            $master = $this->lockMaster($resourceType, $resourceId);
            $oldStock = (float) $master->stock;
            $oldCost = (float) $master->buy_price;
            $newStock = $oldStock + $quantity;
            $newCost = $newStock > 0
                ? (($oldStock * $oldCost) + ($quantity * $unitCost)) / $newStock
                : $unitCost;
            $reference = 'IN-'.Str::upper(Str::random(12));

            $master->update([
                'stock' => $newStock,
                'buy_price' => $newCost,
            ]);

            // Harga bahan berubah, jadi perkiraan HPP menu resep ikut berubah.
            if ($resourceType === 'ingredient') {
                $this->refreshRecipeCostPricesForIngredient($resourceId);
            }

            $this->createMovement(
                $resourceType,
                $resourceId,
                $userId,
                'in',
                $quantity,
                $unitCost,
                $reference,
            );

            return StockBatch::create([
                $this->resourceColumn($resourceType) => $resourceId,
                'qty_in' => $quantity,
                'qty_remaining' => $quantity,
                'buy_price' => $unitCost,
                'serial_number' => $reference,
            ]);
        });
    }

    /**
     * Keep the recipe estimate (`products.cost_price`) in step with ingredient
     * prices.
     *
     * The authoritative HPP is whatever CogsService allocates from the batch
     * layers, so this value is presentation only (the product list and the
     * recipe screen show it as "HPP (Resep)"). Leaving it frozen would make the
     * screen disagree with the profit report, so any ingredient price change
     * has to recalculate every menu that uses it.
     */
    private function refreshRecipeCostPricesForIngredient(int $ingredientId): void
    {
        $menus = Product::whereHas('recipes', fn ($query) => $query->where('ingredient_id', $ingredientId))
            ->with('recipes.ingredient')
            ->get();

        foreach ($menus as $menu) {
            // Seluruh bahan dihitung ulang, bukan hanya bahan yang baru saja
            // berubah, karena yang disimpan adalah total cost menu.
            $cost = 0.0;
            foreach ($menu->recipes as $recipe) {
                if ((float) $recipe->qty_needed <= 0) {
                    continue;
                }
                $cost += (float) $recipe->qty_needed * (float) ($recipe->ingredient->buy_price ?? 0);
            }

            $menu->update(['cost_price' => $cost]);
        }
    }

    /**
     * Align master stock and physical cost layers after an opname or manual
     * stock edit. Positive differences create a new layer; losses consume the
     * oldest layers first.
     *
     * @return array{previous:float,actual:float,difference:float}
     */
    public function setActualQuantity(
        string $resourceType,
        int $resourceId,
        float $actualQuantity,
        int $userId,
        string $reason = 'Penyesuaian stok',
        ?float $unitCost = null,
    ): array {
        if ($actualQuantity < 0) {
            throw new InvalidArgumentException('Stok aktual tidak boleh negatif.');
        }

        return DB::transaction(function () use ($resourceType, $resourceId, $actualQuantity, $userId, $unitCost): array {
            $master = $this->lockMaster($resourceType, $resourceId);
            $previous = (float) $master->stock;
            $difference = $actualQuantity - $previous;
            if (abs($difference) < 0.000001) {
                return ['previous' => $previous, 'actual' => $previous, 'difference' => 0.0];
            }

            $reference = 'ADJ-'.Str::upper(Str::random(12));
            $movementPrice = $unitCost ?? (float) $master->buy_price;

            if ($difference > 0) {
                $this->createMovement(
                    $resourceType,
                    $resourceId,
                    $userId,
                    'in',
                    $difference,
                    $movementPrice,
                    $reference,
                );
                StockBatch::create([
                    $this->resourceColumn($resourceType) => $resourceId,
                    'qty_in' => $difference,
                    'qty_remaining' => $difference,
                    'buy_price' => $movementPrice,
                    'serial_number' => $reference,
                ]);
            } else {
                $loss = abs($difference);
                $this->createMovement(
                    $resourceType,
                    $resourceId,
                    $userId,
                    'out',
                    $loss,
                    $movementPrice,
                    $reference,
                );
                $this->consumeOldestBatches($resourceType, $resourceId, $loss);
            }

            $master->update(['stock' => $actualQuantity]);

            return [
                'previous' => $previous,
                'actual' => $actualQuantity,
                'difference' => $difference,
            ];
        });
    }

    private function lockMaster(string $resourceType, int $resourceId): Model
    {
        $query = $resourceType === 'ingredient'
            ? Ingredient::whereKey($resourceId)
            : Product::whereKey($resourceId);

        return $query->lockForUpdate()->firstOrFail();
    }

    private function consumeOldestBatches(string $resourceType, int $resourceId, float $quantity): void
    {
        $remaining = $quantity;
        $batches = StockBatch::where($this->resourceColumn($resourceType), $resourceId)
            ->where('qty_remaining', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min((float) $batch->qty_remaining, $remaining);
            $batch->update([
                'qty_remaining' => (float) $batch->qty_remaining - $take,
            ]);
            $remaining -= $take;
        }
    }

    private function createMovement(
        string $resourceType,
        int $resourceId,
        int $userId,
        string $type,
        float $quantity,
        float $price,
        string $reference,
    ): StockMovement {
        return StockMovement::create([
            $this->resourceColumn($resourceType) => $resourceId,
            'user_id' => $userId,
            'type' => $type,
            'qty' => $quantity,
            'price' => $price,
            'reference' => $reference,
        ]);
    }

    private function resourceColumn(string $resourceType): string
    {
        return match ($resourceType) {
            'product' => 'product_id',
            'ingredient' => 'ingredient_id',
            default => throw new InvalidArgumentException('Tipe resource inventory tidak valid.'),
        };
    }
}
