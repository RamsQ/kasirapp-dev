<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            // UBAH JADI NULLABLE
            $table->string('image')->nullable();
            $table->string('barcode')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            
            $table->bigInteger('buy_price');
            $table->bigInteger('sell_price');
            $table->integer('stock');
            // Pastikan kolom expired_date ada jika Anda pakai fitur expired
            $table->date('expired_date')->nullable(); 
            $table->timestamps();

            //relationship categories
            $table->foreign('category_id')->references('id')->on('categories');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('products');
    }
};