<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    use HasFactory;

    /**
     * fillable
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'unit', // Sekarang menyimpan teks langsung (kg, gr, dll)
        'buy_price',
        'stock',
        'min_stock'
    ];

    /**
     * casts
     * * Memastikan data angka selalu diproses sebagai angka (bukan string)
     * Ini krusial agar perhitungan $currentStock + $qtyIn di Controller berhasil.
     */
    protected $casts = [
        'buy_price' => 'float',
        'stock'     => 'float',
        'min_stock' => 'float',
    ];

    /**
     * Relasi ke tabel Product (Melalui Resep)
     */
    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_ingredients')
                    ->withPivot('amount')
                    ->withTimestamps();
    }
    
    /**
     * Catatan: Relasi unit() dihapus karena kita sudah beralih 
     * dari unit_id (integer) ke unit (string manual).
     */
}