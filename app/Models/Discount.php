<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;

    // Menggunakan guarded kosong agar semua field bisa diisi (termasuk product_id, bonus_product_id, dan type)
    protected $guarded = [];

    /**
     * Relasi ke Model Product (Produk Utama/Target)
     * Jika product_id bernilai null, maka diskon dianggap Global.
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Relasi ke Model Product (Produk Bonus/Gratis)
     * Digunakan khusus untuk tipe 'buy_get'.
     */
    public function bonusProduct()
    {
        return $this->belongsTo(Product::class, 'bonus_product_id');
    }

    /**
     * Scope untuk mengambil diskon yang valid hari ini
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->whereDate('start_date', '<=', now())
                     ->whereDate('end_date', '>=', now());
    }

    /**
     * Helper untuk mengecek apakah diskon ini spesifik per produk
     */
    public function isProductSpecific()
    {
        return !is_null($this->product_id);
    }
}