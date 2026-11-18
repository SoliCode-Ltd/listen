<?php

// Test members for the checks in this folder, on a LOCAL copy of the site only (never a server):
//   php artisan migrate --force && php artisan db:seed --class=PlanSeeder --force
//   php artisan tinker --execute="require 'mobile/checks/seed.php';"
// Members free@, starter@ and max@example.test, password "password-123"; prints the test agent's id.
if (! app()->environment('local')) {
    throw new RuntimeException('Local only.');
}
use App\Models\{Plan, SiteSetting, Subscription, User, Agent};
foreach (['voice_design_audience', 'video_dubbing_audience', 'voice_cloning_audience', 'agents_audience'] as $s) SiteSetting::set($s, 'everyone');
// The yearly offer bar is switched on, as it can be on live: the checks then prove that no page the app shows carries it.
SiteSetting::set('billing_yearly_discount_percentage', '50', 'billing');
SiteSetting::set(\App\Support\YearlyOfferBanner::ENABLED, '1', \App\Support\YearlyOfferBanner::GROUP);
Plan::query()->where('slug', 'studio_max')->firstOrFail()->forceFill(['voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 5, 'video_dubbing_enabled' => true, 'voice_design_enabled' => true, 'voice_design_max_voices' => 10, 'agents_enabled' => true])->save();
foreach (['free' => 'free', 'starter' => 'starter', 'max' => 'studio_max'] as $name => $slug) {
    $u = User::query()->updateOrCreate(['email' => "$name@example.test"], ['name' => $name === 'max' ? 'منى السيد' : ucfirst($name).' Member', 'password' => bcrypt('password-123'), 'email_verified_at' => now()]);
    Subscription::query()->where('user_id', $u->id)->delete();
    Subscription::create(['user_id' => $u->id, 'plan_id' => Plan::where('slug', $slug)->value('id'), 'status' => 'active', 'current_period_start' => now()->subDay(), 'current_period_end' => now()->addMonth()]);
    echo $name, ' ', $u->id, "\n";
}
$max = User::where('email', 'max@example.test')->first();
if (! Agent::where('user_id', $max->id)->exists()) {
    $a = Agent::create(['user_id' => $max->id, 'name' => 'فاطمة', 'business_name' => 'عيادة الرياض', 'business_type' => 'healthcare', 'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-SA', 'live_voice' => 'Achernar', 'first_message' => 'أهلاً', 'script' => ['identity' => 'A calm receptionist.', 'never' => [], 'steps' => ['Ask what they need.'], 'handoff' => ''], 'knowledge' => 'Open 9am to 9pm', 'choices' => []]);
    echo 'agent ', $a->getRouteKey(), "\n";
}
