<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    /**
     * Izinkan field cogs_method untuk disimpan (Mass Assignment)
     */
    protected $fillable = [
        'cogs_method',
        // tambahkan field lain jika ada di tabel settings Anda nanti
    ];
}