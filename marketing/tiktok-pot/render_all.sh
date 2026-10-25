#!/bin/bash
# Render the three ads: sound effects from the picture's hits, the mix, the video, the final file.
# Run from marketing/tiktok-pot, after `uv run build_vo.py <film>` and the music (beats.py --synth).
# MOTION_REEL_SKILL: the motion-reel skill's folder (render.mjs, sfx.mjs, mix.mjs).
set -e
SK=${MOTION_REEL_SKILL:?set MOTION_REEL_SKILL to the motion-reel skill folder}
for f in eg gulf eg-short; do
  echo "== $f $(date +%T)"
  node $SK/scripts/render.mjs --film $f --format 9x16 --hits | tail -1
  node $SK/scripts/sfx.mjs $f | tail -1
  node $SK/scripts/render.mjs --film $f --format 9x16 --video-only | tail -1
  node $SK/scripts/mix.mjs $f --music-db -7 --sfx-db -3 --vo-db 2 | tail -2
  node $SK/scripts/render.mjs --film $f --format 9x16 --poster | tail -1
done
echo "done $(date +%T)"
