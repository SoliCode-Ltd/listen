<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'free',
                'name' => 'FREE',
                'price_cents' => 0,
                'monthly_minutes' => 20,
                'monthly_chars' => 30000,
                'monthly_requests' => 60,
                'max_chars_per_request' => 1200,
                'allow_overage' => false,
                'overage_price_per_million_cents' => null,
                'commercial_use_allowed' => false,
                'processing_priority' => 'standard',
                'voice_tier' => 'standard',
                'features' => ['No overage', 'No commercial use', 'Standard speed'],
                'is_active' => true,
            ],
            [
                'slug' => 'creator',
                'name' => 'CREATOR',
                'price_cents' => 1200,
                'monthly_minutes' => 240,
                'monthly_chars' => 500000,
                'monthly_requests' => 0,
                'max_chars_per_request' => 50000,
                'allow_overage' => true,
                'overage_price_per_million_cents' => 2000,
                'commercial_use_allowed' => true,
                'processing_priority' => 'standard',
                'voice_tier' => 'hd',
                'features' => ['Unlimited requests', 'Commercial use allowed', 'Standard priority'],
                'is_active' => true,
            ],
            [
                'slug' => 'pro',
                'name' => 'PRO',
                'price_cents' => 3900,
                'monthly_minutes' => 900,
                'monthly_chars' => 2000000,
                'monthly_requests' => 0,
                'max_chars_per_request' => 100000,
                'allow_overage' => true,
                'overage_price_per_million_cents' => 1800,
                'commercial_use_allowed' => true,
                'processing_priority' => 'high',
                'voice_tier' => 'hd_plus',
                'features' => ['Unlimited requests', 'Commercial use allowed', 'High priority processing'],
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }

        $starterId = Plan::query()->where('slug', 'starter')->value('id');
        $growthId = Plan::query()->where('slug', 'growth')->value('id');
        $creatorId = Plan::query()->where('slug', 'creator')->value('id');
        $proId = Plan::query()->where('slug', 'pro')->value('id');

        if ($starterId && $creatorId) {
            Subscription::query()
                ->where('plan_id', $starterId)
                ->update(['plan_id' => $creatorId]);
        }

        if ($growthId && $proId) {
            Subscription::query()
                ->where('plan_id', $growthId)
                ->update(['plan_id' => $proId]);
        }

        Plan::query()
            ->whereNotIn('slug', collect($plans)->pluck('slug')->all())
            ->update(['is_active' => false]);
    }
}
