<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    // Tambahkan baris di bawah ini
    protected $fillable = [
        'product_id',
        'unit_name',
        'conversion',
        'sell_price',
    ];

    /**
     * Relasi ke Produk
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}