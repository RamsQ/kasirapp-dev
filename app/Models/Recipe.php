<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'ingredient_id',
        'qty_needed',
    ];

    // Relasi ke Bahan Baku
    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    // Relasi ke Produk
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}