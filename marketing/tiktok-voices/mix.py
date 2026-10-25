# /// script
# dependencies = ["numpy", "soundfile"]
# ///
"""Mix the ad's soundtrack: the voices, the music under them, a few soft accents.

    uv run mix.py

Reads public/audio/vo.wav and src/timeline.json (build_vo.py), sound/audio/music.wav (the bed:
beats.py --synth) and src/studio-plan.json (the studio's moments). The music is ducked whenever
someone speaks and rises a little under the studio. The accents are made here: a soft air sweep
as the studio comes in, a light tick for each click in it, a tap on Generate, a shimmer when the
voice is ready, a bell under the end card. Writes public/audio/mix.wav (48 kHz stereo), mastered
to -14 LUFS integrated, -1 dBTP (ffmpeg loudnorm, two passes).
"""

import json
import pathlib
import re
import subprocess

import numpy as np
import soundfile as sf

ROOT = pathlib.Path(__file__).parent
SR = 48000
rng = np.random.default_rng(7)


def env_follow(x, attack=0.06, release=0.45):
    """A smoothed 0..1 'someone is speaking' curve from the voice track."""
    hop = SR // 100
    e = np.sqrt(np.convolve(x ** 2, np.ones(hop) / hop, mode="same"))
    on = (e > 10 ** (-40 / 20) * e.max()).astype("float32")
    out = np.zeros_like(on)
    a, r, v = 1 - np.exp(-1 / (attack * SR)), 1 - np.exp(-1 / (release * SR)), 0.0
    for i, target in enumerate(on):
        v += (target - v) * (a if target > v else r)
        out[i] = v
    return out


def place(bus, t, sound, gain=1.0):
    i = int(t * SR)
    if i >= len(bus):
        return
    n = min(len(sound), len(bus) - i)
    bus[i:i + n] += gain * sound[:n]


def sweep(dur=0.9):
    """Soft air: filtered noise rising then falling."""
    n = int(dur * SR)
    noise = rng.standard_normal(n).astype("float32")
    k = np.linspace(0, 1, n)
    out, y = np.zeros(n, "float32"), 0.0
    for i in range(n):  # one-pole low-pass whose corner opens and closes
        c = 0.02 + 0.25 * np.sin(np.pi * k[i]) ** 2
        y += c * (noise[i] - y)
        out[i] = y
    return out * np.sin(np.pi * k) ** 1.5 * 0.5


def tick(freq=2400, dur=0.05):
    n = int(dur * SR)
    t = np.arange(n) / SR
    return (np.sin(2 * np.pi * freq * t) * np.exp(-t * 90)).astype("float32") * 0.35


def tap():
    n = int(0.12 * SR)
    t = np.arange(n) / SR
    body = np.sin(2 * np.pi * 180 * t) * np.exp(-t * 45) * 0.5
    click = np.sin(2 * np.pi * 3200 * t) * np.exp(-t * 160) * 0.25
    return (body + click).astype("float32")


def bell(freq, dur=2.2, gain=0.3):
    t = np.arange(int(dur * SR)) / SR
    tone = sum(a * np.sin(2 * np.pi * freq * m * t) * np.exp(-t * d) for m, a, d in [(1, 1, 2.2), (2.01, 0.35, 3.5), (3.02, 0.15, 5)])
    return (tone * gain * np.minimum(1, t / 0.004)).astype("float32")


def shimmer():
    out = np.zeros(int(1.4 * SR), "float32")
    for i, f in enumerate([1318.5, 1567.98, 1975.5, 2637.0]):  # E6 G6 B6 E7: rising, in the bed's key
        place(out, i * 0.07, bell(f, 1.2, 0.08))
    return out


def lufs(path):
    r = subprocess.run(["ffmpeg", "-hide_banner", "-i", str(path), "-af", "loudnorm=I=-14:TP=-1:LRA=11:print_format=json", "-f", "null", "-"],
                       capture_output=True, text=True)
    return json.loads(re.search(r"\{[^{}]*\}", r.stderr[r.stderr.rfind("[Parsed_loudnorm"):], re.S).group(0))


def main():
    timeline = json.loads((ROOT / "src/timeline.json").read_text(encoding="utf-8"))
    plan = json.loads((ROOT / "src/studio-plan.json").read_text(encoding="utf-8"))
    vo, sr = sf.read(ROOT / "public/audio/vo.wav", dtype="float32")
    assert sr == SR
    music, sr = sf.read(ROOT / "sound/audio/music.wav", dtype="float32", always_2d=True)
    assert sr == SR
    n = len(vo)
    music = np.pad(music, ((0, max(0, n - len(music))), (0, 0)))[:n]

    # the music: low under the voices, a little higher where nobody speaks, faded in and out
    speak = env_follow(vo)
    level = 10 ** ((-14 + 9 * (1 - speak)) / 20)  # -14 dB under a voice, -5 dB in the clear
    k = np.arange(n) / SR
    level *= np.minimum(1, k / 0.6) * np.clip((timeline["duration"] - k) / 1.5, 0, 1)
    bed = music * level[:, None] * 0.55

    # the accents
    fx = np.zeros(n, "float32")
    s0 = timeline["studio"]["start"]
    place(fx, s0 - 0.35, sweep(), 0.6)
    for i in [1, 2, 4, 5, 6, 7]:  # search, pick, menus and tags: a light tick each
        place(fx, s0 + plan["states"][i], tick(2600 if i % 2 else 2100), 0.5)
    place(fx, s0 + plan["tap"], tap(), 0.7)
    place(fx, s0 + plan["states"][-1], shimmer(), 0.9)
    place(fx, timeline["end"]["start"], bell(659.25), 0.55)  # E5 under the end card
    place(fx, timeline["end"]["start"] + 0.12, bell(987.77), 0.3)  # and its fifth

    mix = bed + (vo[:, None] + fx[:, None] * 0.8) * np.array([1.0, 1.0])
    mix /= max(1.0, np.abs(mix).max() / 0.95)
    raw = ROOT / "sound/audio/mix_raw.wav"
    sf.write(raw, mix, SR, subtype="FLOAT")

    m = lufs(raw)
    out = ROOT / "public/audio/mix.wav"
    subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", str(raw), "-af",
                    f"loudnorm=I=-14:TP=-1:LRA=11:measured_I={m['input_i']}:measured_TP={m['input_tp']}:measured_LRA={m['input_lra']}"
                    f":measured_thresh={m['input_thresh']}:offset={m['target_offset']}:linear=true",
                    "-ar", str(SR), "-c:a", "pcm_s24le", str(out)], check=True)
    check = lufs(out)
    print(f"mix: {check['input_i']} LUFS, {check['input_tp']} dBTP, {n / SR:.2f} s -> {out.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
