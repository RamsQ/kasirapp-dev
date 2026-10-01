<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyimpan pilihan metode bayar dari pesanan menu QR.
 *
 * KONTEKS: pesanan dari menu QR BELUM dibayar. Backend membuat `Hold`, bukan
 * `Transaction`, dan uangnya ditagih di kasir. Jadi kolom ini bukan catatan
 * pembayaran, melainkan MINAT pelanggan sebelum datang ke kasir.
 *
 * Kenapa tetap disimpan: supaya kasir tahu harus menyiapkan apa. Pelanggan yang
 * sudah scan QRIS tidak mau menunggu di kasir, dan kasir yang salah menyiapkan
 * kas QRIS akandelay pesanan itu.
 *
 * CATATAN PENTING: kolom `transactions.payment_method` tetap yang otoritatif
 * saat penagihan. Kalau pelanggan bilang QRIS tapi lalu bayar tunai, yang
 * tersimpan di transaksi tetap "cash" supaya neraca tidak salah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holds')) {
            return;
        }

        if (Schema::hasColumn('holds', 'requested_payment_method')) {
            return;
        }

        Schema::table('holds', function (Blueprint $table): void {
            $table->string('requested_payment_method', 30)
                ->nullable()
                ->after('queue_number')
                ->comment('Minat pelanggan dari menu QR. Bukan catatan pembayaran.');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('holds') && Schema::hasColumn('holds', 'requested_payment_method')) {
            Schema::table('holds', function (Blueprint $table): void {
                $table->dropColumn('requested_payment_method');
            });
        }
    }
};
