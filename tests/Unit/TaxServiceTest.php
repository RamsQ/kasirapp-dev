<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\TaxService;
use Tests\TestCase;

class TaxServiceTest extends TestCase
{
    public function test_disabled_tax_returns_the_taxable_total_unchanged(): void
    {
        $setting = new Setting([
            'tax_enabled' => false,
            'tax_rate' => 11,
        ]);

        $result = app(TaxService::class)->calculate(100000, $setting);

        $this->assertSame([
            'enabled' => false,
            'rate' => 0.0,
            'taxable' => 100000,
            'amount' => 0,
            'total' => 100000,
        ], $result);
    }

    public function test_tax_is_calculated_after_discount_and_added_to_total(): void
    {
        $setting = new Setting([
            'tax_enabled' => true,
            'tax_rate' => 11,
        ]);

        $result = app(TaxService::class)->calculate(90000, $setting);

        $this->assertSame(11.0, $result['rate']);
        $this->assertSame(90000, $result['taxable']);
        $this->assertSame(9900, $result['amount']);
        $this->assertSame(99900, $result['total']);
    }

    public function test_tax_is_rounded_to_the_nearest_rupiah(): void
    {
        $setting = new Setting([
            'tax_enabled' => true,
            'tax_rate' => 10,
        ]);

        $result = app(TaxService::class)->calculate(10055, $setting);

        $this->assertSame(1006, $result['amount']);
        $this->assertSame(11061, $result['total']);
    }

    public function test_decimal_taxable_input_is_rounded_before_tax_is_applied(): void
    {
        $setting = new Setting([
            'tax_enabled' => true,
            'tax_rate' => 10,
        ]);

        $result = app(TaxService::class)->calculate(100.5, $setting);

        $this->assertSame(101, $result['taxable']);
        $this->assertSame(10, $result['amount']);
        $this->assertSame(111, $result['total']);
    }
}
