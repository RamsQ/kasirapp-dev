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
    Schema::table('ingredients', function (Blueprint $table) {
        // Cek jika unit_id masih ada, maka hapus
        if (Schema::hasColumn('ingredients', 'unit_id')) {
            // Jika ada foreign key, hapus dulu constraint-nya
            // Nama constraint biasanya: ingredients_unit_id_foreign
            $table->dropForeign(['unit_id']); 
            $table->dropColumn('unit_id');
        }

        // Cek jika kolom 'unit' belum ada, maka tambah sebagai string
        if (!Schema::hasColumn('ingredients', 'unit')) {
            $table->string('unit')->nullable()->after('name');
        }
    });
}


};
