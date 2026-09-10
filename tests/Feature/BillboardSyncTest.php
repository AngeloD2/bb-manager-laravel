<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\TimelineOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Illuminate\Support\Facades\Event;
use App\Events\PlaybackStarted;

class BillboardSyncTest extends TestCase
{
    use RefreshDatabase;

    private Billboard $billboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billboard = Billboard::create([
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
    public function non_billboard_token_returns_401(): void
    {
        // Token with wrong ability
        $token = $this->billboard->createToken('admin-token', ['admin:all'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/sync')->assertStatus(403);
    }

    // ── Sync payload ──────────────────────────────────────────────────────────

    /** @test */
    public function sync_returns_folders_and_eligible_assets(): void
    {
        $this->actAsBillboard();

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
                    'billboard',
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
        $this->actAsBillboard();

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
        $this->actAsBillboard();

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
        $this->actAsBillboard();

        $asset    = $this->makeSyncedAsset();
        $override = TimelineOverride::create([
            'asset_id'  => $asset->id,
            'billboard_id' => $this->billboard->id,
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
        $this->actAsBillboard();

        $asset = $this->makeSyncedAsset();
        TimelineOverride::create(['asset_id' => $asset->id, 'billboard_id' => $this->billboard->id, 'consumed' => true]);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonCount(0, 'data.pending_overrides');
    }

    // ── Heartbeat ─────────────────────────────────────────────────────────────

    /** @test */
    public function billboard_token_must_have_sync_ability(): void
    {
        $token = $this->billboard->createToken('board', ['billboard:log'])->plainTextToken; // Wrong ability

        $this->withToken($token)->getJson('/api/v1/sync')
             ->assertStatus(403)
             ->assertJsonPath('message', 'Token missing ability: billboard:sync');
    }

    /** @test */
    public function sync_updates_billboard_last_seen_at(): void
    {
        $this->actAsBillboard();

        $this->assertNull($this->billboard->last_seen_at);

        $this->getJson('/api/v1/sync')->assertOk();

        $this->assertNotNull($this->billboard->fresh()->last_seen_at);
        $this->assertTrue($this->billboard->fresh()->is_online);
    }

    /** @test */
    public function sync_excludes_loops_and_assets_not_assigned_to_this_billboard(): void
    {
        $this->actAsBillboard();

        // Non-global loop with no billboard assignments
        $otherLoop1 = MediaLoop::create(['name' => 'Other Promo', 'is_fallback' => false, 'is_global' => false]);
        MediaAsset::create([
            'name' => 'Other Nike Ad', 'file_path' => 'media/other-nike.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $otherLoop1->id, 'size_bytes' => 1000, 'duration_secs' => 10,
            'is_synced' => true, 'play_spots_remaining' => 50,
        ]);

        // Non-global loop assigned to a different billboard
        $otherBillboard = Billboard::create([
            'name'     => 'Board Beta',
            'location' => 'Highway 1',
            'geo_zone' => 'West Coast Highways',
        ]);
        $otherLoop2 = MediaLoop::create(['name' => 'Beta Promo', 'is_fallback' => false, 'is_global' => false, 'assigned_billboards' => [$otherBillboard->id]]);
        MediaAsset::create([
            'name' => 'Beta Nike Ad', 'file_path' => 'media/beta-nike.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $otherLoop2->id, 'size_bytes' => 1000, 'duration_secs' => 10,
            'is_synced' => true, 'play_spots_remaining' => 50,
        ]);

        // Non-global loop explicitly assigned to this billboard
        $myLoop = MediaLoop::create(['name' => 'My Promo', 'is_fallback' => false, 'is_global' => false, 'assigned_billboards' => [$this->billboard->id]]);
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
        $this->actAsBillboard();

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
                    'quota'    => ['as_of', 'seconds_per_spot', 'billboard', 'assets', 'loops'],
                ],
            ]);

        $res->assertJsonPath('data.schedule.primary.0.asset_id', $asset->id);
        $res->assertJsonPath('data.quota.assets.' . $asset->id . '.play_spots_remaining', 50);
        $res->assertJsonPath('data.quota.loops.' . $loop->id . '.max_daily_spots', 50);
    }

    /** @test */
    public function sync_payload_includes_bundle_and_fallback_organization(): void
    {
        $this->actAsBillboard();

        $campaign = \App\Models\Campaign::create(['name' => 'Acme Campaign']);

        $bundleLoop = MediaLoop::create([
            'name' => 'Bundle 1',
            'campaign_id' => $campaign->id,
            'is_fallback' => false,
            'is_global' => true,
            'is_bundle' => true,
        ]);

        $campaignFallbackLoop = MediaLoop::create([
            'name' => 'Acme Fallback',
            'campaign_id' => $campaign->id,
            'is_fallback' => true,
            'is_global' => true,
            'is_bundle' => false,
        ]);

        $globalFallbackLoop = MediaLoop::create([
            'name' => 'Global Fallback',
            'campaign_id' => null,
            'is_fallback' => true,
            'is_global' => true,
            'is_bundle' => false,
        ]);

        $asset1 = MediaAsset::create([
            'name' => 'Part 1',
            'file_path' => 'media/part1.mp4',
            'file_type' => 'VIDEO',
            'loop_id' => $bundleLoop->id,
            'order_index' => null, // nullable test
            'size_bytes' => 1000,
            'duration_secs' => 10,
            'is_synced' => true,
            'play_spots_remaining' => 50,
        ]);

        $asset2 = MediaAsset::create([
            'name' => 'Acme FB Asset',
            'file_path' => 'media/acme_fb.mp4',
            'file_type' => 'VIDEO',
            'loop_id' => $campaignFallbackLoop->id,
            'order_index' => 0,
            'size_bytes' => 1000,
            'duration_secs' => 10,
            'is_synced' => true,
            'play_spots_remaining' => 50,
        ]);

        $asset3 = MediaAsset::create([
            'name' => 'Global FB Asset',
            'file_path' => 'media/global_fb.mp4',
            'file_type' => 'VIDEO',
            'loop_id' => $globalFallbackLoop->id,
            'order_index' => 0,
            'size_bytes' => 1000,
            'duration_secs' => 10,
            'is_synced' => true,
            'play_spots_remaining' => 50,
        ]);

        $res = $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'schedule' => ['primary', 'fallback', 'campaign_fallback', 'global_fallback', 'loops'],
                    'quota'    => ['loops'],
                ],
            ]);

        // Loop metadata
        $res->assertJsonPath('data.schedule.loops.' . $bundleLoop->id . '.is_bundle', true);
        $res->assertJsonPath('data.quota.loops.' . $bundleLoop->id . '.is_bundle', true);
        $res->assertJsonPath('data.quota.loops.' . $bundleLoop->id . '.campaign_id', $campaign->id);

        // Asset order_index can be null
        $res->assertJsonPath('data.schedule.primary.0.asset_id', $asset1->id);
        $res->assertJsonPath('data.schedule.primary.0.order_index', null);

        // Fallbacks properly partitioned
        $res->assertJsonPath('data.schedule.campaign_fallback.0.asset_id', $asset2->id);
        $res->assertJsonPath('data.schedule.campaign_fallback.0.campaign_id', $campaign->id);
        $res->assertJsonPath('data.schedule.global_fallback.0.asset_id', $asset3->id);
        $res->assertJsonPath('data.schedule.global_fallback.0.campaign_id', null);
    }

    /** @test */
    public function ping_returns_ok_and_server_time(): void
    {
        $this->actAsBillboard();

        $this->getJson('/api/v1/sync/ping')
            ->assertOk()
            ->assertJsonStructure(['ok', 'server_time'])
            ->assertJsonPath('ok', true);
    }

    /** @test */
    public function sync_returns_standalone_assets(): void
    {
        $this->actAsBillboard();

        // Standalone asset assigned to this billboard
        $standaloneAssigned = MediaAsset::create([
            'name'                  => 'Standalone Assigned',
            'file_path'             => 'media/sa.mp4',
            'file_type'             => 'VIDEO',
            'loop_id'               => null,
            'size_bytes'            => 1000,
            'duration_secs'         => 10,
            'is_synced'             => true,
            'assigned_billboards'      => [$this->billboard->id],
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
        $this->actAsBillboard();

        $otherBillboard = Billboard::create([
            'name'     => 'Board Beta',
            'location' => 'Highway 1',
            'geo_zone' => 'West Coast Highways',
        ]);

        // Standalone asset assigned to another billboard
        MediaAsset::create([
            'name'                  => 'Standalone Other',
            'file_path'             => 'media/so.mp4',
            'file_type'             => 'VIDEO',
            'loop_id'               => null,
            'size_bytes'            => 1000,
            'duration_secs'         => 10,
            'is_synced'             => true,
            'assigned_billboards'      => [$otherBillboard->id],
            'play_spots_remaining'  => 50,
        ]);

        // Standalone asset not assigned to any billboard (not global)
        MediaAsset::create([
            'name'                  => 'Standalone Unassigned',
            'file_path'             => 'media/su.mp4',
            'file_type'             => 'VIDEO',
            'loop_id'               => null,
            'size_bytes'            => 1000,
            'duration_secs'         => 10,
            'is_synced'             => true,
            'is_global'             => false,
            'assigned_billboards'      => null,
            'play_spots_remaining'  => 50,
        ]);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonCount(0, 'data.standalone_assets');
    }

    /** @test */
    public function the_billboard_object_carries_no_zone(): void
    {
        $this->actAsBillboard();

        $billboard = $this->getJson('/api/v1/sync')->assertOk()->json('data.billboard');

        // Zone targeting is enforced server-side at sync; the player is
        // deliberately zone-unaware. Asserted by key, not by structure: a
        // structure assertion passed happily while this key was present but
        // permanently null after the geo_zone column was dropped.
        $this->assertSame(['id', 'name', 'is_frozen', 'is_blacked_out'], array_keys($billboard));
        $this->assertArrayNotHasKey('geo_zone', $billboard);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function actAsBillboard(): void
    {
        $token = $this->billboard->createToken('board', ['billboard:sync', 'billboard:log'])->plainTextToken;
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
    public function billboard_can_report_playback_start(): void
    {
        Event::fake();

        $this->actAsBillboard();
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
                    'billboard_id',
                    'asset_id',
                    'started_at',
                ],
            ]);

        Event::assertDispatched(
            PlaybackStarted::class,
            function (PlaybackStarted $event) use ($asset, $startedAt) {
                return $event->asset->id === $asset->id &&
                       $event->billboard->id === $this->billboard->id &&
                       $event->startedAt === $startedAt;
            }
        );
    }
}
