<?php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Str;

/**
 * S3Service
 *
 * Centralises all AWS S3 interactions:
 *  - Upload files to S3 from the Laravel backend.
 *  - Generate signed GET URLs for secure CDN delivery.
 *  - Build the S3 object key for a new asset.
 *  - Delete objects from S3.
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
     * Generate a presigned PUT URL for direct-to-S3 client uploads.
     */
    public function presignedPutUrl(string $key, string $contentType, int $ttlSeconds = 900): string
    {
        $cmd = $this->client->getCommand('PutObject', [
            'Bucket'      => $this->bucket,
            'Key'         => $key,
            'ContentType' => $contentType,
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
     * Retrieve metadata for an object to verify existence and capture size.
     */
    public function headObject(string $key): ?array
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);

            return [
                'size'        => $result['ContentLength'] ?? null,
                'contentType' => $result['ContentType'] ?? null,
            ];
        } catch (\Aws\S3\Exception\S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
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
     * Upload a file from a local filesystem path to S3, overwriting any
     * existing object at $objectKey. Used by background processing (e.g.
     * AssetProcessingJob replacing an asset with a stretched re-encode).
     */
    public function uploadPath(string $objectKey, string $localPath, string $contentType): void
    {
        $params = [
            'Bucket'               => $this->bucket,
            'Key'                  => $objectKey,
            'SourceFile'           => $localPath,
            'ContentType'          => $contentType,
            'ServerSideEncryption' => 'aws:kms',
        ];

        if ($this->kmsKeyId) {
            $params['SSEKMSKeyId'] = $this->kmsKeyId;
        }

        $this->client->putObject($params);
    }
}
