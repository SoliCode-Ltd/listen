<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A transcript's words as plain text, for the member's search (Transcription
 * keeps it in step with its paragraphs): searching the paragraphs' stored data
 * matched its field names and numbers ("end", "text", "12") and missed words
 * written with a "/". Transcripts made before it are filled in here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transcriptions')) {
            return;
        }
        if (! Schema::hasColumn('transcriptions', 'search_text')) {
            Schema::table('transcriptions', function (Blueprint $table): void {
                $table->mediumText('search_text')->nullable()->after('paragraphs');
            });
        }

        DB::table('transcriptions')->whereNotNull('paragraphs')->whereNull('search_text')->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $paragraphs = json_decode((string) $row->paragraphs, true);
                    $text = is_array($paragraphs)
                        ? implode("\n", array_map(fn ($p) => is_array($p) ? (string) ($p['text'] ?? '') : '', $paragraphs))
                        : '';
                    DB::table('transcriptions')->where('id', $row->id)->update(['search_text' => $text]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('transcriptions') && Schema::hasColumn('transcriptions', 'search_text')) {
            Schema::table('transcriptions', function (Blueprint $table): void {
                $table->dropColumn('search_text');
            });
        }
    }
};
