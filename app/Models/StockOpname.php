<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockOpname extends Model
{
    use HasFactory;

    /**
     * fillable
     *
     * @var array
     */
    protected $fillable = [
        'product_id',
        'ingredient_id',
        'user_id',
        'stock_system',
        'stock_actual',
        'difference',
        'reason',
    ];

    /**
     * Relasi ke Product
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Relasi ke User
     * Menggunakan withTrashed() agar nama petugas tetap tampil di riwayat
     * meskipun akun user tersebut sudah dihapus (Soft Delete).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function ingredient()
    {
    return $this->belongsTo(Ingredient::class);
    }
}