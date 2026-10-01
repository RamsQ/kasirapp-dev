<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mengubah kolom stok dari integer menjadi desimal.
 *
 * MASALAH: stock_batches.qty_in / qty_remaining, stock_movements.qty, dan
 * products.stock dibuat sebagai integer. Padahal StockBatch di-cast ke float
 * dengan komentar "memastikan perhitungan desimal (seperti gram ke kg) tetap
 * presisi". Kombinasi itu membuat setiap konsumsi pecahan terpotong diam-diam:
 *
 *   batch qty_remaining 10, konsumsi 0,12 Kg -> tersimpan 10, tidak berkurang
 *   master stock       20 - 0,12           -> 19,88 (berkurang)
 *
 * Akibatnya ledger batch dan master stok selalu berbeda, inventory:reconcile
 * melaporkan selisih permanen, dan FIFO/LIFO terus memakai batch yang sama
 * karena tidak pernah habis.
 *
 * Untuk restoran yang menjual per berat (0,5 kg nasi, 250 g es teh) dampaknya
 * lebih serius: products.stock juga integer, sehingga stok tidak bisa menyimpan
 * pecahan sama sekali.
 *
 * Presisi 20,6 sengaja disamakan dengan stock_batch_allocations.quantity
 * supaya semua ledger memakai skala yang sama dan tidak ada pembulatan
 * yang tidak disengaja.
 */
return new class extends Migration
{
    private const PRECISION = 20;

    private const SCALE = 6;

    public function up(): void
    {
        $this->changeColumns([
            'stock_batches' => ['qty_in', 'qty_remaining'],
            'stock_movements' => ['qty'],
            'products' => ['stock'],
        ]);
    }

    public function down(): void
    {
        // Sengaja tidak dikembalikan ke integer: kolomnya sudah berisi nilai
        // pecahan dan pembalikannya akan merusak data secara permanen.
        // Untuk rollback yang aman, restore dari backup database.
    }

    /**
     * @param  array<string, list<string>>  $tables
     */
    private function changeColumns(array $tables): void
    {
        foreach ($tables as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->decimal($column, self::PRECISION, self::SCALE)->change();
                });
            }
        }
    }
};
