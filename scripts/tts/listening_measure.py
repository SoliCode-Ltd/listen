#!/usr/bin/env python3
"""For the "Listening test" workflow: saves each recording the staging run
returned and measures it with ffmpeg: integrated loudness (LUFS), true peak
(dBTP), loudness range, length, and the pauses inside it (silences of 0.15 s
or more below -40 dB, leaving out the start and the end). Writes summary.md
next to the recordings and prints it.

  python3 scripts/tts/listening_measure.py result.jsonl out before
"""
import base64
import json
import os
import re
import statistics
import subprocess
import sys


def ffmpeg_stderr(path, audio_filter):
    return subprocess.run(
        ['ffmpeg', '-hide_banner', '-nostats', '-i', path, '-af', audio_filter, '-f', 'null', '-'],
        capture_output=True, text=True,
    ).stderr


def last_number(pattern, text):
    found = re.findall(pattern, text)
    return found[-1] if found else None


def measure(path):
    loud = ffmpeg_stderr(path, 'ebur128=peak=true')
    duration = subprocess.run(
        ['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', path],
        capture_output=True, text=True,
    ).stdout.strip()
    total = float(duration) if duration else 0.0
    silences = ffmpeg_stderr(path, 'silencedetect=noise=-40dB:d=0.15')
    starts = [float(s) for s in re.findall(r'silence_start: (-?[\d.]+)', silences)]
    ends = [(float(e), float(d)) for e, d in re.findall(r'silence_end: ([\d.]+) \| silence_duration: ([\d.]+)', silences)]
    inner = [d for s, (e, d) in zip(starts, ends) if s > 0.05 and e < total - 0.05]
    return {
        'lufs': last_number(r'I:\s+(-?[\d.]+) LUFS', loud),
        'true_peak': last_number(r'Peak:\s+(-?[\d.]+|-inf) dBFS', loud),
        'lra': last_number(r'LRA:\s+([\d.]+) LU', loud),
        'length': round(total, 2),
        'pauses': len(inner),
        'pause_median': round(statistics.median(inner), 2) if inner else None,
        'pause_max': round(max(inner), 2) if inner else None,
        'pause_min': round(min(inner), 2) if inner else None,
    }


def main():
    source, out, label = sys.argv[1], sys.argv[2], sys.argv[3]
    os.makedirs(out, exist_ok=True)
    rows = []
    for raw in open(source, encoding='utf-8'):
        raw = raw.strip()
        if not raw.startswith('{'):
            continue
        case = json.loads(raw)
        audio = case.pop('audio_base64', None)
        if audio:
            extension = os.path.splitext(case.get('file') or 'x.mp3')[1] or '.mp3'
            path = os.path.join(out, case['id'] + extension)
            with open(path, 'wb') as handle:
                handle.write(base64.b64decode(audio))
            case.update(measure(path))
        # The sample the voice list plays for the case's voice, to hear against the take.
        sample = case.pop('sample_base64', None)
        if sample:
            extension = os.path.splitext(case.get('sample_file') or 'x.mp3')[1] or '.mp3'
            path = os.path.join(out, case['id'][:2] + '-sample' + extension)
            with open(path, 'wb') as handle:
                handle.write(base64.b64decode(sample))
            case['sample'] = measure(path)
        rows.append(case)

    lines = [f'# Listening test: {label}', '']
    lines.append('| case | result | made in | length | loudness | true peak | range | pauses inside (count, median / min / max s) |')
    lines.append('|---|---|---|---|---|---|---|---|')
    for row in rows:
        result = row.get('status') or ('refused' if row.get('refused') else 'error')
        if row.get('http') and row.get('http') >= 400:
            result = f"refused ({row['http']})"
        pauses = '-' if not row.get('pauses') else f"{row['pauses']}, {row['pause_median']} / {row['pause_min']} / {row['pause_max']}"
        lines.append('| {id} | {result} | {seconds} s | {length} s | {lufs} LUFS | {tp} dBTP | {lra} LU | {pauses} |'.format(
            id=row['id'], result=result, seconds=row.get('seconds', '-'), length=row.get('length', '-'),
            lufs=row.get('lufs', '-'), tp=row.get('true_peak', '-'), lra=row.get('lra', '-'), pauses=pauses,
        ))
    short = [float(r['lufs']) for r in rows if r['id'][:2] in ('01', '02', '03', '04') and r.get('lufs') not in (None, '-')]
    if len(short) > 1:
        lines += ['', f'Loudness spread across the short takes 01 to 04: {max(short) - min(short):.1f} LU (from {min(short)} to {max(short)} LUFS).']
    speeds = {r['id'][:2]: r.get('length') for r in rows}
    if speeds.get('02'):
        for key, name in (('07', '0.9x'), ('08', '1.1x'), ('09', '1.2x')):
            if speeds.get(key):
                lines.append(f'Speed {name}: {speeds[key]} s against {speeds["02"]} s at 1.0x ({speeds[key] / speeds["02"]:.2f} of its length).')
    compared = [r for r in rows if r.get('sample')]
    if compared:
        lines += ['', 'The samples the voice list plays for the same voices (NN-sample):', '']
        for row in compared:
            s = row['sample']
            lines.append(f"- {row['id'][:2]}-sample: {s.get('length')} s, {s.get('lufs')} LUFS, true peak {s.get('true_peak')} dBTP")
    lines += ['', '## What to listen for', '']
    for row in rows:
        detail = row.get('refused') or row.get('error') or ''
        lines.append(f"- **{row['id']}**: {row.get('listen_for', '')}" + (f' ({detail})' if detail else ''))

    summary = '\n'.join(lines) + '\n'
    with open(os.path.join(out, 'summary.md'), 'w', encoding='utf-8') as handle:
        handle.write(summary)
    with open(os.path.join(out, 'results.json'), 'w', encoding='utf-8') as handle:
        json.dump(rows, handle, ensure_ascii=False, indent=1)
    print(summary)


if __name__ == '__main__':
    main()
