<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BillboardControllerTest extends TestCase
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
    public function it_creates_a_billboard_with_timezone_defaulting_to_utc_if_omitted(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/billboards', [
                'name' => 'Billboard No Timezone',
                'active_hours_start' => '07:00',
                'active_hours_end' => '22:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.billboard.timezone', 'UTC');

        $this->assertDatabaseHas('billboards', [
            'name' => 'Billboard No Timezone',
            'timezone' => 'UTC',
        ]);
    }

    /** @test */
    public function it_creates_a_billboard_with_timezone_defaulting_to_utc_if_passed_as_null(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/billboards', [
                'name' => 'Billboard Null Timezone',
                'timezone' => null,
                'active_hours_start' => '07:00',
                'active_hours_end' => '22:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.billboard.timezone', 'UTC');

        $this->assertDatabaseHas('billboards', [
            'name' => 'Billboard Null Timezone',
            'timezone' => 'UTC',
        ]);
    }

    /** @test */
    public function it_creates_a_billboard_with_custom_timezone(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/billboards', [
                'name' => 'Billboard NY Timezone',
                'timezone' => 'America/New_York',
                'active_hours_start' => '07:00',
                'active_hours_end' => '22:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.billboard.timezone', 'America/New_York');

        $this->assertDatabaseHas('billboards', [
            'name' => 'Billboard NY Timezone',
            'timezone' => 'America/New_York',
        ]);
    }

    /** @test */
    public function it_updates_a_billboard_and_resets_timezone_to_utc_if_passed_as_null(): void
    {
        $billboard = Billboard::create([
            'name' => 'Billboard Update Timezone',
            'timezone' => 'America/Los_Angeles',
        ]);

        $this->actAsAdmin()
            ->putJson("/api/v1/admin/billboards/{$billboard->id}", [
                'name' => 'Billboard Updated Name',
                'timezone' => null,
            ])
            ->assertOk();

        $this->assertDatabaseHas('billboards', [
            'id' => $billboard->id,
            'name' => 'Billboard Updated Name',
            'timezone' => 'UTC',
        ]);
    }

    private function actAsAdmin(): static
    {
        $token = $this->adminUser->createToken('admin-token', ['admin'])->plainTextToken;
        return $this->withToken($token);
    }
}
