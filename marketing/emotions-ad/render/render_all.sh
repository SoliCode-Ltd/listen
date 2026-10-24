#!/bin/bash
# Builds and renders every cut of the emotions ad into marketing/emotions-ad/out,
# committing and pushing each video as soon as it is done. A video already newer
# than the page code is skipped, so the script can be run again after a stop.
#   bash render/render_all.sh [build dir]
set -e
cd "$(dirname "$0")"
B=${1:-/tmp/emotions-ad-build}
for cut in ${CUTS:-ar en}; do for length in full short; do for ratio in 9x16 16x9; do
  out="../out/listen-emotions-$cut-$length-$ratio.mp4"
  if [ -f "$out" ] && [ "$out" -nt ad.js ] && ffprobe -v error "$out" >/dev/null 2>&1; then echo "skip $out"; continue; fi
  rm -rf ../out/.segments-*
  python3 build.py $cut $length "$B" --ratio $ratio > /dev/null
  node capture.cjs "$B/$cut-$length-$ratio" "$out" --workers 3
  git add "$out" && git commit -q -m "Emotions ad: $cut $length cut, $ratio" \
    -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" -m "Claude-Session: https://claude.ai/code/session_01YQf48Cjri8HnhH7JSzPg6d"
  for i in 1 2 3 4; do git push -q origin HEAD && break; sleep $((2**i)); done
done; done; done
echo ALL_DONE
