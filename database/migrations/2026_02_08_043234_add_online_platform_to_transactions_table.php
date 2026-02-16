<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
{
    Schema::table('transactions', function (Blueprint $table) {
        $table->string('online_platform')->nullable()->after('payment_method');
        $table->bigInteger('total_markup')->default(0)->after('online_platform');
        $table->bigInteger('total_fee')->default(0)->after('total_markup');
    });
}

public function down()
{
    Schema::table('transactions', function (Blueprint $table) {
        $table->dropColumn(['online_platform', 'total_markup', 'total_fee']);
    });
}
};
