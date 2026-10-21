"""Dub my video: splits a video's sound into its voices and the rest (music
and effects), for App\\Services\\Dubbing\\VoiceSeparator.

An MDX-Net model (ONNX) gives the sound without the voices from the whole
sound, in chunks of about six seconds; the voices are the sound less that.
Reads and writes raw 32-bit float stereo at 44.1 kHz (the site's ffmpeg makes
and reads them), so it needs nothing but onnxruntime and numpy, which the
installer unpacks into --lib. Says "progress DONE TOTAL" after each chunk.

With --isolator (a speech-isolation program, DeepFilterNet's deep-filter) and
a fourth path, it also splits the voices once more: the main, close speaker,
whom the dub replaces, and the voices further back (children reciting, a
crowd, chanting), which the dub keeps. The program gives the main speaker;
the voices further back are the voices where the main speaker does not
dominate, frequency by frequency (a soft mask), so hardly anything of the
main speaker is left in them. While the main speaker talks they are also held
to how loud they are in the speaker's pauses, so the moments of the speaker
that the program misses stay out too.

    separate.py --lib DIR --model FILE [--threads N] [--isolator FILE --ffmpeg FILE] IN.f32 REST.f32 VOICES.f32 [BACKGROUND_VOICES.f32]
    separate.py --lib DIR --model FILE [--isolator FILE --ffmpeg FILE] --check
"""
import argparse
import os
import subprocess
import sys
import tempfile
import time


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--lib', required=True)
    parser.add_argument('--model', required=True)
    parser.add_argument('--threads', type=int, default=2)
    parser.add_argument('--check', action='store_true')
    parser.add_argument('--isolator', default='')
    parser.add_argument('--ffmpeg', default='ffmpeg')
    # How strongly the main speaker's moments are kept out of the voices further back: measured on a narrator
    # over background voices, 16 leaves him 27-31 dB down and keeps 52-61% of them (4: 26-29 dB, 57-68%; 64: 28-32 dB, 47-54%).
    parser.add_argument('--mask-strength', type=float, default=16.0)
    parser.add_argument('paths', nargs='*')
    args = parser.parse_args()
    sys.path.insert(0, args.lib)
    # Should the server ever run short of memory, this is what the system stops first: never the site.
    try:
        with open('/proc/self/oom_score_adj', 'w') as f:
            f.write('1000')
    except OSError:
        pass

    import numpy as np
    import onnxruntime as ort

    sr = 44100
    n_fft = 6144
    hop = 1024
    dim_f = 3072
    dim_t = 256
    compensate = 1.022
    chunk = hop * (dim_t - 1)
    trim = n_fft // 2
    gen = chunk - 2 * trim
    window = np.hanning(n_fft + 1)[:-1].astype(np.float32)

    def stft(x):
        pad = n_fft // 2
        x = np.pad(x, ((0, 0), (pad, pad)), mode='reflect')
        frames = 1 + (x.shape[1] - n_fft) // hop
        idx = np.arange(n_fft)[None, :] + hop * np.arange(frames)[:, None]
        return np.fft.rfft(x[:, idx] * window, axis=-1).transpose(0, 2, 1)

    def istft(spec):
        frames = spec.shape[2]
        seg = np.fft.irfft(spec.transpose(0, 2, 1), n=n_fft, axis=-1) * window
        length = n_fft + hop * (frames - 1)
        out = np.zeros((spec.shape[0], length), dtype=np.float32)
        norm = np.zeros(length, dtype=np.float32)
        for i in range(frames):
            out[:, i * hop:i * hop + n_fft] += seg[:, i]
            norm[i * hop:i * hop + n_fft] += window ** 2
        out /= np.maximum(norm, 1e-8)
        return out[:, n_fft // 2:length - n_fft // 2]

    options = ort.SessionOptions()
    options.intra_op_num_threads = max(1, args.threads)
    options.inter_op_num_threads = 1
    # Without its memory pools the model runs in well under a gigabyte, at about the same speed.
    options.enable_cpu_mem_arena = False
    options.enable_mem_pattern = False
    session = ort.InferenceSession(args.model, options, providers=['CPUExecutionProvider'])
    name = session.get_inputs()[0].name

    extra_steps = 2 if args.isolator and len(args.paths) > 3 else 0

    def background(mix, report=False):
        n = mix.shape[1]
        pad = gen - n % gen
        padded = np.concatenate([np.zeros((2, trim), np.float32), mix, np.zeros((2, pad + trim), np.float32)], 1)
        total = (n + pad + gen - 1) // gen + extra_steps
        out = []
        i = 0
        while i < n + pad:
            spec = stft(padded[:, i:i + chunk])[:, :dim_f, :]
            model_in = np.stack([spec[0].real, spec[0].imag, spec[1].real, spec[1].imag])[None].astype(np.float32)
            p = session.run(None, {name: model_in})[0][0]
            full = np.zeros((2, n_fft // 2 + 1, dim_t), dtype=np.complex64)
            full[0, :dim_f] = p[0] + 1j * p[1]
            full[1, :dim_f] = p[2] + 1j * p[3]
            out.append(istft(full)[:, trim:-trim])
            i += gen
            if report:
                print('progress %d %d' % (len(out), total), flush=True)
        return np.concatenate(out, 1)[:, :n] * compensate

    def ffmpeg(cmd, data=None):
        return subprocess.run([args.ffmpeg, '-v', 'error', '-y'] + cmd, input=data, capture_output=True, check=True).stdout

    def main_speaker(voices, work):
        """The close, main speaker in the voices, from the isolation program (it works at 48 kHz)."""
        wav = os.path.join(work, 'voices.wav')
        ffmpeg(['-f', 'f32le', '-ar', str(sr), '-ac', '2', '-i', '-', '-ar', '48000', '-c:a', 'pcm_s16le', wav], voices.T.astype(np.float32).tobytes())
        done = subprocess.run([args.isolator, '-D', '-o', os.path.join(work, 'out'), wav], capture_output=True)
        if done.returncode != 0:
            raise RuntimeError(done.stderr.decode(errors='replace')[-300:])
        raw = ffmpeg(['-i', os.path.join(work, 'out', 'voices.wav'), '-f', 'f32le', '-ar', str(sr), '-ac', '2', '-'])
        speaker = np.frombuffer(raw, dtype=np.float32).reshape(-1, 2).T
        out = np.zeros_like(voices)
        n = min(out.shape[1], speaker.shape[1])
        out[:, :n] = speaker[:, :n]
        return out

    def voices_further_back(voices, speaker, strength):
        """The voices where the main speaker does not dominate: a soft mask, frame by frame, in batches;
        then, band by band while the speaker talks, held down to how loud they are in the speaker's pauses (pause_hold)."""
        size, step = 2048, 512
        win = np.hanning(size + 1)[:-1].astype(np.float32)
        n = voices.shape[1]
        pad = size // 2
        v = np.pad(voices, ((0, 0), (pad, pad + size)))
        f = np.pad(speaker, ((0, 0), (pad, pad + size)))
        frames = 1 + (n + pad) // step
        freqs = np.fft.rfftfreq(size, 1 / sr)
        # 40 bands, even to the ear (mel); each frequency's hold is drawn between the bands' centres.
        mel = 2595 * np.log10(1 + freqs / 700)
        band = np.minimum(39, (40 * mel / (mel[-1] + 1e-9)).astype(int))
        members = np.zeros((len(freqs), 40), dtype=np.float32)
        members[np.arange(len(freqs)), band] = 1
        centres = (freqs[:, None] * members).sum(0) / np.maximum(1, members.sum(0))
        spread = np.stack([np.interp(freqs, centres, np.eye(40)[b]) for b in range(40)], 1).astype(np.float32)

        def batches():
            for first in range(0, frames, 256):
                idx = np.arange(size)[None, :] + step * np.arange(first, min(frames, first + 256))[:, None]
                V = np.fft.rfft(v[:, idx] * win, axis=-1)
                F = np.fft.rfft(f[:, idx] * win, axis=-1)
                rest = np.abs(V - F) ** 2
                yield first, idx, V * (rest / (rest + strength * np.abs(F) ** 2 + 1e-12)), F

        bands = np.zeros((frames, 40))
        speaking = np.zeros(frames)
        for first, idx, kept, F in batches():
            bands[first:first + len(idx)] = (np.abs(kept) ** 2).sum(0) @ members
            speaking[first:first + len(idx)] = (np.abs(F) ** 2).sum((0, 2))
        hold = pause_hold(bands, speaking, sr / step)

        out = np.zeros((2, v.shape[1]), dtype=np.float32)
        norm = np.zeros(v.shape[1], dtype=np.float32)
        for first, idx, kept, F in batches():
            gain = 10 ** ((hold[first:first + len(idx)] @ spread.T) / 20)
            y = np.fft.irfft(kept * gain[None], n=size, axis=-1).astype(np.float32) * win
            for k, start in enumerate(idx[:, 0]):
                out[:, start:start + size] += y[:, k]
                norm[start:start + size] += win ** 2
        out /= np.maximum(norm, 1e-8)
        return out[:, pad:pad + n]

    def pause_hold(bands, speaking, fps, margin=6.0):
        """How far down (dB, frame by band) the voices further back are held while the main speaker talks: to
        no louder than they are in the speaker's pauses within 4 s (the loud end of it, plus margin). Voices
        really further back are there in those pauses too; the moments of the speaker that the isolation
        program misses (a tenth of a second, now and then, as loud as the speaker) are not, and they are what
        came through as a whisper under the new voice. Measured on a real video and on a narrator over
        background voices: those moments go from 10 to 28 dB under the speaker's voice, and the voices
        further back keep 49% (52% before)."""
        level = 10 * np.log10(speaking + 1e-12)
        talks = level > max(np.percentile(level, 95) - 45, -30)
        near = talks.copy()
        for k in range(1, 18):  # 200 ms after the speaker
            near[k:] |= talks[:-k]
        for k in range(1, 5):  # 50 ms before
            near[:-k] |= talks[k:]
        pauses = np.where(~near)[0]
        loud = 10 * np.log10(bands + 1e-12)
        everywhere = np.percentile(loud[pauses], 95, axis=0) if len(pauses) >= 20 else np.percentile(loud, 10, axis=0)
        block = max(1, int(0.5 * fps))
        centres = np.arange(0, len(level), block)
        allowed = np.zeros((len(centres), loud.shape[1]))
        for i, c in enumerate(centres):
            allowed[i] = everywhere
            for reach in (4.0, 12.0):
                near_pauses = pauses[np.searchsorted(pauses, c - reach * fps):np.searchsorted(pauses, c + reach * fps)]
                if len(near_pauses) >= 20:
                    allowed[i] = np.percentile(loud[near_pauses], 95, axis=0)
                    break
        allowed = np.stack([np.interp(np.arange(len(level)), centres, allowed[:, b]) for b in range(loud.shape[1])], 1)
        hold = np.maximum(-40.0, np.minimum(0.0, allowed + margin - loud))
        hold[~near] = 0.0
        # Down at once, up again over 40 ms, and a frame early, so nothing slips through at the edges.
        release = np.exp(-1 / (0.04 * fps))
        for t in range(1, len(hold)):
            hold[t] = np.minimum(hold[t], release * hold[t - 1] + (1 - release) * hold[t])
        hold[:-1] = np.minimum(hold[:-1], hold[1:])
        return hold

    if args.check:
        background(np.zeros((2, sr), dtype=np.float32))
        if args.isolator:
            with tempfile.TemporaryDirectory() as work:
                tone = (0.1 * np.sin(2 * np.pi * 220 * np.arange(sr) / sr)).astype(np.float32)
                voices_further_back(np.stack([tone, tone]), main_speaker(np.stack([tone, tone]), work), args.mask_strength)
        print('ok')
        return

    source, rest_path, voices_path = args.paths[:3]
    started = time.time()
    mix = np.fromfile(source, dtype=np.float32).reshape(-1, 2).T.copy()
    rest = background(mix, report=True)
    del session
    np.clip(rest, -1, 1).T.astype(np.float32).tofile(rest_path)
    # The voices: everything the model did not keep, so the two together are the sound as it was.
    voices = mix - rest
    del mix, rest
    np.clip(voices, -1, 1).T.astype(np.float32).tofile(voices_path)
    if extra_steps:
        chunks = (voices.shape[1] + gen - 1) // gen
        try:
            with tempfile.TemporaryDirectory() as work:
                speaker = main_speaker(voices, work)
                print('progress %d %d' % (chunks + 1, chunks + 2), flush=True)
                further = voices_further_back(voices, speaker, args.mask_strength)
            np.clip(further, -1, 1).T.astype(np.float32).tofile(args.paths[3])
            print('progress %d %d' % (chunks + 2, chunks + 2), flush=True)
        except Exception as error:
            # The dub goes on without them: the main speaker's lines only lose the voices further back.
            print('isolation failed: %s' % error, file=sys.stderr, flush=True)
    print('%.1f seconds of sound in %.1f seconds' % (voices.shape[1] / sr, time.time() - started), flush=True)


if __name__ == '__main__':
    main()
