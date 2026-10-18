#!/usr/bin/env python3
"""
Make a "before and after" clip for a sound environment: the voice starts clean,
then at a pause it moves into the place (the same line, already recorded both
ways). It only edits the two existing files; nothing is generated.

    python3 scripts/tts/environment_morph.py env-en-cave env-ar-cave

Writes public/audio/environments/morph/<slug>.mp3. Needs ffmpeg and numpy.
"""
import os
import subprocess
import sys

import numpy as np

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DIR = os.path.join(ROOT, 'public', 'audio', 'environments')
RATE = 44100
HOP = 0.01


def load(path):
    raw = subprocess.run(['ffmpeg', '-v', 'error', '-i', path, '-ac', '2', '-ar', str(RATE), '-f', 'f32le', '-'],
                         capture_output=True, check=True).stdout
    return np.frombuffer(raw, dtype=np.float32).reshape(-1, 2).copy()


def envelope(audio):
    """Loudness every 10 ms, in dB."""
    mono = audio.mean(axis=1)
    hop = int(RATE * HOP)
    n = len(mono) // hop
    frames = mono[:n * hop].reshape(n, hop)
    return 20 * np.log10(np.sqrt((frames.astype(np.float64) ** 2).mean(axis=1)) + 1e-9)


def lag_of(clean, place):
    """How much later the voice starts in the place version, in seconds (cross-correlation of loudness)."""
    a, b = envelope(clean), envelope(place)
    a = np.clip(a - np.percentile(a, 50), 0, None)
    b = np.clip(b - np.percentile(b, 50), 0, None)
    best, lag = -1.0, 0
    for k in range(0, min(300, len(b) - 1)):
        n = min(len(a), len(b) - k)
        score = float(np.dot(a[:n], b[k:k + n]))
        if score > best:
            best, lag = score, k
    return lag * HOP


def switch_point(clean):
    """The first clear pause between 2.5 s and 7 s of the clean voice (the middle of it), in seconds."""
    db = envelope(clean)
    floor = np.percentile(db, 95) - 30
    quiet = db < floor
    best = None
    i = int(2.5 / HOP)
    while i < min(len(db), int(7 / HOP)):
        if quiet[i]:
            j = i
            while j < len(db) and quiet[j]:
                j += 1
            if (j - i) * HOP >= 0.18:
                return (i + j) / 2 * HOP
            if best is None or (j - i) > best[1] - best[0]:
                best = (i, j)
            i = j
        i += 1
    return ((best[0] + best[1]) / 2 * HOP) if best else 3.0


def voiced_rms(audio):
    db = envelope(audio)
    mono = audio.mean(axis=1)
    hop = int(RATE * HOP)
    keep = db > np.percentile(db, 95) - 20
    frames = [mono[k * hop:(k + 1) * hop] for k in np.nonzero(keep)[0]]
    return float(np.sqrt(np.mean(np.concatenate(frames) ** 2))) if frames else 1.0


def morph(slug):
    clean = load(os.path.join(DIR, 'clean', slug + '.mp3'))
    place = load(os.path.join(DIR, slug + '.mp3'))
    lag = lag_of(clean, place)
    at = switch_point(clean)
    # Keep the two halves equally loud, so only the space changes, not the volume.
    place = place * (voiced_rms(clean) / max(1e-6, voiced_rms(place)))
    fade = int(0.45 * RATE)
    cut = int(at * RATE)
    head = clean[:cut + fade // 2]
    tail = place[int((at + lag) * RATE) - fade // 2:]
    t = np.linspace(0, np.pi / 2, fade)[:, None]
    mixed = np.concatenate([
        head[:-fade],
        head[-fade:] * np.cos(t) + tail[:fade] * np.sin(t),
        tail[fade:],
    ])
    # Headroom for the MP3 encoder, which overshoots the peaks a little.
    peak = float(np.max(np.abs(mixed)))
    if peak > 0.85:
        mixed *= 0.85 / peak
    out_dir = os.path.join(DIR, 'morph')
    os.makedirs(out_dir, exist_ok=True)
    out = os.path.join(out_dir, slug + '.mp3')
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-f', 'f32le', '-ar', str(RATE), '-ac', '2', '-i', '-',
                    '-codec:a', 'libmp3lame', '-b:a', '160k', out], input=mixed.astype(np.float32).tobytes(), check=True)
    print(f'{slug}: voice {lag:.2f}s later in the place; moves in at {at:.2f}s -> {os.path.relpath(out, ROOT)}')


if __name__ == '__main__':
    for slug in sys.argv[1:]:
        morph(slug)
