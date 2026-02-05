<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
    Schema::create('stock_opnames', function (Blueprint $table) {
        $table->id();
        $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained(); // Siapa yang melakukan opname
        $table->integer('stock_system'); // Stok di komputer sebelum opname
        $table->integer('stock_actual'); // Stok asli yang dihitung manual
        $table->integer('difference');   // Selisih (actual - system)
        $table->string('reason')->nullable(); // Alasan (misal: barang rusak, hilang)
        $table->timestamps();
    });
    }
};
