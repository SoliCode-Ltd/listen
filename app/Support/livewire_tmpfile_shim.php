<?php

namespace Livewire\Features\SupportFileUploads;

if (! function_exists(__NAMESPACE__.'\\tmpfile')) {
    /**
     * Fallback for environments where the global tmpfile() function is disabled.
     *
     * Livewire calls tmpfile() unqualified inside this namespace. This shim keeps
     * uploads working on locked-down hosts while preserving normal behavior when
     * tmpfile() is available.
     *
     * @return resource|false
     */
    function tmpfile()
    {
        if (\function_exists('tmpfile')) {
            return \tmpfile();
        }

        $tmpDir = \ini_get('upload_tmp_dir') ?: \sys_get_temp_dir();
        $path = \tempnam($tmpDir, 'lwtmp_');

        if ($path === false) {
            return false;
        }

        $handle = \fopen($path, 'w+b');

        if ($handle === false) {
            @\unlink($path);

            return false;
        }

        // Best-effort cleanup for fallback temporary files.
        \register_shutdown_function(static function () use ($path): void {
            @\unlink($path);
        });

        return $handle;
    }
}

