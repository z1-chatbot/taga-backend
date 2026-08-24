<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),

            /*
             * Off, and it matters. `serve => true` makes Laravel register a
             * `GET storage/{path}` route for signed temporary URLs to this
             * private disk — and that route squats on the exact path the public
             * disk is served from, shadowing it and answering every unsigned
             * request with 403.
             *
             * That is what was 403-ing banners: the request reached PHP and
             * Laravel refused it for want of a signature, which looks exactly
             * like a web-server permission problem and sends you hunting
             * through file modes and symlink options for it.
             *
             * Nothing here generates signed URLs anyway. Prescriptions and
             * licence documents are streamed by authenticated controllers, which
             * is stricter than a signature and does not need this.
             */
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            /*
             * /media, not Laravel's default /storage.
             *
             * This host blocks the /storage prefix at the web server, before
             * PHP runs: a request for a file that does not even exist came back
             * 403 from LiteSpeed with no x-powered-by header, while the same
             * .jpg under any other prefix reached Laravel and 404'd properly.
             * It is a sensible rule for them to have — /storage is where a
             * default Laravel install leaks logs and uploads — and not one we
             * can switch off, so the prefix is simply not usable here.
             *
             * Stored values are disk-relative, so this changes the URL without
             * touching a single row.
             */
            'url' => env('APP_URL').'/media',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
