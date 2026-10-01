<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CrmCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'segment_id',
        'type',
        'channel',
        'subject',
        'message',
        'scheduled_at',
        'status',
        'is_dry_run',
        'total_recipients',
        'sent_count',
        'failed_count',
        'skipped_count',
        'last_run_at',
        'sent_at',
        'created_by',
        'voucher_mode',
        'voucher_name',
        'voucher_type',
        'voucher_value',
        'voucher_usage_limit',
        'voucher_min_spend',
        'voucher_expires_at',
        'voucher_prefix',
        'shared_voucher_code',
    ];

    protected $casts = [
        'is_dry_run' => 'boolean',
        'scheduled_at' => 'datetime',
        'last_run_at' => 'datetime',
        'sent_at' => 'datetime',
        'voucher_value' => 'float',
        'voucher_min_spend' => 'float',
        'voucher_expires_at' => 'datetime',
    ];

    public function segment()
    {
        return $this->belongsTo(CustomerSegment::class, 'segment_id');
    }

    public function sharedPromoCode()
    {
        return $this->hasOne(PromoCode::class, 'crm_campaign_id');
    }

    public function deliveries()
    {
        return $this->hasMany(CrmCampaignDelivery::class, 'campaign_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
