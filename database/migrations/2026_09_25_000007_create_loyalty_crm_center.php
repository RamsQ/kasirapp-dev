<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedInteger('loyalty_points')->default(0)->after('address');
            $table->unsignedBigInteger('lifetime_spend')->default(0)->after('loyalty_points');
            $table->string('loyalty_tier', 20)->default('bronze')->after('lifetime_spend');
            $table->boolean('loyalty_enabled')->default(true)->after('loyalty_tier');
            $table->timestamp('loyalty_enrolled_at')->nullable()->after('loyalty_enabled');
            $table->date('birthday')->nullable()->after('loyalty_enrolled_at');
            $table->boolean('marketing_opt_in')->default(false)->after('birthday');
            $table->timestamp('last_visit_at')->nullable()->after('marketing_opt_in');
            $table->text('notes')->nullable()->after('last_visit_at');
        });

        Schema::table('settings', function (Blueprint $table): void {
            $table->boolean('loyalty_enabled')->default(true)->after('tax_rate');
            $table->unsignedInteger('loyalty_points_per_100')->default(1)->after('loyalty_enabled');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('voucher_code', 50)->nullable()->after('discount');
            $table->unsignedBigInteger('voucher_discount')->default(0)->after('voucher_code');
        });

        Schema::create('loyalty_point_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('type', 20);
            $table->integer('points');
            $table->unsignedInteger('balance_after');
            $table->unsignedBigInteger('amount_base')->default(0);
            $table->string('reference')->unique();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['transaction_id', 'type']);
        });

        Schema::create('customer_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('type', 20)->default('fixed');
            $table->decimal('value', 15, 2);
            $table->decimal('min_spend', 15, 2)->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('reserved_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->foreignId('used_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_vouchers');
        Schema::dropIfExists('loyalty_point_ledger');

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['voucher_code', 'voucher_discount']);
        });

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn(['loyalty_enabled', 'loyalty_points_per_100']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'loyalty_points',
                'lifetime_spend',
                'loyalty_tier',
                'loyalty_enabled',
                'loyalty_enrolled_at',
                'birthday',
                'marketing_opt_in',
                'last_visit_at',
                'notes',
            ]);
        });
    }
};
