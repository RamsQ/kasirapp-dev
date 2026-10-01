<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyimpan satuan pada setiap baris resep.
 *
 * MASALAH: recipes.qty_needed tidak punya satuan, sedangkan HPP dihitung
 * sebagai qty_needed x ingredient.buy_price, dan buy_price adalah harga per
 * SATUAN bahan. Kalau kasir menulis 1 (maksudnya 1 gram) untuk bahan yang
 * dihitung per kilogram, HPP meleset 1.000 kali tanpa ada yang mendeteksi.
 *
 * Kolom ini tidak mencegah salah input, tetapi membuat satuan yang dipakai
 * menjadi eksplisit dan bisa ditinjau. Baris lama dibiarkan null supaya
 * tidak mengarang asal-usul data yang sudah tercatat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recipes')) {
            return;
        }

        if (! Schema::hasColumn('recipes', 'unit')) {
            Schema::table('recipes', function (Blueprint $table): void {
                $table->string('unit', 50)->nullable()->after('qty_needed');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('recipes') && Schema::hasColumn('recipes', 'unit')) {
            Schema::table('recipes', function (Blueprint $table): void {
                $table->dropColumn('unit');
            });
        }
    }
};
