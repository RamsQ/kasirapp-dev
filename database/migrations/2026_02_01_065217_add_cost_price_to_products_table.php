<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
{
    // Cek jika kolom BELUM ada, baru buat
    if (!Schema::hasColumn('products', 'cost_price')) {
        Schema::table('products', function (Blueprint $table) {
            $table->double('cost_price')->default(0)->after('buy_price');
        });
    }
}

public function down()
{
    if (Schema::hasColumn('products', 'cost_price')) {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });
    }
}
};
