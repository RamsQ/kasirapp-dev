<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockBatch extends Model
{
    use HasFactory;

    /**
     * fillable
     * product_id & ingredient_id sekarang bersifat nullable di DB.
     * Batch akan terhubung ke salah satu saja tergantung tipe stock-in.
     */
    protected $fillable = [
        'product_id',
        'ingredient_id', 
        'qty_in',        
        'qty_remaining', 
        'buy_price',     
        'serial_number', 
    ];

    /**
     * casts
     * Memastikan perhitungan desimal (seperti gram ke kg) tetap presisi.
     */
    protected $casts = [
        'qty_in'        => 'float',
        'qty_remaining' => 'float',
        'buy_price'     => 'float',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    // --- RELATIONSHIPS ---

    /**
     * Relasi ke Produk (Nullable jika ini adalah bahan baku)
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relasi ke Ingredient (Nullable jika ini adalah produk jadi)
     */
    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    // --- QUERY SCOPES ---

    /**
     * Hanya ambil batch yang stoknya belum habis
     */
    public function scopeAvailable($query)
    {
        return $query->where('qty_remaining', '>', 0);
    }

    /**
     * FIFO: Digunakan untuk mengambil stok lama terlebih dahulu
     */
    public function scopeFifo($query)
    {
        return $query->orderBy('created_at', 'asc');
    }

    /**
     * LIFO: Digunakan untuk mengambil stok terbaru terlebih dahulu
     */
    public function scopeLifo($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Helper scope untuk mempermudah filter di Controller
     */
    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeForIngredient($query, $ingredientId)
    {
        return $query->where('ingredient_id', $ingredientId);
    }
}