"""Music and sound design for the prompts films, made from oscillators and
noise (the launch ad's synth), so the films carry no third-party music.

A calm, glassy bed in D major under the voices; bells and a swell for the
logo and the title cards; a soft groove for the tagline; the last chord
rings out under the end logo. Small story sounds set each scene: a radio
sting before the host, a phone ringing before the agent answers.
"""

import sys
import pathlib

import numpy as np

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "launch-ad/render"))
import sound  # noqa: E402
from sound import SR, add, butter, stereo, midi, env_adsr, pluck, kick, hat, clap  # noqa: E402

BPM = 84
BEAT = 60 / BPM
BAR = 4 * BEAT
# Dmaj9 - Bm9 - Gmaj9 - A6/9, one bar each, voiced close and mid-low.
CHORDS = [
    (38, [50, 57, 64, 66, 69]),
    (35, [47, 54, 61, 62, 66]),
    (31, [43, 50, 57, 59, 66]),
    (33, [45, 52, 59, 61, 66]),
]
RNG = np.random.default_rng(11)


def soft_pad(notes, seconds, brightness):
    """sound.pad_chord, but rounder: fewer high harmonics and a slow bloom."""
    x = sound.pad_chord(notes, seconds, brightness=brightness)
    x *= env_adsr(len(x), min(1.2, seconds * 0.4), 0.3, 0.9, min(1.4, seconds * 0.4))[:, None]
    return x


def bell(note, seconds=2.4, gain=1.0):
    """A glassy FM bell."""
    n = int(seconds * SR)
    t = np.arange(n) / SR
    f = midi(note)
    mod = np.sin(2 * np.pi * f * 3.5 * t) * 1.6 * np.exp(-t * 3.0)
    x = np.sin(2 * np.pi * f * t + mod) * np.exp(-t * 1.6) + 0.25 * np.sin(2 * np.pi * f * 2 * t) * np.exp(-t * 3)
    x *= np.minimum(1, t / 0.004)
    return x * 0.35 * gain


def sub(note, seconds):
    n = int(seconds * SR)
    t = np.arange(n) / SR
    x = np.sin(2 * np.pi * midi(note) * t)
    return stereo(x * env_adsr(n, 0.25, 0.3, 0.8, 0.6) * 0.5)


def sparkle(seconds, density=60, gain=1.0, seed=0):
    """Dots gathering: many tiny glassy ticks, thick at first, thinning out."""
    rng = np.random.default_rng(seed)
    out = np.zeros((int((seconds + 0.6) * SR), 2))
    count = int(density * seconds)
    for k in range(count):
        at = seconds * (rng.random() ** 1.6)
        n = int(0.05 * SR)
        t = np.arange(n) / SR
        f = rng.uniform(2600, 7200)
        x = np.sin(2 * np.pi * f * t) * np.exp(-t * rng.uniform(90, 160)) * rng.uniform(0.3, 1.0)
        add(out, stereo(x * 0.12 * gain, pan=rng.uniform(-0.8, 0.8)), at)
    return sound.reverb(out, wet=0.35, seconds=1.6)


def swell(seconds=1.2, gain=1.0):
    """Air and a rising chord: something forming."""
    n = int(seconds * SR)
    t = np.linspace(0, 1, n)
    noise = butter(RNG.standard_normal(n), "bandpass", [500, 6000], order=2)
    x = noise * (t ** 2.5) * 0.25
    for note in (62, 69, 74):
        x += np.sin(2 * np.pi * midi(note) * np.arange(n) / SR) * (t ** 2) * 0.08
    return sound.reverb(stereo(x * gain), wet=0.4, seconds=1.8)


def radio_sting(gain=1.0):
    """A bright on-air jingle: three rising bells over a soft chord."""
    out = np.zeros((int(3.0 * SR), 2))
    for i, note in enumerate((74, 78, 81)):
        add(out, stereo(bell(note, 1.8), pan=(-0.3, 0.0, 0.3)[i]), i * 0.11, 0.9)
    add(out, soft_pad([62, 66, 69, 74], 1.6, 2600), 0.0, 0.5)
    return sound.reverb(out * gain, wet=0.3, seconds=1.4)


def phone_ring(gain=1.0):
    """Two short rings, heard as from a handset."""
    seconds = 1.25
    n = int(seconds * SR)
    t = np.arange(n) / SR
    tone = np.sin(2 * np.pi * 440 * t) + np.sin(2 * np.pi * 480 * t)
    trill = 0.65 + 0.35 * np.sign(np.sin(2 * np.pi * 20 * t))
    gate = ((t < 0.42) | ((t > 0.62) & (t < 1.04))).astype(float)
    gate = butter(gate, "lowpass", 60)
    x = butter(tone * trill * gate, "bandpass", [350, 3200], order=2) * 0.16 * gain
    return sound.reverb(stereo(x), wet=0.12, seconds=0.8)


def music(duration, sections):
    """sections: (start, end, name), name one of 'intro', 'scene', 'title', 'groove', 'end'."""
    n = int((duration + 4) * SR)
    pad_bus, bell_bus, low_bus, drum_bus = (np.zeros((n, 2)) for _ in range(4))
    kicks = []

    def section_at(time):
        for s, e, name in sections:
            if s <= time < e:
                return name
        return None

    bars = int(np.ceil(duration / BAR)) + 1
    for b in range(bars):
        t0 = b * BAR
        name = section_at(t0 + 0.01) or section_at(min(duration - 0.01, t0 + BAR - 0.01))
        if name is None:
            continue
        root, notes = CHORDS[b % 4]
        last = section_at(t0 + BAR + 0.01) is None
        ring = 5.5 if (name == "end" or last) else BAR + 0.9
        bright = {"intro": 1100, "scene": 800, "title": 1500, "groove": 2000, "end": 1600}[name]
        add(pad_bus, soft_pad(notes, ring, bright), t0, {"scene": 0.5, "end": 0.7}.get(name, 0.62))
        add(low_bus, sub(root + 12, ring), t0, 0.55 if name != "scene" else 0.4)
        for step in range(8):
            ts = t0 + step * BEAT / 2
            here = section_at(ts + 0.001)
            if here is None or ts > duration:
                continue
            # Bells: a slow, sparse figure; busier in the intro and the title cards.
            pattern = {"intro": (0, 3, 5), "title": (0, 2, 3, 5, 6), "scene": (0,), "groove": (0, 3, 5, 6), "end": (0,)}[here]
            if step in pattern:
                note = notes[(step * 3 + b) % len(notes)] + 12
                add(bell_bus, stereo(bell(note, 2.2), pan=(-0.4 if step % 2 else 0.4)), ts, 0.5 if here != "scene" else 0.3)
            if here == "groove":
                if step % 2 == 0:
                    add(drum_bus, kick(0.8), ts, 0.5)
                    kicks.append(ts)
                add(drum_bus, hat(gain=0.6 if step % 2 else 0.9), ts + (BEAT / 4 if step % 2 == 0 else 0), 0.16)
                if step in (2, 6):
                    add(drum_bus, clap(0.8), ts, 0.16)
                add(low_bus, sound.bass(root, BEAT / 2 * 0.85), ts, 0.28)

    # A long echo on the bells, ping-pong.
    delay = int(BEAT * 0.75 * SR)
    wet = np.zeros_like(bell_bus)
    for k in range(1, 5):
        shifted = np.zeros_like(bell_bus)
        shifted[delay * k:] = bell_bus[: n - delay * k]
        wet[:, k % 2] += shifted[:, k % 2] * (0.45 ** k)
    bell_bus = sound.reverb(bell_bus + butter(wet, "lowpass", 5000) * 0.8, wet=0.35, seconds=2.6)
    pad_bus = sound.reverb(pad_bus, wet=0.3, seconds=3.0)

    pump = np.ones(n)
    for kt in kicks:
        i = int(kt * SR)
        m = min(n - i, int(0.3 * SR))
        pump[i:i + m] = np.minimum(pump[i:i + m], 1 - 0.45 * np.exp(-np.arange(m) / SR * 10))
    out = pad_bus * pump[:, None] * 0.9 + bell_bus + low_bus * pump[:, None] + drum_bus
    return butter(out, "highpass", 35)
