<?php

namespace App\Support\InApp;

use Illuminate\Translation\Translator;

/**
 * Inside the Listen app (InApp) the site's own lines that point to buying a
 * plan say what is true and stop there: lang/<locale>/in_app.php holds, for
 * each such line's key, what the app says instead. They replace the lines for
 * this request only, so a page, a script's texts and a JSON reply all get
 * them without each having to remember.
 */
class InAppWording
{
    public static function apply(): void
    {
        /** @var Translator $translator */
        $translator = app('translator');

        foreach (['ar', 'en'] as $locale) {
            $lines = require lang_path($locale.'/in_app.php');
            // A group must be read whole before lines are put over it.
            foreach (array_unique(array_map(fn (string $key): string => explode('.', $key, 2)[0], array_keys($lines))) as $group) {
                $translator->load('*', $group, $locale);
            }
            $translator->addLines($lines, $locale);
        }
    }
}
