<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use HasFactory;

    protected $guarded = [];

    // Relasi ke User (Kasir)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relasi ke banyak Transaksi
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}