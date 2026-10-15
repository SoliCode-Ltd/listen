#!/usr/bin/env python3
"""
Builds the sound environment assets in resources/audio/environments/:

  ir/<room>.flac   the reflections of a real-sized room, simulated with an
                   acoustic simulator (image sources for the early
                   reflections, ray tracing for the tail, real surface
                   materials, air absorption). Stereo: two microphones 40 cm
                   apart. The direct sound is removed and the file is scaled
                   so the direct sound would be 1.0, so mixing the dry voice
                   with this at 0 dB gives the room exactly as heard there.
  bed/<name>.flac  a seamless background loop (room tone, road, crowd...),
                   brought to -20 LUFS. Crowds are made from Listen's own
                   showcase voices, layered until no word can be followed.

Everything here is generated, so the files are Listen's own.

Run once, then commit the files:
  pip install pyroomacoustics soundfile scipy numpy
  python3 scripts/audio/build_environments.py
"""

import os
import subprocess
import sys

import numpy as np
import pyroomacoustics as pra
import soundfile as sf
from scipy.signal import butter, fftconvolve, sosfilt

FS = 44100
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, 'resources', 'audio', 'environments')
SHOWCASE = os.path.join(ROOT, 'public', 'audio', 'showcase')
RNG = np.random.default_rng(20261015)


def mat(name_or_alpha, scattering=0.1):
    if isinstance(name_or_alpha, str):
        return pra.Material(name_or_alpha, scattering)
    return pra.Material(energy_absorption=float(name_or_alpha), scattering=scattering)


def box(walls, floor, ceiling, scattering=0.15):
    return {
        'east': mat(walls, scattering), 'west': mat(walls, scattering),
        'north': mat(walls, scattering), 'south': mat(walls, scattering),
        'floor': mat(floor, scattering), 'ceiling': mat(ceiling, scattering),
    }


# name: dimensions (m), materials, distance from the voice to the microphones (m), max seconds
ROOMS = {
    'office': ([5.2, 4.1, 2.8], box('plasterboard', 'carpet_tufted_9.5mm', 'ceiling_fissured_tile', 0.2), 1.2, 1.0),
    # Tiles, with a shower curtain and towels on one side.
    'bathroom': ([2.6, 2.1, 2.5], {
        'east': mat('ceramic_tiles', 0.1), 'west': mat('curtains_cotton_0.33', 0.3), 'north': mat('ceramic_tiles', 0.1),
        'south': mat('wooden_door', 0.1), 'floor': mat('ceramic_tiles', 0.1), 'ceiling': mat('plasterboard', 0.1),
    }, 0.9, 1.4),
    'living_room': ([5.6, 4.4, 2.6], {
        'east': mat('plasterboard', 0.2), 'west': mat('curtains_cotton_0.5', 0.3), 'north': mat('plasterboard', 0.2),
        'south': mat('glass_window', 0.05), 'floor': mat('carpet_cotton', 0.2), 'ceiling': mat('ceiling_plasterboard', 0.1),
    }, 2.4, 1.0),
    'car': ([1.9, 1.45, 1.15], {
        'east': mat('glass_window', 0.05), 'west': mat('glass_window', 0.05), 'north': mat('glass_window', 0.05),
        'south': mat('chairs_upholstered_cloth', 0.4), 'floor': mat('carpet_hairy', 0.3), 'ceiling': mat('felt_5mm', 0.2),
    }, 0.55, 0.5),
    'helmet': ([0.34, 0.3, 0.36], box('hard_surface', 'hard_surface', 'glass_3mm', 0.05), 0.12, 0.25),
    'hall': ([38.0, 24.0, 16.0], {
        'east': mat('wooden_lining', 0.4), 'west': mat('wooden_lining', 0.4), 'north': mat('stage_floor', 0.3),
        'south': mat('wooden_lining', 0.4), 'floor': mat('audience_upholstered_chairs_1', 0.7), 'ceiling': mat('plasterboard', 0.3),
    }, 5.0, 3.2),
    # Stone, with wooden pews filling the nave.
    'cathedral': ([62.0, 26.0, 28.0], box('limestone_wall', 'audience_wooden_chairs_1_m2', 'limestone_wall', 0.5), 9.0, 5.5),
    'terminal': ([90.0, 40.0, 16.0], {
        'east': mat('glass_window', 0.1), 'west': mat('glass_window', 0.1), 'north': mat('rough_concrete', 0.3),
        'south': mat('glass_window', 0.1), 'floor': mat('marble_floor', 0.2), 'ceiling': mat('ceiling_metal_panel', 0.3),
    }, 14.0, 4.5),
    'warehouse': ([34.0, 22.0, 9.0], box('rough_concrete', 'concrete_floor', 'ceiling_metal_panel', 0.3), 14.0, 3.0),
    # Rough rock that scatters everything, over a floor of grit and sand.
    'cave': ([26.0, 11.0, 7.0], box('brick_wall_rough', 0.3, 'brick_wall_rough', 0.8), 4.0, 3.5),
    # Open air: the sky absorbs everything; stands or buildings reflect.
    'stadium': ([210.0, 150.0, 45.0], {
        'east': mat('audience_1_m2', 0.7), 'west': mat('audience_1_m2', 0.7), 'north': mat('audience_1_m2', 0.7),
        'south': mat('audience_1_m2', 0.7), 'floor': mat(0.35, 0.3), 'ceiling': mat(0.99, 0.0),
    }, 22.0, 3.5),
    'street': ([120.0, 16.0, 22.0], {
        'east': mat('brickwork', 0.5), 'west': mat('brickwork', 0.5), 'north': mat(0.99, 0.0),
        'south': mat(0.99, 0.0), 'floor': mat('rough_concrete', 0.3), 'ceiling': mat(0.99, 0.0),
    }, 3.0, 2.0),
}


def simulate(dims, materials, distance, max_order, rays):
    dims = np.array(dims, dtype=float)
    # Off the room's centre lines, so left and right hear different reflections.
    h = min(1.6, dims[2] * 0.55)
    half = min(0.2, dims[1] * 0.15)
    src = np.array([dims[0] * 0.42 - distance / 2, dims[1] * 0.56, h])
    mics = np.c_[[src[0] + distance, src[1] - half, h], [src[0] + distance, src[1] + half, h]]

    def run(order, ray_tracing):
        room = pra.ShoeBox(dims, fs=FS, materials=materials, max_order=order, ray_tracing=ray_tracing, air_absorption=True)
        if ray_tracing:
            room.set_ray_tracing(receiver_radius=0.5, n_rays=rays, energy_thres=1e-7)
        room.add_source(src)
        room.add_microphone_array(mics)
        room.compute_rir()
        n = max(len(room.rir[0][0]), len(room.rir[1][0]))
        return np.stack([np.pad(room.rir[m][0], (0, n - len(room.rir[m][0]))) for m in range(2)], axis=1)

    full = run(max_order, True)
    direct = run(0, False)
    n = max(len(full), len(direct))
    full = np.pad(full, ((0, n - len(full)), (0, 0)))
    direct = np.pad(direct, ((0, n - len(direct)), (0, 0)))
    scale = np.abs(direct).max()
    reflections = (full - direct) / scale
    start = int(np.argmax(np.abs(direct[:, 0])))
    # Start the file at the moment the direct sound would arrive.
    return reflections[max(0, start - 1):]


def trim(ir, max_seconds):
    ir = ir[: int(max_seconds * FS)]
    env = np.sqrt(np.convolve((ir ** 2).mean(axis=1), np.ones(441) / 441, mode='same'))
    above = np.nonzero(20 * np.log10(env + 1e-12) > 20 * np.log10(env.max() + 1e-12) - 70)[0]
    end = min(len(ir), (above[-1] if len(above) else len(ir)) + 1)
    ir = ir[:end].copy()
    fade = min(len(ir) // 4, int(0.08 * FS))
    ir[-fade:] *= np.linspace(1, 0, fade)[:, None] ** 2
    return ir


def build_rooms():
    os.makedirs(os.path.join(OUT, 'ir'), exist_ok=True)
    only = sys.argv[2:]
    for name, (dims, materials, distance, seconds) in ROOMS.items():
        if only and name not in only:
            continue
        big = max(dims) > 20
        ir = trim(simulate(dims, materials, distance, 2 if big else 6, 20000 if big else 12000), seconds)
        peak = np.abs(ir).max()
        if peak > 0.99:
            # Not expected; keep the file valid and say so.
            print(f'  note: {name} reflections peak at {peak:.2f}, scaled to fit', file=sys.stderr)
            ir *= 0.99 / peak
        sf.write(os.path.join(OUT, 'ir', f'{name}.flac'), ir.astype(np.float32), FS, subtype='PCM_24')
        energy = 10 * np.log10((ir ** 2).sum() / 2 + 1e-12)
        print(f'room {name:12} {len(ir) / FS:5.2f}s  reflections vs direct {energy:+6.1f} dB')


# ---------- beds ----------

def bp(x, lo=None, hi=None, order=4):
    if lo and hi:
        sos = butter(order, [lo, hi], btype='band', fs=FS, output='sos')
    elif lo:
        sos = butter(order, lo, btype='high', fs=FS, output='sos')
    else:
        sos = butter(order, hi, btype='low', fs=FS, output='sos')
    return sosfilt(sos, x, axis=0)


def pink(n, ch=2):
    white = RNG.standard_normal((n, ch))
    spec = np.fft.rfft(white, axis=0)
    f = np.fft.rfftfreq(n, 1 / FS)
    f[0] = f[1]
    return np.fft.irfft(spec / np.sqrt(f)[:, None], n, axis=0)


def brown(n, ch=2):
    x = np.cumsum(RNG.standard_normal((n, ch)), axis=0)
    return bp(x, lo=15, order=2)


def lfo(n, rate, depth, ch=2):
    t = np.arange(n) / FS
    phases = RNG.uniform(0, 2 * np.pi, ch)
    return 1 + depth * np.sin(2 * np.pi * rate * t[:, None] + phases[None, :])


def smooth_random(n, per_second, ch=2):
    points = RNG.standard_normal((int(n / FS * per_second) + 3, ch))
    idx = np.linspace(0, len(points) - 1, n)
    out = np.stack([np.interp(idx, np.arange(len(points)), points[:, c]) for c in range(ch)], axis=1)
    return out


def load_voice(path):
    raw = subprocess.run(['ffmpeg', '-v', 'error', '-i', path, '-ac', '1', '-ar', str(FS), '-f', 'f32le', '-'], capture_output=True, check=True).stdout
    return np.frombuffer(raw, dtype=np.float32).astype(float)


def babble(n, voices, files, ir_name, spread):
    """Many people talking at once, in a room: no single word can be followed."""
    out = np.zeros((n, 2))
    pool = [load_voice(os.path.join(SHOWCASE, f)) for f in files]
    for i in range(voices):
        v = pool[RNG.integers(len(pool))]
        # A few seconds from a random point, slightly re-pitched so the same speaker is not recognised.
        start = RNG.integers(0, max(1, len(v) - FS * 3))
        seg = v[start:start + int(RNG.uniform(2.5, 6) * FS)]
        rate = RNG.uniform(0.86, 1.14)
        seg = np.interp(np.arange(0, len(seg), rate), np.arange(len(seg)), seg)
        at = RNG.integers(0, n)
        gain = 10 ** (RNG.uniform(-14, 0) / 20)
        pan = RNG.uniform(-spread, spread)
        left, right = np.sqrt(0.5 * (1 - pan)), np.sqrt(0.5 * (1 + pan))
        end = min(n, at + len(seg))
        out[at:end, 0] += seg[: end - at] * gain * left
        out[at:end, 1] += seg[: end - at] * gain * right
        # Wrap around so the loop stays even.
        if at + len(seg) > n:
            rest = len(seg) - (n - at)
            out[:rest, 0] += seg[n - at:] * gain * left
            out[:rest, 1] += seg[n - at:] * gain * right
    ir, _ = sf.read(os.path.join(OUT, 'ir', f'{ir_name}.flac'))
    wet = np.stack([fftconvolve(out[:, c], ir[:, c])[:n] for c in range(2)], axis=1)
    return bp(out * 0.25 + wet, lo=180, hi=6500)


def drips(n, per_second, lo_hz, hi_hz):
    out = np.zeros((n, 2))
    count = int(n / FS * per_second)
    for _ in range(count):
        at = RNG.integers(0, n - FS // 4)
        length = int(FS * RNG.uniform(0.03, 0.09))
        t = np.arange(length) / FS
        f0 = RNG.uniform(lo_hz, hi_hz)
        # A water drop: a short tone that rises as the bubble closes.
        tone = np.sin(2 * np.pi * (f0 * t + f0 * 1.8 * t ** 2 / (2 * t[-1]))) * np.exp(-t / (t[-1] / 4))
        pan = RNG.uniform(-0.8, 0.8)
        g = 10 ** (RNG.uniform(-12, 0) / 20)
        out[at:at + length, 0] += tone * g * np.sqrt(0.5 * (1 - pan))
        out[at:at + length, 1] += tone * g * np.sqrt(0.5 * (1 + pan))
    return out


def bubbles(n, per_second):
    out = np.zeros((n, 2))
    for _ in range(int(n / FS * per_second)):
        at = RNG.integers(0, n - FS // 8)
        length = int(FS * RNG.uniform(0.01, 0.05))
        t = np.arange(length) / FS
        f0 = RNG.uniform(300, 1400)
        tone = np.sin(2 * np.pi * f0 * t * (1 + 6 * t)) * np.exp(-t / (t[-1] / 3)) * np.hanning(length) ** 0.3
        g = 10 ** (RNG.uniform(-18, 0) / 20)
        pan = RNG.uniform(-0.6, 0.6)
        out[at:at + length, 0] += tone * g * np.sqrt(0.5 * (1 - pan))
        out[at:at + length, 1] += tone * g * np.sqrt(0.5 * (1 + pan))
    return out


def passes(n, count, length_s):
    """Cars passing at a distance: a swell of road noise that sweeps across."""
    out = np.zeros((n, 2))
    for _ in range(count):
        length = int(length_s * FS * RNG.uniform(0.7, 1.3))
        at = RNG.integers(0, n)
        noise = bp(brown(length, 1)[:, 0] + 0.3 * pink(length, 1)[:, 0], lo=40, hi=1800)
        env = np.hanning(length) ** 2
        pan = np.linspace(-0.9, 0.9, length) * RNG.choice([-1, 1])
        seg = noise * env * 10 ** (RNG.uniform(-8, 0) / 20)
        idx = (np.arange(length) + at) % n
        out[idx, 0] += seg * np.sqrt(0.5 * (1 - pan))
        out[idx, 1] += seg * np.sqrt(0.5 * (1 + pan))
    return out


def loudness(x):
    tmp = os.path.join(OUT, '.tmp.wav')
    sf.write(tmp, x.astype(np.float32), FS)
    o = subprocess.run(['ffmpeg', '-hide_banner', '-i', tmp, '-af', 'ebur128=framelog=quiet', '-f', 'null', '-'], capture_output=True, text=True).stderr
    os.remove(tmp)
    import re
    return float(re.findall(r'I:\s+(-?[\d.]+) LUFS', o)[-1])


def seamless(x, fade_s=2.0):
    """Cross-fade the end into the start so the loop has no seam."""
    f = int(fade_s * FS)
    head, body, tail = x[:f], x[f:-f], x[-f:]
    ramp = np.sqrt(np.linspace(0, 1, f))[:, None]
    return np.concatenate([body, tail * ramp[::-1] + head * ramp])


def write_bed(name, x):
    x = seamless(x)
    x = x / (np.abs(x).max() + 1e-9) * 0.5
    gain = 10 ** ((-20 - loudness(x)) / 20)
    x = x * gain
    peak = np.abs(x).max()
    if peak > 0.97:
        x *= 0.97 / peak
    sf.write(os.path.join(OUT, 'bed', f'{name}.flac'), x.astype(np.float32), FS, subtype='PCM_16')
    print(f'bed  {name:12} {len(x) / FS:5.1f}s  {loudness(x):6.1f} LUFS')


def build_beds():
    os.makedirs(os.path.join(OUT, 'bed'), exist_ok=True)
    n = 26 * FS
    voices = sorted(f for f in os.listdir(SHOWCASE) if f.endswith('.mp3') and not f.startswith('dlg-'))

    # Air conditioning and the hum of a building, very low.
    tone = bp(pink(n), hi=380, order=2) + 0.15 * bp(pink(n), lo=900, hi=5000)
    write_bed('room_tone', tone * lfo(n, 0.05, 0.08))

    # A car on the road: tyres and road, engine, a little wind on the glass.
    road = bp(brown(n), lo=25, hi=320) * (1 + 0.25 * smooth_random(n, 0.4))
    tyres = 0.25 * bp(pink(n), lo=350, hi=1400) * (1 + 0.3 * smooth_random(n, 0.6))
    rpm = 31 + 2 * smooth_random(n, 0.2, 1)[:, 0]
    phase = 2 * np.pi * np.cumsum(rpm) / FS
    engine = sum((0.5 / k) * np.sin(k * phase) for k in (1, 2, 3, 4))[:, None] * np.ones((1, 2))
    wind = 0.12 * bp(pink(n), lo=1500, hi=6000) * (1 + 0.5 * smooth_random(n, 0.3))
    write_bed('car_road', road + tyres + 0.35 * bp(engine, hi=260) + wind)

    # A stadium crowd: a wide roar of many voices.
    crowd = babble(n, 220, voices, 'stadium', 1.0)
    roar = bp(pink(n), lo=250, hi=2500) * (1 + 0.35 * smooth_random(n, 0.25))
    write_bed('crowd', crowd + 0.6 * roar * np.abs(crowd).mean() / (np.abs(roar).mean() + 1e-9))

    # A terminal: people talking across a big hard hall, plus its air.
    talk = babble(n, 70, voices, 'terminal', 0.9)
    write_bed('terminal', talk + 0.4 * tone * np.abs(talk).mean() / (np.abs(tone).mean() + 1e-9))

    # A cave: slow drips in the dark and a low draught.
    ir, _ = sf.read(os.path.join(OUT, 'ir', 'cave.flac'))
    d = drips(n, 0.8, 900, 2600)
    d = np.stack([fftconvolve(d[:, c], ir[:, c])[:n] for c in range(2)], axis=1) + 0.3 * d
    draught = bp(brown(n), lo=30, hi=200) * (1 + 0.6 * smooth_random(n, 0.15))
    write_bed('cave', d + 0.5 * draught * np.abs(d).max() / (np.abs(draught).max() + 1e-9))

    # Under water: a low pressure rumble and bubbles.
    rumble = bp(brown(n), lo=20, hi=160) * (1 + 0.4 * smooth_random(n, 0.2))
    b = bubbles(n, 7)
    write_bed('underwater', rumble / (np.abs(rumble).max() + 1e-9) + 0.5 * b / (np.abs(b).max() + 1e-9))

    # Wind across open ground, gusting.
    gust = np.clip(1 + 0.8 * smooth_random(n, 0.12), 0.15, None)
    wind = (bp(pink(n), lo=120, hi=900) + 0.4 * bp(pink(n), lo=900, hi=3500)) * gust
    write_bed('wind', wind)

    # A city street: distant traffic with cars passing.
    traffic = bp(brown(n), lo=30, hi=700) * (1 + 0.2 * smooth_random(n, 0.3)) + passes(n, 7, 4.0) * 3
    write_bed('street', traffic)

    # A radio's static and mains hum, and the hiss of a line.
    hiss = bp(pink(n), lo=1200, hi=6000)
    crackle = np.zeros((n, 2))
    pops = RNG.integers(0, n, int(n / FS * 6))
    crackle[pops] = RNG.standard_normal((len(pops), 2)) * 1.2
    hum = (np.sin(2 * np.pi * 50 * np.arange(n) / FS) + 0.3 * np.sin(2 * np.pi * 150 * np.arange(n) / FS))[:, None] * np.ones((1, 2))
    write_bed('static', hiss * (1 + 0.5 * smooth_random(n, 2)) + bp(crackle, lo=800, hi=5000) + 0.05 * hum * np.abs(hiss).max())


if __name__ == '__main__':
    what = sys.argv[1] if len(sys.argv) > 1 else 'all'
    if what in ('all', 'rooms'):
        build_rooms()
    if what in ('all', 'beds'):
        build_beds()
