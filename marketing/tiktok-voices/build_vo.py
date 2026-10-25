# /// script
# dependencies = ["numpy", "soundfile"]
# ///
"""Build the ad's voice track and timeline from the studio's clips.

    uv run build_vo.py

Reads public/audio/clips/<slug>.mp3 (each made in the studio from the input shown on screen) and
words.json (when each word and sound tag is heard in the clip). Each clip is trimmed to its sound
(the studio's own silence before and after it removed, nothing inside it changed) and placed after
the one before it. Sumaya's clip plays again when it appears in the studio's player. Writes:
  public/audio/vo.wav        48 kHz mono, the voices
  public/audio/vo_env.json   their loudness at 60 fps (0..1), which the orb ripples with
  src/timeline.json          when each part of the ad starts and ends, read by the film
"""

import json
import pathlib
import subprocess

import numpy as np
import soundfile as sf

ROOT = pathlib.Path(__file__).parent
SR = 48000
ORDER = ["sumaya-excited", "abdulaziz-proud", "sawsan-calm", "abdelilah-surprised", "sundus-sad", "hamed-whisper"]
HOOK = 2.5      # the hook, before the first voice
LEAD = 0.25     # a voice's name and line are on screen this long before it speaks
AFTER = 0.3     # and stay this long after
RESULT = 9.0    # seconds into the studio: the voice is ready in the player
REPLAY = 0.3    # it plays this long after
TO_END = 2.0    # the end card comes in this long after it starts playing (it plays on under it)
END_MIN = 3.4   # the end card stays at least this long
OVERLAP = 0.25  # the studio rises in while the last voice's line leaves


def load(slug):
    pcm = subprocess.run(["ffmpeg", "-v", "error", "-i", str(ROOT / "public/audio/clips" / f"{slug}.mp3"),
                          "-f", "f32le", "-ac", "1", "-ar", str(SR), "-"], capture_output=True, check=True).stdout
    return np.frombuffer(pcm, dtype="float32").copy()


def rms(x, win=480):
    pad = np.pad(x, (win // 2, win // 2))
    c = np.cumsum(pad.astype("float64") ** 2)
    return np.sqrt(np.maximum(0, c[win:] - c[:-win]) / win)[: len(x)]


def bounds(x, floor_db=-45):
    """The first and last sample of the clip's sound."""
    e = rms(x)
    on = np.nonzero(e > 10 ** (floor_db / 20) * e.max())[0]
    return int(on[0]), int(on[-1])


def main():
    words = json.loads((ROOT / "words.json").read_text(encoding="utf-8"))
    pieces, voices, t = [], [], HOOK
    for slug in ORDER:
        x = load(slug)
        a, b = bounds(x)
        a, b = max(0, a - int(0.04 * SR)), min(len(x), b + int(0.12 * SR))
        seg = x[a:b].copy()
        fade = int(0.006 * SR)
        seg[:fade] *= np.linspace(0, 1, fade)
        seg[-fade:] *= np.linspace(1, 0, fade)
        lead = a / SR
        audio = t + LEAD
        end = audio + len(seg) / SR + AFTER
        w = words[slug]
        voices.append({"slug": slug, "start": round(t, 3), "audio": round(audio, 3), "end": round(end, 3),
                       "words": [round(v - lead, 3) for v in w["words"]],
                       "sounds": [round(v - lead, 3) for v in w.get("sounds", [])]})
        pieces.append((audio, seg))
        t = end
    studio = t - OVERLAP
    replay = studio + RESULT + REPLAY
    first = pieces[0][1]
    pieces.append((replay, first))
    end_start = replay + TO_END
    end_end = max(end_start + END_MIN, replay + len(first) / SR + 1.4)
    duration = round(end_end, 2)

    out = np.zeros(int((duration + 0.05) * SR), dtype="float32")
    for start, seg in pieces:
        i = int(start * SR)
        out[i: i + len(seg)] += seg[: len(out) - i]
    out *= 0.89 / (np.abs(out).max() or 1)
    sf.write(ROOT / "public/audio/vo.wav", out, SR, subtype="PCM_24")
    hop = SR // 60
    e = rms(out, hop)[::hop]
    e = np.clip(e / (np.percentile(e[e > 0], 98) if (e > 0).any() else 1), 0, 1)
    (ROOT / "public/audio/vo_env.json").write_text(json.dumps({"fps": 60, "env": [round(float(v), 3) for v in e]}))
    timeline = {"hook": {"end": HOOK}, "voices": voices,
                "studio": {"start": round(studio, 3), "end": round(end_start, 3), "result": RESULT, "replay": round(replay, 3)},
                "end": {"start": round(end_start, 3), "end": duration}, "duration": duration}
    (ROOT / "src/timeline.json").write_text(json.dumps(timeline, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    for v in voices:
        print(f"{v['start']:6.2f} {v['audio']:6.2f} {v['end']:6.2f}  {v['slug']}")
    print(f"studio {studio:.2f}  replay {replay:.2f}  end card {end_start:.2f}  duration {duration}")


if __name__ == "__main__":
    main()
