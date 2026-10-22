"""Builds one cut of the emotions ad: the voice track (whole, or the short
cut's takes joined), music and sound design, and the timeline the motion
graphics page plays. One line said again and again; every take is a new
emotion and a new dialect, shown by a speaker from that dialect.

    python build.py <cut: ar|en> <length: full|short> <out dir> [--ratio 9x16|16x9]

Output (in <out dir>/<cut>-<length>-<ratio>): timeline.js, mix.wav, the page and its assets.
"""

import json
import re
import pathlib
import shutil
import subprocess
import sys

import numpy as np
import soundfile as sf
from PIL import Image, ImageFilter

sys.dont_write_bytecode = True  # no __pycache__ next to the scripts this one borrows from

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parents[2]
PROMPTS = ROOT / "marketing/prompts-film/render"
sys.path.insert(0, str(ROOT / "marketing/launch-ad/render"))
sys.path.insert(0, str(PROMPTS))
import sound  # noqa: E402  (the launch ad's synth: music, whooshes, ticks)
from sound import SR  # noqa: E402
import orbshader  # noqa: E402  (the prompts films' orb)

TRACK = HERE.parent / "track"
FPS = 60
LEAD = 0.55  # seconds of picture before the first take

# Each take is shown by a site speaker from that dialect (their avatar's dress
# fits it): name in Arabic and English, and the avatar file.
CAST = {
    "emo-ar-sa": ("سلطان", "Sultan", "ar-sultan"), "emo-ar-lb": ("رائد", "Raed", "ar-raed"),
    "emo-ar-eg": ("علاء", "Alaa", "ar-alaa"), "emo-ar-iq": ("فيصل", "Faisal", "ar-faisal"),
    "emo-ar-ma": ("منصور", "Mansour", "ar-mansour"), "emo-ar-ae": ("نواف", "Nawaf", "ar-nawaf"),
    "emo-ar-tn": ("عثمان", "Othman", "ar-othman"), "emo-ar-kw": ("سعود", "Saud", "ar-saud"),
    "emo-ar-en": ("كاليب", "Caleb", "en-caleb"),
    "emo-en-us": ("ديزي", "Daisy", "en-daisy"), "emo-en-gb": ("أليس", "Alice", "en-alice"),
    "emo-en-au": ("فريا", "Freya", "en-freya"), "emo-en-ie": ("سيينا", "Sienna", "en-sienna"),
    "emo-en-ny": ("ويلو", "Willow", "en-willow"), "emo-en-za": ("فيبي", "Phoebe", "en-phoebe"),
    "emo-en-ca": ("روبي", "Ruby", "en-ruby"), "emo-en-in": ("لونا", "Luna", "en-luna"),
    "emo-en-eg": ("سمية", "Sumaya", "ar-sumaya"),
}
# Each take's flag and the colour of the light behind the face (by emotion).
FLAGS = {"ar-SA": "sa", "ar-LB": "lb", "ar-EG": "eg", "ar-IQ": "iq", "ar-MA": "ma", "ar-AE": "ae", "ar-TN": "tn",
         "ar-KW": "kw", "en-US": "us", "en-GB": "gb", "en-AU": "au", "en-IE": "ie", "en-US-NY": "us", "en-ZA": "za",
         "en-CA": "ca", "en-IN": "in"}
HUES = {"Neutral": 255, "Whisper": 205, "Laugh": 42, "Sob": 220, "Shout": 8, "Sarcastic": 290,
        "Movie trailer": 25, "Excited": 330}
# More of the product's emotions, faded at both ends of the list, so it reads as a long menu.
EXTRA = {
    "ar": (["هادئ", "فخور", "خجول"], ["غاضب", "حنين", "واثق"]),
    "en": (["Calm", "Proud", "Shy"], ["Angry", "Nostalgic", "Confident"]),
}
# The short cut: the strongest takes, then the end line.
SHORT = {
    "ar": ["emo-ar-eg", "emo-ar-iq", "emo-ar-ma", "emo-ar-ae", "emo-ar-kw"],
    "en": ["emo-en-au", "emo-en-ie", "emo-en-ny", "emo-en-za", "emo-en-in"],
}
# Each cut stays in its own language: the Arabic cut's English take and the
# English cut's Arabic take are left out.
OTHER_LANGUAGE = {"emo-ar-en", "emo-en-eg"}
# Quiet deliveries get quieter music.
MUSIC_GAIN = {"Whisper": 0.45, "Sob": 0.55, "Movie trailer": 0.7}


def load(path):
    data, sr = sf.read(str(path), always_2d=True)
    if sr != SR:
        raw = subprocess.run(["ffmpeg", "-v", "error", "-i", str(path), "-ac", "2", "-ar", str(SR), "-f", "f32le", "-"],
                             capture_output=True, check=True).stdout
        data = np.frombuffer(raw, dtype=np.float32).reshape(-1, 2).astype(float)
    return np.repeat(data, 2, axis=1) if data.shape[1] == 1 else data


def _norm(word):
    import re
    w = re.sub(r"[ً-ْـ]", "", word)
    w = re.sub(r"[إأآا]", "ا", w).replace("ى", "ي").replace("ة", "ه")
    return re.sub(r"[^\w]", "", w.lower())


def script_words(text, heard):
    """The script's own words (what is shown), timed from the transcript: matching
    words take its times, the others share the time between their neighbours."""
    import difflib
    words = text.split()
    if not heard:
        return []
    times = [None] * len(words)
    sm = difflib.SequenceMatcher(a=[_norm(w) for w in words], b=[_norm(h["w"]) for h in heard], autojunk=False)
    for tag, i1, i2, j1, j2 in sm.get_opcodes():
        if tag == "equal" or (tag == "replace" and i2 - i1 == j2 - j1):
            for k in range(i2 - i1):
                times[i1 + k] = (heard[j1 + k]["start"], heard[j1 + k]["end"])
    i = 0
    while i < len(words):
        if times[i] is not None:
            i += 1
            continue
        j = i
        while j < len(words) and times[j] is None:
            j += 1
        lo = times[i - 1][1] if i else heard[0]["start"] - 0.06 * sum(len(w) for w in words[i:j])
        hi = times[j][0] if j < len(words) else heard[-1]["end"]
        total, cur = sum(len(w) for w in words[i:j]) or 1, lo
        for k in range(i, j):
            d = (hi - lo) * len(words[k]) / total
            times[k] = (cur, cur + d)
            cur += d
        i = j
    return [dict(w=w, start=round(a, 3), end=round(max(b, a + 0.05), 3)) for w, (a, b) in zip(words, times)]


def voice_track(cut, length):
    """The voice and its takes, in this cut's time: the cut's takes joined, each
    with a little room around it, then the end line."""
    meta = json.loads((TRACK / f"emotions-{cut}.json").read_text(encoding="utf-8"))
    audio = load(TRACK / f"emotions-{cut}.wav")
    takes = [dict(t, words=script_words(t["text"], t["words"])) for t in meta["takes"]]
    *lines, end = takes
    pick = SHORT[cut] if length == "short" else [t["slug"] for t in lines if t["slug"] not in OTHER_LANGUAGE]
    pick = pick + [end["slug"]]
    # Silence between one take's voice and the next: as in the joined track for the
    # full cut, a little more for the short one; the end line always gets 0.9 s.
    between = 0.5 if length == "full" else 0.75
    by = {t["slug"]: t for t in takes}
    parts, placed, at = [np.zeros((int(LEAD * SR), 2))], [], LEAD
    for k, slug in enumerate(pick):
        t = by[slug]
        a, b = max(0.0, t["start"] - 0.2), min(len(audio) / SR, t["end"] + (0.45 if k == len(pick) - 1 else 0.25))
        seg = audio[int(a * SR): int(b * SR)].copy()
        f = int(0.015 * SR)
        seg[:f] *= np.linspace(0, 1, f)[:, None]
        seg[-f * 3:] *= np.linspace(1, 0, f * 3)[:, None]
        move = lambda x: round(x - a + at, 3)  # noqa: E731
        placed.append(dict(t, start=move(t["start"]), end=move(t["end"]),
                           words=[dict(w, start=move(w["start"]), end=move(w["end"])) for w in t["words"]]))
        parts.append(seg)
        at += len(seg) / SR
        gap = (0.9 if k == len(pick) - 2 else between) - 0.45
        parts.append(np.zeros((int(gap * SR), 2)))
        at += gap
    return np.concatenate(parts), placed


def build(cut, length, out_dir, ratio):
    vo, takes = voice_track(cut, length)
    *lines, end = takes

    scenes = []
    for i, t in enumerate(lines):
        dialect_ar, emo_ar = t["label_ar"].split(" · ")
        dialect_en, emo_en = t["label_en"].split(" · ")
        name_ar, name_en, avatar = CAST[t["slug"]]
        if t["dialect"] == "en-US" and dialect_en == "English":
            dialect_en = "American"
        # A take is labelled in the language it is spoken in: an Arabic take in the
        # English cut reads «سمية · مصري · حماس», an English take in the Arabic cut
        # "Caleb · American · Excited".
        lang = t["dialect"][:2]
        scenes.append(dict(slug=t["slug"], name=name_ar if lang == "ar" else name_en, avatar=avatar, lang=lang, flag=FLAGS[t["dialect"]], hue=HUES.get(emo_en, 255),
                           dialect=dialect_ar if lang == "ar" else dialect_en, emotion=emo_ar if lang == "ar" else emo_en,
                           emo_key=emo_en, vo_start=t["start"], vo_end=t["end"],
                           words=[dict(w=w["w"], s=w["start"], e=w["end"]) for w in t["words"]]))
    # A take's picture starts a little before its voice and lasts until the next one starts.
    for i, sc in enumerate(scenes):
        sc["start"] = round(LEAD - 0.4 if i == 0 else sc["vo_start"] - 0.32, 3)
    for i, sc in enumerate(scenes):
        sc["end"] = round(scenes[i + 1]["start"] if i + 1 < len(scenes) else end["start"] - 0.35, 3)
    cta = dict(start=round(end["start"] - 0.35, 3), vo_start=end["start"], vo_end=end["end"],
               words=[dict(w=w["w"], s=w["start"], e=w["end"]) for w in end["words"]])
    brand = next((w for w in cta["words"] if w["w"].startswith(("إسمع", "Listen"))), cta["words"][-1])
    cta["logo_at"] = round(brand["s"] - 0.05, 3)
    # The end line is shown up to the brand ("…منصة إسمع" / "…Listen"); the logo says the rest.
    k = next((j for j, w in enumerate(cta["words"]) if w["w"].startswith(("منصة", "Listen", "إسمع"))), len(cta["words"]))
    cta["text_words"] = k
    cta["text_out"] = round(cta["words"][k]["s"] if k < len(cta["words"]) else cta["logo_at"], 3)
    # The end, as in the prompts films: the portrait becomes the Listen orb, the orb
    # fills the frame behind the end line, then shrinks back and splits into the
    # logo's bars as the brand is said; the wordmark and the address follow.
    cta["orb_at"] = round(cta["start"] - 0.2, 3)
    cta["contract_at"] = round(cta["logo_at"] - 0.95, 3)
    duration = round(max(end["end"] + 2.6, cta["logo_at"] + 3.4), 3)
    n = int((duration + 1.0) * SR)
    vo = np.pad(vo, ((0, max(0, n - len(vo))), (0, 0)))[:n]

    # ── Music: a calm bed under the takes, the groove for the end line ──
    sections = [(0, cta["start"] - 0.5, "intro"), (cta["start"] - 0.5, cta["start"] + 0.1, "hold"),
                (cta["start"] + 0.1, duration - 1.4, "outro")]
    music = sound.music(duration + 0.5, sections)
    music = music[:n] if len(music) >= n else np.pad(music, ((0, n - len(music)), (0, 0)))
    g = np.ones(n)
    for sc in scenes:
        k = MUSIC_GAIN.get(sc["emo_key"])
        if k:
            a, b, r = int((sc["vo_start"] - 0.3) * SR), int((sc["vo_end"] + 0.25) * SR), int(0.3 * SR)
            g[a:b] = np.minimum(g[a:b], k)
            g[a - r:a] = np.minimum(g[a - r:a], np.linspace(1, k, r))
            g[b:b + r] = np.minimum(g[b:b + r], np.linspace(k, 1, r))
    music *= g[:, None]

    # ── Sound design: a tick as the list steps, air into the end card ──
    sfx = np.zeros((n, 2))
    for i, sc in enumerate(scenes[1:], start=1):
        sound.add(sfx, sound.tick(1.2), sc["start"] + 0.05, 0.5)
        sound.add(sfx, sound.pop(pitch=1.0 + 0.04 * (i % 5), pan=0.25 if i % 2 else -0.25), sc["start"] + 0.12, 0.05)
    sound.add(sfx, sound.whoosh(0.6, pan_from=0.4, pan_to=-0.4), cta["start"] - 0.35, 0.12)
    sound.add(sfx, sound.whoosh(0.7, rising=False, pan_from=-0.3, pan_to=0.3), cta["contract_at"] - 0.1, 0.12)
    typing = [round(w["s"], 3) for w in cta["words"]]
    sound.add(sfx, sound.butter(sound.impact(0.5), "lowpass", 150), cta["logo_at"], 0.32)
    sound.add(sfx, sound.shimmer(), cta["logo_at"] + 0.1, 0.25)

    # ── Mix: music ducks under the voice ──
    def lufs_gain(x, target):
        import pyloudnorm as pyln
        return 10 ** ((target - pyln.Meter(SR).integrated_loudness(x)) / 20)

    vo *= lufs_gain(vo, -16)
    music *= lufs_gain(music, -27)
    env = np.sqrt(np.maximum(sound.butter(vo.mean(axis=1) ** 2, "lowpass", 8), 0))
    env = np.clip(env / (np.percentile(env[env > 1e-4], 60) + 1e-9), 0, 1)
    duck = np.empty_like(env)
    level, attack, release = 0.0, np.exp(-1 / (0.03 * SR)), np.exp(-1 / (0.4 * SR))
    for i in range(0, len(env), 64):
        target = env[i]
        level = target + (level - target) * (attack if target > level else release) ** 64
        duck[i:i + 64] = level
    music_under = sound.butter(music, "bandstop", [1400, 3800], order=1) * 0.35 + music * 0.65
    gain = 1 - 0.55 * duck
    music = (music * (1 - duck)[:, None] + music_under * duck[:, None]) * gain[:, None]
    mix = vo + music + sfx * (1 - 0.45 * duck)[:, None] * 0.9
    mix = mix[: int((duration + 0.2) * SR)]
    mix[-int(0.5 * SR):] *= np.linspace(1, 0, int(0.5 * SR))[:, None]

    out = pathlib.Path(out_dir) / f"{cut}-{length}-{ratio}"
    out.mkdir(parents=True, exist_ok=True)
    sf.write(out / "premaster.wav", mix.astype(np.float32), SR, subtype="FLOAT")
    subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", str(out / "premaster.wav"), "-af",
                    "alimiter=limit=0.89:attack=3:release=60:level=false,loudnorm=I=-14:TP=-1.2:LRA=9:linear=true",
                    "-ar", str(SR), "-c:a", "pcm_s16le", str(out / "mix.wav")], check=True)

    # ── Voice level per frame, for the ring and the waves ──
    frames = int(np.ceil(duration * FPS)) + 1
    vmono, win = np.abs(vo.mean(axis=1)), int(SR / FPS)
    amp = np.array([np.sqrt(np.mean(vmono[i * win:(i + 1) * win] ** 2)) if i * win < len(vmono) else 0 for i in range(frames)])
    amp = amp / (np.percentile(amp[amp > 1e-4], 92) + 1e-9)
    smooth = np.zeros_like(amp)
    for i in range(frames):
        prev = smooth[i - 1] if i else 0
        smooth[i] = prev + (amp[i] - prev) * (0.45 if amp[i] > prev else 0.12)

    timeline = dict(cut=cut, length=length, ui=cut, ratio=ratio, fps=FPS, duration=duration,
                    scenes=scenes, cta=cta, extra=EXTRA[cut], typing=typing,
                    amp=[round(float(min(a, 1.6)), 3) for a in smooth])
    (out / "timeline.js").write_text("window.TIMELINE = " + json.dumps(timeline, ensure_ascii=False) + ";\n", encoding="utf-8")

    # ── Page and assets ──
    for name in ("ad.html", "ad.js", "ad.css"):
        shutil.copy(HERE / name, out / name)
    # The Listen orb: the site's shader, drawn as in the prompts films.
    shutil.copy(PROMPTS / "orb.js", out / "orb.js")
    orbshader.write(ROOT, out)
    shutil.copytree(ROOT / "marketing/launch-ad/render/fonts", out / "fonts", dirs_exist_ok=True)
    assets = out / "assets"
    assets.mkdir(exist_ok=True)
    for sc in scenes:
        target = assets / f"{sc['avatar']}.png"
        if not target.exists():
            im = Image.open(ROOT / f"public/images/speakers/{sc['avatar']}.webp").convert("RGB")
            im.resize((900, 900), Image.LANCZOS).filter(ImageFilter.UnsharpMask(radius=2, percent=60, threshold=2)).save(target)
        shutil.copy(ROOT / f"public/images/flags/{sc['flag']}.svg", assets / f"{sc['flag']}.svg")
    for logo in ("logo-ar-light.svg", "logo-light.svg", "icon-128.svg"):
        shutil.copy(ROOT / f"public/images/{logo}", assets / logo)
    for logo in ("logo-ar-light.svg", "logo-light.svg"):
        # The wordmark alone: the bars are drawn by the page so they can come out of the orb.
        svg = (ROOT / f"public/images/{logo}").read_text(encoding="utf-8")
        (assets / logo.replace("logo", "wordmark")).write_text(re.sub(r"<g transform.*?</g>", "", svg, flags=re.S), encoding="utf-8")
    grain = assets / "grain.png"
    if not grain.exists():
        noise = np.random.default_rng(3).normal(128, 42, (512, 512)).clip(0, 255).astype(np.uint8)
        Image.fromarray(noise, "L").save(grain)

    print(f"{out.name}: {duration:.2f}s")
    for sc in scenes:
        print(f"  {sc['start']:6.2f}-{sc['end']:6.2f} {sc['dialect']} · {sc['emotion']}  " + " ".join(w["w"] for w in sc["words"]))
    print(f"  {cta['start']:6.2f}-{duration:6.2f} end  " + " ".join(w["w"] for w in cta["words"]))


if __name__ == "__main__":
    cut, length, out_dir = sys.argv[1:4]
    ratio = sys.argv[sys.argv.index("--ratio") + 1] if "--ratio" in sys.argv else "9x16"
    build(cut, length, out_dir, ratio)
