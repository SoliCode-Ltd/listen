#!/bin/bash
# Runs the built app in iPhone simulators and keeps a picture of what it shows:
# the smallest and the largest current iPhone, in Arabic and English, light and dark.
#   simulator-shots.sh <path to App.app> <folder for the pictures>
# It opens the app's first page only (the sign-in page of the site the app was built for);
# it signs in to nothing, makes no voice and pays for nothing.
set -euo pipefail

APP="$1"
OUT="$2"
BUNDLE_ID="studio.listenai.app"
mkdir -p "$OUT"

# The smallest and the largest iPhone this machine has, and an iPad (reviewers often use one;
# an iPhone-only app runs there in iPhone size). Each is taken from the newest system installed.
udid_of() { xcrun simctl list devices available | grep -F " $1 (" | tail -1 | grep -oE '[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}' | head -1 || true; }
NAMES=()
UDIDS=()
SMALL=""
for name in "iPhone 17e" "iPhone 16e"; do
  if [ -z "$SMALL" ] && [ -n "$(udid_of "$name")" ]; then SMALL="$name"; fi
done
for name in "$SMALL" "iPhone 17 Pro Max" "iPad (A16)"; do
  [ -n "$name" ] || continue
  udid="$(udid_of "$name")"
  if [ -n "$udid" ]; then NAMES+=("$name"); UDIDS+=("$udid"); fi
done
if [ "${#UDIDS[@]}" -eq 0 ]; then
  echo "::error::No iPhone simulator was found on this machine."
  xcrun simctl list devices available
  exit 1
fi

# What the app wrote about its pages since the last launch (debug builds only: ShellViewController.note).
app_log() { cat "$(xcrun simctl get_app_container "$1" "$BUNDLE_ID" data 2>/dev/null)/Documents/listen-debug.log" 2>/dev/null || true; }

FAILED=0
for index in "${!UDIDS[@]}"; do
  device="${UDIDS[$index]}"
  name="${NAMES[$index]}"
  slug="$(echo "$name" | tr ' ()' '---' | tr -s '-' | sed 's/-$//')"
  echo "== $name ($device)"
  xcrun simctl boot "$device" 2>/dev/null || true
  xcrun simctl bootstatus "$device" -b >/dev/null
  for look in light dark; do
    xcrun simctl ui "$device" appearance "$look"
    # A fresh copy of the app for each look, so the page starts in the phone's own light or dark
    # (the site remembers a member's choice of theme).
    xcrun simctl uninstall "$device" "$BUNDLE_ID" 2>/dev/null || true
    xcrun simctl install "$device" "$APP"
    for lang in ar en; do
      locale="en_US"; [ "$lang" = "ar" ] && locale="ar_EG"
      xcrun simctl terminate "$device" "$BUNDLE_ID" 2>/dev/null || true
      rm -f "$(xcrun simctl get_app_container "$device" "$BUNDLE_ID" data 2>/dev/null)/Documents/listen-debug.log" 2>/dev/null || true
      xcrun simctl launch "$device" "$BUNDLE_ID" -AppleLanguages "($lang)" -AppleLocale "$locale" >/dev/null
      # The opening logo first.
      sleep 1
      xcrun simctl io "$device" screenshot "$OUT/$slug-$lang-$look-1-opening.png" >/dev/null 2>&1 || true
      # Then the page, as soon as the app says it has come (at most a minute).
      shown=""
      for _ in $(seq 1 60); do
        if app_log "$device" | grep -q "^[0-9.]* finished "; then shown="yes"; break; fi
        sleep 1
      done
      sleep 2
      xcrun simctl io "$device" screenshot "$OUT/$slug-$lang-$look-2-first-page.png" >/dev/null
      echo "-- $name, $lang, $look: ${shown:-THE PAGE DID NOT COME}"
      app_log "$device" | sed 's/^/     /'
      if [ -z "$shown" ]; then
        FAILED=1
        echo "::error::$name ($lang, $look): the first page did not come within a minute."
        xcrun simctl spawn "$device" log show --last 90s --style compact --predicate 'process == "App"' 2>/dev/null | tail -60 || true
      fi
    done
  done
  # The app must still be running: a crash at start-up fails the build.
  running="$(xcrun simctl spawn "$device" launchctl list || true)"
  if ! echo "$running" | grep -F "$BUNDLE_ID" >/dev/null; then
    echo "::error::The app is not running on $name after start-up."
    FAILED=1
  fi
  xcrun simctl shutdown "$device" || true
done
ls -la "$OUT"
exit "$FAILED"
