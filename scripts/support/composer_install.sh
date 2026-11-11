#!/usr/bin/env bash
#
# For the "Server deploy" workflow's composer mode: installs the packages
# composer.lock lists (without the development tools, unless the server
# already has them), makes the "Sign in with Listen" keys once (passport:keys,
# never replaced; readable by the web server as .env is), then rebuilds the
# caches as deploy does. Anything that fails stops here and fails the run.
#
# Members keep using the site throughout. The new packages are put together
# beside the ones in use (vendor.next, from a copy of vendor/, so only what
# changed is fetched), the app is started on them once without touching the
# site, and only then are they swapped in with two renames: no page ever runs
# on half-installed packages. If the app does not start after the swap, the
# packages before go back at once and the run fails. They stay as
# vendor.previous until the next run.
#
# The web server's PHP keeps compiled copies of the files it runs and looks at
# the disk again only every few seconds (opcache), so for a moment after the
# swap a page could mix files from before and after. Straight after each swap a
# one-off page under a random name (deleted at once, as php-limits does) drops
# those copies, so the next page reads the files that are there.
#
#   ssh user@host 'cd ~/public_html && bash -s' < scripts/support/composer_install.sh
set -euo pipefail
swapped=0
url="$( { grep -E '^APP_URL=' .env || true; } | tail -1 | cut -d= -f2- | tr -d "\"' ")"
flush_page="public/opcache-reset-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n').php"
trap 'rm -rf vendor.next storage/app/listen-deploy/next-cache; rm -f "$flush_page"' EXIT
# The web server's compiled copies of the files, dropped (see above).
flush_web_php() {
  printf '%s\n' '<?php echo function_exists("opcache_reset") && opcache_reset() ? "dropped" : "none kept";' > "$flush_page"
  echo "  The web server's compiled copies: $(curl -s --max-time 10 "${url%/}/${flush_page#public/}" 2>/dev/null || echo 'the page did not answer')"
  rm -f "$flush_page"
}
# The caches made for the packages in use. The routes' own file goes first: while it is
# there the app takes the routes as cached, and /en and /ar pages need their language's file.
drop_caches() {
  rm -f bootstrap/cache/routes-v7.php
  rm -f bootstrap/cache/routes-v7_*.php bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/events.php
}
# The packages before, back in place, with the app's caches made for them.
back() {
  [ "$swapped" = 1 ] && [ -d vendor.previous ] || return 0
  echo "== Putting the packages before back =="
  rm -rf vendor.failed
  php -r 'rename("vendor", "vendor.failed") && rename("vendor.previous", "vendor") || exit(1);' \
    || { echo "COULD NOT PUT THEM BACK: the packages before are in vendor.previous"; exit 1; }
  drop_caches
  flush_web_php
  swapped=0
  php artisan package:discover 2>&1 | tail -n 2 || true
  php artisan optimize:clear --except=cache 2>&1 | tail -n 1 || true
  php artisan route:trans:clear 2>&1 | tail -n 1 || true
  if [ "$c" = 1 ]; then php artisan config:cache 2>&1 | tail -n 1 || true; fi
  if [ "$r" = 1 ]; then php artisan route:trans:cache 2>&1 | tail -n 1 || true; fi
  php artisan view:cache 2>&1 | tail -n 1 || true
  php artisan queue:restart 2>&1 | tail -n 1 || true
  echo "The packages before are back (the new ones are in vendor.failed)."
}
# One step: its last lines when it works, all of its output and a FAILED line when it does not.
step() {
  local lines="$1" out; shift
  if ! out="$("$@" 2>&1)"; then
    printf '%s\n' "$out"; echo "COMPOSER FAILED at: $*"; back; exit 1
  fi
  printf '%s\n' "$out" | tail -n "$lines"
}
# Which caches the server uses, noted first: they are cleared on the way.
c=0; [ -f bootstrap/cache/config.php ] && c=1
r=0; [ -f bootstrap/cache/routes-v7.php ] && r=1
composer=composer; command -v composer >/dev/null 2>&1 || composer="php $HOME/composer.phar"
# The development tools stay only on a server that already has them.
dev=--no-dev; grep -q '"dev": true' vendor/composer/installed.json 2>/dev/null && dev=

# Room for a second copy of the packages (and what composer fetches), before anything starts.
rm -rf vendor.next vendor.previous vendor.failed
need=$(( $(du -sk vendor | cut -f1) * 2 + 200000 ))
free=$(df -Pk . | awk 'NR == 2 {print $4}')
echo "== Disk: $(( free / 1024 )) MB free, $(( need / 1024 )) MB needed beside the packages in use =="
[ "$free" -gt "$need" ] || { echo "COMPOSER FAILED: not enough disk space; nothing was changed"; exit 1; }

echo "== composer install ${dev:-(with the development tools, as before)}, beside the packages in use =="
cp -a vendor vendor.next
# Composer's own scripts run after the swap, on the packages they are for.
step 15 env COMPOSER_VENDOR_DIR=vendor.next $composer install $dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# The app started on the new packages, with caches of its own: the site is not touched.
echo "== The app on the new packages, before the swap =="
nc="$PWD/storage/app/listen-deploy/next-cache"
rm -rf "$nc" && mkdir -p "$nc"
next() {
  env COMPOSER_VENDOR_DIR=vendor.next APP_PACKAGES_CACHE="$nc/packages.php" APP_SERVICES_CACHE="$nc/services.php" \
    APP_CONFIG_CACHE="$nc/config.php" APP_ROUTES_CACHE="$nc/routes-v7.php" APP_EVENTS_CACHE="$nc/events.php" \
    php -r 'require "vendor.next/autoload.php"; $app = require "bootstrap/app.php"; $_SERVER["argv"] = array_merge(["artisan"], array_slice($argv, 1)); exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput($_SERVER["argv"])));' -- "$@"
}
step 2 next package:discover
step 1 next --version
step 1 next route:list --path=pricing

# The swap: two renames, then the caches made for the packages before are dropped
# (each page builds what it needs until they are made again below) and so are the
# web server's compiled copies of the files.
# Both renames in one process, microseconds apart (two commands would be milliseconds apart).
if ! php -r 'rename("vendor", "vendor.previous") && rename("vendor.next", "vendor") || exit(1);'; then
  [ -d vendor ] || mv vendor.previous vendor
  echo "COMPOSER FAILED at the swap: the packages before are in use"; exit 1
fi
swapped=1
drop_caches
flush_web_php
# The queue workers finish the job in hand and start again on the new packages.
php artisan queue:restart 2>&1 | tail -n 1 || true
echo "== Swapped in: $(php artisan --version 2>&1 | tail -n 1) =="
# Composer's scripts (composer.json post-autoload-dump), now on the new packages.
step 2 php artisan package:discover
step 2 php artisan filament:upgrade
# "Sign in with Listen" keys: made once and never replaced (new keys would sign every
# member's connected apps out). The web server reads them as it reads .env.
if php artisan list --raw 2>/dev/null | grep '^passport:keys' >/dev/null; then
  if [ -f storage/oauth-private.key ]; then
    echo "Sign-in keys: already there, kept"
  else
    step 1 php artisan passport:keys
    chgrp --reference=.env storage/oauth-private.key storage/oauth-public.key 2>/dev/null || true
    chmod 640 storage/oauth-private.key storage/oauth-public.key
    echo "Sign-in keys: made"
  fi
  ls -l storage/oauth-private.key storage/oauth-public.key .env | awk '{print "  "$1" "$3":"$4" "$NF}'
fi
# Then the caches, as deploy rebuilds them (never the app cache; routes only with route:trans:cache).
step 6 php artisan optimize:clear --except=cache
step 1 php artisan route:trans:clear
if [ "$c" = 1 ]; then step 1 php artisan config:cache; fi
if [ "$r" = 1 ]; then step 1 php artisan route:trans:cache; fi
step 1 php artisan view:cache
php artisan queue:restart | tail -1 || true
echo "COMPOSER DONE (the packages before are kept as vendor.previous until the next run)"
