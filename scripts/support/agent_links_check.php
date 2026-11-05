<?php

/*
 * Staging only, and read-only: the business's own social media links read from real websites by
 * the server itself (SiteReader and AgentLinks, as a new agent and "Find them on my website" use
 * them), and an agent that has links getting the show_links tool and the rules that go with it.
 *
 * Nothing is saved, no call is made and no sound is made: only the websites' public pages are read.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/agent_links_check.php
 */

use App\Models\Agent;
use App\Services\Agents\LiveSession;
use App\Services\Agents\SiteReader;
use App\Support\Agents\AgentInstructions;
use App\Support\Agents\AgentLinks;
use Illuminate\Contracts\Console\Kernel;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Staging only: live runs real customers.
if (filter_var(config('listen.live_actions'), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "This check runs on staging only.\n");
    exit(1);
}
if (! class_exists(AgentLinks::class)) {
    fwrite(STDERR, "This server's code does not read social media links yet: deploy first.\n");
    exit(1);
}

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (! $ok) {
        $failures++;
    }
    echo '  '.($ok ? 'PASS ' : 'FAIL ').$what.PHP_EOL;
};

$reader = app(SiteReader::class);

// Public websites whose footers link their accounts: each must give at least this many.
$sites = [
    ['https://www.moh.gov.sa/', 4],
    ['https://www.ksu.edu.sa/ar', 4],
];
foreach ($sites as [$url, $least]) {
    echo PHP_EOL."== {$url}".PHP_EOL;
    $started = microtime(true);
    $read = $reader->read($url);
    $links = $read['links'] ?? [];
    foreach ($links as $link) {
        echo '  '.str_pad($link['network'], 10).AgentLinks::label($link['url']).PHP_EOL;
    }
    echo sprintf('  %d page(s) read in %.1f s', count($read['pages'] ?? []), microtime(true) - $started).PHP_EOL;
    $check(count($links) >= $least, sprintf('at least %d accounts found (%d)', $least, count($links)));
    $check(count(array_unique(array_column($links, 'network'))) === count($links), 'one account on each network');
    $check(array_filter($links, fn (array $l): bool => AgentLinks::clean($l['url']) === null) === [], 'each one an account page, written one way');
}

// The agents of a medical society, if this server has them (a copy of live): what their website gives.
$society = Agent::query()->where('business_name', 'like', '%طب العيون%')->whereNotNull('website_url')->get(['id', 'business_name', 'website_url']);
foreach ($society as $agent) {
    echo PHP_EOL.'== agent '.$agent->id.' ('.$agent->business_name.')'.PHP_EOL;
    $read = $reader->read((string) $agent->website_url);
    echo '  website read: '.($read['ok'] ? 'yes' : 'no ('.$read['error'].')').PHP_EOL;
    foreach ($read['links'] ?? [] as $link) {
        echo '  '.str_pad($link['network'], 10).AgentLinks::label($link['url']).PHP_EOL;
    }
    echo '  accounts found: '.count($read['links'] ?? []).PHP_EOL;
}

// An agent with links (never saved): the tool, its networks and the rules it is given.
echo PHP_EOL.'== an agent with links'.PHP_EOL;
$agent = new Agent(['name' => 'Check', 'business_name' => 'Check Clinic', 'language' => 'ar', 'dialect' => 'ar-SA', 'website_url' => 'https://www.moh.gov.sa/']);
$agent->settings = ['links' => [['network' => 'x', 'url' => 'https://x.com/saudimoh'], ['network' => 'instagram', 'url' => 'https://www.instagram.com/saudimoh'], ['network' => 'website', 'url' => 'https://www.moh.gov.sa']]];
$tool = collect(LiveSession::tools($agent))->firstWhere('name', 'show_links');
$check($tool !== null && ($tool['parameters']['properties']['networks']['items']['enum'] ?? []) === ['x', 'instagram', 'website'], 'the call offers show_links with its three networks');
$text = AgentInstructions::build($agent);
$check(str_contains($text, '# Links you can write in the chat') && str_contains($text, '- Instagram: @saudimoh'), 'the instructions list the links');
$check(str_contains($text, 'Never read a link or a web address aloud'), 'the agent never reads a link aloud');
$plain = new Agent(['name' => 'Check', 'business_name' => 'Check Clinic', 'language' => 'ar', 'dialect' => 'ar-SA']);
$check(collect(LiveSession::tools($plain))->firstWhere('name', 'show_links') === null, 'an agent without links has no such tool');

echo PHP_EOL.'== Summary'.PHP_EOL;
echo '  '.($failures === 0 ? 'Every check passed.' : $failures.' check(s) failed.').PHP_EOL;
exit($failures === 0 ? 0 : 1);
