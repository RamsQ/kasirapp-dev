<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
{
    Schema::create('ingredients', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->foreignId('unit_id')->constrained()->onDelete('cascade');
        $table->double('stock')->default(0);
        $table->double('min_stock')->default(10);
        $table->timestamps();
    });
}

public function down()
{
    Schema::dropIfExists('ingredients');
}
};
