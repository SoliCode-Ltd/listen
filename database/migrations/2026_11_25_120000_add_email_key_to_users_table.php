<?php

use App\Support\MailboxKey;
use App\Support\OnlineSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The inbox each address reaches (App\Support\MailboxKey), so the free demo
 * counts jane.doe+2@gmail.com and janedoe@gmail.com as one person. Only adds:
 * a nullable column at the end of the table (on MySQL without copying the
 * table, or not at all) and its index (built while the table stays in use),
 * then fills it in for the accounts already there, a thousand at a time. Each
 * change waits a few seconds at most for a busy table, so members' pages are
 * never held up behind it (App\Support\OnlineSchema). Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'email_key')) {
            OnlineSchema::change(function (): void {
                if (DB::getDriverName() !== 'mysql') {
                    Schema::table('users', fn (Blueprint $table) => $table->string('email_key', 255)->nullable());

                    return;
                }
                try {
                    DB::statement('ALTER TABLE `users` ADD COLUMN `email_key` VARCHAR(255) NULL, ALGORITHM=INSTANT');
                } catch (QueryException $e) {
                    if (! OnlineSchema::notSupported($e)) {
                        throw $e;
                    }
                    // An older MySQL: the table is rebuilt while it stays in use, or MySQL refuses.
                    DB::statement('ALTER TABLE `users` ADD COLUMN `email_key` VARCHAR(255) NULL, ALGORITHM=INPLACE, LOCK=NONE');
                }
            });
        }

        if (! $this->hasIndex()) {
            OnlineSchema::change(fn () => DB::getDriverName() === 'mysql'
                ? DB::statement('ALTER TABLE `users` ADD INDEX `users_email_key_index` (`email_key`), ALGORITHM=INPLACE, LOCK=NONE')
                : Schema::table('users', fn (Blueprint $table) => $table->index('email_key', 'users_email_key_index')));
        }

        DB::table('users')->whereNull('email_key')->whereNotNull('email')->select(['id', 'email'])
            ->chunkById(1000, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update(['email_key' => MailboxKey::for($user->email)]);
                }
            });
    }

    public function down(): void
    {
        if ($this->hasIndex()) {
            Schema::table('users', fn (Blueprint $table) => $table->dropIndex('users_email_key_index'));
        }
        if (Schema::hasColumn('users', 'email_key')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_key'));
        }
    }

    private function hasIndex(): bool
    {
        return collect(Schema::getIndexes('users'))->contains(fn (array $index): bool => $index['name'] === 'users_email_key_index');
    }
};
