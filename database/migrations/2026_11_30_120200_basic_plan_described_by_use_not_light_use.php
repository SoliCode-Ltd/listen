<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Basic plan said "The premium voice for light use". It is the first plan a search
 * engine or an AI assistant reads on the pricing page, and assistants repeated "for light
 * use" as what the whole studio is for. The phrase becomes "Premium voices for personal
 * projects" (Arabic likewise); the rest of the description, as saved, stays.
 */
return new class extends Migration
{
    public const PHRASES = [
        'description_en' => ['The premium voice for light use', 'Premium voices for personal projects'],
        'description_ar' => ['الصوت المتميز للاستخدام الخفيف', 'أصوات متميزة لمشاريعك الشخصية'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }
        foreach (DB::table('plans')->get() as $plan) {
            $changes = [];
            foreach (self::PHRASES as $column => [$old, $new]) {
                $value = (string) ($plan->{$column} ?? '');
                if (Schema::hasColumn('plans', $column) && str_contains($value, $old)) {
                    $changes[$column] = str_replace($old, $new, $value);
                }
            }
            if ($changes !== []) {
                DB::table('plans')->where('id', $plan->id)->update($changes + ['updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // The words stay: the team may have edited them since.
    }
};
