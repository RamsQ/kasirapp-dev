<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
    Schema::create('shifts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained(); // Kasir yang bertugas
        $table->integer('starting_cash'); // Modal awal (misal 100rb)
        $table->integer('total_cash_expected')->default(0); // Hitungan sistem
        $table->integer('total_cash_actual')->nullable(); // Input manual kasir saat tutup
        $table->integer('difference')->default(0); // Selisih uang
        $table->enum('status', ['open', 'closed'])->default('open');
        $table->timestamp('opened_at');
        $table->timestamp('closed_at')->nullable();
        $table->timestamps();
    });
    }
};
