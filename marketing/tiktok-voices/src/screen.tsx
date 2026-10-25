import React from 'react';
import { Easing, interpolate } from 'remotion';
import { C, H, W } from './theme';

// The studio page in a browser window, filmed by a camera that moves and zooms over it. Shared by
// the ad's studio scene and the studio tour. Positions on the page are fractions of it.
export type Box = { x: number; y: number; w: number; h: number };
export type Key = [number, number, number, number]; // [time, x, y, zoom]: where the camera looks
export const WIN = 980, BAR = 42;
export const clamp01 = { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' } as const;
const ease = Easing.bezier(0.65, 0, 0.35, 1);

export function camera(keys: Key[], t: number) {
  let i = 0;
  while (i < keys.length - 2 && t > keys[i + 1][0]) i++;
  const [t0, x0, y0, z0] = keys[i], [t1, x1, y1, z1] = keys[i + 1];
  const p = interpolate(t, [t0, t1], [0, 1], { ...clamp01, easing: ease });
  return { x: x0 + (x1 - x0) * p, y: y0 + (y1 - y0) * p, z: z0 * (z1 / z0) ** p };
}

// Where the window and the page sit on screen at zoom 1 (centred), and a box on the page there.
export function layout(pageH: number) {
  const wx = (W - WIN) / 2, wy = (H - pageH - BAR) / 2, py = wy + BAR;
  return { wx, wy, py, box: (b: Box) => ({ left: wx + b.x * WIN, top: py + b.y * pageH, width: b.w * WIN, height: b.h * pageH }) };
}

// The window's own bar: the three buttons and the address.
export const BrowserBar: React.FC = () => (
  <div style={{ height: BAR, background: '#F1F0F7', display: 'flex', alignItems: 'center', direction: 'ltr', padding: '0 18px', gap: 9 }}>
    {['#FF5F57', '#FEBC2E', '#28C840'].map((c) => <div key={c} style={{ width: 12, height: 12, borderRadius: 6, background: c }} />)}
    <div style={{ margin: '0 auto', width: 340, height: 24, borderRadius: 12, background: '#fff', fontSize: 13, fontWeight: 500, color: '#6B6880', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
      listenai.studio/ar/dashboard
    </div>
  </div>
);

// A ring on what a step touches, drawn on the page (so it is as thick at any zoom).
export const Ring: React.FC<{ at: { left: number; top: number; width: number; height: number }; on: number; z: number; r: number }> = ({ at, on, z, r }) => {
  if (on <= 0) return null;
  const pad = 8 / z + (1 - on) * (6 / z);
  return <div style={{ position: 'absolute', left: at.left - pad, top: at.top - pad, width: at.width + pad * 2, height: at.height + pad * 2, borderRadius: r, border: `${4 / z}px solid ${C.accent}`, opacity: on }} />;
};

// How far a ring is on at t: in from a, out from b.
export const ringOn = (t: number, a: number, b: number) =>
  interpolate(t, [a, a + 0.3], [0, 1], { ...clamp01, easing: Easing.bezier(0.16, 1, 0.3, 1) }) * (1 - interpolate(t, [b, b + 0.25], [0, 1], clamp01));

// A step in words, above the screen.
export const StepChip: React.FC<{ text: string; k: number; opacity?: number }> = ({ text, k, opacity = 1 }) => (
  <div style={{ position: 'absolute', top: 186, width: '100%', display: 'flex', justifyContent: 'center', opacity: k * opacity, translate: `0px ${(1 - k) * 24}px` }}>
    <div style={{ padding: '20px 44px', borderRadius: 999, background: C.ink, color: '#fff', fontSize: 54, fontWeight: 700, lineHeight: 1.25, boxShadow: '0 18px 40px rgba(15, 14, 23, 0.22)' }}>
      {text}
    </div>
  </div>
);
