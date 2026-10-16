"""Builds one cut of the launch ad: scene timing, captions, the mixed soundtrack
and the timeline the motion graphics page plays.

    python build.py <cut: ar|en> <out dir> [--ratio 9x16|4x5]

Output (in <out dir>/<cut>): timeline.js, mix.wav, and the page with its assets.
"""

import json
import pathlib
import shutil
import subprocess
import sys

import numpy as np
import soundfile as sf
from PIL import Image, ImageFilter

import sound
from sound import SR

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parents[2]
VOICES = ROOT / "marketing/launch-ad/voices"
FPS = 30

# Every speaker is a Listen HD voice with its product name and avatar.
CAST = {
    "fahad": dict(name_en="Fahad", name_ar="فهد", avatar="ar-fahad", flag="sa", clip="ad-ar-fahad", lang="ar",
                  dialect_en="Saudi · Najdi", dialect_ar="سعودي · نجدي", hue=265,
                  chip_en="Natural laugh", chip_ar="ضحكة طبيعية", chip_icon="laugh",
                  tr_en="Me? Of course I'm real! What more do you want?"),
    "sumaya": dict(name_en="Sumaya", name_ar="سمية", avatar="ar-sumaya", flag="eg", clip="ad-ar-sumaya", lang="ar",
                   dialect_en="Egyptian · Cairo", dialect_ar="مصري · قاهري", hue=320,
                   chip_en="Natural laugh", chip_ar="ضحكة طبيعية", chip_icon="laugh",
                   tr_en="No way, I'm the real one! Who else laughs like this?"),
    "sawsan": dict(name_en="Sawsan", name_ar="سوسن", avatar="ar-sawsan", flag="lb", clip="ad-ar-sawsan", lang="ar",
                   dialect_en="Lebanese · Beirut", dialect_ar="لبناني · بيروتي", hue=195,
                   tr_en="Honestly? None of you can tell us apart."),
    "nawaf": dict(name_en="Nawaf", name_ar="نواف", avatar="ar-nawaf", flag="ae", clip="ad-ar-nawaf", lang="ar",
                  dialect_en="Emirati", dialect_ar="إماراتي", hue=160,
                  tr_en="I'm 100% Emirati… what more do you want?"),
    "aisha": dict(name_en="Aisha", name_ar="عائشة", avatar="ar-aisha", flag="ma", clip="ad-ar-aisha", lang="ar",
                  dialect_en="Moroccan · Darija", dialect_ar="مغربي · دارجة", hue=10,
                  tr_en="Can you hear me well? This is real Moroccan Darija!"),
    "sultan": dict(name_en="Sultan", name_ar="سلطان", avatar="ar-sultan", flag="sa", clip="stadium", lang="ar",
                   dialect_en="Saudi · Commentator", dialect_ar="سعودي · معلّق رياضي", hue=135,
                   chip_en="Sound environment: Stadium", chip_ar="بيئة الصوت: ملعب", chip_icon="stadium",
                   tr_en="GOOOAL! Wow, wow!", stadium=True),
    "daisy": dict(name_en="Daisy", name_ar="ديزي", avatar="en-daisy", flag="us", clip="ad-en-daisy", lang="en",
                  dialect_en="American", dialect_ar="أمريكية", hue=220,
                  tr_ar="حقيقية؟ يا عزيزي، أنا أمريكية حتى النخاع!"),
    "theodore": dict(name_en="Theodore", name_ar="ثيودور", avatar="en-theodore", flag="gb", clip="ad-en-theodore", lang="en",
                     dialect_en="British", dialect_ar="بريطاني", hue=250,
                     tr_ar="أؤكد لك… أنا بريطاني جداً."),
    "finn": dict(name_en="Finn", name_ar="فين", avatar="en-finn", flag="au", clip="ad-en-finn", lang="en",
                 dialect_en="Australian", dialect_ar="أسترالي", hue=40,
                 tr_ar="بجد يا صاحبي، حقيقي مية بالمية!"),
    "luna": dict(name_en="Luna", name_ar="لونا", avatar="en-luna", flag="in", clip="ad-en-luna", lang="en",
                 dialect_en="Indian", dialect_ar="هندية", hue=300,
                 tr_ar="يا جماعة، أكيد أنا حقيقية! بس اسمعوا!"),
    "charlie": dict(name_en="Charlie", name_ar="تشارلي", avatar="en-charlie", flag="ng", clip="ad-en-charlie", lang="en",
                    dialect_en="Nigerian", dialect_ar="نيجيري", hue=120,
                    tr_ar="أنا الحقيقي الوحيد هنا!"),
}

# Two full lines set up the joke, then a fast montage of one phrase each.
# words=(from, to) picks script words (inclusive); fast=True is a quick cut.
FAST_AR = [
    ("sawsan", dict(words=(1, None), fast=True, tr_en="None of you can tell us apart.")),
    ("nawaf", dict(words=(0, 3), fast=True, tr_en="I'm 100% Emirati…")),
    ("aisha", dict(words=(3, None), fast=True, tr_en="This is real Moroccan Darija!")),
    ("sultan", dict(goal=True, fast=True, tr_en="GOOOAL!")),
]
FAST_EN = [
    ("finn", dict(fast=True)),
    ("luna", dict(words=(0, 5), fast=True, tr_ar="يا جماعة، أكيد أنا حقيقية!")),
    ("charlie", dict(fast=True, tail=0.5)),
]
CUTS = {
    "ar": dict(ui="ar", narrator="ad-ar", order=[
        "hook", "fahad", "sumaya", *FAST_AR, "switch", "daisy", ("theodore", dict(fast=True)), *FAST_EN, "reveal", "cta"]),
    "en": dict(ui="en", narrator="ad-en", order=[
        "hook", "daisy", "theodore", *FAST_EN, "switch", "fahad", "sumaya", *FAST_AR, "reveal", "cta"]),
    # About 30 seconds, for paid placements.
    "ar30": dict(ui="ar", narrator="ad-ar", order=[
        "hook", "sumaya", FAST_AR[1], FAST_AR[2], FAST_AR[3], FAST_EN[1], "reveal", "cta"]),
    "en30": dict(ui="en", narrator="ad-en", order=[
        "hook", "daisy", FAST_EN[0], FAST_EN[1], FAST_AR[2], FAST_AR[3], "reveal", "cta"]),
}

NARRATION = {
    "ad-ar": dict(hook="ad-ar-hook", switch="ad-ar-english", reveal="ad-ar-reveal", cta="ad-ar-cta"),
    "ad-en": dict(hook="ad-en-hook", switch="ad-en-arabic", reveal="ad-en-reveal", cta="ad-en-cta"),
}

# Seconds before the voice starts and after it ends, per scene kind.
PRE = dict(hook=0.6, speaker=0.34, fast=0.2, switch=0.24, reveal=0.45, cta=0.45)
POST = dict(hook=0.3, speaker=0.26, fast=0.1, switch=0.14, reveal=0.65, cta=2.9)
GRID = 0.25  # half a beat at 120 BPM: every scene starts on the music grid


def lines_text():
    php = "echo json_encode(array_column((require 'marketing/launch-ad/lines.php')['samples'], 'text', 'slug'), JSON_UNESCAPED_UNICODE);"
    out = subprocess.run(["php", "-r", php], cwd=ROOT, capture_output=True, text=True, check=True).stdout
    return json.loads(out)


def load_audio(path):
    data, sr = sf.read(str(path), always_2d=True)
    if sr != SR:
        raw = subprocess.run(["ffmpeg", "-v", "error", "-i", str(path), "-ac", "2", "-ar", str(SR), "-f", "f32le", "-"],
                             capture_output=True, check=True).stdout
        data = np.frombuffer(raw, dtype=np.float32).reshape(-1, 2).astype(float)
    if data.shape[1] == 1:
        data = np.repeat(data, 2, axis=1)
    return data


def speech_span(audio, threshold_db=-42):
    mono = audio.mean(axis=1)
    hop = int(0.01 * SR)
    frames = len(mono) // hop
    rms = np.sqrt(np.array([np.mean(mono[i * hop:(i + 1) * hop] ** 2) for i in range(frames)]) + 1e-12)
    db = 20 * np.log10(rms)
    loud = np.where(db > threshold_db)[0]
    if len(loud) == 0:
        return 0.0, len(mono) / SR
    return loud[0] * hop / SR, (loud[-1] + 1) * hop / SR


def _norm(word):
    import re
    w = re.sub(r"[\u064B-\u0652\u0640]", "", word)  # diacritics, tatweel
    w = re.sub(r"[إأآا]", "ا", w).replace("ى", "ي").replace("ة", "ه")
    return re.sub(r"[^\w]", "", w.lower())


def caption_words(script, heard, offset, speech_start=0.0):
    """Script words timed from the transcript: matching words take the
    transcript's times, the rest are spread between their neighbours."""
    import difflib
    import re
    clean = re.sub(r"\s*<[^<>]+>\s*", " ", script).strip()
    words = clean.split()
    if not heard:
        return [dict(w=w, s=None, e=None) for w in words]
    times = [None] * len(words)
    sm = difflib.SequenceMatcher(a=[_norm(w) for w in words], b=[_norm(h["w"]) for h in heard], autojunk=False)
    for tag, i1, i2, j1, j2 in sm.get_opcodes():
        if tag == "equal" or (tag == "replace" and i2 - i1 == j2 - j1):
            for k in range(i2 - i1):
                times[i1 + k] = (heard[j1 + k]["start"], heard[j1 + k]["end"])
    # Words the transcript missed: share the gap between known neighbours by length.
    i = 0
    while i < len(words):
        if times[i] is not None:
            i += 1
            continue
        j = i
        while j < len(words) and times[j] is None:
            j += 1
        lo = times[i - 1][1] if i > 0 else speech_start
        hi = times[j][0] if j < len(words) else heard[-1]["end"]
        if i == 0 and j < len(words):
            # Leading words: said right at the start, at a natural pace.
            hi = min(hi, lo + sum(len(w) for w in words[i:j]) * 0.075 + 0.15)
        total = sum(len(w) for w in words[i:j]) or 1
        cur = lo
        for k in range(i, j):
            d = (hi - lo) * len(words[k]) / total
            times[k] = (cur, cur + d)
            cur += d
        i = j
    out = []
    for k, (w, (a, b)) in enumerate(zip(words, times)):
        if k == 0:
            a = max(a, speech_start)
        out.append(dict(w=w, s=a - offset, e=max(b, a + 0.05) - offset))
    return out


def stadium_clip(goal_only=False):
    audio = load_audio(ROOT / "public/audio/environments/env-ar-stadium.mp3")
    end = 3.0 if goal_only else 4.95
    cut = audio[: int(end * SR)].copy()
    fade = int((0.3 if goal_only else 0.35) * SR)
    cut[-fade:] *= np.linspace(1, 0, fade)[:, None]
    words = [dict(w="قووووول!", s=0.1, e=2.75)]
    if not goal_only:
        words += [dict(w="يا", s=3.15, e=3.42), dict(w="سلام", s=3.42, e=3.95), dict(w="يا", s=3.95, e=4.2), dict(w="سلام!", s=4.2, e=4.75)]
    return cut, 0.08, words[-1]["e"] + 0.02, words


def prepared_clip(slug, texts, heard_all, word_range=None, lead_trim=False, tail=None):
    audio = load_audio(VOICES / f"{slug}.wav")
    s, e = speech_span(audio)
    heard = (heard_all.get(slug) or {}).get("words") or []
    words = caption_words(texts.get(slug, ""), heard, 0.0, s)
    if any(w["s"] is None for w in words):
        span = (e - s) / max(1, len(words))
        for i, w in enumerate(words):
            w["s"], w["e"] = s + i * span, s + (i + 1) * span
    if word_range:
        lo, hi = word_range
        words = words[lo: (hi + 1) if hi is not None else None]
        s = max(s, words[0]["s"] - 0.05) if lo else s
        e = min(e, words[-1]["e"] + 0.08) if hi is not None else e
    if tail is not None and words:
        e = min(e, words[-1]["e"] + tail)
    if lead_trim and words:
        s = max(s, words[0]["s"] - 0.06)
    a = max(0.0, s - 0.04)
    b = min(len(audio) / SR, e + 0.18)
    clip = audio[int(a * SR): int(b * SR)].copy()
    fade = int(0.012 * SR)
    clip[:fade] *= np.linspace(0, 1, fade)[:, None]
    clip[-fade * 4:] *= np.linspace(1, 0, fade * 4)[:, None]
    words = [dict(w=w["w"], s=w["s"] - a, e=w["e"] - a) for w in words]
    return clip, s - a, e - a, words


def snap(t):
    return np.ceil(t / GRID - 1e-6) * GRID


def build(cut_name, out_dir, ratio):
    cut = CUTS[cut_name]
    ui = cut["ui"]
    texts = lines_text()
    heard_all = json.loads((VOICES / "words.json").read_text(encoding="utf-8")) if (VOICES / "words.json").exists() else {}
    narr = NARRATION[cut["narrator"]]

    scenes = []
    t = 0.0
    for entry in cut["order"]:
        item, opts = entry if isinstance(entry, tuple) else (entry, {})
        kind = "speaker" if item in CAST else item
        if kind == "speaker":
            meta = CAST[item]
            if meta.get("stadium"):
                clip, vs, ve, words = stadium_clip(goal_only=opts.get("goal", False))
            else:
                clip, vs, ve, words = prepared_clip(meta["clip"], texts, heard_all, opts.get("words"), opts.get("lead_trim", False), opts.get("tail"))
        else:
            clip, vs, ve, words = prepared_clip(narr[kind], texts, heard_all)
        pace = "fast" if opts.get("fast") else kind
        start = 0.0 if not scenes else snap(t)
        clip_at = start + PRE[pace] - vs
        vo_start, vo_end = start + PRE[pace], start + PRE[pace] + (ve - vs)
        end = vo_end + POST[pace]
        if kind == "cta":
            end = max(end, vo_end + 2.9)
        scene = dict(kind=kind, id=item, start=round(start, 3), end=round(end, 3), vo_start=round(vo_start, 3), vo_end=round(vo_end, 3),
                     clip_at=clip_at, words=[dict(w=w["w"], s=None if w["s"] is None else round(w["s"] + clip_at, 3),
                                                  e=None if w["e"] is None else round(w["e"] + clip_at, 3)) for w in words])
        if kind == "speaker":
            meta = CAST[item]
            tr_key = "tr_ar" if ui == "ar" else "tr_en"
            foreign = meta["lang"] != ui
            scene.update(name=meta["name_ar" if ui == "ar" else "name_en"], dialect=meta["dialect_ar" if ui == "ar" else "dialect_en"],
                         avatar=meta["avatar"], flag=meta["flag"], hue=meta["hue"], lang=meta["lang"], stadium=bool(meta.get("stadium")),
                         fast=bool(opts.get("fast")), id=item,
                         chip=meta.get("chip_ar" if ui == "ar" else "chip_en"), chip_icon=meta.get("chip_icon"),
                         tr=(opts.get(tr_key) or meta.get(tr_key)) if foreign else None)
        scene["_clip"] = clip
        scenes.append(scene)
        t = end

    duration = round(scenes[-1]["end"], 3)

    # Fill in caption times that the transcript could not give.
    for sc in scenes:
        ws = sc["words"]
        if ws and any(w["s"] is None for w in ws):
            span = (sc["vo_end"] - sc["vo_start"]) / len(ws)
            for i, w in enumerate(ws):
                w["s"], w["e"] = round(sc["vo_start"] + i * span, 3), round(sc["vo_start"] + (i + 1) * span, 3)

    # The reveal's impact lands on "None" / "ولا".
    reveal = next(s for s in scenes if s["kind"] == "reveal")
    impact_word = next((w for w in reveal["words"] if w["w"].startswith(("ولا", "None"))), reveal["words"][min(1, len(reveal["words"]) - 1)])
    reveal["impact"] = impact_word["s"]
    cta = next(s for s in scenes if s["kind"] == "cta")
    brand = next((w for w in cta["words"] if w["w"].startswith(("منصة", "Listen"))), cta["words"][-1])
    cta["logo_at"] = round(brand["s"] - 0.1, 3)

    # ── Voice track ──
    n = int((duration + 1.0) * SR)
    vo = np.zeros((n, 2))
    for sc in scenes:
        sound.add(vo, sc.pop("_clip"), sc["clip_at"], 1.0)

    # ── Music and sound design ──
    first_speaker = next(s for s in scenes if s["kind"] == "speaker")
    switch = next((s for s in scenes if s["kind"] == "switch"), None)
    if switch:
        middle = [(first_speaker["start"], switch["start"], "groove"), (switch["start"], switch["end"], "intro"), (switch["end"], reveal["start"], "lift")]
    else:
        half = snap((first_speaker["start"] + reveal["start"]) / 2)
        middle = [(first_speaker["start"], half, "groove"), (half, reveal["start"], "lift")]
    sections = [
        (0, first_speaker["start"], "intro"),
        *middle,
        (reveal["start"], reveal["impact"], "hold"),
        (reveal["impact"], cta["start"], "lift"),
        (cta["start"], duration - 1.6, "outro"),
    ]
    music = sound.music(duration + 0.5, sections)
    music = music[:n] if len(music) >= n else np.pad(music, ((0, n - len(music)), (0, 0)))
    sfx = np.zeros((n, 2))

    hook = scenes[0]
    tiles = [CAST[sc["id"]]["avatar"] for sc in scenes if sc["kind"] == "speaker"]
    tile_times = [round(0.12 + i * 0.07, 3) for i in range(len(tiles))]
    for i, tt in enumerate(tile_times):
        sound.add(sfx, sound.pop(pitch=0.9 + 0.05 * (i % 5), pan=((i % 4) - 1.5) / 2.5), tt, 0.22)
    sound.add(sfx, sound.riser(1.2), first_speaker["start"] - 1.2, 0.1)
    for i, sc in enumerate(scenes[1:], start=1):
        if sc["kind"] in ("speaker", "switch", "cta"):
            sound.add(sfx, sound.whoosh(0.5, pan_from=0.7 if i % 2 else -0.7, pan_to=-0.7 if i % 2 else 0.7), sc["start"] - 0.3, 0.2)
        if sc.get("stadium"):
            sound.add(sfx, sound.butter(sound.impact(0.6), "lowpass", 180), sc["vo_start"] - 0.05, 0.3)
    sound.add(sfx, sound.whoosh(0.7, rising=False), reveal["start"] - 0.4, 0.24)
    sound.add(sfx, sound.riser(1.4), reveal["impact"] - 1.4, 0.2)
    # The hit is mostly sub-bass so it lands under the narrator's words, not on them.
    sound.add(sfx, sound.butter(sound.impact(), "lowpass", 160), reveal["impact"] - 0.03, 0.75)
    sound.add(sfx, sound.butter(sound.impact(), "highpass", 160), reveal["impact"] - 0.03, 0.12)
    reveal_tiles = [round(reveal["impact"] + 0.12 + i * 0.05, 3) for i in range(len(tiles))]
    for i, tt in enumerate(reveal_tiles):
        sound.add(sfx, sound.pop(pitch=1.25 + 0.04 * (i % 6), pan=((i % 4) - 1.5) / 2.5), tt, 0.12)
    typing = [round(cta["start"] + 0.55 + k * 0.075 + (0.02 if k % 3 == 0 else 0), 3) for k in range(18)]
    for tt in typing:
        sound.add(sfx, sound.tick(), tt, 0.3)
    stats_at = round(cta["logo_at"] + 0.45, 3)
    sound.add(sfx, sound.pop(1.1), stats_at, 0.35)
    sound.add(sfx, sound.pop(1.3), stats_at + 0.25, 0.35)
    sound.add(sfx, sound.butter(sound.impact(0.55), "lowpass", 160), cta["logo_at"], 0.45)
    sound.add(sfx, sound.shimmer(), cta["vo_end"] - 0.05, 0.4)

    # ── Mix: music ducks under the voices ──
    def lufs_gain(x, target):
        import pyloudnorm as pyln
        meter = pyln.Meter(SR)
        loud = meter.integrated_loudness(x)
        return 10 ** ((target - loud) / 20)

    vo *= lufs_gain(vo, -16)
    music *= lufs_gain(music, -25)
    env = np.sqrt(np.maximum(sound.butter(vo.mean(axis=1) ** 2, "lowpass", 8), 0))
    env = np.clip(env / (np.percentile(env[env > 1e-4], 60) + 1e-9), 0, 1)
    # Slow release so the music comes back between lines, not between words.
    duck = np.empty_like(env)
    level = 0.0
    attack, release = np.exp(-1 / (0.03 * SR)), np.exp(-1 / (0.35 * SR))
    for i in range(0, len(env), 64):
        target = env[i]
        coef = attack if target > level else release
        level = target + (level - target) * coef ** 64
        duck[i:i + 64] = level
    music_gain = 1 - 0.55 * duck
    music_under = sound.butter(music, "bandstop", [1400, 3800], order=1) * 0.35 + music * 0.65
    music = music * (1 - duck)[:, None] * music_gain[:, None] + music_under * duck[:, None] * music_gain[:, None]
    sfx = sfx * (1 - 0.45 * duck)[:, None]
    mix = vo + music + sfx * 0.9
    stems = pathlib.Path(out_dir) / cut_name / "stems"
    stems.mkdir(parents=True, exist_ok=True)
    for name, x in (("vo", vo), ("music", music), ("sfx", sfx * 0.9)):
        sf.write(stems / f"{name}.wav", x.astype(np.float32), SR, subtype="FLOAT")
    mix = mix[: int((duration + 0.2) * SR)]
    tail = int(0.5 * SR)
    mix[-tail:] *= np.linspace(1, 0, tail)[:, None]

    out = pathlib.Path(out_dir) / cut_name
    out.mkdir(parents=True, exist_ok=True)
    sf.write(out / "premaster.wav", mix.astype(np.float32), SR, subtype="FLOAT")
    # Master: -14 LUFS, true peak -1 dBTP (social video).
    subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", str(out / "premaster.wav"), "-af",
                    "alimiter=limit=0.89:attack=3:release=60:level=false,loudnorm=I=-14:TP=-1.2:LRA=9:linear=true",
                    "-ar", str(SR), "-c:a", "pcm_s16le", str(out / "mix.wav")], check=True)

    # ── Visual timeline: voice level per frame ──
    frames = int(np.ceil(duration * FPS)) + 1
    vmono = np.abs(vo.mean(axis=1))
    win = int(SR / FPS)
    amp = np.array([np.sqrt(np.mean(vmono[i * win:(i + 1) * win] ** 2)) if i * win < len(vmono) else 0 for i in range(frames)])
    amp = amp / (np.percentile(amp[amp > 1e-4], 92) + 1e-9)
    smooth = np.zeros_like(amp)
    for i in range(frames):
        prev = smooth[i - 1] if i else 0
        smooth[i] = amp[i] if amp[i] > prev else prev * 0.72 + amp[i] * 0.28
    beats = [round(b * sound.BEAT, 3) for b in range(int(duration / sound.BEAT) + 1)]

    timeline = dict(cut=cut_name, ui=ui, ratio=ratio, fps=FPS, duration=duration, scenes=scenes,
                    amp=[round(float(min(a, 1.6)), 3) for a in smooth], beats=beats, tiles=tiles,
                    tile_times=tile_times, reveal_tiles=reveal_tiles, typing=typing, stats_at=stats_at,
                    tile_lang={CAST[k]["avatar"]: CAST[k]["lang"] for k in CAST},
                    tile_flag={CAST[k]["avatar"]: CAST[k]["flag"] for k in CAST})
    (out / "timeline.js").write_text("window.TIMELINE = " + json.dumps(timeline, ensure_ascii=False) + ";\n", encoding="utf-8")

    # ── Page and assets ──
    for name in ("ad.html", "ad.js", "ad.css"):
        shutil.copy(HERE / name, out / name)
    shutil.copytree(HERE / "fonts", out / "fonts", dirs_exist_ok=True)
    assets = out / "assets"
    assets.mkdir(exist_ok=True)
    for k, meta in CAST.items():
        target = assets / f"{meta['avatar']}.png"
        if not target.exists():
            im = Image.open(ROOT / f"public/images/speakers/{meta['avatar']}.webp").convert("RGB")
            im = im.resize((768, 768), Image.LANCZOS).filter(ImageFilter.UnsharpMask(radius=2, percent=60, threshold=2))
            im.save(target)
        shutil.copy(ROOT / f"public/images/flags/{meta['flag']}.svg", assets / f"{meta['flag']}.svg")
    for logo in ("logo-ar-dark.svg", "logo-dark.svg", "icon-128.svg"):
        shutil.copy(ROOT / f"public/images/{logo}", assets / logo)
    grain = assets / "grain.png"
    if not grain.exists():
        noise = (np.random.default_rng(3).normal(128, 42, (512, 512))).clip(0, 255).astype(np.uint8)
        Image.fromarray(noise, "L").save(grain)

    print(f"{cut_name}: {duration:.2f}s, {len(scenes)} scenes -> {out}")
    for sc in scenes:
        print(f"  {sc['start']:6.2f}-{sc['end']:6.2f} {sc['kind']:8s} {sc['id']:9s} vo {sc['vo_start']:.2f}-{sc['vo_end']:.2f}  " +
              " ".join(w["w"] for w in sc["words"]))


if __name__ == "__main__":
    cut_name = sys.argv[1]
    out_dir = sys.argv[2]
    ratio = sys.argv[sys.argv.index("--ratio") + 1] if "--ratio" in sys.argv else "9x16"
    build(cut_name, out_dir, ratio)
