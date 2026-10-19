#!/usr/bin/env bash
#
# For the "Server deploy" workflow's php-limits mode: shows, without changing
# anything, the PHP limits the web server really runs the site with. The
# command line's own limits (php -i) are not the web server's, so it reads
# the PHP-FPM pools and the limits in the pool files this user can read, any
# .user.ini, and what the web server's PHP uses, from a one-off page under a
# random name that is deleted straight after. Also who the web server runs as
# and who can read .env.
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
