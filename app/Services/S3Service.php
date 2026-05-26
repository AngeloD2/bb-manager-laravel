<?php

namespace App\Services;

use Aws\S3\S3Client;
use Aws\S3\PostObjectV4;
use Illuminate\Support\Str;

/**
 * S3Service
 *
 * Centralises all AWS S3 interactions:
 *  - Generate presigned POST/PUT URLs for direct browser/app → S3 uploads.
 *  - Generate signed GET URLs for secure CDN delivery.
 *  - Build the S3 object key for a new asset.
 *
 * All objects are stored with SSE-KMS encryption using the key configured
 * in AWS_KMS_KEY_ID.
 */
class S3Service
{
    private S3Client $client;
    private string   $bucket;
    private ?string  $kmsKeyId;

    public function __construct()
    {
        $this->bucket   = config('filesystems.disks.s3.bucket');
        $this->kmsKeyId = config('filesystems.disks.s3.kms_key_id');

        $this->client = new S3Client([
            'version'     => 'latest',
            'region'      => config('filesystems.disks.s3.region'),
            'credentials' => [
                'key'    => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);
    }

    /**
     * Generate a presigned PUT URL so the Expo app can upload directly to S3
     * without routing the binary payload through Laravel.
     *
     * @param  string  $objectKey   S3 key returned by buildObjectKey()
     * @param  string  $mimeType    e.g. 'video/mp4', 'image/gif'
     * @param  int     $ttlSeconds  URL expiry window
     */
    public function presignedPutUrl(
        string $objectKey,
        string $mimeType,
        int $ttlSeconds = 300
    ): array {
        $params = [
            'Bucket'               => $this->bucket,
            'Key'                  => $objectKey,
            'ContentType'          => $mimeType,
            'ServerSideEncryption' => 'aws:kms',
        ];

        if ($this->kmsKeyId) {
            $params['SSEKMSKeyId'] = $this->kmsKeyId;
        }

        $cmd = $this->client->getCommand('PutObject', $params);
        $presigned = $this->client->createPresignedRequest($cmd, "+{$ttlSeconds} seconds");

        return [
            'upload_url' => (string) $presigned->getUri(),
            'object_key' => $objectKey,
            'expires_in' => $ttlSeconds,
            'method'     => 'PUT',
        ];
    }

    /**
     * Generate a presigned GET URL for temporary asset delivery
     * (used when CloudFront is not configured).
     */
    public function presignedGetUrl(string $objectKey, int $ttlSeconds = 3600): string
    {
        $cmd = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $objectKey,
        ]);

        return (string) $this->client->createPresignedRequest(
            $cmd,
            "+{$ttlSeconds} seconds"
        )->getUri();
    }

    /**
     * Delete an object from S3 (called when an asset is deleted from admin).
     */
    public function deleteObject(string $objectKey): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $objectKey,
        ]);
    }

    /**
     * Build a deterministic, human-readable S3 key for a new asset.
     * Pattern: media/{year}/{month}/{uuid}-{slug}
     */
    public function buildObjectKey(string $filename): string
    {
        $ext  = pathinfo($filename, PATHINFO_EXTENSION);
        $slug = Str::slug(pathinfo($filename, PATHINFO_FILENAME));
        $uuid = Str::uuid();

        return sprintf(
            'media/%s/%s/%s-%s.%s',
            now()->format('Y'),
            now()->format('m'),
            $uuid,
            $slug,
            strtolower($ext)
        );
    }

    /**
     * Upload a file directly to S3.
     */
    public function upload(string $objectKey, \Illuminate\Http\UploadedFile $file): void
    {
        $params = [
            'Bucket'               => $this->bucket,
            'Key'                  => $objectKey,
            'SourceFile'           => $file->getRealPath(),
            'ContentType'          => $file->getMimeType(),
            'ServerSideEncryption' => 'aws:kms',
        ];

        if ($this->kmsKeyId) {
            $params['SSEKMSKeyId'] = $this->kmsKeyId;
        }

        $this->client->putObject($params);
    }
}
