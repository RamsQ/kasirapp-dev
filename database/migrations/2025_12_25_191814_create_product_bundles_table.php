<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
    // Tambahkan kolom tipe di tabel products
        Schema::table('products', function (Blueprint $table) {
        // 'single' = Produk Biasa, 'bundle' = Paket
        $table->enum('type', ['single', 'bundle'])->default('single')->after('barcode');
        });

    // Tabel Pivot (Isi Paket)
        Schema::create('product_bundles', function (Blueprint $table) {
        $table->id();
        $table->foreignId('product_id')->constrained('products')->onDelete('cascade'); // ID Paketnya
        $table->foreignId('item_id')->constrained('products')->onDelete('cascade'); // ID Barang isinya
        $table->integer('qty'); // Butuh berapa pcs untuk 1 paket ini
        $table->timestamps();
    });
}

    public function down()
    {
        Schema::dropIfExists('product_bundles');
        Schema::table('products', function (Blueprint $table) {
        $table->dropColumn('type');
        });
    }
};
