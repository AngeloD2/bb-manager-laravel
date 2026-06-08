<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use App\Services\DeviceNotifier;
use Illuminate\Support\Facades\Storage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Removes media assets whose optimization/conversion failed.
 *
 * AssetProcessingJob::failed() records a sync_error (and leaves is_synced false)
 * once all retries are exhausted. Those files are dead weight on S3, so this
 * command — scheduled every 12 minutes (see routes/console.php) — deletes both
 * the S3 object and the asset record.
 */
class CleanupFailedAssets extends Command
{
    protected $signature = 'assets:cleanup-failed';

    protected $description = 'Delete media assets whose optimization/conversion failed (S3 object + record).';

    public function handle(DeviceNotifier $notifier): int
    {
        $failed = MediaAsset::whereNotNull('sync_error')
            ->where('is_synced', false)
            ->get();

        if ($failed->isEmpty()) {
            $this->info('No failed assets to clean up.');
            return self::SUCCESS;
        }

        foreach ($failed as $asset) {
            // Attempt S3 cleanup; log failures but still drop the record.
            try {
                if ($asset->file_path) {
                    Storage::disk('s3')->delete($asset->file_path);
                }
            } catch (\Throwable $e) {
                Log::warning('CleanupFailedAssets: S3 delete failed', [
                    'asset_id'  => $asset->id,
                    'file_path' => $asset->file_path,
                    'error'     => $e->getMessage(),
                ]);
            }

            $assetId = $asset->id;
            $asset->delete();

            Log::info('CleanupFailedAssets: removed failed asset', ['asset_id' => $assetId]);
            $this->line("Removed failed asset {$assetId}");
        }

        // Let connected clients refresh their library now that the rows are gone.
        $notifier->notifyScheduleChanged();

        $this->info("Cleaned up {$failed->count()} failed asset(s).");
        return self::SUCCESS;
    }
}
