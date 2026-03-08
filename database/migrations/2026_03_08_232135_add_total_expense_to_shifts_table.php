<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // Kita tambahkan kolom total_expense setelah kolom total_discounts
            if (!Schema::hasColumn('shifts', 'total_expense')) {
                $table->decimal('total_expense', 15, 2)->default(0)->after('total_discounts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'total_expense')) {
                $table->dropColumn('total_expense');
            }
        });
    }
};