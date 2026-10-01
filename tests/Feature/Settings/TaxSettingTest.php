<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaxSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('settings.index', 'web');
        Permission::findOrCreate('settings.cgos', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_authorized_user_can_enable_tax_with_a_rate(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('settings.index', 'settings.cgos');

        $this->actingAs($user)
            ->post(route('settings.update'), [
                'cogs_method' => 'AVERAGE',
                'tax_enabled' => true,
                'tax_rate' => 11,
                'kitchen_display_enabled' => false,
                'kitchen_display_payment_status' => 'both',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('settings', [
            'tax_enabled' => true,
            'tax_rate' => 11,
        ]);
    }

    public function test_enabled_tax_requires_a_valid_rate(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('settings.index', 'settings.cgos');

        $this->actingAs($user)
            ->postJson(route('settings.update'), [
                'cogs_method' => 'AVERAGE',
                'tax_enabled' => true,
                'tax_rate' => 101,
                'kitchen_display_enabled' => false,
                'kitchen_display_payment_status' => 'both',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tax_rate');
    }
}
