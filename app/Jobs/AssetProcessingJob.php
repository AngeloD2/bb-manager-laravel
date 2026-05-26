<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AssetProcessingJob
 *
 * Dispatched after the frontend confirms a successful S3 PUT.
 * Responsibilities:
 *  1. Download a small sample of the file from S3 to run FFprobe.
 *  2. Extract duration and resolution metadata.
 *  3. Clamp duration to the 8–15 second display window rule.
 *  4. Mark the asset as is_synced = true.
 *
 * On failure the job retries 3 times with exponential backoff.
 */
class AssetProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly string $assetId
    ) {}

    public function handle(): void
    {
        $asset = MediaAsset::findOrFail($this->assetId);

        Log::info("AssetProcessingJob: starting", ['asset_id' => $asset->id]);

        try {
            $metadata = $this->extractMetadata($asset);

            $asset->update([
                'duration_secs' => $this->clampDuration($metadata['duration'] ?? $asset->duration_secs),
                'is_synced'     => true,
            ]);

            Log::info("AssetProcessingJob: completed", [
                'asset_id' => $asset->id,
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Log::error("AssetProcessingJob: failed", [
                'asset_id' => $asset->id,
                'error'    => $e->getMessage(),
            ]);

            // Do not mark synced on failure — leave for retry or manual fix.
            throw $e;
        }
    }

    /**
     * Run FFprobe against the S3-hosted file using a temporary presigned URL.
     *
     * @return array{duration: float, width: int, height: int}
     */
    private function extractMetadata(MediaAsset $asset): array
    {
        // Get a short-lived URL to let FFprobe stream from S3
        $url = Storage::disk('s3')->temporaryUrl($asset->file_path, now()->addMinutes(5));

        $ffprobe = config('media.ffprobe_binaries', '/usr/bin/ffprobe');

        $command = sprintf(
            '%s -v quiet -print_format json -show_streams -show_format %s 2>&1',
            escapeshellarg($ffprobe),
            escapeshellarg($url)
        );

        $output = shell_exec($command);

        if (! $output) {
            // FFprobe not available in environment — return asset defaults
            Log::warning('AssetProcessingJob: FFprobe unavailable, using defaults', ['asset_id' => $asset->id]);
            return ['duration' => (float) $asset->duration_secs, 'width' => 0, 'height' => 0];
        }

        $data     = json_decode($output, true);
        $duration = (float) ($data['format']['duration'] ?? $asset->duration_secs);
        $stream   = collect($data['streams'] ?? [])->firstWhere('codec_type', 'video') ?? [];

        return [
            'duration' => $duration,
            'width'    => (int) ($stream['width']  ?? 0),
            'height'   => (int) ($stream['height'] ?? 0),
        ];
    }

    /**
     * Enforce the 8–15 second display window constraint from the token economy spec.
     */
    private function clampDuration(float $seconds): int
    {
        return (int) max(8, min(15, round($seconds)));
    }

    public function backoff(): array
    {
        return [30, 60, 120]; // seconds between retries
    }
}
