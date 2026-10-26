<?php

use App\Support\SeoSettingDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saving the admin's SEO settings page used to save every field, so the
     * site's own titles, descriptions, llms.txt and landing content were kept
     * as copies that win over any later improvement to that text. The page now
     * keeps only what differs from the site's text; this lets go of the copies
     * saved before. Each one says the same as the site's text today, so no page
     * changes; a value written differently in the admin stays as it is.
     */
    public function up(): void
    {
        if (! Schema::hasTable('site_settings')) {
            return;
        }
        SeoSettingDefaults::forgetSavedCopies();
    }

    public function down(): void
    {
        // The copies only repeated the site's own text; there is nothing to put back.
    }
};
