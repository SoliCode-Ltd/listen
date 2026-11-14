// Listen agents (Arabic): a calm launch film. Pure function of time: window.seek(t) paints frame t.
// 90 bpm; scenes change on the beat (u), words and the voice run in seconds (t).
//
// The look follows the reference the owner chose (docs/reference): soft flowing colour fields,
// small light type that comes in a word at a time, white cards on the colour, an orb beside the
// conversation, a circle that opens onto the colour. The colour is Listen's own violet, the face
// is Listen's (Alexandria), and what is said is a real recorded call from the homepage
// (audio/vo_ar.json: its loudness 60 times a second, and the moment of each word).
import * as M from './lib/motion.js';

const { W, H, E, prog, lerp, clamp, font, fill, rrect, hash01, mix } = M;

const C = { paper: '#F4F3F7', ink: '#14121F', quiet: '#B5B2C4', label: '#8B88A0', accent: '#7659FA', white: '#FFFFFF' };
const FACE = 'Display';
const sec = (u) => M.TS(u);

// Four calls, four dialects (lines.php): a customer asks, the business's agent answers. Each
// agent's scene takes one of the orb colours an owner can pick (AgentWidget::PALETTES). When each
// scene starts and each word is said comes from the recordings (build_audio.py -> audio/vo.json).
const CAST = {
  eg: { pal: 'violet', orb: 'orb', agent: 'سمية', who: 'شحن المدار', dialect: 'مصرية', her: 'العميل' },
  sa: { pal: 'emerald', orb: 'emeraldOrb', agent: 'فهد', who: 'معرض الريادة للسيارات', dialect: 'سعودية', her: 'العميلة' },
  kw: { pal: 'ocean', orb: 'oceanOrb', agent: 'فاطمة', who: 'فندق المرسى', dialect: 'كويتية', her: 'العميل' },
  lb: { pal: 'rose', orb: 'roseOrb', agent: 'رائد', who: 'مكتب البحر العقاري', dialect: 'لبنانية', her: 'العميلة' },
};
let CALLS = [], SC = {};
const DIALECTS = ['مصرية', 'سعودية', 'كويتية', 'لبنانية', 'إماراتية', 'مغربية', 'عراقية', 'أردنية', 'سودانية'];
const SAVED = { title: 'حُفظ موعد المعاينة', name: 'ريم', phone: '03 123 456', note: 'شقة الأشرفية، بكرا 11 الصبح' };
const LINE = [['يرد', 'على', 'كل', 'مكالمة،'], ['بلهجة', 'عملائك.']];
const TRY = ['جرّبه', 'على', 'موقعك', 'اليوم.'];

// Every accent: [beat, label, sfx, opts]. The opening is fixed; the rest follows the recordings
// (filled in once they are read, below).
const HITS = [
  [0, 'mark', 'blip', { pitch: 'D5' }],
  [2.4, 'name', 'blip', { pitch: 'A5' }],
];

let VO = { env: [], calls: [], scenes: {} }, LOGO = null, GRAIN = null;

// ---------------------------------------------------------------- the voice
function loud(t) {
  const x = t * 60, i = Math.floor(x);
  if (i < 0 || i >= VO.env.length - 1) return 0;
  return lerp(VO.env[i], VO.env[i + 1], x - i);
}
// Smoothed over a tenth of a second: the orb breathes with the voice, it does not flicker.
const voice = (t) => { let a = 0; for (let k = -3; k <= 3; k++) a += loud(t + k * 0.017); return Math.pow(a / 7, 0.75); };

// ---------------------------------------------------------------- the colour field
// A soft silk of the brand's violets, flowing slowly: drawn small from a few long waves bent by
// one another along a slant, then stretched over the frame (so it is smooth by construction).
const SILK = { w: 160, h: 90, cv: null, cx: null, img: null };
const PAL = {
  violet: [[23, 14, 92], [58, 36, 176], [118, 89, 250], [176, 158, 255], [236, 230, 255]],
  lilac: [[92, 66, 214], [140, 116, 252], [190, 174, 255], [226, 216, 255], [250, 247, 255]],
  orb: [[58, 36, 176], [104, 76, 240], [140, 116, 252], [190, 174, 255], [232, 224, 255]],
  emerald: [[3, 44, 34], [6, 78, 59], [16, 185, 129], [140, 232, 196], [232, 255, 244]],
  emeraldOrb: [[6, 78, 59], [16, 185, 129], [94, 214, 170], [167, 243, 208], [240, 255, 248]],
  ocean: [[6, 34, 68], [11, 59, 110], [30, 136, 229], [143, 211, 255], [232, 246, 255]],
  oceanOrb: [[11, 59, 110], [30, 136, 229], [96, 178, 244], [143, 211, 255], [238, 249, 255]],
  rose: [[78, 14, 40], [131, 24, 67], [236, 72, 153], [249, 168, 212], [255, 236, 246]],
  roseOrb: [[131, 24, 67], [236, 72, 153], [244, 126, 184], [249, 168, 212], [255, 240, 247]],
  night: [[12, 8, 46], [34, 20, 110], [84, 58, 204], [132, 106, 250], [196, 182, 255]],
};
function ramp(pal, v) {
  const x = clamp(v) * (pal.length - 1), i = Math.min(pal.length - 2, Math.floor(x)), f = x - i, s = f * f * (3 - 2 * f);
  const a = pal[i], b = pal[i + 1];
  return [a[0] + (b[0] - a[0]) * s, a[1] + (b[1] - a[1]) * s, a[2] + (b[2] - a[2]) * s];
}
function silkInto(t, pal, seed, zoom, bias) {
  if (!SILK.cv) { SILK.cv = document.createElement('canvas'); SILK.cv.width = SILK.w; SILK.cv.height = SILK.h; SILK.cx = SILK.cv.getContext('2d'); SILK.img = SILK.cx.createImageData(SILK.w, SILK.h); }
  const d = SILK.img.data, ca = Math.cos(-0.62), sa = Math.sin(-0.62), z = zoom || 1;
  for (let y = 0, o = 0; y < SILK.h; y++) {
    for (let x = 0; x < SILK.w; x++, o += 4) {
      const nx = ((x / SILK.w) * 2 - 1) * 1.78 / z, ny = ((y / SILK.h) * 2 - 1) / z;
      const px = nx * ca - ny * sa, py = (nx * sa + ny * ca) * 2.3;      // long along the slant
      const w1 = Math.sin(py * 1.1 + seed + t * 0.11 + 1.6 * Math.sin(px * 0.7 - t * 0.07 + seed * 2));
      const w2 = Math.sin(px * 0.9 - seed * 3 + t * 0.05 + 1.3 * Math.sin(py * 0.8 + t * 0.09));
      const w3 = Math.sin((px + py) * 1.9 + seed * 5 - t * 0.13 + w1 * 1.2);
      const v = 0.5 + 0.30 * w1 + 0.17 * w2 + 0.09 * w3 - 0.10 * ny + (bias || 0);
      const c = ramp(pal, v);
      d[o] = c[0]; d[o + 1] = c[1]; d[o + 2] = c[2]; d[o + 3] = 255;
    }
  }
  SILK.cx.putImageData(SILK.img, 0, 0);
}
function silk(ctx, t, pal, seed, x, y, w, h, zoom, bias) {
  silkInto(t, pal, seed, zoom, bias);
  ctx.imageSmoothingEnabled = true; ctx.imageSmoothingQuality = 'high';
  // (a little larger than its place, so its edge pixels never show)
  ctx.drawImage(SILK.cv, 1, 1, SILK.w - 2, SILK.h - 2, x, y, w, h);
}

// ---------------------------------------------------------------- type
// A word being said: how present it is (it starts to appear a breath before its moment) and how
// far it has taken its full strength (quiet at first, then lit). Both are smooth curves in time,
// so nothing switches from one frame to the next.
const smooth = (x) => { const c = clamp(x); return c * c * (3 - 2 * c); };
const present = (t, at) => smooth((t - (at - 0.1)) / 0.34);
const lit = (t, at) => smooth((t - (at + 0.06)) / 0.5);

// Words right to left from xr. Each word: { w, a (0..1 how present), c (colour) }.
function measure(ctx, list, f, gap) {
  ctx.font = f; ctx.direction = 'rtl';
  return list.reduce((a, w) => a + ctx.measureText(w.w || w).width, 0) + gap * (list.length - 1);
}
function line(ctx, list, xr, y, f, gap) {
  ctx.font = f; ctx.direction = 'rtl'; ctx.textAlign = 'right';
  let x = xr;
  list.forEach((w) => {
    const wd = ctx.measureText(w.w).width;
    if (w.a > 0.004) { ctx.globalAlpha = w.a; ctx.fillStyle = w.c; ctx.fillText(w.w, x, y + (w.dy || 0)); }
    x -= wd + gap;
  });
  ctx.globalAlpha = 1;
}
// A sentence in the middle of the frame, a word at a time: each word waits quiet, then is said.
function sentence(ctx, wordsList, t, t0, every, y, size, quiet, loudC, weight = 500) {
  const f = font(size, weight, FACE), gap = size * 0.3;
  const total = measure(ctx, wordsList, f, gap);
  const here = clamp((t - (t0 - 0.35)) / 0.4);
  // quiet and loud are the same colour at two strengths (white on the colour) or two colours (on paper)
  const onColour = loudC === C.white;
  line(ctx, wordsList.map((w, i) => {
    const p = smooth((t - (t0 + i * every)) / 0.55);
    return onColour ? { w, a: here * lerp(0.42, 1, p), c: C.white } : { w, a: here, c: mix(quiet, loudC, p) };
  }), W / 2 + total / 2, y, f, gap);
}
// Text for a card: broken into lines once, right to left.
function wrap(ctx, str, f, gap, maxW) {
  ctx.font = f; ctx.direction = 'rtl';
  const out = [[]]; let w = 0;
  str.split(' ').forEach((word) => {
    const wd = ctx.measureText(word).width;
    if (out[out.length - 1].length && w + gap + wd > maxW) { out.push([]); w = 0; }
    w += (out[out.length - 1].length ? gap : 0) + wd;
    out[out.length - 1].push(word);
  });
  return out;
}

// ---------------------------------------------------------------- pieces
// The brand's mark: seven bars (public/images/logo-ar-light.svg), each rising in turn.
function mark(ctx, t, t0, cx, cy, k, color) {
  const hs = [10, 44, 72, 100, 72, 44, 10];
  hs.forEach((h, i) => {
    const p = smooth((t - t0 - Math.abs(i - 3) * 0.08) / 0.7);
    if (p <= 0) return;
    const hh = lerp(10, h, p) * k, x = cx + (i - 3) * 18 * k;
    ctx.globalAlpha = clamp(p * 2); ctx.fillStyle = color;
    rrect(ctx, x - 5 * k, cy - hh / 2, 10 * k, hh, 5 * k); ctx.fill();
  });
  ctx.globalAlpha = 1;
}
function logoWhite(ctx, cx, cy, w, a) {
  if (!LOGO || a <= 0) return;
  const h = w * LOGO.height / LOGO.width;
  ctx.globalAlpha = a; ctx.drawImage(LOGO, cx - w / 2, cy - h / 2, w, h); ctx.globalAlpha = 1;
}
// The orb: the same silk seen through a sphere, lit from above, breathing with the voice.
function orb(ctx, t, cx, cy, r, pal, glow, heard) {
  const v = heard ? voice(t) : 0, rr = r * (1 + 0.07 * v);
  ctx.save();
  ctx.shadowColor = `rgba(${glow[0]},${glow[1]},${glow[2]},.30)`; ctx.shadowBlur = 70; ctx.shadowOffsetY = 34;
  ctx.beginPath(); ctx.arc(cx, cy, rr, 0, M.TAU); ctx.fillStyle = `rgb(${glow[0]},${glow[1]},${glow[2]})`; ctx.fill();
  ctx.restore();
  ctx.save(); ctx.beginPath(); ctx.arc(cx, cy, rr, 0, M.TAU); ctx.clip();
  silk(ctx, t * 1.8 + 40, pal, 3.1, cx - rr * 1.5, cy - rr * 1.5, rr * 3, rr * 3, 1.25);
  let g = ctx.createRadialGradient(cx - rr * 0.35, cy - rr * 0.45, rr * 0.05, cx, cy, rr * 1.05);
  g.addColorStop(0, 'rgba(255,255,255,.42)'); g.addColorStop(0.45, 'rgba(255,255,255,.04)'); g.addColorStop(1, `rgba(${Math.round(glow[0] * 0.3)},${Math.round(glow[1] * 0.3)},${Math.round(glow[2] * 0.4)},.5)`);
  ctx.fillStyle = g; ctx.fillRect(cx - rr, cy - rr, rr * 2, rr * 2);
  ctx.restore();
}
// A card of the conversation: who, then their words as they come. kind: 'her' (white) or 'agent' (glass).
// As tall as the lines that have begun (and, as it arrives, it takes its room little by little).
function talkHeight(t, card) {
  const a = smooth((t - card.t0) / 0.6);
  if (a <= 0) return 0;
  let lines = 0;
  card.lines.forEach((ln, i) => { lines += i === 0 ? 1 : smooth((t - ln[0].t + 0.3) / 0.6); });
  return (62 + lines * 54 + 22) * a;
}
function talk(ctx, t, card, x, y, w) {
  const a = smooth((t - card.t0) / 0.6);
  if (a <= 0) return 0;
  const pad = 30, lh = 54, top = 62;
  const h = talkHeight(t, card) / a;
  ctx.save(); ctx.globalAlpha = a; ctx.translate(0, (1 - a) * 18);
  if (card.kind === 'her') {
    ctx.save(); ctx.shadowColor = 'rgba(20,10,80,.22)'; ctx.shadowBlur = 50; ctx.shadowOffsetY = 20;
    rrect(ctx, x, y, w, h, 22); ctx.fillStyle = C.white; ctx.fill(); ctx.restore();
  } else {
    rrect(ctx, x, y, w, h, 22); ctx.fillStyle = 'rgba(14,10,44,.34)'; ctx.fill();
    ctx.lineWidth = 1.5; ctx.strokeStyle = 'rgba(255,255,255,.30)'; ctx.stroke();
  }
  const ink = card.kind === 'her' ? C.ink : C.white, quiet = card.kind === 'her' ? C.quiet : 'rgba(255,255,255,.55)';
  // who: a dot and a name
  ctx.fillStyle = card.kind === 'her' ? '#C4C1D2' : 'rgba(255,255,255,.85)'; ctx.beginPath(); ctx.arc(x + w - pad - 6, y + 36, 6, 0, M.TAU); ctx.fill();
  ctx.font = font(22, 500, FACE); ctx.direction = 'rtl'; ctx.textAlign = 'right'; ctx.fillStyle = card.kind === 'her' ? C.label : 'rgba(255,255,255,.78)';
  ctx.fillText(card.who, x + w - pad - 22, y + 44);
  ctx.save(); rrect(ctx, x, y, w, h, 22); ctx.clip();
  const f = font(34, 500, FACE);
  card.lines.forEach((ln, i) => {
    line(ctx, ln.map((wd) => {
      const p = present(t, wd.t), l = lit(t, wd.t);
      return card.kind === 'her' ? { w: wd.w, a: p * a, c: mix(C.quiet, C.ink, l), dy: (1 - p) * 5 }
        : { w: wd.w, a: p * a * lerp(0.5, 1, l), c: C.white, dy: (1 - p) * 5 };
    }), x + w - pad, y + top + 36 + i * lh, f, 10);
  });
  ctx.restore();
  ctx.restore();
  return h * a;
}

// ---------------------------------------------------------------- scenes
function sceneMark(ctx, t, IMG) {               // the mark's bars rise; the name writes itself beside them
  fill(ctx, C.paper);
  // the logo's own geometry (public/images/logo-ar-light.svg, 307 x 128): bars around x 64, the name from x 140
  const k = 1.7, lw = 307 * k, lh = 128 * k, x0 = (W - lw) / 2, y0 = (H - lh) / 2;
  const join = smooth((t - 1.25) / 0.9);                      // the bars make room for the name
  const bx = lerp(W / 2, x0 + 64 * k, join);
  mark(ctx, t, 0.15, bx, H / 2, k, C.accent);
  if (IMG.logo && join > 0) {
    const show = smooth((t - 1.5) / 0.9), from = x0 + 140 * k;
    ctx.save();
    ctx.beginPath(); ctx.rect(from, y0 - 20, (lw - 140 * k + 30) * show, lh + 40); ctx.clip();
    ctx.globalAlpha = show; ctx.drawImage(IMG.logo, x0 - (1 - show) * 26, y0, lw, lh);
    ctx.restore();
  }
}
function sceneColour(ctx, t, u0, pal, seed, withUrl) {   // the logo on the colour
  const t0 = sec(u0), drift = 1.06 + 0.02 * (t - t0);
  silk(ctx, t, pal, seed, 0, 0, W, H, drift);
  const a = E.outCubic(clamp((t - t0 - 0.15) / 0.7));
  logoWhite(ctx, W / 2, H / 2 - (withUrl ? 30 : 0), 380 + 14 * E.outCubic(clamp((t - t0) / 3)), a);
  if (withUrl) {
    const b = E.outCubic(clamp((t - t0 - 1.2) / 0.6));
    ctx.globalAlpha = b * 0.9; ctx.font = font(34, 400, FACE); ctx.direction = 'ltr'; ctx.textAlign = 'center'; ctx.fillStyle = C.white;
    ctx.fillText('listenai.studio', W / 2, H / 2 + 130); ctx.globalAlpha = 1;
  }
}
function sceneCircle(ctx, t) {                  // a circle opens onto the colour, the line is said in it
  const t0 = sec(SC.line[0]), t1 = sec(SC.line[1]);
  fill(ctx, C.paper);
  const grow = E.inOutCubic(clamp((t - (t1 - 1.5)) / 1.5));
  const r = lerp(330, 356, E.outCubic(clamp((t - t0) / 2.5))) + grow * 950;
  // two fine arcs around it, as it settles
  const ar = E.outCubic(clamp((t - t0 - 0.2) / 1.2)) * (1 - grow);
  if (ar > 0) { ctx.strokeStyle = `rgba(20,18,31,${0.16 * ar})`; ctx.lineWidth = 2; [[-0.5, 0.5], [2.64, 3.64]].forEach(([a, b]) => { ctx.beginPath(); ctx.arc(W / 2, H / 2, r + 46, a, lerp(a, b, ar)); ctx.stroke(); }); }
  ctx.save(); ctx.beginPath(); ctx.arc(W / 2, H / 2, r, 0, M.TAU); ctx.clip();
  silk(ctx, t, PAL.violet, 1.3, 0, 0, W, H, 1.1);
  ctx.restore();
  sentence(ctx, LINE[0], t, t0 + 0.6, 0.34, H / 2 - 16, 46, 'rgba(255,255,255,.45)', C.white);
  sentence(ctx, LINE[1], t, t0 + 0.6 + 4 * 0.34, 0.34, H / 2 + 52, 46, 'rgba(255,255,255,.45)', C.white);
}
let TALK = {};
function sceneTalk(ctx, t, D) {                 // someone asks, the agent answers out loud, in its dialect
  const t0 = sec(D.u0), t1 = sec(D.u1), pal = PAL[D.pal];
  fill(ctx, C.paper);
  // the first time, the colour steps back into its panel on the right; after that it is there
  const p = D.first ? E.inOutCubic(clamp((t - t0) / 0.9)) : 1, m = 28 * p;
  const px = lerp(0, 700, p), pw = W - px - m, py = m, ph = H - 2 * m;
  ctx.save(); rrect(ctx, px, py, pw, ph, 34 * p); ctx.clip();
  silk(ctx, t, pal, 1.3 + D.u0 * 0.37, 0, 0, W, H, 1.1 + 0.004 * (t - t0), D.first ? lerp(0, -0.2, p) : -0.2);
  ctx.restore();
  // the orb's own panel: the agent, where it works, how it speaks
  const op = D.first ? E.outCubic(clamp((t - t0 - 0.25) / 0.8)) : 1, inn = E.outCubic(clamp((t - t0) / 0.7));
  if (op > 0) {
    ctx.globalAlpha = op; rrect(ctx, 28, 28, 644, H - 56, 34); ctx.fillStyle = C.white; ctx.fill(); ctx.globalAlpha = 1;
    ctx.save(); ctx.globalAlpha = op * inn; orb(ctx, t, 350, H / 2 - 46, 150 * lerp(0.9, 1, inn), PAL[D.orb], pal[2], t > D.answer.at - 0.1 && t < D.answer.end + 0.2); ctx.restore();
    const name = E.outCubic(clamp((t - t0 - (D.first ? 0.9 : 0.25)) / 0.6));
    ctx.globalAlpha = name; ctx.direction = 'rtl'; ctx.textAlign = 'center';
    ctx.font = font(40, 600, FACE); ctx.fillStyle = C.ink; ctx.fillText(D.agent, 350, H / 2 + 206);
    ctx.font = font(25, 400, FACE); ctx.fillStyle = C.label; ctx.fillText(D.who, 350, H / 2 + 252);
    // the dialect, in a quiet pill
    ctx.font = font(24, 500, FACE);
    const tw = ctx.measureText(D.dialect).width + 44;
    rrect(ctx, 350 - tw / 2, H / 2 + 284, tw, 46, 23); ctx.fillStyle = `rgba(${pal[2][0]},${pal[2][1]},${pal[2][2]},.12)`; ctx.fill();
    ctx.fillStyle = `rgb(${pal[1][0]},${pal[1][1]},${pal[1][2]})`; ctx.fillText(D.dialect, 350, H / 2 + 316);
    ctx.globalAlpha = 1;
  }
  // the conversation, in the middle of its panel whatever its height just now
  const cards = TALK[D.key], x = px + (pw - 780) / 2, w = 780;
  let all = 0;
  cards.forEach((card) => { const h = talkHeight(t, card); if (h) all += h + 22; });
  let y = H / 2 - Math.max(0, all - 22) / 2;
  cards.forEach((card) => { const h = talk(ctx, t, card, x, y, w); if (h) y += h + 22; });
  // the last scene lets go softly, into the colour that follows
  if (D.last) { const out = E.inCubic(clamp((t - (t1 - 0.3)) / 0.3)); if (out > 0) { ctx.globalAlpha = out; fill(ctx, C.paper); ctx.globalAlpha = 1; } }
}
// Every dialect: their names pass, one after another, on the brand's colour.
function sceneDialects(ctx, t) {
  const t0 = sec(SC.dialects[0]), t1 = sec(SC.dialects[1]);
  silk(ctx, t, PAL.violet, 2.9, 0, 0, W, H, 1.08 + 0.01 * (t - t0));
  const f = font(120, 500, FACE), gap = 110;
  ctx.font = f; ctx.direction = 'rtl'; ctx.textAlign = 'right';
  const ws = DIALECTS.map((d) => ctx.measureText(d).width), total = ws.reduce((a, b) => a + b, 0) + gap * (ws.length - 1);
  // they travel from the left to the right (the way Arabic is read), slowing a little to be read
  const k = (t - t0) / (t1 - t0), shift = lerp(0.12, 0.62, k * 0.75 + 0.25 * E.inOutSine(k)) * total;
  let x = W * 0.5 + shift + ws[0] / 2;
  DIALECTS.forEach((d, i) => {
    const mid = x - ws[i] / 2, far = Math.abs(mid - W / 2) / (W * 0.42);
    ctx.globalAlpha = clamp(1 - far) * 0.85 + 0.12; ctx.fillStyle = C.white;
    if (mid > -400 && mid < W + 400) ctx.fillText(d, x, H / 2 + 40);
    x -= ws[i] + gap;
  });
  ctx.globalAlpha = 1;
}
function sceneSaved(ctx, t) {                   // what the business is left with
  const t0 = sec(SC.saved[0]);
  silk(ctx, t, PAL.lilac, 4.2, 0, 0, W, H, 1.08 + 0.015 * (t - t0));
  const a = E.outCubic(clamp((t - t0 - 0.1) / 0.6)), w = 820, h = 430, x = (W - w) / 2, y = (H - h) / 2 + (1 - a) * 22;
  ctx.save(); ctx.globalAlpha = a;
  ctx.save(); ctx.shadowColor = 'rgba(40,20,130,.28)'; ctx.shadowBlur = 80; ctx.shadowOffsetY = 34;
  rrect(ctx, x, y, w, h, 30); ctx.fillStyle = C.white; ctx.fill(); ctx.restore();
  // its title, with a tick
  const xr = x + w - 54;
  ctx.fillStyle = C.accent; ctx.beginPath(); ctx.arc(xr - 22, y + 84, 24, 0, M.TAU); ctx.fill();
  const tk = E.outCubic(clamp((t - t0 - 0.5) / 0.35));
  ctx.strokeStyle = C.white; ctx.lineWidth = 4.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
  ctx.beginPath(); ctx.moveTo(xr - 33, y + 85); ctx.lineTo(lerp(xr - 33, xr - 25, clamp(tk * 2)), lerp(y + 85, y + 93, clamp(tk * 2)));
  if (tk > 0.5) ctx.lineTo(lerp(xr - 25, xr - 11, (tk - 0.5) * 2), lerp(y + 93, y + 76, (tk - 0.5) * 2));
  ctx.stroke();
  ctx.direction = 'rtl'; ctx.textAlign = 'right';
  ctx.font = font(44, 600, FACE); ctx.fillStyle = C.ink; ctx.fillText(SAVED.title, xr - 64, y + 100);
  ctx.fillStyle = 'rgba(20,18,31,.08)'; ctx.fillRect(x + 54, y + 146, w - 108, 2);
  [['الاسم', SAVED.name, false], ['الهاتف', SAVED.phone, true], ['الطلب', SAVED.note, false]].forEach(([k, v, ltr], i) => {
    const r = E.outCubic(clamp((t - t0 - 0.8 - i * 0.28) / 0.45)), yy = y + 214 + i * 76 + (1 - r) * 12;
    ctx.globalAlpha = a * r;
    ctx.font = font(26, 400, FACE); ctx.direction = 'rtl'; ctx.textAlign = 'right'; ctx.fillStyle = C.label; ctx.fillText(k, xr, yy);
    ctx.font = font(34, 500, FACE); ctx.fillStyle = C.ink;
    if (ltr) { ctx.direction = 'ltr'; ctx.textAlign = 'right'; ctx.fillText(v, xr - 150, yy + 2); } else ctx.fillText(v, xr - 150, yy + 2);
  });
  ctx.restore();
}
function sceneTry(ctx, t) {
  fill(ctx, C.paper);
  sentence(ctx, TRY, t, sec(SC.try[0]) + 0.6, 0.36, H / 2 + 20, 64, C.quiet, C.ink);
}

M.film({
  fonts: [font(54, 500, FACE), font(34, 500, FACE), font(22, 400, FACE), font(44, 600, FACE)],
  images: { logo: 'assets/logo-ar.svg' },
  hits: HITS,
  samples: 3,
  async init(ctx, IMG) {
    VO = await fetch('audio/vo.json').then((r) => r.json());
    await Promise.all([400, 500, 600].map((w) => document.fonts.load(font(40, w, FACE), 'يرد على كل مكالمة 100')));
    // the logo in white, for the colour (the file's own shapes, recoloured once)
    if (IMG.logo) { LOGO = document.createElement('canvas'); LOGO.width = 921; LOGO.height = 384; const c = LOGO.getContext('2d'); c.filter = 'brightness(0) invert(1)'; c.drawImage(IMG.logo, 0, 0, 921, 384); }
    // a fine grain, so the soft colour never bands
    GRAIN = document.createElement('canvas'); GRAIN.width = 256; GRAIN.height = 256;
    const g = GRAIN.getContext('2d'), d = g.createImageData(256, 256);
    for (let i = 0; i < 256 * 256; i++) { const v = 128 + (hash01(i, 9) - 0.5) * 255; d.data[i * 4] = v; d.data[i * 4 + 1] = v; d.data[i * 4 + 2] = v; d.data[i * 4 + 3] = 255; }
    g.putImageData(d, 0, 0);
    // the conversation: both sides are spoken, and every word comes at its own moment
    const f = font(34, 500, FACE);
    const said = (line) => { let i = 0; return wrap(ctx, line.words.map((w) => w[0]).join(' '), f, 10, 720).map((ln) => ln.map((w) => ({ w, t: line.words[i++][1] }))); };
    SC = VO.scenes;
    CALLS = VO.calls.map((c, i) => ({ ...CAST[c.key], key: c.key, u0: c.u0, u1: c.u1, first: i === 0, last: i === VO.calls.length - 1, answer: c.agent }));
    VO.calls.forEach((c) => {
      const D = CAST[c.key];
      TALK[c.key] = [
        { kind: 'her', who: D.her, t0: c.ask.at - 0.4, lines: said(c.ask) },
        { kind: 'agent', who: D.agent + ' · ' + D.who, t0: c.agent.at - 0.35, lines: said(c.agent) },
      ];
      HITS.push([c.u0, c.key, c === VO.calls[0] ? 'whoosh' : 'tick', c === VO.calls[0] ? { len: 0.6, from: 400, to: 1400 } : {}]);
    });
    HITS.push([SC.line[0], 'circle', 'tick']);
    HITS.push([SC.dialects[0], 'dialects', 'sub', { len: 0.6 }], [SC.saved[0], 'saved', 'bell', { pitch: 'D6' }], [SC.try[0], 'try it', 'tick'],
      [SC.logo[0], 'end colour', 'sub', { len: 0.8 }], [SC.logo[0] + 1, 'logo', 'bell', { pitch: 'A5' }]);
    HITS.sort((a, b) => a[0] - b[0]);
  },
  draw(ctx, u, t, IMG) {
    const call = CALLS.find((D) => u >= D.u0 && u < D.u1);
    if (u < SC.line[0]) sceneMark(ctx, t, IMG);
    else if (u < SC.line[1]) sceneCircle(ctx, t);
    else if (call) sceneTalk(ctx, t, call);
    else if (u < SC.dialects[1]) sceneDialects(ctx, t);
    else if (u < SC.saved[1]) sceneSaved(ctx, t);
    else if (u < SC.try[1]) sceneTry(ctx, t);
    else sceneColour(ctx, t, SC.logo[0], PAL.night, 2.4, true);
  },
  post(ctx, u, t) {
    if (!GRAIN) return;
    const f = Math.floor(t * 30), ox = Math.floor(hash01(f, 3) * 256), oy = Math.floor(hash01(f, 4) * 256);
    ctx.globalAlpha = 0.035; ctx.globalCompositeOperation = 'overlay';
    for (let y = -oy; y < H; y += 256) for (let x = -ox; x < W; x += 256) ctx.drawImage(GRAIN, x, y);
    ctx.globalCompositeOperation = 'source-over'; ctx.globalAlpha = 1;
  },
});
