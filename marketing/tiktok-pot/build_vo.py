# /// script
# dependencies = ["numpy", "soundfile"]
# ///
"""Build a version's voice track from its cues.

    uv run build_vo.py <film>      (e.g. eg, eg-short, gulf)

Reads <film>/film.json "cues". A cue with a "clip" plays public/audio/tiktok-pot/<clip>.wav from
"from" to "to" (seconds; each cut is moved to the quietest point within 120 ms, so no syllable is
clipped). Cues follow each other with "gap" seconds of air (default 0.16); "hold" is how long a
cue without a clip stays (studio, end). Writes:
  <film>/audio/vo.wav        48 kHz mono, the edit
  <film>/audio/vo_env.json   its loudness at 60 fps (0..1), which moves the waves on screen
and puts each cue's start ("at", the moment its speech begins) and the total "duration" back into
film.json, so picture and sound come from the same numbers.
"""

import json
import pathlib
import sys

import numpy as np
import soundfile as sf

ROOT = pathlib.Path(__file__).parent
CLIPS = ROOT.parent.parent / "public" / "audio" / "tiktok-pot"
SR = 48000


def load(name):
    data, sr = sf.read(CLIPS / f"{name}.wav", dtype="float32", always_2d=True)
    data = data.mean(axis=1)
    if sr != SR:
        idx = np.arange(0, len(data), sr / SR)
        data = np.interp(idx, np.arange(len(data)), data).astype("float32")
    return data


def rms(x, win=480):
    pad = np.pad(x, (win // 2, win // 2))
    c = np.cumsum(pad ** 2)
    return np.sqrt((c[win:] - c[:-win]) / win)[: len(x)]


def snap(x, t, reach=0.12):
    """The quietest sample within reach seconds of t."""
    e = rms(x)
    a, b = max(0, int((t - reach) * SR)), min(len(x) - 1, int((t + reach) * SR))
    if b <= a:
        return int(t * SR)
    return a + int(np.argmin(e[a:b]))


def onset(x, floor_db=-38):
    """First sample where the speech is clearly on."""
    e = rms(x)
    th = 10 ** (floor_db / 20) * max(1e-6, e.max())
    on = np.nonzero(e > th)[0]
    return int(on[0]) if len(on) else 0


def tail(x, floor_db=-42):
    e = rms(x)
    th = 10 ** (floor_db / 20) * max(1e-6, e.max())
    on = np.nonzero(e > th)[0]
    return int(on[-1]) if len(on) else len(x)


def main(film):
    path = ROOT / film / "film.json"
    cfg = json.loads(path.read_text(encoding="utf-8"))
    pieces, t = [], float(cfg.get("start", 0.2))
    for cue in cfg["cues"]:
        cue.pop("lead", None)
        if "clip" not in cue:
            cue["at"] = round(t, 3)
            t += float(cue.get("hold", 2.5)) + float(cue.get("gap", 0))
            continue
        x = load(cue["clip"])
        a = snap(x, float(cue.get("from", 0)))
        b = snap(x, float(cue["to"])) if "to" in cue else len(x)
        seg = x[a:b]
        on, off = onset(seg), tail(seg)
        seg = seg[max(0, on - int(0.02 * SR)): off + int(0.12 * SR)]
        fade = int(0.008 * SR)
        seg[:fade] *= np.linspace(0, 1, fade)
        seg[-fade:] *= np.linspace(1, 0, fade)
        t += float(cue.get("gap", 0.16))
        cue["at"] = round(t, 3)
        cue["len"] = round(len(seg) / SR, 3)
        pieces.append((t, seg))
        t += len(seg) / SR
    duration = round(t, 2)
    out = np.zeros(int((duration + 0.05) * SR), dtype="float32")
    for start, seg in pieces:
        i = int(start * SR)
        out[i: i + len(seg)] += seg[: len(out) - i]
    peak = np.abs(out).max() or 1
    out *= 0.89 / peak
    (ROOT / film / "audio").mkdir(parents=True, exist_ok=True)
    sf.write(ROOT / film / "audio" / "vo.wav", out, SR, subtype="PCM_24")
    # loudness at 60 fps, normalised: what the waves on screen follow
    hop = SR // 60
    e = rms(out, hop)[::hop]
    e = np.clip(e / (np.percentile(e, 98) or 1), 0, 1)
    (ROOT / film / "audio" / "vo_env.json").write_text(json.dumps({"fps": 60, "env": [round(float(v), 3) for v in e]}))
    cfg["duration"] = duration
    cfg["poster"] = round(next((c["at"] + 1.2 for c in cfg["cues"] if c.get("type") == "dialect"), 1.0), 2)
    path.write_text(json.dumps(cfg, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    for c in cfg["cues"]:
        print(f"{c['at']:6.2f}  {c.get('type'):8s} {c.get('clip', ''):16s} {c.get('len', c.get('hold', ''))}")
    print(f"duration {duration} s")


if __name__ == "__main__":
    main(sys.argv[1])
