<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyimpan asal pesanan menu QR pada transaksi resmi.
 *
 * MASALAH: pesanan dari menu QR dibuat sebagai `Hold`, lalu kasir menagih dan
 * Hold itu DIHAPUS. Padahal pelanggan sudah memegang URL struk digital dengan
 * pola `/menu/struk/{ref}`. Begitu Hold hilang, URL itu mati tepat setelah
 * pesanan selesai diproses — dan struk digital adalah satu-satunya bukti yang
 * pelanggan bawa ke kasir.
 *
 * Kenapa tidak memakai `reference_code`: kolom itu juga dipakai transaksi POS
 * biasa, yang diisi `Str::random(4)`. Kalau receipt mencari transaksi lewat
 * kolom itu, `QR-1234` bisa cocok dengan transaksi kasir yang kebetulan punya
 * kode sama, dan pelanggan bisa melihat struk orang lain.
 *
 * Kolom ini hanya diisi kalau transaksi berasal dari pesanan menu QR, jadi
 * pencocokannya tidak pernah ambigu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            return;
        }

        if (Schema::hasColumn('transactions', 'menu_ref')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('menu_ref', 30)
                ->nullable()
                ->after('reference_code')
                ->index()
                ->comment('ref_number pesanan menu QR. Kosong untuk transaksi kasir biasa.');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'menu_ref')) {
            Schema::table('transactions', function (Blueprint $table): void {
                $table->dropColumn('menu_ref');
            });
        }
    }
};
