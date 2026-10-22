"""Music and sound design for the prompts films, made from oscillators and
noise, so the films carry no third-party music. Each film has its own score,
unlike the earlier ads and unlike each other:

- Arabic: maqam Hijaz on D. An oud (plucked-string synthesis), a ney over a
  low drone, and a darbuka playing maqsum for the closing lines.
- English: a felt piano in A-flat, a soft low pulse, and a light kit
  (kick, rim, shaker) for the closing lines.

Both take the same sections: (start, end, name) with name one of 'intro',
'scene', 'title', 'groove' or 'end'.
"""

import sys
import pathlib

import numpy as np
from scipy import signal

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "launch-ad/render"))
import sound  # noqa: E402
from sound import SR, add, butter, stereo, midi, env_adsr  # noqa: E402

RNG = np.random.default_rng(11)


def _section_at(sections, time):
    for s, e, name in sections:
        if s <= time < e:
            return name
    return None


# ── Arabic: oud, ney, drone, darbuka ──────────────────────────────────────

def oud(note, seconds=1.6, gain=1.0, bright=1.0):
    """A plucked string (Karplus-Strong at 4x the rate, so it stays in tune) through a small wooden body."""
    up = 4
    rate = SR * up
    n = int(seconds * rate)
    period = rate / midi(note)
    N = int(period)
    # The string's shape when plucked a fifth of the way along (soft low harmonics), with a little grit.
    pos = np.arange(N) / N
    shape = np.where(pos < 0.2, pos / 0.2, (1 - pos) / 0.8)
    grit = butter(RNG.standard_normal(N), "lowpass", 1800 * bright) * 0.12
    x = np.zeros(n)
    x[:N] = shape - shape.mean() + grit
    g = 0.9965 if note < 60 else 0.9955
    a = np.zeros(N + 2)
    a[0], a[N], a[N + 1] = 1.0, -g / 2, -g / 2
    y = signal.lfilter([1.0], a, x)
    y = signal.resample_poly(y, 1, up)
    # The bowl: two soft resonances and less fizz.
    y = y + 0.5 * butter(y, "bandpass", [90, 260], order=1) + 0.3 * butter(y, "bandpass", [350, 700], order=1)
    y = butter(y, "lowpass", 3000 * bright)
    t = np.arange(len(y)) / SR
    y *= np.exp(-t * 1.2) * np.minimum(1, t / 0.002)
    return y / (np.abs(y).max() + 1e-9) * 0.5 * gain


def ney(note, seconds, gain=1.0):
    """A breathy reed flute with a slow vibrato that opens after the attack."""
    n = int(seconds * SR)
    t = np.arange(n) / SR
    vib = 1 + 0.006 * np.sin(2 * np.pi * 5.2 * t) * np.clip((t - 0.35) / 0.5, 0, 1)
    phase = 2 * np.pi * np.cumsum(midi(note) * vib) / SR
    tone = np.sin(phase) + 0.18 * np.sin(2 * phase) + 0.06 * np.sin(3 * phase)
    breath = butter(RNG.standard_normal(n), "bandpass", [midi(note) * 1.5, midi(note) * 6], order=2) * 0.22
    env = env_adsr(n, 0.25, 0.3, 0.8, min(0.8, seconds * 0.35))
    return (tone + breath) * env * 0.3 * gain


def drone(notes, seconds, gain=1.0):
    """A low, slowly breathing bed of sines (no saw pads: those are the launch ad's)."""
    n = int(seconds * SR)
    t = np.arange(n) / SR
    x = np.zeros((n, 2))
    for i, note in enumerate(notes):
        for k, cents in enumerate((-4, 4)):
            f = midi(note) * 2 ** (cents / 1200)
            mono = np.sin(2 * np.pi * f * t + i) + 0.3 * np.sin(4 * np.pi * f * t) + 0.12 * np.sin(6 * np.pi * f * t)
            mono *= 1 + 0.12 * np.sin(2 * np.pi * (0.13 + 0.05 * i) * t + k)
            x += stereo(mono, pan=(-0.5, 0.5)[k])
    x *= env_adsr(n, min(1.5, seconds * 0.3), 0.3, 0.9, min(1.8, seconds * 0.35))[:, None]
    return butter(x, "lowpass", 900) * 0.12 * gain


def doum(gain=1.0):
    n = int(0.45 * SR)
    t = np.arange(n) / SR
    f = 88 + 70 * np.exp(-t * 35)
    body = np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t * 7)
    slap = butter(RNG.standard_normal(n), "bandpass", [300, 1500]) * np.exp(-t * 90) * 0.25
    return stereo(np.tanh((body + slap) * 1.3) * 0.7 * gain)


def tek(gain=1.0, pitch=1.0):
    n = int(0.12 * SR)
    t = np.arange(n) / SR
    crack = butter(RNG.standard_normal(n), "bandpass", [1800, 8000], order=2) * np.exp(-t * 85)
    ring = np.sin(2 * np.pi * 1150 * pitch * t) * np.exp(-t * 45) * 0.35
    return stereo((crack + ring) * 0.45 * gain, pan=RNG.uniform(-0.15, 0.15))


def jingle(gain=1.0):
    """Frame-drum zils: a short bright shimmer."""
    n = int(0.18 * SR)
    t = np.arange(n) / SR
    x = butter(RNG.standard_normal(n), "bandpass", [5000, 12000], order=2) * np.exp(-t * 30) * (1 + 0.4 * np.sin(2 * np.pi * 38 * t))
    return stereo(x * 0.22 * gain, pan=RNG.uniform(-0.4, 0.4))


HIJAZ = [62, 63, 66, 67, 69, 70, 72, 74]  # D Eb F# G A Bb C D
TAQSIM = [(69, 0.0), (70, 0.45), (69, 0.7), (67, 0.95), (66, 1.3), (63, 1.75), (62, 2.1)]
RIFF = [(62, 0), (62, 1), (63, 2), (66, 3), (67, 4), (66, 5), (63, 6), (62, 7)]
RIFF2 = [(69, 0), (70, 1), (69, 2), (67, 3), (66, 4), (67, 5), (66, 6), (63, 7)]


def arabic(duration, sections):
    bpm = 92
    beat = 60 / bpm
    bar = 4 * beat
    n = int((duration + 5) * SR)
    pluck_bus, wind_bus, bed_bus, drum_bus = (np.zeros((n, 2)) for _ in range(4))
    roots = [38, 38, 36, 38]  # D D C D, under the drone
    bars = int(np.ceil(duration / bar)) + 1
    for b in range(bars):
        t0 = b * bar
        name = _section_at(sections, t0 + 0.01) or _section_at(sections, min(duration - 0.01, t0 + bar - 0.01))
        if name is None:
            continue
        root = roots[b % 4]
        last = _section_at(sections, t0 + bar + 0.01) is None
        ring = 6.0 if (name == "end" or last) else bar + 1.2
        add(bed_bus, drone([root, root + 7, root + 12], ring), t0, {"scene": 0.7, "intro": 1.0}.get(name, 0.9))
        if name in ("intro", "title") and b % 2 == 0:
            for note, at in TAQSIM:
                if t0 + at < duration:
                    add(pluck_bus, stereo(oud(note, 1.8), pan=-0.2), t0 + at, 0.55)
        if name == "intro" and b % 2 == 1:
            add(wind_bus, stereo(ney(69, bar * 0.95), pan=0.25), t0 + 0.1, 0.5)
        if name == "scene" and b % 2 == 0:
            add(pluck_bus, stereo(oud(HIJAZ[(b * 3) % 5] , 2.0, bright=0.8), pan=-0.3), t0, 0.25)
        if name == "groove":
            riff = RIFF if b % 2 == 0 else RIFF2
            for note, step in riff:
                add(pluck_bus, stereo(oud(note, 1.0), pan=-0.25), t0 + step * beat / 2, 0.42)
            add(bed_bus, stereo(oud(root + 12, 1.6, bright=0.6)), t0, 0.5)
            # Maqsum: doum tek - tek doum - tek -
            for step, hit in ((0, "D"), (1, "T"), (3, "T"), (4, "D"), (6, "T"), (2, "k"), (5, "k"), (7, "k")):
                ts = t0 + step * beat / 2
                if hit == "D":
                    add(drum_bus, doum(), ts, 0.6)
                elif hit == "T":
                    add(drum_bus, tek(), ts, 0.45)
                else:
                    add(drum_bus, tek(0.5, pitch=0.85), ts, 0.3)
                if step % 2 == 1:
                    add(drum_bus, jingle(), ts, 0.4)
        if name == "end" and _section_at(sections, t0 - 0.01) != "end":
            for k, note in enumerate((50, 57, 62, 66, 69)):
                add(pluck_bus, stereo(oud(note, 3.5, bright=0.9), pan=-0.3 + 0.15 * k), t0 + 0.06 * k, 0.4)
            add(wind_bus, stereo(ney(74, 3.8)), t0 + 0.4, 0.45)

    pluck_bus = sound.reverb(pluck_bus, wet=0.28, seconds=2.2)
    wind_bus = sound.reverb(wind_bus, wet=0.45, seconds=3.0)
    drum_bus = sound.reverb(drum_bus, wet=0.12, seconds=1.0)
    out = pluck_bus + wind_bus + bed_bus + drum_bus
    return butter(out, "highpass", 35)


# ── English: felt piano, a low pulse and a light kit ──────────────────────

def piano(note, seconds=3.0, gain=1.0, soft=1.0):
    """A felt piano: slightly stretched partials, two strings a hair apart, a soft hammer."""
    n = int(seconds * SR)
    t = np.arange(n) / SR
    f0 = midi(note)
    x = np.zeros(n)
    B = 0.00035
    for k in range(1, 14):
        fk = k * f0 * np.sqrt(1 + B * k * k)
        if fk > 9000:
            break
        amp = 1 / k ** 1.5 * (0.6 if k > 4 else 1.0) * soft ** (k * 0.3)
        decay = 0.55 + 0.32 * k + f0 / 900
        for cents in (-0.8, 0.8):
            x += amp * np.sin(2 * np.pi * fk * 2 ** (cents / 1200) * t) * np.exp(-t * decay)
    hammer = butter(RNG.standard_normal(n), "lowpass", 1500) * np.exp(-t * 120) * 0.08
    x = (x + hammer) * np.minimum(1, t / 0.004)
    return butter(x, "lowpass", 3800 * soft + 1200) * 0.18 * gain


def pulse(note, seconds, gain=1.0):
    n = int(seconds * SR)
    t = np.arange(n) / SR
    f = midi(note)
    x = np.sin(2 * np.pi * f * t) + 0.35 * np.sin(4 * np.pi * f * t) + 0.1 * np.sin(6 * np.pi * f * t)
    return stereo(x * env_adsr(n, 0.01, 0.12, 0.55, 0.06) * 0.3 * gain)


def rim(gain=1.0):
    n = int(0.08 * SR)
    t = np.arange(n) / SR
    x = np.sin(2 * np.pi * 1750 * t) * np.exp(-t * 70) * 0.5 + butter(RNG.standard_normal(n), "highpass", 3000) * np.exp(-t * 160) * 0.3
    return stereo(x * 0.35 * gain, pan=0.2)


def shaker(gain=1.0):
    n = int(0.07 * SR)
    t = np.arange(n) / SR
    env = np.minimum(1, t / 0.012) * np.exp(-t * 55)
    return stereo(butter(RNG.standard_normal(n), "bandpass", [6000, 11000], order=2) * env * 0.16 * gain, pan=RNG.uniform(-0.3, 0.3))


# Fm9 - Dbmaj9 - Ab(add9) - Ebsus4, one bar each.
EN_CHORDS = [
    (41, [53, 60, 63, 67, 68]),
    (37, [49, 56, 60, 63, 65]),
    (44, [56, 60, 63, 70]),
    (39, [51, 58, 63, 68, 70]),
]


def english(duration, sections):
    bpm = 96
    beat = 60 / bpm
    bar = 4 * beat
    n = int((duration + 5) * SR)
    keys_bus, low_bus, drum_bus = (np.zeros((n, 2)) for _ in range(3))
    kicks = []
    bars = int(np.ceil(duration / bar)) + 1
    for b in range(bars):
        t0 = b * bar
        name = _section_at(sections, t0 + 0.01) or _section_at(sections, min(duration - 0.01, t0 + bar - 0.01))
        if name is None:
            continue
        root, notes = EN_CHORDS[b % 4]
        last = _section_at(sections, t0 + bar + 0.01) is None
        hold = 5.0 if (name == "end" or last) else bar + 1.5
        # A rolled chord at the top of each bar.
        level = {"scene": 0.45, "intro": 0.8, "title": 0.8, "groove": 0.7, "end": 0.9}[name]
        for k, note in enumerate(notes):
            add(keys_bus, stereo(piano(note, hold, soft=0.75), pan=-0.35 + 0.17 * k), t0 + 0.035 * k, level)
        add(low_bus, stereo(piano(root, hold, soft=0.5)), t0, level * 0.8)
        if name in ("intro", "title", "groove"):
            # A slow broken figure over it.
            for step in range(8):
                if step in (0, 4) and name != "groove":
                    continue
                note = notes[(step * 2 + b) % len(notes)] + 12
                add(keys_bus, stereo(piano(note, 1.6, soft=0.6), pan=(0.3 if step % 2 else -0.1)), t0 + step * beat / 2, 0.3)
        if name == "scene" and b % 2 == 1:
            add(keys_bus, stereo(piano(notes[-1] + 12, 2.2, soft=0.55), pan=0.3), t0 + 2 * beat, 0.25)
        if name == "groove":
            for step in range(8):
                ts = t0 + step * beat / 2
                add(low_bus, pulse(root + 12, beat / 2 * 0.8), ts, 0.5)
                if step % 2 == 0:
                    add(drum_bus, sound.kick(0.7), ts, 0.45)
                    kicks.append(ts)
                if step in (2, 6):
                    add(drum_bus, rim(), ts, 0.5)
                add(drum_bus, shaker(1.0 if step % 2 else 0.6), ts, 0.5)
                add(drum_bus, shaker(0.5), ts + beat / 4, 0.4)

    keys_bus = sound.reverb(keys_bus, wet=0.32, seconds=2.8)
    pump = np.ones(n)
    for kt in kicks:
        i = int(kt * SR)
        m = min(n - i, int(0.3 * SR))
        pump[i:i + m] = np.minimum(pump[i:i + m], 1 - 0.4 * np.exp(-np.arange(m) / SR * 10))
    out = keys_bus * pump[:, None] + low_bus * pump[:, None] + sound.reverb(drum_bus, wet=0.1, seconds=0.9)
    return butter(out, "highpass", 35)


def music(film, duration, sections):
    return arabic(duration, sections) if film == "ar" else english(duration, sections)


# ── Sound design ──────────────────────────────────────────────────────────

def signature(film):
    """The logo's first appearance: an oud's open fifth, or a piano's."""
    out = np.zeros((int(4 * SR), 2))
    if film == "ar":
        for k, note in enumerate((50, 57, 62)):
            add(out, stereo(oud(note, 3.5, bright=0.9), pan=-0.2 + 0.2 * k), 0.03 * k, 0.6)
        add(out, doum(0.7), 0.0, 0.5)
    else:
        for k, note in enumerate((56, 63, 72)):
            add(out, stereo(piano(note, 3.5, soft=0.8), pan=-0.2 + 0.2 * k), 0.03 * k, 0.8)
    return sound.reverb(out, wet=0.35, seconds=2.4)


def sparkle(seconds, density=60, gain=1.0, seed=0):
    """Dots gathering: many tiny glassy ticks, thick at first, thinning out."""
    rng = np.random.default_rng(seed)
    out = np.zeros((int((seconds + 0.6) * SR), 2))
    for _ in range(int(density * seconds)):
        at = seconds * (rng.random() ** 1.6)
        n = int(0.05 * SR)
        t = np.arange(n) / SR
        x = np.sin(2 * np.pi * rng.uniform(2600, 7200) * t) * np.exp(-t * rng.uniform(90, 160)) * rng.uniform(0.3, 1.0)
        add(out, stereo(x * 0.12 * gain, pan=rng.uniform(-0.8, 0.8)), at)
    return sound.reverb(out, wet=0.35, seconds=1.6)


def swell(seconds=1.2, gain=1.0):
    """Air rising: something forming."""
    n = int(seconds * SR)
    t = np.linspace(0, 1, n)
    x = butter(RNG.standard_normal(n), "bandpass", [500, 6000], order=2) * (t ** 2.5) * 0.25
    return sound.reverb(stereo(x * gain), wet=0.4, seconds=1.8)


def radio_sting(film, gain=1.0):
    """An on-air jingle in the film's own instruments."""
    out = np.zeros((int(3.0 * SR), 2))
    if film == "ar":
        for k, (note, at) in enumerate(((62, 0.0), (66, 0.1), (69, 0.2), (74, 0.3))):
            add(out, stereo(oud(note, 1.6), pan=-0.3 + 0.2 * k), at, 0.6)
        for at in (0.0, 0.1, 0.2, 0.3):
            add(out, tek(0.8), at, 0.4)
        add(out, doum(), 0.3, 0.5)
    else:
        for k, (note, at) in enumerate(((68, 0.0), (72, 0.1), (75, 0.2), (80, 0.3))):
            add(out, stereo(piano(note, 1.8, soft=0.9), pan=-0.3 + 0.2 * k), at, 0.8)
    return sound.reverb(out * gain, wet=0.3, seconds=1.4)


def phone_ring(gain=1.0):
    """Two short rings, heard as from a handset."""
    seconds = 1.25
    n = int(seconds * SR)
    t = np.arange(n) / SR
    tone = np.sin(2 * np.pi * 440 * t) + np.sin(2 * np.pi * 480 * t)
    trill = 0.65 + 0.35 * np.sign(np.sin(2 * np.pi * 20 * t))
    gate = butter(((t < 0.42) | ((t > 0.62) & (t < 1.04))).astype(float), "lowpass", 60)
    x = butter(tone * trill * gate, "bandpass", [350, 3200], order=2) * 0.16 * gain
    return sound.reverb(stereo(x), wet=0.12, seconds=0.8)
