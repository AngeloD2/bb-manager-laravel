<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\S3Service;
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
 *  4. Optionally stretch the media to the billboard's exact dimensions.
 *  5. Mark the asset as is_synced = true.
 *
 * On failure the job retries 3 times with exponential backoff.
 */
class AssetProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(
        private readonly string $assetId,
        private readonly bool $stretchToFit = false
    ) {}

    public function handle(S3Service $s3): void
    {
        $asset = MediaAsset::findOrFail($this->assetId);

        Log::info("AssetProcessingJob: starting", [
            'asset_id'       => $asset->id,
            'stretch_to_fit' => $this->stretchToFit,
        ]);

        try {
            $metadata = $this->extractMetadata($asset);

            if ($this->stretchToFit) {
                $this->stretchToBillboard($asset, $metadata, $s3);
            }

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
     * Re-encode the asset so it exactly fills the billboard canvas.
     *
     * FFmpeg's `scale=W:H` filter does NOT preserve aspect ratio — the source
     * is deliberately stretched to the panel's dimensions, which is the whole
     * point of the "stretch to fit" upload toggle.
     *
     * @param array{duration: float, width: int, height: int} $metadata
     */
    private function stretchToBillboard(MediaAsset $asset, array $metadata, S3Service $s3): void
    {
        $targetW = (int) config('media.billboard_width', 3648);
        $targetH = (int) config('media.billboard_height', 1152);

        // Already the right size — nothing to re-encode.
        if ($metadata['width'] === $targetW && $metadata['height'] === $targetH) {
            Log::info('AssetProcessingJob: asset already at billboard size, skipping stretch', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        $ffmpeg = config('media.ffmpeg_binaries', '/usr/bin/ffmpeg');

        [$ext, $contentType] = match ($asset->file_type) {
            'VIDEO' => ['mp4', 'video/mp4'],
            'GIF'   => ['gif', 'image/gif'],
            default => ['jpg', 'image/jpeg'],
        };

        $srcUrl  = Storage::disk('s3')->temporaryUrl($asset->file_path, now()->addMinutes(10));
        $outPath = tempnam(sys_get_temp_dir(), 'bbstretch_') . '.' . $ext;

        // setsar=1 forces square pixels so downstream players don't "correct"
        // the stretch back toward the original aspect ratio.
        $vf = sprintf('scale=%d:%d,setsar=1', $targetW, $targetH);

        if ($asset->file_type === 'VIDEO') {
            $command = sprintf(
                '%s -y -i %s -vf %s -c:v libx264 -preset veryfast -pix_fmt yuv420p -c:a copy %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($srcUrl),
                escapeshellarg($vf),
                escapeshellarg($outPath)
            );
        } else {
            // GIF and stills: single-pass filter, no audio stream involved.
            $command = sprintf(
                '%s -y -i %s -vf %s %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($srcUrl),
                escapeshellarg($vf),
                escapeshellarg($outPath)
            );
        }

        $output = shell_exec($command);

        if (! is_file($outPath) || filesize($outPath) === 0) {
            @unlink($outPath);
            throw new \RuntimeException(
                "FFmpeg stretch produced no output for asset {$asset->id}: " . trim((string) $output)
            );
        }

        try {
            $s3->uploadPath($asset->file_path, $outPath, $contentType);
            $asset->update(['size_bytes' => filesize($outPath)]);

            Log::info('AssetProcessingJob: stretched asset to billboard size', [
                'asset_id' => $asset->id,
                'from'     => "{$metadata['width']}x{$metadata['height']}",
                'to'       => "{$targetW}x{$targetH}",
            ]);
        } finally {
            @unlink($outPath);
        }
    }

    /**
     * Enforce the 8–15 second display window constraint from the spot economy spec.
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
