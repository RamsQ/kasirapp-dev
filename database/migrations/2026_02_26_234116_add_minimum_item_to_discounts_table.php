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
        Schema::table('discounts', function (Blueprint $table) {
            // Menambahkan kolom syarat jumlah barang (default 1 agar diskon langsung aktif)
            $table->integer('minimum_item')->default(1)->after('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            // Menghapus kolom jika migration di-rollback
            $table->dropColumn('minimum_item');
        });
    }
};