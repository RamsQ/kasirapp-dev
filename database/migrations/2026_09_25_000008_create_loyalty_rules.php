<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('min_spend')->default(0);
            $table->unsignedInteger('points')->default(0);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
            $table->index(['min_spend', 'priority']);
        });

        Schema::table('loyalty_point_ledger', function (Blueprint $table): void {
            $table->foreignId('loyalty_rule_id')->nullable()->after('transaction_id')->constrained('loyalty_rules')->nullOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('loyalty_rule_id')->nullable()->after('customer_id')->constrained('loyalty_rules')->nullOnDelete();
            $table->unsignedInteger('loyalty_points_awarded')->default(0)->after('loyalty_rule_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['loyalty_rule_id', 'loyalty_points_awarded']);
        });

        Schema::table('loyalty_point_ledger', function (Blueprint $table): void {
            $table->dropColumn('loyalty_rule_id');
        });

        Schema::dropIfExists('loyalty_rules');
    }
};
