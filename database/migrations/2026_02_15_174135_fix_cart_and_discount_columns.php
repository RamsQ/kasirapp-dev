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
        // Perbaikan Tabel Carts
        Schema::table('carts', function (Blueprint $table) {
            // 1. Ubah tipe data price ke decimal agar akurat (Wajib)
            $table->decimal('price', 15, 2)->change();

            // 2. Tambah product_unit_id HANYA jika belum ada
            if (!Schema::hasColumn('carts', 'product_unit_id')) {
                $table->unsignedBigInteger('product_unit_id')->nullable()->after('product_id');
            }
        });

        // Perbaikan Tabel Discounts
        Schema::table('discounts', function (Blueprint $table) {
            // Tambah product_id jika belum ada
            if (!Schema::hasColumn('discounts', 'product_id')) {
                $table->unsignedBigInteger('product_id')->nullable()->after('id');
            }
            
            // Tambah bonus_product_id jika belum ada
            if (!Schema::hasColumn('discounts', 'bonus_product_id')) {
                $table->unsignedBigInteger('bonus_product_id')->nullable()->after('product_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Opsional: kembalikan ke bigInteger jika rollback
        Schema::table('carts', function (Blueprint $table) {
            $table->bigInteger('price')->change();
        });
    }
};