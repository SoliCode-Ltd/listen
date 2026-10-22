"""Builds one of the two prompts films: places every voice, times every word
and tag from the transcripts, writes the timeline the page plays, the music
and sound design, and the portraits made of dots.

    python build.py <film: ar|en> <out dir> [--music pulse|cinematic|chill] [--audio-only]

Output (in <out dir>/<film>): film.html with its script, styles and assets,
timeline.js, dots.js and mix.wav.
"""

import importlib.util
import json
import pathlib
import re
import shutil
import subprocess
import sys

import numpy as np
import soundfile as sf
from PIL import Image

sys.dont_write_bytecode = True  # no __pycache__ next to this or the emotions ad's build script

HERE = pathlib.Path(__file__).resolve().parent
FILM = HERE.parent
ROOT = HERE.parents[2]
sys.path.insert(0, str(HERE))
import score  # noqa: E402
from score import SR, sound  # noqa: E402

# The emotions ad's loader and its aligner of the script's words to the transcript.
_spec = importlib.util.spec_from_file_location("emotions_build", ROOT / "marketing/emotions-ad/render/build.py")
emotions = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(emotions)

FPS = 60
W, H = 1080, 1920

# Who speaks: the name in each film's language and the site avatar they are drawn from.
CAST = {
    "Fatima": ("فاطمة", "ar-fatima"), "Alaa": ("علاء", "ar-alaa"), "Sawsan": ("سوسن", "ar-sawsan"), "Raed": ("رائد", "ar-raed"),
    "Alice": ("أليس", "en-alice"), "Caleb": ("كاليب", "en-caleb"), "Miles": ("مايلز", "en-miles"), "Sumaya": ("سمية", "ar-sumaya"),
}
# A tag is shown in the language its line is spoken in, as the editor names it.
TAGS_AR = {
    "nervous": "متوتر", "whispers": "همس", "commanding": "بحزم", "clap": "تصفيق", "trembling": "بارتجاف", "sob": "نحيب",
    "gasp": "شهقة", "laugh": "ضحكة", "chuckle": "ضحكة خفيفة", "sigh": "تنهيدة", "cheerful": "ببهجة", "tense": "بترقّب",
    "professional": "باحترافية", "warm": "دافئ", "friendly": "بودّ", "sarcastic": "ساخر", "snicker": "ضحكة ساخرة",
}
# Sounds happen between words; the others colour the words after them.
SOUND_TAGS = {"clap", "sob", "gasp", "laugh", "chuckle", "sigh", "snicker"}
# Where each avatar's mouth is in its 520 px portrait: the voice ripples out from there.
MOUTHS = {
    "ar-fatima": (262, 252), "ar-alaa": (262, 232), "ar-sawsan": (266, 250), "ar-raed": (262, 268),
    "en-alice": (262, 254), "en-caleb": (262, 281), "en-miles": (262, 272), "ar-sumaya": (262, 240),
}

FILMS = {
    "ar": dict(
        intro="كل صوت ستسمعه الآن | صُنع من النص المعروض أمامك كما هو، | دون أي تعديل — فقط",
        scenes=[
            dict(kind="duo", context="في موقع التصوير", lines=["pf-ar-1", "pf-ar-2", "pf-ar-3", "pf-ar-4", "pf-ar-5"]),
            dict(kind="solo", context="على الهواء", title="إحساس", lines=["pf-ar-6", "pf-ar-7", "pf-ar-8"], cue="radio"),
            dict(kind="solo", context="مكالمة", title="لهجة", lines=["pf-ar-9", "pf-ar-10"], cue="phone"),
        ],
        tagline=["أي نص.", "أي إحساس.", "أي لهجة."],
    ),
    "en": dict(
        intro="Every voice you're about to hear | was made from the text on screen, | exactly as written, with no edits — | just",
        scenes=[
            dict(kind="duo", context="Podcast", lines=["pf-en-1", "pf-en-2", "pf-en-3", "pf-en-4", "pf-en-5"]),
            dict(kind="solo", context="On air", title="Emotion", lines=["pf-en-6", "pf-en-7", "pf-en-8"], cue="radio"),
            dict(kind="solo", context="On a call", title="Accent", lines=["pf-en-9", "pf-en-12", "pf-en-13"], cue="phone"),
        ],
        tagline=["Any text.", "Any emotion.", "Any accent."],
    ),
}

# Each film's music (score.py): one of score.STYLES.
MUSIC = {"ar": "pulse", "en": "chill"}

# The film's clock, in seconds.
INTRO = dict(text_in=0.1, step=0.075, text_out=3.75, orb_in=3.8, bars_at=4.7, pulse_at=5.35, burst=5.9)
ASSEMBLE = 1.25   # dots fly in, then the first line
GATHER = 0.85     # dots gather into the orb
TITLE = dict(word=GATHER, write=GATHER + 0.4, hold=GATHER + 1.0, out=GATHER + 1.65, burst=GATHER + 2.15)
OUTRO = dict(expand=GATHER, full=GATHER + 1.15, tag=GATHER + 1.25, tag_step=0.6, contract=GATHER + 4.55,
             bars=GATHER + 5.4, word=GATHER + 6.15, end=GATHER + 9.0)


def lines_php():
    raw = subprocess.run(["php", "-r", 'echo json_encode(require "marketing/prompts-film/lines.php", JSON_UNESCAPED_UNICODE);'],
                         cwd=ROOT, capture_output=True, text=True, check=True).stdout
    return {s["slug"]: s for s in json.loads(raw)["samples"]}


def trim(audio):
    """The take without its silences: (audio, seconds cut from the start)."""
    mono = np.abs(audio.mean(axis=1))
    hop = int(0.01 * SR)
    rms = np.array([np.sqrt(np.mean(mono[i:i + hop] ** 2)) for i in range(0, len(mono), hop)])
    floor = rms.max() * 10 ** (-42 / 20)
    on = np.nonzero(rms > floor)[0]
    a = max(0, on[0] * hop - int(0.05 * SR))
    b = min(len(audio), (on[-1] + 1) * hop + int(0.15 * SR))
    seg = audio[a:b].copy()
    f = int(0.008 * SR)
    seg[:f] *= np.linspace(0, 1, f)[:, None]
    seg[-f * 4:] *= np.linspace(1, 0, f * 4)[:, None]
    return seg, a / SR


def tokens_of(line, heard, cut):
    """The prompt as shown: words timed by the transcript and tags timed between them."""
    parts = [("tag", m.group(1).strip().lower()) if m.group(1) else ("w", m.group(2))
             for m in re.finditer(r"<([^<>]+)>|(\S+)", line["text"])]
    words = emotions.script_words(" ".join(p[1] for p in parts if p[0] == "w"), heard)
    lang = line["language"]
    out, wi = [], 0
    for kind, text in parts:
        if kind == "w":
            w = words[wi]
            wi += 1
            out.append(dict(t="w", text=text, s=round(w["start"] - cut, 3), e=round(w["end"] - cut, 3)))
        else:
            out.append(dict(t="tag", key=text, text=TAGS_AR.get(text, text) if lang == "ar" else text,
                            sound=text in SOUND_TAGS))
    # Tag times: a sound fills the gap it sits in (shared with the sounds next to it);
    # a delivery tag lasts while the words after it are said, up to the next tag.
    first, last = 0.0, max((t["e"] for t in out if t["t"] == "w"), default=0.0)
    i = 0
    while i < len(out):
        if out[i]["t"] != "tag":
            i += 1
            continue
        j = i
        while j < len(out) and out[j]["t"] == "tag":
            j += 1
        before = next((out[k]["e"] for k in range(i - 1, -1, -1) if out[k]["t"] == "w"), first)
        after = next((out[k]["s"] for k in range(j, len(out)) if out[k]["t"] == "w"), last)
        sounds = [k for k in range(i, j) if out[k]["sound"]]
        span = max(0.25, after - before)
        for n, k in enumerate(sounds):
            out[k]["on"] = round(before + span * n / len(sounds), 3)
            out[k]["off"] = round(before + span * (n + 1) / len(sounds) + (0.15 if n == len(sounds) - 1 else -0.12), 3)
        nxt = next((k for k in range(j, len(out)) if out[k]["t"] == "tag"), len(out))
        until = max((out[k]["e"] for k in range(j, nxt) if out[k]["t"] == "w"), default=after)
        for k in range(i, j):
            if not out[k]["sound"]:
                out[k]["on"] = round(max(before, after - 0.18), 3)
                out[k]["off"] = round(until, 3)
        i = j
    return out


def shift(tokens, at):
    moved = []
    for tok in tokens:
        tok = dict(tok)
        for key in ("s", "e", "on", "off"):
            if key in tok:
                tok[key] = round(tok[key] + at, 3)
        moved.append(tok)
    return moved


def build(film, out_dir, style=None, audio_only=False):
    spec = FILMS[film]
    style = style or MUSIC[film]
    lines = lines_php()
    heard = json.loads((FILM / "voices/words.json").read_text(encoding="utf-8"))

    vo = np.zeros((int(240 * SR), 2))
    scenes, t = [], INTRO["burst"] + ASSEMBLE
    cues = []
    for k, sc in enumerate(spec["scenes"]):
        scene = dict(kind=sc["kind"], context=sc["context"], timer=sc.get("cue") == "phone", title=None, faces=[], lines=[])
        if k:
            start = scenes[-1]["exit"]
            scene["title"] = dict(text=sc["title"], start=start)
            t = start + TITLE["burst"] + ASSEMBLE
        scene["enter"] = round(t - ASSEMBLE, 3)
        if sc.get("cue"):
            cues.append((sc["cue"], scene["enter"]))
            if sc["cue"] == "phone":
                t += 0.9  # the phone rings before the agent answers
        prev = None
        for slug in sc["lines"]:
            line = lines[slug]
            speaker = line["speaker"]
            if speaker not in [f["key"] for f in scene["faces"]]:
                name_ar, avatar = CAST[speaker]
                scene["faces"].append(dict(key=speaker, name=name_ar if film == "ar" else speaker, avatar=avatar,
                                           mouth=MOUTHS[avatar]))
            face = [f["key"] for f in scene["faces"]].index(speaker)
            audio, cut = trim(emotions.load(FILM / f"voices/{slug}.wav"))
            if prev is not None:
                same = prev["face"] == face
                switch = prev["dialect"] != line["dialect"]
                t = prev["end"] + (0.8 if switch else 0.62 if same else 0.42)
            sound.add(vo, audio, t)
            lang = line["language"]
            entry = dict(slug=slug, face=face, lang=lang, at=round(t, 3), end=round(t + len(audio) / SR, 3),
                         tokens=shift(tokens_of(line, heard[slug]["words"], cut), t))
            scene["lines"].append(entry)
            prev = dict(entry, dialect=line["dialect"])
        scene["exit"] = round(prev["end"] + 0.65, 3)
        scenes.append(scene)

    # When each prompt shows: in just before its voice, out just after (the last
    # one in a scene stays until the dots leave).
    for scene in scenes:
        for i, ln in enumerate(scene["lines"]):
            prev_out = scene["lines"][i - 1]["out"] + 0.22 if i else scene["enter"] + 0.6
            ln["show"] = round(max(prev_out, ln["at"] - 0.45), 3)
            ln["out"] = round(ln["end"] + 0.1 if i + 1 < len(scene["lines"]) else scene["exit"] - 0.05, 3)

    outro = dict(start=scenes[-1]["exit"], tagline=spec["tagline"])
    duration = round(outro["start"] + OUTRO["end"], 3)
    n = int((duration + 0.5) * SR)
    vo = vo[:n]

    # ── Music and sound design ──
    sections = [(0, INTRO["burst"] + 0.4, "intro")]
    for k, scene in enumerate(scenes):
        if scene["title"]:
            sections.append((scene["title"]["start"], scene["enter"] + 0.3, "title"))
        sections.append((scene["enter"] + 0.3, scene["exit"], "scene"))
    o = outro["start"]
    sections += [(o, o + OUTRO["tag"], "title"), (o + OUTRO["tag"], o + OUTRO["bars"], "groove"), (o + OUTRO["bars"], duration, "end")]
    music = score.music(style, duration + 0.5, sections)[:n]
    music = np.pad(music, ((0, max(0, n - len(music))), (0, 0)))

    sfx = np.zeros((n, 2))
    add = sound.add
    add(sfx, score.swell(1.0), INTRO["orb_in"] - 0.55, 0.7)
    add(sfx, score.signature(style), INTRO["bars_at"] + 0.05, 0.6)
    add(sfx, sound.shimmer(), INTRO["pulse_at"], 0.35)
    for scene in scenes:
        add(sfx, sound.whoosh(0.5, pan_from=-0.3, pan_to=0.3), scene["enter"] - 0.12, 0.13)
        add(sfx, score.sparkle(1.0, seed=int(scene["enter"] * 10)), scene["enter"], 0.55)
        add(sfx, score.sparkle(0.7, density=45, seed=int(scene["exit"] * 10)), scene["exit"], 0.4)
        if scene["title"]:
            s0 = scene["title"]["start"]
            add(sfx, score.swell(0.8), s0 + 0.1, 0.5)
            add(sfx, sound.whoosh(0.55, pan_from=-0.4 if film == "en" else 0.4, pan_to=0.4 if film == "en" else -0.4), s0 + TITLE["write"] - 0.05, 0.1)
            add(sfx, sound.butter(sound.impact(0.5), "lowpass", 180), s0 + TITLE["hold"], 0.35)
            add(sfx, sound.tick(1.2), s0 + TITLE["hold"], 0.6)
    for cue, at in cues:
        if cue == "radio":
            add(sfx, score.radio_sting(style), at + 0.25, 0.55)
        if cue == "phone":
            add(sfx, score.phone_ring(), at + 0.35, 1.0)
    add(sfx, score.swell(1.1), o + OUTRO["expand"] - 0.3, 0.7)
    for k in range(3):
        add(sfx, sound.butter(sound.impact(0.45), "lowpass", 220), o + OUTRO["tag"] + k * OUTRO["tag_step"], 0.22)
    add(sfx, sound.whoosh(0.7, rising=False, pan_from=0.3, pan_to=-0.3), o + OUTRO["contract"] - 0.1, 0.16)
    add(sfx, sound.butter(sound.impact(0.6), "lowpass", 160), o + OUTRO["bars"] + 0.55, 0.35)
    add(sfx, sound.shimmer(), o + OUTRO["bars"] + 0.6, 0.35)

    def lufs_gain(x, target):
        import pyloudnorm as pyln
        return 10 ** ((target - pyln.Meter(SR).integrated_loudness(x)) / 20)

    vo *= lufs_gain(vo, -16)
    music *= lufs_gain(music, -25)
    env = np.sqrt(np.maximum(sound.butter(vo.mean(axis=1) ** 2, "lowpass", 8), 0))
    env = np.clip(env / (np.percentile(env[env > 1e-4], 60) + 1e-9), 0, 1)
    duck = np.empty_like(env)
    level, attack, release = 0.0, np.exp(-1 / (0.04 * SR)), np.exp(-1 / (0.6 * SR))
    for i in range(0, len(env), 64):
        target = env[i]
        level = target + (level - target) * (attack if target > level else release) ** 64
        duck[i:i + 64] = level
    music = music * (1 - 0.62 * duck)[:, None]
    mix = vo + music + sfx * (1 - 0.4 * duck)[:, None]
    mix = mix[: int((duration + 0.1) * SR)]
    mix[-int(0.6 * SR):] *= np.linspace(1, 0, int(0.6 * SR))[:, None]

    out = pathlib.Path(out_dir) / film
    out.mkdir(parents=True, exist_ok=True)
    sf.write(out / "premaster.wav", mix.astype(np.float32), SR, subtype="FLOAT")
    subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", str(out / "premaster.wav"), "-af",
                    "alimiter=limit=0.89:attack=3:release=60:level=false,loudnorm=I=-14:TP=-1.2:LRA=9:linear=true",
                    "-ar", str(SR), "-c:a", "pcm_s16le", str(out / "mix.wav")], check=True)
    if audio_only:
        print(f"{film} ({style}): {duration:.2f}s, {out / 'mix.wav'}")
        return

    # ── The voice's level per frame, for the faces ──
    frames = int(np.ceil(duration * FPS)) + 1
    vmono, win = np.abs(vo.mean(axis=1)), int(SR / FPS)
    amp = np.array([np.sqrt(np.mean(vmono[i * win:(i + 1) * win] ** 2)) if i * win < len(vmono) else 0 for i in range(frames)])
    amp = amp / (np.percentile(amp[amp > 1e-4], 92) + 1e-9)
    smooth = np.zeros_like(amp)
    for i in range(frames):
        prev = smooth[i - 1] if i else 0
        smooth[i] = prev + (amp[i] - prev) * (0.5 if amp[i] > prev else 0.14)

    timeline = dict(film=film, rtl=film == "ar", fps=FPS, W=W, H=H, duration=duration,
                    intro=dict(INTRO, text=spec["intro"]), title=TITLE, outro=dict(OUTRO, **outro), assemble=ASSEMBLE,
                    gather=GATHER, scenes=scenes, amp=[round(float(min(a, 1.6)), 3) for a in smooth])
    (out / "timeline.js").write_text("window.TIMELINE = " + json.dumps(timeline, ensure_ascii=False) + ";\n", encoding="utf-8")

    # ── Page, portraits and assets ──
    for name in ("film.html", "film.js", "film.css", "orb.js"):
        shutil.copy(HERE / name, out / name)
    # The orbs are the site's own (public/js/listen-orbs.js): the same shader, with
    # its grain made optional, since the page adds its own over the whole frame.
    site = (ROOT / "public/js/listen-orbs.js").read_text(encoding="utf-8")
    frag = re.search(r"var FRAG = (\[.*?\])\.join\('\\n'\);", site, re.S).group(1)
    frag = frag.replace("uniform float uLift;", "uniform float uLift; uniform float uGrain;")
    frag = frag.replace("col += (g - 0.5) * 0.05;", "col += (g - 0.5) * 0.05 * uGrain;")
    assert "uGrain;" in frag and "* uGrain" in frag, "the site's orb shader changed: update build.py"
    (out / "orb-shader.js").write_text("window.ORB_FRAG = " + frag + ".join('\\n');\n", encoding="utf-8")
    shutil.copytree(ROOT / "marketing/launch-ad/render/fonts", out / "fonts", dirs_exist_ok=True)
    assets = out / "assets"
    assets.mkdir(exist_ok=True)
    cache = pathlib.Path(out_dir) / "dots"
    cache.mkdir(exist_ok=True)
    dots = {}
    for scene in scenes:
        for face in scene["faces"]:
            av = face["avatar"]
            if av in dots:
                continue
            cut_png, data = cache / f"{av}.png", cache / f"{av}.json"
            if not data.exists():
                subprocess.run([sys.executable, str(HERE / "cutout.py"), str(ROOT / f"public/images/speakers/{av}.webp"), str(cut_png)], check=True)
                subprocess.run([sys.executable, str(HERE / "dots.py"), str(cut_png), str(data), "520", "4.3"], check=True)
            dots[av] = json.loads(data.read_text())
    (out / "dots.js").write_text("window.DOTS = " + json.dumps(dots, separators=(",", ":")) + ";\n")
    for logo in ("logo-ar-light.svg", "logo-light.svg"):
        svg = (ROOT / f"public/images/{logo}").read_text(encoding="utf-8")
        shutil.copy(ROOT / f"public/images/{logo}", assets / logo)
        # The wordmark alone: the bars are drawn by the page so they can move.
        (assets / logo.replace("logo", "wordmark")).write_text(re.sub(r"<g transform.*?</g>", "", svg, flags=re.S), encoding="utf-8")
    grain = assets / "grain.png"
    if not grain.exists():
        noise = np.random.default_rng(3).normal(128, 40, (512, 512)).clip(0, 255).astype(np.uint8)
        Image.fromarray(noise, "L").save(grain)

    print(f"{film}: {duration:.2f}s")
    for scene in scenes:
        if scene["title"]:
            print(f"  {scene['title']['start']:6.2f} title {scene['title']['text']}")
        for ln in scene["lines"]:
            shown = " ".join(f"<{x['text']}>" if x["t"] == "tag" else x["text"] for x in ln["tokens"])
            face = scene["faces"][ln["face"]]
            print(f"  {ln['at']:6.2f}-{ln['end']:6.2f} {face['name']}  {shown}")
    print(f"  {outro['start']:6.2f} outro")


if __name__ == "__main__":
    args = sys.argv[1:]
    style = args[args.index("--music") + 1] if "--music" in args else None
    build(args[0], args[1], style=style, audio_only="--audio-only" in args)
