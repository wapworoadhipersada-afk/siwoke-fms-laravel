<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | Evidence files live on a private disk and are only ever served through a controller that
    | checks who is asking. Point this at an S3-compatible disk in production.
    |
    */

    'disk' => env('ATTACHMENTS_DISK', 'attachments'),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | The mobile app is expected to downscale before uploading. These limits protect the server
    | from oversized files and from images crafted to exhaust memory when decoded.
    |
    */

    'max_upload_kilobytes' => (int) env('ATTACHMENTS_MAX_UPLOAD_KB', 8192),

    'max_image_pixels' => (int) env('ATTACHMENTS_MAX_PIXELS', 30_000_000),

    'max_image_edge' => 12_000,

    'min_image_edge' => 100,

    /*
    |--------------------------------------------------------------------------
    | Stored Image Format
    |--------------------------------------------------------------------------
    |
    | Every upload is decoded and re-encoded, which discards anything hidden in the original
    | file (EXIF, scripts, trailing data). Photos become JPEG, signatures PNG.
    |
    */

    'photo_max_edge' => 2560,

    'photo_quality' => 85,

    'signature_max_width' => 1024,

    'thumbnail_edge' => 400,

    'thumbnail_quality' => 75,

    /*
    |--------------------------------------------------------------------------
    | Per Visit
    |--------------------------------------------------------------------------
    */

    'max_photos_per_kind' => 10,

    /*
    |--------------------------------------------------------------------------
    | Capture Time
    |--------------------------------------------------------------------------
    |
    | A photo taken offline is uploaded later, so its capture time may be in the past, within reason.
    |
    */

    'max_capture_age_days' => 7,

    'max_capture_future_minutes' => 5,

];
