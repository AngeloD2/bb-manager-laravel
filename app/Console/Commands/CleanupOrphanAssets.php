<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

#[Signature('assets:cleanup-orphans')]
#[Description('Sweep S3 for media uploads that were presigned and PUT but never confirmed')]
class CleanupOrphanAssets extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $disk = Storage::disk('s3');
        $this->info('Scanning S3 for orphan assets...');

        $files = $disk->allFiles('media');
        $deleted = 0;

        foreach ($files as $file) {
            $lastModified = Carbon::createFromTimestamp($disk->lastModified($file));

            if ($lastModified->diffInHours(now()) < 1) {
                continue;
            }

            $exists = MediaAsset::where('file_path', $file)->exists();

            if (!$exists) {
                $this->info("Deleting orphan file: {$file}");
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Finished. Deleted {$deleted} orphan files.");
    }
}
