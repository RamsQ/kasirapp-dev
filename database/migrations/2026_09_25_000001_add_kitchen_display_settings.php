<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->boolean('kitchen_display_enabled')->default(false)->after('tax_rate');
            $table->string('kitchen_display_payment_status', 20)->default('both')->after('kitchen_display_enabled');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('kitchen_status', 20)->default('pending')->after('status');
        });

        Schema::table('holds', function (Blueprint $table): void {
            $table->string('kitchen_status', 20)->default('pending')->after('total');
        });

        // Order lama tidak boleh memenuhi layar KDS ketika fitur ini pertama diaktifkan.
        DB::table('transactions')->update(['kitchen_status' => 'completed']);
        DB::table('holds')->update(['kitchen_status' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('holds', function (Blueprint $table): void {
            $table->dropColumn('kitchen_status');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('kitchen_status');
        });

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn(['kitchen_display_enabled', 'kitchen_display_payment_status']);
        });
    }
};
