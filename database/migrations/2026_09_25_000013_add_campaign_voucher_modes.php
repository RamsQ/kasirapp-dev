<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kode promo bersama (shop-wide), tidak terikat satu pelanggan.
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('type', 20)->default('fixed'); // fixed | percentage
            $table->decimal('value', 15, 2);
            $table->decimal('min_spend', 15, 2)->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('reserved_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('crm_campaign_id')->nullable()->constrained('crm_campaigns')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
        });

        Schema::table('crm_campaigns', function (Blueprint $table): void {
            // Kolom lama menunjuk voucher pribadi satu pelanggan. Kalau dipakai
            // untuk campaign, kode tersebut bocor ke seluruh penerima segment
            // dan hanya bisa dipakai oleh pemiliknya. Diganti definisi voucher.
            $table->dropForeign(['voucher_id']);
            $table->dropColumn('voucher_id');

            $table->string('voucher_mode', 20)->default('none'); // none | per_recipient | shared
            $table->string('voucher_name')->nullable();
            $table->string('voucher_type', 20)->nullable(); // fixed | percentage
            $table->decimal('voucher_value', 15, 2)->nullable();
            $table->decimal('voucher_min_spend', 15, 2)->default(0);
            $table->timestamp('voucher_expires_at')->nullable();
            $table->string('voucher_prefix', 20)->nullable(); // prefiks kode unik per penerima
            $table->string('shared_voucher_code', 50)->nullable(); // kode promo bersama
        });

        // Voucher pribadi yang diterbitkan campaign, untuk idempotensi retry.
        Schema::table('customer_vouchers', function (Blueprint $table): void {
            $table->foreignId('source_campaign_id')->nullable()->after('customer_id')
                ->constrained('crm_campaigns')->nullOnDelete();
            $table->index(['source_campaign_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_vouchers', function (Blueprint $table): void {
            $table->dropForeign(['source_campaign_id']);
            $table->dropColumn('source_campaign_id');
        });

        Schema::table('crm_campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'voucher_mode', 'voucher_name', 'voucher_type', 'voucher_value',
                'voucher_min_spend', 'voucher_expires_at', 'voucher_prefix', 'shared_voucher_code',
            ]);
            $table->foreignId('voucher_id')->nullable()->constrained('customer_vouchers')->nullOnDelete();
        });

        Schema::dropIfExists('promo_codes');
    }
};
