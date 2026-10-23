<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plugin secret
    |--------------------------------------------------------------------------
    |
    | Shared with the Aviato connector, in the Standard Webhooks format (whsec_…).
    | Every request the connector sends to this application is signed with it and
    | requests with a missing, invalid or stale signature are rejected.
    |
    */

    'secret' => env('AVIATO_PLUGIN_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The plugin endpoints are mounted under this path, outside the `web`
    | middleware group (no session, no CSRF). `plugin_url` is the public URL
    | of those endpoints as the connector reaches them; it defaults to APP_URL
    | followed by the base path.
    |
    */

    'base_path' => env('AVIATO_BASE_PATH', 'aviato'),

    'plugin_url' => env('AVIATO_PLUGIN_URL'),

    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | One-time file downloads
    |--------------------------------------------------------------------------
    |
    | Files produced by actions are kept in this cache store until the connector
    | downloads them once, or until they expire (seconds). Use a store shared
    | by every worker of the application (file, database, redis…).
    |
    */

    'files' => [
        'cache_store' => env('AVIATO_FILES_CACHE_STORE'),
        'ttl' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Relation hints
    |--------------------------------------------------------------------------
    |
    | Relations declared on these Eloquent models are sent to the connector, which
    | uses them next to the foreign keys it finds in the database. Leave
    | `models` empty to discover the models in app/Models.
    |
    */

    'relations' => [
        'enabled' => env('AVIATO_RELATION_HINTS', true),
        'models' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Connector
    |--------------------------------------------------------------------------
    |
    | `php artisan aviato:install` downloads the connector binary for the current
    | platform from `download_url` ({version}, {os}, {arch} and {ext} are
    | replaced) and `php artisan aviato:serve` runs it next to your app.
    |
    */

    'connector' => [
        'version' => env('AVIATO_CONNECTOR_VERSION', env('AVIATO_AGENT_VERSION', '0.5.1')),
        'download_url' => env('AVIATO_CONNECTOR_DOWNLOAD_URL', 'https://github.com/getaviato/connector/releases/download/v{version}/aviato-{os}-{arch}{ext}'),
        // Optional: a URL returning the SHA-256 of the binary (same placeholders plus {url}).
        'checksum_url' => env('AVIATO_CONNECTOR_CHECKSUM_URL', env('AVIATO_AGENT_CHECKSUM_URL')),
        'path' => env('AVIATO_CONNECTOR_PATH', env('AVIATO_AGENT_PATH')),
        'data_dir' => env('AVIATO_DATA_DIR'),
        'control_plane_url' => env('AVIATO_CONTROL_PLANE_URL'),
        'token' => env('AVIATO_CONNECTOR_TOKEN', env('AVIATO_AGENT_TOKEN')),
        // Without a token, `aviato:serve --dev` runs your own development environment of this project.
        'project' => env('AVIATO_PROJECT'),
        // Defaults to a URL built from the connection below (the default connection when null).
        'database_url' => env('AVIATO_DATABASE_URL'),
        'connection' => env('AVIATO_DB_CONNECTION'),
        // Extra environment variables passed to the connector.
        'env' => [],
    ],

];
