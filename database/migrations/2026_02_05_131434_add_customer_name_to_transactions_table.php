<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
    Schema::table('transactions', function (Blueprint $table) {
        // Menambahkan kolom untuk menyimpan "Nama #1234"
        $table->string('customer_name')->nullable()->after('table_name');
    });
    }
};
