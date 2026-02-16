<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
{
    // Kita hapus tabel lama dan buat ulang dengan struktur yang benar
    Schema::dropIfExists('online_settings');
    Schema::create('online_settings', function (Blueprint $table) {
        $table->id();
        $table->string('name'); // Nama Platform (GoFood, GrabFood, dll)
        $table->integer('markup_percent')->default(0);
        $table->integer('additional_fee')->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
}
};
