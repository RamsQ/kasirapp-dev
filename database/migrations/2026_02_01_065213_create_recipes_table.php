<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
    Schema::create('recipes', function (Blueprint $table) {
        $table->id();
        $table->foreignId('product_id')->constrained()->onDelete('cascade');
        $table->foreignId('ingredient_id')->constrained()->onDelete('cascade');
        $table->double('qty_needed'); // Misal: 0.1 untuk 100gram
        $table->timestamps();
    });
    }
};
