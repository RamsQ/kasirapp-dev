<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentSetting;
use App\Models\Transaction;

class PaymentGatewayManager
{
    /**
     * Menggunakan Constructor Property Promotion (PHP 8+)
     */
    public function __construct(
        private MidtransGateway $midtransGateway,
        private XenditGateway $xenditGateway
    ) {
    }

    /**
     * Mengelola pembuatan pembayaran berdasarkan gateway yang dipilih
     * * @param Transaction $transaction
     * @param string $gateway
     * @param PaymentSetting $setting
     * @return array
     * @throws PaymentGatewayException
     */
    public function createPayment(Transaction $transaction, string $gateway, PaymentSetting $setting): array
    {
        return match ($gateway) {
            // Jalur Otomatis: Midtrans
            PaymentSetting::GATEWAY_MIDTRANS => $this->midtransGateway->createCharge($transaction, $setting->midtransConfig()),
            
            // Jalur Otomatis: Xendit
            PaymentSetting::GATEWAY_XENDIT => $this->xenditGateway->createInvoice($transaction, $setting->xenditConfig()),

            // Jalur Offline: Tunai atau QRIS Manual (Tidak butuh request API)
            'cash', PaymentSetting::GATEWAY_QRIS => [
                'reference'   => $transaction->invoice,
                'payment_url' => null,
                'token'       => null,
                'status'      => 'success'
            ],

            default => throw new PaymentGatewayException("Metode pembayaran {$gateway} belum didukung atau tidak aktif."),
        };
    }
}