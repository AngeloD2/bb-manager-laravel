<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\MediaAsset;
use App\Models\MediaLoop;
use App\Jobs\AssetProcessingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssetControllerTest extends TestCase
{
    use RefreshDatabase;

    private Billboard $adminBillboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminBillboard = Billboard::create(['name' => 'Admin App', 'location' => 'HQ']);

    }

    // ── Presign: validation of the direct-to-S3 handshake ────────────────────

    /** @test */
    public function presign_rejects_unsupported_content_types(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/assets/presign', [
                'original_name' => 'malware.exe',
                'file_type'     => 'VIDEO',
                'content_type'  => 'application/octet-stream',
                'size_bytes'    => 100,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content_type']);
    }

    /** @test */
    public function presign_rejects_files_over_the_5gb_limit(): void
    {
        $this->actAsAdmin()
            ->postJson('/api/v1/admin/assets/presign', [
                'original_name' => 'huge.mp4',
                'file_type'     => 'VIDEO',
                'content_type'  => 'video/mp4',
                'size_bytes'    => 5368709121,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['size_bytes']);
    }

    // ── Confirm: the object exists in S3, so record the asset ────────────────

    /** @test */
    public function confirm_creates_the_asset_and_dispatches_processing(): void
    {
        Queue::fake();
        Storage::fake('s3');

        $loop = MediaLoop::create(['name' => 'Promo', 'is_fallback' => false]);
        Storage::disk('s3')->put('media/2026/01/summer_ad.mp4', str_repeat('x', 5000));

        $this->actAsAdmin()
            ->postJson('/api/v1/admin/assets/confirm', [
                'object_key'           => 'media/2026/01/summer_ad.mp4',
                'name'                 => 'Summer Ad',
                'file_type'            => 'VIDEO',
                'loop_id'              => $loop->id,
                'duration_secs'        => 10,
                'play_spots_remaining' => 100,
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Summer Ad')
            ->assertJsonPath('file_path', 'media/2026/01/summer_ad.mp4')
            ->assertJsonPath('is_synced', false);

        $this->assertDatabaseHas('media_assets', [
            'name'      => 'Summer Ad',
            'file_type' => 'VIDEO',
            'loop_id'   => $loop->id,
        ]);

        Queue::assertPushed(AssetProcessingJob::class);
    }

    /** @test */
    public function confirm_rejects_an_object_key_that_is_not_in_s3(): void
    {
        Storage::fake('s3');

        $this->actAsAdmin()
            ->postJson('/api/v1/admin/assets/confirm', [
                'object_key' => 'media/never-uploaded.mp4',
                'name'       => 'Ghost Ad',
                'file_type'  => 'VIDEO',
            ])
            ->assertStatus(400);

        $this->assertDatabaseMissing('media_assets', ['name' => 'Ghost Ad']);
    }

    /** @test */
    public function confirm_rejects_a_zero_byte_upload(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('media/empty.mp4', '');

        $this->actAsAdmin()
            ->postJson('/api/v1/admin/assets/confirm', [
                'object_key' => 'media/empty.mp4',
                'name'       => 'Empty Ad',
                'file_type'  => 'VIDEO',
            ])
            ->assertStatus(400);

        $this->assertDatabaseMissing('media_assets', ['name' => 'Empty Ad']);
    }

    /** @test */
    public function confirm_handles_json_string_arrays_from_form_data(): void
    {
        Queue::fake();
        Storage::fake('s3');

        $conflict = MediaAsset::create([
            'name' => 'Existing Ad', 'file_path' => 'media/existing.mp4', 'file_type' => 'VIDEO',
            'size_bytes' => 1000, 'duration_secs' => 10, 'is_synced' => true, 'play_spots_remaining' => 50,
        ]);
        Storage::disk('s3')->put('media/ad.mp4', str_repeat('x', 3000));

        $this->actAsAdmin()
            ->postJson('/api/v1/admin/assets/confirm', [
                'object_key'         => 'media/ad.mp4',
                'name'               => 'New Ad',
                'file_type'          => 'VIDEO',
                'duration_secs'      => 10,
                'conflict_asset_ids' => json_encode([$conflict->id]),
                'assigned_billboards'   => json_encode([$this->adminBillboard->id]),
            ])
            ->assertCreated();

        $asset = MediaAsset::where('name', 'New Ad')->first();
        $this->assertContains($conflict->id, $asset->conflicts->pluck('id')->toArray());
        $this->assertSame([$this->adminBillboard->id], $asset->assigned_billboards);
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
        $token = $adminUser->createToken('admin-token', ['admin'])->plainTextToken;
        return $this->withToken($token);
    }
}
