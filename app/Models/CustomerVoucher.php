<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerVoucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'source_campaign_id',
        'code',
        'name',
        'type',
        'value',
        'min_spend',
        'expires_at',
        'status',
        'usage_limit',
        'used_count',
        'reserved_transaction_id',
        'used_transaction_id',
        'reserved_at',
        'used_at',
    ];

    protected $casts = [
        'value' => 'float',
        'min_spend' => 'float',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'expires_at' => 'datetime',
        'reserved_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function sourceCampaign()
    {
        return $this->belongsTo(CrmCampaign::class, 'source_campaign_id');
    }

    public function reservedTransaction()
    {
        return $this->belongsTo(Transaction::class, 'reserved_transaction_id');
    }

    public function usedTransaction()
    {
        return $this->belongsTo(Transaction::class, 'used_transaction_id');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
