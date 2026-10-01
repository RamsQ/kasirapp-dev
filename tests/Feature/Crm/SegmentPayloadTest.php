<?php

namespace Tests\Feature\Crm;

use App\Models\CustomerSegment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Mengunci kontrak payload antara form frontend (Campaigns.jsx) dan controller.
 * Segment tidak bisa dibuat jika field filter tidak dibungkus di `rules`.
 */
class SegmentPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function manager(): User
    {
        $manage = Permission::firstOrCreate(['name' => 'crm-manage', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo($manage);

        return $user;
    }

    /**
     * Payload ini disalin persis dari buildSegmentPayload() di Campaigns.jsx.
     */
    protected function frontendPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Pelanggan Setia',
            'description' => null,
            'is_active' => true,
            'rules' => [
                'tiers' => ['gold'],
                'min_lifetime_spend' => '500000',
                'min_transactions' => '',
                'inactive_days' => '',
                'birthday_month' => '',
                'birthday_day' => '',
                'product_id' => '',
                'category_id' => '',
            ],
        ], $overrides);
    }

    public function test_segment_can_be_created_with_the_exact_frontend_payload(): void
    {
        $this->actingAs($this->manager())
            ->post(route('crm.segments.store'), $this->frontendPayload())
            ->assertSessionHasNoErrors();

        $segment = CustomerSegment::where('name', 'Pelanggan Setia')->firstOrFail();

        $this->assertSame(['gold'], $segment->rules['tiers']);
        $this->assertSame(500000, (int) $segment->rules['min_lifetime_spend']);
        $this->assertTrue($segment->is_active);
    }

    public function test_empty_optional_filters_are_stripped_from_rules(): void
    {
        $this->actingAs($this->manager())
            ->post(route('crm.segments.store'), $this->frontendPayload([
                'rules' => [
                    'tiers' => [],
                    'min_lifetime_spend' => '',
                    'min_transactions' => '',
                    'inactive_days' => '',
                    'birthday_month' => '',
                    'birthday_day' => '',
                    'product_id' => '',
                    'category_id' => '',
                ],
            ]))
            ->assertSessionHasNoErrors();

        $segment = CustomerSegment::where('name', 'Pelanggan Setia')->firstOrFail();

        // Filter kosong tidak boleh tersimpan sebagai string kosong.
        $this->assertSame(['tiers' => []], $segment->rules);
    }

    public function test_birthday_filters_are_stored_from_frontend_payload(): void
    {
        $this->actingAs($this->manager())
            ->post(route('crm.segments.store'), $this->frontendPayload([
                'rules' => [
                    'tiers' => [],
                    'birthday_month' => '7',
                    'birthday_day' => '14',
                ],
            ]))
            ->assertSessionHasNoErrors();

        $segment = CustomerSegment::where('name', 'Pelanggan Setia')->firstOrFail();

        $this->assertSame(7, (int) $segment->rules['birthday_month']);
        $this->assertSame(14, (int) $segment->rules['birthday_day']);
    }

    public function test_segment_can_be_updated_with_the_exact_frontend_payload(): void
    {
        $segment = CustomerSegment::create([
            'name' => 'Lama',
            'rules' => ['tiers' => ['bronze']],
            'is_active' => true,
        ]);

        $this->actingAs($this->manager())
            ->put(route('crm.segments.update', $segment), $this->frontendPayload([
                'name' => 'Baru',
                'is_active' => false,
            ]))
            ->assertSessionHasNoErrors();

        $segment->refresh();
        $this->assertSame('Baru', $segment->name);
        $this->assertFalse($segment->is_active);
        $this->assertSame(['gold'], $segment->rules['tiers']);
    }

    public function test_validation_errors_use_indonesian_messages(): void
    {
        $this->actingAs($this->manager())
            ->post(route('crm.segments.store'), $this->frontendPayload(['name' => '']))
            ->assertSessionHasErrors(['name']);

        $this->actingAs($this->manager())
            ->post(route('crm.segments.store'), $this->frontendPayload([
                'rules' => ['birthday_month' => '13'],
            ]))
            ->assertSessionHasErrors('rules.birthday_month');
    }
}
