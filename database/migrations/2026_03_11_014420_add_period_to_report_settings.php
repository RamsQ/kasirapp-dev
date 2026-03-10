<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('report_settings', function (Blueprint $table) {
        $table->boolean('is_weekly')->default(false)->after('is_active');
        $table->boolean('is_monthly')->default(false)->after('is_weekly');
    });
}
};