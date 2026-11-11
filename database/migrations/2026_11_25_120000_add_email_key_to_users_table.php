<?php

use App\Support\MailboxKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The inbox each address reaches (App\Support\MailboxKey), so the free demo
 * counts jane.doe+2@gmail.com and janedoe@gmail.com as one person. Only adds:
 * a nullable column at the end of the table (MySQL adds it without copying
 * the table) and its index (built while the table stays in use), then fills
 * it in for the accounts already there, a thousand at a time. Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'email_key')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('email_key', 255)->nullable();
            });
        }

        if (! $this->hasIndex()) {
            Schema::table('users', function (Blueprint $table): void {
                $table->index('email_key', 'users_email_key_index');
            });
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
