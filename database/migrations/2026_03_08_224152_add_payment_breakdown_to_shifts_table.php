<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // Kita cek satu per satu agar tidak terjadi error duplicate lagi
            if (!Schema::hasColumn('shifts', 'total_cash_sales')) {
                $table->decimal('total_cash_sales', 15, 2)->default(0)->after('starting_cash');
            }
            
            if (!Schema::hasColumn('shifts', 'total_midtrans_sales')) {
                $table->decimal('total_midtrans_sales', 15, 2)->default(0)->after('total_cash_sales');
            }
            
            // total_qris_sales dilewati karena error bilang sudah ada
            
            if (!Schema::hasColumn('shifts', 'total_transfer_sales')) {
                $table->decimal('total_transfer_sales', 15, 2)->default(0)->after('total_qris_sales');
            }
            
            if (!Schema::hasColumn('shifts', 'total_discounts')) {
                $table->decimal('total_discounts', 15, 2)->default(0)->after('total_transfer_sales');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'total_cash_sales', 
                'total_midtrans_sales', 
                'total_transfer_sales',
                'total_discounts'
            ]);
        });
    }
};