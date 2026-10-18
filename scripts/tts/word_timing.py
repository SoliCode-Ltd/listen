#!/usr/bin/env python3
"""
Find when each word of the showcase voice samples is spoken, so the voice
samples page can fill in every word in time with the voice.

It works from the audio alone, with no transcription service: the clip is split
at its pauses, the pauses are matched to the script's punctuation (a speaker
stops at a comma or a full stop, rarely mid-phrase), and each stretch of speech
is shared out between its words by their syllables. The spoken sign-off after
the long pause at the end is found first and left out.

The result goes to database/seeders/data/showcase_word_times.json, which the
voice samples page reads. Run it after generating samples:

    python3 scripts/tts/word_timing.py [slug ...]

Needs ffmpeg and numpy.
"""
import json
import math
import os
import re
import subprocess
import sys
import unicodedata

import numpy as np

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
AUDIO = os.path.join(ROOT, 'public', 'audio', 'showcase')
OUT = os.path.join(ROOT, 'database', 'seeders', 'data', 'showcase_word_times.json')
RATE = 16000
HOP = 0.01
# Roughly how long each delivery cue takes, in seconds.
CUES = {'laugh': 0.9, 'chuckle': 0.6, 'gasp': 0.45, 'sigh': 0.7}
PUNCTUATION = '.,!?;:…،؛؟'


def samples():
    """Slug, language, script and sign-off of every single-voice sample, read through PHP."""
    php = (
        '$d = require "database/seeders/data/showcase_samples.php"; $o = [];'
        'foreach ($d["samples"] as $s) { if (($s["kind"] ?? "single") !== "single") continue;'
        ' $o[$s["slug"]] = ["language" => $s["language"], "text" => $s["text"],'
        ' "sign_off" => ($s["sign_off"] ?? true) ? $d["sign_off"][$s["language"]] : ""]; } echo json_encode($o);'
    )
    return json.loads(subprocess.check_output(['php', '-r', php], cwd=ROOT))


def load(path):
    raw = subprocess.run(['ffmpeg', '-v', 'error', '-i', path, '-ac', '1', '-ar', str(RATE), '-f', 'f32le', '-'],
                         capture_output=True, check=True).stdout
    return np.frombuffer(raw, dtype=np.float32)


def loudness(audio):
    """Level in dB every 10 ms."""
    hop, win = int(RATE * HOP), int(RATE * 0.025)
    if len(audio) < win:
        return np.zeros(0)
    frames = np.lib.stride_tricks.sliding_window_view(audio, win)[::hop]
    return 20 * np.log10(np.sqrt((frames.astype(np.float64) ** 2).mean(axis=1)) + 1e-9)


def segments(db):
    """Stretches of speech as (start, end) seconds; dips under 80 ms stay inside a stretch."""
    if len(db) == 0:
        return []
    on = db > max(-42.0, np.percentile(db, 99) - 34)
    runs, start = [], None
    for i, v in enumerate(on):
        if v and start is None:
            start = i
        elif not v and start is not None:
            runs.append([start, i])
            start = None
    if start is not None:
        runs.append([start, len(on)])
    merged = []
    for a, b in runs:
        if merged and a - merged[-1][1] < 8:
            merged[-1][1] = b
        else:
            merged.append([a, b])
    return [(a * HOP, b * HOP) for a, b in merged if b - a >= 5]


def syllables(word, language):
    letters = ''.join(c for c in unicodedata.normalize('NFKD', word) if c.isalpha() or c.isdigit())
    if not letters:
        return 0.3
    if language == 'ar':
        digits = sum(c.isdigit() for c in letters)
        return max(1.0, len(letters) - digits) * 0.5 + 0.5 + digits * 1.5
    w = letters.lower()
    count = len(re.findall(r'[aeiouy]+', w))
    if w.endswith('e') and not w.endswith(('le', 'ee')) and count > 1:
        count -= 1
    return max(1, count) + sum(c.isdigit() for c in w) * 1.5


def split_sign_off(segs, text_syllables, sign_off_syllables):
    """The stretches before the sign-off: the cut is the long pause after which the speech left is
    as long as the sign-off should take, at the pace of the script before it."""
    best, cut = None, len(segs)
    for k in range(1, len(segs)):
        gap = segs[k][0] - segs[k - 1][1]
        if gap < 0.3:
            continue
        before = sum(b - a for a, b in segs[:k])
        after = sum(b - a for a, b in segs[k:])
        expect = sign_off_syllables * before / max(1.0, text_syllables)
        cost = abs(math.log(max(after, 0.05) / max(expect, 0.05))) - 0.15 * min(gap, 2.0)
        if best is None or cost < best:
            best, cut = cost, k
    return segs[:cut]


def tokens(text, language):
    """Words (shown) and cues (timed, not shown), in spoken order."""
    out = []
    for part in re.split(r'(<[^<>]+>)', text):
        cue = re.match(r'<\s*([a-z ]+)', part, re.I)
        if cue:
            name = cue.group(1).strip().lower()
            out.append({'cue': name, 'seconds': CUES.get(name, 0.5 if 'pause' not in name else 0.0)})
            continue
        for word in part.split():
            out.append({'word': word, 'syllables': syllables(word, language), 'stop': word.rstrip('"\'»”)').endswith(tuple(PUNCTUATION))})
    return out


def align(segs, toks, db=None):
    """Group the tokens with the stretches of speech so durations fit and pauses fall at punctuation."""
    voiced = sum(b - a for a, b in segs)
    cue_seconds = sum(t.get('seconds', 0) for t in toks if 'cue' in t)
    words = [t for t in toks if 'word' in t]
    per_syllable = max(0.06, (voiced - min(cue_seconds, voiced * 0.45)) / max(1.0, sum(t['syllables'] for t in words)))
    weight = [t['syllables'] * per_syllable if 'word' in t else min(t['seconds'], voiced * 0.45) for t in toks]
    n, m = len(toks), len(segs)

    def boundary_cost(i, pause):
        """Cost of a pause after token i-1."""
        before = toks[i - 1]
        after = toks[i] if i < n else None
        if 'cue' in before or (after is not None and 'cue' in after) or before.get('stop'):
            return -0.6
        return 0.4 + 2.0 * pause

    INF = float('inf')
    best = [[INF] * (m + 1) for _ in range(n + 1)]
    back = [[None] * (m + 1) for _ in range(n + 1)]
    best[0][0] = 0.0
    for i in range(n):
        for j in range(m):
            if best[i][j] == INF:
                continue
            for i2 in range(i + 1, min(n, i + 14) + 1):
                expect = sum(weight[i:i2])
                for j2 in range(j + 1, min(m, j + 6) + 1):
                    dur = sum(b - a for a, b in segs[j:j2])
                    inner = sum(segs[k + 1][0] - segs[k][1] for k in range(j, j2 - 1))
                    cost = 4 * math.log(max(dur, 0.02) / max(expect, 0.02)) ** 2 if expect > 0 else 0.5
                    cost += sum(0.05 + 3.0 * max(0.0, segs[k + 1][0] - segs[k][1] - 0.12) for k in range(j, j2 - 1))
                    if i2 < n:
                        cost += boundary_cost(i2, segs[j2][0] - segs[j2 - 1][1] if j2 < m else 0.0)
                    if best[i][j] + cost < best[i2][j2]:
                        best[i2][j2] = best[i][j] + cost
                        back[i2][j2] = (i, j)
    if best[n][m] == INF:
        return None
    groups, i, j = [], n, m
    while (i, j) != (0, 0):
        pi, pj = back[i][j]
        groups.append((pi, i, pj, j))
        i, j = pi, pj
    groups.reverse()

    timed = [None] * n
    for i, i2, j, j2 in groups:
        parts = segs[j:j2]
        total = sum(b - a for a, b in parts)
        expect = sum(weight[i:i2]) or 1.0

        def at(v):
            """Real time once v seconds of this group's speech have passed."""
            for a, b in parts:
                if v <= b - a:
                    return a + v
                v -= b - a
            return parts[-1][1]

        done = 0.0
        for k in range(i, i2):
            share = weight[k] / expect * total
            timed[k] = (at(done + 1e-6) if done > 0 else parts[0][0], at(done + share))
            done += share
        # Inside a phrase, each boundary moves to the quietest moment within 80 ms: the voice dips between words.
        if db is not None and len(db):
            smooth = np.convolve(db, np.ones(3) / 3, mode='same')
            for k in range(i, i2 - 1):
                t = timed[k][1]
                lo = max(int((timed[k][0] + 0.06) / HOP), int((t - 0.08) / HOP))
                hi = min(int((timed[k + 1][1] - 0.06) / HOP), int((t + 0.08) / HOP), len(smooth) - 1)
                if hi > lo:
                    t = (lo + int(np.argmin(smooth[lo:hi + 1]))) * HOP
                    timed[k] = (timed[k][0], t)
                    timed[k + 1] = (t, timed[k + 1][1])
    return timed


def main():
    wanted = set(sys.argv[1:])
    times = json.load(open(OUT)) if os.path.exists(OUT) else {}
    for slug, s in samples().items():
        path = os.path.join(AUDIO, slug + '.mp3')
        if (wanted and slug not in wanted) or not os.path.exists(path):
            continue
        toks = tokens(s['text'], s['language'])
        db = loudness(load(path))
        segs = segments(db)
        if s['sign_off']:
            text_syllables = sum(t['syllables'] for t in toks if 'word' in t)
            segs = split_sign_off(segs, text_syllables, sum(syllables(w, s['language']) for w in s['sign_off'].split()))
        timed = align(segs, toks, db)
        if timed is None:
            print(f'{slug}: could not match {len(segs)} stretches of speech, skipped')
            continue
        shown = [(t['word'], timed[k]) for k, t in enumerate(toks) if 'word' in t]
        times[slug] = [[round(a, 2), round(b, 2)] for _, (a, b) in shown]
        print(f'== {slug}: {len(segs)} stretches, {len(shown)} words, last word ends {shown[-1][1][1]:.2f}s')
    with open(OUT, 'w', encoding='utf-8') as f:
        json.dump(dict(sorted(times.items())), f, ensure_ascii=False, separators=(',', ':'))
        f.write('\n')


if __name__ == '__main__':
    main()
