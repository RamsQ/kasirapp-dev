<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentSettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'provider',
        'provider_reference',
        'status',
        'amount',
        'currency',
        'settled_amount',
        'attempt_count',
        'last_checked_at',
        'next_retry_at',
        'settled_at',
        'failed_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'float',
        'settled_amount' => 'float',
        'attempt_count' => 'integer',
        'last_checked_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'settled_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function events()
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'unknown']);
    }

    public function scopeDueForReconciliation($query)
    {
        return $query->where(function ($query): void {
            $query->whereNull('next_retry_at')
                ->orWhere('next_retry_at', '<=', now());
        });
    }

    /**
     * Settlement yang sudah menggantung terlalu lama dan tidak lagi layak
     * di-retry otomatis.
     */
    public function scopeStale($query)
    {
        $cutoff = now()->subMinutes(max(1, (int) config('services.settlement.expire_after_minutes', 1440)));

        return $query->whereIn('status', ['pending', 'unknown'])
            ->where(function ($query) use ($cutoff): void {
                $query->where('created_at', '<=', $cutoff)
                    ->orWhere('last_checked_at', '<=', $cutoff);
            });
    }

    /**
     * Berapa lama settlement ini menggantung, dalam menit.
     */
    public function pendingMinutes(): int
    {
        $reference = $this->last_checked_at ?? $this->created_at;

        return $reference ? (int) abs(now()->diffInMinutes($reference)) : 0;
    }
}
