import React from 'react';
import { AbsoluteFill, Easing, Img, interpolate, staticFile, useCurrentFrame } from 'remotion';
import { C, FONT, FPS, PALETTES, W } from './theme';
import { Orb } from './Orb';

// The end card: the Listen orb, the logo, the invitation, the address, the question.
export const EndCard: React.FC<{ title: string; question: string }> = ({ title, question }) => {
  const t = useCurrentFrame() / FPS;
  const k = (a: number) => interpolate(t, [a, a + 0.6], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.bezier(0.16, 1, 0.3, 1) });
  const rise = (a: number, dy = 40) => ({ opacity: k(a), translate: `0px ${(1 - k(a)) * dy}px` });
  return (
    <AbsoluteFill style={{ fontFamily: FONT, direction: 'rtl', alignItems: 'center' }}>
      <div style={{ position: 'absolute', top: 330, left: (W - 420) / 2, scale: `${0.85 + 0.15 * k(0)}`, opacity: k(0), filter: 'drop-shadow(0 40px 80px rgba(118, 89, 250, 0.3))' }}>
        <Orb size={420} palette={PALETTES.listen} time={t * 1.4 + 20} level={0.25 + 0.15 * Math.sin(t * 4)} seed={2.1} />
      </div>
      <div style={{ position: 'absolute', top: 820, ...rise(0.15, 30) }}>
        <Img src={staticFile('brand/logo-ar-light.svg')} style={{ height: 120 }} />
      </div>
      <div style={{ position: 'absolute', top: 1000, fontSize: 132, fontWeight: 800, color: C.ink, lineHeight: 1.25, ...rise(0.3) }}>{title}</div>
      <div style={{ position: 'absolute', top: 1220, ...rise(0.45) }}>
        <div style={{ padding: '26px 64px', borderRadius: 999, background: C.accent, color: '#fff', fontSize: 58, fontWeight: 700, direction: 'ltr', boxShadow: '0 24px 50px rgba(118, 89, 250, 0.35)' }}>
          listenai.studio
        </div>
      </div>
      <div style={{ position: 'absolute', top: 1400, fontSize: 46, fontWeight: 600, color: C.muted, ...rise(0.65, 24) }}>{question}</div>
    </AbsoluteFill>
  );
};
