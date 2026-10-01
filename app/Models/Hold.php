<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    /**
     * Minat metode bayar yang bisa dipilih pelanggan dari menu QR.
     *
     * Nilainya sengaja memakai kode yang sama dengan
     * `transactions.payment_method`, supaya kasir tidak perlu menerjemahkan
     * saat memproses pesanan dan supaya laporan bisa langsung memakai kolom
     * yang sama.
     *
     * Dua kelompok:
     *  - CASH / QRIS statis: dibayar di kasir, kasir yang memverifikasi.
     *  - MIDTRANS / XENDIT: pelanggan membayar sendiri lewat QRIS dinamis, dan
     *    pesanan sudah lunas sebelum kasir menagih.
     */
    public const PREFERENCE_CASH = 'cash';

    public const PREFERENCE_QRIS = 'qris_manual';

    /** Bayar sendiri lewat gateway. */
    public const PREFERENCE_MIDTRANS = PaymentSetting::GATEWAY_MIDTRANS;

    public const PREFERENCE_XENDIT = PaymentSetting::GATEWAY_XENDIT;

    public const PAYMENT_PREFERENCES = [
        self::PREFERENCE_CASH,
        self::PREFERENCE_QRIS,
        self::PREFERENCE_MIDTRANS,
        self::PREFERENCE_XENDIT,
    ];

    /**
     * Preferensi yang berarti pelanggan sudah membayar sendiri lewat gateway.
     */
    public static function isSelfPaidGateway(?string $value): bool
    {
        return in_array($value, [self::PREFERENCE_MIDTRANS, self::PREFERENCE_XENDIT], true);
    }

    /**
     * fillable
     *
     * @var array
     */
    protected $fillable = [
        'ref_number',
        'reference_code', // --- [BARU] AGAR KODE 4 DIGIT BISA DISIMPAN DI ANTREAN ---
        'cart_data',
        'total',
        'kitchen_status',
        'taxable_amount',
        'tax_rate',
        'tax_amount',
        'user_id',
        'table_id',
        'customer_name',
        'queue_number',
        'requested_payment_method',
        // Status pembayaran untuk pesanan mandiri. Kolom-kolom ini sengaja
        // TIDAK ikut terisi lewat `create()` dari input user: nilai awalnya
        // dibuat server saat pesanan dibuat, dan setelah itu hanya
        // HoldPaymentService yang boleh mengubahnya. Kalau tidak sengaja
        // fillable, nilai yang dikirim akan diam-diam dibuang dan pesanan
        // terlihat belum bayar padahal sudah lunas.
        'payment_method',
        'payment_status',
        'payment_reference',
        'payment_payload',
        'paid_amount',
        'paid_at',
        'payment_expires_at',
        'transaction_id',
    ];

    /**
     * casts
     *
     * @var array
     */
    protected $casts = [
        'cart_data' => 'array', // Casting JSON ke Array otomatis agar bundling bisa terbaca
        'kitchen_status' => 'string',
        'taxable_amount' => 'integer',
        'tax_rate' => 'float',
        'tax_amount' => 'integer',
        'total' => 'float',
        'payment_payload' => 'array',
        'paid_amount' => 'float',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'paid_at' => 'datetime',
        'payment_expires_at' => 'datetime',
    ];

    /**
     * Apakah QR pembayaran masih berlaku?
     */
    public function paymentIsLive(): bool
    {
        return $this->payment_status === 'pending'
            && $this->payment_expires_at !== null
            && $this->payment_expires_at->isFuture();
    }

    /**
     * user
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * table
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function stockReservations()
    {
        return $this->hasMany(StockReservation::class);
    }
}
