<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mengizinkan voucher pribadi dipakai lebih dari sekali, sampai batas tertentu.
 *
 * KEADAAN SEBELUMNYA
 *
 * Voucher pribadi selalu sekali pakai, dan itu terjadi karena `status`, bukan
 * karena ada penghitungnya. `consumeVoucher()` men-set `status = 'used'`, dan
 * `voucherIsUsable()` menolak status di luar `active` dan `reserved`. Tidak
 * ada kolom yang mencatat pemakaian ke-2, ke-3, dan seterusnya.
 *
 * BENTUK BARU
 *
 * `usage_limit` dan `used_count` jadi sumber kebenaran sebenarnya. Status
 * sekarang hanya akibat dari kuota: `used` baru muncul kalau kuota habis.
 * Dengan begitu satu voucher bisa dipakai berkali-kali tanpa harus menerbitkan
 * kode baru tiap transaksi.
 *
 * MENGAPA `usage_limit` BUKAN NULL
 *
 * Kolomnya NOT NULL dengan bawaan 1 supaya voucher yang sudah ada dan voucher
 * yang diterbitkan manual oleh LoyaltyService ikut ikut aturan yang sama:
 * sekali pakai. Kalau dibiarkan NULL, ada dua arti "tanpa batas" yang berbeda
 * antara kode lama dan kode baru.
 *
 * MENGAPA ADA BACKFILL
 *
 * Voucher yang sudah pernah terpakai punya `status = 'used'`. Kalau `used_count`
 * dibiarkan 0, kuota 1-nya akan terlihat belum habis dan voucher itu bisa
 * dipakai lagi -- yang merehabilitasi kode yang seharusnya. Migration ini menutup
 * celah itu dengan menghitung voucher yang benar-benar sudah terpakai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_vouchers', function (Blueprint $table): void {
            $table->unsignedInteger('usage_limit')
                ->default(1)
                ->after('status')
                ->comment('Berapa kali voucher boleh dipakai. 1 = sekali pakai.');

            $table->unsignedInteger('used_count')
                ->default(0)
                ->after('usage_limit')
                ->comment('Sudah dipakai berapa kali.');
        });

        // Voucher yang statusnya sudah `used` pasti pernah dipakai satu kali.
        // Tanpa baris ini, kuota 1-nya terlihat belum habis.
        DB::table('customer_vouchers')
            ->where('status', 'used')
            ->update(['used_count' => 1]);

        DB::table('customer_vouchers')
            ->whereNotNull('used_transaction_id')
            ->where('used_count', 0)
            ->update(['used_count' => 1]);

        // Kolom di campaign supaya operator bisa memilih batas sejak awal.
        Schema::table('crm_campaigns', function (Blueprint $table): void {
            $table->unsignedInteger('voucher_usage_limit')
                ->default(1)
                ->after('voucher_value')
                ->comment('Berapa kali kode unik per penerima boleh dipakai. 1 = sekali pakai.');
        });

        // Campaign lama memakai perilaku lama: sekali pakai.
        DB::table('crm_campaigns')->update(['voucher_usage_limit' => 1]);
    }

    public function down(): void
    {
        Schema::table('customer_vouchers', function (Blueprint $table): void {
            $table->dropColumn(['usage_limit', 'used_count']);
        });

        Schema::table('crm_campaigns', function (Blueprint $table): void {
            $table->dropColumn('voucher_usage_limit');
        });
    }
};
