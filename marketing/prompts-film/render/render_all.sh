#!/bin/bash
# Builds and renders both prompts films into marketing/prompts-film/out,
# committing and pushing each video as soon as it is done. A video already newer
# than the page code is skipped, so the script can be run again after a stop.
#   bash render/render_all.sh [build dir] [ar|en ...]
set -e
cd "$(dirname "$0")"
B=${1:-/tmp/prompts-film-build}
shift || true
films=${*:-ar en}
mkdir -p ../out
for film in $films; do
  out="../out/listen-prompts-$film-9x16.mp4"
  if [ -f "$out" ] && [ "$out" -nt film.js ] && [ "$out" -nt build.py ] && ffprobe -v error "$out" >/dev/null 2>&1; then echo "skip $out"; continue; fi
  rm -rf ../out/.segments-*
  python3 build.py $film "$B" > /dev/null
  node capture.cjs "$B/$film" "$out" --workers 4
  git add "$out" && git commit -q -m "Prompts film: the $film film, 9:16" \
    -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01YQf48Cjri8HnhH7JSzPg6d"
  for i in 1 2 3 4; do git push -q origin HEAD && break; sleep $((2**i)); done
done
echo ALL_DONE
