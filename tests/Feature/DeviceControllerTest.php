<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'name'     => 'Admin User',
            'username' => 'admin-test',
            'password' => Hash::make('password'),
        ]);
    }

    /** @test */
    public function it_creates_a_device_with_timezone_defaulting_to_utc_if_omitted(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/devices', [
                'name' => 'Device No Timezone',
                'active_hours_start' => '07:00',
                'active_hours_end' => '22:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.device.timezone', 'UTC');

        $this->assertDatabaseHas('devices', [
            'name' => 'Device No Timezone',
            'timezone' => 'UTC',
        ]);
    }

    /** @test */
    public function it_creates_a_device_with_timezone_defaulting_to_utc_if_passed_as_null(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/devices', [
                'name' => 'Device Null Timezone',
                'timezone' => null,
                'active_hours_start' => '07:00',
                'active_hours_end' => '22:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.device.timezone', 'UTC');

        $this->assertDatabaseHas('devices', [
            'name' => 'Device Null Timezone',
            'timezone' => 'UTC',
        ]);
    }

    /** @test */
    public function it_creates_a_device_with_custom_timezone(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/devices', [
                'name' => 'Device NY Timezone',
                'timezone' => 'America/New_York',
                'active_hours_start' => '07:00',
                'active_hours_end' => '22:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.device.timezone', 'America/New_York');

        $this->assertDatabaseHas('devices', [
            'name' => 'Device NY Timezone',
            'timezone' => 'America/New_York',
        ]);
    }

    /** @test */
    public function it_updates_a_device_and_resets_timezone_to_utc_if_passed_as_null(): void
    {
        $device = Device::create([
            'name' => 'Device Update Timezone',
            'timezone' => 'America/Los_Angeles',
        ]);

        $this->actAsAdmin()
            ->putJson("/api/v1/admin/devices/{$device->id}", [
                'name' => 'Device Updated Name',
                'timezone' => null,
            ])
            ->assertOk();

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'name' => 'Device Updated Name',
            'timezone' => 'UTC',
        ]);
    }

    private function actAsAdmin(): static
    {
        $spot = $this->adminUser->createToken('admin-spot', ['admin'])->plainTextToken;
        return $this->withToken($spot);
    }
}
