<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
    Schema::table('transaction_details', function (Blueprint $table) {
        // Menambahkan kolom unit setelah kolom qty
        $table->string('unit')->nullable()->after('qty');
    });
    }

    public function down()
    {
    Schema::table('transaction_details', function (Blueprint $table) {
        $table->dropColumn('unit');
    });
    }
};
