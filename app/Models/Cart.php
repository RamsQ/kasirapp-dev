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
        'product_unit_id', // Pastikan ini dapat diisi untuk menyimpan pilihan satuan
        'qty', 
        'price',           // Menyimpan harga final (setelah markup & diskon dashboard)
        'notes', 
        'hold_id', 
        'hold_label', 
        'held_at',
        'table_id',
    ];

    /**
     * casts
     *
     * @var array
     */
    protected $casts = [
        'held_at' => 'datetime',
        'qty'     => 'float', // Mendukung angka desimal untuk berat/satuan tertentu
        'price'   => 'float', // Cast ke float agar sinkron dengan tipe decimal di database
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
     * Scope untuk mengambil keranjang yang aktif (bukan pesanan yang ditunda)
     */
    public function scopeActive($query)
    {
        return $query->whereNull('hold_id');
    }

    /**
     * Scope untuk pesanan yang sedang ditunda (held)
     */
    public function scopeHeld($query)
    {
        return $query->whereNotNull('hold_id');
    }

    /**
     * Scope untuk grup penundaan (hold) spesifik
     */
    public function scopeForHold($query, $holdId)
    {
        return $query->where('hold_id', $holdId);
    }
}