<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
    Schema::create('product_units', function (Blueprint $table) {
        $table->id();
        $table->foreignId('product_id')->constrained()->onDelete('cascade');
        $table->string('unit_name');     // Contoh: Kg, Box, Pack
        $table->decimal('conversion', 10, 2); // Pengali ke stok dasar (Contoh: Kg konversi 10 artinya 1kg = 10 pcs)
        $table->bigInteger('sell_price'); // Harga khusus satuan ini
        $table->timestamps();
    });
    }
};
