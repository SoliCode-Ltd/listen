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
  TASHKEEL=formants,engine   the standard engine's name clips the listeners disagreed on
  TASHKEEL=formants,jidda    the premium voice's جدة clips
  TASHKEEL=formants,splits,20261124,031500   every clip of that listening run the listeners disagreed on
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


# The other (standard) engine's name clips the two listeners disagreed on, each against the same
# engine's clip of the other reading. Run 20261123-214519; asked for with "formants,engine".
ENGINE_RUN = '20261123-214519'
ENGINE_PAIRS = [
    ('n13-jidda-engine', 'n13-jadda-engine', 'زرت جدة أمس: asked for Jidda; the other clip is jadda'),
    ('n14-khubar-engine', 'n14-khabar-engine', 'سمعت عن الخبر أمس: asked for al-Khubar; the other clip is al-khabar'),
    ('n15-umar-engine', 'n15-umr-engine', 'سألته عن عمر: asked for ʿUmar; the other clip is ʿumr'),
    ('n16-hasan-engine', 'n16-husn-engine', 'أعجبني حسن كثيراً: asked for Ḥasan; the other clip is ḥusn'),
    ('n19-ala-engine', 'n19-ula-engine', 'تكلمت مع علا: asked for ʿalā; the other clip is ʿUlā'),
    ('n20-hamad-engine', 'n20-hamd-engine', 'شكرت حمد كثيراً: asked for Ḥamad; the other clip is ḥamd'),
    ('n21-aniza-engine', 'n21-unayza-engine', 'وصلنا عنيزة مساء: asked for ʿAnīza; the other clip is ʿUnayza'),
]


# The premium voice's جدة clips (Listener 1 heard "jadda" in all of them) and one عنيزة clip, each
# against a clip both listeners agreed on. Asked for with "formants,jidda". [clip, other, what, run]
JIDDA_PAIRS = [
    ('n13-jidda-take1', 'n13-jadda-take1', 'زرت جدة أمس, marks only, take 1: asked for Jidda', '20261123-220024'),
    ('n13-jidda-take2', 'n13-jadda-take1', 'the same, take 2', '20261123-220024'),
    ('n13-jidda-take3', 'n13-jadda-take1', 'the same, take 3', '20261123-220024'),
    ('n13-jidda-gloss', 'n13-jadda-gloss', 'the same, marks and the meaning told', '20261123-214519'),
    ('n13-jidda-glossonly', 'n13-jadda-glossonly', 'the same, the meaning told and no marks', '20261123-214519'),
    ('n21-aniza-take1', 'n21-unayza-take1', 'وصلنا عنيزة مساء, marks only, take 1: asked for ʿAnīza', '20261123-220024'),
]


# الجزر as carrots with the meaning told and on the other engine: the listeners disagreed on both.
# Asked for with "formants,jazar".
JAZAR_PAIRS = [
    ('n5-jazar-gloss', 'n5-juzur-gloss', 'الجزر كثيرة هنا, marks and the meaning told: asked for al-jazar (carrots); the other clip is al-juzur', '20261123-214519'),
    ('n5-jazar-engine', 'n5-juzur-engine', 'the same on the other engine', '20261123-214519'),
]


def compact(tr):
    """The whole clip on a few lines: F1/F2 in tens of Hz every 20 ms, a dot where there is no voice."""
    for start in range(0, len(tr['t']), 50):
        cells = []
        for k in range(start, min(start + 50, len(tr['t'])), 2):
            voiced = tr['f0'][k] > 0 and np.isfinite(tr['f1'][k]) and np.isfinite(tr['f2'][k])
            cells.append(f"{tr['f1'][k] / 10:.0f}/{tr['f2'][k] / 10:.0f}" if voiced else '.')
        print(f"     {tr['t'][start]:4.2f}s  " + ' '.join(cells))


def fetch(clip):
    if '.' in clip:  # a file name, as splits.json gives it
        with urllib.request.urlopen(f'{BASE}/{RUN}/{clip}', timeout=30) as r:
            return r.read()
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


def splits():
    """
    Every clip of a listening run the two listeners disagreed on (splits.json, written beside the
    clips), against the same sentence's other clip when the listeners agreed on that one. Where no
    vowel differs much the two clips say the same sounds; where one does, both are printed to be read.
    """
    import json
    with urllib.request.urlopen(f'{BASE}/{RUN}/splits.json', timeout=30) as r:
        entries = json.loads(r.read().decode('utf-8'))
    print(f'{len(entries)} clip(s) the listeners disagreed on, run {RUN}')
    for e in entries:
        print(f"\n== {e['id']}: meant {e['want']}; the listeners heard {e['heard'][0]} and {e['heard'][1]}")
        try:
            a = tracks(sound(fetch(e['clip'])))
        except Exception as err:
            print(f'   could not be read: {err}')
            continue
        if not e.get('partner'):
            print('   no other clip of this sentence the listeners agree on: the clip alone, F1/F2 in tens of Hz every 20 ms')
            compact(a)
            continue
        try:
            b = tracks(sound(fetch(e['partner'])))
        except Exception as err:
            print(f'   the other clip could not be read: {err}')
            continue
        heard = 'both heard the reading meant' if e['partner_result'] == 'ok' else 'both heard ANOTHER reading'
        print(f"   against {e['partner']} ({heard}); lengths {a['t'][-1]:.2f}s and {b['t'][-1]:.2f}s")
        to = align(a['feat'], b['feat'])
        big = 0
        lines = []
        for n, k in enumerate(nuclei(a), 1):
            j = int(to[k])
            f1a, f2a, f1b, f2b = mean_at(a, k, 'f1'), mean_at(a, k, 'f2'), mean_at(b, j, 'f1'), mean_at(b, j, 'f2')
            far = np.isfinite(f1a + f2a + f1b + f2b) and (abs(f1a - f1b) >= 200 or abs(f2a - f2b) >= 450)
            big += int(far)
            lines.append(f'   {n:>4}  {a["t"][k]:5.2f}   {f1a:5.0f} {f2a:5.0f}  |  {b["t"][j]:5.2f}  {f1b:5.0f} {f2b:5.0f}  |  {f1a - f1b:+6.0f}  {f2a - f2b:+6.0f}{"   <<" if far else ""}')
        if big == 0:
            print('   no vowel differs much between the two clips: they say the same sounds')
            continue
        print(f'   {big} vowel(s) differ: vowel, at(s), this clip F1 F2 | the other clip at(s) F1 F2 | differences')
        print('\n'.join(lines))
        for name, tr in ((e['clip'], a), (e['partner'], b)):
            print(f'   {name}: F1/F2 in tens of Hz every 20 ms')
            compact(tr)


def main():
    if not BASE:
        sys.exit('BASE is not set')
    # "formants,n14" measures only the pairs whose clip names start with n14, and prints them in full.
    global RUN
    only = [x for x in os.environ.get('TASHKEEL', '').split(',')[1:] if x]
    pairs = PAIRS
    if 'splits' in only:
        # "formants,splits,20261124,031500": the run 20261124-031500.
        numbers = [x for x in only if x.isdigit()]
        RUN = '-'.join(numbers[:2])
        return splits()
    if 'engine' in only:
        pairs, RUN, only = ENGINE_PAIRS, ENGINE_RUN, [x for x in only if x != 'engine']
        brief = True
    elif 'jazar' in only:
        pairs, only, brief = JAZAR_PAIRS, [x for x in only if x != 'jazar'], True
    elif 'jidda' in only:
        pairs, only, brief = JIDDA_PAIRS, [x for x in only if x != 'jidda'], True
    else:
        brief = False
    shown = set()
    for pair in pairs:
        asked, other, what = pair[:3]
        if len(pair) > 3:
            RUN = pair[3]
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
        if brief:
            for name, tr in ((asked, a), (other, b)):
                if name in shown:
                    continue
                shown.add(name)
                print(f'   {name}: F1/F2 in tens of Hz every 20 ms')
                compact(tr)
        elif only or asked == PAIRS[0][0]:
            for name, tr in ((asked, a), (other, b)):
                print(f'   {name}, every 20 ms: time loudness pitch F1 F2')
                for k in range(0, min(len(tr['t']), 141 if not only else 400), 2):
                    print(f'     {tr["t"][k]:4.2f} {tr["loud"][k]:5.1f} {tr["f0"][k]:4.0f} {tr["f1"][k]:5.0f} {tr["f2"][k]:5.0f}')


if __name__ == '__main__':
    main()
