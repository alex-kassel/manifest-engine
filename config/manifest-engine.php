<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Locks Storage Directory
    |--------------------------------------------------------------------------
    |
    | Defines the directory where OS-level advisory lock files are stored.
    | Storing lock files in a dedicated cache/storage folder keeps project
    | roots and git status clean from hidden .lock files.
    |
    */
    'locks_directory' => function_exists('storage_path')
        ? storage_path('framework/manifest-locks')
        : sys_get_temp_dir().DIRECTORY_SEPARATOR.'manifest-locks',

    /*
    |--------------------------------------------------------------------------
    | JSON Serialization Flags
    |--------------------------------------------------------------------------
    |
    | Flags used when serializing manifest files to disk. Formatted for
    | human readability and minimal git diff churn.
    |
    */
    'json_encode_flags' => JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,

    /*
    |--------------------------------------------------------------------------
    | Registered Manifests
    |--------------------------------------------------------------------------
    |
    | Manifest definitions that should be automatically registered on application
    | boot, mapping alias names to their target relative filenames and schemas.
    |
    */
    'manifests' => [
        // 'workspace' => [
        //     'filename' => 'workspace.json',
        //     'schema' => App\Schemas\WorkspaceSchema::class,
        //     'description' => 'Application workspace metadata',
        // ],
    ],
];
