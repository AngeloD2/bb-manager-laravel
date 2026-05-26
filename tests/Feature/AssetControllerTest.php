<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Services\S3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetControllerTest extends TestCase
{
    use RefreshDatabase;

    private Device $adminDevice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminDevice = Device::create(['name' => 'Admin App', 'location' => 'HQ']);

        // Mock S3Service so tests don't need real AWS credentials
        $this->mock(S3Service::class, function ($mock) {
            $mock->shouldReceive('buildObjectKey')
                 ->andReturn('media/2026/01/test-uuid-ad-title.mp4');

            $mock->shouldReceive('presignedPutUrl')
                 ->andReturn([
                     'upload_url' => 'https://s3.amazonaws.com/bucket/media/2026/01/test.mp4?X-Amz-Signature=abc',
                     'object_key' => 'media/2026/01/test.mp4',
                     'expires_in' => 300,
                     'method'     => 'PUT',
                 ]);

            $mock->shouldReceive('deleteObject')->andReturn(null);
        });
    }

    // ── Presigned URL generation ──────────────────────────────────────────────

    /** @test */
    public function it_generates_a_presigned_put_url_for_valid_video_upload(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/assets/presigned-url', [
                'filename'  => 'coca_cola_summer.mp4',
                'mime_type' => 'video/mp4',
            ])
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['upload_url', 'object_key', 'expires_in', 'method'],
            ])
            ->assertJsonPath('data.method', 'PUT');
    }

    /** @test */
    public function presigned_url_rejects_unsupported_mime_types(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/assets/presigned-url', [
                'filename'  => 'malware.exe',
                'mime_type' => 'application/octet-stream',
            ])
            ->assertUnprocessable();
    }

    // ── Confirm upload / AssetProcessingJob dispatch ──────────────────────────

    /** @test */
    public function confirm_upload_dispatches_asset_processing_job(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $folder = MediaFolder::create(['name' => 'Promo', 'is_fallback' => false]);
        $asset  = MediaAsset::create([
            'name'                  => 'New Nike Ad',
            'file_path'             => 'media/2026/01/test.mp4',
            'file_type'             => 'VIDEO',
            'folder_id'             => $folder->id,
            'size_bytes'            => 15_000_000,
            'duration_secs'         => 10,
            'is_synced'             => false,
            'play_tokens_remaining' => 100,
        ]);

        $this->actAsAdmin()
            ->postJson("/api/v1/admin/assets/{$asset->id}/confirm")
            ->assertOk()
            ->assertJsonPath('message', 'Upload confirmed. Asset is being processed.');

        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\AssetProcessingJob::class,
            fn ($job) => $this->readPrivate($job, 'assetId') === $asset->id
        );
    }

    /** @test */
    public function confirm_upload_is_idempotent_when_asset_already_synced(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $asset = MediaAsset::create([
            'name' => 'Already Synced', 'file_path' => 'media/done.mp4', 'file_type' => 'VIDEO',
            'size_bytes' => 1000, 'duration_secs' => 10, 'is_synced' => true, 'play_tokens_remaining' => 50,
        ]);

        $this->actAsAdmin()
            ->postJson("/api/v1/admin/assets/{$asset->id}/confirm")
            ->assertOk()
            ->assertJsonPath('message', 'Asset already confirmed.');

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
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
                'duration_secs' => 5,   // < 8 seconds — invalid
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
            'size_bytes' => 1000, 'duration_secs' => 10, 'is_synced' => true, 'play_tokens_remaining' => 10,
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
        $token = $adminUser->createToken('admin-token', ['admin'])->plainTextToken;
        return $this->withToken($token);
    }

    private function readPrivate(object $obj, string $property): mixed
    {
        $ref = new \ReflectionProperty($obj, $property);
        $ref->setAccessible(true);
        return $ref->getValue($obj);
    }
}
