<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            // Mengubah kolom type menjadi string agar lebih fleksibel 
            // ATAU mengupdate ENUM-nya jika Anda ingin tetap pakai ENUM
            $table->string('type')->change(); 
        });
    }

    public function down(): void
    {
        Schema::table('discounts', function (Blueprint $table) {
            // Kembalikan ke enum semula jika diperlukan (opsional)
            // $table->enum('type', ['percentage', 'fixed'])->change();
        });
    }
};