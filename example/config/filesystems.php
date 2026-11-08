<?php

/*
| Only what differs from the framework's config/filesystems.php; Laravel
| merges the "disks" below into its own (local, public, s3).
*/
return [

    /*
    | Where uploaded files (sponsor banners) go: "public" (storage/app/public,
    | served at /storage) or "s3" for any S3-compatible bucket (AWS S3,
    | DigitalOcean Spaces, Cloudflare R2, MinIO). Use s3 when the container
    | disk is not kept, as on DigitalOcean App Platform.
    */
    'uploads' => env('UPLOADS_DISK', 'public'),

    'disks' => [

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET'),
            // Public base URL of the bucket (or its CDN), used for links to uploads.
            'url' => env('AWS_URL'),
            // Set for anything that is not AWS, e.g. https://ams3.digitaloceanspaces.com
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // Uploads are shown to visitors, so they are written public-read. Set
            // AWS_VISIBILITY to empty for buckets that reject ACLs (AWS buckets with
            // "bucket owner enforced"); make them readable with a bucket policy instead.
            'visibility' => env('AWS_VISIBILITY', 'public') ?: null,
            // Fail the upload instead of saving a sponsor without its image.
            'throw' => true,
            'report' => false,
        ],

    ],

];
