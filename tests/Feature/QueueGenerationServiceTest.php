<?php

namespace Tests\Feature;

use App\Models\Device;
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
    public function it_plays_loops_in_device_defined_order_and_finishes_a_loop_before_the_next(): void
    {
        // Loop A created first, but the device orders B before A.
        $loopA = MediaLoop::create(['name' => 'A', 'is_fallback' => false, 'is_global' => true]);
        $loopB = MediaLoop::create(['name' => 'B', 'is_fallback' => false, 'is_global' => true]);

        $this->asset('a1', $loopA, 0);
        $this->asset('a2', $loopA, 1);
        $this->asset('b1', $loopB, 0);

        $device = Device::create([
            'name' => 'Board', 'loop_orders' => [$loopB->id, $loopA->id],
        ]);

        $queue = app(QueueGenerationService::class)->getUpcomingQueue($device, 6);
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

        $device = Device::create([
            'name' => 'Board', 'loop_orders' => [$loop->id, $fallback->id],
        ]);

        $queue = app(QueueGenerationService::class)->getUpcomingQueue($device, 4);
        $names = array_map(fn ($i) => $i['asset_name'], $queue);

        $this->assertSame('p1', $names[0], 'first slot is the primary');
        $this->assertSame(1, array_count_values($names)['p1'], 'pacing keeps p1 to a single play in the batch');
        $this->assertSame('f1', $names[1], 'the paced gap is filled by the fallback loop');
    }
}
