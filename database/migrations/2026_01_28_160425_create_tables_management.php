<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up()
{
    Schema::create('tables', function (Blueprint $table) {
        $table->id();
        $table->string('name'); // Contoh: Meja 01, Meja 02
        $table->enum('status', ['available', 'occupied'])->default('available');
        $table->integer('position_x')->default(0); // Untuk denah meja (drag-drop sederhana)
        $table->integer('position_y')->default(0);
        $table->timestamps();
    });

    Schema::table('holds', function (Blueprint $table) {
        $table->foreignId('table_id')->nullable()->constrained();
        $table->string('customer_name')->nullable();
        $table->string('queue_number')->nullable();
    });

    Schema::table('transactions', function (Blueprint $table) {
        $table->string('table_name')->nullable();
        $table->string('queue_number')->nullable();
    });
}
};
