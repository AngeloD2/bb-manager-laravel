<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\SecureShareLink;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Every admin endpoint serving a resource wraps it in `data`.
 *
 * These drifted apart once already: collections were wrapped, single resources
 * were not, and the vault's collection was served bare — which silently gave
 * the Expo client an empty list, since it reads `.data` everywhere.
 */
class ResponseEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'name'     => 'Admin',
            'username' => 'admin-' . uniqid(),
            'password' => Hash::make('password'),
        ]);
        $this->withToken($user->createToken('admin-token', ['admin'])->plainTextToken);
    }

    /** @test */
    public function every_collection_endpoint_is_wrapped_in_data(): void
    {
        $loop = MediaLoop::create(['name' => 'Promo']);
        MediaAsset::create([
            'name' => 'Ad', 'file_path' => 'media/a.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $loop->id, 'size_bytes' => 10, 'duration_secs' => 10,
            'is_synced' => true, 'play_spots_remaining' => 1,
        ]);
        Campaign::create(['name' => 'October']);
        Zone::create(['name' => 'Downtown Core']);
        SecureShareLink::create([
            'label' => 'Proof', 'loop_id' => $loop->id, 'token' => 'abc12345',
            'password_hash' => Hash::make('123456'), 'expires_at' => now()->addDay(),
        ]);

        foreach ([
            '/api/v1/admin/loops',
            '/api/v1/admin/assets',
            '/api/v1/admin/campaigns',
            '/api/v1/admin/billboards',
            '/api/v1/admin/vault/links',
            '/api/v1/admin/zones',
        ] as $url) {
            $body = $this->getJson($url)->assertOk()->json();

            $this->assertArrayHasKey('data', $body, "{$url} is not wrapped in data");
            $this->assertIsArray($body['data'], "{$url} data is not a list");
        }
    }

    /** @test */
    public function a_created_resource_is_wrapped_in_data(): void
    {
        $this->postJson('/api/v1/admin/campaigns', ['name' => 'November'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'November')
            ->assertJsonMissingPath('name');
    }

    /** @test */
    public function an_updated_resource_is_wrapped_in_data(): void
    {
        $campaign = Campaign::create(['name' => 'October']);

        $this->putJson("/api/v1/admin/campaigns/{$campaign->id}", ['name' => 'October (revised)'])
            ->assertOk()
            ->assertJsonPath('data.name', 'October (revised)')
            ->assertJsonMissingPath('name');
    }
}
