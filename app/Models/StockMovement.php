<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasFactory;

    /**
     * Properti fillable untuk mengizinkan penyimpanan data secara massal.
     * Catatan: product_id dan ingredient_id bersifat nullable di database.
     * Pergerakan stok akan tercatat ke salah satu kolom tersebut.
     */
    protected $fillable = [
        'product_id',
        'ingredient_id', // Mendukung pencatatan riwayat Bahan Baku
        'user_id',       // Petugas yang bertanggung jawab
        'type',          // 'in' (Masuk) atau 'out' (Keluar)
        'qty',           // Volume stok
        'price',         // Harga beli/modal saat transaksi ini
        'reference',     // Kode unik referensi (misal: IN-697E...)
    ];

    /**
     * Casting tipe data agar konsisten. 
     * Menggunakan float agar mendukung satuan desimal (contoh: 1.5 liter).
     */
    protected $casts = [
        'qty'        => 'float',
        'price'      => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // --- RELATIONSHIPS (RELASI) ---

    /**
     * Relasi ke model Product.
     * Mengembalikan data produk jika record ini adalah stok barang jadi.
     */
    public function product() 
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relasi ke model Ingredient.
     * Mengembalikan data bahan baku jika record ini adalah stok dapur.
     */
    public function ingredient() 
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * Relasi ke model User.
     * Mengetahui siapa yang melakukan input stok.
     */
    public function user() 
    {
        return $this->belongsTo(User::class);
    }

    // --- QUERY SCOPES (PENYARINGAN) ---

    /**
     * Menyaring hanya data stok masuk.
     */
    public function scopeIn($query)
    {
        return $query->where('type', 'in');
    }

    /**
     * Menyaring hanya data stok keluar.
     */
    public function scopeOut($query)
    {
        return $query->where('type', 'out');
    }

    /**
     * Menyaring riwayat berdasarkan Bahan Baku tertentu.
     */
    public function scopeForIngredient($query, $id)
    {
        return $query->where('ingredient_id', $id);
    }
}