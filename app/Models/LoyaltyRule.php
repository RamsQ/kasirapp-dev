<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'min_spend',
        'points',
        'product_id',
        'category_id',
        'starts_at',
        'ends_at',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'min_spend' => 'integer',
        'points' => 'integer',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(LoyaltyPointLedger::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('starts_at')->orWhereDate('starts_at', '<=', $today);
            })
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today);
            });
    }
}
