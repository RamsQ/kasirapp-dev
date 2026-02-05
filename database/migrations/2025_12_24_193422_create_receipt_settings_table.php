<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_settings', function (Blueprint $table) {
            $table->id();
            $table->string('store_name');
            $table->text('store_address')->nullable();
            $table->string('store_phone')->nullable();
            $table->text('store_footer')->nullable();
            $table->string('store_logo')->nullable(); // Kolom Logo
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_settings');
    }
};