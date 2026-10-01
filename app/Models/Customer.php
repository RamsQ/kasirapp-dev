<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'no_telp',
        'phone',
        'email',
        'address',
        'loyalty_points',
        'lifetime_spend',
        'loyalty_tier',
        'loyalty_enabled',
        'loyalty_enrolled_at',
        'last_visit_at',
        'birthday',
        'marketing_opt_in',
        'crm_opt_out_at',
        'crm_opt_out_reason',
        'notes',
    ];

    protected $casts = [
        'loyalty_points' => 'integer',
        'lifetime_spend' => 'float',
        'loyalty_enabled' => 'boolean',
        'loyalty_enrolled_at' => 'datetime',
        'birthday' => 'date',
        'marketing_opt_in' => 'boolean',
        'crm_opt_out_at' => 'datetime',
        'last_visit_at' => 'datetime',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function loyaltyLedger()
    {
        return $this->hasMany(LoyaltyPointLedger::class);
    }

    public function vouchers()
    {
        return $this->hasMany(CustomerVoucher::class);
    }

    public function campaignDeliveries()
    {
        return $this->hasMany(CrmCampaignDelivery::class);
    }

    public function tierLabel(): string
    {
        return match ($this->loyalty_tier) {
            'platinum' => 'Platinum',
            'gold' => 'Gold',
            'silver' => 'Silver',
            default => 'Bronze',
        };
    }
}
