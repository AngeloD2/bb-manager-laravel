<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Models\TimelineOverride;
use App\Services\QueueGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OverrideControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @test */
    public function it_can_queue_and_cancel_overrides_via_api(): void
    {
        $billboard = Billboard::create(['name' => 'Test Billboard']);
        $loop = MediaLoop::create(['name' => 'Promo', 'is_fallback' => false, 'is_global' => true]);
        $asset = MediaAsset::create([
            'name' => 'Nike Ad', 'file_path' => 'media/nike.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $loop->id, 'size_bytes' => 1000, 'duration_secs' => 10,
            'is_synced' => true, 'play_spots_remaining' => 50,
        ]);
        $overrideAsset = MediaAsset::create([
            'name' => 'Override Ad', 'file_path' => 'media/override.mp4', 'file_type' => 'VIDEO',
            'loop_id' => $loop->id, 'size_bytes' => 1200, 'duration_secs' => 15,
            'is_synced' => true, 'play_spots_remaining' => 50,
        ]);

        // 1. Post to create override
        $resStore = $this->actAsAdmin()->postJson('/api/v1/admin/overrides', [
            'asset_id' => $overrideAsset->id,
            'billboard_id' => $billboard->id,
        ]);

        $resStore->assertStatus(201);
        $this->assertDatabaseHas('timeline_overrides', [
            'asset_id' => $overrideAsset->id,
            'billboard_id' => $billboard->id,
            'consumed' => false,
        ]);

        // Check if injected into the cached queue
        $queueService = app(QueueGenerationService::class);
        $queue = $queueService->getUpcomingQueue($billboard, 12);
        $overrideItems = array_filter($queue, fn($item) => $item['is_override'] ?? false);
        $this->assertCount(1, $overrideItems);

        // 2. Delete to cancel override
        $resDestroy = $this->actAsAdmin()->deleteJson('/api/v1/admin/overrides', [
            'billboard_id' => $billboard->id,
        ]);

        $resDestroy->assertStatus(200);
        $this->assertDatabaseMissing('timeline_overrides', [
            'asset_id' => $overrideAsset->id,
            'billboard_id' => $billboard->id,
            'consumed' => false,
        ]);

        // Assert it is removed from the cached queue
        $queueAfter = $queueService->getUpcomingQueue($billboard, 12);
        $overrideItemsAfter = array_filter($queueAfter, fn($item) => $item['is_override'] ?? false);
        $this->assertCount(0, $overrideItemsAfter);
    }

    private function actAsAdmin(): static
    {
        $adminUser = \App\Models\User::create([
            'name'     => 'Admin Test User',
            'username' => 'admin-test-' . uniqid(),
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);
        $token = $adminUser->createToken('admin-token', ['admin'])->plainTextToken;
        return $this->withToken($token);
    }
}
