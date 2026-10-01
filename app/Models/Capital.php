<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Setoran modal pemilik (sisi ekuitas pada neraca).
 *
 * Uang fisiknya tidak tersimpan di sini. Setoran modal sudah tercermin di
 * "Kas di Laci" lewat modal awal shift, atau di saldo bank. Tabel ini hanya
 * mencatat dari mana asal dan berapa besarnya, supaya sisi ekuitas neraca
 * bisa diisi.
 *
 * Penarikan modal tidak dicatat di sini, melainkan sebagai pengeluaran dengan
 * source "Modal Luar" -- jadi tidak ikut mengurangi laba.
 *
 * @property int $id
 * @property string $source
 * @property float $amount
 * @property string $date
 */
class Capital extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'amount',
        'date',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    /**
     * `date` dipakai sebagai tanggal bisnis (bukan created_at), jadi harus
     * bisa difilter per periode pada laporan neraca.
     */
    protected $dates = ['date'];
}
