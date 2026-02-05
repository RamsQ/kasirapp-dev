<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            // Tambahkan kolom buy_price untuk menyimpan modal saat transaksi terjadi
            $table->double('buy_price')->default(0)->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->dropColumn('buy_price');
        });
    }
};