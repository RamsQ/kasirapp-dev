<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran mandiri untuk pesanan menu QR.
 *
 * KONTEKS: pesanan dari menu QR disimpan sebagai `Hold`, bukan `Transaction`.
 * Stok dan HPP baru dihitung saat kasir menagih. Kalau pesanan diubah jadi
 * `Transaction` sejak awal supaya bisa ditagih gateway, dua hal yang sudah
 * benar jadi rusak:
 *   - stok dan HPP akan terpakai sebelum ada yang membayar
 *   - dua pesanan di meja yang sama tidak bisa digabung jadi satu tagihan
 *
 * SOLUSI: pembayaran dicatat di `Hold` lebih dulu. Uang masuk, pesanan
 * ditandai lunas, lalu kasir menagih dan barulah penjualan tercatat resmi.
 * `transactions.payment_method` tetap yang otoritatif untuk laporan.
 *
 * Kolom yang ditambahkan hanya mencatat PERJALANAN pembayaran. Tidak ada
 * kolom yang bisa membuat penjualan tercatat di neraca, jadi Neraca tetap
 * hanya membaca dari `transactions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holds')) {
            return;
        }

        if (Schema::hasColumn('holds', 'payment_status')) {
            return;
        }

        Schema::table('holds', function (Blueprint $table): void {
            // Metode yang benar-benar dipakai untuk membayar: midtrans, xendit,
            // atau qris_manual (QRIS statis, tanpa callback).
            $table->string('payment_method', 30)->nullable()->after('requested_payment_method');

            // unpaid -> pending -> paid, atau failed / expired.
            $table->string('payment_status', 20)->default('unpaid')->after('payment_method')->index();

            // Referensi dari gateway, dipakai untuk mencocokkan callback.
            $table->string('payment_reference', 191)->nullable()->after('payment_status')->index();

            // Isi respons gateway: qr_string (Xendit) atau payment_url
            // (Snap Midtrans). Disimpan supaya halaman bisa menampilkan ulang
            // QR yang sama tanpa memanggil gateway dua kali.
            $table->json('payment_payload')->nullable()->after('payment_reference');

            // Nominal yang benar-benar dibayar. WAJIB dibandingkan dengan
            // total hold saat callback: kalau beda, pembayaran ditolak.
            $table->decimal('paid_amount', 15, 2)->nullable()->after('payment_payload');

            $table->timestamp('paid_at')->nullable()->after('paid_amount');
            $table->timestamp('payment_expires_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('holds') || ! Schema::hasColumn('holds', 'payment_status')) {
            return;
        }

        Schema::table('holds', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_method',
                'payment_status',
                'payment_reference',
                'payment_payload',
                'paid_amount',
                'paid_at',
                'payment_expires_at',
            ]);
        });
    }
};
