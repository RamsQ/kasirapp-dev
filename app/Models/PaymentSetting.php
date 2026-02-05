<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    use HasFactory;

    public const GATEWAY_MIDTRANS = 'midtrans';
    public const GATEWAY_XENDIT = 'xendit';
    public const GATEWAY_QRIS = 'qris'; // Tambahkan konstanta QRIS

    protected $fillable = [
        'default_gateway',
        'midtrans_enabled',
        'midtrans_server_key',
        'midtrans_client_key',
        'midtrans_production',
        'xendit_enabled',
        'xendit_secret_key',
        'xendit_public_key',
        'xendit_production',
        // --- FIELD BARU QRIS MANUAL ---
        'qris_manual_enabled',
        'qris_manual_image',
    ];

    protected $casts = [
        'midtrans_enabled' => 'boolean',
        'midtrans_production' => 'boolean',
        'xendit_enabled' => 'boolean',
        'xendit_production' => 'boolean',
        // --- CAST BARU ---
        'qris_manual_enabled' => 'boolean',
    ];

    /**
     * Mendapatkan daftar gateway yang aktif untuk ditampilkan di UI
     */
    public function enabledGateways(): array
    {
        $gateways = [];

        // Midtrans
        if ($this->isGatewayReady(self::GATEWAY_MIDTRANS)) {
            $gateways[] = [
                'value' => self::GATEWAY_MIDTRANS,
                'label' => 'Midtrans',
                'description' => 'Bagikan tautan pembayaran Snap Midtrans ke pelanggan.',
            ];
        }

        // Xendit
        if ($this->isGatewayReady(self::GATEWAY_XENDIT)) {
            $gateways[] = [
                'value' => self::GATEWAY_XENDIT,
                'label' => 'Xendit',
                'description' => 'Buat invoice otomatis menggunakan Xendit.',
            ];
        }

        // QRIS Manual (BARU)
        if ($this->isGatewayReady(self::GATEWAY_QRIS)) {
            $gateways[] = [
                'value' => self::GATEWAY_QRIS,
                'label' => 'QRIS Manual',
                'description' => 'Tampilkan gambar QRIS statis untuk discan pelanggan.',
            ];
        }

        return $gateways;
    }

    /**
     * Mengecek apakah konfigurasi gateway sudah lengkap dan diaktifkan
     */
    public function isGatewayReady(string $gateway): bool
    {
        return match ($gateway) {
            self::GATEWAY_MIDTRANS => $this->midtrans_enabled
                && filled($this->midtrans_server_key)
                && filled($this->midtrans_client_key),
                
            self::GATEWAY_XENDIT => $this->xendit_enabled
                && filled($this->xendit_secret_key)
                && filled($this->xendit_public_key),

            // QRIS Ready jika diaktifkan dan gambar sudah diunggah
            self::GATEWAY_QRIS => $this->qris_manual_enabled
                && filled($this->qris_manual_image),

            default => false,
        };
    }

    public function midtransConfig(): array
    {
        return [
            'enabled' => $this->isGatewayReady(self::GATEWAY_MIDTRANS),
            'server_key' => $this->midtrans_server_key,
            'client_key' => $this->midtrans_client_key,
            'is_production' => $this->midtrans_production,
        ];
    }

    public function xenditConfig(): array
    {
        return [
            'enabled' => $this->isGatewayReady(self::GATEWAY_XENDIT),
            'secret_key' => $this->xendit_secret_key,
            'public_key' => $this->xendit_public_key,
            'is_production' => $this->xendit_production,
        ];
    }

    /**
     * Konfigurasi QRIS Manual
     */
    public function qrisConfig(): array
    {
        return [
            'enabled' => $this->isGatewayReady(self::GATEWAY_QRIS),
            'image_url' => $this->qris_manual_image ? asset('storage/payments/' . $this->qris_manual_image) : null,
        ];
    }
}