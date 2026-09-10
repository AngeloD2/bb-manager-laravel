<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Services\QueueGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class QueueGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function asset(string $name, MediaLoop $loop, int $orderIndex, array $extra = []): MediaAsset
    {
        return MediaAsset::create(array_merge([
            'name' => $name, 'file_path' => "media/{$name}.mp4", 'file_type' => 'VIDEO',
            'loop_id' => $loop->id, 'size_bytes' => 1000, 'duration_secs' => 15,
            'is_synced' => true, 'play_spots_remaining' => 100, 'order_index' => $orderIndex,
        ], $extra));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @test */
    public function it_plays_loops_in_billboard_defined_order_and_finishes_a_loop_before_the_next(): void
    {
        // Loop A created first, but the billboard orders B before A.
        $loopA = MediaLoop::create(['name' => 'A', 'is_fallback' => false, 'is_global' => true]);
        $loopB = MediaLoop::create(['name' => 'B', 'is_fallback' => false, 'is_global' => true]);

        $this->asset('a1', $loopA, 0);
        $this->asset('a2', $loopA, 1);
        $this->asset('b1', $loopB, 0);

        $billboard = Billboard::create([
            'name' => 'Board', 'loop_orders' => [$loopB->id, $loopA->id],
        ]);

        $queue = app(QueueGenerationService::class)->getUpcomingQueue($billboard, 6);
        $names = array_map(fn ($i) => $i['asset_name'], $queue);

        // B's pass first (b1), then A's pass (a1, a2), then it wraps — loop-complete + ordered.
        $this->assertSame(['b1', 'a1', 'a2', 'b1', 'a1', 'a2'], $names);
    }

    /** @test */
    public function it_spreads_plays_across_the_hour_via_pacing(): void
    {
        $loop = MediaLoop::create(['name' => 'P', 'is_fallback' => false, 'is_global' => true]);
        $fallback = MediaLoop::create(['name' => 'F', 'is_fallback' => true, 'is_global' => true]);

        // maxPlaysPerHour=2 → ideal interval 1800s; pacing blocks a repeat for ~1350s,
        // so within a back-to-back batch p1 may appear only once and the fallback fills.
        $this->asset('p1', $loop, 0, ['max_plays_per_hour' => 2]);
        $this->asset('f1', $fallback, 0);

        $billboard = Billboard::create([
            'name' => 'Board', 'loop_orders' => [$loop->id, $fallback->id],
        ]);

        $queue = app(QueueGenerationService::class)->getUpcomingQueue($billboard, 4);
        $names = array_map(fn ($i) => $i['asset_name'], $queue);

        $this->assertSame('p1', $names[0], 'first slot is the primary');
        $this->assertSame(1, array_count_values($names)['p1'], 'pacing keeps p1 to a single play in the batch');
        $this->assertSame('f1', $names[1], 'the paced gap is filled by the fallback loop');
    }

    /** @test */
    public function it_cancels_and_removes_injected_override(): void
    {
        $loop = MediaLoop::create(['name' => 'P', 'is_fallback' => false, 'is_global' => true]);
        $asset = $this->asset('p1', $loop, 0);
        $overrideAsset = $this->asset('o1', $loop, 1);

        $billboard = Billboard::create([
            'name' => 'Board', 'loop_orders' => [$loop->id],
        ]);

        $service = app(QueueGenerationService::class);

        // Get initial queue
        $service->getUpcomingQueue($billboard, 4);
        
        // Inject override
        $service->injectOverride($billboard, $overrideAsset);

        // Retrieve queue and assert override is present
        $queueAfterInject = $service->getUpcomingQueue($billboard, 4);
        $overrideCount = count(array_filter($queueAfterInject, fn ($i) => $i['is_override'] ?? false));
        $this->assertEquals(1, $overrideCount);

        // Cancel override
        $service->cancelOverride($billboard);

        // Retrieve queue and assert override is removed
        $queueAfterCancel = $service->getUpcomingQueue($billboard, 4);
        $overrideCountAfter = count(array_filter($queueAfterCancel, fn ($i) => $i['is_override'] ?? false));
        $this->assertEquals(0, $overrideCountAfter);
    }

    /** @test */
    public function it_schedules_explicit_assets_before_unordered_and_sorts_unordered_by_least_recently_played(): void
    {
        $loop = MediaLoop::create(['name' => 'MixedLoop', 'is_fallback' => false, 'is_global' => true]);

        // Explicit asset (order 0)
        $explicit = $this->asset('e1', $loop, 0);

        // Two unordered assets (order null)
        $u1 = MediaAsset::create([
            'name' => 'u1', 'file_path' => 'media/u1.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $loop->id, 'size_bytes' => 1000, 'duration_secs' => 15,
            'is_synced' => true, 'play_spots_remaining' => 100, 'order_index' => null,
        ]);
        $u2 = MediaAsset::create([
            'name' => 'u2', 'file_path' => 'media/u2.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $loop->id, 'size_bytes' => 1000, 'duration_secs' => 15,
            'is_synced' => true, 'play_spots_remaining' => 100, 'order_index' => null,
        ]);

        $billboard = Billboard::create([
            'name' => 'Board', 'loop_orders' => [$loop->id],
        ]);

        // Seed play history: u1 played 10 mins ago, u2 never played
        \App\Models\PlaybackLog::create([
            'asset_id' => $u1->id,
            'loop_id' => $loop->id,
            'billboard_id' => $billboard->id,
            'client_event_id' => (string) \Illuminate\Support\Str::uuid(),
            'spot_spent' => 1,
            'was_override' => false,
            'played_at' => now()->subMinutes(10),
        ]);

        $queue = app(QueueGenerationService::class)->getUpcomingQueue($billboard, 6);
        $names = array_map(fn ($i) => $i['asset_name'], $queue);

        // Pass 1: e1 (explicit), u2 (never played, ts=0), u1 (played 10m ago)
        // Pass 2: e1 (explicit), u2 (played at start of pass 1), u1 (played 15s after u2 in pass 1)
        $this->assertSame(['e1', 'u2', 'u1', 'e1', 'u2', 'u1'], $names);
    }

    /** @test */
    public function it_skips_entire_bundle_loop_when_first_asset_fails_constraints_or_pacing(): void
    {
        $bundle = MediaLoop::create([
            'name' => 'BundleLoop',
            'is_fallback' => false,
            'is_global' => true,
            'is_bundle' => true,
        ]);

        // Part 1 is capped at 1 play per day
        $part1 = $this->asset('part1', $bundle, 0, ['max_daily_plays' => 1]);
        $part2 = $this->asset('part2', $bundle, 1, ['max_daily_plays' => 10]);

        $fallback = MediaLoop::create(['name' => 'FallbackLoop', 'is_fallback' => true, 'is_global' => true]);
        $f1 = $this->asset('f1', $fallback, 0);

        $billboard = Billboard::create([
            'name' => 'Board', 'loop_orders' => [$bundle->id, $fallback->id],
        ]);

        // Part 1 already played today (cap reached)
        \App\Models\PlaybackLog::create([
            'asset_id' => $part1->id,
            'loop_id' => $bundle->id,
            'billboard_id' => $billboard->id,
            'client_event_id' => (string) \Illuminate\Support\Str::uuid(),
            'spot_spent' => 1,
            'was_override' => false,
            'played_at' => now()->startOfDay()->addHours(1),
        ]);

        $queue = app(QueueGenerationService::class)->getUpcomingQueue($billboard, 2);
        $names = array_map(fn ($i) => $i['asset_name'], $queue);

        // Because part1 is capped and bundle is atomic, entire bundle is skipped (neither part1 nor part2 plays).
        // Fallback fills the slots.
        $this->assertSame(['f1', 'f1'], $names);
    }

    /** @test */
    public function it_substitutes_campaign_specific_fallback_when_campaign_primary_loop_is_exhausted(): void
    {
        $campaign = \App\Models\Campaign::create(['name' => 'Brand Alpha']);

        $primaryLoop = MediaLoop::create([
            'name' => 'Alpha Primary',
            'campaign_id' => $campaign->id,
            'is_fallback' => false,
            'is_global' => true,
        ]);
        $primaryAsset = $this->asset('alpha_p1', $primaryLoop, 0, ['max_daily_plays' => 1]);

        $campaignFallbackLoop = MediaLoop::create([
            'name' => 'Alpha Fallback',
            'campaign_id' => $campaign->id,
            'is_fallback' => true,
            'is_global' => true,
        ]);
        $campaignFallbackAsset = $this->asset('alpha_fb1', $campaignFallbackLoop, 0);

        $globalFallbackLoop = MediaLoop::create([
            'name' => 'Global Fallback',
            'campaign_id' => null,
            'is_fallback' => true,
            'is_global' => true,
        ]);
        $globalFallbackAsset = $this->asset('global_fb1', $globalFallbackLoop, 0);

        $billboard = Billboard::create([
            'name' => 'Board', 'loop_orders' => [$primaryLoop->id],
        ]);

        // Primary asset is capped
        \App\Models\PlaybackLog::create([
            'asset_id' => $primaryAsset->id,
            'loop_id' => $primaryLoop->id,
            'billboard_id' => $billboard->id,
            'client_event_id' => (string) \Illuminate\Support\Str::uuid(),
            'spot_spent' => 1,
            'was_override' => false,
            'played_at' => now()->startOfDay()->addHours(1),
        ]);

        $queue = app(QueueGenerationService::class)->getUpcomingQueue($billboard, 2);
        $names = array_map(fn ($i) => $i['asset_name'], $queue);

        // Should substitute campaign-specific fallback (alpha_fb1), NOT global fallback (global_fb1)
        $this->assertSame(['alpha_fb1', 'alpha_fb1'], $names);
    }

    /** @test */
    public function it_falls_back_to_global_fallback_when_campaign_has_no_fallback_loops(): void
    {
        $campaign = \App\Models\Campaign::create(['name' => 'Brand Beta']);

        $primaryLoop = MediaLoop::create([
            'name' => 'Beta Primary',
            'campaign_id' => $campaign->id,
            'is_fallback' => false,
            'is_global' => true,
        ]);
        $primaryAsset = $this->asset('beta_p1', $primaryLoop, 0, ['max_daily_plays' => 1]);

        $globalFallbackLoop = MediaLoop::create([
            'name' => 'Global Fallback',
            'campaign_id' => null,
            'is_fallback' => true,
            'is_global' => true,
        ]);
        $globalFallbackAsset = $this->asset('global_fb1', $globalFallbackLoop, 0);

        $billboard = Billboard::create([
            'name' => 'Board', 'loop_orders' => [$primaryLoop->id],
        ]);

        // Primary asset is capped
        \App\Models\PlaybackLog::create([
            'asset_id' => $primaryAsset->id,
            'loop_id' => $primaryLoop->id,
            'billboard_id' => $billboard->id,
            'client_event_id' => (string) \Illuminate\Support\Str::uuid(),
            'spot_spent' => 1,
            'was_override' => false,
            'played_at' => now()->startOfDay()->addHours(1),
        ]);

        $queue = app(QueueGenerationService::class)->getUpcomingQueue($billboard, 2);
        $names = array_map(fn ($i) => $i['asset_name'], $queue);

        // Beta has no campaign fallback, so it falls back to global fallback
        $this->assertSame(['global_fb1', 'global_fb1'], $names);
    }

    /** @test */
    public function it_round_robins_multiple_campaign_specific_fallbacks(): void
    {
        $campaign = \App\Models\Campaign::create(['name' => 'Brand Gamma']);

        $primaryLoop = MediaLoop::create([
            'name' => 'Gamma Primary',
            'campaign_id' => $campaign->id,
            'is_fallback' => false,
            'is_global' => true,
        ]);
        $primaryAsset = $this->asset('gamma_p1', $primaryLoop, 0, ['max_daily_plays' => 1]);

        $campaignFallbackLoop1 = MediaLoop::create([
            'name' => 'Gamma FB 1',
            'campaign_id' => $campaign->id,
            'is_fallback' => true,
            'is_global' => true,
        ]);
        $campaignFallbackAsset1 = $this->asset('gamma_fb1', $campaignFallbackLoop1, 0);

        $campaignFallbackLoop2 = MediaLoop::create([
            'name' => 'Gamma FB 2',
            'campaign_id' => $campaign->id,
            'is_fallback' => true,
            'is_global' => true,
        ]);
        $campaignFallbackAsset2 = $this->asset('gamma_fb2', $campaignFallbackLoop2, 0);

        $billboard = Billboard::create([
            'name' => 'Board', 'loop_orders' => [$primaryLoop->id],
        ]);

        // Primary asset is capped
        \App\Models\PlaybackLog::create([
            'asset_id' => $primaryAsset->id,
            'loop_id' => $primaryLoop->id,
            'billboard_id' => $billboard->id,
            'client_event_id' => (string) \Illuminate\Support\Str::uuid(),
            'spot_spent' => 1,
            'was_override' => false,
            'played_at' => now()->startOfDay()->addHours(1),
        ]);

        $queue = app(QueueGenerationService::class)->getUpcomingQueue($billboard, 3);
        $names = array_map(fn ($i) => $i['asset_name'], $queue);

        // Round-robin among campaign's fallback loops: fb1, fb2, fb1
        $this->assertSame(['gamma_fb1', 'gamma_fb2', 'gamma_fb1'], $names);
    }
}
