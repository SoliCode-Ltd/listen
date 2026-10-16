"""Synthesised, royalty-free music and sound design for the launch ad.

Everything is made from oscillators and noise here, so the ad carries no
third-party music. 48 kHz stereo float arrays throughout.
"""

import numpy as np
from scipy import signal

SR = 48000
RNG = np.random.default_rng(7)


def silence(seconds):
    return np.zeros((int(round(seconds * SR)), 2))


def add(bus, clip, at, gain=1.0):
    """Mix clip (n, 2) into bus at time `at` seconds."""
    start = int(round(at * SR))
    if start >= len(bus):
        return
    if start < 0:
        clip = clip[-start:]
        start = 0
    end = min(len(bus), start + len(clip))
    bus[start:end] += clip[: end - start] * gain


def stereo(mono, pan=0.0):
    left = np.cos((pan + 1) * np.pi / 4)
    right = np.sin((pan + 1) * np.pi / 4)
    return np.stack([mono * left, mono * right], axis=1) * np.sqrt(2)


def midi(note):
    return 440.0 * 2 ** ((note - 69) / 12)


def butter(x, kind, freq, order=2):
    sos = signal.butter(order, freq, btype=kind, fs=SR, output="sos")
    return signal.sosfilt(sos, x, axis=0)


def env_adsr(n, attack, decay, sustain, release):
    a, d, r = int(attack * SR), int(decay * SR), int(release * SR)
    s = max(0, n - a - d - r)
    return np.concatenate([
        np.linspace(0, 1, max(a, 1)),
        np.linspace(1, sustain, max(d, 1)),
        np.full(s, sustain),
        np.linspace(sustain, 0, max(r, 1)),
    ])[:n]


def saw(freq, seconds, detune_cents=0.0, max_harm_hz=9000, phase=None):
    """Band-limited sawtooth by additive synthesis."""
    n = int(seconds * SR)
    t = np.arange(n) / SR
    f = freq * 2 ** (detune_cents / 1200)
    ph = RNG.uniform(0, 2 * np.pi) if phase is None else phase
    out = np.zeros(n)
    k = 1
    while k * f < max_harm_hz:
        out += np.sin(2 * np.pi * k * f * t + k * ph) / k
        k += 1
    return out * (2 / np.pi)


def reverb_ir(seconds=2.2, tone=5000):
    n = int(seconds * SR)
    t = np.arange(n) / SR
    decay = np.exp(-t * 6.9 / seconds)
    ir = RNG.standard_normal((n, 2)) * decay[:, None]
    ir = butter(ir, "lowpass", tone)
    ir[: int(0.012 * SR)] *= np.linspace(0, 1, int(0.012 * SR))[:, None]
    return ir / np.sqrt(np.sum(ir ** 2) / 2)


IR = None


def reverb(x, wet=0.25, seconds=2.2):
    global IR
    if IR is None or len(IR) != int(seconds * SR):
        IR = reverb_ir(seconds)
    tail = np.stack([signal.fftconvolve(x[:, c], IR[:, c])[: len(x)] for c in range(2)], axis=1)
    return x + tail * wet


# ── Instruments ────────────────────────────────────────────────────────────

def pad_chord(notes, seconds, brightness=1800):
    voices = []
    for i, note in enumerate(notes):
        for j, cents in enumerate((-9, 0, 9)):
            mono = saw(midi(note), seconds, cents, max_harm_hz=6000)
            voices.append(stereo(mono, pan=(-0.6, 0.0, 0.6)[j] * (0.7 if i % 2 else 1.0)))
    x = sum(voices) / len(voices)
    x = butter(x, "lowpass", brightness, order=2)
    x *= env_adsr(len(x), 0.45, 0.4, 0.85, 0.7)[:, None]
    return x


def pluck(note, seconds=0.45, bright=1.0):
    n = int(seconds * SR)
    t = np.arange(n) / SR
    f = midi(note)
    out = np.zeros(n)
    k = 1
    while k * f < 10000:
        out += np.sin(2 * np.pi * k * f * t) / k * np.exp(-t * (6 + k * 2.6 / bright))
        k += 1
    out *= np.minimum(1, t / 0.003)
    return out * 0.6


def kick(gain=1.0):
    n = int(0.5 * SR)
    t = np.arange(n) / SR
    freq = 46 + 110 * np.exp(-t * 28)
    phase = 2 * np.pi * np.cumsum(freq) / SR
    body = np.sin(phase) * np.exp(-t * 7.5)
    click = butter(RNG.standard_normal(n) * np.exp(-t * 400), "highpass", 2500) * 0.25
    return stereo(np.tanh((body + click) * 1.6) * 0.8 * gain)


def hat(gain=1.0, open_=False):
    n = int((0.22 if open_ else 0.06) * SR)
    t = np.arange(n) / SR
    noise = butter(RNG.standard_normal(n), "highpass", 7500, order=4)
    x = noise * np.exp(-t * (14 if open_ else 70)) * 0.35 * gain
    return stereo(x, pan=RNG.uniform(-0.25, 0.25))


def clap(gain=1.0):
    n = int(0.3 * SR)
    t = np.arange(n) / SR
    noise = butter(RNG.standard_normal(n), "bandpass", [900, 5000], order=2)
    env = np.zeros(n)
    for d in (0.0, 0.011, 0.023):
        i = int(d * SR)
        env[i:] += np.exp(-(t[: n - i]) * 55)
    env += np.exp(-t * 14) * 0.35
    return reverb(stereo(noise * env * 0.35 * gain), wet=0.18, seconds=1.0)


def bass(note, seconds):
    n = int(seconds * SR)
    t = np.arange(n) / SR
    f = midi(note)
    x = np.sin(2 * np.pi * f * t) + 0.25 * np.sin(4 * np.pi * f * t)
    x = np.tanh(x * 1.4) * env_adsr(n, 0.005, 0.12, 0.7, 0.05)
    return stereo(x * 0.55)


# ── Sound design ───────────────────────────────────────────────────────────

def whoosh(seconds=0.55, rising=True, gain=1.0, pan_from=-0.7, pan_to=0.7):
    n = int(seconds * SR)
    t = np.linspace(0, 1, n)
    noise = RNG.standard_normal(n)
    centers = (300 * (25 ** t)) if rising else (6000 * (0.06 ** t))
    out = np.zeros(n)
    hop = 512
    for i in range(0, n, hop):
        c = float(np.clip(centers[min(i, n - 1)], 150, 12000))
        lo, hi = c * 0.6, min(c * 1.6, 20000)
        seg = noise[max(0, i - 2048): i + hop]
        y = butter(seg, "bandpass", [lo, hi], order=2)[-hop:] if len(seg) > hop else np.zeros(hop)
        out[i:i + hop] = y[: len(out[i:i + hop])]
    shape = np.sin(np.pi * t) ** 1.6
    out *= shape * 0.9 * gain
    pans = np.linspace(pan_from, pan_to, n)
    left = np.cos((pans + 1) * np.pi / 4)
    right = np.sin((pans + 1) * np.pi / 4)
    return reverb(np.stack([out * left, out * right], axis=1) * np.sqrt(2), wet=0.2, seconds=1.2)


def riser(seconds=2.0, gain=1.0):
    n = int(seconds * SR)
    t = np.linspace(0, 1, n)
    noise = RNG.standard_normal(n)
    out = np.zeros(n)
    hop = 512
    for i in range(0, n, hop):
        c = 200 * (40 ** t[min(i, n - 1)])
        seg = noise[max(0, i - 2048): i + hop]
        y = butter(seg, "bandpass", [c * 0.7, min(c * 1.5, 20000)], order=2)[-hop:] if len(seg) > hop else np.zeros(hop)
        out[i:i + hop] = y[: len(out[i:i + hop])]
    tone_f = 110 * (8 ** t)
    tone = np.sin(2 * np.pi * np.cumsum(tone_f) / SR) * 0.25
    x = (out * 0.8 + tone) * (t ** 2.2) * gain
    return reverb(stereo(x), wet=0.3, seconds=1.6)


def impact(gain=1.0):
    n = int(2.4 * SR)
    t = np.arange(n) / SR
    f = 34 + 40 * np.exp(-t * 9)
    boom = np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t * 2.2)
    crack = butter(RNG.standard_normal(n), "lowpass", 3500) * np.exp(-t * 18) * 0.6
    x = np.tanh((boom * 1.2 + crack) * 1.3) * 0.75 * gain
    return reverb(stereo(x), wet=0.35, seconds=2.4)


def pop(pitch=1.0, gain=1.0, pan=0.0):
    n = int(0.09 * SR)
    t = np.arange(n) / SR
    f = (700 + 900 * np.exp(-t * 60)) * pitch
    x = np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t * 55) * 0.5 * gain
    return stereo(x, pan)


def tick(gain=1.0):
    n = int(0.03 * SR)
    t = np.arange(n) / SR
    x = butter(RNG.standard_normal(n), "bandpass", [1800, 6000]) * np.exp(-t * 260) * 0.5 * gain
    return stereo(x, RNG.uniform(-0.3, 0.3))


def shimmer(gain=1.0):
    n = int(2.6 * SR)
    t = np.arange(n) / SR
    x = np.zeros(n)
    for f, a, d in ((1318.5, 0.5, 2.2), (1760, 0.35, 2.8), (2637, 0.22, 3.5), (3520, 0.15, 4.5), (2093, 0.25, 3.0)):
        x += np.sin(2 * np.pi * f * t) * a * np.exp(-t * d)
    x *= np.minimum(1, t / 0.004)
    return reverb(stereo(x * 0.28 * gain), wet=0.55, seconds=2.6)


def tape_stop(seconds=0.5, gain=1.0):
    n = int(seconds * SR)
    t = np.linspace(0, 1, n)
    f = 220 * (1 - t) ** 2 + 30
    x = np.sin(2 * np.pi * np.cumsum(f) / SR) * (1 - t) * 0.3 * gain
    return stereo(x)


# ── Music ──────────────────────────────────────────────────────────────────

BPM = 120
BEAT = 60 / BPM
BAR = 4 * BEAT
# A minor: Am - F - C - G, one bar each.
CHORDS = [
    (57, [57, 60, 64, 69]),  # Am
    (53, [53, 57, 60, 65]),  # F
    (48, [55, 60, 64, 67]),  # C
    (55, [55, 59, 62, 67]),  # G
]
ARP = [0, 2, 1, 3, 2, 1, 3, 2]


def music(duration, sections):
    """sections: list of (start, end, name) covering the ad.

    Names: 'intro' (pad and pluck, no drums), 'groove', 'lift' (groove with
    claps and open hats), 'hold' (low drone only), 'outro' (groove, then the
    last chord rings out).
    """
    n = int(duration * SR) + SR * 3
    pad_bus = np.zeros((n, 2))
    pluck_bus = np.zeros((n, 2))
    bass_bus = np.zeros((n, 2))
    drum_bus = np.zeros((n, 2))
    kick_times = []

    def section_at(time):
        for s, e, name in sections:
            if s <= time < e:
                return name
        return None

    bars = int(np.ceil(duration / BAR)) + 1
    for b in range(bars):
        t0 = b * BAR
        name = section_at(t0 + 0.01)
        if name is None and t0 > duration:
            break
        root, notes = CHORDS[b % 4]
        if name in ("intro", "groove", "lift", "outro", "hold"):
            bright = {"intro": 900, "hold": 500, "groove": 1500, "lift": 2400, "outro": 2000}[name]
            ring = 3.2 if (name == "outro" and section_at(t0 + BAR + 0.01) is None) else BAR + 0.6
            chord = pad_chord(notes if name != "hold" else [45, 52], ring, brightness=bright)
            add(pad_bus, chord, t0, 0.55 if name != "hold" else 0.7)
        for step in range(16):
            ts = t0 + step * BEAT / 4
            here = section_at(ts + 0.001)
            if here is None or ts > duration:
                continue
            if here in ("groove", "lift", "outro", "intro") and step % 2 == 0:
                note = notes[ARP[(step // 2) % 8] % len(notes)] + 12
                p = pluck(note, 0.5, bright=1.4 if here == "lift" else 1.0)
                gain = 0.16 if here == "intro" else 0.22
                add(pluck_bus, stereo(p, pan=(-0.35 if step % 4 == 0 else 0.35)), ts, gain)
            if here in ("groove", "lift", "outro"):
                if step % 4 == 0:
                    add(drum_bus, kick(), ts, 0.9)
                    kick_times.append(ts)
                if step % 4 == 2:
                    add(drum_bus, hat(open_=(here == "lift" and step % 8 == 6)), ts, 0.55)
                if step % 2 == 1:
                    add(drum_bus, hat(gain=0.45), ts, 0.4)
                if here == "lift" and step in (4, 12):
                    add(drum_bus, clap(), ts, 0.5)
                if step % 2 == 0:
                    add(bass_bus, bass(root - 12, BEAT / 2 * 0.9), ts, 0.55)

    # Ping-pong delay on the plucks (dotted eighth).
    delay = int(BEAT * 0.75 * SR)
    wet = np.zeros_like(pluck_bus)
    fb = pluck_bus.copy()
    for k in range(1, 5):
        shifted = np.zeros_like(pluck_bus)
        shifted[delay * k:] = fb[: n - delay * k]
        side = 0 if k % 2 else 1
        wet[:, side] += shifted[:, side] * (0.42 ** k)
    pluck_bus = pluck_bus + butter(wet, "lowpass", 4000) * 0.8

    # Sidechain pump from the kicks on pad and bass.
    pump = np.ones(n)
    for kt in kick_times:
        i = int(kt * SR)
        m = min(n - i, int(0.35 * SR))
        curve = 1 - 0.55 * np.exp(-np.arange(m) / SR * 9)
        pump[i:i + m] = np.minimum(pump[i:i + m], curve)
    pad_bus *= pump[:, None]
    bass_bus *= pump[:, None]

    mix = reverb(pad_bus, wet=0.35) + reverb(pluck_bus, wet=0.25) + bass_bus + drum_bus
    mix = butter(mix, "highpass", 28)
    return mix[: int(duration * SR) + SR * 2]
