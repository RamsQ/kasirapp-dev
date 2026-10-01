<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            if (! Schema::hasColumn('carts', 'online_platform_id')) {
                $table->unsignedBigInteger('online_platform_id')->nullable()->after('product_unit_id');
            }
            if (! Schema::hasColumn('carts', 'platform_markup')) {
                $table->decimal('platform_markup', 15, 2)->default(0)->after('price');
            }
            if (! Schema::hasColumn('carts', 'platform_fee')) {
                $table->decimal('platform_fee', 15, 2)->default(0)->after('platform_markup');
            }
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('carts', 'online_platform_id') ? 'online_platform_id' : null,
                Schema::hasColumn('carts', 'platform_markup') ? 'platform_markup' : null,
                Schema::hasColumn('carts', 'platform_fee') ? 'platform_fee' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
