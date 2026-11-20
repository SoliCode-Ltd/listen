<?php

use App\Support\OnlineSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things kept on each voice (audio_generations):
 *
 * - content_check: what the reading of its text found before the voice was made
 *   (App\Services\ContentSafety\TextContentCheck): "allowed", "refused", or "unchecked"
 *   when the reading could not be done, so it is read again later
 *   (listen:recheck-unchecked-texts). Indexed, so the few unchecked ones are found at once.
 * - hidden_at, hidden_by, hidden_note: a voice an admin hid from the member
 *   (App\Support\HiddenAudio). The member no longer sees or plays it; the row, its text
 *   and its file stay for the admin.
 *
 * Columns are only added when missing, so this is safe to run again; it waits a few
 * seconds at most for a busy table (App\Support\OnlineSchema).
 */
return new class extends Migration
{
    private const TABLE = 'audio_generations';

    public const INDEX = 'audio_generations_content_check_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        OnlineSchema::change(function (): void {
            // One column at a time, so a run that stopped half way finishes the rest.
            $missing = fn (string $column): bool => ! Schema::hasColumn(self::TABLE, $column);
            Schema::table(self::TABLE, function (Blueprint $table) use ($missing): void {
                if ($missing('content_check')) {
                    $table->string('content_check', 12)->nullable();
                }
                if ($missing('hidden_at')) {
                    $table->timestamp('hidden_at')->nullable();
                }
                if ($missing('hidden_by')) {
                    $table->unsignedBigInteger('hidden_by')->nullable();
                }
                if ($missing('hidden_note')) {
                    $table->string('hidden_note', 255)->nullable();
                }
            });
        });

        if (! $this->hasIndex()) {
            OnlineSchema::change(fn () => Schema::table(self::TABLE, fn (Blueprint $table) => $table->index('content_check', self::INDEX)));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        if ($this->hasIndex()) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }
        foreach (['content_check', 'hidden_at', 'hidden_by', 'hidden_note'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    private function hasIndex(): bool
    {
        return collect(Schema::getIndexes(self::TABLE))->contains(fn (array $index): bool => $index['name'] === self::INDEX);
    }
};
