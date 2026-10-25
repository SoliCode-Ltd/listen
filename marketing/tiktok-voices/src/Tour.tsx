import React from 'react';
import { AbsoluteFill, Easing, Img, Sequence, interpolate, staticFile, useCurrentFrame } from 'remotion';
import v2Json from '../public/studio/v2-boxes.json';
import tourJson from '../public/tour/tour.json';
import { Background } from './Background';
import { EndCard } from './EndCard';
import { BAR, BrowserBar, Box, Key, Ring, StepChip, WIN, camera, clamp01, layout, ringOn } from './screen';
import { END } from './script';
import { C, FONT, FPS, H, W } from './theme';
import timeline from './tour-timeline.json';
import { VoiceTrack } from './VoiceTrack';

// The studio tour: the whole film stays in the studio (desktop, light theme). It opens on
// Sumaya's clip playing in the studio's own player, shows how it was made (the library, the
// dialects, the input with its tags, Generate), then each of the other five speakers with one of
// the studio's options (the library, the performance styles, the live sounds, the feelings, voice
// and rhythm), each clip playing in full in the player, then the history and the end card. Every
// screen is the real page (capture-tour.cjs, make-clips.cjs); the player is the real one, filmed
// while each clip played. Times come from src/tour-timeline.json (build_tour.py).

type VoiceBoxes = { card: Box; search: Box; dialect: Box; item?: Box; menu?: Box; marks: Box[]; text: Box; button: Box; result: Box; row: Box; play: [number, string][]; duration: number };
const T = tourJson as unknown as { size: [number, number]; voices: Record<string, VoiceBoxes>; history: { rows: Box[] } };
const V2 = v2Json as unknown as Record<string, Box>;
type Beat = { kind: string; slug?: string; start: number; end: number; audio?: number; lead?: number };
const TL = timeline as unknown as { beats: Beat[]; duration: number };

const PAGE_H = WIN * (T.size[1] / T.size[0]);
const L = layout(PAGE_H);
const centre = (b: Box) => [b.x + b.w / 2, b.y + b.h / 2];
const union = (bs: Box[]): Box => {
  const x = Math.min(...bs.map((b) => b.x)), y = Math.min(...bs.map((b) => b.y));
  const r = Math.max(...bs.map((b) => b.x + b.w)), d = Math.max(...bs.map((b) => b.y + b.h));
  return { x, y, w: r - x, h: d - y };
};
// the typed line: from its first tag to the end of the text box's first line (the text is right-aligned)
const lineOf = (v: VoiceBoxes): Box => {
  const m = union(v.marks);
  return { x: Math.min(m.x, v.text.x + v.text.w * 0.3), y: m.y - 0.004, w: v.text.x + v.text.w - 0.012 - Math.min(m.x, v.text.x + v.text.w * 0.3), h: m.h + 0.008 };
};

const STEP: Record<string, string> = {
  open: 'جرّبنا استوديو إسمع', library: 'اختار من 155 صوت', dialect: '147 لهجة من 24 دولة',
  type: 'اكتب… وزوّد إحساس وضحكة', generate: 'ودوس توليد', history: 'وكل أصواتك محفوظة',
};
const OPTION: Record<string, string> = {
  'abdulaziz-proud': 'غيّر الصوت واللهجة', 'sawsan-calm': '42 أسلوب أداء', 'abdelilah-surprised': 'ضحكة حقيقية وسط الكلام',
  'sundus-sad': 'حزن… وتنهيدة', 'hamed-whisper': 'وحتى الهمس',
};

// The plan, from the timeline: which page image shows from when, where the camera looks, the
// rings, the steps' words, the players.
type Ringed = { box: Box; a: number; b: number; r: number };
type Player = { row: Box; audio: number; lead: number; play: [number, string][]; until: number };
const shots: { img: string; at: number }[] = [];
const cam: Key[] = [];
const rings: Ringed[] = [];
const steps: { text: string; at: number }[] = [];
const players: Player[] = [];
let typing = [0, 0], tap = -1;
// Close up, the page always fills the frame (no background around the window): the camera is
// kept far enough inside the page for its zoom. A wide shot (the page smaller than the frame)
// stays centred.
const fit = (x: number, y: number, z: number) => {
  const hx = W / 2 / (WIN * z), hy = H / 2 / (PAGE_H * z);
  const keep = (v: number, h: number) => (h < 0.5 ? Math.min(Math.max(v, h), 1 - h) : 0.5);
  return [keep(x, hx), keep(y, hy)];
};
const look = (t: number, x: number, y: number, z: number) => { const [fx, fy] = fit(x, y, z); cam.push([t, fx, fy, z]); };
const hold = (t: number) => { const [, x, y, z] = cam[cam.length - 1]; look(t, x, y, z); };
const step = (text: string, at: number) => { if (!steps.length || steps[steps.length - 1].text !== text) steps.push({ text, at }); };

// a voice playing: one close shot with its input at the top and the player at the bottom, pushing
// in slowly; a ring on the input as it starts, then on the player
const SIDE = 0.645; // the input's side of the page (it is right-aligned), with the player's words
const playing = (slug: string, b: Beat, from: number) => {
  const v = T.voices[slug];
  const audio = b.audio as number, len = b.end - audio;
  rings.push({ box: lineOf(v), a: from + 0.25, b: audio + len * 0.4, r: 10 }, { box: v.row, a: audio + len * 0.45, b: b.end, r: 26 });
  look(from + 0.45, SIDE, 1, 2.85);
  look(b.end, SIDE, 1, 2.95);
  players.push({ row: v.row, audio, lead: b.lead as number, play: v.play, until: b.end });
};

for (const b of TL.beats) {
  const s = b.start;
  if (b.kind === 'open') {
    shots.push({ img: `tour/res-${b.slug}.png`, at: s });
    look(s, SIDE, 1, 2.6);
    step(STEP.open, s);
    playing(b.slug as string, b, s);
  } else if (b.kind === 'library' && !b.slug) {
    hold(s);
    look(s + 0.5, 0.5, 0.5, 1.1);
    hold(s + 0.65);
    look(s + 1.15, 0.24, 0.29, 2.8);
    shots.push({ img: 'studio/v2-0.png', at: s + 0.2 }, { img: 'studio/v2-1.png', at: s + 1.0 }, { img: 'studio/v2-2.png', at: s + 1.5 });
    rings.push({ box: V2.search, a: s + 1.0, b: s + 1.45, r: 12 }, { box: V2.card, a: s + 1.55, b: b.end, r: 16 });
    step(STEP.library, s);
  } else if (b.kind === 'dialect') {
    shots.push({ img: 'tour/opt-dialect.png', at: s });
    hold(s);
    look(s + 0.4, 0.6, 0.5, 2.6);
    rings.push({ box: T.voices['sumaya-excited'].item as Box, a: s + 0.45, b: b.end, r: 12 });
    step(STEP.dialect, s);
  } else if (b.kind === 'type') {
    shots.push({ img: 'studio/v2-3.png', at: s }, { img: 'studio/v2-5.png', at: s + 0.95 }, { img: 'studio/v2-7.png', at: s + 1.45 });
    hold(s);
    look(s + 0.35, 0.7, 0.33, 2.9);
    typing = [s + 0.1, s + 0.75];
    rings.push({ box: V2.emoTag, a: s + 1.0, b: s + 1.45, r: 5 }, { box: V2.soundTag, a: s + 1.5, b: b.end, r: 5 });
    step(STEP.type, s);
  } else if (b.kind === 'generate') {
    const v = T.voices['sumaya-excited'];
    hold(s);
    look(s + 0.3, 0.5, 0.59, 2.9);
    rings.push({ box: V2.button, a: s + 0.1, b: s + 0.55, r: 8 }, { box: v.result, a: s + 1.15, b: b.end, r: 16 });
    tap = s + 0.35;
    shots.push({ img: 'studio/v2-8.png', at: s + 0.45 }, { img: 'tour/res-sumaya-excited.png', at: s + 1.0 });
    hold(s + 0.95);
    look(s + 1.35, SIDE, 1, 2.85);
    step(STEP.generate, s);
  } else if (b.slug && b.kind !== 'voice') {
    // an option, before its voice
    const v = T.voices[b.slug];
    hold(s);
    if (b.kind === 'library') {
      shots.push({ img: `tour/pick-${b.slug}.png`, at: s });
      look(s + 0.4, 0.36, 0.26, 2.6);
      rings.push({ box: v.card, a: s + 0.35, b: s + 0.85, r: 16 }, { box: v.dialect, a: s + 0.85, b: b.end, r: 10 });
    } else {
      shots.push({ img: `tour/opt-${b.kind}.png`, at: s });
      const [ix, iy] = centre(v.item as Box);
      if (b.kind === 'style') look(s + 0.4, 0.6, 0.5, 2.6);
      else look(s + 0.4, ix, iy - 0.04, 2.9);
      rings.push({ box: v.item as Box, a: s + 0.45, b: b.end, r: 8 });
    }
    step(OPTION[b.slug], s);
  } else if (b.kind === 'voice') {
    shots.push({ img: `tour/res-${b.slug}.png`, at: s });
    hold(s);
    playing(b.slug as string, b, s);
  } else if (b.kind === 'history') {
    const rows = union(T.history.rows), [hx, hy] = centre(rows);
    shots.push({ img: 'tour/history.png', at: s });
    hold(s);
    look(s + 0.45, Math.min(hx + 0.12, 0.6), hy, 2.7);
    rings.push({ box: rows, a: s + 0.5, b: b.end, r: 14 });
    step(STEP.history, s);
  } else if (b.kind === 'end') {
    hold(b.end);
  }
}
const endBeat = TL.beats.find((b) => b.kind === 'end') as Beat;

// The studio in its window, with the camera on it.
const Screen: React.FC = () => {
  const t = useCurrentFrame() / FPS;
  const out = interpolate(t, [endBeat.start - 0.35, endBeat.start], [0, 1], clamp01);
  const c = camera(cam, t);
  const z = c.z;
  const { wx, wy, py, box } = L;
  const fx = wx + c.x * WIN, fy = py + c.y * PAGE_H;
  let si = 0;
  shots.forEach((s, i) => { if (t >= s.at) si = i; });
  const fade = si === 0 ? 1 : interpolate(t, [shots[si].at, shots[si].at + 0.15], [0, 1], clamp01);
  const typed = interpolate(t, typing, [0, 1], { ...clamp01, easing: Easing.out(Easing.cubic) });
  let k = -1;
  steps.forEach((s, i) => { if (t >= s.at) k = i; });
  const stepIn = k >= 0 ? interpolate(t, [steps[k].at, steps[k].at + 0.35], [0, 1], { ...clamp01, easing: Easing.bezier(0.16, 1, 0.3, 1) }) : 0;
  // the player, while its clip plays: the image of the row as it was at that moment of the clip
  const player = players.find((p) => t >= p.audio - 0.2 && t < p.until + 0.5 && shots[si].img.startsWith('tour/res-'));
  const frameOf = (p: Player) => {
    const at = t - p.audio + p.lead;
    let f: string | null = null;
    for (const [when, file] of p.play) if (when <= at) f = file;
    return f;
  };
  const playFile = player ? frameOf(player) : null;
  return (
    <AbsoluteFill style={{ fontFamily: FONT, direction: 'rtl', opacity: 1 - out }}>
      <div style={{ position: 'absolute', inset: 0, transformOrigin: `${fx}px ${fy}px`, transform: `translate(${W / 2 - fx}px, ${H / 2 - fy}px) scale(${z})` }}>
        <div style={{
          position: 'absolute', left: wx, top: wy, width: WIN, height: PAGE_H + BAR, borderRadius: 22, overflow: 'hidden',
          background: '#fff', boxShadow: '0 50px 120px rgba(36, 19, 138, 0.22), 0 8px 24px rgba(36, 19, 138, 0.08)',
        }}>
          <BrowserBar />
          <div style={{ position: 'relative', width: WIN, height: PAGE_H }}>
            {si > 0 && fade < 1 && <Img src={staticFile(shots[si - 1].img)} style={{ position: 'absolute', inset: 0, width: WIN, height: PAGE_H }} />}
            <Img src={staticFile(shots[si].img)} style={{ position: 'absolute', inset: 0, width: WIN, height: PAGE_H, opacity: fade }} />
          </div>
        </div>
        {playFile && player && <Img src={staticFile(`tour/${playFile}`)} style={{ position: 'absolute', ...box(player.row) }} />}
        {/* typing: the line appears right to left, the caret at its end */}
        {shots[si].img === 'studio/v2-3.png' && typed < 1 && (
          <>
            <div style={{ position: 'absolute', ...box(V2.line), background: '#fff', width: V2.line.w * WIN * (1 - typed) + 3 }} />
            <div style={{ position: 'absolute', left: wx + (V2.line.x + V2.line.w * (1 - typed)) * WIN - 1, top: py + V2.line.y * PAGE_H + 2, width: 1.4, height: V2.line.h * PAGE_H - 4, background: C.accent }} />
          </>
        )}
        {rings.map((r, i) => <Ring key={i} at={box(r.box)} on={ringOn(t, r.a, r.b)} z={z} r={r.r} />)}
        {(() => {
          const p = interpolate(t, [tap, tap + 0.55], [0, 1], clamp01);
          if (tap < 0 || p <= 0 || p >= 1) return null;
          const bb = box(V2.button), cx = bb.left + bb.width / 2, cy = bb.top + bb.height / 2, r = 3 + p * 26;
          return <div style={{ position: 'absolute', left: cx - r, top: cy - r, width: r * 2, height: r * 2, borderRadius: '50%', border: `${2.2 * (1 - p)}px solid ${C.accent}`, opacity: 1 - p }} />;
        })()}
      </div>
      {k >= 0 && <StepChip text={steps[k].text} k={stepIn} opacity={1 - out} />}
    </AbsoluteFill>
  );
};

export const Tour: React.FC = () => {
  const f = (s: number) => Math.round(s * FPS);
  return (
    <AbsoluteFill>
      <Background />
      <Sequence durationInFrames={f(endBeat.start + 0.05)} name="The studio">
        <Screen />
      </Sequence>
      <Sequence from={f(endBeat.start)} name="End card">
        <EndCard title={END.title} question={END.question} level={(t) => 0.22 + 0.12 * Math.sin(t * 3.2)} />
      </Sequence>
      <VoiceTrack src="audio/tour_mix.wav" />
    </AbsoluteFill>
  );
};

export const TOUR_DURATION = TL.duration;
