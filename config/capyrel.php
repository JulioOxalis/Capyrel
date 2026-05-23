<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    | Which disk to use for uploaded files. Defaults to FILESYSTEM_DISK env.
    | Use 'public' for local storage (run: php artisan storage:link).
    */
    'storage_disk' => env('CAPYREL_STORAGE_DISK', env('FILESYSTEM_DISK', 'public')),

    /*
    |--------------------------------------------------------------------------
    | Image Processing
    |--------------------------------------------------------------------------
    | Options for auto-detected image columns (avatar, photo, cover, etc.).
    | Compression requires intervention/image: composer require intervention/image
    */
    'images' => [
        'max_width'  => (int) env('CAPYREL_IMAGE_MAX_WIDTH', 1200),
        'max_height' => (int) env('CAPYREL_IMAGE_MAX_HEIGHT', 1200),
        'quality'    => (int) env('CAPYREL_IMAGE_QUALITY', 80),
        'format'     => env('CAPYREL_IMAGE_FORMAT', 'webp'),  // webp | jpg | original
        'max_kb'     => (int) env('CAPYREL_IMAGE_MAX_KB', 5120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    | per_page: rows per page.
    | type: paginate | cursor | simple
    |   cursor  — no "page N of M" but faster on large tables (no COUNT(*))
    |   simple  — prev/next only, one extra SQL query
    */
    'pagination' => [
        'per_page' => (int) env('CAPYREL_PER_PAGE', 15),
        'type'     => env('CAPYREL_PAGINATION', 'paginate'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generated Controller Features
    |--------------------------------------------------------------------------
    */
    'features' => [
        // Wrap create/update in DB::transaction() when BTM pivot syncs are present
        'transaction_pivots' => (bool) env('CAPYREL_TRANSACTION_PIVOTS', true),

        // Add $request->wantsJson() dual responses to every action
        'json_responses'     => (bool) env('CAPYREL_JSON_RESPONSES', true),

        // Generate search block in index() for detected string columns
        'search'             => (bool) env('CAPYREL_SEARCH', true),

        // Auto-generate soft-delete methods (restore, forceDelete) when detected
        'soft_deletes'       => (bool) env('CAPYREL_SOFT_DELETES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Modal UX
    |--------------------------------------------------------------------------
    */
    'modals' => [
        // Require typing the record name before confirming delete
        'delete_typing' => (bool) env('CAPYREL_DELETE_TYPING', true),

        // Warn user when closing a modal with unsaved changes
        'unsaved_warning' => (bool) env('CAPYREL_UNSAVED_WARNING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spatie Integrations (auto-detected from composer.json)
    |--------------------------------------------------------------------------
    | These are detected automatically. Set to false to opt out even if
    | the package is installed.
    */
    'spatie' => [
        'media_library'  => (bool) env('CAPYREL_SPATIE_MEDIA', true),
        'permissions'    => (bool) env('CAPYREL_SPATIE_PERMISSIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Scout
    |--------------------------------------------------------------------------
    */
    'scout' => [
        'enabled' => (bool) env('CAPYREL_SCOUT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Adapter System
    |--------------------------------------------------------------------------
    | The UI adapter controls how UI Contracts are rendered into Blade views.
    |
    | adapter: name of the globally active adapter (blade-basic by default).
    |          Install third-party adapters via Composer and set the name here.
    |
    | model_adapters: per-model overrides — takes precedence over the global
    |          adapter. Keyed by model name (e.g. 'Post' => 'blade-social').
    |
    | Available built-in adapters: blade-basic
    */
    'ui' => [
        'adapter' => env('CAPYREL_UI_ADAPTER', 'blade-basic'),

        'model_adapters' => [
            // 'Post'    => 'blade-social',
            // 'Product' => 'blade-marketplace',
        ],
    ],

];
