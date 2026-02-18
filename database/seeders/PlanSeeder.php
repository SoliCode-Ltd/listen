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
                'monthly_minutes' => 10,
                'max_chars_per_request' => 600,
                'features' => ['Arabic playback', 'English playback', 'Basic queue'],
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'price_cents' => 1200,
                'monthly_minutes' => 250,
                'max_chars_per_request' => 4000,
                'features' => ['Priority queue', 'Translate + read', 'Voice presets'],
            ],
            [
                'slug' => 'business',
                'name' => 'Business',
                'price_cents' => 3900,
                'monthly_minutes' => 1000,
                'max_chars_per_request' => 10000,
                'features' => ['Team seats', 'API access', 'Advanced analytics'],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
