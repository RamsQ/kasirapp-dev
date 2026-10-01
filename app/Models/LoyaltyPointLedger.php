<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyPointLedger extends Model
{
    use HasFactory;

    protected $table = 'loyalty_point_ledger';

    protected $fillable = [
        'customer_id',
        'transaction_id',
        'loyalty_rule_id',
        'type',
        'points',
        'balance_after',
        'amount_base',
        'reference',
        'description',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer',
        'amount_base' => 'float',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function loyaltyRule()
    {
        return $this->belongsTo(LoyaltyRule::class);
    }
}
