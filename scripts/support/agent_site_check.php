<?php

/*
 * Staging only: every page of real public websites, read by the server itself as an agent's
 * website is read (SiteReader::site, a WordPress site from its content feed; the words in its
 * pictures, AgentSite::withPictures; its PDF files, PictureReader::pdf), then looked up with callers'
 * questions (AgentLookup::searchIn), so it is known what an agent will know: how many pages, how
 * long the read takes, what a page whose words are in a picture gives, and what a look-up finds.
 *
 * No agent, page or call is saved, and no sound is made. The text model reads the pictures (as an
 * uploaded photo is read); what a picture says is kept for a week in the cache, as on a real read.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/agent_site_check.php
 */

use App\Services\Agents\PictureReader;
use App\Services\Agents\SiteReader;
use App\Support\Agents\AgentLookup;
use App\Support\Agents\AgentSite;
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
if (! class_exists(AgentSite::class)) {
    fwrite(STDERR, "This server's code does not read whole websites yet: deploy first.\n");
    exit(1);
}

$reader = app(SiteReader::class);
$pictures = app(PictureReader::class);
$sites = [
    // A medical society: pages built with a page builder (1.5 MB each), its board of directors in one picture.
    ['url' => 'https://sos.org.sa', 'language' => 'ar', 'business' => 'الجمعية السعودية لطب العيون', 'questions' => [
        'رسوم العضوية اشتراك', 'كم رسوم عضوية أخصائي البصريات', 'رئيس مجلس الإدارة', 'أعضاء مجلس الإدارة', 'موعد المؤتمر السنوي',
        'جوائز الجمعية', 'المجموعات التخصصية', 'رؤية الجمعية', 'تاريخ تأسيس الجمعية', 'مواقف السيارات',
    ]],
];

$failed = 0;
foreach ($sites as $site) {
    $start = microtime(true);
    $read = $reader->site($site['url'], $site['language'], microtime(true) + 80);
    $seconds = round(microtime(true) - $start, 1);
    $pages = $read['pages'];
    echo "\n== {$site['url']}: ".count($pages)." pages in {$seconds} s".($read['done'] ? ', all of it' : ', more left for the next read')."\n";
    if ($pages === []) {
        $failed++;

        continue;
    }
    foreach ($pages as $page) {
        printf("   %5d chars  %s%s\n", mb_strlen($page['text']), rawurldecode($page['url']), $page['pictures'] !== [] ? '  ('.count($page['pictures']).' pictures)' : '');
    }

    echo '   PDF files linked: '.count($read['files'] ?? []).($read['files'] ?? [] ? ': '.implode(' ', array_map('rawurldecode', $read['files'])) : '')."\n";
    $start = microtime(true);
    $withWords = AgentSite::withPictures($pages, $pictures, $site['business'], microtime(true) + 110);
    echo '   pictures read in '.round(microtime(true) - $start, 1)." s:\n";
    foreach ($withWords as $i => $page) {
        if ($page['text'] !== $pages[$i]['text']) {
            $words = trim(mb_substr($page['text'], 0, mb_strlen($page['text']) - mb_strlen($pages[$i]['text'])));
            echo '   - '.rawurldecode($page['url']).":\n      ".str_replace("\n", "\n      ", mb_substr($words, 0, 600))."\n";
        }
    }

    $docs = array_map(fn (array $p): array => ['page' => $p['title'], 'text' => $p['text']], $withWords);
    foreach (array_slice($read['files'] ?? [], 0, 2) as $file) {
        $start = microtime(true);
        $title = AgentSite::pdfTitle($file, $read['file_pages'][$file] ?? null);
        $words = $pictures->pdf($file, $title, $site['business'], microtime(true) + 70);
        echo '   - PDF '.rawurldecode($file).' read in '.round(microtime(true) - $start, 1).' s: '.mb_strlen($words)." chars\n      ".str_replace("\n", "\n      ", mb_substr($words, 0, 400))."\n";
        if ($words !== '') {
            $docs[] = ['page' => $title.' (PDF)', 'text' => $words];
        }
    }
    echo "   look-ups:\n";
    foreach ($site['questions'] as $question) {
        $found = AgentLookup::searchIn($docs, $question);
        echo "   ? {$question}\n";
        if ($found === []) {
            echo "      (nothing: the agent says it does not have this)\n";
        }
        foreach ($found as $hit) {
            echo '      ['.$hit['page'].'] '.mb_substr(preg_replace('/\s+/u', ' ', $hit['text']), 0, 170)."\n";
        }
    }
}

echo "\n".($failed === 0 ? 'Every website was read.' : "{$failed} website(s) could not be read.")."\n";
