<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('storage:configure-cors')]
#[Description('Configure CORS policy for the S3/R2 storage bucket to allow cross-origin requests')]
class ConfigureBucketCors extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Initializing S3/R2 Client...');
        try {
            $s3 = Storage::disk('s3')->getClient();
            $bucket = config('filesystems.disks.s3.bucket');

            if (!$bucket) {
                $this->error('Bucket configuration is not set.');
                return Command::FAILURE;
            }

            $this->info("Setting CORS configuration on bucket: {$bucket}...");

            $s3->putBucketCors([
                'Bucket' => $bucket,
                'CORSConfiguration' => [
                    'CORSRules' => [
                        [
                            'AllowedHeaders' => ['*'],
                            'AllowedMethods' => ['GET', 'HEAD', 'PUT', 'POST', 'DELETE'],
                            'AllowedOrigins' => ['http://localhost:5173'],
                            'MaxAgeSeconds'  => 3600,
                        ],
                    ],
                ],
            ]);

            $this->info('CORS policy successfully configured for the storage bucket!');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to configure CORS: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
