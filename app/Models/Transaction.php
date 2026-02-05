<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;
    
    /**
     * fillable
     *
     * @var array
     */
    protected $fillable = [
        'cashier_id',
        'customer_id',
        'shift_id', // <--- [BARU] Tambahkan ini agar shift_id bisa disimpan
        'invoice',
        'customer_name',
        'cash',
        'change',
        'discount',
        'grand_total',
        'payment_method',
        'payment_status',
        'table_name',
        'queue_number',
        'payment_reference',
        'payment_url',
    ];

    /**
     * shift
     *
     * @return void
     */
    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * details
     *
     * @return void
     */
    public function details()
    {
        return $this->hasMany(TransactionDetail::class);
    }

    /**
     * customer
     *
     * @return void
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * cashier
     *
     * @return void
     */
    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * profits
     *
     * @return void
     */
    public function profits()
    {
        return $this->hasMany(Profit::class);
    }

    /**
     * createdAt
     *
     * @return Attribute
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Carbon::parse($value)->format('d-M-Y H:i:s'),
        );
    }
} 