<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockBatchAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'stock_batch_id',
        'product_id',
        'ingredient_id',
        'resource_type',
        'quantity',
        'unit_cost',
        'total_cost',
        'method',
        'movement_type',
        'status',
        'original_allocation_id',
        'reference',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_cost' => 'float',
        'total_cost' => 'float',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function batch()
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient()
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function originalAllocation()
    {
        return $this->belongsTo(self::class, 'original_allocation_id');
    }
}
