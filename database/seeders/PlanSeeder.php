<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'free',
                'name' => 'Free',
                'price_cents' => 0,
                'monthly_minutes' => 20,
                'max_chars_per_request' => 1200,
                'features' => ['Arabic + English playback', 'Standard queue', 'Community support'],
                'is_active' => true,
            ],
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'price_cents' => 900,
                'monthly_minutes' => 120,
                'max_chars_per_request' => 2500,
                'features' => ['Priority queue', 'Voice presets', 'Email support'],
                'is_active' => true,
            ],
            [
                'slug' => 'growth',
                'name' => 'Growth',
                'price_cents' => 2900,
                'monthly_minutes' => 500,
                'max_chars_per_request' => 6000,
                'features' => ['Faster processing lane', 'Usage analytics', 'Webhook-ready API'],
                'is_active' => true,
            ],
            [
                'slug' => 'scale',
                'name' => 'Scale',
                'price_cents' => 7900,
                'monthly_minutes' => 1800,
                'max_chars_per_request' => 12000,
                'features' => ['Team seats', 'Advanced controls', 'Priority support'],
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }

        Plan::query()
            ->whereNotIn('slug', collect($plans)->pluck('slug')->all())
            ->update(['is_active' => false]);
    }
}
