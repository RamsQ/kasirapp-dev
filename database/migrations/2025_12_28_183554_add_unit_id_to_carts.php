<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
    Schema::table('carts', function (Blueprint $table) {
        $table->foreignId('product_unit_id')->nullable()->constrained('product_units')->onDelete('set null');
    });
    }
};
