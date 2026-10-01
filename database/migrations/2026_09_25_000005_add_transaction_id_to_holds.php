<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holds', function (Blueprint $table): void {
            $table->foreignId('transaction_id')
                ->nullable()
                ->after('queue_number')
                ->constrained('transactions')
                ->nullOnDelete();
            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('holds', function (Blueprint $table): void {
            $table->dropForeign(['transaction_id']);
            $table->dropIndex(['transaction_id']);
            $table->dropColumn('transaction_id');
        });
    }
};
