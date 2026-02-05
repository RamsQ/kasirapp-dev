<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionDetail extends Model
{
    use HasFactory;
    
    /**
     * fillable
     *
     * @var array
     */
    protected $fillable = [
        'transaction_id', 
        'product_id', 
        'qty', 
        'price',
        'buy_price',       // <--- TAMBAHKAN INI AGAR HPP TERSIMPAN
        'unit',            // Kolom teks: Menyimpan "Sachet", "Box", dll.
        'product_unit_id'  // Foreign Key: ID referensi ke tabel product_units
    ];

    /**
     * Relasi ke Transaction
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Relasi ke Product
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Relasi ke ProductUnit (Satuan Kustom Konversi)
     * * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product_unit()
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }
}