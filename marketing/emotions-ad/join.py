"""Join each cut's takes into one voice track for the talking-video tool.

Usage: python marketing/emotions-ad/join.py [ar|en ...]   (default: both)

Reads the takes in the order of lines.php from marketing/emotions-ad/voices/
(made by the "Launch ad voices" workflow with ad = emotions-ad) and writes, per
cut, into marketing/emotions-ad/track/:

  emotions-<cut>.wav   voice only, 48 kHz mono, -16 LUFS, peaks under -1.5 dBTP
  emotions-<cut>.mp3   the same, for tools that want MP3
  emotions-<cut>.json  where each take starts and ends in the track, its label
                       for the on-screen list (Arabic and English) and its
                       words with times in the track (from voices/words.json)

Every take is cut to its speech, faded in and out, set to one loudness (a
whisper a little quieter, a shout a little louder, so the change still reads)
and placed with a short pause between takes and a longer one before the end
line. The track has no music: the talking-video tool lip-syncs to the voice
alone, and music and sound are added in the edit.
"""

import json
import pathlib
import subprocess
import sys

import numpy as np
import pyloudnorm as pyln
import soundfile as sf

ROOT = pathlib.Path(__file__).resolve().parents[2]
HERE = ROOT / "marketing" / "emotions-ad"
VOICES = HERE / "voices"
OUT = HERE / "track"
SR = 48000

LEAD_IN = 0.4      # silence before the first take
GAP = 0.5          # pause between takes
GAP_END = 0.9      # pause before the end line
TAIL = 0.7         # silence after the end line
TARGET = -18.0     # loudness of each take before the master
MASTER = -16.0

# What the on-screen list highlights for each take, in both languages.
LABELS = {
    "emo-ar-sa": ("سعودي · عادي", "Saudi · Neutral"),
    "emo-ar-lb": ("لبناني · همس", "Lebanese · Whisper"),
    "emo-ar-eg": ("مصري · ضحك", "Egyptian · Laugh"),
    "emo-ar-iq": ("عراقي · بكاء", "Iraqi · Sob"),
    "emo-ar-ma": ("مغربي · صراخ", "Moroccan · Shout"),
    "emo-ar-ae": ("إماراتي · سخرية", "Emirati · Sarcastic"),
    "emo-ar-tn": ("تونسي · إعلان فيلم", "Tunisian · Movie trailer"),
    "emo-ar-kw": ("كويتي · حماس", "Kuwaiti · Excited"),
    "emo-ar-en": ("إنجليزي · حماس", "English · Excited"),
    "emo-ar-cta": ("", ""),
    "emo-en-us": ("أمريكي · عادي", "American · Neutral"),
    "emo-en-gb": ("بريطاني · همس", "British · Whisper"),
    "emo-en-au": ("أسترالي · ضحك", "Australian · Laugh"),
    "emo-en-ie": ("أيرلندي · بكاء", "Irish · Sob"),
    "emo-en-ny": ("نيويورك · صراخ", "New York · Shout"),
    "emo-en-za": ("جنوب أفريقي · سخرية", "South African · Sarcastic"),
    "emo-en-ca": ("كندي · إعلان فيلم", "Canadian · Movie trailer"),
    "emo-en-in": ("هندي · حماس", "Indian · Excited"),
    "emo-en-eg": ("مصري · حماس", "Egyptian Arabic · Excited"),
    "emo-en-cta": ("", ""),
}

# Loudness offsets in dB, so a whisper still sounds like a whisper.
OFFSETS = {"whispers": -3.0, "sob": -1.5, "shout": 1.5}


def lines():
    php = "echo json_encode((require 'marketing/emotions-ad/lines.php')['samples'], JSON_UNESCAPED_UNICODE);"
    out = subprocess.run(["php", "-r", php], cwd=ROOT, capture_output=True, text=True, check=True).stdout
    return json.loads(out)


def load_mono(path):
    raw = subprocess.run(["ffmpeg", "-v", "error", "-i", str(path), "-ac", "1", "-ar", str(SR), "-f", "f32le", "-"],
                         capture_output=True, check=True).stdout
    return np.frombuffer(raw, dtype=np.float32).astype(float)


def speech_span(x, threshold_db=-45):
    hop = int(0.01 * SR)
    frames = len(x) // hop
    rms = np.sqrt(np.array([np.mean(x[i * hop:(i + 1) * hop] ** 2) for i in range(frames)]) + 1e-12)
    loud = np.where(20 * np.log10(rms) > threshold_db)[0]
    if len(loud) == 0:
        return 0, len(x)
    return loud[0] * hop, (loud[-1] + 1) * hop


def prepared(x, tag):
    start, end = speech_span(x)
    cut_from = max(0, start - int(0.06 * SR))
    x = x[cut_from: min(len(x), end + int(0.15 * SR))].copy()
    fade_in, fade_out = int(0.02 * SR), int(0.08 * SR)
    x[:fade_in] *= np.linspace(0, 1, fade_in)
    x[-fade_out:] *= np.linspace(1, 0, fade_out)
    loud = pyln.Meter(SR).integrated_loudness(x)
    if np.isfinite(loud):
        x *= 10 ** ((TARGET + OFFSETS.get(tag, 0.0) - loud) / 20)
    return x, cut_from


def build(cut, samples, words):
    takes = [s for s in samples if s["slug"].startswith(f"emo-{cut}-")]
    missing = [s["slug"] for s in takes if not (VOICES / f"{s['slug']}.wav").exists()]
    if missing:
        sys.exit(f"{cut}: missing takes {', '.join(missing)}; run the voice workflow first")

    parts, timeline, at = [np.zeros(int(LEAD_IN * SR))], [], LEAD_IN
    for i, s in enumerate(takes):
        slug = s["slug"]
        is_end = slug.endswith("-cta")
        if i:
            gap = GAP_END if is_end else GAP
            parts.append(np.zeros(int(gap * SR)))
            at += gap
        tag = s["text"].split(">")[0].lstrip("<").strip() if s["text"].startswith("<") else ""
        audio, cut_from = prepared(load_mono(VOICES / f"{slug}.wav"), tag)
        parts.append(audio)
        length = len(audio) / SR
        heard = words.get(slug, {}).get("words", [])
        timeline.append({
            "slug": slug,
            "dialect": s.get("dialect"),
            "label_ar": LABELS.get(slug, ("", ""))[0],
            "label_en": LABELS.get(slug, ("", ""))[1],
            "text": s["text"].split(">")[-1].strip(),
            "start": round(at, 3),
            "end": round(at + length, 3),
            "words": [{"w": w["w"], "start": round(at + w["start"] - cut_from / SR, 3),
                       "end": round(at + w["end"] - cut_from / SR, 3)} for w in heard],
        })
        at += length
    parts.append(np.zeros(int(TAIL * SR)))
    track = np.concatenate(parts)

    OUT.mkdir(parents=True, exist_ok=True)
    raw = OUT / f"emotions-{cut}.raw.wav"
    sf.write(raw, track.astype(np.float32), SR, subtype="FLOAT")
    master = f"alimiter=limit=0.84:attack=3:release=60:level=false,loudnorm=I={MASTER}:TP=-1.5:LRA=11:linear=true"
    subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", str(raw), "-af", master, "-ar", str(SR), "-ac", "1",
                    "-c:a", "pcm_s16le", str(OUT / f"emotions-{cut}.wav")], check=True)
    subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", str(OUT / f"emotions-{cut}.wav"), "-c:a", "libmp3lame",
                    "-b:a", "192k", str(OUT / f"emotions-{cut}.mp3")], check=True)
    raw.unlink()
    (OUT / f"emotions-{cut}.json").write_text(json.dumps(
        {"duration": round(len(track) / SR, 3), "takes": timeline}, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"{cut}: {len(takes)} takes, {len(track) / SR:.1f} s -> track/emotions-{cut}.wav")


if __name__ == "__main__":
    cuts = sys.argv[1:] or ["ar", "en"]
    words_file = VOICES / "words.json"
    words = json.loads(words_file.read_text(encoding="utf-8")) if words_file.exists() else {}
    samples = lines()
    for cut in cuts:
        build(cut, samples, words)
