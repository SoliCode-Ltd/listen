<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A plan is either a monthly subscription or a one-time character pack.
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'kind')) {
                $table->string('kind', 20)->default('subscription');
            }
            if (! Schema::hasColumn('plans', 'pack_days')) {
                $table->unsignedSmallInteger('pack_days')->nullable();
            }
        });

        // Each member's pack characters: what they bought, what they used, and when they end.
        if (! Schema::hasTable('char_pack_wallets')) {
            Schema::create('char_pack_wallets', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('chars_total')->default(0);
                $table->unsignedBigInteger('chars_used')->default(0);
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('char_pack_purchases')) {
            Schema::create('char_pack_purchases', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedInteger('chars');
                $table->unsignedSmallInteger('days');
                $table->unsignedBigInteger('amount_minor');
                $table->string('currency', 3);
                $table->string('method', 20);
                $table->string('status', 20)->default('pending');
                $table->string('stripe_payment_intent_id')->nullable()->unique();
                $table->foreignId('manual_payment_request_id')->nullable()->constrained()->nullOnDelete();
                $table->string('failure_message', 500)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
                $table->index(['status', 'paid_at']);
            });
        }

        // Generations paid from pack characters reconcile against the pack, not the month.
        if (! Schema::hasColumn('audio_generations', 'billed_to_pack')) {
            Schema::table('audio_generations', function (Blueprint $table): void {
                $table->boolean('billed_to_pack')->default(false);
            });
        }

        // Members who joined before this change keep the free plan's monthly characters.
        if (! Schema::hasColumn('users', 'free_monthly_chars')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('free_monthly_chars')->default(false);
            });
            DB::table('users')->update(['free_monthly_chars' => true]);
        }

        $this->createPackPlan();
    }

    public function down(): void
    {
        Schema::dropIfExists('char_pack_purchases');
        Schema::dropIfExists('char_pack_wallets');
        DB::table('plans')->where('kind', 'pack')->delete();

        foreach (['plans' => ['kind', 'pack_days'], 'audio_generations' => ['billed_to_pack'], 'users' => ['free_monthly_chars']] as $tableName => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }

    /**
     * The $2 pack, switched off until the owner turns it on. Its features
     * start as a copy of Starter's and can be changed in Admin → Plans.
     */
    private function createPackPlan(): void
    {
        if (DB::table('plans')->where('slug', 'pack')->exists()) {
            return;
        }

        $starter = DB::table('plans')->where('slug', 'starter')->first();
        $copy = fn (string $column, mixed $default): mixed => ($starter && property_exists($starter, $column) && $starter->{$column} !== null)
            ? $starter->{$column}
            : $default;

        $row = [
            'slug' => 'pack',
            'name' => 'Premium Pack',
            'name_en' => 'Premium Pack',
            'name_ar' => 'الباقة المتميزة',
            'description_en' => 'The premium voice in your dashboard and browser extension. One payment, no subscription.',
            'description_ar' => 'الصوت المتميز في لوحة التحكم وإضافة المتصفح. دفعة واحدة بدون اشتراك.',
            'button_text_en' => 'Buy the pack',
            'button_text_ar' => 'اشترِ الباقة',
            'kind' => 'pack',
            'pack_days' => 30,
            'price_cents' => 200,
            'monthly_chars' => 20000,
            'monthly_minutes' => 0,
            'monthly_requests' => 0,
            'max_chars_per_request' => $copy('max_chars_per_request', 5000),
            'voice_tier' => 'hd',
            'processing_priority' => $copy('processing_priority', 'standard'),
            'commercial_use_allowed' => $copy('commercial_use_allowed', false),
            'recent_voices_days' => $copy('recent_voices_days', 30),
            'dialogue_enabled' => $copy('dialogue_enabled', false),
            'custom_prompt_enabled' => false,
            'tts_provider_policy' => $copy('tts_provider_policy', 'default'),
            'quality_tier' => $copy('quality_tier', 'high'),
            'queue_priority_weight' => $copy('queue_priority_weight', 0),
            'download_formats' => $copy('download_formats', null),
            'allow_overage' => false,
            'voice_cloning_enabled' => false,
            'is_active' => false,
            'is_featured' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('plans')->insert(array_filter(
            $row,
            fn (string $column): bool => Schema::hasColumn('plans', $column),
            ARRAY_FILTER_USE_KEY,
        ));
    }
};
