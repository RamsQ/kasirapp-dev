<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membiarkan `payment_events.signature_valid` bernilai null.
 *
 * MASALAH: kolom ini `NOT NULL DEFAULT 0`, padahal ada tiga keadaan yang
 * berbeda dan hanya dua yang bisa disimpan:
 *
 *   true  = callback terverifikasi, sah
 *   false = callback ditolak karena tanda tangannya tidak cocok (ada yang
 *           menyamar sebagai gateway)
 *   ???   = event yang TIDAK dari callback sama sekali, misalnya "tagihan
 *           dibuat" saat QR baru dibuat, atau "kedaluwarsa" yang ditandai
 *           oleh sistem sendiri
 *
 * Dengan skema lama, kasus ketiga ikut tersimpan sebagai `0` — sama persis
 * dengan tanda tangan palsu. Akibatnya riwayat pembayaran menampilkan
 * "tanda tangan tidak valid" untuk event yang sama sekali tidak punya tanda
 * tangan, sehingga kasir akan mencari penyalahgunaan yang sebenarnya tidak
 * terjadi.
 *
 * NULL membuat keadaan ketiga terbedakan secara jujur. Kolom yang sudah punya
 * nilai tidak diubah: yang `0` berarti "sudah diverifikasi dan tidak cocok",
 * persis seperti sebelumnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_events')) {
            return;
        }

        Schema::table('payment_events', function (Blueprint $table): void {
            $table->boolean('signature_valid')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_events')) {
            return;
        }

        // Event tanpa tanda tangan kembali dianggap tidak valid, seperti
        // skema sebelumnya.
        DB::table('payment_events')->whereNull('signature_valid')->update(['signature_valid' => false]);

        Schema::table('payment_events', function (Blueprint $table): void {
            $table->boolean('signature_valid')->default(false)->change();
        });
    }
};
