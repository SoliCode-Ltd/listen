"""How well a dub keeps to the original speaker ("Dub my video").

Hears the original video and the dub with a speech recogniser (faster-whisper,
word times), then measures what a viewer sees:

  1. On the lips: for each line, how far the new voice starts and stops from
     where the speaker starts and stops it (the line windows come from the
     dub's report.json, else from the original's own sentences).
  2. No drift: words said in both (names, places, numbers, key nouns), how
     far apart they are; a word a phrase early or late shows here.
  3. Nothing left out, nothing extra: time the speaker talks while the new
     voice is silent, and the other way round.

Prints a table and a PASS / CHECK line for each. It never calls the site or
any paid service; the recogniser runs on this machine.

  python3 scripts/dubbing/sync_check.py --original original.mp4 --dub dub.mp4 \
      --report report.json --models-dir ~/whisper --cache ~/whisper/cache

A transcript made once (--cache) is reused for the same file. A transcript
can also be given as it is (--original-words / --dub-words): a JSON list of
[word, start, end], or lines with "words: word@start-end ...".
"""

import argparse
import difflib
import hashlib
import json
import os
import re
import statistics
import subprocess
import sys

# Thresholds (seconds) for PASS.
START_OFF = 0.2
END_OFF = 0.3
DRIFT = 0.7
UNVOICED = 2.0

DIACRITICS = re.compile('[ً-ْٰـ]')
NOT_LETTER = re.compile(r'[^\w]', re.UNICODE)
COMMON = set('''من في على الى عن ان هذا هذه ده دي اللي التي الذي الذين كان كانت مع لا ما هو هي قد لم لن ثم او كل بعد قبل حتى
عند لكن يا انه انها كما بين ذلك تلك هنا كده بس عشان علشان اوي جدا يعني فيه فيها منه منها عليه عليها اله الله'''.split())


def norm(word):
    w = DIACRITICS.sub('', word)
    w = NOT_LETTER.sub('', w)
    w = re.sub('[أإآٱ]', 'ا', w).replace('ى', 'ي').replace('ة', 'ه').replace('ؤ', 'و').replace('ئ', 'ي')
    for prefix in ('وبال', 'وال', 'بال', 'كال', 'فال', 'لل', 'ال'):
        if w.startswith(prefix) and len(w) - len(prefix) >= 3:
            w = w[len(prefix):]
            break
    if w[:1] in ('و', 'ف', 'ب') and len(w) > 4:
        w = w[1:]
    return w


def load_words(path):
    """A transcript: {"words": [[w, s, e]], "segments": [[s, e, text]]} as JSON, or the
    recogniser's printout: "[ s- e] text" lines, each followed by "words: w@s-e ..."."""
    text = open(path, encoding='utf-8').read()
    try:
        data = json.loads(text)
        if isinstance(data, list):
            data = {'words': data, 'segments': []}
        return ([(str(w), float(a), float(b)) for w, a, b in data['words']],
                [(float(a), float(b), str(t)) for a, b, t in data.get('segments', [])])
    except ValueError:
        pass
    words, segments = [], []
    for line in text.splitlines():
        m = re.match(r'^\[\s*([\d.]+)-\s*([\d.]+)\]\s*(.*)$', line)
        if m:
            segments.append((float(m.group(1)), float(m.group(2)), m.group(3)))
        if 'words:' in line:
            for m in re.finditer(r'(\S+)@([\d.]+)-([\d.]+)', line.split('words:', 1)[1]):
                words.append((m.group(1), float(m.group(2)), float(m.group(3))))
    return words, segments


def transcribe(media, args):
    digest = hashlib.md5(open(media, 'rb').read()).hexdigest()[:16]
    cached = os.path.join(args.cache, digest + '-' + args.model + '.json') if args.cache else None
    if cached and os.path.exists(cached):
        return load_words(cached)
    try:
        import numpy as np
        from faster_whisper import WhisperModel
    except ImportError:
        sys.exit('faster-whisper is not installed: pip install faster-whisper (or give --original-words / --dub-words).')
    pcm = subprocess.run([args.ffmpeg, '-v', 'error', '-i', media, '-vn', '-f', 'f32le', '-ac', '1', '-ar', '16000', '-'],
                         capture_output=True, check=True).stdout
    model = WhisperModel(args.model, device='cpu', compute_type='int8', cpu_threads=os.cpu_count() or 2,
                         download_root=args.models_dir or None)
    heard, _ = model.transcribe(np.frombuffer(pcm, dtype=np.float32), language=args.language, word_timestamps=True,
                                vad_filter=False, beam_size=5)
    heard = list(heard)
    words = [(w.word.strip(), round(w.start, 2), round(w.end, 2)) for s in heard for w in s.words if w.word.strip()]
    segments = [(round(s.start, 2), round(s.end, 2), s.text.strip()) for s in heard]
    if cached:
        os.makedirs(args.cache, exist_ok=True)
        json.dump({'words': words, 'segments': segments}, open(cached, 'w', encoding='utf-8'), ensure_ascii=False)
    return words, segments


def same(a, b):
    return a == b or (min(len(a), len(b)) >= 5 and difflib.SequenceMatcher(None, a, b).ratio() >= 0.8)


def aligned(orig, dub, reach=8.0):
    """The words said in both, paired in order (each at most once, never crossing):
    [(dub time - original time, original word, dub word, original time, dub time)]."""
    o = [(norm(w), s, w) for w, s, _ in orig]
    o = [x for x in o if len(x[0]) >= 3 and x[0] not in COMMON]
    d = [(norm(w), s, w) for w, s, _ in dub]
    d = [x for x in d if len(x[0]) >= 3 and x[0] not in COMMON]
    n, m = len(o), len(d)
    best = [[0.0] * (m + 1) for _ in range(n + 1)]
    for i in range(1, n + 1):
        for j in range(1, m + 1):
            best[i][j] = max(best[i - 1][j], best[i][j - 1])
            if abs(o[i - 1][1] - d[j - 1][1]) <= reach and same(o[i - 1][0], d[j - 1][0]):
                # A pair counts for more the nearer in time it is, so a repeated word pairs with its own.
                best[i][j] = max(best[i][j], best[i - 1][j - 1] + 1 + 0.01 * (reach - abs(o[i - 1][1] - d[j - 1][1])))
    pairs, i, j = [], n, m
    while i and j:
        if abs(o[i - 1][1] - d[j - 1][1]) <= reach and same(o[i - 1][0], d[j - 1][0]) and \
                abs(best[i][j] - (best[i - 1][j - 1] + 1 + 0.01 * (reach - abs(o[i - 1][1] - d[j - 1][1])))) < 1e-9:
            pairs.append((round(d[j - 1][1] - o[i - 1][1], 2), o[i - 1][2], d[j - 1][2], o[i - 1][1], d[j - 1][1]))
            i, j = i - 1, j - 1
        elif best[i - 1][j] >= best[i][j - 1]:
            i -= 1
        else:
            j -= 1
    return pairs[::-1]


def span(words, a, b):
    """First start and last end of the words whose middle is inside [a, b]."""
    inside = [(s, e) for _, s, e in words if a <= (s + e) / 2 <= b]
    return (inside[0][0], inside[-1][1]) if inside else (None, None)


def talking(words, until, close=0.3, step=0.02):
    """Where someone talks, every 20 ms: a word's time, gaps under 0.3 s closed."""
    frames = [False] * (int(until / step) + 1)
    merged = []
    for _, s, e in sorted(words, key=lambda x: x[1]):
        if merged and s - merged[-1][1] <= close:
            merged[-1][1] = max(merged[-1][1], e)
        else:
            merged.append([s, e])
    for s, e in merged:
        for i in range(int(s / step), min(len(frames), int(e / step) + 1)):
            frames[i] = True
    return frames


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--original', help='the original video or its sound')
    p.add_argument('--dub', help='the dubbed video')
    p.add_argument('--report', help="the dub's report.json (line windows)")
    p.add_argument('--original-words')
    p.add_argument('--dub-words')
    p.add_argument('--model', default='small')
    p.add_argument('--models-dir', default='')
    p.add_argument('--cache', default='')
    p.add_argument('--language', default='ar')
    p.add_argument('--ffmpeg', default='ffmpeg')
    args = p.parse_args()

    orig, orig_segments = load_words(args.original_words) if args.original_words else transcribe(args.original, args)
    dub, _ = load_words(args.dub_words) if args.dub_words else transcribe(args.dub, args)
    if not orig or not dub:
        sys.exit('No words heard in %s.' % ('the original' if not orig else 'the dub'))
    until = max(orig[-1][2], dub[-1][2]) + 1

    report = json.load(open(args.report, encoding='utf-8')) if args.report else None
    lines = [(float(l['start']), float(l['end']), l.get('original', ''), l.get('dubbed', ''))
             for l in (report or {}).get('lines', []) if not l.get('quiet')]
    if not lines:
        lines = [(a, b, t, '') for a, b, t in orig_segments]
    lines.sort()

    # 1. On the lips.
    print('== 1. Each line: where the speaker starts and stops it, and the new voice ==')
    print('   line            speaker on/off      new voice on/off    start   end')
    starts, ends, worst = [], [], []
    for k, (a, b, said, dubbed) in enumerate(lines):
        lo = max(a - 0.4, (lines[k - 1][1] + a) / 2) if k else a - 0.4
        hi = min(b + 0.4, (b + lines[k + 1][0]) / 2) if k + 1 < len(lines) else b + 0.4
        os_, oe = span(orig, lo, hi)
        ds, de = span(dub, lo - 0.2, hi + 0.2)
        if os_ is None or ds is None:
            print('  %6.2f-%6.2f  %s' % (a, b, 'no speech heard in the original here' if os_ is None else 'NO NEW VOICE HEARD HERE'))
            if ds is None and os_ is not None:
                worst.append((9.9, a, said, dubbed, 'no new voice'))
            continue
        sd, ed = ds - os_, de - oe
        starts.append(abs(sd))
        ends.append(abs(ed))
        worst.append((max(abs(sd) / START_OFF, abs(ed) / END_OFF), a, said, dubbed, 'start %+.2f s, end %+.2f s' % (sd, ed)))
        print('  %6.2f-%6.2f  %6.2f / %6.2f    %6.2f / %6.2f   %+5.2f  %+5.2f%s' % (a, b, os_, oe, ds, de, sd, ed,
              '   <<' if abs(sd) > START_OFF or abs(ed) > END_OFF else ''))

    # 2. Drift: the same words in both.
    print('\n== 2. Words said in both: how far apart ==')
    drift = aligned(orig, dub)
    for off, ow_, dw, ot, dt in drift:
        if abs(off) > DRIFT:
            print('  %-14s at %6.2f  -> %-14s at %6.2f   %+5.2f s  <<' % (ow_, ot, dw, dt, off))
    offs = [abs(d[0]) for d in drift]

    # 3. Talking where the other is silent.
    a, b = talking(orig, until), talking(dub, until)
    unvoiced = sum(1 for x, y in zip(a, b) if x and not y) * 0.02
    extra = sum(1 for x, y in zip(a, b) if y and not x) * 0.02

    print('\n== Summary ==')
    if starts:
        print('1. On the lips: starts %.2f s typical, %.2f s worst; ends %.2f s typical, %.2f s worst; %d of %d lines off  -> %s' % (
            statistics.median(starts), max(starts), statistics.median(ends), max(ends),
            sum(1 for s, e in zip(starts, ends) if s > START_OFF or e > END_OFF), len(starts),
            'PASS' if max(starts) <= START_OFF and max(ends) <= END_OFF else 'CHECK'))
    if offs:
        print('2. No drift: %d words in both, %.2f s typical, %.2f s worst, %d over %.1f s  -> %s' % (
            len(offs), statistics.median(offs), max(offs), sum(1 for o in offs if o > DRIFT), DRIFT,
            'PASS' if max(offs) <= DRIFT else 'CHECK'))
    print('3. Speaker talking, new voice silent: %.1f s; new voice talking, speaker silent: %.1f s  -> %s' % (
        unvoiced, extra, 'PASS' if unvoiced <= UNVOICED and extra <= UNVOICED else 'CHECK'))
    print('\nWorst lines:')
    for score, at, said, dubbed, why in sorted(worst, reverse=True)[:5]:
        print('  %6.2f  %s%s%s' % (at, why, ('\n          said:   ' + said) if said else '', ('\n          dubbed: ' + dubbed) if dubbed else ''))


if __name__ == '__main__':
    main()
