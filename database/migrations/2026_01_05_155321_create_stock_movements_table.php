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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            // Menghubungkan ke tabel produk
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            
            // Tipe pergerakan: 'in' (Stock In/Pembelian) atau 'out' (Penjualan/Sales)
            $table->enum('type', ['in', 'out']);
            
            // Jumlah barang yang masuk atau keluar
            $table->integer('qty');
            
            // Harga modal per unit saat barang masuk (Sangat penting untuk FIFO/Average)
            $table->bigInteger('price');
            
            // Referensi transaksi (Contoh: Nomor Invoice atau ID Purchase)
            $table->string('reference')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};