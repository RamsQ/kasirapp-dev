<?php

namespace App\Services;

use App\Models\CrmCampaign;
use App\Models\Customer;
use App\Models\CustomerVoucher;
use App\Models\PromoCode;
use Illuminate\Support\Str;

/**
 * Definisi voucher untuk campaign CRM.
 *
 * `customer_vouchers` bersifat pribadi (satu kode milik satu pelanggan), jadi
 * campaign tidak boleh memakai voucher milik pelanggan tertentu: kode tersebut
 * akan bocor ke seluruh penerima segment dan hanya bisa ditukarkan oleh
 * pemiliknya. Karena itu campaign punya dua mode sendiri:
 *
 * - per_recipient: terbitkan satu voucher pribadi berkode unik per penerima.
 *   Langsung bisa dipakai di checkout karena alur voucher sudah per-pelanggan.
 * - shared: satu kode promo bersama untuk semua penerima, disimpan di
 *   `promo_codes` dan dikenali saat checkout.
 */
class CrmVoucherService
{
    public const MODE_NONE = 'none';

    public const MODE_PER_RECIPIENT = 'per_recipient';

    public const MODE_SHARED = 'shared';

    public function mode(CrmCampaign $campaign): string
    {
        return $campaign->voucher_mode ?: self::MODE_NONE;
    }

    public function isEnabled(CrmCampaign $campaign): bool
    {
        return in_array($this->mode($campaign), [self::MODE_PER_RECIPIENT, self::MODE_SHARED], true);
    }

    /**
     * Kode yang disisipkan ke pesan. Untuk dry-run memakai kode contoh supaya
     * admin bisa melihat bentuk pesan tanpa menerbitkan voucher sungguhan.
     */
    public function codeForMessage(CrmCampaign $campaign, Customer $customer, bool $issue = false): string
    {
        return match ($this->mode($campaign)) {
            self::MODE_SHARED => (string) $this->ensureSharedPromoCode($campaign)->code,
            self::MODE_PER_RECIPIENT => $issue
                ? $this->issueForRecipient($customer, $campaign)->code
                : $this->sampleCode($campaign),
            default => '',
        };
    }

    public function nameForMessage(CrmCampaign $campaign): string
    {
        return (string) ($campaign->voucher_name ?: '');
    }

    /**
     * Terbitkan voucher pribadi dengan kode unik. Idempoten: bila penerima sudah
     * punya voucher dari campaign ini (mis. setelah retry), kode lama dipakai
     * kembali supaya tidak endfor voucher dan pesan tetap konsisten.
     *
     * Batas pemakaian diambil dari campaign. Campaign yang tidak
     * menyetelnya akan punya `usage_limit = 1`, jadi perilakunya sama dengan
     * sebelum fitur ini ada: sekali pakai.
     */
    public function issueForRecipient(Customer $customer, CrmCampaign $campaign): CustomerVoucher
    {
        $existing = CustomerVoucher::where('customer_id', $customer->id)
            ->where('source_campaign_id', $campaign->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return CustomerVoucher::create([
            'customer_id' => $customer->id,
            'source_campaign_id' => $campaign->id,
            'code' => $this->generateUniqueCode($campaign, $customer),
            'name' => $campaign->voucher_name ?: 'Voucher Campaign '.$campaign->name,
            'type' => $campaign->voucher_type ?: 'fixed',
            'value' => $campaign->voucher_value ?: 0,
            'min_spend' => $campaign->voucher_min_spend ?: 0,
            'expires_at' => $campaign->voucher_expires_at,
            'status' => 'active',
            'usage_limit' => max(1, (int) ($campaign->voucher_usage_limit ?: 1)),
            'used_count' => 0,
        ]);
    }

    /**
     * Kode promo bersama dibuat/diperbarui mengikuti definisi campaign.
     */
    public function ensureSharedPromoCode(CrmCampaign $campaign): PromoCode
    {
        $code = strtoupper(trim((string) $campaign->shared_voucher_code));

        $attributes = [
            'name' => $campaign->voucher_name ?: 'Promo '.$campaign->name,
            'type' => $campaign->voucher_type ?: 'fixed',
            'value' => $campaign->voucher_value ?: 0,
            'min_spend' => $campaign->voucher_min_spend ?: 0,
            'expires_at' => $campaign->voucher_expires_at,
            'is_active' => true,
            'crm_campaign_id' => $campaign->id,
        ];

        return PromoCode::updateOrCreate(['code' => $code], $attributes);
    }

    /**
     * Bentuk kode contoh untuk preview: PREFIX-XXXXXX (huruf acak, bukan voucher nyata).
     */
    public function sampleCode(CrmCampaign $campaign): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $campaign->voucher_prefix) ?: 'PROMO');

        return substr($prefix, 0, 12).'-'.strtoupper(Str::random(6));
    }

    private function generateUniqueCode(CrmCampaign $campaign, Customer $customer): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $campaign->voucher_prefix) ?: 'PROMO');
        $prefix = substr($prefix, 0, 12);

        do {
            $code = $prefix.'-'.strtoupper(Str::random(6));
        } while (CustomerVoucher::whereRaw('UPPER(code) = ?', [$code])->exists()
            || PromoCode::whereRaw('UPPER(code) = ?', [$code])->exists());

        return $code;
    }
}
