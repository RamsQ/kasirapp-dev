<?php

namespace Tests\Feature\Crm;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerPhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_number_is_stored_as_string_and_keeps_leading_zero(): void
    {
        $columns = collect(Schema::getColumns('customers'))->keyBy('name');

        $this->assertSame('varchar', strtolower((string) $columns['no_telp']['type_name']));
    }

    /**
     * @dataProvider phoneProvider
     */
    public function test_phone_values_round_trip_without_numeric_truncation(string $input): void
    {
        $customer = Customer::create([
            'name' => 'Pelanggan',
            'no_telp' => $input,
            'address' => 'Alamat',
        ]);

        $raw = DB::table('customers')->where('id', $customer->id)->value('no_telp');

        $this->assertSame($input, $raw);
    }

    public static function phoneProvider(): array
    {
        return [
            'local with leading zero' => ['081234567890'],
            'country code' => ['6281234567890'],
            'plus prefix' => ['+6281234567890'],
            'short local' => ['0812111'],
        ];
    }
}
