<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->boolean('tax_enabled')->default(false)->after('cogs_method');
            $table->decimal('tax_rate', 5, 2)->nullable()->after('tax_enabled');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('taxable_amount')->nullable()->after('discount');
            $table->decimal('tax_rate', 5, 2)->nullable()->after('taxable_amount');
            $table->unsignedBigInteger('tax_amount')->default(0)->after('tax_rate');
        });

        Schema::table('holds', function (Blueprint $table): void {
            $table->unsignedBigInteger('taxable_amount')->nullable()->after('total');
            $table->decimal('tax_rate', 5, 2)->nullable()->after('taxable_amount');
            $table->unsignedBigInteger('tax_amount')->default(0)->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('holds', function (Blueprint $table): void {
            $table->dropColumn(['taxable_amount', 'tax_rate', 'tax_amount']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['taxable_amount', 'tax_rate', 'tax_amount']);
        });

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn(['tax_enabled', 'tax_rate']);
        });
    }
};
