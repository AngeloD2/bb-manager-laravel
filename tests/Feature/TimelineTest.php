<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimelineTest extends TestCase
{
    use RefreshDatabase;

    private Billboard $billboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billboard = Billboard::create(['name' => 'Board', 'location' => 'Downtown']);
        $loop = MediaLoop::create(['name' => 'Rotation', 'is_fallback' => false, 'is_global' => true]);
        foreach (['one', 'two', 'three'] as $i => $name) {
            MediaAsset::create([
                'name' => $name, 'file_path' => "media/{$name}.mp4", 'file_type' => 'VIDEO',
                'loop_id' => $loop->id, 'order_index' => $i, 'size_bytes' => 1000,
                'duration_secs' => 15, 'is_synced' => true, 'play_spots_remaining' => 100,
            ]);
        }
    }

    /** @test */
    public function the_timeline_pages_through_upcoming_media_beyond_the_live_queue(): void
    {
        $url = "/api/v1/admin/timeline?billboard_id={$this->billboard->id}&limit=10";

        $first = $this->actAsAdmin()->getJson("{$url}&offset=0")
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.has_more', true);
        $second = $this->actAsAdmin()->getJson("{$url}&offset=10")
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.has_more', true);

        // The rotation carries on across the live queue's end and the page boundary.
        $names = array_merge(
            array_column($first->json('data'), 'asset_name'),
            array_column($second->json('data'), 'asset_name'),
        );
        $expected = array_map(fn ($i) => ['one', 'two', 'three'][$i % 3], range(0, 19));
        $this->assertSame($expected, $names);

        // Looking ahead never grows the board's live queue.
        $this->assertCount(12, Cache::get("billboard:{$this->billboard->id}:queue"));
    }

    /** @test */
    public function the_timeline_look_ahead_ends_at_its_cap(): void
    {
        $this->actAsAdmin()
            ->getJson("/api/v1/admin/timeline?billboard_id={$this->billboard->id}&offset=190&limit=50")
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.has_more', false);
    }

    /** @test */
    public function without_paging_the_timeline_returns_the_live_queue(): void
    {
        $this->actAsAdmin()
            ->getJson("/api/v1/admin/timeline?billboard_id={$this->billboard->id}")
            ->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonMissingPath('meta');
    }

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
}
