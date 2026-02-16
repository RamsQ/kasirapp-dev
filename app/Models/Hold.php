<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    /**
     * fillable
     *
     * @var array
     */
    protected $fillable = [
        'ref_number', 
        'reference_code', // --- [BARU] AGAR KODE 4 DIGIT BISA DISIMPAN DI ANTREAN ---
        'cart_data', 
        'total', 
        'user_id', 
        'table_id', 
        'customer_name', 
        'queue_number'
    ];

    /**
     * casts
     *
     * @var array
     */
    protected $casts = [
        'cart_data' => 'array', // Casting JSON ke Array otomatis agar bundling bisa terbaca
    ];

    /**
     * user
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user() 
    {
        return $this->belongsTo(User::class);
    }

    /**
     * table
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function table()
    {
        return $this->belongsTo(Table::class);
    }
}