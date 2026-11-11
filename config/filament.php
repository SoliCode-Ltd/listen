<?php

return [

    /*
    | Where the admin's file uploads are kept when a field names no disk (the site
    | settings' logo and favicon, for example): the public disk, as before Filament 4,
    | whose own default is the private local disk.
    */

    'default_filesystem_disk' => env('FILAMENT_FILESYSTEM_DISK', 'public'),

];
