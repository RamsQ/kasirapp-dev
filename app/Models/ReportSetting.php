<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportSetting extends Model
{
    protected $fillable = ['is_active', 'is_weekly', 'is_monthly', 'method', 'target', 'send_at', 'wa_api_key'];
}