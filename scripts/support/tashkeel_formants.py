#!/usr/bin/env python3
"""
Which vowel did the voice say? An acoustic check of the round 2 listening
clips, with no listener model in it: for a word asked for in one reading, the
clip is compared with a clip of the same sentence in the other reading (one
both listeners agreed on). The two are lined up in time (DTW over MFCCs), and
at each vowel of the first clip the two lowest resonances of the mouth (F1,
F2) are read in both. The same vowel gives about the same F1/F2 in both
clips; a different one does not: "a" has a high F1 (about 650-900 Hz in this
voice), "i" a low F1 and a high F2 (above about 1900), "u" a low F1 and a low
F2 (below about 1200).

Runs on the GitHub runner (the "tashkeel-listen" mode with the input
"formants"), reads the clips from staging's public storage, makes no speech
and writes nothing.

  BASE=https://<staging>/storage/tashkeel-listen RUN=20261123-220024 python3 tashkeel_formants.py
  TASHKEEL=formants,n14   only the pairs of sentence n14, printed in full
"""
import io
import os
import sys
import urllib.request

import numpy as np
import parselmouth
import soundfile as sf

BASE = os.environ.get('BASE', '').rstrip('/')
RUN = os.environ.get('RUN', '20261123-220024')
STEP = 0.01

# [the clip in question, the same sentence in the other reading, what it is]
PAIRS = [
    ('n4-aqd-take1', 'n4-iqd-take1', 'العقد: asked for ʿaqd; the other clip is ʿiqd'),
    ('n7-sullam-take1', 'n7-silm-take1', 'السلم: asked for sullam; the other clip is silm'),
    ('n9-kataba-take2', 'n9-kutiba-take1', 'كتب: asked for kataba; the other clip is kutiba'),
    ('n10-nashara-take1', 'n10-nushira-take1', 'نشر: asked for nashara; the other clip is nushira'),
    ('n12-dhakara-take1', 'n12-dhukira-take1', 'ذكر: asked for dhakara; the other clip is dhukira'),
    ('n14-khubar-take1', 'n14-khabar-take2', 'الخبر: asked for al-Khubar; the other clip is al-khabar'),
]


def fetch(clip):
    for ext in ('wav', 'mp3', 'ogg', 'flac'):
        try:
            with urllib.request.urlopen(f'{BASE}/{RUN}/{clip}.{ext}', timeout=30) as r:
                return r.read()
        except Exception:
            continue
    raise RuntimeError(f'{clip}: not found under {RUN}')


def sound(raw):
    data, rate = sf.read(io.BytesIO(raw), dtype='float64', always_2d=True)
    return parselmouth.Sound(data.mean(axis=1), sampling_frequency=rate)


def tracks(snd):
    """Per 10 ms: loudness, pitch (0 = unvoiced), F1, F2; and the MFCCs. Silence at both ends cut."""
    intensity = snd.to_intensity(time_step=STEP)
    times = intensity.xs()
    loud = np.array([intensity.get_value(t) for t in times])
    loud = np.nan_to_num(loud, nan=0.0)
    keep = np.where(loud > loud.max() - 30)[0]
    times = times[keep[0]:keep[-1] + 1]
    loud = loud[keep[0]:keep[-1] + 1]
    pitch = snd.to_pitch(time_step=STEP, pitch_floor=100, pitch_ceiling=500)
    formant = snd.to_formant_burg(time_step=STEP, max_number_of_formants=5, maximum_formant=5500)
    f0 = np.nan_to_num(np.array([pitch.get_value_at_time(t) for t in times]), nan=0.0)
    f1 = np.array([formant.get_value_at_time(1, t) for t in times])
    f2 = np.array([formant.get_value_at_time(2, t) for t in times])
    mfcc = snd.to_mfcc(number_of_coefficients=12, time_step=STEP)
    grid = mfcc.xs()
    coef = mfcc.to_array()[1:]
    cols = np.clip(np.searchsorted(grid, times), 0, coef.shape[1] - 1)
    feat = coef[:, cols].T
    feat = (feat - feat.mean(axis=0)) / (feat.std(axis=0) + 1e-9)
    return {'t': times - times[0], 'loud': loud, 'f0': f0, 'f1': f1, 'f2': f2, 'feat': feat}


def align(a, b):
    """For each frame of a, the frame of b it lines up with (DTW over the MFCCs)."""
    n, m = len(a), len(b)
    cost = np.linalg.norm(a[:, None, :] - b[None, :, :], axis=2)
    acc = np.full((n + 1, m + 1), np.inf)
    acc[0, 0] = 0
    for i in range(1, n + 1):
        for j in range(1, m + 1):
            acc[i, j] = cost[i - 1, j - 1] + min(acc[i - 1, j], acc[i, j - 1], acc[i - 1, j - 1])
    i, j, path = n, m, []
    while i > 0 and j > 0:
        path.append((i - 1, j - 1))
        step = int(np.argmin([acc[i - 1, j - 1], acc[i - 1, j], acc[i, j - 1]]))
        i, j = (i - 1, j - 1) if step == 0 else (i - 1, j) if step == 1 else (i, j - 1)
    to = np.zeros(n, dtype=int)
    for x, y in path:
        to[x] = y
    return to


def nuclei(tr):
    """The middle of each vowel: loudness peaks in voiced speech, at least 90 ms apart."""
    loud, f0 = tr['loud'], tr['f0']
    found = []
    for k in range(2, len(loud) - 2):
        if f0[k] > 0 and loud[k] >= loud[k - 2:k + 3].max() and loud[k] > loud.max() - 18:
            if found and k - found[-1] < 9:
                if loud[k] > loud[found[-1]]:
                    found[-1] = k
            else:
                found.append(k)
    return found


def mean_at(tr, k, name):
    span = tr[name][max(0, k - 2):k + 3]
    span = span[np.isfinite(span)]
    return float(np.median(span)) if len(span) else float('nan')


def vowel(f1, f2):
    if not np.isfinite(f1) or not np.isfinite(f2):
        return '?'
    if f1 >= 620:
        return 'a'
    if f2 >= 1850:
        return 'i'
    if f2 <= 1250:
        return 'u'
    return 'mid'


def main():
    if not BASE:
        sys.exit('BASE is not set')
    # "formants,n14" measures only the pairs whose clip names start with n14, and prints them in full.
    only = [x for x in os.environ.get('TASHKEEL', '').split(',')[1:] if x]
    for asked, other, what in PAIRS:
        if only and not any(asked.startswith(x + '-') for x in only):
            continue
        print(f'\n== {what}\n   {asked}  against  {other}')
        try:
            a, b = tracks(sound(fetch(asked))), tracks(sound(fetch(other)))
        except Exception as e:  # one pair failing must not hide the others
            print(f'   could not be read: {e}')
            continue
        to = align(a['feat'], b['feat'])
        print(f'   lengths: {a["t"][-1]:.2f}s and {b["t"][-1]:.2f}s')
        print('   vowel  at(s)   this clip F1/F2  looks  |  other clip at(s)  F1/F2  looks  |  F1 diff  F2 diff')
        rows = []
        for n, k in enumerate(nuclei(a), 1):
            j = int(to[k])
            f1a, f2a, f1b, f2b = mean_at(a, k, 'f1'), mean_at(a, k, 'f2'), mean_at(b, j, 'f1'), mean_at(b, j, 'f2')
            rows.append((abs(f1a - f1b) + abs(f2a - f2b) / 2, n))
            print(f'   {n:>4}  {a["t"][k]:5.2f}   {f1a:5.0f} {f2a:5.0f}    {vowel(f1a, f2a):<4}  |  {b["t"][j]:5.2f}  {f1b:5.0f} {f2b:5.0f}    {vowel(f1b, f2b):<4}  |  {f1a - f1b:+6.0f}  {f2a - f2b:+6.0f}')
        rows = [r for r in rows if np.isfinite(r[0])]
        if rows:
            print(f'   the vowel that differs most between the two clips: number {max(rows)[1]}')
        if only or asked == PAIRS[0][0]:
            for name, tr in ((asked, a), (other, b)):
                print(f'   {name}, every 20 ms: time loudness pitch F1 F2')
                for k in range(0, min(len(tr['t']), 141 if not only else 400), 2):
                    print(f'     {tr["t"][k]:4.2f} {tr["loud"][k]:5.1f} {tr["f0"][k]:4.0f} {tr["f1"][k]:5.0f} {tr["f2"][k]:5.0f}')


if __name__ == '__main__':
    main()
