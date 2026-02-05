<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
    Schema::table('products', function (Blueprint $table) {
        // Kita beri default 'Pcs' agar data produk lama otomatis memiliki satuan
        $table->string('unit', 20)->default('Pcs')->after('stock');
    });
    }

    public function down(): void
    {
    Schema::table('products', function (Blueprint $table) {
        $table->dropColumn('unit');
    });
    }
};
