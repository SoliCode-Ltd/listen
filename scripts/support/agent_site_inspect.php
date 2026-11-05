<?php

/*
 * Read-only: why does an agent's website not read? For every agent whose website is
 * AGENT_SITE (sos.org.sa by default): its pages and when they were read, the flags a reading
 * leaves (reading, failed, the follow-up run), the queue's waiting and failed website jobs, the
 * app log's errors about agents today, the web server's own record of the owner's "Read the
 * website again" and "Find them on my website" requests, and the reader itself, timed, on this
 * server. Prints ids, counts and statuses only: no caller's words, no visitor's address. On
 * MySQL the session is READ ONLY before any query; nothing is written.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/agent_site_inspect.php
 */

use App\Models\Agent;
use App\Models\AgentSource;
use App\Services\Agents\SiteReader;
use App\Support\Agents\AgentSite;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
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

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$site = getenv('AGENT_SITE') ?: 'sos.org.sa';
$line = static function (string $label, $value): void {
    echo '  '.str_pad($label, 44).(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value).PHP_EOL;
};
// A visitor's address never leaves the server.
$clean = static fn (string $s): string => (string) preg_replace(['/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '/\b(?=[0-9a-f:]*(?:[a-f]|::))(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}\b/i', '/agt_[a-z0-9]{24}/'], ['[ip]', '[ip]', 'agt_…'], $s);

echo "== Server\n";
$line('now', now()->toIso8601String());
$line('cache store', (string) config('cache.default'));
$line('queue connection', (string) config('queue.default'));
$line('commit files: AgentSite has pdfTitle()', method_exists(AgentSite::class, 'pdfTitle'));

echo "\n== Agents whose website is {$site}\n";
$agents = Agent::withTrashed()->where('website_url', 'like', '%'.$site.'%')->orderBy('id')->get();
if ($agents->isEmpty()) {
    echo "  none\n";
}
foreach ($agents as $agent) {
    $pages = $agent->sources()->where('kind', AgentSource::PAGE);
    echo "\n  agent #{$agent->id} ({$agent->status}".($agent->trashed() ? ', deleted' : '').'), owner #'.$agent->user_id.', website '.$agent->website_url."\n";
    $line('pages kept', (clone $pages)->count().' (of them PDF: '.(clone $pages)->where('title', 'like', '%(PDF)')->count().')');
    $line('pages last read', (string) ((clone $pages)->max('read_at') ?? 'never'));
    $line('website source (setup read)', (string) ($agent->sources()->where('kind', AgentSource::WEBSITE)->value('status') ?? 'none'));
    foreach (['reading', 'failed', 'extras'] as $flag) {
        $line("cache agent-site-{$flag}", Cache::has('agent-site-'.$flag.':'.$agent->id) ? 'SET' : '-');
    }
    foreach (['', ':extras'] as $part) {
        $key = 'laravel_unique_job:'.\App\Jobs\ReadAgentWebsite::class.':'.$agent->id.$part;
        $line('unique-job lock'.($part ?: ' (pages)'), Cache::has($key) ? 'HELD' : '-');
    }
    $line('due for a reading', AgentSite::isDue($agent));
    $state = AgentSite::state($agent);
    $line('Knowledge card says', $state['state'].': '.$state['text']);
    $links = (array) $agent->setting('links', []);
    $line('links kept', count($links).', found at '.(string) $agent->setting('links_found_at', '-'));
}

echo "\n== Queue: website jobs\n";
if (Schema::hasTable('jobs')) {
    $jobs = DB::table('jobs')->where('payload', 'like', '%ReadAgentWebsite%')->orderBy('id')->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at', 'created_at']);
    $line('waiting or running', $jobs->count());
    foreach ($jobs->take(10) as $j) {
        echo '    job #'.$j->id.' queue '.$j->queue.', attempts '.$j->attempts.', created '.date('c', (int) $j->created_at).($j->reserved_at ? ', running since '.date('c', (int) $j->reserved_at) : '')."\n";
    }
    $line('all jobs waiting', DB::table('jobs')->count().' (oldest '.(($o = DB::table('jobs')->min('created_at')) ? date('c', (int) $o) : '-').')');
}
if (Schema::hasTable('failed_jobs')) {
    $failed = DB::table('failed_jobs')->where('payload', 'like', '%ReadAgentWebsite%')->where('failed_at', '>=', now()->subDays(2))->orderByDesc('id')->limit(5)->get(['id', 'failed_at', 'exception']);
    $line('failed in two days', $failed->count());
    foreach ($failed as $f) {
        echo '    failed #'.$f->id.' at '.$f->failed_at.': '.$clean(mb_substr(strtok((string) $f->exception, "\n"), 0, 300))."\n";
    }
}

echo "\n== App log: errors about agents today\n";
$logs = glob($root.'/storage/logs/*.log') ?: [];
usort($logs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
$today = now()->format('Y-m-d');
$found = 0;
foreach (array_slice($logs, 0, 3) as $log) {
    $fh = @fopen($log, 'r');
    if (! $fh) {
        continue;
    }
    fseek($fh, max(0, filesize($log) - 6_000_000));
    while (($row = fgets($fh)) !== false) {
        if (! str_starts_with($row, '['.$today) || ! preg_match('/\.(ERROR|CRITICAL|ALERT|EMERGENCY|WARNING)/', $row)) {
            continue;
        }
        if (! preg_match('/Agent|SiteReader|PictureReader|ReadAgentWebsite|AgentSite|AgentLink|links|wp-json|Maximum execution|memory/i', $row)) {
            continue;
        }
        $found++;
        if ($found <= 25) {
            echo '  '.$clean(mb_substr(trim($row), 0, 360))."\n";
        }
    }
    fclose($fh);
}
$line('matching lines', $found);

echo "\n== Web server: the owner's website requests today\n";
$dirs = array_filter([dirname($root).'/logs', $root.'/../logs', getenv('HOME').'/logs']);
$seen = 0;
$byFrom = $methods = [];
$shown = [];
foreach (array_unique(array_map('realpath', array_filter($dirs, 'is_dir'))) as $dir) {
    foreach (glob($dir.'/*access*.log') ?: [] as $log) {
        $tail = (string) shell_exec('tail -n 40000 '.escapeshellarg($log).' 2>/dev/null');
        foreach (explode("\n", $tail) as $row) {
            // The owner's agent page and its requests: when, what, the answer, and from which address
            // (a page opened on www. sends its requests to the main address, another site to the browser).
            if (! preg_match('#\[(\d\d/\w{3}/\d{4}:\d\d:\d\d:\d\d)[^\]]*\]\s+"(\w+) ([^" ]*/agents/agt_[a-z0-9]{24}(?:/[a-z-]+)?)[^"]*"\s+(\d{3})(?:\s+\S+\s+"([^"]*)")?#', $row, $m)) {
                continue;
            }
            $seen++;
            $from = (string) (parse_url($m[5] ?? '', PHP_URL_HOST) ?: '-');
            $byFrom[$from] = ($byFrom[$from] ?? 0) + 1;
            $methods[$m[2]] = ($methods[$m[2]] ?? 0) + 1;
            if ($seen <= 40 || ! in_array($m[2], ['GET'], true)) {
                $shown[] = '  '.str_pad(basename($log, '.log'), 22).' '.$m[1].' '.str_pad($m[2], 7).' '.$clean($m[3]).' -> '.$m[4].'  from '.$from;
            }
        }
    }
}
foreach (array_slice($shown, -60) as $row) {
    echo mb_substr($row, 0, 200)."\n";
}
$line('requests seen', $seen);
$line('by method', json_encode($methods));
$line('by the page they came from', json_encode($byFrom));
echo "\n== Web server log files\n";
foreach (array_unique(array_map('realpath', array_filter($dirs, 'is_dir'))) as $dir) {
    foreach (glob($dir.'/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) > time() - 86400) {
            echo '  '.str_pad(basename($f), 70).' '.number_format((int) filesize($f))." bytes\n";
        }
    }
    // Requests the front server answered itself or the browser gave up on (499), or that failed (5xx), today.
    foreach (glob($dir.'/*access*.log') ?: [] as $log) {
        $odd = (string) shell_exec('tail -n 60000 '.escapeshellarg($log).' 2>/dev/null | grep -E "/agents/agt_[a-z0-9]{24}" | grep -E "\" (499|5[0-9][0-9]) " | tail -n 15');
        foreach (array_filter(explode("\n", $odd)) as $row) {
            echo '  odd '.basename($log).': '.$clean(mb_substr(trim($row), 0, 220))."\n";
        }
    }
}
echo "\n== The site's addresses\n";
$line('APP_URL', (string) config('app.url'));
$line('session cookie domain', (string) (config('session.domain') ?: '(this address only)'));
$www = 0;
foreach (array_unique(array_map('realpath', array_filter($dirs, 'is_dir'))) as $dir) {
    foreach (glob($dir.'/*access*.log') ?: [] as $log) {
        $www += (int) trim((string) shell_exec('tail -n 40000 '.escapeshellarg($log).' 2>/dev/null | grep -c "https://www\\." || true'));
    }
}
$line('recent requests from a www. page', $www);

echo "\n== The reader on this server, timed\n";
$reader = app(SiteReader::class);
$start = microtime(true);
try {
    $read = $reader->read('https://'.$site);
    $line('read() (Find them on my website)', ($read['ok'] ? 'ok' : 'NOT ok').', '.count($read['pages'] ?? []).' pages, '.count($read['links'] ?? []).' links in '.round(microtime(true) - $start, 1).' s');
} catch (\Throwable $e) {
    $line('read() threw', get_class($e).': '.$clean(mb_substr($e->getMessage(), 0, 240)).' after '.round(microtime(true) - $start, 1).' s');
}
$line('PHP max_execution_time (web)', ini_get('max_execution_time').' s here; the web server sets its own');
