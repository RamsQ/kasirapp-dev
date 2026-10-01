<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromoCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'value',
        'min_spend',
        'starts_at',
        'expires_at',
        'max_uses',
        'used_count',
        'reserved_count',
        'is_active',
        'crm_campaign_id',
    ];

    protected $casts = [
        'value' => 'float',
        'min_spend' => 'float',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'reserved_count' => 'integer',
    ];

    public function campaign()
    {
        return $this->belongsTo(CrmCampaign::class, 'crm_campaign_id');
    }

    public function scopeUsable(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            // NB: orWhereColumn() hanya menerima dua kolom tanpa operator,
            // jadi perbandingan antar kolom harus lewat whereRaw.
            ->where(fn (Builder $q) => $q->whereNull('max_uses')
                ->orWhereRaw('(used_count + reserved_count) < max_uses'));
    }

    public function minimumSpend(): int
    {
        return (int) ($this->min_spend ?? 0);
    }

    /**
     * Diskon dalam rupiah penuh, tidak melebihi nilai keranjang.
     */
    public function discountFor(int $amount): int
    {
        return max(0, min(
            $amount,
            $this->type === 'percentage'
                ? (int) round($amount * ((float) $this->value / 100))
                : (int) $this->value,
        ));
    }

    public function hasQuotaLeft(): bool
    {
        return $this->max_uses === null
            || ($this->used_count + $this->reserved_count) < $this->max_uses;
    }
}
