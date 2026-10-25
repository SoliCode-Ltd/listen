# /// script
# dependencies = ["numpy", "soundfile"]
# ///
"""Build the studio tour's voice track and timeline.

    uv run build_tour.py

The tour (src/Tour.tsx) stays in the studio: it opens on Sumaya's clip playing in the studio's
player, shows how it was made, then each of the other five speakers with one of the studio's
options, and each clip plays in full in the player. Reads public/audio/clips/<slug>.mp3 (trimmed
as build_vo.py trims them). Writes:
  public/audio/tour_vo.wav     48 kHz mono, the voices
  src/tour-timeline.json       each beat's start and end, each clip's start and trim, the accents
"""

import json
import pathlib

import numpy as np
import soundfile as sf

from build_vo import SR, bounds, load

ROOT = pathlib.Path(__file__).parent
HOWTO = [("library", 2.2), ("dialect", 1.7), ("type", 2.1), ("generate", 1.8)]
VOICES = [("abdulaziz-proud", "library"), ("sawsan-calm", "style"), ("abdelilah-surprised", "sounds"),
          ("sundus-sad", "feelings"), ("hamed-whisper", "rhythm")]
OPTION = 1.4     # an option on screen before its voice
LAND = 0.45      # the camera lands on the input before the voice starts
AFTER = 0.35     # the player holds after the clip ends
HISTORY = 2.2
END = 3.2


def clip(slug):
    x = load(slug)
    a, b = bounds(x)
    a, b = max(0, a - int(0.04 * SR)), min(len(x), b + int(0.12 * SR))
    seg = x[a:b].copy()
    fade = int(0.006 * SR)
    seg[:fade] *= np.linspace(0, 1, fade)
    seg[-fade:] *= np.linspace(1, 0, fade)
    return seg, a / SR


def main():
    beats, pieces, sfx, t = [], [], [], 0.0

    def voice(slug, kind, start):
        seg, lead = clip(slug)
        audio = start + LAND
        end = audio + len(seg) / SR + AFTER
        beats.append({"kind": kind, "slug": slug, "start": round(start, 3), "audio": round(audio, 3), "lead": round(lead, 3), "end": round(end, 3)})
        pieces.append((audio, seg))
        return end

    t = voice("sumaya-excited", "open", 0.0)
    for name, dur in HOWTO:
        beats.append({"kind": name, "start": round(t, 3), "end": round(t + dur, 3)})
        sfx.append([round(t, 3), "tick-hi"])
        if name == "generate":
            sfx.append([round(t + 0.35, 3), "tap"])
            sfx.append([round(t + 1.0, 3), "shimmer"])
        t += dur
    for slug, option in VOICES:
        beats.append({"kind": option, "slug": slug, "start": round(t, 3), "end": round(t + OPTION, 3)})
        sfx.append([round(t, 3), "tick-hi"])
        sfx.append([round(t + 0.5, 3), "tick-lo"])
        t = voice(slug, "voice", t + OPTION)
    beats.append({"kind": "history", "start": round(t, 3), "end": round(t + HISTORY, 3)})
    sfx.append([round(t - 0.3, 3), "sweep"])
    t += HISTORY
    beats.append({"kind": "end", "start": round(t, 3), "end": round(t + END, 3)})
    sfx.append([round(t, 3), "bell"])
    duration = round(t + END, 2)

    out = np.zeros(int((duration + 0.05) * SR), dtype="float32")
    for start, seg in pieces:
        i = int(start * SR)
        out[i: i + len(seg)] += seg[: len(out) - i]
    out *= 0.89 / (np.abs(out).max() or 1)
    sf.write(ROOT / "public/audio/tour_vo.wav", out, SR, subtype="PCM_24")
    (ROOT / "src/tour-timeline.json").write_text(json.dumps({"beats": beats, "sfx": sfx, "duration": duration}, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    for b in beats:
        print(f"{b['start']:6.2f} {b['end']:6.2f}  {b['kind']:9s} {b.get('slug', '')}")
    print(f"duration {duration} s")


if __name__ == "__main__":
    main()
