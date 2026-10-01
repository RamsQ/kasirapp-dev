<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    /**
     * Izinkan field cogs_method untuk disimpan (Mass Assignment)
     */
    protected $fillable = [
        'cogs_method',
        'tax_enabled',
        'tax_rate',
        'loyalty_enabled',
        'loyalty_points_per_100',
        'kitchen_display_enabled',
        'kitchen_display_payment_status',
        'kitchen_display_enabled_at',
    ];

    protected function casts(): array
    {
        return [
            'tax_enabled' => 'boolean',
            'tax_rate' => 'float',
            'loyalty_enabled' => 'boolean',
            'loyalty_points_per_100' => 'integer',
            'kitchen_display_enabled' => 'boolean',
            'kitchen_display_enabled_at' => 'datetime',
        ];
    }
}
