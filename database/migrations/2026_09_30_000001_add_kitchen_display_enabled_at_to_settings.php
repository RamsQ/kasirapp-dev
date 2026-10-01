<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menandai kapan Kitchen Display terakhir kali DINYALAKAN.
 *
 * MASALAH ASAL: begitu KDS dinyalakan, layar langsung penuh order lama yang
 * dibuat saat KDS masih mati. Tiga sumber order lama itu:
 *
 *  1. Order yang dibuat jalur mana pun yang lupa menulis `kitchen_status`.
 *     Kolomnya ber-default 'pending', jadi order yang tidak pernah masuk
 *     dapur tetap lahir sebagai "menunggu dimasak".
 *
 *  2. Order yang dibuat disengaja saat KDS masih mati. Jalur POS sudah
 *     benar menulis 'completed' kalau KDS mati, tapi tidak ada catatan
 *     KIDS berapa lama sudah aktif, sehingga tidak ada cara tahu order mana
 *     yang benar-benar sudah masuk dapur dan mana yang belum.
 *
 *  3. Data lama hasil migrasi. Migrasi 2026_09_25_000001 sudah menandai
 *     order yang ada saat itu sebagai 'completed', tapi itu hanya berlaku
 *     untuk order yang sudah ada MENURUT Migration itu. Order setelahnya
 *     tidak tersentuh.
 *
 * SOLUSI: simpan waktu KDS terakhir dinyalakan, dan KDS hanya menampilkan
 * order yang dibuat pada atau setelah waktu itu. Ini tidak mengubah data
 * order sama sekali, jadi tidak ada order yang hilang dari pembukuan atau
 * laporan.
 *
 * Dipakai juga untuk menentukan arah tampilan saat KDS dimatikan: order yang
 * masih 'pending' di layar ketika KDS dimatikan dianggap sudah dimasak, jadi
 * tidak muncul lagi kalau nanti dinyalakan lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->timestamp('kitchen_display_enabled_at')
                ->nullable()
                ->after('kitchen_display_payment_status');
        });

        /*
         * Seed(instalasi yang sudah jalan) belum punya nilai ini. Kalau NULL,
         * KDS tidak memfilter apa pun - yang penting tidak langsung
         * menyembunyikan order yang sedang dimasak.
         */
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn('kitchen_display_enabled_at');
        });
    }
};
