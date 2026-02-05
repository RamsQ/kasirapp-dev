<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    Schema::table('stock_movements', function (Blueprint $table) {
        $table->foreignId('ingredient_id')->nullable()->constrained()->onDelete('cascade')->after('product_id');
    });

    Schema::table('stock_batches', function (Blueprint $table) {
        $table->foreignId('ingredient_id')->nullable()->constrained()->onDelete('cascade')->after('product_id');
    });
}
};
