<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\Zone;
use App\Services\BillboardSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZoneTest extends TestCase
{
    use RefreshDatabase;

    private BillboardSyncService $syncService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncService = app(BillboardSyncService::class);
    }

    private function createAsset(array $attrs = []): MediaAsset
    {
        $loop = MediaLoop::create(['name' => 'Primary Loop', 'is_fallback' => false]);
        return MediaAsset::create(array_merge([
            'name' => 'test-asset',
            'file_path' => 'test.mp4',
            'file_type' => 'VIDEO',
            'is_synced' => true,
            'loop_id' => $loop->id,
        ], $attrs));
    }

    /** @test */
    public function asset_with_explicit_assignment_ignores_zones_and_plays_on_assigned_board()
    {
        $zone = Zone::create(['name' => 'Downtown']);
        $board = Billboard::create(['name' => 'B1', 'zone_id' => $zone->id]);
        
        $asset = $this->createAsset([
            'assigned_billboards' => [$board->id],
            'targeted_zones' => [], // Does not include the board's zone
        ]);

        $payload = $this->syncService->buildPayload($board);
        $this->assertTrue($payload['eligible_assets']->contains('id', $asset->id));
    }

    /** @test */
    public function asset_with_explicit_assignment_ignores_zones_and_skips_unassigned_board()
    {
        $zone = Zone::create(['name' => 'Downtown']);
        $board = Billboard::create(['name' => 'B1', 'zone_id' => $zone->id]);
        
        $asset = $this->createAsset([
            'assigned_billboards' => ['some-other-board'],
            'targeted_zones' => [$zone->id], // Includes the board's zone!
        ]);

        $payload = $this->syncService->buildPayload($board);
        $this->assertFalse($payload['eligible_assets']->contains('id', $asset->id));
    }

    /** @test */
    public function asset_without_explicit_assignment_plays_if_zone_matches()
    {
        $zone = Zone::create(['name' => 'Downtown']);
        $board = Billboard::create(['name' => 'B1', 'zone_id' => $zone->id]);
        
        $asset = $this->createAsset([
            'assigned_billboards' => [], // Empty assignment
            'targeted_zones' => [$zone->id],
        ]);

        $payload = $this->syncService->buildPayload($board);
        $this->assertTrue($payload['eligible_assets']->contains('id', $asset->id));
    }

    /** @test */
    public function asset_without_explicit_assignment_skips_if_zone_does_not_match()
    {
        $zone = Zone::create(['name' => 'Downtown']);
        $otherZone = Zone::create(['name' => 'Suburbs']);
        $board = Billboard::create(['name' => 'B1', 'zone_id' => $zone->id]);
        
        $asset = $this->createAsset([
            'assigned_billboards' => [],
            'targeted_zones' => [$otherZone->id],
        ]);

        $payload = $this->syncService->buildPayload($board);
        $this->assertFalse($payload['eligible_assets']->contains('id', $asset->id));
    }

    /** @test */
    public function asset_without_explicit_assignment_and_without_zones_falls_back_to_legacy_behavior()
    {
        $board = Billboard::create(['name' => 'B1']);
        
        $asset = $this->createAsset([
            'assigned_billboards' => [],
            'targeted_zones' => [],
            'is_global' => true,
        ]);

        $payload = $this->syncService->buildPayload($board);
        $this->assertTrue($payload['eligible_assets']->contains('id', $asset->id));
    }
}
