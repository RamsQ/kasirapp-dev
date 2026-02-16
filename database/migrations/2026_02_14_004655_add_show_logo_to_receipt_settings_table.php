<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
        public function up()
{
    Schema::table('receipt_settings', function (Blueprint $table) {
        // Menambahkan kolom show_logo dengan default 1 (tampil)
        $table->boolean('show_logo')->default(1)->after('store_logo');
    });
}

public function down()
{
    Schema::table('receipt_settings', function (Blueprint $table) {
        $table->dropColumn('show_logo');
    });
}
};
