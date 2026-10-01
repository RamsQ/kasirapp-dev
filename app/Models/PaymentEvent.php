<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'payment_settlement_id',
        'provider',
        'event_id',
        'provider_reference',
        'status',
        'amount',
        'signature_valid',
        'processed',
        'processing_error',
        'payload',
        'occurred_at',
        'received_at',
        'hold_id',
    ];

    protected $casts = [
        'amount' => 'float',
        // `signature_valid` sengaja TIDAK di-cast jadi boolean. Event yang
        // dibuat di sisi server (misal "tagihan dibuat" atau "kedaluwarsa")
        // tidak punya tanda tangan sama sekali, dan harus tetap null supaya
        // tidak ditampilkan sebagai "tanda tangan tidak valid" — itu membuat
        // kasir curiga ada penyalahgunaan yang sebenarnya tidak terjadi.
        'processed' => 'boolean',
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function settlement()
    {
        return $this->belongsTo(PaymentSettlement::class, 'payment_settlement_id');
    }

    /**
     * Pesanan menu QR yang sedang menunggu pembayaran.
     *
     * Setelah kasir menagih, event dipindahkan ke transaksi dan `hold_id`
     * dikosongkan supaya tidak terhitung dua kali.
     */
    public function hold()
    {
        return $this->belongsTo(Hold::class);
    }
}
