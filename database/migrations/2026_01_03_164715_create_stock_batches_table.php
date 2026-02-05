<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cek jika tabel BELUM ada, baru buat
        if (!Schema::hasTable('stock_batches')) {
            Schema::create('stock_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->onDelete('cascade');
                $table->integer('qty_in');
                $table->integer('qty_remaining');
                $table->decimal('buy_price', 15, 2);
                $table->string('serial_number')->nullable()->index();
                $table->timestamps();
            });
        }

        // Cek tabel settings untuk menambah kolom cogs_method
        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table) {
                if (!Schema::hasColumn('settings', 'cogs_method')) {
                    $table->string('cogs_method')->default('AVERAGE')->after('id');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
        
        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table) {
                if (Schema::hasColumn('settings', 'cogs_method')) {
                    $table->dropColumn('cogs_method');
                }
            });
        }
    }
};