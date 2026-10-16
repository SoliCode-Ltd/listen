"""Transcribe the launch ad voices with word timings.

Usage: python transcribe.py <voices dir> <lines.json> <out words.json>

lines.json maps each slug to {"language": "ar"|"en", "text": "..."} (written by
the workflow from lines.php). The script text is given to Whisper as the
initial prompt so the transcript (and so the word timings) follows it. The
transcript is kept next to the script, so a wrong or cut-off clip shows up.
"""

import json
import pathlib
import sys

import numpy as np
import soundfile as sf
from faster_whisper import WhisperModel
from scipy.signal import resample_poly

voices, lines_file, out_file = map(pathlib.Path, sys.argv[1:4])
lines = json.loads(lines_file.read_text(encoding="utf-8"))
model = WhisperModel("large-v3", device="cpu", compute_type="int8")

result = {}
for wav in sorted(voices.glob("*.wav")):
    slug = wav.stem
    line = lines.get(slug, {})
    language = line.get("language", "ar")
    data, rate = sf.read(str(wav), dtype="float32", always_2d=True)
    audio = resample_poly(data.mean(axis=1), 16000, rate).astype(np.float32)
    segments, info = model.transcribe(
        audio,
        language=language,
        word_timestamps=True,
        beam_size=5,
        vad_filter=False,
        initial_prompt=line.get("text"),
        condition_on_previous_text=False,
    )
    words = []
    for segment in segments:
        for word in segment.words or []:
            words.append({"w": word.word.strip(), "start": round(word.start, 3), "end": round(word.end, 3), "p": round(word.probability, 3)})
    result[slug] = {
        "script": line.get("text"),
        "heard": " ".join(w["w"] for w in words),
        "duration": round(info.duration, 3),
        "words": words,
    }
    print(f"{slug} ({info.duration:.2f}s): {result[slug]['heard']}", flush=True)

out_file.write_text(json.dumps(result, ensure_ascii=False, indent=1), encoding="utf-8")
