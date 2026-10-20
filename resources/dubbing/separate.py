"""Dub my video: splits a video's sound into its voices and the rest (music
and effects), for App\\Services\\Dubbing\\VoiceSeparator.

An MDX-Net model (ONNX) gives the sound without the voices from the whole
sound, in chunks of about six seconds; the voices are the sound less that.
Reads and writes raw 32-bit float stereo at 44.1 kHz (the site's ffmpeg makes
and reads them), so it needs nothing but onnxruntime and numpy, which the
installer unpacks into --lib. Says "progress DONE TOTAL" after each chunk.

    separate.py --lib DIR --model FILE [--threads N] IN.f32 REST.f32 VOICES.f32
    separate.py --lib DIR --model FILE --check
"""
import argparse
import sys
import time


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--lib', required=True)
    parser.add_argument('--model', required=True)
    parser.add_argument('--threads', type=int, default=2)
    parser.add_argument('--check', action='store_true')
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

    def background(mix, report=False):
        n = mix.shape[1]
        pad = gen - n % gen
        padded = np.concatenate([np.zeros((2, trim), np.float32), mix, np.zeros((2, pad + trim), np.float32)], 1)
        total = (n + pad + gen - 1) // gen
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

    if args.check:
        background(np.zeros((2, sr), dtype=np.float32))
        print('ok')
        return

    source, rest_path, voices_path = args.paths
    started = time.time()
    mix = np.fromfile(source, dtype=np.float32).reshape(-1, 2).T.copy()
    rest = background(mix, report=True)
    np.clip(rest, -1, 1).T.astype(np.float32).tofile(rest_path)
    # The voices: everything the model did not keep, so the two together are the sound as it was.
    np.clip(mix - rest, -1, 1).T.astype(np.float32).tofile(voices_path)
    print('%.1f seconds of sound in %.1f seconds' % (mix.shape[1] / sr, time.time() - started), flush=True)


if __name__ == '__main__':
    main()
