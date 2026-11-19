<?php

use App\Support\OnlineSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An index only, so the sweeps for stuck voices (listen:expire-stuck-generations and
 * api:expire-stuck-generations, every five minutes) find the few voices still "queued" or
 * "processing" at once, instead of reading every voice ever made, four times a run.
 *
 * Nothing else changes. It is added only when it is not there yet, so this is safe to run
 * again. On MySQL it is built in place while the table stays in use (ALGORITHM=INPLACE,
 * LOCK=NONE): if that is not possible MySQL refuses at once rather than locking the table.
 * It waits a few seconds at most for a busy table, so members' pages are never held up
 * behind it (App\Support\OnlineSchema).
 */
return new class extends Migration
{
    private const TABLE = 'audio_generations';

    private const COLUMNS = ['status', 'updated_at'];

    /** As Laravel names it. */
    public const NAME = 'audio_generations_status_updated_at_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumns(self::TABLE, self::COLUMNS) || $this->has()) {
            return;
        }

        OnlineSchema::change(function (): void {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE `'.self::TABLE.'` ADD INDEX `'.self::NAME.'` (`status`, `updated_at`), ALGORITHM=INPLACE, LOCK=NONE');
            } else {
                Schema::table(self::TABLE, fn (Blueprint $t) => $t->index(self::COLUMNS, self::NAME));
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE) && $this->has()) {
            Schema::table(self::TABLE, fn (Blueprint $t) => $t->dropIndex(self::NAME));
        }
    }

    private function has(): bool
    {
        return collect(Schema::getIndexes(self::TABLE))->contains(fn (array $index): bool => $index['name'] === self::NAME);
    }
};
