import React from 'react';
import { AbsoluteFill, Easing, Img, interpolate, staticFile, useCurrentFrame } from 'remotion';
import boxesJson from '../public/studio/v2-boxes.json';
import plan from './studio-plan.json';
import { C, FONT, FPS, H, W } from './theme';

// The real studio page (desktop, light theme) in a browser window, making Sumaya's clip exactly
// as it was made: find her, pick her (the Egyptian dialect), type the line, add the feeling, add
// the laugh, press Generate, and the voice is ready in the studio's player. Ten real screen states
// (public/studio/v2-*.png) change in place while the camera moves over the page, slowly enough
// to follow. Times are seconds from the start of the scene; positions are fractions of the page.
type Box = { x: number; y: number; w: number; h: number };
const B = boxesJson as unknown as Record<string, Box> & { size: [number, number] };

// [time, x, y, zoom]: wide, the library, the dialect, the text, the feelings, the text, the
// sounds, the text, Generate, the player. Long moves pull back a little on the way.
const CAM: [number, number, number, number][] = [
  [0, 0.5, 0.5, 1.04], [0.6, 0.5, 0.5, 1.05], [1.15, 0.24, 0.29, 2.6], [1.9, 0.24, 0.3, 2.62],
  [2.35, 0.44, 0.27, 2.6], [2.95, 0.44, 0.27, 2.62], [3.25, 0.56, 0.3, 1.9], [3.55, 0.7, 0.33, 2.9],
  [4.45, 0.7, 0.33, 2.92], [4.85, 0.68, 0.38, 2.5], [5.35, 0.68, 0.38, 2.52], [5.75, 0.72, 0.33, 2.9],
  [6.1, 0.72, 0.33, 2.92], [6.5, 0.72, 0.37, 2.5], [6.95, 0.72, 0.37, 2.52], [7.35, 0.67, 0.33, 2.7],
  [7.7, 0.67, 0.33, 2.72], [7.95, 0.57, 0.45, 1.8], [8.25, 0.46, 0.59, 2.6], [8.6, 0.46, 0.59, 2.62],
  [8.85, 0.55, 0.66, 2.0], [9.2, 0.66, 0.72, 2.9], [11.5, 0.66, 0.72, 2.95],
];
// when each screen state comes in, when each step's words appear above the screen (the last: the
// voice is ready), when the line is typed, when Generate is pressed (src/studio-plan.json)
const STATES = plan.states;
const STEP_AT = plan.steps;
// a ring on what each step touches: [box, in, out, corner radius]
const RINGS: [string, number, number, number][] = [
  ['search', 1.05, 1.6, 12], ['card', 1.9, 2.5, 16], ['dialect', 2.55, 3.05, 10], ['emoItem', 5.0, 5.35, 6],
  ['emoTag', 5.6, 6.05, 5], ['soundItem', 6.6, 6.95, 6], ['soundTag', 7.2, 7.65, 5], ['button', 7.95, 8.45, 8],
  ['result', 9.15, 99, 16],
];
const TYPE = plan.type;
const TAP = plan.tap;

const ease = Easing.bezier(0.65, 0, 0.35, 1);
const clamp01 = { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' } as const;

function camera(t: number) {
  let i = 0;
  while (i < CAM.length - 2 && t > CAM[i + 1][0]) i++;
  const [t0, x0, y0, z0] = CAM[i], [t1, x1, y1, z1] = CAM[i + 1];
  const p = interpolate(t, [t0, t1], [0, 1], { ...clamp01, easing: ease });
  return { x: x0 + (x1 - x0) * p, y: y0 + (y1 - y0) * p, z: z0 * (z1 / z0) ** p };
}

const WIN = 980, BAR = 42, PAGE_H = WIN * (B.size[1] / B.size[0]);

export const Studio: React.FC<{ duration: number; steps: string[] }> = ({ duration, steps }) => {
  const t = useCurrentFrame() / FPS;
  const rise = interpolate(t, [0, 0.7], [0, 1], { ...clamp01, easing: Easing.bezier(0.16, 1, 0.3, 1) });
  const out = interpolate(t, [duration - 0.35, duration], [0, 1], clamp01);
  const cam = camera(t);
  const z = cam.z * (0.92 + 0.08 * rise);
  const wx = (W - WIN) / 2, wy = (H - PAGE_H - BAR) / 2, px = wx, py = wy + BAR;
  const fx = px + cam.x * WIN, fy = py + cam.y * PAGE_H;
  let si = 0;
  STATES.forEach((from, i) => { if (t >= from) si = i; });
  const fade = si === 0 ? 1 : interpolate(t, [STATES[si], STATES[si] + 0.15], [0, 1], clamp01);
  const typed = interpolate(t, TYPE, [0, 1], { ...clamp01, easing: Easing.out(Easing.cubic) });
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
            {si > 0 && fade < 1 && <Img src={staticFile(`studio/v2-${si - 1}.png`)} style={{ position: 'absolute', inset: 0, width: WIN, height: PAGE_H }} />}
            <Img src={staticFile(`studio/v2-${si}.png`)} style={{ position: 'absolute', inset: 0, width: WIN, height: PAGE_H, opacity: fade }} />
          </div>
        </div>
        {/* typing: the line appears right to left, the caret at its end */}
        {si === 3 && typed < 1 && (
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
        {/* the tap on Generate */}
        {(() => {
          const tap = interpolate(t, [TAP, TAP + 0.55], [0, 1], clamp01);
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
