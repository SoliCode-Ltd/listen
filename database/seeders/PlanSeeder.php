<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |----------------------------------------------------------------------
        | Pricing Philosophy
        |----------------------------------------------------------------------
        | Free plan → Standard tier (Google WaveNet), no emotion controls.
        | Paid plans → HD tier (Gemini), full emotion/dialect controls.
        |
        | Top-up margins:
        |   - Character top-ups: ~50-60% margin over Gemini cost
        |   - Premium minute top-ups: priced per minute with healthy margin
        |
        | WaveNet cost: $4/1M (Standard), $16/1M (WaveNet)
        | Gemini cost: approx $10-15/1M chars (flash preview)
        | We charge top-ups at $20-30/1M to maintain margin.
        |----------------------------------------------------------------------
        */

        $plans = [
            // ─── Free ───────────────────────────────────────────────────
            [
                'slug' => 'free',
                'name' => 'Free',
                'price_cents' => 0,
                'monthly_minutes' => 10,
                'monthly_chars' => 10000,
                'premium_voice_minutes' => 0,
                'monthly_requests' => 60,
                'max_chars_per_request' => 1200,
                'allow_overage' => false,
                'overage_price_per_million_cents' => null,
                'topup_char_price_cents' => null,
                'topup_minute_price_cents' => null,
                'commercial_use_allowed' => false,
                'processing_priority' => 'standard',
                'voice_tier' => 'standard',
                'tts_provider_policy' => 'google_cloud',
                'dialogue_enabled' => false,
                'quality_tier' => 'wavenet',
                'queue_priority_weight' => 50,
                'recent_voices_days' => 7,
                'features' => [
                    'Standard voices (WaveNet)',
                    'Standard queue',
                    'Personal use only',
                    'Recent voices: 7 days',
                ],
                'is_active' => true,
                'is_featured' => false,
            ],

            // ─── Starter — $9/mo ────────────────────────────────────────
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'price_cents' => 900,
                'monthly_minutes' => 120,
                'monthly_chars' => 500000,
                'premium_voice_minutes' => 60,
                'monthly_requests' => 0,   // 0 = unlimited
                'max_chars_per_request' => 5000,
                'allow_overage' => false,
                'overage_price_per_million_cents' => 2800,   // $28/1M chars
                'topup_char_price_cents' => 2800,
                'topup_minute_price_cents' => 40,            // $0.40/min
                'commercial_use_allowed' => true,
                'processing_priority' => 'standard_plus',
                'voice_tier' => 'hd',
                'tts_provider_policy' => 'gemini',
                'dialogue_enabled' => false,
                'quality_tier' => 'gemini',
                'queue_priority_weight' => 100,
                'recent_voices_days' => 30,
                'features' => [
                    '500,000 characters/month',
                    '60 premium voice minutes',
                    'Unlimited requests',
                    'HD voices (Gemini model)',
                    'Emotion + dialect controls',
                    'Standard+ queue',
                    'Commercial use',
                    'Recent voices: 30 days',
                ],
                'is_active' => true,
                'is_featured' => false,
            ],

            // ─── Creator (Most Popular) — $22/mo ────────────────────────
            [
                'slug' => 'creator',
                'name' => 'Creator',
                'price_cents' => 2200,
                'monthly_minutes' => 360,
                'monthly_chars' => 1500000,
                'premium_voice_minutes' => 180,
                'monthly_requests' => 0,
                'max_chars_per_request' => 20000,
                'allow_overage' => false,
                'overage_price_per_million_cents' => 2400,   // $24/1M chars
                'topup_char_price_cents' => 2400,
                'topup_minute_price_cents' => 35,            // $0.35/min
                'commercial_use_allowed' => true,
                'processing_priority' => 'high',
                'voice_tier' => 'hd',
                'tts_provider_policy' => 'gemini',
                'dialogue_enabled' => false,
                'quality_tier' => 'gemini',
                'queue_priority_weight' => 200,
                'recent_voices_days' => 90,
                'features' => [
                    '1,500,000 characters/month',
                    '180 premium voice minutes',
                    'Unlimited requests',
                    'HD voices (Gemini model)',
                    'Full emotion + dialect controls',
                    'High priority queue',
                    'Commercial use',
                    'Recent voices: 90 days',
                ],
                'is_active' => true,
                'is_featured' => true,
            ],

            // ─── Pro — $49/mo ────────────────────────────────────────────
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'price_cents' => 4900,
                'monthly_minutes' => 900,
                'monthly_chars' => 4000000,
                'premium_voice_minutes' => 300,
                'monthly_requests' => 0,
                'max_chars_per_request' => 120000,
                'allow_overage' => false,
                'overage_price_per_million_cents' => 2000,   // $20/1M chars
                'topup_char_price_cents' => 2000,
                'topup_minute_price_cents' => 30,            // $0.30/min
                'commercial_use_allowed' => true,
                'processing_priority' => 'priority',
                'voice_tier' => 'hd',
                'tts_provider_policy' => 'gemini',
                'dialogue_enabled' => false,
                'quality_tier' => 'gemini',
                'queue_priority_weight' => 350,
                'recent_voices_days' => 180,
                'features' => [
                    '4,000,000 characters/month',
                    '300 premium voice minutes',
                    'Unlimited requests',
                    'Priority queue',
                    'Commercial use',
                    'Recent voices: 180 days',
                ],
                'is_active' => true,
                'is_featured' => false,
            ],

            // ─── Studio Max — $99/mo ─────────────────────────────────────
            [
                'slug' => 'studio_max',
                'name' => 'Studio Max',
                'price_cents' => 9900,
                'monthly_minutes' => 2400,
                'monthly_chars' => 8000000,
                'premium_voice_minutes' => 800,
                'monthly_requests' => 0,
                'max_chars_per_request' => 250000,
                'allow_overage' => false,
                'overage_price_per_million_cents' => 1600,   // $16/1M chars
                'topup_char_price_cents' => 1600,
                'topup_minute_price_cents' => 25,            // $0.25/min
                'commercial_use_allowed' => true,
                'processing_priority' => 'highest',
                'voice_tier' => 'hd',
                'tts_provider_policy' => 'gemini',
                'dialogue_enabled' => true,
                'quality_tier' => 'gemini',
                'queue_priority_weight' => 500,
                'recent_voices_days' => 0,   // 0 = unlimited
                'features' => [
                    '8,000,000 characters/month',
                    '800 premium voice minutes',
                    'Unlimited requests',
                    'Highest queue priority',
                    'Commercial use',
                    'Dialogue mode (multi-speaker)',
                    'Recent voices: Unlimited',
                ],
                'is_active' => true,
                'is_featured' => false,
            ],
        ];

        $activeSlugs = collect($plans)->pluck('slug')->all();

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }

        // Migrate users on old plans to nearest equivalent
        $oldToNew = [
            'growth' => 'creator',
        ];

        foreach ($oldToNew as $old => $new) {
            $oldId = Plan::query()->where('slug', $old)->value('id');
            $newId = Plan::query()->where('slug', $new)->value('id');
            if ($oldId && $newId) {
                Subscription::query()
                    ->where('plan_id', $oldId)
                    ->update(['plan_id' => $newId]);
            }
        }

        // Deactivate any plans not in the current set
        Plan::query()
            ->whereNotIn('slug', $activeSlugs)
            ->update(['is_active' => false]);
    }
}
