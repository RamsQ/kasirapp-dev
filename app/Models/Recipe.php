<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu bahan dalam resep sebuah menu.
 *
 * `unit` disalin dari bahan saat resep disimpan. HPP dihitung sebagai
 * qty_needed x ingredient.buy_price, dan buy_price adalah harga per satuan
 * bahan, jadi satuan takaran WAJIB sama dengan satuan bahan. Kolom ini tidak
 * mencegah salah input, tetapi membuatnya eksplisit dan bisa ditinjau.
 */
class Recipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'ingredient_id',
        'qty_needed',
        'unit',
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
