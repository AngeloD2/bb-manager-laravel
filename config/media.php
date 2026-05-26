<?php

/*
|--------------------------------------------------------------------------
| BCC Media Configuration
|--------------------------------------------------------------------------
|
| FFmpeg/FFprobe binary paths and any media processing settings.
| Values are read from the environment, defaulting to standard Linux paths.
|
*/

return [

    'ffmpeg_binaries'  => env('FFMPEG_BINARIES',  '/usr/bin/ffmpeg'),
    'ffprobe_binaries' => env('FFPROBE_BINARIES', '/usr/bin/ffprobe'),

    /*
    |----------------------------------------------------------------------
    | Display window constraints (mirrors the Token Economy spec)
    |----------------------------------------------------------------------
    */
    'min_duration_secs' => 8,
    'max_duration_secs' => 15,

    /*
    |----------------------------------------------------------------------
    | S3 / CloudFront delivery
    |----------------------------------------------------------------------
    | cloudfront_url: if set, asset URLs are built from this CDN base
    |                 instead of generating short-lived S3 presigned URLs.
    |                 Example: https://d1abc123.cloudfront.net
    */
    'cloudfront_url' => env('CLOUDFRONT_URL', null),

];
