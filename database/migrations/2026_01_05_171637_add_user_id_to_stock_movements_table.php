<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $blueprint) {
            // Menambahkan kolom user_id setelah product_id
            $blueprint->foreignId('user_id')->after('product_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $blueprint) {
            $blueprint->dropForeign(['user_id']);
            $blueprint->dropColumn('user_id');
        });
    }
};