<?php

namespace App\Services;

use App\Models\Setting;

class TaxService
{
    /**
     * Calculate an additional percentage tax after discounts.
     * Currency is rounded to the nearest rupiah.
     *
     * @return array{enabled: bool, rate: float, taxable: int, amount: int, total: int}
     */
    public function calculate(float $taxableAmount, ?Setting $setting = null): array
    {
        $setting ??= Setting::query()->first();
        $enabled = (bool) ($setting?->tax_enabled);
        $rate = $enabled ? max(0, min(100, (float) $setting?->tax_rate)) : 0.0;
        $taxable = (int) round(max(0, $taxableAmount), 0, PHP_ROUND_HALF_UP);
        $amount = $enabled
            ? (int) round($taxable * $rate / 100, 0, PHP_ROUND_HALF_UP)
            : 0;

        return [
            'enabled' => $enabled,
            'rate' => $rate,
            'taxable' => $taxable,
            'amount' => $amount,
            'total' => $taxable + $amount,
        ];
    }
}
