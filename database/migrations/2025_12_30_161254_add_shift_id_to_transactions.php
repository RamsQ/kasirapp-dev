<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Kita gunakan nullable() agar transaksi lama (sebelum ada fitur shift) tidak error
            $table->foreignId('shift_id')->nullable()->constrained()->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Untuk menghapus relasi dan kolom jika migration di-rollback
            $table->dropForeign(['shift_id']);
            $table->dropColumn('shift_id');
        });
    }
};