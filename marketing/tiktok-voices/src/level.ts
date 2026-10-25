// The voice's loudness at a moment (0..1), from public/audio/vo_env.json once the voice track
// exists (build step); until then a gentle stand-in so the orb can be judged in stills.
import env from '../public/audio/vo_env.json';

const E = env as { fps: number; env: number[] };

export const levelAt = (t: number): number => {
  if (!E.env.length) return 0.35 + 0.25 * Math.sin(t * 9) * Math.sin(t * 2.3);
  const x = t * E.fps, i = Math.floor(x), f = x - i;
  const a = E.env[i] ?? 0, b = E.env[i + 1] ?? 0;
  return Math.max(0, Math.min(1, a + (b - a) * f));
};
