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
        Schema::table('payment_settings', function (Blueprint $table) {
        // Menambahkan status aktif dan nama file gambar
        $table->boolean('qris_manual_enabled')->default(false)->after('xendit_production');
        $table->string('qris_manual_image')->nullable()->after('qris_manual_enabled');
    });
    }

};
