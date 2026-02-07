<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
    Schema::table('transaction_details', function (Blueprint $table) {
        // Tambahkan kolom notes setelah product_id
        $table->string('notes')->nullable()->after('product_id');
    });
    }

public function down()
    {
    Schema::table('transaction_details', function (Blueprint $table) {
        $table->dropColumn('notes');
    });
    }
};
