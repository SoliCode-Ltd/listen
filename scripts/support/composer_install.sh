#!/usr/bin/env bash
#
# For the "Server deploy" workflow's composer mode: installs the packages
# composer.lock lists (without the development tools, unless the server
# already has them), makes the "Sign in with Listen" keys once (passport:keys,
# never replaced; readable by the web server as .env is), then rebuilds the
# caches as deploy does. Anything that fails stops here and fails the run.
#
#   ssh user@host 'cd ~/public_html && bash -s' < scripts/support/composer_install.sh
set -euo pipefail
# One step: its last lines when it works, all of its output and a FAILED line when it does not.
step() {
  local lines="$1" out; shift
  if ! out="$("$@" 2>&1)"; then
    printf '%s\n' "$out"; echo "COMPOSER FAILED at: $*"; exit 1
  fi
  printf '%s\n' "$out" | tail -n "$lines"
}
# Which caches the server uses, noted first: composer's own scripts clear them (filament:upgrade).
c=0; [ -f bootstrap/cache/config.php ] && c=1
r=0; [ -f bootstrap/cache/routes-v7.php ] && r=1
composer=composer; command -v composer >/dev/null 2>&1 || composer="php $HOME/composer.phar"
# The development tools stay only on a server that already has them.
dev=--no-dev; grep -q '"dev": true' vendor/composer/installed.json 2>/dev/null && dev=
echo "== composer install ${dev:-(with the development tools, as before)} =="
step 15 $composer install $dev --no-interaction --prefer-dist --optimize-autoloader
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
echo "COMPOSER DONE"
