<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
    Schema::create('holds', function (Blueprint $table) {
        $table->id();
        $table->string('ref_number'); // Nama Pelanggan/Meja
        $table->json('cart_data');    // Isi keranjang (disimpan sebagai JSON)
        $table->decimal('total', 15, 2);
        $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Kasir yang handle
        $table->timestamps();
    });
    }
};
