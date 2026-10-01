<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'hold_id',
        'product_id',
        'ingredient_id',
        'quantity',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'quantity' => 'float',
        'expires_at' => 'datetime',
    ];

    public function hold()
    {
        return $this->belongsTo(Hold::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }
}
