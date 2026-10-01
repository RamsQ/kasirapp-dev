<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'loyalty_rule_id',
        'loyalty_points_awarded',
        'shift_id',
        'invoice',
        'reference_code',
        'menu_ref',
        'customer_name',
        'cash',
        'change',
        'discount',
        'voucher_code',
        'voucher_discount',
        'taxable_amount',
        'tax_rate',
        'tax_amount',
        'grand_total',
        'payment_method',
        'payment_status',
        'paid_at',
        'settled_at',
        'failed_at',
        'refunded_at',
        'stock_restored_at',
        'status',          // --- TAMBAHKAN INI AGAR REFUND TERSIMPAN ---
        'kitchen_status',
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

    public function paymentSettlement()
    {
        return $this->hasOne(PaymentSettlement::class);
    }

    public function paymentEvents()
    {
        return $this->hasMany(PaymentEvent::class);
    }

    public function loyaltyLedger()
    {
        return $this->hasMany(LoyaltyPointLedger::class);
    }

    public function loyaltyRule()
    {
        return $this->belongsTo(LoyaltyRule::class);
    }

    public function stockBatchAllocations()
    {
        return $this->hasMany(StockBatchAllocation::class);
    }

    public function hold()
    {
        return $this->hasOne(Hold::class, 'transaction_id');
    }

    public function reservedVouchers()
    {
        return $this->hasMany(CustomerVoucher::class, 'reserved_transaction_id');
    }

    public function usedVouchers()
    {
        return $this->hasMany(CustomerVoucher::class, 'used_transaction_id');
    }

    protected function casts(): array
    {
        return [
            'taxable_amount' => 'integer',
            'tax_rate' => 'float',
            'tax_amount' => 'integer',
            'grand_total' => 'integer',
            'discount' => 'integer',
            'voucher_discount' => 'integer',
            'loyalty_points_awarded' => 'integer',
            'paid_at' => 'datetime',
            'settled_at' => 'datetime',
            'failed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'stock_restored_at' => 'datetime',
            'kitchen_status' => 'string',
        ];
    }

    /**
     * createdAt
     */
    protected function createdAt(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Carbon::parse($value)->format('d-M-Y H:i:s'),
        );
    }
}
