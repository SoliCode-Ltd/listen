"""Music and sound design for the prompts films, made from oscillators and
noise, so the films carry no third-party music.

Three styles, written to what works for product ads with people talking:
rhythm rather than melody under the voices, weight in the low end so the
speech stays clear, an instant hook, a lift at the titles and the logo, and a
clean end. Each film picks one; none reuses the earlier ads' music.

- pulse: bright tech electronic in F major at 118 BPM. Square-wave plucks
  in sixteenths, glassy FM stabs, a sidechained sub, four on the floor for
  the closing lines.
- cinematic: inspiring and wide in D minor at 92 BPM. A low ostinato,
  ticking percussion, deep booms, a rising brass swell on the logo, a
  half-time beat with a big snare for the closing lines.
- chill: soft electronic in A-flat major at 128 BPM (felt in half time).
  FM electric piano, shuffled hats, a warm sub, a two-step beat for the
  closing lines.

Every style takes the same sections: (start, end, name), with name one of
'intro', 'scene', 'title', 'groove' or 'end'.
"""

import sys
import pathlib

import numpy as np

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[2] / "launch-ad/render"))
import sound  # noqa: E402
from sound import SR, add, butter, stereo, midi, env_adsr  # noqa: E402

RNG = np.random.default_rng(11)
STYLES = ("pulse", "cinematic", "chill")


def _section_at(sections, time):
    for s, e, name in sections:
        if s <= time < e:
            return name
    return None


def _t(seconds):
    return np.arange(int(seconds * SR)) / SR


# ── Instruments ───────────────────────────────────────────────────────────

def square_pluck(note, seconds=0.35, bright=1.0):
    """A square-wave pluck whose filter closes as it decays (odd harmonics, the high ones dying first)."""
    t = _t(seconds)
    f = midi(note)
    x = np.zeros(len(t))
    k = 1
    while k * f < 9000:
        x += np.sin(2 * np.pi * k * f * t) / k * np.exp(-t * (5 + k * f / 260 / bright))
        k += 2
    return x * np.minimum(1, t / 0.002) * np.exp(-t * 3) * 0.5


def fm(note, seconds, ratio=1.0, index=1.5, idx_decay=4.0, amp_decay=1.2, attack=0.003):
    t = _t(seconds)
    f = midi(note)
    idx = index * np.exp(-t * idx_decay)
    x = np.sin(2 * np.pi * f * t + idx * np.sin(2 * np.pi * f * ratio * t))
    return x * np.minimum(1, t / attack) * np.exp(-t * amp_decay)


def glass_stab(notes, seconds=0.9):
    """A glassy FM chord stab, spread across the stereo field."""
    out = np.zeros((int(seconds * SR), 2))
    for i, note in enumerate(notes):
        x = fm(note, seconds, ratio=2.0, index=1.1, idx_decay=7, amp_decay=4.5) * 0.7 + fm(note + 12, seconds, ratio=3.0, index=0.6, idx_decay=10, amp_decay=6) * 0.25
        add(out, stereo(x, pan=-0.5 + i / max(1, len(notes) - 1)), 0.0)
    return out / len(notes) * 1.4


def epiano(note, seconds=2.2, vel=1.0):
    """An FM electric piano: a soft bell on the attack, a warm body, a slow stereo tremolo."""
    t = _t(seconds)
    body = fm(note, seconds, ratio=1.0, index=1.4 * vel, idx_decay=3.5, amp_decay=0.9 + midi(note) / 1400, attack=0.004)
    tine = fm(note, seconds, ratio=14.0, index=0.4, idx_decay=25, amp_decay=18) * 0.12 * vel if midi(note) * 14 < 12000 else 0
    x = (body + tine) * 0.4 * vel
    trem = 0.5 + 0.5 * np.sin(2 * np.pi * 4.2 * t)
    return np.stack([x * (0.75 + 0.25 * trem), x * (0.75 + 0.25 * (1 - trem))], axis=1)


def warm_pad(notes, seconds, cutoff=900):
    """Sines and soft triangles, gently detuned: a pad with no saw edge."""
    t = _t(seconds)
    out = np.zeros((len(t), 2))
    for i, note in enumerate(notes):
        for j, cents in enumerate((-6, 6)):
            f = midi(note) * 2 ** (cents / 1200)
            x = np.sin(2 * np.pi * f * t) + 0.11 * np.sin(2 * np.pi * 3 * f * t) + 0.04 * np.sin(2 * np.pi * 5 * f * t)
            add(out, stereo(x, pan=(-0.6, 0.6)[j] * (1 if i % 2 else 0.6)), 0.0)
    out = butter(out, "lowpass", cutoff)
    out *= env_adsr(len(t), min(0.8, seconds * 0.3), 0.3, 0.9, min(1.2, seconds * 0.3))[:, None]
    return out / len(notes) * 0.5


def sub(note, seconds, gain=1.0):
    t = _t(seconds)
    f = midi(note)
    x = np.tanh((np.sin(2 * np.pi * f * t) + 0.2 * np.sin(4 * np.pi * f * t)) * 1.3)
    return stereo(x * env_adsr(len(t), 0.008, 0.1, 0.85, 0.05) * 0.45 * gain)


def kick(gain=1.0, punch=1.0):
    t = _t(0.45)
    f = 47 + 95 * np.exp(-t * 32 * punch)
    body = np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t * 8)
    click = butter(RNG.standard_normal(len(t)), "highpass", 3000) * np.exp(-t * 500) * 0.2
    return stereo(np.tanh((body + click) * 1.5) * 0.8 * gain)


def hat(open_=False, gain=1.0):
    """Metallic hats: six square waves at inharmonic pitches, high-passed."""
    seconds = 0.3 if open_ else 0.06
    t = _t(seconds)
    x = sum(np.sign(np.sin(2 * np.pi * f * t)) for f in (205.3, 304.4, 369.6, 522.7, 540.0, 800.0))
    x = butter(x + RNG.standard_normal(len(t)) * 0.6, "bandpass", [7000, 15000], order=2)
    x *= np.exp(-t * (13 if open_ else 75))
    return stereo(x * 0.07 * gain, pan=RNG.uniform(-0.25, 0.25))


def clap(gain=1.0):
    return sound.clap(gain)


def rim(gain=1.0):
    t = _t(0.08)
    x = np.sin(2 * np.pi * 1700 * t) * np.exp(-t * 70) * 0.5 + butter(RNG.standard_normal(len(t)), "highpass", 3000) * np.exp(-t * 160) * 0.3
    return stereo(x * 0.35 * gain, pan=0.15)


def shaker(gain=1.0):
    t = _t(0.07)
    env = np.minimum(1, t / 0.012) * np.exp(-t * 55)
    return stereo(butter(RNG.standard_normal(len(t)), "bandpass", [6000, 11000], order=2) * env * 0.15 * gain, pan=RNG.uniform(-0.3, 0.3))


def snare(gain=1.0):
    """A big, roomy snare: body, wires and a long tail."""
    t = _t(0.6)
    body = np.sin(2 * np.pi * np.cumsum(185 + 60 * np.exp(-t * 40)) / SR) * np.exp(-t * 18)
    wires = butter(RNG.standard_normal(len(t)), "bandpass", [1500, 9000], order=2) * np.exp(-t * 14)
    x = np.tanh((body * 0.6 + wires * 0.7) * 1.4) * 0.5 * gain
    return sound.reverb(stereo(x), wet=0.45, seconds=2.2)


def boom(gain=1.0):
    t = _t(2.5)
    f = 38 + 30 * np.exp(-t * 6)
    x = np.tanh(np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t * 1.6) * 1.6) * 0.8 * gain
    return sound.reverb(stereo(x), wet=0.3, seconds=2.6)


def swell_brass(notes, seconds=2.0):
    """A low brass-like chord whose filter opens: rising weight for a reveal."""
    t = _t(seconds)
    out = np.zeros(len(t))
    cutoff = 150 + 2600 * (t / seconds) ** 2
    for note in notes:
        f = midi(note)
        for cents in (-7, 0, 7):
            fc = f * 2 ** (cents / 1200)
            k = 1
            while k * fc < 3500:
                out += np.sin(2 * np.pi * k * fc * t) / k / (1 + (k * fc / cutoff) ** 4)
                k += 1
    out *= env_adsr(len(t), seconds * 0.6, 0.1, 0.9, 0.25)
    return sound.reverb(stereo(out / (len(notes) * 3) * 0.6), wet=0.3, seconds=2.4)


def vinyl(seconds, gain=1.0):
    """A faint record surface: soft hiss and sparse crackle."""
    n = int(seconds * SR)
    hiss = butter(RNG.standard_normal(n), "bandpass", [1500, 9000], order=1) * 0.008
    crackle = np.zeros(n)
    idx = RNG.integers(0, n, int(seconds * 7))
    crackle[idx] = RNG.uniform(-1, 1, len(idx)) * 0.12
    crackle = butter(crackle, "bandpass", [800, 6000], order=1)
    return stereo((hiss + crackle) * gain)


def _pump(n, kicks, depth=0.5, rate=9):
    p = np.ones(n)
    for kt in kicks:
        i = int(kt * SR)
        m = min(n - i, int(0.3 * SR))
        if m > 0:
            p[i:i + m] = np.minimum(p[i:i + m], 1 - depth * np.exp(-np.arange(m) / SR * rate))
    return p[:, None]


# ── Styles ────────────────────────────────────────────────────────────────

def pulse(duration, sections):
    """Bright tech electronic: F - C - Dm - Bb, 118 BPM."""
    beat = 60 / 118
    bar = 4 * beat
    chords = [(41, [65, 69, 72, 77]), (36, [64, 67, 72, 76]), (38, [62, 65, 69, 74]), (34, [62, 65, 70, 74])]
    n = int((duration + 5) * SR)
    arp, keys, low, drums = (np.zeros((n, 2)) for _ in range(4))
    kicks = []
    for b in range(int(np.ceil(duration / bar)) + 1):
        t0 = b * bar
        name = _section_at(sections, t0 + 0.01) or _section_at(sections, min(duration - 0.01, t0 + bar - 0.01))
        if name is None:
            continue
        root, notes = chords[b % 4]
        for step in range(16):
            ts = t0 + step * beat / 4
            here = _section_at(sections, ts + 0.001)
            if here is None or ts > duration:
                continue
            # The arpeggio: chord tones in sixteenths, darker under the voices.
            bright = {"scene": 0.35, "intro": 0.8, "title": 1.0, "groove": 1.1, "end": 0.6}[here]
            level = {"scene": 0.12, "intro": 0.3, "title": 0.32, "groove": 0.3, "end": 0.2}[here]
            note = notes[(step * 3 + step // 4) % len(notes)] + (12 if here in ("title", "groove") and step % 8 == 7 else 0)
            add(arp, stereo(square_pluck(note, 0.32, bright), pan=(-0.35 if step % 2 else 0.35)), ts, level)
            if step % 2 == 0 and here in ("scene", "intro", "title", "groove"):
                add(low, sub(root + 12, beat / 2 * 0.85), ts, {"scene": 0.7, "intro": 0.8}.get(here, 1.0))
            if step % 4 == 0:
                if here in ("groove", "title") or (here == "intro" and t0 >= bar):
                    add(drums, kick(), ts, 0.55)
                    kicks.append(ts)
                elif here == "scene":
                    add(drums, kick(0.5, punch=0.7), ts, 0.35)
                    kicks.append(ts)
            if step % 2 == 0 and here != "end":
                add(drums, hat(gain=0.5 if here == "scene" else 1.0), ts + beat / 4 if step % 4 == 2 else ts, 0.5 if here == "scene" else 0.7)
            if here == "groove":
                if step in (4, 12):
                    add(drums, clap(0.9), ts, 0.3)
                if step in (2, 6, 10, 14):
                    add(drums, hat(open_=True), ts, 0.5)
                if step in (6, 14):
                    add(keys, glass_stab([n_ + 12 for n_ in notes]), ts, 0.5)
        if name in ("title", "groove", "end") and _section_at(sections, t0 - 0.01) != name:
            add(keys, glass_stab([n_ + 12 for n_ in notes], 2.0), t0, 0.6)
    arp = sound.reverb(arp + butter(np.roll(arp, int(beat * 0.75 * SR), axis=0)[:, ::-1], "lowpass", 4000) * 0.35, wet=0.2, seconds=1.6)
    keys = sound.reverb(keys, wet=0.35, seconds=2.4)
    pump = _pump(n, kicks)
    return butter(arp * pump + keys + low * pump + drums, "highpass", 30)


def cinematic(duration, sections):
    """Inspiring and wide: Dm - Bb - F - C, 92 BPM."""
    beat = 60 / 92
    bar = 4 * beat
    chords = [(38, [50, 57, 62, 65]), (34, [46, 53, 58, 62]), (41, [48, 53, 57, 60]), (36, [48, 52, 55, 60])]
    n = int((duration + 6) * SR)
    pads, ost, low, drums = (np.zeros((n, 2)) for _ in range(4))
    for b in range(int(np.ceil(duration / bar)) + 1):
        t0 = b * bar
        name = _section_at(sections, t0 + 0.01) or _section_at(sections, min(duration - 0.01, t0 + bar - 0.01))
        if name is None:
            continue
        root, notes = chords[b % 4]
        last = _section_at(sections, t0 + bar + 0.01) is None
        ring = 6.0 if (name == "end" or last) else bar + 1.0
        add(pads, warm_pad(notes, ring, cutoff={"scene": 700, "end": 1800}.get(name, 1400)), t0, {"scene": 0.7}.get(name, 1.0))
        add(low, boom(0.8 if name != "scene" else 0.45), t0, 0.5)
        for step in range(16):
            ts = t0 + step * beat / 4
            here = _section_at(sections, ts + 0.001)
            if here is None or ts > duration:
                continue
            # The ostinato: the root in eighths, octave jumps, opening up in the lifts.
            if step % 2 == 0 and here != "end":
                note = root + 24 + (12 if step in (6, 14) else 0)
                bright = {"scene": 0.3, "intro": 0.7, "title": 0.9, "groove": 1.0}[here]
                add(ost, stereo(square_pluck(note, 0.28, bright), pan=(-0.3 if step % 4 else 0.3)), ts, {"scene": 0.12}.get(here, 0.26))
            # Ticking time: soft sixteenths.
            if here != "end":
                add(drums, shaker(1.0 if step % 4 == 2 else 0.5), ts, 0.35 if here == "scene" else 0.6)
            if here == "groove":
                if step == 0:
                    add(drums, kick(1.0), ts, 0.7)
                if step == 10:
                    add(drums, kick(0.7), ts, 0.5)
                if step == 8:
                    add(drums, snare(), ts, 0.6)
                if step in (4, 12):
                    add(drums, rim(), ts, 0.4)
        if name in ("title", "groove") and _section_at(sections, t0 - 0.01) != name:
            add(pads, swell_brass([root + 12, root + 19, root + 24], 1.6), max(0, t0 - 1.5), 0.7)
    ost = sound.reverb(ost, wet=0.25, seconds=2.0)
    return butter(pads + ost + low + drums, "highpass", 30)


def chill(duration, sections):
    """Soft electronic, two-step: Abmaj9 - Fm9 - Dbmaj9 - Eb6, 128 BPM in half time."""
    beat = 60 / 128
    bar = 4 * beat
    chords = [(44, [56, 60, 63, 67, 70]), (41, [56, 60, 63, 65, 67]), (37, [56, 60, 61, 63, 65]), (39, [55, 58, 60, 63, 65])]
    n = int((duration + 5) * SR)
    keys, low, drums = (np.zeros((n, 2)) for _ in range(3))
    kicks = []
    for b in range(int(np.ceil(duration / (2 * bar))) + 1):
        t0 = b * 2 * bar  # one chord every two bars
        name = _section_at(sections, t0 + 0.01) or _section_at(sections, min(duration - 0.01, t0 + 2 * bar - 0.01))
        if name is None:
            continue
        root, notes = chords[b % 4]
        vel = {"scene": 0.55, "intro": 0.85, "title": 0.9, "groove": 0.9, "end": 1.0}[name]
        # The chord, struck softly and again on the "and" of three.
        for k, note in enumerate(notes):
            add(keys, epiano(note, 3.2 if name == "end" else 2.4, vel), t0 + 0.012 * k, 0.5)
            if name in ("title", "groove", "intro"):
                add(keys, epiano(note, 1.6, vel * 0.7), t0 + bar + 2.5 * beat + 0.012 * k, 0.35)
        add(low, sub(root, 2 * bar * 0.95, gain=0.9), t0, 1.0 if name != "scene" else 0.75)
        for step in range(32):
            ts = t0 + step * beat / 4
            here = _section_at(sections, ts + 0.001)
            if here is None or ts > duration or here == "end":
                continue
            swing = beat / 4 * 0.18 if step % 2 else 0  # shuffled sixteenths
            if step % 2 == 0 or here in ("groove", "title"):
                add(drums, hat(gain=0.45 if step % 4 else 0.7), ts + swing, 0.35 if here == "scene" else 0.55)
            if here in ("groove", "title", "intro"):
                if step in (0, 10, 16, 26):  # two-step kick
                    add(drums, kick(0.8, punch=0.8), ts, 0.5)
                    kicks.append(ts)
                if step in (8, 24):
                    add(drums, clap(0.8), ts, 0.3)
                    add(drums, rim(0.6), ts, 0.25)
            elif here == "scene" and step in (0, 16):
                add(drums, kick(0.45, punch=0.7), ts, 0.35)
                kicks.append(ts)
            if here == "scene" and step in (8, 24):
                add(drums, rim(0.5), ts, 0.25)
    keys = sound.reverb(keys, wet=0.3, seconds=2.2)
    out = keys * _pump(n, kicks, depth=0.3) + low * _pump(n, kicks, depth=0.45) + drums
    return butter(out + vinyl(n / SR, 0.9), "highpass", 30)


def music(style, duration, sections):
    return {"pulse": pulse, "cinematic": cinematic, "chill": chill}[style](duration, sections)


# ── Sound design ──────────────────────────────────────────────────────────

def signature(style):
    """The logo's first appearance, in the style's own sound."""
    out = np.zeros((int(4 * SR), 2))
    if style == "pulse":
        add(out, glass_stab([65, 72, 77, 81], 2.5), 0.0, 0.8)
        add(out, kick(), 0.0, 0.5)
    elif style == "cinematic":
        add(out, boom(), 0.0, 0.7)
        add(out, warm_pad([50, 57, 62, 69], 3.5, cutoff=2200), 0.0, 1.0)
    else:
        for k, note in enumerate((56, 63, 67, 70, 75)):
            add(out, epiano(note, 3.0, 1.0), 0.015 * k, 0.5)
    return sound.reverb(out, wet=0.3, seconds=2.4)


def radio_sting(style, gain=1.0):
    """A short on-air jingle in the style's own sound."""
    out = np.zeros((int(3.0 * SR), 2))
    if style == "chill":
        for k, (note, at) in enumerate(((68, 0.0), (72, 0.09), (75, 0.18), (80, 0.27))):
            add(out, epiano(note, 1.6, 1.0), at, 0.6)
    else:
        for k, (note, at) in enumerate(((72, 0.0), (77, 0.09), (81, 0.18), (84, 0.27))):
            add(out, stereo(square_pluck(note, 0.6, 1.2), pan=-0.3 + 0.2 * k), at, 0.5)
        add(out, glass_stab([77, 81, 84], 1.5), 0.27, 0.5)
    return sound.reverb(out * gain, wet=0.3, seconds=1.4)


def sparkle(seconds, density=60, gain=1.0, seed=0):
    """Dots gathering: many tiny glassy ticks, thick at first, thinning out."""
    rng = np.random.default_rng(seed)
    out = np.zeros((int((seconds + 0.6) * SR), 2))
    for _ in range(int(density * seconds)):
        at = seconds * (rng.random() ** 1.6)
        t = _t(0.05)
        x = np.sin(2 * np.pi * rng.uniform(2600, 7200) * t) * np.exp(-t * rng.uniform(90, 160)) * rng.uniform(0.3, 1.0)
        add(out, stereo(x * 0.12 * gain, pan=rng.uniform(-0.8, 0.8)), at)
    return sound.reverb(out, wet=0.35, seconds=1.6)


def swell(seconds=1.2, gain=1.0):
    """Air rising: something forming."""
    n = int(seconds * SR)
    t = np.linspace(0, 1, n)
    x = butter(RNG.standard_normal(n), "bandpass", [500, 6000], order=2) * (t ** 2.5) * 0.25
    return sound.reverb(stereo(x * gain), wet=0.4, seconds=1.8)


def phone_ring(gain=1.0):
    """Two short rings, heard as from a handset."""
    t = _t(1.25)
    tone = np.sin(2 * np.pi * 440 * t) + np.sin(2 * np.pi * 480 * t)
    trill = 0.65 + 0.35 * np.sign(np.sin(2 * np.pi * 20 * t))
    gate = butter(((t < 0.42) | ((t > 0.62) & (t < 1.04))).astype(float), "lowpass", 60)
    x = butter(tone * trill * gate, "bandpass", [350, 3200], order=2) * 0.16 * gain
    return sound.reverb(stereo(x), wet=0.12, seconds=0.8)
