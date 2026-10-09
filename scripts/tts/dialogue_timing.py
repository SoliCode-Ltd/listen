#!/usr/bin/env python3
"""
Find when each line of the homepage dialogues is spoken, so the page can
light up the right line in time with the voice.

Each dialogue MP3 is split at its short pauses, every piece is transcribed,
and the pieces are matched to the script lines in order by their words. The
result goes to database/seeders/data/showcase_dialogue_times.json, which
`php artisan tts:showcase --import` reads. Run it after generating dialogues:

    GEMINI_KEY=... python3 scripts/tts/dialogue_timing.py [slug ...]

Needs ffmpeg and a Gemini API key (it transcribes; it does not generate speech).
"""
import base64
import json
import os
import re
import subprocess
import sys
import tempfile
import time
import unicodedata
import urllib.request
from difflib import SequenceMatcher

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
AUDIO = os.path.join(ROOT, 'public', 'audio', 'showcase')
OUT = os.path.join(ROOT, 'database', 'seeders', 'data', 'showcase_dialogue_times.json')
MODEL = 'gemini-3.5-flash'


def dialogues():
    """Slug, turn texts and sign-off of every dialogue, read through PHP."""
    php = (
        '$d = require "database/seeders/data/showcase_samples.php"; $o = [];'
        'foreach ($d["samples"] as $s) { if ($s["kind"] !== "dialogue") continue;'
        ' $o[$s["slug"]] = ["lines" => array_map(fn($l) => trim(explode(":", $l, 2)[1]), $s["lines"]),'
        ' "sign_off" => $d["sign_off"][$s["language"]."_dialogue"]]; } echo json_encode($o);'
    )
    return json.loads(subprocess.check_output(['php', '-r', php], cwd=ROOT))


def norm(text):
    text = re.sub(r'<[^<>]+>', ' ', text)
    text = unicodedata.normalize('NFKD', text)
    text = ''.join(c for c in text if not unicodedata.combining(c))
    for a, b in (('أ', 'ا'), ('إ', 'ا'), ('آ', 'ا'), ('ة', 'ه'), ('ى', 'ي'), ('گ', 'ك'), ('چ', 'ج')):
        text = text.replace(a, b)
    return re.sub(r'[\W_]+', '', text.lower())


def speech_chunks(path):
    duration = float(subprocess.check_output(
        ['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', path]).strip())
    log = subprocess.run(['ffmpeg', '-hide_banner', '-nostats', '-i', path, '-af',
                          'silencedetect=noise=-38dB:d=0.09', '-f', 'null', '-'],
                         capture_output=True, text=True).stderr
    silences, start = [], None
    for m in re.finditer(r'silence_start: ([\d.]+)|silence_end: ([\d.]+)', log):
        if m.group(1):
            start = float(m.group(1))
        elif start is not None:
            silences.append((start, float(m.group(2))))
            start = None
    chunks, cursor = [], 0.0
    for a, b in silences:
        if a > cursor + 0.05:
            chunks.append([cursor, a])
        cursor = b
    if duration > cursor + 0.05:
        chunks.append([cursor, duration])
    # Very short blips join the piece before them.
    merged = []
    for c in chunks:
        if merged and c[1] - c[0] < 0.18:
            merged[-1][1] = c[1]
        else:
            merged.append(c)
    return merged


def transcribe(path, start, end, key):
    clip = tempfile.mktemp(suffix='.mp3')
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-ss', f'{max(0, start - 0.05):.2f}', '-to', f'{end + 0.05:.2f}',
                    '-i', path, clip], check=True)
    body = {'contents': [{'role': 'user', 'parts': [
        {'inline_data': {'mime_type': 'audio/mpeg', 'data': base64.b64encode(open(clip, 'rb').read()).decode()}},
        {'text': 'Transcribe this short audio clip verbatim in its own language. Output only the words, or nothing if there are none.'}]}]}
    for attempt in range(3):
        try:
            req = urllib.request.Request(
                f'https://generativelanguage.googleapis.com/v1beta/models/{MODEL}:generateContent',
                data=json.dumps(body).encode(), headers={'Content-Type': 'application/json', 'x-goog-api-key': key})
            r = json.load(urllib.request.urlopen(req, timeout=120))
            parts = r.get('candidates', [{}])[0].get('content', {}).get('parts', [])
            return ''.join(p.get('text', '') for p in parts).strip()
        except Exception:
            time.sleep(3 * (attempt + 1))
    return ''


def align(chunks, texts, targets):
    """Split the pieces into len(targets) consecutive groups that best match the targets."""
    n, m = len(targets), len(chunks)
    score = lambda g, a, b: SequenceMatcher(None, norm(''.join(texts[a:b])), norm(targets[g])).ratio() * max(1, len(norm(targets[g])))
    best = [[-1e9] * (m + 1) for _ in range(n + 1)]
    back = [[0] * (m + 1) for _ in range(n + 1)]
    best[0][0] = 0
    for g in range(1, n + 1):
        for b in range(g, m + 1):
            for a in range(g - 1, b):
                v = best[g - 1][a] + score(g - 1, a, b)
                if v > best[g][b]:
                    best[g][b], back[g][b] = v, a
    groups, b = [], m
    for g in range(n, 0, -1):
        a = back[g][b]
        groups.append((a, b))
        b = a
    groups.reverse()
    return [(chunks[a][0], chunks[b - 1][1], ' '.join(texts[a:b])) for a, b in groups]


def main():
    key = os.environ.get('GEMINI_KEY', '')
    if not key:
        sys.exit('Set GEMINI_KEY.')
    wanted = set(sys.argv[1:])
    times = json.load(open(OUT)) if os.path.exists(OUT) else {}
    for slug, d in dialogues().items():
        path = os.path.join(AUDIO, slug + '.mp3')
        if (wanted and slug not in wanted) or not os.path.exists(path):
            continue
        chunks = speech_chunks(path)
        texts = [transcribe(path, a, b, key) for a, b in chunks]
        targets = d['lines'] + [d['sign_off']]
        if len(chunks) < len(targets):
            print(f'{slug}: only {len(chunks)} pieces for {len(targets)} lines, skipped')
            continue
        groups = align(chunks, texts, targets)
        times[slug] = [[round(a, 2), round(b, 2)] for a, b, _ in groups[:-1]]
        print(f'== {slug}')
        for (a, b, heard), line in zip(groups, targets):
            print(f'  {a:6.2f}-{b:6.2f}  {line[:50]}\n                 heard: {heard[:70]}')
    with open(OUT, 'w', encoding='utf-8') as f:
        json.dump(times, f, ensure_ascii=False, indent=1)
        f.write('\n')


if __name__ == '__main__':
    main()
