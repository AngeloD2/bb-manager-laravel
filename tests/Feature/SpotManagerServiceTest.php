<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\PlaybackLog;
use App\Services\ConstraintValidationService;
use App\Services\SpotManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpotManagerServiceTest extends TestCase
{
    use RefreshDatabase;

    private SpotManagerService $service;
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SpotManagerService::class);

        $this->device = Device::create([
            'name'     => 'Test Board Alpha',
            'location' => 'Downtown Core',
            'geo_zone' => 'Downtown Core',
        ]);
    }

    // ── Spot deduction ───────────────────────────────────────────────────────

    /** @test */
    public function it_deducts_one_token_per_accepted_log(): void
    {
        $asset = $this->makeAsset(spots: 10);

        $this->service->processBatch($this->device, [
            ['asset_id' => $asset->id, 'played_at' => now()->toIso8601String(), 'was_override' => false],
        ]);

        $this->assertDatabaseHas('media_assets', [
            'id'                    => $asset->id,
            'play_spots_remaining' => 9,
        ]);
    }

    /** @test */
    public function it_creates_a_playback_log_record_per_accepted_entry(): void
    {
        $asset = $this->makeAsset(spots: 50);

        $entries = array_map(fn () => [
            'asset_id'    => $asset->id,
            'played_at'   => now()->toIso8601String(),
            'was_override'=> false,
        ], range(1, 3));

        $result = $this->service->processBatch($this->device, $entries);

        $this->assertEquals(3, $result['accepted']);
        $this->assertEquals(0, $result['rejected']);
        $this->assertCount(3, PlaybackLog::where('asset_id', $asset->id)->get());
    }

    // ── Spot exhaustion ──────────────────────────────────────────────────────

    /** @test */
    public function it_rejects_plays_when_asset_has_no_tokens_remaining(): void
    {
        $asset = $this->makeAsset(spots: 0);

        $result = $this->service->processBatch($this->device, [
            ['asset_id' => $asset->id, 'played_at' => now()->toIso8601String(), 'was_override' => false],
        ]);

        $this->assertEquals(0, $result['accepted']);
        $this->assertEquals(1, $result['rejected']);
        $this->assertDatabaseMissing('playback_logs', ['asset_id' => $asset->id]);
    }

    // ── Hourly micro-constraint ───────────────────────────────────────────────

    /** @test */
    public function it_rejects_plays_exceeding_max_plays_per_hour(): void
    {
        $asset = $this->makeAsset(spots: 100, maxPerHour: 2);

        // Log 2 plays in the past 30 minutes (within the hour window)
        PlaybackLog::insert([
            ['id' => \Str::uuid(), 'asset_id' => $asset->id, 'loop_id' => null, 'device_id' => $this->device->id, 'spot_spent' => 1, 'was_override' => false, 'played_at' => now()->subMinutes(30)],
            ['id' => \Str::uuid(), 'asset_id' => $asset->id, 'loop_id' => null, 'device_id' => $this->device->id, 'spot_spent' => 1, 'was_override' => false, 'played_at' => now()->subMinutes(15)],
        ]);

        $result = $this->service->processBatch($this->device, [
            ['asset_id' => $asset->id, 'played_at' => now()->toIso8601String(), 'was_override' => false],
        ]);

        $this->assertEquals(0, $result['accepted']);
        $this->assertEquals(1, $result['rejected']);
    }

    // ── Loop daily cap ─────────────────────────────────────────────────────

    /** @test */
    public function it_rejects_plays_when_folder_daily_cap_is_reached(): void
    {
        $loop = MediaLoop::create(['name' => 'Promo', 'is_fallback' => false, 'max_daily_spots' => 2]);
        $asset  = $this->makeAsset(spots: 100, folderId: $loop->id);

        // Simulate 2 plays already recorded today for this loop
        PlaybackLog::insert([
            ['id' => \Str::uuid(), 'asset_id' => $asset->id, 'loop_id' => $loop->id, 'device_id' => $this->device->id, 'spot_spent' => 1, 'was_override' => false, 'played_at' => now()->startOfDay()->addHour()],
            ['id' => \Str::uuid(), 'asset_id' => $asset->id, 'loop_id' => $loop->id, 'device_id' => $this->device->id, 'spot_spent' => 1, 'was_override' => false, 'played_at' => now()->startOfDay()->addHours(2)],
        ]);

        $result = $this->service->processBatch($this->device, [
            ['asset_id' => $asset->id, 'played_at' => now()->toIso8601String(), 'was_override' => false],
        ]);

        $this->assertEquals(0, $result['accepted']);
        $this->assertEquals(1, $result['rejected']);
    }

    // ── Fallback assets bypass constraints ────────────────────────────────────

    /** @test */
    public function fallback_assets_are_always_accepted_regardless_of_token_count(): void
    {
        $fallbackFolder = MediaLoop::create(['name' => 'Filler', 'is_fallback' => true]);
        $asset = $this->makeAsset(spots: 0, folderId: $fallbackFolder->id);

        $result = $this->service->processBatch($this->device, [
            ['asset_id' => $asset->id, 'played_at' => now()->toIso8601String(), 'was_override' => false],
        ]);

        // Fallback assets bypass constraint validation entirely
        $this->assertEquals(1, $result['accepted']);
    }

    // ── Mixed batch ───────────────────────────────────────────────────────────

    /** @test */
    public function it_correctly_partitions_accepted_and_rejected_in_a_mixed_batch(): void
    {
        $eligible  = $this->makeAsset(spots: 50);
        $exhausted = $this->makeAsset(spots: 0);

        $result = $this->service->processBatch($this->device, [
            ['asset_id' => $eligible->id,  'played_at' => now()->toIso8601String(), 'was_override' => false],
            ['asset_id' => $exhausted->id, 'played_at' => now()->toIso8601String(), 'was_override' => false],
        ]);

        $this->assertEquals(1, $result['accepted']);
        $this->assertEquals(1, $result['rejected']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeAsset(int $spots, ?int $maxPerHour = null, ?string $folderId = null): MediaAsset
    {
        return MediaAsset::create([
            'name'                  => 'Test Asset ' . uniqid(),
            'file_path'             => 'media/test/' . uniqid() . '.mp4',
            'file_type'             => 'VIDEO',
            'loop_id'             => $folderId,
            'size_bytes'            => 10_000_000,
            'duration_secs'         => 10,
            'is_synced'             => true,
            'max_plays_per_hour'    => $maxPerHour,
            'play_spots_remaining' => $spots,
        ]);
    }
}
