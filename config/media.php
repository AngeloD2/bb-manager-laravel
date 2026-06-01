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
    | Billboard canvas dimensions
    |----------------------------------------------------------------------
    | Target output resolution fed to the billboard (4K UHD, 16:9). When an
    | asset is uploaded with the "stretch to fit" toggle enabled,
    | AssetProcessingJob re-encodes the media to exactly these dimensions.
    | Aspect ratio is NOT preserved — the source is stretched to fill it.
    */
    'billboard_width'  => (int) env('BILLBOARD_WIDTH', 3840),
    'billboard_height' => (int) env('BILLBOARD_HEIGHT', 2160),

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
