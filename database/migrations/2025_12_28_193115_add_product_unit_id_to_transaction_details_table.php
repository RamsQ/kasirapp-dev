<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            // Tambahkan kolom product_unit_id setelah kolom price
            $table->foreignId('product_unit_id')->nullable()->after('price')->constrained('product_units')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->dropForeign(['product_unit_id']);
            $table->dropColumn('product_unit_id');
        });
    }
};