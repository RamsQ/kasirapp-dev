<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
    Schema::table('product_bundles', function (Blueprint $table) {
        $table->foreignId('product_unit_id')->nullable()->after('qty')->constrained('product_units')->onDelete('cascade');
    });
    }
};
