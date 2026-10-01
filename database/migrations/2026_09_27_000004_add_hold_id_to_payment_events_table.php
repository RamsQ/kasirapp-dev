<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menghubungkan `payment_events` ke pesanan menu QR.
 *
 * MASALAH: jalur pembayaran mandiri dari menu QR mencatat statusnya di tabel
 * `holds`, bukan di `payment_events`. Padahal `payment_events` adalah satu-
 * satu jejak audit yang dipakai untuk "¿sudah bayar?", "kapan callbacks-nya
 * masuk?", dan "signature-nya valid?". Akibatnya separuh transaksi yang
 * dibayar QRIS tidak punya riwayat sama sekali.
 *
 * Kolom ini memungkinkan event ditulis saat pembayaran dimulai, dan lalu
 * dipindahkan ke transaksi resmi ketika kasir menagih, sehingga jejaknya ikut
 * ke riwayat transaksi.
 *
 * Setelah dipindahkan, `hold_id` dikosongkan supaya event tidak terhitung dua
 * kali saat_structur dibuka.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_events')) {
            return;
        }

        if (Schema::hasColumn('payment_events', 'hold_id')) {
            return;
        }

        Schema::table('payment_events', function (Blueprint $table): void {
            $table->foreignId('hold_id')
                ->nullable()
                ->after('transaction_id')
                ->constrained('holds')
                ->cascadeOnDelete();
            $table->index(['hold_id', 'received_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_events') && Schema::hasColumn('payment_events', 'hold_id')) {
            Schema::table('payment_events', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('hold_id');
                $table->dropIndex(['hold_id', 'received_at']);
            });
        }
    }
};
