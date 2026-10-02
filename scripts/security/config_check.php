<?php

/*
 * Read-only security configuration check.
 *
 * Boots the Laravel app in the current directory and prints JSON describing
 * security-relevant settings. Only flags, modes and counts are printed: never
 * secret values. Nothing is written.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/security/config_check.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$filled = fn ($v) => trim((string) $v) !== '';
$perm = fn (string $path) => file_exists($path) ? substr(sprintf('%o', fileperms($path)), -4) : null;
$stripeMode = function (?string $key): ?string {
    $key = (string) $key;
    if ($key === '') {
        return null;
    }

    return str_contains($key, '_live_') ? 'live' : (str_contains($key, '_test_') ? 'test' : 'unknown');
};

$checks = [
    'app' => [
        'env' => app()->environment(),
        'debug' => (bool) config('app.debug'),
        'url_is_https' => str_starts_with((string) config('app.url'), 'https://'),
        'key_set' => $filled(config('app.key')),
        'php_version' => PHP_VERSION,
        'laravel_version' => app()->version(),
        'display_errors_ini' => ini_get('display_errors'),
        'exec_enabled' => function_exists('exec') && ! in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true),
    ],
    'session' => [
        'driver' => config('session.driver'),
        'lifetime_minutes' => config('session.lifetime'),
        'secure_cookie' => config('session.secure'),
        'http_only' => (bool) config('session.http_only'),
        'same_site' => config('session.same_site'),
        'encrypt' => (bool) config('session.encrypt'),
    ],
    'services' => [
        'stripe_key_mode' => $stripeMode(config('services.stripe.key') ?? config('cashier.key')),
        'stripe_secret_mode' => $stripeMode(config('services.stripe.secret') ?? config('cashier.secret')),
        'stripe_webhook_secret_set' => $filled(config('services.stripe.webhook_secret')),
        'legacy_listen_api_key_set' => $filled(env('LISTEN_API_KEY')),
        'mail_mailer' => config('mail.default'),
        'queue' => config('queue.default'),
        'cache' => config('cache.default'),
        'log_level' => config('logging.channels.'.config('logging.default').'.level'),
    ],
    'files' => [
        'env_permissions' => $perm($root.'/.env'),
        'env_world_readable' => file_exists($root.'/.env') ? (bool) (fileperms($root.'/.env') & 0004) : null,
        'storage_link_exists' => is_link(public_path('storage')) || is_dir(public_path('storage')),
        'public_has_unexpected_php' => array_values(array_filter(
            glob(public_path('*.php')) ?: [],
            fn ($f) => basename($f) !== 'index.php'
        )),
        'public_dotfiles' => array_values(array_map('basename', glob(public_path('.*')) ?: [])),
    ],
    'installed_packages' => (function () use ($root) {
        $installed = json_decode((string) @file_get_contents($root.'/vendor/composer/installed.json'), true);
        $packages = $installed['packages'] ?? $installed ?? [];
        $wanted = ['laravel/framework', 'laravel/cashier', 'stripe/stripe-php', 'filament/filament', 'livewire/livewire',
            'guzzlehttp/guzzle', 'dompdf/dompdf', 'league/commonmark', 'symfony/mime'];

        return collect($packages)->filter(fn ($p) => in_array($p['name'] ?? '', $wanted, true))
            ->mapWithKeys(fn ($p) => [$p['name'] => $p['version'] ?? '?'])->sortKeys()->all();
    })(),
    'stripe_api_version' => class_exists(\Laravel\Cashier\Cashier::class) ? \Laravel\Cashier\Cashier::STRIPE_VERSION : null,
    'composer_lock_matches_installed' => (function () use ($root) {
        $lock = json_decode((string) @file_get_contents($root.'/composer.lock'), true);
        $installed = json_decode((string) @file_get_contents($root.'/vendor/composer/installed.json'), true);
        $locked = collect($lock['packages'] ?? [])->pluck('version', 'name');
        $have = collect($installed['packages'] ?? [])->pluck('version', 'name');

        return $locked->filter(fn ($v, $name) => ($have[$name] ?? null) !== $v)->count() === 0;
    })(),
    'users' => [
        'admins' => Schema::hasColumn('users', 'is_admin') ? DB::table('users')->where('is_admin', true)->count() : null,
    ],
];

echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
