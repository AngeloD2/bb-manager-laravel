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
 *  1. Download the file from S3 to a local temp file.
 *  2. Extract duration and resolution metadata via FFprobe.
 *  3. Clamp duration to the 8–15 second display window rule.
 *  4. Optionally stretch the media to the billboard's exact dimensions.
 *  5. Mark the asset as is_synced = true.
 *
 * IMPORTANT: Laravel Cloud containers cannot resolve Cloudflare R2 hostnames
 * via DNS, so ffprobe/ffmpeg cannot stream from presigned URLs. All media
 * processing uses local temp files downloaded via the PHP S3 SDK.
 *
 * On failure the job retries 3 times with exponential backoff.
 */
class AssetProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    // Large (up to ~500 MB) source files can take a while to transcode. Keep this
    // below the queue's retry_after (DB_QUEUE_RETRY_AFTER) so the job isn't
    // re-dispatched while still running.
    public int $timeout = 1800;

    public function __construct(
        private readonly string $assetId,
        private readonly bool $stretchToFit = false
    ) {}

    public function handle(): void
    {
        $asset = MediaAsset::findOrFail($this->assetId);

        Log::info("AssetProcessingJob: starting", [
            'asset_id'       => $asset->id,
            'stretch_to_fit' => $this->stretchToFit,
        ]);

        // ── Download source file from S3 once ──────────────────────────────
        $ext     = pathinfo($asset->file_path, PATHINFO_EXTENSION) ?: 'mp4';
        $srcFile = tempnam(sys_get_temp_dir(), 'bbsrc_') . '.' . $ext;

        try {
            $exists = Storage::disk('s3')->exists($asset->file_path);
            $s3Size = $exists ? Storage::disk('s3')->size($asset->file_path) : 0;

            Log::info('AssetProcessingJob: S3 diagnostics', [
                'asset_id'  => $asset->id,
                'file_path' => $asset->file_path,
                'exists'    => $exists,
                's3_size'   => $s3Size,
            ]);

            if (!$exists || $s3Size === 0) {
                throw new \RuntimeException(
                    "S3 file missing or empty: exists={$exists}, size={$s3Size}, path={$asset->file_path}"
                );
            }

            $stream = Storage::disk('s3')->readStream($asset->file_path);
            if (!$stream) {
                throw new \RuntimeException("Failed to open S3 read stream for {$asset->file_path}");
            }
            $target = fopen($srcFile, 'wb');
            if (!$target) {
                throw new \RuntimeException("Failed to open local temp file for writing: {$srcFile}");
            }
            stream_copy_to_stream($stream, $target);
            fclose($target);
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (!$asset->size_bytes) {
                $asset->size_bytes = $s3Size;
            }

            Log::info('AssetProcessingJob: downloaded source', [
                'asset_id'   => $asset->id,
                'local_size' => filesize($srcFile),
            ]);

            // ── Extract metadata ───────────────────────────────────────────
            $metadata = $this->extractMetadata($asset, $srcFile);

            // ── Process (resize / transcode) ───────────────────────────────
            $this->processMedia($asset, $metadata, $srcFile);

            $probedDuration = $metadata['duration'] ?? $asset->duration_secs;

            $asset->update([
                // Videos play for their full, ffprobe-measured length. The 8–15s
                // display-window clamp only applies to still media (photo/GIF),
                // which has no intrinsic duration and uses a fixed dwell time.
                'duration_secs' => $asset->file_type === 'VIDEO'
                    ? max(1, (int) round($probedDuration))
                    : $this->clampDuration($probedDuration),
                'size_bytes'    => $asset->size_bytes,
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

            $asset->update([
                'sync_error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            @unlink($srcFile);
        }
    }

    /**
     * Run FFprobe against a local file to extract media metadata.
     *
     * @return array{duration: float, width: int, height: int, codec: string}
     */
    private function extractMetadata(MediaAsset $asset, string $localPath): array
    {
        $ffprobe = config('media.ffprobe_binaries', '/usr/bin/ffprobe');

        $command = sprintf(
            '%s -v error -print_format json -show_streams -show_format %s 2>&1',
            escapeshellarg($ffprobe),
            escapeshellarg($localPath)
        );

        $output = shell_exec($command);

        if (!$output) {
            Log::warning('AssetProcessingJob: FFprobe unavailable, using defaults', ['asset_id' => $asset->id]);
            return ['duration' => (float) $asset->duration_secs, 'width' => 0, 'height' => 0, 'codec' => ''];
        }

        $data = json_decode($output, true);

        if (!$data || empty($data['streams'])) {
            throw new \RuntimeException(
                "ffprobe found no streams. file_size=" . filesize($localPath) . ", raw_output=" . trim((string) $output)
            );
        }

        $stream = collect($data['streams'])->firstWhere('codec_type', 'video');

        if (!$stream) {
            throw new \RuntimeException("No video/image stream detected by ffprobe.");
        }

        $duration = (float) ($data['format']['duration'] ?? $asset->duration_secs);

        return [
            'duration' => $duration,
            'width'    => (int) ($stream['width']  ?? 0),
            'height'   => (int) ($stream['height'] ?? 0),
            'codec'    => (string) ($stream['codec_name'] ?? ''),
        ];
    }

    /**
     * Process the asset using a local source file. If stretchToFit is true,
     * stretch it to exactly fill the billboard. Otherwise, optimize it and
     * proportionally scale it to fit within bounds. Uploads the result back to S3.
     *
     * @param array{duration: float, width: int, height: int, codec: string} $metadata
     */
    private function processMedia(MediaAsset $asset, array $metadata, string $localSrcPath): void
    {
        $targetW = (int) config('media.billboard_width', 3648);
        $targetH = (int) config('media.billboard_height', 1152);

        $w = $metadata['width'];
        $h = $metadata['height'];

        // Does this asset actually need an FFmpeg pass?
        //   stretch on  → only if it isn't already exactly the billboard size
        //   stretch off → only if it overflows the billboard bounds (needs a downscale)
        $needsResize = $this->stretchToFit
            ? ! ($w === $targetW && $h === $targetH)
            : ($w > $targetW || $h > $targetH);

        // The mobile app now compresses video to web-optimized H.264/MP4 before
        // upload, so re-encoding here would be a redundant second compression.
        // Skip whenever no resize is needed and the codec is already H.264
        // (images: skip when they don't need a resize either). Anything else —
        // a stretch, a downscale, or a non-H.264 codec (HEVC, etc.) — still needs
        // the pass, since that's a genuine transform, not gratuitous re-encoding.
        if (! $needsResize) {
            $isVideo  = $asset->file_type === 'VIDEO';
            $isH264   = $metadata['codec'] === 'h264';
            if (! $isVideo || $isH264) {
                Log::info('AssetProcessingJob: asset already optimized, skipping re-encode', [
                    'asset_id'   => $asset->id,
                    'file_type'  => $asset->file_type,
                    'codec'      => $metadata['codec'],
                    'dimensions' => "{$w}x{$h}",
                ]);
                return;
            }
        }

        $ffmpeg = config('media.ffmpeg_binaries', '/usr/bin/ffmpeg');

        [$ext, $contentType] = match ($asset->file_type) {
            'VIDEO' => ['mp4', 'video/mp4'],
            'GIF'   => ['gif', 'image/gif'],
            default => ['jpg', 'image/jpeg'],
        };

        $outPath = tempnam(sys_get_temp_dir(), 'bbmedia_') . '.' . $ext;

        if ($this->stretchToFit) {
            // setsar=1 forces square pixels so players don't "correct" the stretch
            $vf = sprintf('scale=%d:%d,setsar=1', $targetW, $targetH);
        } else {
            // Proportional scale to fit inside target dimensions
            $vf = sprintf("scale='min(%d,iw)':'min(%d,ih)':force_original_aspect_ratio=decrease", $targetW, $targetH);
        }

        if ($asset->file_type === 'VIDEO') {
            // libx264 requires even dimensions for yuv420p
            $vf .= ',pad=ceil(iw/2)*2:ceil(ih/2)*2';
            $command = sprintf(
                '%s -y -i %s -vf %s -c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p -movflags +faststart -c:a aac -b:a 128k %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($localSrcPath),
                escapeshellarg($vf),
                escapeshellarg($outPath)
            );
        } else {
            // GIF and stills: single-pass filter, no audio stream involved.
            $command = sprintf(
                '%s -y -i %s -vf %s %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($localSrcPath),
                escapeshellarg($vf),
                escapeshellarg($outPath)
            );
        }

        $output = shell_exec($command);

        if (! is_file($outPath) || filesize($outPath) === 0) {
            @unlink($outPath);
            throw new \RuntimeException(
                "FFmpeg processing produced no output for asset {$asset->id}: " . trim((string) $output)
            );
        }

        try {
            $oldPath = $asset->file_path;
            
            // Update the extension (e.g. .mov to .mp4)
            $pathParts = pathinfo($oldPath);
            $dir = $pathParts['dirname'] === '.' ? '' : $pathParts['dirname'] . '/';
            $newPath = $dir . $pathParts['filename'] . '.' . $ext;

            Storage::disk('s3')->putFileAs('', new \Illuminate\Http\File($outPath), $newPath);
            
            if ($oldPath !== $newPath) {
                Storage::disk('s3')->delete($oldPath);
            }

            $asset->update([
                'size_bytes' => filesize($outPath),
                'file_path'  => $newPath,
            ]);

            Log::info('AssetProcessingJob: optimized asset', [
                'asset_id' => $asset->id,
                'from'     => "{$metadata['width']}x{$metadata['height']}",
                'to'       => $this->stretchToFit ? "{$targetW}x{$targetH}" : 'proportional',
            ]);
        } finally {
            @unlink($outPath);
        }
    }

    /**
     * Enforce the 8–15 second display window for still media (photo/GIF), which
     * has no intrinsic duration. Videos are exempt and keep their real length.
     */
    private function clampDuration(float $seconds): int
    {
        return (int) max(8, min(15, round($seconds)));
    }

    public function backoff(): array
    {
        return [30, 60, 120]; // seconds between retries
    }

    /**
     * Handle a job failure after all retries are exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = MediaAsset::find($this->assetId);
        if ($asset) {
            $asset->update([
                'sync_error' => $exception->getMessage(),
            ]);

            // Notify clients of the failure
            app(\App\Services\DeviceNotifier::class)->notifyScheduleChanged();
        }
    }
}
