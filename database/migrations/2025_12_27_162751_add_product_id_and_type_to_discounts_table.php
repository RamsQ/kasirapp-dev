<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
{
    Schema::table('discounts', function (Blueprint $table) {
        // Cek jika kolom product_id belum ada, baru buat
        if (!Schema::hasColumn('discounts', 'product_id')) {
            $table->foreignId('product_id')->nullable()->after('id')->constrained()->onDelete('cascade');
        }
        
        // Cek jika kolom type belum ada, baru buat
        if (!Schema::hasColumn('discounts', 'type')) {
            $table->enum('type', ['fixed', 'percentage'])->default('fixed')->after('value');
        }
    });
}
};
