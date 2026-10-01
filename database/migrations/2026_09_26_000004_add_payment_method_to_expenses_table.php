<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membiarkan expense mencatat cara pembayarannya.
 *
 * KEBUTUHAN: pembelian stok adalah konversi kas menjadi persediaan, BUKAN
 * beban. Kalau tidak ada pencatatannya, nilai persediaan naik di sisi aktiva
 * tanpa ada sisi lain yang turun, sehingga neraca tidak akan pernah balance.
 *
 * Kolom ini membedakan:
 *   - 'cash'   : dibayar dari laci/rekening. Mengurangi kas, tidak menambah hutang.
 *   - 'credit' : dibayar kemudian. Menambah hutang usaha, kas tidak tersentuh.
 *
 * Expense yang sudah ada tidak berubah artinya karena default-nya 'cash'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expenses')) {
            return;
        }

        if (Schema::hasColumn('expenses', 'payment_method')) {
            return;
        }

        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('payment_method', 20)->default('cash')->after('source')->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('expenses') && Schema::hasColumn('expenses', 'payment_method')) {
            Schema::table('expenses', function (Blueprint $table): void {
                $table->dropColumn('payment_method');
            });
        }
    }
};
