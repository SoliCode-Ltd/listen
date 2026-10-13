<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The $2 pack is sold as a monthly plan instead: "Basic", 20,000 premium
     * characters a month. Only the unsold pack created by the previous
     * migration is changed; it stays switched off until the owner turns it on.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'kind') || DB::table('plans')->where('slug', 'basic')->exists()) {
            return;
        }

        $pack = DB::table('plans')->where('slug', 'pack')->where('kind', 'pack')->first();
        if (! $pack) {
            return;
        }
        if (Schema::hasTable('char_pack_purchases') && DB::table('char_pack_purchases')->where('plan_id', $pack->id)->exists()) {
            return; // Already sold as a pack: leave it alone.
        }

        DB::table('plans')->where('id', $pack->id)->update(array_filter([
            'slug' => 'basic',
            'kind' => 'subscription',
            'pack_days' => null,
            'name' => 'Basic',
            'name_en' => 'Basic',
            'name_ar' => 'الأساسية',
            'description_en' => 'The premium voice for light use, in your dashboard and the browser extension.',
            'description_ar' => 'الصوت المتميز للاستخدام الخفيف، في لوحة التحكم وإضافة المتصفح.',
            'button_text_en' => null,
            'button_text_ar' => null,
            'updated_at' => now(),
        ], fn ($value, string $column): bool => Schema::hasColumn('plans', $column), ARRAY_FILTER_USE_BOTH));
    }

    public function down(): void
    {
        $basic = DB::table('plans')->where('slug', 'basic')->where('kind', 'subscription')->first();
        if ($basic && ! DB::table('subscriptions')->where('plan_id', $basic->id)->exists()) {
            DB::table('plans')->where('id', $basic->id)->update([
                'slug' => 'pack', 'kind' => 'pack', 'pack_days' => 30,
                'name' => 'Premium Pack', 'name_en' => 'Premium Pack', 'name_ar' => 'الباقة المتميزة',
            ]);
        }
    }
};
