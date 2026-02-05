<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('stock_opnames', function (Blueprint $table) {
        // Mengubah product_id menjadi nullable
        $table->foreignId('product_id')->nullable()->change();
        
        // Memastikan kolom ingredient_id sudah ada, jika belum tambahkan
        if (!Schema::hasColumn('stock_opnames', 'ingredient_id')) {
            $table->foreignId('ingredient_id')->nullable()->constrained()->onDelete('cascade');
        }
    });
}

public function down(): void
{
    Schema::table('stock_opnames', function (Blueprint $table) {
        $table->foreignId('product_id')->nullable(false)->change();
        $table->dropColumn('ingredient_id');
    });
}
};
