<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\TimelineOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Support\Facades\Event;
use App\Events\PlaybackStarted;

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

        $loop = MediaLoop::create(['name' => 'Promo', 'is_fallback' => false, 'is_global' => true]);
        MediaAsset::create([
            'name' => 'Nike Ad', 'file_path' => 'media/nike.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $loop->id, 'size_bytes' => 1000, 'duration_secs' => 10,
            'is_synced' => true, 'play_spots_remaining' => 50,
        ]);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'device',
                    'loops',
                    'eligible_assets',
                    'fallback_assets',
                    'standalone_assets',
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

        $loop = MediaLoop::create(['name' => 'Promo', 'is_fallback' => false, 'is_global' => true]);
        MediaAsset::create([
            'name' => 'Exhausted Ad', 'file_path' => 'media/x.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $loop->id, 'size_bytes' => 1000, 'duration_secs' => 10,
            'is_synced' => true, 'play_spots_remaining' => 0,   // exhausted
        ]);

        $this->getJson('/api/v1/sync')
             ->assertOk()
             ->assertJsonCount(0, 'data.eligible_assets');
    }

    /** @test */
    public function fallback_assets_appear_in_fallback_collection(): void
    {
        $this->actAsDevice();

        $fallback = MediaLoop::create(['name' => 'Filler', 'is_fallback' => true, 'is_global' => true]);
        MediaAsset::create([
            'name' => 'Filler', 'file_path' => 'media/f.gif', 'file_type' => 'GIF',
            'loop_id' => $fallback->id, 'size_bytes' => 500, 'duration_secs' => 8,
            'is_synced' => true, 'play_spots_remaining' => 0,
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
    public function device_token_must_have_sync_ability(): void
    {
        $token = $this->device->createToken('board', ['device:log'])->plainTextToken; // Wrong ability

        $this->withToken($token)->getJson('/api/v1/sync')
             ->assertStatus(403)
             ->assertJsonPath('message', 'Token missing ability: device:sync');
    }

    /** @test */
    public function sync_updates_device_last_seen_at(): void
    {
        $this->actAsDevice();

        $this->assertNull($this->device->last_seen_at);

        $this->getJson('/api/v1/sync')->assertOk();

        $this->assertNotNull($this->device->fresh()->last_seen_at);
        $this->assertTrue($this->device->fresh()->is_online);
    }

    /** @test */
    public function sync_excludes_loops_and_assets_not_assigned_to_this_device(): void
    {
        $this->actAsDevice();

        // Non-global loop with no device assignments
        $otherLoop1 = MediaLoop::create(['name' => 'Other Promo', 'is_fallback' => false, 'is_global' => false]);
        MediaAsset::create([
            'name' => 'Other Nike Ad', 'file_path' => 'media/other-nike.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $otherLoop1->id, 'size_bytes' => 1000, 'duration_secs' => 10,
            'is_synced' => true, 'play_spots_remaining' => 50,
        ]);

        // Non-global loop assigned to a different device
        $otherDevice = Device::create([
            'name'     => 'Board Beta',
            'location' => 'Highway 1',
            'geo_zone' => 'West Coast Highways',
        ]);
        $otherLoop2 = MediaLoop::create(['name' => 'Beta Promo', 'is_fallback' => false, 'is_global' => false, 'assigned_devices' => [$otherDevice->id]]);
        MediaAsset::create([
            'name' => 'Beta Nike Ad', 'file_path' => 'media/beta-nike.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $otherLoop2->id, 'size_bytes' => 1000, 'duration_secs' => 10,
            'is_synced' => true, 'play_spots_remaining' => 50,
        ]);

        // Non-global loop explicitly assigned to this device
        $myLoop = MediaLoop::create(['name' => 'My Promo', 'is_fallback' => false, 'is_global' => false, 'assigned_devices' => [$this->device->id]]);
        MediaAsset::create([
            'name' => 'My Nike Ad', 'file_path' => 'media/my-nike.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $myLoop->id, 'size_bytes' => 1000, 'duration_secs' => 10,
            'is_synced' => true, 'play_spots_remaining' => 50,
        ]);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonCount(1, 'data.loops')
            ->assertJsonPath('data.loops.0.id', $myLoop->id)
            ->assertJsonCount(1, 'data.eligible_assets')
            ->assertJsonPath('data.eligible_assets.0.loop_id', $myLoop->id);
    }

    // ── Pre-baked schedule + quota (decoupled-brain payload) ──────────────────

    /** @test */
    public function sync_includes_prebaked_schedule_and_quota(): void
    {
        $this->actAsDevice();

        $loop  = MediaLoop::create(['name' => 'Promo', 'is_fallback' => false, 'is_global' => true, 'max_daily_spots' => 50]);
        $asset = MediaAsset::create([
            'name' => 'Nike Ad', 'file_path' => 'media/nike.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $loop->id, 'order_index' => 0, 'size_bytes' => 1000, 'duration_secs' => 10,
            'is_synced' => true, 'play_spots_remaining' => 50, 'max_daily_plays' => 20,
        ]);

        $res = $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'schedule' => ['primary', 'fallback'],
                    'quota'    => ['as_of', 'seconds_per_spot', 'device', 'assets', 'loops'],
                ],
            ]);

        $res->assertJsonPath('data.schedule.primary.0.asset_id', $asset->id);
        $res->assertJsonPath('data.quota.assets.' . $asset->id . '.play_spots_remaining', 50);
        $res->assertJsonPath('data.quota.loops.' . $loop->id . '.max_daily_spots', 50);
    }

    /** @test */
    public function ping_returns_ok_and_server_time(): void
    {
        $this->actAsDevice();

        $this->getJson('/api/v1/sync/ping')
            ->assertOk()
            ->assertJsonStructure(['ok', 'server_time'])
            ->assertJsonPath('ok', true);
    }

    /** @test */
    public function sync_returns_standalone_assets(): void
    {
        $this->actAsDevice();

        // Standalone asset assigned to this device
        $standaloneAssigned = MediaAsset::create([
            'name'                  => 'Standalone Assigned',
            'file_path'             => 'media/sa.mp4',
            'file_type'             => 'VIDEO',
            'loop_id'               => null,
            'size_bytes'            => 1000,
            'duration_secs'         => 10,
            'is_synced'             => true,
            'assigned_devices'      => [$this->device->id],
            'play_spots_remaining'  => 50,
        ]);

        // Global standalone asset
        $standaloneGlobal = MediaAsset::create([
            'name'                  => 'Standalone Global',
            'file_path'             => 'media/sg.mp4',
            'file_type'             => 'VIDEO',
            'loop_id'               => null,
            'size_bytes'            => 1000,
            'duration_secs'         => 10,
            'is_synced'             => true,
            'is_global'             => true,
            'play_spots_remaining'  => 50,
        ]);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonCount(2, 'data.standalone_assets')
            ->assertJsonPath('data.standalone_assets.0.id', $standaloneAssigned->id)
            ->assertJsonPath('data.standalone_assets.1.id', $standaloneGlobal->id);
    }

    /** @test */
    public function sync_excludes_unassigned_standalone_assets(): void
    {
        $this->actAsDevice();

        $otherDevice = Device::create([
            'name'     => 'Board Beta',
            'location' => 'Highway 1',
            'geo_zone' => 'West Coast Highways',
        ]);

        // Standalone asset assigned to another device
        MediaAsset::create([
            'name'                  => 'Standalone Other',
            'file_path'             => 'media/so.mp4',
            'file_type'             => 'VIDEO',
            'loop_id'               => null,
            'size_bytes'            => 1000,
            'duration_secs'         => 10,
            'is_synced'             => true,
            'assigned_devices'      => [$otherDevice->id],
            'play_spots_remaining'  => 50,
        ]);

        // Standalone asset not assigned to any device (not global)
        MediaAsset::create([
            'name'                  => 'Standalone Unassigned',
            'file_path'             => 'media/su.mp4',
            'file_type'             => 'VIDEO',
            'loop_id'               => null,
            'size_bytes'            => 1000,
            'duration_secs'         => 10,
            'is_synced'             => true,
            'is_global'             => false,
            'assigned_devices'      => null,
            'play_spots_remaining'  => 50,
        ]);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonCount(0, 'data.standalone_assets');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function actAsDevice(): void
    {
        $token = $this->device->createToken('board', ['device:sync', 'device:log'])->plainTextToken;
        $this->withToken($token);
    }

    private function makeSyncedAsset(): MediaAsset
    {
        $loop = MediaLoop::create(['name' => 'Test Loop', 'is_fallback' => false, 'is_global' => true]);

        return MediaAsset::create([
            'name'                  => 'Test Asset',
            'file_path'             => 'media/test.mp4',
            'file_type'             => 'VIDEO',
            'loop_id'             => $loop->id,
            'size_bytes'            => 1000,
            'duration_secs'         => 10,
            'is_synced'             => true,
            'play_spots_remaining' => 50,
        ]);
    }

    /** @test */
    public function device_can_report_playback_start(): void
    {
        Event::fake();

        $this->actAsDevice();
        $asset = $this->makeSyncedAsset();
        $startedAt = now()->toIso8601String();

        $this->postJson('/api/v1/playback/start', [
            'asset_id'   => $asset->id,
            'started_at' => $startedAt,
        ])
            ->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'device_id',
                    'asset_id',
                    'started_at',
                ],
            ]);

        Event::assertDispatched(
            PlaybackStarted::class,
            function (PlaybackStarted $event) use ($asset, $startedAt) {
                return $event->asset->id === $asset->id &&
                       $event->device->id === $this->device->id &&
                       $event->startedAt === $startedAt;
            }
        );
    }
}
