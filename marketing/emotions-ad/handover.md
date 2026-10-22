# Listen: handover (emotions video, SEO, Google Ads), 22 Oct 2026, 02:30 UTC

Repo: github.com/SoliCode-Ltd/listen. Follow CLAUDE.md (clear PR titles, never merge
without "yes merge it", never name other companies in site text, draft emails only,
deploy with the "Server deploy" workflow, ask before generating audio).

## State of things

- **GitHub Actions works again.** The billing lock is fixed; the voice run on
  22 Oct 02:00 UTC ran normally.
- **Repo visibility:** the owner made it public for the voice run; it should be
  private again. Check it is.
- **Work branch:** `solicode/nifty-knuth-gq5141` = `claude/emotions-ad` + the
  joining tool + the two voice tracks (latest commit "Emotions ad: the Arabic and
  English voice tracks for the talking-video tool"). No PR yet.

## Emotions video (in progress)

The idea, after the reference post (one face, one line, many deliveries): a
realistic AI presenter says one line again and again; a list beside them scrolls
and highlights the current option; every take is a new emotion and a new dialect.

### Done
- **Voices:** all 20 takes made by the "Launch ad voices" workflow (ad =
  emotions-ad, run 39708875451) and committed to `claude/emotions-ad` with word
  timings: `marketing/emotions-ad/voices/*.wav` + `words.json`. Owner-approved; they
  used 20 short clips of the shared speech quota. Do not remake them without asking.
- **Tracks for lip sync:** `marketing/emotions-ad/track/`
  - `emotions-ar.wav/.mp3`: 35.1 s, the man, voice Iapetus ("Fahad"):
    Saudi plain → Lebanese whisper → Egyptian laugh → Iraqi sob → Moroccan shout
    → Emirati sarcastic → Tunisian trailer → Kuwaiti excited → English excited →
    «سطر واحد. كل المشاعر. كل اللهجات… منصة إسمع.»
  - `emotions-en.wav/.mp3`: 39.3 s, the woman in a hijab, voice Kore ("Daisy"):
    US plain → British whisper → Australian laugh → Irish sob → New York shout →
    South African sarcastic → Canadian trailer → Indian excited → Egyptian Arabic →
    "One line. Every emotion. Every accent… Listen."
  - `emotions-<cut>.json`: each take's start/end in the track, its on-screen label
    (Arabic and English, e.g. «لبناني · همس» / "Lebanese · Whisper") and word
    timings in track time. Use these to drive the list and captions.
  - Made by `python marketing/emotions-ad/join.py [ar|en]` (needs numpy,
    soundfile, pyloudnorm, ffmpeg, php). Voice only, -16 LUFS, peaks under -1.5 dBTP.
- **Presenters** (realistic AI people, no real person):
  `marketing/emotions-ad/presenters/ar-man.png` (Arabic cut) and `en-woman.png`
  (English cut, the woman in a white hijab).

### Open points
- **Lebanese whisper** (2nd Arabic take): the transcription did not hear the first
  word «ما». Owner to listen; remake only that take if it is missing (ask first).
- **Length:** 35 and 39 s versus about 20 s in the reference. Offer a ~20 s cut:
  the 5 strongest takes + the end line (re-run join.py with a take list).

### The reference (owner's upload, re-upload it to the new session)
19.8 s, 1920×1080, 24 fps. Light grey/white background; head and shoulders in a soft
white radial glow, left of centre; a vertical list of options to the right that
scrolls, the current one in [brackets] in the accent colour, the others fading out
above and below; the spoken line beside the list with small up/down arrows; logo
top left; product name and tagline bottom left; end card: a tagline typed in on a
warm blurred background, then the logo. Ours: Listen purple instead of orange,
Listen logo, Arabic cut right-to-left, 9:16 and 16:9.

### The talking face: what was tried
The owner wants the people in the two portraits to really talk, like the reference.
- This cloud container has **no GPU** (4 CPU cores, 15 GB RAM).
- Started setting up the open talking-photo model SadTalker (head motion, blinks,
  lip sync from one photo + audio), not yet run:
  - Code: `git clone` of the model host's space `vinthony/SadTalker` (MIT).
    Its weights use the Basel Face Model, which has its own terms: check them
    before using the result in a paid ad.
  - Python 3.10 via `uv python install 3.10`; torch 1.13.1 CPU from
    download.pytorch.org; the space's `requirements.txt` installs, plus
    `setuptools<70` for librosa.
  - Weights: `git lfs pull` gives nothing, and the space's `checkpoints/` are
    broken symlinks. Delete the symlinks, then download each file from
    `https://huggingface.co/vinthony/SadTalker/resolve/main/<file>` (the list is in
    that model's file listing). GitHub release downloads are blocked (403).
  - Clear `~/.cache/uv` after installing: it grows to about 5.5 GB.
  - Expect on CPU: minutes per take, a softer mouth, and a face that follows
    the lips but does not really laugh or cry like the reference.
- The owner interrupted the weight download to ask for this handover: ask whether to
  continue with this, or have the owner make the talking clips in an AI
  talking-video tool (upload a portrait + its WAV) and send them back.

### Then
- Build the edit around the talking clips, with the motion-reel skill: the
  scrolling list driven by `track/emotions-<cut>.json`, captions, Listen logo, end
  card, 9:16 and 16:9, RTL for Arabic.
- Next two videos in the same format: **cloning** (the presenter's Listen voice
  cloned, speaking other dialects) and **dubbing** (the presenter's English clip run
  through "Dub my video" into Arabic, side by side).

## SEO (done, live), still to do
- Keep requesting indexing in Search Console, about 10 a day: /ar/text-to-speech-arabic,
  /ar, /en, /en/text-to-speech-arabic, /ar/arabic-dialects/saudi, /egyptian, /emirati,
  /en/english-accents/british, /american, /ar/english-accents/british.
- Optional: Arabic descriptions on the accent cards of the /ar accent pages.

## Google Ads (set up), still to do in the account
- Purchase goal on every campaign (showed 2 of 3); bidding to Maximize conversion
  value with no target; final URL from /demo to /ar or /en; sitelinks Free trial
  (/ar/free-trial) and Pricing (/ar/pricing) instead of Demo and Create Account;
  one language per ad; negative keywords.
- Check the trial action AW-18014537634/jHW0CPLrhosdEKKPgI5D still exists.

## Sales ideas (agreed direction)
1. Let visitors hear one line without signing up (biggest win).
2. A clear offer on the pricing page (trial, or a $1 first month).
3. Local payments for Egypt and the Gulf.
4. An email sequence for sign-ups who never paid (draft only).
5. Target Arabic voiceover freelancers, creators, e-learning makers and businesses.

## Still on the owner's list from the 19 Oct overnight report
Live's PHP limits in Cloudways, the speech engine's monthly spending cap, publishing
browser extension 1.1.1, members 9 and 37 (free Starter plans end 15 and 8 Nov), the
Pro and Studio Max per-request characters, grace days versus Stripe's retries, and
changing the admin password.
