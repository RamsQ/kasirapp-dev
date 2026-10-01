<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\Product;
use App\Models\StockBatch;
use App\Services\InventoryStockService;
use Illuminate\Console\Command;

class ReconcileInventoryBatches extends Command
{
    protected $signature = 'inventory:reconcile {--repair} {--user= : ID staff untuk repair batch}';

    protected $description = 'Bandingkan stok master dengan total batch dan opsional selaraskan layer batch';

    public function handle(InventoryStockService $inventory): int
    {
        $repair = (bool) $this->option('repair');
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        if ($repair && ! $userId) {
            $this->error('Opsi --repair membutuhkan --user.');

            return self::INVALID;
        }

        $mismatches = 0;
        $repaired = 0;
        foreach ([
            ['model' => Product::class, 'type' => 'product', 'field' => 'product_id', 'label' => 'title'],
            ['model' => Ingredient::class, 'type' => 'ingredient', 'field' => 'ingredient_id', 'label' => 'name'],
        ] as $resource) {
            /** @var class-string $modelClass */
            $modelClass = $resource['model'];
            foreach ($modelClass::query()->get(['id', 'stock', 'buy_price', $resource['label']]) as $master) {
                $masterStock = (float) $master->stock;
                $batchStock = (float) StockBatch::where($resource['field'], $master->id)->sum('qty_remaining');
                $difference = $masterStock - $batchStock;
                if (abs($difference) < 0.000001) {
                    continue;
                }

                $mismatches++;
                $this->line(sprintf(
                    '%s #%d (%s): master %.3f, batch %.3f, selisih %.3f',
                    $resource['type'],
                    $master->id,
                    $master->{$resource['label']},
                    $masterStock,
                    $batchStock,
                    $difference,
                ));

                if ($repair) {
                    $inventory->setActualQuantity(
                        $resource['type'],
                        (int) $master->id,
                        $masterStock,
                        $userId,
                        'Rekonsiliasi batch',
                        (float) $master->buy_price,
                    );
                    $repaired++;
                }
            }
        }

        $this->info("Mismatch ditemukan: {$mismatches}. Batch diperbaiki: {$repaired}.");

        return self::SUCCESS;
    }
}
