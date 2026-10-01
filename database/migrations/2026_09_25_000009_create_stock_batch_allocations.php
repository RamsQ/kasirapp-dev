<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Product and ingredient movements share the same tables. Older
        // schemas kept product_id required, which made ingredient stock-in
        // impossible even though ingredient_id was added later.
        Schema::table('stock_batches', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable()->change();
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable()->change();
        });

        Schema::create('stock_batch_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained('ingredients')->nullOnDelete();
            $table->string('resource_type', 20);
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_cost', 20, 2);
            $table->decimal('total_cost', 20, 2);
            $table->string('method', 20);
            $table->string('movement_type', 20)->default('sale');
            $table->string('status', 20)->default('consumed');
            $table->unsignedBigInteger('original_allocation_id')->nullable();
            $table->string('reference')->unique();
            $table->timestamps();

            $table->index(['transaction_id', 'status']);
            $table->index(['stock_batch_id', 'status']);
            $table->index(['product_id', 'ingredient_id', 'status']);
            $table->index('original_allocation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batch_allocations');

        Schema::table('stock_batches', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });
    }
};
