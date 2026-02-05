<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class Product extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $fillable = [
        'image', 
        'barcode', 
        'title', 
        'description', 
        'buy_price', 
        'sell_price', 
        'cost_price', // Field HPP dari resep (untuk produk olahan)
        'category_id', 
        'stock',
        'unit', 
        'expired_date',
        'type',
    ];

    protected $casts = [
        'expired_date' => 'date',
        'buy_price'    => 'float',
        'sell_price'   => 'float',
        'cost_price'   => 'float', 
        'stock'        => 'float',
    ];

    /**
     * Mendaftarkan atribut virtual agar muncul saat data dipanggil ke Frontend/Inertia
     */
    protected $appends = [
        'days_until_expired',
        'final_hpp' // Menambahkan atribut HPP Final
    ];

    /**
     * Accessor: Mendapatkan Nilai Modal (HPP) Final
     * Prioritas: 1. cost_price (Resep), 2. buy_price (Input Manual)
     */
    protected function finalHpp(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->cost_price > 0 ? $this->cost_price : ($this->buy_price ?? 0)
        );
    }

    /**
     * Relasi ke Recipe (Manajemen Resep & HPP)
     * Menghubungkan produk dengan komponen bahan bakunya
     */
    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    /**
     * Accessor: Menghitung sisa hari menjelang expired secara real-time
     */
    protected function daysUntilExpired(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!$this->expired_date) return null;
                
                $now = Carbon::now()->startOfDay();
                $expiry = Carbon::parse($this->expired_date)->startOfDay();
                
                return (int) $now->diffInDays($expiry, false);
            }
        );
    }

    /**
     * Relasi ke Stock Movements (Sinkronisasi riwayat kedatangan/pengeluaran barang)
     */
    public function stock_movements()
    {
        return $this->hasMany(StockMovement::class)->latest();
    }

    /**
     * Relasi ke Stock Batches (Penting untuk FIFO/LIFO)
     */
    public function stock_batches()
    {
        return $this->hasMany(StockBatch::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function details()
    {
        return $this->hasMany(TransactionDetail::class, 'product_id');
    }

    /**
     * Accessor untuk URL Image
     */
    protected function image(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (!$value) return null;
                if (filter_var($value, FILTER_VALIDATE_URL)) return $value;

                return asset('/storage/products/' . $value);
            }
        );
    }

    /**
     * Fitur Bundle / Paket Produk
     */
    public function bundle_items()
    {
        return $this->belongsToMany(Product::class, 'product_bundles', 'product_id', 'item_id')
                ->withPivot('qty', 'product_unit_id')
                ->with(['units']);
    }

    public function bundles()
    {
        return $this->belongsToMany(Product::class, 'product_bundles', 'item_id', 'product_id');
    }

    /**
     * Fitur Multi-Satuan (Pcs, Box, Dus, dll)
     */
    public function units()
    {
        return $this->hasMany(ProductUnit::class);
    }

    /**
     * Riwayat Audit Stok
     */
    public function stock_opnames()
    {
        return $this->hasMany(StockOpname::class);
    }
}