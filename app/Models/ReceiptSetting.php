<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReceiptSetting extends Model
{
    use HasFactory;

    // Menggunakan guarded empty array berarti semua field (termasuk show_logo) bisa disimpan
    protected $guarded = [];
}