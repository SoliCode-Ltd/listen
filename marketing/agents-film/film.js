// Listen agents, test scene (Arabic): a visitor asks, the card opens, the agent answers out loud.
// Pure function of time: window.seek(t) paints frame t. Timing in beats (u), 120 bpm.
// Everything on screen from the product is the product: the card is the real widget, captured
// as layers (assets/card/<lang>, geo.json holds each layer's place in the panel), and the voice
// is a real recorded call from the homepage (audio/vo_<lang>.json: its loudness, 60 a second).
import * as M from './lib/motion.js';

const { W, H, E, prog, lerp, clamp, spring, springU, SPRING, wobble, font, text, fill, rrect, mix, hash01 } = M;

const C = { paper: '#FBFAFF', ink: '#17144A', accent: '#7659FA', soft: '#E9E4FF', card: '#1A1730', muted: '#8C88B5' };
const FACE = 'Display';
const VO_AT = 4.5;                         // seconds: where the recorded answer starts (beat 9)
const PANEL = { w: 344, h: 560, r: 26 };   // the card, in its own (CSS) pixels
const S = 1.5;                             // card pixels -> film pixels

// What is said, as lines to read; times come from the recording's two breaths (vo.voiced).
const SAID = [
  { seg: 0, lines: [['وعليكم', 'السلام'], ['ورحمة', 'الله', 'وبركاته.']] },
  { seg: 1, lines: [['أيوه', 'طبعًا،'], ['سحب', 'العينات', 'من', 'البيت']] },
  { seg: 1, lines: [['متاح', 'في', 'القاهرة'], ['والجيزة']] },
  { seg: 1, lines: [['بزيادة'], ['100', 'جنيه.']], strong: ['100', 'جنيه.'] },
];
const HOOK = ['عميلك', 'يسأل.'];
const TAG = ['يرد', 'على', 'كل', 'مكالمة،', 'بلهجة', 'عملائك.'];

const HITS = [
  [0, 'hook 1', 'pop', { pitch: 'D5' }],
  [1, 'hook 2', 'pop', { pitch: 'A5' }],
  [2, 'button lands', 'thud'],
  [3.5, 'tap', 'tick'],
  [4, 'card opens', 'whoosh', { len: 0.45, from: 500, to: 2600 }],
  [5.6, 'greeting', 'blip', { pitch: 'A5' }],
  [6.6, 'question', 'pop', { pitch: 'E5' }],
  [7.5, 'tap call', 'tick'],
  [8, 'the call', 'impact'],
  [24, 'end card', 'whoosh', { len: 0.5, from: 2400, to: 500 }],
  [24.5, 'logo', 'bell', { pitch: 'D6' }],
];

let VO = { env: [], voiced: [] }, GEO = {}, WORDS = [];
const sec = (u) => M.TS(u);                       // beats -> seconds
const beat = (t) => M.U(t);                       // seconds -> beats
// The voice's loudness at film time t (0 outside the recording), a little smoothed.
function loud(t) {
  const x = (t - VO_AT) * 60, i = Math.floor(x);
  if (i < 0 || i >= VO.env.length - 1) return 0;
  const a = VO.env[i], b = VO.env[i + 1];
  return lerp(a, b, x - i);
}
const voice = (t) => Math.pow((loud(t - 0.03) + loud(t) * 2 + loud(t + 0.03)) / 4, 0.7);

// Each word's moment: spread over its breath by its length.
function timeWords() {
  const out = [];
  const bySeg = {};
  SAID.forEach((p) => { (bySeg[p.seg] = bySeg[p.seg] || []).push(...p.lines.flat()); });
  const at = {};
  Object.keys(bySeg).forEach((k) => {
    const [a, b] = VO.voiced[k] || [0, 1], words = bySeg[k];
    const len = words.map((w) => w.replace(/[^؀-ۿA-Za-z0-9]/g, '').length + 1.2);
    const total = len.reduce((x, y) => x + y, 0);
    let acc = 0;
    at[k] = words.map((w, i) => { const t = a + (b - a) * (acc / total); acc += len[i]; return VO_AT + t; });
  });
  const used = {};
  SAID.forEach((p, pi) => {
    used[p.seg] = used[p.seg] || 0;
    p.lines.forEach((line, li) => line.forEach((w, wi) => { out.push({ p: pi, li, wi, w, t: at[p.seg][used[p.seg]++], strong: (p.strong || []).includes(w) }); }));
  });
  return out;
}

// ---------------------------------------------------------------- pieces
function layer(ctx, img, g, alpha = 1) {
  if (!img || !g || alpha <= 0) return;
  ctx.globalAlpha *= alpha; ctx.drawImage(img, g.x, g.y, g.w, g.h); ctx.globalAlpha /= alpha;
}
// A tap: a soft dot that presses, and a ring that leaves it.
function tap(ctx, u, u0, x, y) {
  const p = prog(u, u0 - 0.5, u0 + 1.4);
  if (p <= 0 || p >= 1) return;
  const press = E.outCubic(prog(u, u0 - 0.5, u0)), ring = E.outCubic(prog(u, u0, u0 + 1.2));
  ctx.save();
  ctx.globalAlpha = (1 - prog(u, u0 + 0.5, u0 + 1.4)) * 0.9;
  ctx.fillStyle = 'rgba(255,255,255,.55)'; ctx.strokeStyle = 'rgba(255,255,255,.95)'; ctx.lineWidth = 3;
  ctx.beginPath(); ctx.arc(x, y, lerp(30, 17, press), 0, M.TAU); ctx.fill(); ctx.stroke();
  if (ring > 0) { ctx.globalAlpha *= 1 - ring; ctx.strokeStyle = C.accent; ctx.lineWidth = 5; ctx.beginPath(); ctx.arc(x, y, 20 + ring * 90, 0, M.TAU); ctx.stroke(); }
  ctx.restore();
}
// The brand's waves: rounded bars, tall in the middle, moving with the voice.
function waves(ctx, t, u, x0, x1, cy, n, maxH, barW, born, gone) {
  const idle = 0.07 + 0.035 * Math.sin(t * 2.2);
  for (let i = 0; i < n; i++) {
    const f = n === 1 ? 0.5 : i / (n - 1), mid = 1 - Math.abs(f - 0.5) * 2;
    const k = springU(u, born + (1 - mid) * 0.9, SPRING.bouncy) * (1 - E.inCubic(prog(u, gone + mid * 0.5, gone + 0.7 + mid * 0.5)));
    if (k <= 0.001) continue;
    const v = voice(t - (1 - mid) * 0.09);
    const shape = 0.3 + 0.7 * Math.pow(mid, 0.8);
    const live = 0.55 + 0.45 * Math.sin(i * 1.7 + t * 9 + hash01(i, 5) * 6.28);
    const h = Math.max(barW, (idle * (0.5 + 0.5 * Math.sin(i * 0.8 + t * 3)) + v * live * 1.45) * shape * maxH * k + barW);
    const x = lerp(x0, x1, f);
    ctx.fillStyle = mix(C.soft, C.accent, clamp(0.25 + mid * 0.75));
    rrect(ctx, x - barW / 2, cy - h / 2, barW, h, barW / 2); ctx.fill();
  }
}
// A line of Arabic words, right to left from xr, each word arriving on its own moment.
function words(ctx, list, xr, y, f, gap, draw) {
  ctx.font = f; ctx.direction = 'rtl'; ctx.textAlign = 'right';
  let x = xr;
  list.forEach((w, i) => { const wd = ctx.measureText(w.w || w).width; draw(w, x, y, i, wd); x -= wd + gap; });
  ctx.direction = 'ltr';
}

// ---------------------------------------------------------------- the card
function card(ctx, u, t, IMG) {
  const open = springU(u, 4, SPRING.gentle);                       // from the button
  if (open <= 0.001) return;
  const aside = springU(u, 8, SPRING.gentle);                      // the call: it steps aside
  const leave = E.inBack(prog(u, 23.6, 24.6), 1.2);
  const k = S * lerp(0.2, 1, open) * lerp(1.16, 0.98, aside) * (1 + 0.012 * wobble(sec(u) - sec(8), 2.2, 6));
  const cx = lerp(960, 400, aside), bottom = lerp(1080 - 150, 1080 - 26, open) - aside * 60 + leave * 1300;
  const push = 1 + 0.035 * E.inOutSine(prog(u, 4.5, 8));           // a slow push in while it is read
  ctx.save();
  ctx.translate(cx, bottom); ctx.scale(k * push, k * push); ctx.translate(-PANEL.w / 2, -PANEL.h);
  ctx.globalAlpha = clamp(open * 1.6);
  // its shadow, then the panel as it is
  ctx.save(); ctx.shadowColor = 'rgba(23,20,74,.30)'; ctx.shadowBlur = 70 * k; ctx.shadowOffsetY = 34 * k;
  rrect(ctx, 0, 0, PANEL.w, PANEL.h, PANEL.r); ctx.fillStyle = C.card; ctx.fill(); ctx.restore();
  // (clipped to its own corners: the capture carries a little of the page's shadow outside them)
  ctx.save(); rrect(ctx, 0, 0, PANEL.w, PANEL.h, PANEL.r); ctx.clip(); ctx.drawImage(IMG.panel, 0, 0, PANEL.w, PANEL.h); ctx.restore();

  const onCall = springU(u, 8, SPRING.snappy), asked = springU(u, 6.6, SPRING.snappy);
  // where the lines sit: the greeting alone, then with the question, then under the call's strip
  const gy = lerp(lerp(382.1, 295.9, clamp(springU(u, 6.6, SPRING.gentle))), GEO.greetOnCall.y, onCall);
  const my = lerp(380.1, GEO.meOnCall.y, onCall);
  ctx.save(); rrect(ctx, GEO.lines.x - 4, GEO.lines.y, GEO.lines.w + 8, GEO.lines.h, 8); ctx.clip();
  // the agent's dots, then its greeting written out from them (it grows from the dots' place)
  const dots = springU(u, 4.7, SPRING.bouncy), write = E.outCubic(prog(u, 5.6, 6.5));
  if (dots > 0.01) {
    const g = GEO.greet, d = GEO.dots, right = g.x + g.w, bot = gy + g.h;
    const w = lerp(d.w, g.w, write), h = lerp(d.h, g.h, E.outCubic(prog(u, 5.6, 6.0)));
    ctx.save(); ctx.translate(right, bot); ctx.scale(dots, dots); ctx.translate(-right, -bot);
    ctx.save(); rrect(ctx, right - w, bot - h, w, h, 18); ctx.clip();
    if (write <= 0) { ctx.drawImage(IMG.dots, right - d.w, bot - d.h, d.w, d.h); }
    else ctx.drawImage(IMG.greet, g.x, gy, g.w, g.h);
    ctx.restore();
    if (write <= 0.02) for (let i = 0; i < 3; i++) {
      const ph = (sec(u) * 1.25 - i * 0.16) % 1, a = 0.25 + 0.6 * Math.max(0, Math.sin(ph * M.TAU));
      ctx.fillStyle = `rgba(255,255,255,${a})`; ctx.beginPath(); ctx.arc(right - 19 - i * 11, bot - d.h / 2 - Math.max(0, Math.sin(ph * M.TAU)) * 2, 3, 0, M.TAU); ctx.fill();
    }
    ctx.restore();
  }
  // the visitor's question pops from its own corner
  if (asked > 0.01) {
    const g = GEO.me;
    ctx.save(); ctx.translate(g.x, my + g.h); ctx.scale(asked, asked); ctx.translate(-g.x, -(my + g.h));
    ctx.globalAlpha *= clamp(asked * 2); ctx.drawImage(IMG.me, g.x, my, g.w, g.h); ctx.restore();
  }
  const noted = springU(u, 9, SPRING.snappy);
  if (noted > 0.01) { const g = GEO.note; ctx.save(); ctx.globalAlpha *= clamp(noted); ctx.drawImage(IMG.note, g.x, g.y + (1 - noted) * 10, g.w, g.h); ctx.restore(); }
  ctx.restore();
  // the call: the button turns to hang up, the strip opens, its small waves follow the voice
  if (onCall > 0.01) {
    layer(ctx, IMG.hangup, GEO.hcall, clamp(onCall * 2));
    const g = GEO.strip;
    ctx.save(); rrect(ctx, g.x, g.y, g.w, g.h * clamp(onCall), 16); ctx.clip();
    ctx.globalAlpha *= clamp(onCall * 1.5); ctx.drawImage(IMG.strip, g.x, g.y, g.w, g.h);
    const v = GEO.viz;
    for (let i = 0; i < 5; i++) {
      const sh = [0.45, 0.8, 1, 0.8, 0.45][i], h = 5 + (0.12 + voice(t) * (0.6 + 0.4 * Math.sin(i * 2 + t * 10))) * sh * 22;
      ctx.fillStyle = '#B9A8FF'; rrect(ctx, v.x + 4 + i * 5.2, v.y + v.h / 2 - h / 2, 3, h, 1.5); ctx.fill();
    }
    ctx.restore();
  }
  ctx.restore();
  // taps (in film space): the button that opens it, then the call button
  const kk = k * push, hx = cx + (GEO.hcall.x + GEO.hcall.w / 2 - PANEL.w / 2) * kk, hy = bottom - (PANEL.h - GEO.hcall.y - GEO.hcall.h / 2) * kk;
  tap(ctx, u, 7.5, hx, hy);
}

// The website's button, before the card: it lands, is pressed, and the card comes out of it.
function launcher(ctx, u, IMG) {
  const land = springU(u, 2, SPRING.bouncy);
  if (land <= 0.001) return;
  const g = GEO.launch, k = 2.5, gone = E.outCubic(prog(u, 4, 4.5));
  const press = 1 - 0.06 * E.outCubic(prog(u, 3.3, 3.5)) + 0.06 * E.outCubic(prog(u, 3.5, 3.9));
  const x = 960, y = 1080 - 230 - (1 - land) * 120;
  ctx.save(); ctx.globalAlpha = clamp(land * 2) * (1 - gone);
  ctx.translate(x, y); ctx.scale(k * press * lerp(1, 0.7, gone), k * press * lerp(1, 0.7, gone));
  ctx.save(); ctx.shadowColor = 'rgba(23,20,74,.28)'; ctx.shadowBlur = 44; ctx.shadowOffsetY = 20;
  rrect(ctx, -g.w / 2, -g.h / 2, g.w, g.h, g.h / 2); ctx.fillStyle = C.card; ctx.fill(); ctx.restore();
  ctx.drawImage(IMG.launch, -g.w / 2, -g.h / 2, g.w, g.h);
  ctx.restore();
  tap(ctx, u, 3.5, x + 70, y + 6);
}

// ---------------------------------------------------------------- type
function hook(ctx, u) {
  const out = E.inCubic(prog(u, 3.7, 4.4));
  if (out >= 1) return;
  const f = font(210, 800, FACE);
  ctx.save(); ctx.globalAlpha = 1 - out;
  ctx.font = f; ctx.direction = 'rtl';
  const hw = HOOK.reduce((a, w) => a + ctx.measureText(w).width, 0) + 54 * (HOOK.length - 1);
  words(ctx, HOOK, W / 2 + hw / 2, 500 - out * 90, f, 54, (w, x, y, i) => {
    const k = springU(u, i - 0.08, SPRING.bouncy);
    if (k <= 0) return;
    ctx.save(); ctx.translate(x, y); ctx.scale(lerp(0.86, 1, k), lerp(0.86, 1, k)); ctx.globalAlpha *= clamp(k * 2.2);
    ctx.fillStyle = i === 1 ? C.accent : C.ink; ctx.fillText(w, 0, (1 - k) * 90); ctx.restore();
  });
  ctx.restore();
}
function said(ctx, u, t) {
  if (u < 8.8) return;
  const xr = 1800, top = 560, maxW = 1000;
  const gone = E.inCubic(prog(u, 23.2, 23.9));
  SAID.forEach((p, pi) => {
    const mine = WORDS.filter((w) => w.p === pi), first = mine[0].t;
    if (t < first - 0.05) return;
    const next = WORDS.find((w) => w.p === pi + 1), after = WORDS.find((w) => w.p === pi + 2);
    // the size its widest line allows
    ctx.font = font(100, 800, FACE); ctx.direction = 'rtl';
    const wide = Math.max(...p.lines.map((l) => l.reduce((a, w) => a + ctx.measureText(w).width, 0) + 30 * (l.length - 1)));
    const size = Math.min(p.strong ? 150 : 112, 100 * maxW / wide), lh = size * 1.36, f = font(size, 800, FACE);
    // lifted when the next phrase starts, gone when the one after it does
    const lift = next ? spring(t - (next.t - 0.26), SPRING.snappy) : 0, out = after ? clamp((t - (after.t - 0.3)) / 0.22) : 0;
    if (out >= 1) return;
    ctx.save(); ctx.globalAlpha = lerp(1, 0.3, clamp(lift)) * (1 - out) * (1 - gone);
    const y0 = top - lift * 300 - out * 50 - gone * 60, sc = lerp(1, 0.62, clamp(lift));
    ctx.translate(xr, y0); ctx.scale(sc, sc); ctx.translate(-xr, -y0);
    p.lines.forEach((line, li) => {
      words(ctx, mine.filter((w) => w.li === li), xr, y0 + li * lh, f, size * 0.29, (w, x, y) => {
        const k = spring(t - w.t, SPRING.snappy);
        if (k <= 0) return;
        const now = !lift && t >= w.t && t < w.t + 0.34;
        ctx.save(); ctx.globalAlpha *= clamp(k * 2.4);
        ctx.fillStyle = (w.strong && lift < 0.5) || now ? C.accent : C.ink;
        ctx.fillText(w.w, x, y + (1 - k) * 46); ctx.restore();
      });
    });
    ctx.restore();
  });
  // who is speaking, small, above the words
  const who = springU(u, 9, SPRING.gentle) * (1 - gone);
  if (who > 0.01) { ctx.save(); ctx.globalAlpha = clamp(who); ctx.direction = 'rtl'; text(ctx, 'سمية · وكيلة معمل نور، بلهجة مصرية', xr, 138 + (1 - who) * 20, font(46, 600, FACE), '#6F6A9C', 'right'); ctx.restore(); }
}
function endCard(ctx, u, IMG) {
  if (u < 24) return;
  const k = springU(u, 24.4, SPRING.bouncy);
  if (IMG.logo && k > 0.01) {
    const w = 660, h = w * 128 / 307;
    ctx.save(); ctx.translate(W / 2, 410); ctx.scale(lerp(0.7, 1, k), lerp(0.7, 1, k)); ctx.globalAlpha = clamp(k * 2);
    ctx.drawImage(IMG.logo, -w / 2, -h / 2, w, h); ctx.restore();
  }
  const f = font(78, 700, FACE);
  ctx.font = f; ctx.direction = 'rtl';
  const total = TAG.reduce((a, w) => a + ctx.measureText(w).width, 0) + 26 * (TAG.length - 1);
  words(ctx, TAG, W / 2 + total / 2, 670, f, 26, (w, x, y, i) => {
    const p = springU(u, 25 + i * 0.22, SPRING.snappy);
    if (p <= 0) return;
    ctx.save(); ctx.globalAlpha = clamp(p * 2); ctx.fillStyle = i >= 4 ? C.accent : C.ink; ctx.fillText(w, x, y + (1 - p) * 34); ctx.restore();
  });
  const up = springU(u, 26.6, SPRING.gentle);
  if (up > 0.01) { ctx.save(); ctx.globalAlpha = clamp(up); text(ctx, 'listenai.studio', W / 2, 800 + (1 - up) * 16, font(40, 600, FACE), C.muted, 'center'); ctx.restore(); }
}

M.film({
  fonts: [font(100, 800, FACE), font(40, 600, FACE), font(60, 700, FACE)],
  images: { panel: 'assets/card/ar/panel.png', greet: 'assets/card/ar/greet.png', dots: 'assets/card/ar/dots.png', me: 'assets/card/ar/me.png', note: 'assets/card/ar/note.png',
    strip: 'assets/card/ar/strip.png', hangup: 'assets/card/ar/hangup.png', launch: 'assets/card/ar/launch.png', logo: 'assets/logo-ar.svg' },
  hits: HITS,
  async init() {
    [VO, GEO] = await Promise.all([fetch('audio/vo_ar.json').then((r) => r.json()), fetch('assets/card/ar/geo.json').then((r) => r.json())]);
    // Arabic needs its own glyphs loaded before the first frame
    await document.fonts.load(font(100, 800, FACE), 'عميلك يسأل 100');
    WORDS = timeWords();
  },
  draw(ctx, u, t, IMG) {
    fill(ctx, C.paper);
    // the big waves: born on the call, gone for the end card
    // the whole frame takes the drop, lightly
    const hit = 1 + 0.016 * wobble(t - sec(8), 1.9, 5);
    M.zoomAbout(ctx, hit, W / 2, H / 2);
    if (u >= 7.6) waves(ctx, t, u, 800, 1800, 900, 34, 300, 17, 8, 23.3);
    hook(ctx, u);
    launcher(ctx, u, IMG);
    card(ctx, u, t, IMG);
    said(ctx, u, t);
    endCard(ctx, u, IMG);
  },
});
