#!/bin/bash
# Builds and renders every cut of the emotions ad into marketing/emotions-ad/out.
#   bash render/render_all.sh [build dir]
set -e
cd "$(dirname "$0")"
B=${1:-/tmp/emotions-ad-build}
for cut in ar en; do for length in full short; do for ratio in 9x16 16x9; do
  python3 build.py $cut $length "$B" --ratio $ratio > /dev/null
  node capture.cjs "$B/$cut-$length-$ratio" "../out/listen-emotions-$cut-$length-$ratio.mp4" --workers 3
done; done; done
