<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes only, so pages stay as fast at millions of rows as they are today:
 *
 * - a member's own voices in order (the studio's history, its "latest" check every
 *   few seconds, "Show older voices", the API's history, the credits breakdown);
 * - each member's first voice (Monthly usage → "First-time creators");
 * - the column every admin list opens sorted by (newest first), which otherwise
 *   sorts the whole table on each page load.
 *
 * Nothing else changes. Each index is added only when its table and columns are
 * there and it is not there yet, so this is safe to run again. On MySQL each is
 * built in place while the table stays in use (ALGORITHM=INPLACE, LOCK=NONE): if
 * that is not possible MySQL refuses at once rather than locking the table.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> table => indexes (their columns) */
    private const INDEXES = [
        'audio_generations' => [['user_id', 'id'], ['user_id', 'created_at'], ['created_at']],
        'users' => [['created_at']],
        'demo_voice_generations' => [['created_at']],
        'agent_conversations' => [['started_at']],
        'agent_contacts' => [['last_contact_at']],
        'agents' => [['created_at']],
        'subscriptions' => [['created_at']],
        'text_items' => [['created_at']],
        'usage_counters' => [['total_chars'], ['period_ym']],
        'generation_errors' => [['last_seen_at']],
        'demo_fraud_signals' => [['created_at']],
        'demo_identity_links' => [['created_at']],
        'social_share_logs' => [['created_at']],
        'referral_conversions' => [['created_at']],
        'cloned_voices' => [['created_at']],
        'designed_voices' => [['created_at']],
        'contacts' => [['created_at']],
        'video_dubs' => [['created_at']],
        'manual_payment_requests' => [['submitted_at']],
        'newsletter_suppressions' => [['unsubscribed_at']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $columns) {
                $name = self::name($table, $columns);
                if (! Schema::hasColumns($table, $columns) || $this->has($table, $name)) {
                    continue;
                }
                if (DB::getDriverName() === 'mysql') {
                    $list = implode(', ', array_map(fn (string $c): string => '`'.$c.'`', $columns));
                    DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$list}), ALGORITHM=INPLACE, LOCK=NONE");
                } else {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($indexes as $columns) {
                $name = self::name($table, $columns);
                if ($this->has($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }

    /** As Laravel names them: audio_generations_user_id_id_index (App\Models\AudioGeneration::MEMBER_ID_INDEX). */
    private static function name(string $table, array $columns): string
    {
        return strtolower($table.'_'.implode('_', $columns).'_index');
    }

    private function has(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
