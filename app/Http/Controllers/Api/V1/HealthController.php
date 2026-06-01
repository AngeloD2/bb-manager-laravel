<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Check the health of Database, S3, and CloudFront.
     */
    public function index(Request $request): JsonResponse
    {
        $status = [
            'database'   => ['healthy' => false, 'message' => ''],
            's3'         => ['healthy' => false, 'message' => ''],
            'cloudfront' => ['healthy' => false, 'message' => ''],
        ];

        $overallHealthy = true;

        // 1. Database Check
        try {
            DB::connection()->getPdo();
            $status['database']['healthy'] = true;
            $status['database']['message'] = 'Connected';
        } catch (\Exception $e) {
            $status['database']['message'] = $e->getMessage();
            $overallHealthy = false;
        }

        // 2. S3 Check
        try {
            $s3Disk = Storage::disk('s3');
            $s3Client = $s3Disk->getClient();
            $bucket = config('filesystems.disks.s3.bucket');
            
            if (!$bucket) {
                throw new \Exception('S3 bucket is not configured.');
            }

            $s3Client->headBucket(['Bucket' => $bucket]);
            $status['s3']['healthy'] = true;
            $status['s3']['message'] = 'Connected';
        } catch (\Exception $e) {
            $status['s3']['message'] = $e->getMessage();
            $overallHealthy = false;
        }

        // 3. CloudFront Check
        $cfUrl = config('media.cloudfront_url');

        if (!$cfUrl) {
            $status['cloudfront']['healthy'] = true;
            $status['cloudfront']['message'] = 'Not configured';
            $status['cloudfront']['configured'] = false;
        } else {
            $status['cloudfront']['configured'] = true;
            try {
                // HEAD request with a short timeout — 403/404 at root is fine,
                // we only care that DNS resolves and the distribution responds.
                $response = Http::timeout(5)->head($cfUrl);
                $status['cloudfront']['healthy'] = true;
                $status['cloudfront']['message'] = 'Reachable (HTTP ' . $response->status() . ')';
            } catch (\Exception $e) {
                $status['cloudfront']['message'] = $e->getMessage();
                $overallHealthy = false;
            }
        }

        return response()->json([
            'status'   => $overallHealthy ? 'healthy' : 'degraded',
            'services' => $status
        ], $overallHealthy ? 200 : 503);
    }
}
