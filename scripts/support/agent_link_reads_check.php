<?php

/*
 * Staging only, and read-only: the newest posts the server itself reads from real public
 * accounts and pages (LinkReader, as an agent's links are read every few hours), so it is known
 * what an agent will know from them: how many posts, how new, and how long each read takes.
 *
 * Nothing is saved, no call is made and no sound is made: only public pages are read.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/agent_link_reads_check.php
 */

use App\Services\Agents\LinkReader;
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
if (! class_exists(LinkReader::class)) {
    fwrite(STDERR, "This server's code does not read links yet: deploy first.\n");
    exit(1);
}

$reader = app(LinkReader::class);
$links = [
    ['network' => 'x', 'url' => 'https://x.com/SaudiMOH'],
    ['network' => 'x', 'url' => 'https://x.com/KSU_News'],
    ['network' => 'telegram', 'url' => 'https://t.me/durov'],
    ['network' => 'youtube', 'url' => 'https://www.youtube.com/@SaudiMOH'],
    // A website's news feed, found from its page.
    ['network' => 'page', 'url' => 'https://wordpress.org/news/'],
    // A page without a feed: its words.
    ['network' => 'page', 'url' => 'https://www.moh.gov.sa/en/Pages/Default.aspx'],
    // Never asked: it shows nothing without signing in.
    ['network' => 'instagram', 'url' => 'https://www.instagram.com/saudimoh'],
];

$failed = 0;
foreach ($links as $link) {
    $way = LinkReader::way($link);
    $start = microtime(true);
    $read = $reader->read($link);
    $seconds = round(microtime(true) - $start, 1);
    echo "\n== {$link['url']} ({$way}, {$seconds} s)\n";
    if (! $read['ok']) {
        echo "   not read: {$read['error']}\n";
        if ($way !== LinkReader::CLOSED) {
            $failed++;
        }

        continue;
    }
    echo '   kind: '.$read['kind'].', title: '.$read['title']."\n";
    if ($read['kind'] === 'posts') {
        echo '   posts: '.count($read['items']).($read['items'] !== [] ? ', newest '.substr($read['items'][0]['at'], 0, 10).', oldest '.substr(end($read['items'])['at'], 0, 10) : '')
            .', characters: '.array_sum(array_map(fn ($i) => mb_strlen($i['text']), $read['items']))."\n";
        foreach (array_slice($read['items'], 0, 3) as $item) {
            echo '   - '.substr($item['at'], 0, 10).($item['about'] ? ' (around)' : '').': '.mb_substr($item['text'], 0, 110)."\n";
        }
        $links = array_filter($read['items'], fn ($i) => preg_match('#https?://|t\.co/#', $i['text']));
        echo '   posts with a link left in them: '.count($links)."\n";
    } else {
        echo '   characters: '.mb_strlen($read['text']).': '.mb_substr(preg_replace('/\s+/u', ' ', $read['text']), 0, 160)."\n";
    }
}

echo "\n".($failed === 0 ? 'Every readable link was read.' : "{$failed} readable link(s) could not be read.")."\n";

// A live agent's link page on this server, to look at its call card (the same as the website button's).
$page = \App\Models\AgentWidget::query()->where('link_enabled', true)
    ->whereHas('agent', fn ($q) => $q->where('status', \App\Models\Agent::LIVE))->orderBy('id')->value('slug');
echo 'A link page to look at: '.($page ? url('/talk/'.$page) : 'none')."\n";
