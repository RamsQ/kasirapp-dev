<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $blueprint) {
            // Menambahkan kolom bonus_product_id setelah product_id
            $blueprint->foreignId('bonus_product_id')->nullable()->after('product_id')->constrained('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $blueprint) {
            $blueprint->dropForeign(['bonus_product_id']);
            $blueprint->dropColumn('bonus_product_id');
        });
    }
};