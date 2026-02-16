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
        Schema::table('shifts', function (Blueprint $table) {
            // Kita tambahkan keduanya sekaligus
            // Gunakan after('total_cash_actual') atau hapus ->after(...) jika ragu
            $table->bigInteger('total_qris_sales')->default(0)->after('total_cash_actual');
            $table->bigInteger('total_discounts')->default(0)->after('total_qris_sales');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['total_qris_sales', 'total_discounts']);
        });
    }
};