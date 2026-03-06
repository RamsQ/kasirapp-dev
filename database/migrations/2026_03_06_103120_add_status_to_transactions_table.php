<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Kita tambahkan kolom status setelah kolom payment_status
            // Default-nya 'paid' agar transaksi lama tidak dianggap refund
            $table->string('status')->default('paid')->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};