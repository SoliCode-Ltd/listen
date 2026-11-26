<?php

/*
 * One-off, STAGING ONLY (it refuses anywhere else), run through the "Server deploy"
 * workflow's data-checks mode from this branch only: makes a throwaway member on a
 * paid plan so "Sign in with Listen" can be tried for real in a browser, and prints
 * its one-time password. The cleanup script on this branch removes it again.
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! str_contains((string) config('app.url'), 'cloudwaysapps.com')) {
    echo "Not staging (", config('app.url'), "): nothing done.\n";
    exit(0);
}
$email = 'mcp-connection-check@listen.invalid';
$password = bin2hex(random_bytes(9));
try {
    $user = App\Models\User::query()->where('email', $email)->first() ?? new App\Models\User;
    $user->forceFill([
        'name' => 'MCP connection check',
        'email' => $email,
        'password' => Illuminate\Support\Facades\Hash::make($password),
        'email_verified_at' => now(),
    ])->save();
    $plan = App\Models\Plan::query()->where('slug', 'creator')->firstOrFail();
    app(App\Services\PlanAccessService::class)->assignPlan($user, $plan);
    $access = app(App\Services\PlanAccessService::class)->resolveAccessPlanForUserId((int) $user->id);
    echo 'member ', $user->id, ' ', $email, ' pw ', $password, "\n";
    echo 'plan ', $access?->slug, ', status for AI apps: ', App\Mcp\McpAccess::statusFor((int) $user->id, $access), ', api: ', App\Support\Api\ApiPlanAccess::enabled($access) ? 'yes' : 'no', "\n";
    echo 'keys: ', is_file(storage_path('oauth-private.key')) ? 'present' : 'MISSING', "\n";
} catch (Throwable $e) {
    echo 'FAILED: ', get_class($e), ': ', substr($e->getMessage(), 0, 400), "\n";
}
