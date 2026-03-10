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
    Schema::create('report_settings', function (Blueprint $table) {
        $table->id();
        $table->boolean('is_active')->default(false);
        $table->string('method')->default('whatsapp'); // whatsapp atau email
        $table->string('target')->nullable(); // Nomor WA atau Email Tujuan
        $table->time('send_at')->default('21:00'); // Jam pengiriman
        $table->string('wa_api_key')->nullable(); // Token Fonnte/Wablas
        $table->timestamps();
    });
    }
};
