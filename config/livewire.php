<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Livewire Temporary Uploads
    |--------------------------------------------------------------------------
    |
    | Keep temporary uploads on the public disk by default to avoid
    | permission issues on hosts where storage/app/private is not writable.
    |
    */
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMP_DISK', 'livewire-tmp'),
        'directory' => env('LIVEWIRE_TEMP_DIRECTORY', 'livewire-tmp'),
        'rules' => ['file', 'max:5120'],
    ],
];
