import React from 'react';
import { AbsoluteFill, Easing, Img, interpolate, spring, staticFile, useCurrentFrame } from 'remotion';
import { C, FONT, FPS, PALETTES, W } from './theme';
import { Orb, mixPalette } from './Orb';
import { HOOK, TimedVoice, VOICES } from './script';
import { levelAt } from './level';

// The hook and the six voices on one stage. One orb stays through it all: it changes colour for
// each speaker and ripples with their voice. Everything else has its own row, so nothing ever
// sits on anything else: what the voice is for at the top, the orb with room above and below it,
// the speaker centred under it, the line. All above the area TikTok covers with its own buttons.
const ROW = { chips: 168, orb: 344, orbSize: 560, card: 994, line: 1188 };

const ease = Easing.bezier(0.16, 1, 0.3, 1);
const inOut = (t: number, a: number, b: number) => interpolate(t, [a, b], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: ease });

// An element entering at `a` and leaving at `b` (seconds): rises in, lifts away.
const enter = (t: number, a: number, b: number, dy = 36) => {
  const k = inOut(t, a, a + 0.55), out = inOut(t, b - 0.28, b);
  return { opacity: k * (1 - out), translate: `0px ${(1 - k) * dy - out * 24}px` };
};

const Chip: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <div style={{
    padding: '18px 34px', borderRadius: 999, fontSize: 44, fontWeight: 600, lineHeight: 1.2,
    background: C.card, color: C.ink, border: `2px solid ${C.line}`, boxShadow: '0 6px 24px rgba(36, 19, 138, 0.06)',
  }}>{children}</div>
);

// The speaker, centred under the orb: the avatar with their flag, their name and dialect. No box.
const SpeakerCard: React.FC<{ v: TimedVoice }> = ({ v }) => (
  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 26 }}>
    <div style={{ position: 'relative', width: 112, height: 112, flex: 'none' }}>
      <Img src={staticFile(`speakers/${v.avatar}.webp`)} style={{ width: 112, height: 112, borderRadius: '50%', boxShadow: '0 14px 34px rgba(36, 19, 138, 0.18)' }} />
      <Img src={staticFile(`flags/${v.flag}.svg`)} style={{ position: 'absolute', left: -2, bottom: -2, width: 42, height: 42, borderRadius: '50%', border: `4px solid ${C.bg}`, objectFit: 'cover' }} />
    </div>
    <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
      <div style={{ fontSize: 56, fontWeight: 700, color: C.ink, lineHeight: 1.3 }}>{v.name}</div>
      <div style={{ fontSize: 38, fontWeight: 500, color: C.muted, lineHeight: 1.3 }}>{v.dialect}</div>
    </div>
  </div>
);

// The line exactly as it was typed in the studio, tags and all, marked as the studio's editor
// marks them: a feeling tag in amber, a sound tag in violet. Each word darkens as it is said and
// each tag as it is performed. Rows break where the script says (\n).
const MARK = { delivery: 'rgba(242, 181, 68, 0.28)', sound: 'rgba(106, 69, 242, 0.16)' };
type Token = { kind: 'word' | 'delivery' | 'sound'; text: string };
const tokens = (row: string): Token[] =>
  row.trim().split(/(\[[^\]]+\]|<[^>]+>)/).flatMap((piece): Token[] => {
    if (piece.startsWith('[')) return [{ kind: 'delivery', text: piece }];
    if (piece.startsWith('<')) return [{ kind: 'sound', text: piece }];
    return piece.split(' ').filter(Boolean).map((text) => ({ kind: 'word' as const, text }));
  });

const Line: React.FC<{ v: TimedVoice; t: number }> = ({ v, t }) => {
  const rows = v.input.split('\n').map(tokens);
  const lt = t - v.audio;
  const size = v.size ?? 68;
  let wi = 0, si = 0;
  return (
    <div style={{ width: 940, textAlign: 'center', fontSize: size, fontWeight: 700, lineHeight: 1.5, whiteSpace: 'nowrap' }}>
      {rows.map((row, r) => (
        <div key={r}>
          {row.map((k, i) => {
            // a feeling tag is performed from the first word, a sound tag when it is heard
            const at = k.kind === 'word' ? v.words[wi++] : k.kind === 'delivery' ? v.words[0] : v.sounds[si++];
            const lit = inOut(lt, at - 0.02, at + 0.14);
            const gap = i < row.length - 1 ? ' ' : '';
            if (k.kind === 'word') {
              return <span key={i} style={{ color: lit > 0.5 ? C.ink : C.faint, opacity: 0.55 + 0.45 * lit }}>{k.text}{gap}</span>;
            }
            return (
              <React.Fragment key={i}>
                <span style={{
                  fontWeight: 600, color: C.ink, opacity: 0.6 + 0.4 * lit, padding: '0 0.14em', borderRadius: 14,
                  background: MARK[k.kind], boxShadow: `0 0 0 3px ${MARK[k.kind]}`,
                }}>{k.text}</span>{gap}
              </React.Fragment>
            );
          })}
        </div>
      ))}
    </div>
  );
};

export const Stage: React.FC = () => {
  const frame = useCurrentFrame();
  const t = frame / FPS;
  const vi = VOICES.findIndex((v) => t >= v.start && t < v.end);
  const v = vi >= 0 ? VOICES[vi] : null;
  // the orb's colour: the Listen orb for the hook, then each speaker's, blended over 0.5 s
  const pal = (i: number) => (i < 0 ? PALETTES.listen : PALETTES[VOICES[i].palette]);
  const cur = v ? vi : -1;
  const from = v ? (vi === 0 ? -1 : vi - 1) : -1;
  const blend = v ? inOut(t, v.start, v.start + 0.5) : 1;
  const palette = mixPalette(pal(from), pal(cur), blend);
  const level = levelAt(t);
  // the orb lands once, at the start, then breathes with the voice
  const land = spring({ frame, fps: FPS, config: { damping: 18, stiffness: 120 }, delay: 2 });
  const breathe = 1 + level * 0.05;
  const orbOut = inOut(t, VOICES[VOICES.length - 1].end - 0.3, VOICES[VOICES.length - 1].end);
  return (
    <AbsoluteFill style={{ fontFamily: FONT, direction: 'rtl' }}>
      {/* the orb */}
      <div style={{
        position: 'absolute', top: ROW.orb, left: (W - ROW.orbSize) / 2, width: ROW.orbSize, height: ROW.orbSize,
        scale: `${(0.6 + 0.4 * land) * breathe * (1 - orbOut * 0.15)}`, opacity: Math.min(1, land * 1.4) * (1 - orbOut),
        filter: `drop-shadow(0 40px 80px ${palette[1]}40)`,
      }}>
        <Orb size={ROW.orbSize} palette={palette} time={t * 1.4} level={level} seed={1.7} lift={level * 0.12} />
      </div>
      {/* the hook */}
      {t < HOOK.end && (
        <>
          <div style={{ position: 'absolute', top: ROW.chips - 6, width: '100%', display: 'flex', justifyContent: 'center', ...enter(t, -0.3, HOOK.end, 20) }}>
            <Img src={staticFile('brand/logo-ar-light.svg')} style={{ height: 96 }} />
          </div>
          <div style={{ position: 'absolute', top: ROW.card + 20, left: 80, right: 80, textAlign: 'center' }}>
            <div style={{ fontSize: 118, fontWeight: 800, color: C.ink, lineHeight: 1.3, ...enter(t, -0.25, HOOK.end, 50) }}>{HOOK.title}</div>
            <div style={{ marginTop: 26, fontSize: 60, fontWeight: 600, color: C.accent, lineHeight: 1.45, whiteSpace: 'pre-line', ...enter(t, 0.55, HOOK.end, 40) }}>{HOOK.sub}</div>
          </div>
        </>
      )}
      {/* the speaker */}
      {v && (
        <>
          <div style={{ position: 'absolute', top: ROW.chips, width: '100%', display: 'flex', justifyContent: 'center', gap: 18, ...enter(t, v.start - 0.05, v.end, 24) }}>
            <Chip>{v.use}</Chip>
          </div>
          <div style={{ position: 'absolute', top: ROW.card, width: '100%', display: 'flex', justifyContent: 'center', ...enter(t, v.start + 0.06, v.end, 40) }}>
            <SpeakerCard v={v} />
          </div>
          <div style={{ position: 'absolute', top: ROW.line, width: '100%', display: 'flex', justifyContent: 'center', ...enter(t, v.start + 0.12, v.end, 40) }}>
            <Line v={v} t={t} />
          </div>
        </>
      )}
    </AbsoluteFill>
  );
};
