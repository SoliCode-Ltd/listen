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
FPS = 60

# Every speaker is a Listen HD voice with its product name and avatar.
CAST = {
    "fahad": dict(name_en="Fahad", name_ar="فهد", avatar="ar-fahad", flag="sa", clip="ad-ar-fahad", lang="ar",
                  dialect_en="Saudi · Najdi", dialect_ar="سعودي · نجدي", hue=265,
                  tr_en="Me? Of course I'm real! What more do you want?", emo_ar="واثق", emo_en="Confident"),
    "sumaya": dict(name_en="Sumaya", name_ar="سمية", avatar="ar-sumaya", flag="eg", clip="ad-ar-sumaya", lang="ar",
                   dialect_en="Egyptian · Cairo", dialect_ar="مصري · قاهري", hue=330,
                   tr_en="Seriously?! No way, I'm the real one!", emo_ar="مندهش", emo_en="Surprised"),
    "sawsan": dict(name_en="Sawsan", name_ar="سوسن", avatar="ar-sawsan", flag="lb", clip="ad-ar-sawsan", lang="ar",
                   dialect_en="Lebanese · Beirut", dialect_ar="لبناني · بيروتي", hue=195,
                   tr_en="Honestly? None of you can tell us apart.", emo_ar="غامض", emo_en="Mysterious"),
    "nawaf": dict(name_en="Nawaf", name_ar="نواف", avatar="ar-nawaf", flag="ae", clip="ad-ar-nawaf", lang="ar",
                  dialect_en="Emirati", dialect_ar="إماراتي", hue=160,
                  tr_en="I'm 100% Emirati… what more do you want?", emo_ar="فخور", emo_en="Proud"),
    "aisha": dict(name_en="Aisha", name_ar="عائشة", avatar="ar-aisha", flag="ma", clip="ad-ar-aisha", lang="ar",
                  dialect_en="Moroccan · Darija", dialect_ar="مغربي · دارجة", hue=15,
                  tr_en="Can you hear me well? This is real Moroccan Darija!", emo_ar="متحمس", emo_en="Excited"),
    "mansour": dict(name_en="Mansour", name_ar="منصور", avatar="ar-mansour", flag="iq", clip="ad-ar-mansour", lang="ar",
                    dialect_en="Iraqi · Baghdad", dialect_ar="عراقي · بغدادي", hue=210, emo_ar="حزين", emo_en="Sad",
                    tr_en="Why does nobody believe me? I swear I'm real…"),
    "daisy": dict(name_en="Daisy", name_ar="ديزي", avatar="en-daisy", flag="us", clip="ad-en-daisy", lang="en",
                  dialect_en="American", dialect_ar="أمريكية", hue=220,
                  tr_ar="حقيقية؟ يا عزيزي، أنا أمريكية حتى النخاع!", emo_ar="ساخر", emo_en="Sarcastic"),
    "theodore": dict(name_en="Theodore", name_ar="ثيودور", avatar="en-theodore", flag="gb", clip="ad-en-theodore", lang="en",
                     dialect_en="British", dialect_ar="بريطاني", hue=245,
                     tr_ar="أؤكد لك… أنا بريطاني جداً.", emo_ar="هادئ", emo_en="Calm"),
    "finn": dict(name_en="Finn", name_ar="فين", avatar="en-finn", flag="au", clip="ad-en-finn", lang="en",
                 dialect_en="Australian", dialect_ar="أسترالي", hue=40,
                 tr_ar="بجد يا صاحبي، حقيقي مية بالمية!", emo_ar="مرح", emo_en="Playful"),
    "luna": dict(name_en="Luna", name_ar="لونا", avatar="en-luna", flag="in", clip="ad-en-luna", lang="en",
                 dialect_en="Indian", dialect_ar="هندية", hue=300,
                 tr_ar="يا جماعة، أكيد أنا حقيقية! بس اسمعوا!", emo_ar="مرح", emo_en="Playful"),
    "charlie": dict(name_en="Charlie", name_ar="تشارلي", avatar="en-charlie", flag="ng", clip="ad-en-charlie", lang="en",
                    dialect_en="Nigerian", dialect_ar="نيجيري", hue=120,
                    tr_ar="أنا الحقيقي الوحيد هنا!", emo_ar="غاضب", emo_en="Angry"),
}

# The homepage sound environments (public/audio/environments), cut to one phrase.
# Every one starts on the clean voice and switches into the place on word ab.
ENVS = {
    "env-ar-radio": dict(lang="ar", icon="radio", hue=35, words=(0, 4), ab=3,
                         place_ar="راديو قديم", scene_ar="نشرة إذاعية من الماضي · وليد"),
    "env-ar-phone": dict(lang="ar", icon="phone", hue=150, words=(0, 7), ab=4,
                         place_ar="مكالمة هاتفية", scene_ar="سلمى · مصري"),
    "env-ar-cave": dict(lang="ar", icon="cave", hue=260, words=(0, 4), ab=3, ring=0.9,
                        place_ar="كهف", scene_ar="مغامرة في كهف · فارس"),
    "env-en-radio": dict(lang="en", icon="radio", hue=35, words=(0, 9), ab=5,
                         place_en="Old radio", scene_en="Radio bulletin from the past · Walter"),
    "env-en-phone": dict(lang="en", icon="phone", hue=150, words=(0, 9), ab=3,
                         place_en="Phone call", scene_en="Chloe · American"),
    "env-en-cave": dict(lang="en", icon="cave", hue=260, words=(0, 8), ab=4, ring=0.9,
                        place_en="Cave", scene_en="Cave expedition · Oliver"),
}
# Delivery tags as the product names them, in the voice's own language.
TAGS = {"laugh": ("ضحكة", "Laugh"), "chuckle": ("ضحكة خفيفة", "Chuckle"), "gasp": ("شهقة", "Gasp"), "whispers": ("همس", "Whisper"),
        "sigh": ("تنهيدة", "Sigh"), "sob": ("نحيب", "Sob"), "snicker": ("ضحكة ساخرة", "Snicker"), "giggle": ("قهقهة", "Giggle"),
        "shout": ("صياح", "Shout")}
# The language switch shows the accents and dialects of the language that follows.
SWITCH_FLAGS = {
    # Two rows; the middle of each row is the most visible, the ends fade out.
    "en": ["jm", "ie", "nz", "gb", "us", "au", "ca", "za", "ug", "tt", "ke", "gh", "in", "ng", "ph", "gb-sct", "sg", "pk"],
    "ar": ["jo", "kw", "ma", "eg", "sa", "ae", "lb", "qa", "om", "bh", "ly", "tn", "iq", "sy", "dz", "ps", "sd", "ye"],
}

# Two full lines set up the joke, then a fast montage of one phrase each.
# words=(from, to) picks script words (inclusive); fast=True is a quick cut.
F = dict(
    sawsan=("sawsan", dict(fast=True)),
    nawaf=("nawaf", dict(words=(0, 3), fast=True, tr_en="I'm 100% Emirati…")),
    aisha=("aisha", dict(words=(3, None), fast=True, tr_en="This is real Moroccan Darija!")),
    theodore=("theodore", dict(fast=True)),
    finn=("finn", dict(fast=True)),
    luna=("luna", dict(words=(0, 5), fast=True, tr_ar="يا جماعة، أكيد أنا حقيقية!")),
    mansour=("mansour", dict(fast=True)),
    charlie=("charlie", dict(fast=True, tail=0.35)),
)
AR_ENVS = ["env-ar-radio", "env-ar-phone", "env-ar-cave"]
EN_ENVS = ["env-en-radio", "env-en-phone", "env-en-cave"]
CUTS = {
    "ar": dict(ui="ar", narrator="ad-ar", order=[
        "hook", "fahad", "sumaya", F["mansour"], F["sawsan"], F["aisha"], "switch", "daisy", F["luna"], F["charlie"],
        "reveal", "envintro", *AR_ENVS, "cta"]),
    "en": dict(ui="en", narrator="ad-en", order=[
        "hook", "daisy", "theodore", F["luna"], F["charlie"], "switch", "fahad", "sumaya", F["mansour"], F["sawsan"],
        "reveal", "envintro", *EN_ENVS, "cta"]),
    # About 30 seconds, for paid placements.
    "ar30": dict(ui="ar", narrator="ad-ar", order=[
        "hook", "sumaya", F["mansour"], F["sawsan"], "reveal", AR_ENVS[0], "cta"]),
    "en30": dict(ui="en", narrator="ad-en", order=[
        "hook", "daisy", F["luna"], F["mansour"], "reveal", EN_ENVS[0], "cta"]),
    # Design preview with the takes that exist now.
    "preview": dict(ui="ar", narrator="ad-ar", order=[
        "hook", "fahad", "theodore", "reveal", "envintro", *AR_ENVS, "cta"]),
}

NARRATION = {
    "ad-ar": dict(hook="ad-ar-hook", switch="ad-ar-english", reveal="ad-ar-reveal", envintro="ad-ar-env", cta="ad-ar-cta"),
    "ad-en": dict(hook="ad-en-hook", switch="ad-en-arabic", reveal="ad-en-reveal", envintro="ad-en-env", cta="ad-en-cta"),
}

# Seconds before the voice starts and after it ends, per scene kind.
PRE = dict(hook=0.55, speaker=0.32, fast=0.22, switch=0.22, reveal=0.4, envintro=0.28, env=0.32, cta=0.4)
POST = dict(hook=0.25, speaker=0.18, fast=0.08, switch=0.1, reveal=0.45, envintro=0.15, env=0.32, cta=2.5)
GRID = 0.125  # a sixteenth at 120 BPM: every scene starts on the music grid


def lines_text():
    php = ("echo json_encode(array_column(array_merge((require 'marketing/launch-ad/lines.php')['samples'], "
           "(require 'database/seeders/data/environment_samples.php')['samples']), 'text', 'slug'), JSON_UNESCAPED_UNICODE);")
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


def script_tokens(script):
    """The script as words and delivery tags, in order: [("w", i) | ("tag", name)]."""
    import re
    out, i = [], 0
    for part in re.split(r"(<[^<>]+>)", script):
        if part.startswith("<"):
            out.append(("tag", part.strip("<>").strip()))
        else:
            for _ in part.split():
                out.append(("w", i))
                i += 1
    return out


def prepared_clip(slug, texts, heard_all, word_range=None, lead_trim=False, tail=None, audio=None):
    audio = load_audio(VOICES / f"{slug}.wav") if audio is None else audio
    s, e = speech_span(audio)
    heard = (heard_all.get(slug) or {}).get("words") or []
    words = caption_words(texts.get(slug, ""), heard, 0.0, s)
    if any(w["s"] is None for w in words):
        span = (e - s) / max(1, len(words))
        for i, w in enumerate(words):
            w["s"], w["e"] = s + i * span, s + (i + 1) * span
    toks = script_tokens(texts.get(slug, ""))
    lo, hi = word_range or (0, None)
    last = len(words) - 1 if hi is None else hi
    tokens, seen = [], 0
    for kind, v in toks:
        if kind == "w":
            if lo <= v <= last:
                tokens.append(dict(word=v - lo))
            seen += 1
        # A tag sits between word seen-1 and word seen: keep it inside the cut,
        # or at an edge only when the cut reaches that edge of the line.
        elif lo < seen <= last or (seen == lo == 0) or (seen == last + 1 and hi is None):
            tokens.append(dict(tag=v))
    if word_range:
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
    return clip, s - a, e - a, words, tokens


def env_clip(slug, meta, texts, heard_all):
    """A homepage environment line: placed in its environment, or (ab) clean
    first and then switched into the place at the same moment of the take."""
    clean = load_audio(ROOT / f"public/audio/environments/clean/{slug}.mp3")
    placed = load_audio(ROOT / f"public/audio/environments/{slug}.mp3")
    # Align the placed take to the clean one (effects can add a little delay).
    a_env = np.abs(clean[: SR * 4].mean(axis=1)); b_env = np.abs(placed[: SR * 4].mean(axis=1))
    k = 48
    a_env = a_env[: len(a_env) // k * k].reshape(-1, k).mean(axis=1); b_env = b_env[: len(b_env) // k * k].reshape(-1, k).mean(axis=1)
    m = min(len(a_env), len(b_env))
    corr = [np.dot(a_env[: m - 60], b_env[d: d + m - 60]) for d in range(0, 60)]
    offset = int(np.argmax(corr)) * k
    placed = placed[offset:]
    n = max(len(clean), len(placed))
    clean = np.pad(clean, ((0, n - len(clean)), (0, 0))); placed = np.pad(placed, ((0, n - len(placed)), (0, 0)))
    words_all = caption_words(texts.get(slug, ""), (heard_all.get(slug) or {}).get("words") or [], 0.0, speech_span(clean)[0])
    if any(w["s"] is None for w in words_all):
        s0, e0 = speech_span(clean)
        span = (e0 - s0) / len(words_all)
        for i, w in enumerate(words_all):
            w["s"], w["e"] = s0 + i * span, s0 + (i + 1) * span
    if meta.get("ab") is not None:
        switch_at = words_all[meta["ab"]]["s"] - 0.06
        x = int(switch_at * SR); fade = int(0.07 * SR)
        ramp = np.clip((np.arange(n) - x) / fade, 0, 1)[:, None]
        audio = clean * (1 - ramp) + placed * ramp
    else:
        switch_at = None
        audio = placed
    clip, vs, ve, words, tokens = prepared_clip(slug, texts, heard_all, meta["words"], audio=audio)
    # prepared_clip trimmed from a = max(0, s - 0.04); recover that start to place the switch.
    lo = meta["words"][0]
    a = max(0.0, (words_all[lo]["s"] - 0.05 if lo else speech_span(audio)[0]) - 0.04)
    # Let the place ring out after the last word.
    end = int((words[-1]["e"] + a + meta.get("ring", 0.55)) * SR)
    clip = audio[int(a * SR): end].copy()
    f = int(0.012 * SR); clip[:f] *= np.linspace(0, 1, f)[:, None]
    f = int(0.3 * SR); clip[-f:] *= np.linspace(1, 0, f)[:, None]
    return clip, vs, ve, words, tokens, (None if switch_at is None else switch_at - a)


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
        kind = "speaker" if item in CAST else "env" if item in ENVS else item
        ab = None
        if kind == "speaker":
            meta = CAST[item]
            clip, vs, ve, words, tokens = prepared_clip(meta["clip"], texts, heard_all, opts.get("words"), opts.get("lead_trim", False), opts.get("tail"))
        elif kind == "env":
            meta = ENVS[item]
            clip, vs, ve, words, tokens, ab = env_clip(item, meta, texts, heard_all)
        else:
            clip, vs, ve, words, tokens = prepared_clip(narr[kind], texts, heard_all)
        pace = "fast" if opts.get("fast") else kind
        start = 0.0 if not scenes else snap(t)
        clip_at = start + PRE[pace] - vs
        vo_start, vo_end = start + PRE[pace], start + PRE[pace] + (ve - vs)
        end = vo_end + POST[pace]
        if kind == "cta":
            end = max(end, vo_end + 2.5)
        if kind == "env":
            end = max(end, vo_end + ENVS[item].get("ring", 0.55) - 0.15)  # the place rings out before the next scene
        scene = dict(kind=kind, id=item, start=round(start, 3), end=round(end, 3), vo_start=round(vo_start, 3), vo_end=round(vo_end, 3),
                     clip_at=clip_at, words=[dict(w=w["w"], s=None if w["s"] is None else round(w["s"] + clip_at, 3),
                                                  e=None if w["e"] is None else round(w["e"] + clip_at, 3)) for w in words])
        if kind == "speaker":
            meta = CAST[item]
            # The card speaks the voice's own language (name, accent, emotion, tags);
            # only the translation line is in the viewer's language.
            lang = meta["lang"]
            tr_key = "tr_ar" if ui == "ar" else "tr_en"
            scene.update(name=meta[f"name_{lang}"], dialect=meta[f"dialect_{lang}"],
                         avatar=meta["avatar"], flag=meta["flag"], hue=meta["hue"], lang=lang, fast=bool(opts.get("fast")),
                         emotion=meta.get(f"emo_{lang}"),
                         tokens=[dict(tag=TAGS.get(tk["tag"], (tk["tag"], tk["tag"]))[0 if lang == "ar" else 1], key=tk["tag"]) if "tag" in tk else tk
                                 for tk in tokens],
                         tr=(opts.get(tr_key) or meta.get(tr_key)) if lang != ui else None)
        elif kind == "env":
            meta = ENVS[item]
            scene.update(lang=meta["lang"], icon=meta["icon"], hue=meta["hue"], place=meta.get(f"place_{ui}") or meta.get("place_ar") or meta.get("place_en"),
                         scene_label=meta.get(f"scene_{ui}") or meta.get("scene_ar") or meta.get("scene_en"), fast=False,
                         ab_at=None if ab is None else round(ab + clip_at, 3), tokens=tokens, tr=None)
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
    envs = [s for s in scenes if s["kind"] in ("env", "envintro")]
    after_reveal = envs[0]["start"] if envs else cta["start"]
    if switch:
        middle = [(first_speaker["start"], switch["start"], "groove"), (switch["start"], switch["end"], "intro"), (switch["end"], reveal["start"], "lift")]
    else:
        half = snap((first_speaker["start"] + reveal["start"]) / 2)
        middle = [(first_speaker["start"], half, "groove"), (half, reveal["start"], "lift")]
    sections = [
        (0, first_speaker["start"], "intro"),
        *middle,
        (reveal["start"], reveal["impact"], "hold"),
        (reveal["impact"], after_reveal, "lift"),
        *([(after_reveal, cta["start"], "hold")] if envs else []),
        (cta["start"], duration - 1.6, "outro"),
    ]
    music = sound.music(duration + 0.5, sections)
    music = music[:n] if len(music) >= n else np.pad(music, ((0, n - len(music)), (0, 0)))
    # The environments are heard on their own: the music steps back under them.
    if envs:
        g = np.ones(n)
        a0, a1 = int(envs[0]["start"] * SR), int(cta["start"] * SR)
        ramp = int(0.4 * SR)
        g[a0:a1] = 0.35
        g[a0:a0 + ramp] = np.linspace(1, 0.35, ramp)
        g[a1 - ramp:a1] = np.linspace(0.35, 1, ramp)
        music *= g[:, None]
    sfx = np.zeros((n, 2))

    hook = scenes[0]
    tiles = [CAST[sc["id"]]["avatar"] for sc in scenes if sc["kind"] == "speaker"]
    tile_times = [round(0.1 + i * 0.06, 3) for i in range(len(tiles))]
    for i, tt in enumerate(tile_times):
        sound.add(sfx, sound.pop(pitch=0.85 + 0.04 * (i % 5), pan=((i % 4) - 1.5) / 2.5), tt, 0.12)
    for i, sc in enumerate(scenes[1:], start=1):
        if sc["kind"] in ("speaker", "switch", "env", "envintro", "cta"):
            sound.add(sfx, sound.whoosh(0.45, pan_from=0.5 if i % 2 else -0.5, pan_to=-0.5 if i % 2 else 0.5), sc["start"] - 0.28, 0.1)
        if sc.get("ab_at"):
            sound.add(sfx, sound.tick(1.4), sc["ab_at"] - 0.1, 0.9)
    sound.add(sfx, sound.riser(1.2), reveal["impact"] - 1.2, 0.1)
    sound.add(sfx, sound.butter(sound.impact(), "lowpass", 150), reveal["impact"] - 0.03, 0.5)
    reveal_tiles = [round(reveal["impact"] + 0.25 + i * 0.05, 3) for i in range(len(tiles))]
    for i, tt in enumerate(reveal_tiles):
        sound.add(sfx, sound.pop(pitch=1.2 + 0.03 * (i % 6), pan=((i % 4) - 1.5) / 2.5), tt, 0.07)
    if envs:
        sound.add(sfx, sound.shimmer(), envs[0]["start"] + 0.1, 0.18)
    typing = [round(cta["start"] + 0.55 + k * 0.07 + (0.02 if k % 3 == 0 else 0), 3) for k in range(18)]
    for tt in typing:
        sound.add(sfx, sound.tick(), tt, 0.22)
    stats_at = round(cta["logo_at"] + 0.45, 3)
    sound.add(sfx, sound.butter(sound.impact(0.5), "lowpass", 150), cta["logo_at"], 0.3)
    sound.add(sfx, sound.shimmer(), cta["vo_end"] - 0.05, 0.3)

    # ── Mix: music ducks under the voices ──
    def lufs_gain(x, target):
        import pyloudnorm as pyln
        meter = pyln.Meter(SR)
        loud = meter.integrated_loudness(x)
        return 10 ** ((target - loud) / 20)

    vo *= lufs_gain(vo, -16)
    music *= lufs_gain(music, -26)
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
    # Quick to rise, slow to fall: the waves breathe with the voice instead of flickering.
    smooth = np.zeros_like(amp)
    for i in range(frames):
        prev = smooth[i - 1] if i else 0
        smooth[i] = prev + (amp[i] - prev) * (0.45 if amp[i] > prev else 0.12)
    beats = [round(b * sound.BEAT, 3) for b in range(int(duration / sound.BEAT) + 1)]

    timeline = dict(cut=cut_name, ui=ui, ratio=ratio, fps=FPS, duration=duration, scenes=scenes,
                    amp=[round(float(min(a, 1.6)), 3) for a in smooth], beats=beats, tiles=tiles,
                    tile_times=tile_times, reveal_tiles=reveal_tiles, typing=typing, stats_at=stats_at,
                    switch_flags=SWITCH_FLAGS["en" if ui == "ar" else "ar"],
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
    for flag in sum(SWITCH_FLAGS.values(), []):
        shutil.copy(ROOT / f"public/images/flags/{flag}.svg", assets / f"{flag}.svg")
    for logo in ("logo-ar-light.svg", "logo-light.svg", "icon-128.svg"):
        shutil.copy(ROOT / f"public/images/{logo}", assets / logo)
    (assets / "icon-light.svg").write_text((ROOT / "public/images/icon-128.svg").read_text().replace("#7659FA", "#FFFFFF"))
    grain = assets / "grain.png"
    if not grain.exists():
        noise = (np.random.default_rng(3).normal(128, 42, (512, 512))).clip(0, 255).astype(np.uint8)
        Image.fromarray(noise, "L").save(grain)

    print(f"{cut_name}: {duration:.2f}s, {len(scenes)} scenes -> {out}")
    for sc in scenes:
        print(f"  {sc['start']:6.2f}-{sc['end']:6.2f} {sc['kind']:8s} {sc['id'][:19]:19s} vo {sc['vo_start']:.2f}-{sc['vo_end']:.2f}  " +
              " ".join(w["w"] for w in sc["words"]))


if __name__ == "__main__":
    cut_name = sys.argv[1]
    out_dir = sys.argv[2]
    ratio = sys.argv[sys.argv.index("--ratio") + 1] if "--ratio" in sys.argv else "9x16"
    build(cut_name, out_dir, ratio)
