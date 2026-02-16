<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function bonusProduct()
    {
        return $this->belongsTo(Product::class, 'bonus_product_id');
    }

    /**
     * Scope untuk mengambil diskon yang valid (DIPERBAIKI)
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function($q) {
                // Promo aktif jika: Tanggal kosong ATAU hari ini masuk dalam rentang tanggal
                $q->where(function($sub) {
                    $sub->whereNull('start_date')->whereNull('end_date');
                })->orWhere(function($sub) {
                    $sub->whereDate('start_date', '<=', now()->toDateString())
                        ->whereDate('end_date', '>=', now()->toDateString());
                });
            });
    }

    public function isProductSpecific()
    {
        return !is_null($this->product_id);
    }
}