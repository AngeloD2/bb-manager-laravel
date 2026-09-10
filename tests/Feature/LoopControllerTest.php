<?php

namespace Tests\Feature;

use App\Models\MediaLoop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoopControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): static
    {
        $adminUser = User::create([
            'name'     => 'Admin Test User',
            'username' => 'admin-test-' . uniqid(),
            'password' => Hash::make('password'),
        ]);
        $token = $adminUser->createToken('admin-token', ['admin'])->plainTextToken;

        return $this->withToken($token);
    }

    /** @test */
    public function it_can_create_a_loop_with_is_bundle_flag(): void
    {
        $res = $this->actAsAdmin()->postJson('/api/v1/admin/loops', [
            'name'      => 'Holiday Bundle',
            'is_bundle' => true,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.name', 'Holiday Bundle')
            ->assertJsonPath('data.is_bundle', true);

        $this->assertDatabaseHas('media_loops', [
            'name'      => 'Holiday Bundle',
            'is_bundle' => true,
        ]);
    }

    /** @test */
    public function it_defaults_is_bundle_to_false_when_omitted(): void
    {
        $res = $this->actAsAdmin()->postJson('/api/v1/admin/loops', [
            'name' => 'Standard Loop',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.name', 'Standard Loop')
            ->assertJsonPath('data.is_bundle', false);

        $this->assertDatabaseHas('media_loops', [
            'name'      => 'Standard Loop',
            'is_bundle' => false,
        ]);
    }

    /** @test */
    public function it_can_update_a_loop_to_become_a_bundle(): void
    {
        $loop = MediaLoop::create([
            'name'      => 'Existing Loop',
            'is_bundle' => false,
        ]);

        $res = $this->actAsAdmin()->putJson("/api/v1/admin/loops/{$loop->id}", [
            'is_bundle' => true,
        ]);

        $res->assertOk()
            ->assertJsonPath('data.is_bundle', true);

        $this->assertTrue($loop->fresh()->is_bundle);
    }

    /** @test */
    public function loop_resource_includes_is_bundle_and_order_index(): void
    {
        $loop = MediaLoop::create([
            'name'        => 'Resource Loop',
            'is_bundle'   => true,
            'order_index' => null,
        ]);

        $res = $this->actAsAdmin()->getJson('/api/v1/admin/loops');

        $res->assertOk();
        $item = collect($res->json('data'))->firstWhere('id', $loop->id);

        $this->assertNotNull($item);
        $this->assertTrue($item['is_bundle']);
        $this->assertNull($item['order_index']);
    }
}
