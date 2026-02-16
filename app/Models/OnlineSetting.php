<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineSetting extends Model
{
    protected $fillable = ['name', 'markup_percent', 'additional_fee', 'is_active'];
}