<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
    Schema::create('expenses', function (Blueprint $table) {
        $table->id();
        $table->string('name');         // Nama pengeluaran (misal: Listrik Jan)
        $table->string('category');     // Kategori (Operasional, Gaji, Sewa, dll)
        $table->decimal('amount', 15, 2);
        $table->date('date');           // Tanggal transaksi
        $table->string('image')->nullable(); // Foto Nota (Opsional)
        $table->text('note')->nullable();
        $table->timestamps();
    });
    }
};
