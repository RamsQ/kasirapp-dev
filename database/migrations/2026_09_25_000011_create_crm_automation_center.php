<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('phone');
            $table->timestamp('crm_opt_out_at')->nullable()->after('marketing_opt_in');
            $table->string('crm_opt_out_reason')->nullable()->after('crm_opt_out_at');
        });

        Schema::create('customer_segments', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('rules');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('is_active');
        });

        Schema::create('crm_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('segment_id')->nullable()->constrained('customer_segments')->nullOnDelete();
            $table->string('type', 30)->default('manual');
            $table->string('channel', 20)->default('copy');
            $table->string('subject')->nullable();
            $table->text('message');
            $table->foreignId('voucher_id')->nullable()->constrained('customer_vouchers')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('is_dry_run')->default(true);
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('crm_campaign_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('crm_campaigns')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('target')->nullable();
            $table->text('message');
            $table->string('status', 20)->default('queued');
            $table->string('provider_reference')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'customer_id']);
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_campaign_deliveries');
        Schema::dropIfExists('crm_campaigns');
        Schema::dropIfExists('customer_segments');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['email', 'crm_opt_out_at', 'crm_opt_out_reason']);
        });
    }
};
