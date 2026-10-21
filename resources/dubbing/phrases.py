"""Dub my video: where someone speaks, phrase by phrase, for VideoDubber's
speech map (the moments the dubbed lines are fitted to, so the new voice
speaks when the lips move and pauses when they close).

A small speech-detection model (Silero VAD, ONNX, MIT licence, taken from its
wheel by App\\Services\\Dubbing\\VoiceSeparator) tells speech from music and
noise every 32 ms. Inside the speech, a pause is where the model no longer
hears speech, or where the speech band falls 25 dB under the speech around
it, for 0.15 s or more: the mouth closes. A phrase over 8 s without one is
split where it is quietest. Reads raw 32-bit float mono at 16 kHz (the site's
ffmpeg makes it); needs only onnxruntime and numpy (--lib) and prints
{"phrases": [[start, end], ...]} in seconds.

    phrases.py --lib DIR --vad FILE IN.f32
    phrases.py --lib DIR --vad FILE --check
"""
import argparse
import json
import sys

RATE = 16000
WINDOW = 512
CONTEXT = 64
STEP = WINDOW / RATE


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--lib', required=True)
    parser.add_argument('--vad', required=True)
    parser.add_argument('--check', action='store_true')
    parser.add_argument('path', nargs='?')
    args = parser.parse_args()
    sys.path.insert(0, args.lib)

    import numpy as np
    import onnxruntime as ort

    options = ort.SessionOptions()
    options.intra_op_num_threads = 1
    options.inter_op_num_threads = 1
    session = ort.InferenceSession(args.vad, options, providers=['CPUExecutionProvider'])

    def probabilities(audio):
        """How surely each 32 ms is speech, 0 to 1."""
        frames = len(audio) // WINDOW
        state = np.zeros((2, 1, 128), dtype=np.float32)
        before = np.zeros((1, CONTEXT), dtype=np.float32)
        rate = np.array(RATE, dtype=np.int64)
        out = np.zeros(frames)
        for i in range(frames):
            x = np.concatenate([before, audio[None, i * WINDOW:(i + 1) * WINDOW]], 1)
            p, state = session.run(None, {'input': x, 'state': state, 'sr': rate})
            before = x[:, -CONTEXT:]
            out[i] = p[0, 0]
        return out

    def levels(audio, frames):
        """The speech band's loudness (150 Hz to 4 kHz) every 32 ms, in dB."""
        freqs = np.fft.rfftfreq(WINDOW, 1 / RATE)
        band = (freqs >= 150) & (freqs <= 4000)
        window = np.hanning(WINDOW).astype(np.float32)
        out = np.zeros(frames)
        for first in range(0, frames, 4096):
            last = min(frames, first + 4096)
            chunk = audio[first * WINDOW:last * WINDOW].reshape(last - first, WINDOW) * window
            out[first:last] = 10 * np.log10((np.abs(np.fft.rfft(chunk, axis=1)) ** 2)[:, band].sum(1) + 1e-10)
        return out

    def split(first, last, level, out):
        """A phrase into pieces of 8 s at most, each cut where it is quietest (in its middle 60%)."""
        if (last - first) * STEP <= 8.0:
            out.append((first, last))
            return
        a = first + int(0.2 * (last - first))
        b = last - int(0.2 * (last - first))
        cut = a + int(np.argmin(level[a:b]))
        split(first, cut, level, out)
        split(cut, last, level, out)

    def phrases(audio):
        p = probabilities(audio)
        n = len(p)
        if n == 0:
            return []
        level = levels(audio, n)
        # The model's own way of telling speech: in at 0.5, out under 0.35.
        speech = np.zeros(n, dtype=bool)
        on = False
        for i in range(n):
            on = p[i] >= (0.35 if on else 0.5)
            speech[i] = on
        # How loud the speech is around each moment (the loud end, within a second either way).
        reach = int(round(1.0 / STEP))
        around = np.full(n, -200.0)
        for i in range(n):
            a, b = max(0, i - reach), min(n, i + reach + 1)
            heard = level[a:b][speech[a:b]]
            if len(heard):
                around[i] = np.percentile(heard, 80)
        pause = ~speech | (level < around - 25)
        least = int(np.ceil(0.15 / STEP))
        runs = []
        start = None
        quiet = 0
        last_speech = 0
        for i in range(n + 1):
            if i < n and not pause[i]:
                if start is None:
                    start = i
                quiet = 0
                last_speech = i
            elif start is not None:
                quiet += 1
                if i == n or quiet >= least:
                    runs.append((start, last_speech + 1))
                    start = None
                    quiet = 0
        pieces = []
        for first, last in runs:
            split(first, last, level, pieces)
        out = []
        for k, (first, last) in enumerate(pieces):
            if (last - first) * STEP < 0.2:
                continue
            # A little before and after, as far as the phrases around it allow.
            begin = max(first * STEP - 0.03, pieces[k - 1][1] * STEP if k > 0 else 0.0)
            end = min(last * STEP + 0.03, pieces[k + 1][0] * STEP if k + 1 < len(pieces) else n * STEP)
            out.append([round(begin, 2), round(end, 2)])
        return out

    if args.check:
        tone = (0.2 * np.sin(2 * np.pi * 220 * np.arange(RATE) / RATE)).astype(np.float32)
        phrases(np.concatenate([np.zeros(RATE // 2, dtype=np.float32), tone, np.zeros(RATE // 2, dtype=np.float32)]))
        print('ok')
        return

    audio = np.fromfile(args.path, dtype=np.float32)
    print(json.dumps({'phrases': phrases(audio)}))


if __name__ == '__main__':
    main()
