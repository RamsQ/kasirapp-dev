<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up() {
    Schema::create('capitals', function (Blueprint $table) {
        $table->id();
        $table->string('source'); // Contoh: "Setoran Bank"
        $table->decimal('amount', 15, 2);
        $table->date('date');
        $table->timestamps();
    });
    }
};
