<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nomor telepon adalah identifier, bukan angka: kolom bigInteger menghapus
        // nol di depan (0812 -> 812) sehingga target WhatsApp/gateway jadi salah.
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('no_telp', 32)->nullable()->change();
        });

        // Pulihkan nol di depan yang hilang pada data lama (nomor Indonesia).
        DB::table('customers')
            ->whereNotNull('no_telp')
            ->where('no_telp', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($customers): void {
                foreach ($customers as $customer) {
                    $phone = trim((string) $customer->no_telp);

                    if ($phone === '' || str_starts_with($phone, '0') || str_starts_with($phone, '+')) {
                        continue;
                    }

                    // 62xxx -> 0xxx (kembalikan format lokal Indonesia)
                    if (str_starts_with($phone, '62') && strlen($phone) >= 11) {
                        $phone = '0'.substr($phone, 2);
                    } elseif (str_starts_with($phone, '8')) {
                        $phone = '0'.$phone;
                    } else {
                        continue;
                    }

                    DB::table('customers')->where('id', $customer->id)->update(['no_telp' => $phone]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->bigInteger('no_telp')->nullable()->change();
        });
    }
};
