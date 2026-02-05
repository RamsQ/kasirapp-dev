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
        Schema::table('expenses', function (Blueprint $table) {
            /**
             * Menambahkan kolom source untuk membedakan sumber dana.
             * 'Kas Laci' = Diambil dari hasil penjualan di laci kasir.
             * 'Modal Luar' = Diambil dari saldo bank atau modal tambahan pemilik.
             */
            $table->string('source')->default('Kas Laci')->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            // Menghapus kolom jika migration di-rollback
            $table->dropColumn('source');
        });
    }
};