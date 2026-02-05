<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    /**
     * fillable
     *
     * @var array
     */
    protected $fillable = [
        'cashier_id', 
        'product_id', 
        'qty', 
        'price', 
        'hold_id', 
        'hold_label', 
        'held_at',
        'product_unit_id', // Tambahkan ini agar bisa menyimpan pilihan satuan
        'table_id',
    ];

    /**
     * casts
     *
     * @var array
     */
    protected $casts = [
        'held_at' => 'datetime',
        'qty'     => 'float', // Cast ke float agar mendukung angka desimal (0.5 dll)
    ];

    /**
     * product
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relasi ke ProductUnit
     * * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function unit()
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    /**
     * Scope for active (not held) carts
     */
    public function scopeActive($query)
    {
        return $query->whereNull('hold_id');
    }

    /**
     * Scope for held carts
     */
    public function scopeHeld($query)
    {
        return $query->whereNotNull('hold_id');
    }

    /**
     * Scope for specific hold group
     */
    public function scopeForHold($query, $holdId)
    {
        return $query->where('hold_id', $holdId);
    }
}