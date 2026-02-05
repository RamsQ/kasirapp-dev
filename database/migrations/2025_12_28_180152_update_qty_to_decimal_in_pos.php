<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
    Schema::table('carts', function (Blueprint $table) {
        $table->decimal('qty', 10, 2)->change(); // Mengizinkan 2 angka di belakang koma
        });
    Schema::table('transaction_details', function (Blueprint $table) {
        $table->decimal('qty', 10, 2)->change();
        });
    }
};
