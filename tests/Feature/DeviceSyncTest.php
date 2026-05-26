<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Models\TimelineOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceSyncTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = Device::create([
            'name'     => 'Board Alpha',
            'location' => 'Main Street & 5th',
            'geo_zone' => 'Downtown Core',
        ]);
    }

    // ── Auth guard ────────────────────────────────────────────────────────────

    /** @test */
    public function unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/sync')->assertUnauthorized();
    }

    /** @test */
    public function non_device_token_returns_401(): void
    {
        // Token with wrong ability
        $token = $this->device->createToken('admin-token', ['admin:all'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/sync')->assertStatus(403);
    }

    // ── Sync payload ──────────────────────────────────────────────────────────

    /** @test */
    public function sync_returns_folders_and_eligible_assets(): void
    {
        $this->actAsDevice();

        $folder = MediaFolder::create(['name' => 'Promo', 'is_fallback' => false]);
        MediaAsset::create([
            'name' => 'Nike Ad', 'file_path' => 'media/nike.mp4', 'file_type' => 'VIDEO',
            'folder_id' => $folder->id, 'size_bytes' => 1000, 'duration_secs' => 10,
            'is_synced' => true, 'play_tokens_remaining' => 50,
        ]);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'device',
                    'folders',
                    'eligible_assets',
                    'fallback_assets',
                    'pending_overrides',
                    'synced_at',
                ],
            ])
            ->assertJsonCount(1, 'data.eligible_assets');
    }

    /** @test */
    public function exhausted_assets_are_excluded_from_eligible_assets(): void
    {
        $this->actAsDevice();

        $folder = MediaFolder::create(['name' => 'Promo', 'is_fallback' => false]);
        MediaAsset::create([
            'name' => 'Exhausted Ad', 'file_path' => 'media/x.mp4', 'file_type' => 'VIDEO',
            'folder_id' => $folder->id, 'size_bytes' => 1000, 'duration_secs' => 10,
            'is_synced' => true, 'play_tokens_remaining' => 0,   // exhausted
        ]);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonCount(0, 'data.eligible_assets');
    }

    /** @test */
    public function fallback_assets_appear_in_fallback_collection(): void
    {
        $this->actAsDevice();

        $fallback = MediaFolder::create(['name' => 'Filler', 'is_fallback' => true]);
        MediaAsset::create([
            'name' => 'Filler', 'file_path' => 'media/f.gif', 'file_type' => 'GIF',
            'folder_id' => $fallback->id, 'size_bytes' => 500, 'duration_secs' => 8,
            'is_synced' => true, 'play_tokens_remaining' => 0,
        ]);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonCount(1, 'data.fallback_assets')
            ->assertJsonCount(0, 'data.eligible_assets');
    }

    // ── Override delivery ─────────────────────────────────────────────────────

    /** @test */
    public function pending_overrides_are_delivered_then_marked_consumed(): void
    {
        $this->actAsDevice();

        $asset    = $this->makeSyncedAsset();
        $override = TimelineOverride::create([
            'asset_id'  => $asset->id,
            'device_id' => $this->device->id,
            'consumed'  => false,
        ]);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonCount(1, 'data.pending_overrides');

        $this->assertDatabaseHas('timeline_overrides', [
            'id'       => $override->id,
            'consumed' => true,
        ]);
    }

    /** @test */
    public function consumed_overrides_are_not_redelivered_on_subsequent_sync(): void
    {
        $this->actAsDevice();

        $asset = $this->makeSyncedAsset();
        TimelineOverride::create(['asset_id' => $asset->id, 'device_id' => $this->device->id, 'consumed' => true]);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonCount(0, 'data.pending_overrides');
    }

    // ── Heartbeat ─────────────────────────────────────────────────────────────

    /** @test */
    public function sync_updates_device_last_seen_at(): void
    {
        $this->actAsDevice();

        $this->assertNull($this->device->last_seen_at);

        $this->getJson('/api/v1/sync')->assertOk();

        $this->assertNotNull($this->device->fresh()->last_seen_at);
        $this->assertTrue($this->device->fresh()->is_online);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function actAsDevice(): void
    {
        $token = $this->device->createToken('board', ['device:sync', 'device:log'])->plainTextToken;
        $this->withToken($token);
    }

    private function makeSyncedAsset(): MediaAsset
    {
        $folder = MediaFolder::create(['name' => 'Test Folder', 'is_fallback' => false]);

        return MediaAsset::create([
            'name'                  => 'Test Asset',
            'file_path'             => 'media/test.mp4',
            'file_type'             => 'VIDEO',
            'folder_id'             => $folder->id,
            'size_bytes'            => 1000,
            'duration_secs'         => 10,
            'is_synced'             => true,
            'play_tokens_remaining' => 50,
        ]);
    }
}
