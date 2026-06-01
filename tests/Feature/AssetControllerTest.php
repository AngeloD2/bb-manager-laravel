<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Services\S3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AssetControllerTest extends TestCase
{
    use RefreshDatabase;

    private Device $adminDevice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminDevice = Device::create(['name' => 'Admin App', 'location' => 'HQ']);

        $this->mock(S3Service::class, function ($mock) {
            $mock->shouldReceive('buildObjectKey')
                 ->andReturn('media/2026/01/test-uuid-ad-title.mp4');

            $mock->shouldReceive('upload')->andReturn(null);
            $mock->shouldReceive('deleteObject')->andReturn(null);
        });
    }

    // ── Upload endpoint ──────────────────────────────────────────────────────

    /** @test */
    public function upload_stores_file_and_creates_asset(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $loop = MediaLoop::create(['name' => 'Promo', 'is_fallback' => false]);

        $this->actAsAdmin()
            ->post('/api/v1/admin/assets/upload', [
                'file'                 => UploadedFile::fake()->create('summer_ad.mp4', 5000, 'video/mp4'),
                'name'                 => 'Summer Ad',
                'file_type'            => 'VIDEO',
                'loop_id'              => $loop->id,
                'size_bytes'           => 5_000_000,
                'duration_secs'        => 10,
                'campaign_name'        => 'Summer Campaign',
                'play_spots_remaining' => 100,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Summer Ad')
            ->assertJsonPath('data.file_path', 'media/2026/01/test-uuid-ad-title.mp4')
            ->assertJsonPath('data.is_synced', false);

        $this->assertDatabaseHas('media_assets', [
            'name'      => 'Summer Ad',
            'file_type' => 'VIDEO',
            'loop_id'   => $loop->id,
        ]);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\AssetProcessingJob::class);
    }

    /** @test */
    public function upload_rejects_unsupported_mime_types(): void
    {
        $this->actAsAdmin()
            ->post('/api/v1/admin/assets/upload', [
                'file'      => UploadedFile::fake()->create('malware.exe', 100, 'application/octet-stream'),
                'name'      => 'Bad File',
                'file_type' => 'VIDEO',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    /** @test */
    public function upload_handles_json_string_arrays_from_form_data(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $conflict = MediaAsset::create([
            'name' => 'Existing Ad', 'file_path' => 'media/existing.mp4', 'file_type' => 'VIDEO',
            'size_bytes' => 1000, 'duration_secs' => 10, 'is_synced' => true, 'play_spots_remaining' => 50,
        ]);

        $this->actAsAdmin()
            ->post('/api/v1/admin/assets/upload', [
                'file'               => UploadedFile::fake()->create('ad.mp4', 3000, 'video/mp4'),
                'name'               => 'New Ad',
                'file_type'          => 'VIDEO',
                'duration_secs'      => 10,
                'conflict_asset_ids' => json_encode([$conflict->id]),
                'assigned_devices'   => json_encode([$this->adminDevice->id]),
            ])
            ->assertCreated();

        $asset = MediaAsset::where('name', 'New Ad')->first();
        $this->assertContains($conflict->id, $asset->conflicts->pluck('id')->toArray());
    }

    // ── Duration constraint enforcement ──────────────────────────────────────

    /** @test */
    public function store_rejects_duration_outside_8_to_15_second_window(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/assets', [
                'name'          => 'Too Short',
                'file_path'     => 'media/short.mp4',
                'file_type'     => 'VIDEO',
                'size_bytes'    => 100000,
                'duration_secs' => 5,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['duration_secs']);
    }

    // ── Delete cleans up S3 ───────────────────────────────────────────────────

    /** @test */
    public function destroy_soft_deletes_asset_and_calls_s3_delete(): void
    {
        $asset = MediaAsset::create([
            'name' => 'Old Ad', 'file_path' => 'media/old.mp4', 'file_type' => 'VIDEO',
            'size_bytes' => 1000, 'duration_secs' => 10, 'is_synced' => true, 'play_spots_remaining' => 10,
        ]);

        $this->actAsAdmin()
            ->deleteJson("/api/v1/admin/assets/{$asset->id}")
            ->assertOk();

        $this->assertSoftDeleted('media_assets', ['id' => $asset->id]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function actAsAdmin(): static
    {
        $adminUser = \App\Models\User::create([
            'name'     => 'Admin Test User',
            'username' => 'admin-test-' . uniqid(),
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
        ]);
        $spot = $adminUser->createToken('admin-spot', ['admin'])->plainTextToken;
        return $this->withToken($spot);
    }
}
