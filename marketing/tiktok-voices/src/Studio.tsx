import React from 'react';
import { AbsoluteFill, Easing, Img, interpolate, staticFile, useCurrentFrame } from 'remotion';
import boxesJson from '../public/studio/pg-boxes.json';
import { C, FONT, FPS, H, W } from './theme';

// The real studio page (desktop, light theme), in a browser window, as a short how-to: the
// dialect, typing, a feeling (an emotion tag), a laugh (a live sound tag), generate. Six real
// screen states change in place while the camera moves over the page, slowly enough to follow.
// Times are seconds from the start of the scene; positions are fractions of the page.
type Box = { x: number; y: number; w: number; h: number };
const B = (boxesJson as unknown as Record<string, Record<string, Box>>).eg;

const SLOW = 1.65;
const CAM: [number, number, number, number][] = (
  [[0, 0.5, 0.5, 1], [0.45, 0.5, 0.5, 1], [0.95, 0.5, 0.268, 3.0], [1.12, 0.5, 0.268, 3.05],
    [1.45, 0.725, 0.506, 3.1], [1.9, 0.722, 0.506, 3.15], [2.22, 0.67, 0.64, 2.6], [2.5, 0.67, 0.64, 2.62],
    [2.8, 0.76, 0.52, 3.0], [3.1, 0.76, 0.52, 3.02], [3.45, 0.73, 0.58, 2.7], [3.7, 0.73, 0.58, 2.72],
    [4.02, 0.7, 0.52, 3.0], [4.22, 0.7, 0.52, 3.02], [4.45, 0.6, 0.7, 2.0], [4.68, 0.47, 0.88, 2.8],
    [4.95, 0.47, 0.88, 2.85], [5.35, 0.5, 0.5, 1.02]] as [number, number, number, number][]
).map(([t, x, y, z]) => [t * SLOW, x, y, z]);
const STATES = [[0, 0], [1.2, 1], [2.05, 2], [2.62, 3], [3.4, 4], [3.92, 5]].map(([a, s]) => [a * SLOW, s]);
const STEP_AT = [0.6, 1.25, 2.0, 3.35, 4.45].map((a) => a * SLOW);
const RINGS: [keyof typeof B, number, number, number][] = [
  ['dialect', 0.75, 1.15, 10], ['emoItem', 2.22, 2.6, 6], ['emoTag', 2.7, 3.2, 4],
  ['laughItem', 3.55, 3.85, 6], ['laughTag', 3.98, 4.35, 4], ['button', 4.55, 5.0, 8],
].map(([k, a, b, r]) => [k as keyof typeof B, (a as number) * SLOW, (b as number) * SLOW, r as number]);

const ease = Easing.bezier(0.65, 0, 0.35, 1);
const clamp01 = { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' } as const;

function camera(t: number) {
  let i = 0;
  while (i < CAM.length - 2 && t > CAM[i + 1][0]) i++;
  const [t0, x0, y0, z0] = CAM[i], [t1, x1, y1, z1] = CAM[i + 1];
  const p = interpolate(t, [t0, t1], [0, 1], { ...clamp01, easing: ease });
  return { x: x0 + (x1 - x0) * p, y: y0 + (y1 - y0) * p, z: z0 * (z1 / z0) ** p };
}

const WIN = 980, BAR = 42, PAGE_H = WIN * (2052 / 3840);

export const Studio: React.FC<{ duration: number; steps: string[] }> = ({ duration, steps }) => {
  const t = useCurrentFrame() / FPS;
  const rise = interpolate(t, [0, 0.7], [0, 1], { ...clamp01, easing: Easing.bezier(0.16, 1, 0.3, 1) });
  const out = interpolate(t, [duration - 0.35, duration], [0, 1], clamp01);
  const cam = camera(t);
  const z = cam.z * (0.92 + 0.08 * rise);
  const wx = (W - WIN) / 2, wy = (H - PAGE_H - BAR) / 2, px = wx, py = wy + BAR;
  const fx = px + cam.x * WIN, fy = py + cam.y * PAGE_H;
  let si = 0;
  STATES.forEach(([from], i) => { if (t >= from) si = i; });
  const fade = si === 0 ? 1 : interpolate(t, [STATES[si][0], STATES[si][0] + 0.15], [0, 1], clamp01);
  const typed = interpolate(t, [1.22 * SLOW, 1.85 * SLOW], [0, 1], { ...clamp01, easing: Easing.out(Easing.cubic) });
  let k = -1;
  STEP_AT.forEach((a, i) => { if (t >= a) k = i; });
  const stepIn = k >= 0 ? interpolate(t, [STEP_AT[k], STEP_AT[k] + 0.35], [0, 1], { ...clamp01, easing: Easing.bezier(0.16, 1, 0.3, 1) }) : 0;
  const box = (b: Box) => ({ left: px + b.x * WIN, top: py + b.y * PAGE_H, width: b.w * WIN, height: b.h * PAGE_H });
  return (
    <AbsoluteFill style={{ fontFamily: FONT, direction: 'rtl', opacity: 1 - out }}>
      <div style={{
        position: 'absolute', left: 0, top: 0, width: W, height: H, transformOrigin: `${fx}px ${fy}px`,
        transform: `translate(${W / 2 - fx}px, ${H / 2 - fy + (1 - rise) * 260}px) scale(${z})`,
      }}>
        {/* the window */}
        <div style={{
          position: 'absolute', left: wx, top: wy, width: WIN, height: PAGE_H + BAR, borderRadius: 22, overflow: 'hidden',
          background: '#fff', boxShadow: '0 50px 120px rgba(36, 19, 138, 0.22), 0 8px 24px rgba(36, 19, 138, 0.08)',
          opacity: rise,
        }}>
          <div style={{ height: BAR, background: '#F1F0F7', display: 'flex', alignItems: 'center', direction: 'ltr', padding: '0 18px', gap: 9 }}>
            {['#FF5F57', '#FEBC2E', '#28C840'].map((c) => <div key={c} style={{ width: 12, height: 12, borderRadius: 6, background: c }} />)}
            <div style={{ margin: '0 auto', width: 340, height: 24, borderRadius: 12, background: '#fff', fontSize: 13, fontWeight: 500, color: '#6B6880', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              listenai.studio/ar/dashboard
            </div>
          </div>
          <div style={{ position: 'relative', width: WIN, height: PAGE_H }}>
            {si > 0 && fade < 1 && <Img src={staticFile(`studio/pg-eg-${STATES[si - 1][1]}.png`)} style={{ position: 'absolute', inset: 0, width: WIN, height: PAGE_H }} />}
            <Img src={staticFile(`studio/pg-eg-${STATES[si][1]}.png`)} style={{ position: 'absolute', inset: 0, width: WIN, height: PAGE_H, opacity: fade }} />
          </div>
        </div>
        {/* typing: the line appears right to left, the caret at its end */}
        {si === 1 && typed < 1 && (
          <>
            <div style={{ position: 'absolute', ...box(B.line), background: '#fff', width: B.line.w * WIN * (1 - typed) + 3 }} />
            <div style={{ position: 'absolute', left: px + (B.line.x + B.line.w * (1 - typed)) * WIN - 1, top: py + B.line.y * PAGE_H + 2, width: 1.4, height: B.line.h * PAGE_H - 4, background: C.accent }} />
          </>
        )}
        {/* a ring on what each step touches */}
        {RINGS.map(([key, a, b, r]) => {
          const on = interpolate(t, [a, a + 0.3], [0, 1], { ...clamp01, easing: Easing.bezier(0.16, 1, 0.3, 1) }) * (1 - interpolate(t, [b, b + 0.25], [0, 1], clamp01));
          if (on <= 0) return null;
          const bb = box(B[key]), pad = 8 / z + (1 - on) * (6 / z);
          return <div key={key} style={{ position: 'absolute', left: bb.left - pad, top: bb.top - pad, width: bb.width + pad * 2, height: bb.height + pad * 2, borderRadius: r, border: `${4 / z}px solid ${C.accent}`, opacity: on }} />;
        })}
        {/* the tap on generate */}
        {(() => {
          const tap = interpolate(t, [4.72 * SLOW, 4.72 * SLOW + 0.55], [0, 1], clamp01);
          if (tap <= 0 || tap >= 1) return null;
          const bb = box(B.button), cx = bb.left + bb.width / 2, cy = bb.top + bb.height / 2, r = 3 + tap * 26;
          return <div style={{ position: 'absolute', left: cx - r, top: cy - r, width: r * 2, height: r * 2, borderRadius: '50%', border: `${2.2 * (1 - tap)}px solid ${C.accent}`, opacity: 1 - tap }} />;
        })()}
      </div>
      {/* the step, in words, above the screen */}
      {k >= 0 && (
        <div style={{ position: 'absolute', top: 186, width: '100%', display: 'flex', justifyContent: 'center', opacity: stepIn * (1 - out), translate: `0px ${(1 - stepIn) * 24}px` }}>
          <div style={{ padding: '20px 44px', borderRadius: 999, background: C.ink, color: '#fff', fontSize: 54, fontWeight: 700, lineHeight: 1.25, boxShadow: '0 18px 40px rgba(15, 14, 23, 0.22)' }}>
            {steps[k]}
          </div>
        </div>
      )}
    </AbsoluteFill>
  );
};
