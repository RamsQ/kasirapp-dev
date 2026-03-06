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
        'shift_id', 
        'invoice',
        'reference_code',
        'customer_name',
        'cash',
        'change',
        'discount',
        'grand_total',
        'payment_method',
        'payment_status',
        'status',          // --- TAMBAHKAN INI AGAR REFUND TERSIMPAN ---
        'table_name',
        'queue_number',
        'payment_reference',
        'payment_url',
        // --- FIELD UNTUK LAPORAN ONLINE ---
        'online_platform',
        'total_markup',
        'total_fee',
    ];

    /**
     * CATATAN: $appends DIHAPUS.
     * Karena reference_code dan queue_number sudah ada di database (hasil migration),
     * Laravel otomatis akan mengirimkannya ke Frontend tanpa perlu bantuan $appends.
     */

    /**
     * shift
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * details
     *
     * @return \Illuminate\Database\Relations\HasMany
     */
    public function details()
    {
        return $this->hasMany(TransactionDetail::class);
    }

    /**
     * customer
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * cashier
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * profits
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
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