#!/usr/bin/env bash
#
# For the "Server deploy" workflow's php-limits mode: shows, without changing
# the site, the PHP limits the web server really runs the site with. The
# command line's own limits (php -i) are not the web server's, so it reads
# the PHP-FPM pools and the limits in the pool files this user can read, any
# .user.ini, and what the web server's PHP uses, from a one-off page under a
# random name that is deleted straight after. Also who the web server runs as
# and who can read .env, and how long work after the reply may run: each run
# reads the last run's test of that and starts a new one (a page that answers
# at once, then only waits, for up to 15 minutes).
#
#   ssh user@host 'cd ~/public_html && bash -s' < scripts/support/php_limits.sh
set -uo pipefail
echo "== PHP-FPM processes (how many, as which user) =="
ps -eo user:24,args 2>/dev/null | grep -E 'php-fpm: (master|pool)' | grep -v grep | sed -E 's/ +/ /g' | sort | uniq -c || echo "  none visible from here"
echo "== Limits set in the PHP-FPM pool files this user can read =="
found=0
for f in /etc/php/*/fpm/pool.d/*.conf /etc/php-fpm.d/*.conf; do
  [ -r "$f" ] || continue
  found=1; echo "  $f"
  grep -E '^[[:space:]]*(user|group|request_terminate_timeout|pm(\.[a-z_]+)?|php_(admin_)?(value|flag)\[(memory_limit|max_execution_time|max_input_time|max_input_vars|upload_max_filesize|post_max_size|max_file_uploads|opcache\.[a-z_]+)\])[[:space:]]*=' "$f" | sed 's/^[[:space:]]*/    /' || true
done
[ "$found" = 1 ] || echo "  none readable"
echo "== Cron lines that start Laravel (scheduled jobs and queue workers) =="
crontab -l 2>/dev/null | grep -E 'artisan' | sed 's/^/  /' || echo "  none in this user's crontab"
echo "== .user.ini files in the app =="
for f in .user.ini public/.user.ini; do [ -f "$f" ] && { echo "  $f:"; sed 's/^/    /' "$f"; }; done; true
echo "== What the web server's PHP uses (a one-off page under a random name, deleted straight after) =="
name="php-limits-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n').php"
trap 'rm -f "public/$name"' EXIT
cat > "public/$name" <<'PHP'
<?php
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo 'PHP ', PHP_VERSION, ' (', PHP_SAPI, '), document root ends in /', basename((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), "\n";
foreach (['memory_limit', 'max_execution_time', 'max_input_time', 'upload_max_filesize', 'post_max_size', 'max_file_uploads', 'max_input_vars', 'default_socket_timeout', 'user_ini.filename', 'user_ini.cache_ttl', 'opcache.enable', 'opcache.memory_consumption', 'opcache.max_accelerated_files', 'opcache.validate_timestamps', 'opcache.revalidate_freq', 'realpath_cache_size'] as $k) {
    $v = ini_get($k);
    echo str_pad($k, 32), $v === false ? '(not set)' : ($v === '' ? '(empty)' : $v), "\n";
}
// Whether the app can give itself more while it runs (a voice made after the reply does).
$memory = ini_get('memory_limit');
echo str_pad('memory raised while running', 32), @ini_set('memory_limit', '1024M') === false ? 'NOT allowed (fixed by the host)' : 'allowed', "\n";
@ini_set('memory_limit', (string) $memory);
echo str_pad('time raised while running', 32), function_exists('set_time_limit') && @set_time_limit(1800) ? 'allowed' : 'NOT allowed', "\n";
$off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
foreach (['set_time_limit', 'ignore_user_abort', 'fastcgi_finish_request', 'proc_open'] as $f) {
    echo str_pad($f.'()', 32), function_exists($f) && ! in_array($f, $off, true) ? 'available' : 'NOT available', "\n";
}
$who = function (int $id, bool $group): string {
    $entry = function_exists('posix_getpwuid') ? ($group ? posix_getgrgid($id) : posix_getpwuid($id)) : false;

    return is_array($entry) ? (string) $entry['name'] : (string) $id;
};
if (function_exists('posix_geteuid')) {
    echo str_pad('runs as user:group', 32), $who(posix_geteuid(), false), ':', $who(posix_getegid(), true), "\n";
}
$env = dirname(__DIR__).'/.env';
if (is_file($env)) {
    echo str_pad('.env', 32), substr(sprintf('%o', fileperms($env)), -4), ' owned by ', $who((int) fileowner($env), false), ':', $who((int) filegroup($env), true), "\n";
}
PHP
url="$(grep -E '^APP_URL=' .env | tail -1 | cut -d= -f2- | tr -d "\"' ")"
curl -s --max-time 30 -w '\n(HTTP %{http_code})\n' "${url%/}/$name" | sed 's/^/  /' || echo "  could not open it"
rm -f "public/$name"
[ ! -e "public/$name" ] && echo "  (the page is deleted)"

# A long text's voice is made after the reply, in the web server's PHP: the host
# may stop such work after some minutes whatever PHP's own limits say. A page
# that answers at once and then only waits (no work, no speech) for up to 15
# minutes, noting the time every 10 seconds, shows how long it may run; the
# next php-limits run reads the note.
echo "== How long work after the reply may run =="
note="storage/logs/after-reply-probe.json"
start_new=1
if [ -f "$note" ]; then
  php -r '$n = json_decode((string) file_get_contents($argv[1]), true) ?: []; $s = (int) ($n["started"] ?? 0); $l = (int) ($n["last"] ?? 0);
    echo "  the test started ", gmdate("Y-m-d H:i:s", $s), " UTC ", ! empty($n["done"]) ? "ran its full ".($l - $s)." s: nothing stopped it" : (time() - $l > 30 ? "was stopped after about ".($l - $s)." s" : "is still running (".($l - $s)." s so far): read it again later"), "\n";' "$note"
  # A finished or stopped test makes way for a new one (which writes over the note); a running one is left alone.
  if [ -n "$(php -r '$n = json_decode((string) file_get_contents($argv[1]), true) ?: []; echo (! empty($n["done"]) || time() - (int) ($n["last"] ?? 0) > 30) ? "x" : "";' "$note")" ]; then
    rm -f "$note" 2>/dev/null || true
  else
    start_new=0
  fi
else
  echo "  no earlier test to read"
fi
if [ "$start_new" = 1 ]; then
  wait_name="after-reply-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n').php"
  trap 'rm -f "public/$name" "public/$wait_name"' EXIT
  cat > "public/$wait_name" <<'PHP'
<?php
ignore_user_abort(true);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$note = dirname(__DIR__).'/storage/logs/after-reply-probe.json';
$start = time();
$write = function (bool $done) use ($note, $start): void {
    @file_put_contents($note, json_encode(['started' => $start, 'last' => time(), 'done' => $done]));
    @chmod($note, 0664);
};
$write(false);
echo is_file($note) ? "started\n" : "could not write the note\n";
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
while (time() - $start < 900) {
    sleep(10);
    $write(false);
}
$write(true);
PHP
  curl -s --max-time 30 "${url%/}/$wait_name" | sed 's/^/  /' || echo "  could not open it"
  rm -f "public/$wait_name"
  echo "  a new test has started (its page is already deleted): run php-limits again in 16 minutes or more to read it"
fi
