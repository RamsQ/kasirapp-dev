<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Nama Promo, misal: "Promo Merdeka", "Bundling Hemat"
            $table->text('description')->nullable();
            $table->enum('type', ['percentage', 'fixed']); // Persen atau Rupiah
            $table->decimal('value', 15, 2); // Nilai potongannya
            $table->decimal('min_transaction', 15, 2)->default(0); // Syarat minimal belanja
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};