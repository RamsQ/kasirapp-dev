<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',       // Contoh: 'Meja 01'
        'status',     // 'available' atau 'occupied'
        'position_x', // Untuk koordinat denah meja (drag & drop)
        'position_y'
    ];
}