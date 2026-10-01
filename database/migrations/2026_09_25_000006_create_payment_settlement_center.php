<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')
                ->constrained('transactions')
                ->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('provider_reference', 150)->nullable();
            $table->string('status', 30)->default('pending');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('IDR');
            $table->decimal('settled_amount', 15, 2)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('transaction_id');
            $table->index(['provider', 'status']);
            $table->index(['status', 'next_retry_at']);
        });

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')
                ->nullable()
                ->constrained('transactions')
                ->nullOnDelete();
            $table->foreignId('payment_settlement_id')
                ->nullable()
                ->constrained('payment_settlements')
                ->nullOnDelete();
            $table->string('provider', 30);
            $table->string('event_id', 180)->nullable();
            $table->string('provider_reference', 150)->nullable();
            $table->string('status', 40);
            $table->decimal('amount', 15, 2)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->boolean('processed')->default(false);
            $table->text('processing_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'event_id']);
            $table->index(['transaction_id', 'provider']);
            $table->index(['status', 'received_at']);
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->timestamp('paid_at')->nullable()->after('payment_status');
            $table->timestamp('settled_at')->nullable()->after('paid_at');
            $table->timestamp('failed_at')->nullable()->after('settled_at');
            $table->timestamp('refunded_at')->nullable()->after('failed_at');
        });

        Schema::table('payment_settings', function (Blueprint $table): void {
            $table->string('xendit_callback_token')->nullable()->after('xendit_secret_key');
        });
    }

    public function down(): void
    {
        Schema::table('payment_settings', function (Blueprint $table): void {
            $table->dropColumn('xendit_callback_token');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['paid_at', 'settled_at', 'failed_at', 'refunded_at']);
        });

        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_settlements');
    }
};
