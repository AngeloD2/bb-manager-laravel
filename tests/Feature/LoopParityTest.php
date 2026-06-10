<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\PlaybackLog;
use App\Services\QueueGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cross-engine parity: the PHP queue generator must make the same deterministic
 * loop-rule decisions (order, loop-completion, daily-cap exclusion) as the JS
 * scheduler. Both sides assert against the SAME canonical fixture
 * (tests/fixtures/loop_parity.json); the JS counterpart lives in
 * bb-manager-player-react/test/loop-parity.test.mjs. If either engine drifts on
 * those rules, its parity test fails.
 */
class LoopParityTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function php_queue_matches_the_canonical_parity_fixture(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/fixtures/loop_parity.json')), true);

        Cache::flush();

        // Loops keyed by fixture id (e.g. "A","B") -> real model.
        $loops = [];
        foreach ($fixture['loops'] as $l) {
            $loops[$l['id']] = MediaLoop::create([
                'name' => $l['id'],
                'is_fallback' => $l['isFallback'],
                'is_global' => true,
            ]);
        }

        $device = Device::create([
            'name' => 'Parity Board',
            'loop_orders' => array_map(fn ($id) => $loops[$id]->id, $fixture['loopOrder']),
        ]);

        foreach ($fixture['assets'] as $a) {
            $asset = MediaAsset::create([
                'name' => $a['id'],
                'file_path' => "media/{$a['id']}.mp4",
                'file_type' => 'VIDEO',
                'loop_id' => $loops[$a['loop']]->id,
                'size_bytes' => 1000,
                'duration_secs' => $a['durationSecs'],
                'is_synced' => true,
                'play_spots_remaining' => $a['playSpotsRemaining'],
                'order_index' => $a['orderIndex'],
                'max_daily_plays' => $a['maxDailyPlays'],
            ]);

            // Seed today's play count so daily caps are already met where the fixture says so.
            for ($i = 0; $i < $a['playsToday']; $i++) {
                PlaybackLog::create([
                    'asset_id' => $asset->id,
                    'loop_id' => $asset->loop_id,
                    'device_id' => $device->id,
                    'client_event_id' => (string) Str::uuid(),
                    'spot_spent' => 1,
                    'was_override' => false,
                    'played_at' => now()->startOfDay()->addHours(8)->addMinutes($i),
                ]);
            }
        }

        $queue = app(QueueGenerationService::class)->getUpcomingQueue($device, $fixture['picks']);
        $names = array_map(fn ($item) => $item['asset_name'], $queue);

        $this->assertSame($fixture['expected'], $names);
    }
}
