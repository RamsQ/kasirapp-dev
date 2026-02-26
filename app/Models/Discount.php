<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;

    /**
     * Mengizinkan semua kolom diisi secara mass-assignment.
     * Pastikan kolom berikut ada di DB: 
     * product_id, bonus_product_id, minimum_item, name, type, value, min_transaction, start_date, end_date, is_active
     */
    protected $guarded = [];

    /**
     * Relasi ke Produk Utama (Trigger Promo)
     */
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Relasi ke Produk Hadiah (Reward Promo)
     */
    public function bonusProduct()
    {
        return $this->belongsTo(Product::class, 'bonus_product_id');
    }

    /**
     * Scope untuk mengambil diskon yang sedang aktif berdasarkan status dan rentang tanggal.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function($q) {
                // Promo dianggap aktif jika: 
                // 1. Tanggal tidak diatur (NULL) - berlaku selamanya
                // 2. Hari ini berada di antara start_date dan end_date
                $q->where(function($sub) {
                    $sub->whereNull('start_date')->whereNull('end_date');
                })->orWhere(function($sub) {
                    $sub->whereDate('start_date', '<=', now()->toDateString())
                        ->whereDate('end_date', '>=', now()->toDateString());
                });
            });
    }

    /**
     * Helper: Mengecek apakah ini diskon produk spesifik (BUKAN diskon global/nota)
     */
    public function isProductSpecific()
    {
        return !is_null($this->product_id);
    }

    /**
     * Helper: Mengecek apakah ini tipe promo "Beli X Gratis Y"
     */
    public function isBuyGet()
    {
        return $this->type === 'buy_get' && !is_null($this->bonus_product_id);
    }
}