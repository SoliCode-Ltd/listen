// «الحلة» (the cooking pot): a TikTok ad for إسمع. A pure function of time: window.seek(t)
// paints frame t. Each version (eg, eg-short, gulf) is a folder with its own film.json, whose
// "cues" place the voice clips and say what each moment shows; this file draws them all.
// Times in the cues are in seconds; the voice track (audio/vo.wav) is built from the same cues
// by build_vo.py, and audio/vo_env.json is its loudness, which drives the waves on screen.
import * as M from './motion.js';

const { W, H, E, prog, lerp, clamp, springU, SPRING, wobble, font, fill, rrect, mix } = M;

// Brand: the site's dark theme. One accent (violet), its lighter tint for text on dark.
const C = {
  bg: '#0B0B0F', panel: '#16151F', line: '#2A2838', text: '#FFFFFF', muted: '#A6A3B8',
  accent: '#7659FA', tint: '#9580FF', glow: '#5B3DF5',
};
const F = 'Alexandria';
const S = M.FORMAT.safe;                 // 9:16 keeps clear of TikTok's own UI
const CX = S.x + S.w / 2;                // the column the ad is built on
const sec = (u) => M.TS(u);              // beats -> seconds (cues are in seconds)

// ---------------------------------------------------------------- data
let CUES = [], CAST = {}, ENV = null, END = 0;
const HITS = [];   // filled from the cues in init: every accent gets its sound (sfx.mjs)
const at = (t) => {
  let cur = null, i = -1;
  CUES.forEach((c, k) => { if (t >= c.at - (c.lead ?? 0)) { cur = c; i = k; } });
  if (!cur && CUES.length) { cur = CUES[0]; i = 0; }   // the first scene is already on frame 0
  return { cue: cur, i, prev: CUES[i - 1], next: CUES[i + 1] };
};
// Loudness of the voice at t (0..1), smoothed a little so the waves breathe rather than flicker.
const env = (t) => {
  if (!ENV) return 0;
  const x = t * ENV.fps, i = Math.floor(x), f = x - i, a = ENV.env[i] ?? 0, b = ENV.env[i + 1] ?? 0;
  return a + (b - a) * f;
};
const envS = (t) => (env(t) * 0.5 + env(t - 0.03) * 0.3 + env(t - 0.06) * 0.2);

// ---------------------------------------------------------------- drawing helpers
function textW(ctx, s, f) { ctx.font = f; return ctx.measureText(s).width; }

// Arabic is laid out word by word from the right, so each word can move on its own.
function wordsRTL(ctx, str, size, weight, maxW) {
  const f = font(size, weight, F);
  ctx.font = f; ctx.direction = 'rtl';
  const space = ctx.measureText(' ').width;
  const lines = []; let w = 0;
  str.split('\n').forEach((part) => {
    lines.push([]); w = 0;
    part.trim().split(' ').forEach((word) => {
      const ww = ctx.measureText(word).width;
      if (w > 0 && w + space + ww > maxW) { lines.push([]); w = 0; }
      lines[lines.length - 1].push({ word, w: ww });
      w += (w > 0 ? space : 0) + ww;
    });
  });
  return lines.map((ln) => {
    const total = ln.reduce((a, b) => a + b.w, 0) + space * (ln.length - 1);
    let x = (W + total) / 2;                       // right edge of the line, centred on the page
    return ln.map((it) => { const o = { ...it, right: x }; x -= it.w + space; return o; });
  }).map((ln) => ({ items: ln }));
}

function drawWord(ctx, word, right, y, f, color) {
  ctx.font = f; ctx.direction = 'rtl'; ctx.textAlign = 'right'; ctx.fillStyle = color;
  ctx.fillText(word, right, y);
}

function pill(ctx, x, y, w, h, color, alpha = 1) {
  ctx.save(); ctx.globalAlpha *= alpha; rrect(ctx, x, y, w, h, h / 2); ctx.fillStyle = color; ctx.fill(); ctx.restore();
}

// A chip at the top of the frame: what the viewer is hearing now (the emotion or the dialect).
function chip(ctx, label, y, k, { flag = null, IMG } = {}) {
  if (k <= 0) return;
  const size = 62, f = font(size, 800, F), pad = 42, fl = flag ? 78 : 0, gap = flag ? 20 : 0;
  const w = textW(ctx, label, f) + pad * 2 + fl + gap, h = 112, x = CX - w / 2;
  ctx.save();
  ctx.translate(CX, y + h / 2); ctx.scale(lerp(0.6, 1, k), lerp(0.6, 1, k)); ctx.translate(-CX, -(y + h / 2));
  ctx.globalAlpha = clamp(k * 1.4);
  pill(ctx, x, y, w, h, C.panel);
  ctx.lineWidth = 3; rrect(ctx, x, y, w, h, h / 2); ctx.strokeStyle = C.accent; ctx.stroke();
  if (flag && IMG[`flag_${flag}`]) {
    ctx.save(); ctx.beginPath(); ctx.arc(x + w - pad - fl / 2, y + h / 2, fl / 2, 0, M.TAU); ctx.clip();
    const im = IMG[`flag_${flag}`], s = Math.max(fl / im.width, fl / im.height);
    ctx.drawImage(im, x + w - pad - fl / 2 - (im.width * s) / 2, y + h / 2 - (im.height * s) / 2, im.width * s, im.height * s);
    ctx.restore();
  }
  ctx.font = f; ctx.direction = 'rtl'; ctx.textAlign = 'right'; ctx.fillStyle = C.text;
  ctx.fillText(label, x + w - pad - fl - gap, y + h / 2 + size * 0.36);
  ctx.restore();
}

// The speaker: their avatar inside a ring of the brand's rounded bars, moving with their voice.
function speaker(ctx, img, cx, cy, r, level, t, { style = 'normal', k = 1, ring = 1 } = {}) {
  if (k <= 0) return;
  ctx.save();
  ctx.translate(cx, cy); ctx.scale(k, k); ctx.translate(-cx, -cy);
  const n = 64;
  const thin = style === 'whisper' ? 0.45 : 1;
  for (let i = 0; i < n; i++) {
    const a = (i / n) * M.TAU - Math.PI / 2;
    const wob = 0.55 + 0.45 * M.noise1(i * 0.37 + t * 6.5, 7);
    const len = (10 + level * 120 * wob * thin) * ring;
    const r0 = r + 26;
    ctx.beginPath();
    ctx.moveTo(cx + Math.cos(a) * r0, cy + Math.sin(a) * r0);
    ctx.lineTo(cx + Math.cos(a) * (r0 + len), cy + Math.sin(a) * (r0 + len));
    ctx.lineWidth = 9 * (style === 'whisper' ? 0.7 : 1); ctx.lineCap = 'round';
    ctx.strokeStyle = mix(C.accent, C.tint, wob);
    ctx.globalAlpha = (0.35 + 0.65 * clamp(level * 2.2 + 0.15)) * ring;
    ctx.stroke();
  }
  ctx.globalAlpha = 1;
  // the face
  ctx.beginPath(); ctx.arc(cx, cy, r + 8, 0, M.TAU); ctx.fillStyle = C.accent; ctx.fill();
  ctx.save(); ctx.beginPath(); ctx.arc(cx, cy, r, 0, M.TAU); ctx.clip();
  if (img) ctx.drawImage(img, cx - r, cy - r, r * 2, r * 2);
  ctx.restore();
  ctx.restore();
}

// The cooking pot, drawn in the brand's line style: lid, handles, steam; can be empty and shake.
function pot(ctx, cx, cy, s, t, { k = 1, steam = 1, lid = 0, shake = 0 } = {}) {
  if (k <= 0) return;
  ctx.save();
  const sx = shake ? Math.sin(t * 70) * shake * 14 : 0;
  ctx.translate(cx + sx, cy); ctx.scale(s * k, s * k);
  ctx.lineWidth = 14; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
  ctx.strokeStyle = C.tint; ctx.fillStyle = '#2A2155';
  // body
  rrect(ctx, -170, -40, 340, 190, 46); ctx.fill(); ctx.stroke();
  // handles
  ctx.beginPath(); ctx.moveTo(-170, 10); ctx.lineTo(-215, 10); ctx.moveTo(170, 10); ctx.lineTo(215, 10); ctx.stroke();
  // lid, lifts on cue
  ctx.save(); ctx.translate(0, -40 - lid * 70); ctx.rotate(-lid * 0.35);
  ctx.beginPath(); ctx.moveTo(-185, 0); ctx.quadraticCurveTo(0, -80, 185, 0); ctx.closePath(); ctx.fill(); ctx.stroke();
  ctx.beginPath(); ctx.moveTo(-26, -42); ctx.lineTo(26, -42); ctx.stroke();
  ctx.restore();
  // steam: three soft curls rising
  if (steam > 0) {
    for (let i = 0; i < 3; i++) {
      const ph = (t * 0.7 + i / 3) % 1;
      ctx.globalAlpha = steam * Math.sin(ph * Math.PI) * 0.7;
      ctx.beginPath();
      const x0 = -80 + i * 80, y0 = -110 - lid * 70 - ph * 150;
      ctx.moveTo(x0, y0 + 60); ctx.bezierCurveTo(x0 - 30, y0 + 30, x0 + 30, y0, x0, y0 - 30);
      ctx.strokeStyle = C.muted; ctx.lineWidth = 10; ctx.stroke();
    }
  }
  ctx.restore();
}

// The spoken line as big kinetic words; the pot word sits on a violet pill. inK: entrance 0..1
// (words rise in, staggered), outK: exit 0..1. roll: 0..1 while the pot word is being swapped.
function line(ctx, str, potWord, y, t0, t, { size = 84, outK = 0, IMG, karaoke = null } = {}) {
  const lines = wordsRTL(ctx, str, size, 800, W * 0.9);
  const lh = size * 1.55;
  const top = y - ((lines.length - 1) * lh) / 2;
  let wi = 0;
  lines.forEach((ln, li) => {
    ln.items.forEach((it) => {
      const k = springU(M.U(t), M.U(t0 + wi * 0.06), SPRING.snappy ?? SPRING.bouncy);
      const yy = top + li * lh + (1 - k) * 60 - outK * 80;
      const a = clamp(k * 1.5) * (1 - outK);
      if (a <= 0) { wi++; return; }
      const isPot = potWord && it.word.replace(/[!؟?…،.]/g, '') === potWord;
      ctx.save(); ctx.globalAlpha = a;
      if (isPot) {
        const pk = springU(M.U(t), M.U(t0 + 0.18 + wi * 0.06), SPRING.bouncy);
        const pw = it.w + 44, ph = size * 1.25;
        ctx.save(); ctx.translate(it.right - it.w / 2, yy - size * 0.32);
        ctx.scale(lerp(0.4, 1, pk), lerp(0.4, 1, pk)); ctx.rotate((1 - pk) * -0.12);
        pill(ctx, -pw / 2, -ph / 2, pw, ph, C.accent);
        ctx.restore();
      }
      const lit = karaoke == null ? 1 : clamp((t - karaoke[wi]) * 8);
      drawWord(ctx, it.word, it.right, yy, font(size, 800, F), isPot ? '#FFFFFF' : mix(C.muted, C.text, lit));
      ctx.restore();
      wi++;
    });
  });
}

// ---------------------------------------------------------------- scenes
const MUM = { x: CX, y: 860, r: 235 };

function background(ctx, t, level) {
  fill(ctx, C.bg);
  // one soft stage light behind the speaker, breathing with the voice
  const g = ctx.createRadialGradient(MUM.x, MUM.y, 40, MUM.x, MUM.y, 820);
  g.addColorStop(0, `rgba(118, 89, 250, ${0.20 + level * 0.18})`);
  g.addColorStop(0.55, 'rgba(91, 61, 245, 0.06)');
  g.addColorStop(1, 'rgba(11, 11, 15, 0)');
  ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
}

function logoTop(ctx, IMG, k, y = S.y + 6) {
  if (k <= 0 || !IMG.logo) return;
  const h = 84, w = (IMG.logo.width / IMG.logo.height) * h;
  ctx.save(); ctx.globalAlpha = k; ctx.drawImage(IMG.logo, CX - w / 2, y + (1 - k) * -30, w, h); ctx.restore();
}

// Hook: the angry mum, the proposition on top, the brand right away.
function sceneHook(ctx, t, c, IMG, nextAt) {
  const lv = envS(t);
  background(ctx, t, lv);
  const out = E.inCubic(prog(t, nextAt - 0.25, nextAt));
  logoTop(ctx, IMG, E.outCubic(prog(t, -0.2, 0.2)) * (1 - out));
  // proposition: two lines, words rising in
  const hk = (i) => springU(M.U(t), M.U(-0.3 + i * 0.09), SPRING.bouncy);
  const head = c.headline || ['جملة واحدة…', 'بأي إحساس، وبأي لهجة'];
  head.forEach((ln, i) => {
    const k = hk(i);
    const f = font(i ? 66 : 60, i ? 800 : 600, F);
    ctx.save(); ctx.globalAlpha = clamp(k * 1.4) * (1 - out);
    ctx.font = f; ctx.direction = 'rtl'; ctx.textAlign = 'center';
    ctx.fillStyle = i ? C.text : C.muted;
    ctx.fillText(ln, CX, S.y + 190 + i * 92 + (1 - k) * 50);
    ctx.restore();
  });
  // the mum lands with a squash and the steam rises
  const k = springU(M.U(t), M.U(-0.32), SPRING.bouncy);
  const sh = c.style === 'angry' ? M.noise1(t * 30, 3) * 10 * clamp(lv * 2) : 0;
  speaker(ctx, IMG.mum, MUM.x + sh, MUM.y, MUM.r, lv, t, { k });
  line(ctx, c.text, c.pot, 1400, -0.25, t, { outK: out, IMG, size: 86 });
}

// Emotions: the same line, four ways. The chip names the feeling; the speaker acts it.
function sceneEmotion(ctx, t, c, IMG, nextAt, first) {
  const lv = envS(t);
  background(ctx, t, lv);
  const inK = springU(M.U(t), M.U(c.at - 0.12), SPRING.bouncy);
  const out = E.inCubic(prog(t, nextAt - 0.18, nextAt));
  chip(ctx, c.label, S.y + 70, inK * (1 - out), { IMG });
  let dx = 0, dy = 0, rot = 0, sc = 1;
  const lt = t - c.at;
  if (c.style === 'whisper') sc = 0.86 + 0.02 * Math.sin(lt * 3);
  if (c.style === 'laughcry') { dy = -Math.abs(Math.sin(lt * 9)) * 26 * clamp(lv * 2); sc = 1 + 0.04 * wobble(lt, 3, 2); }
  if (c.style === 'plead') rot = 0.08 * Math.sin(lt * 2.2);
  if (c.style === 'angry') dx = M.noise1(t * 30, 3) * 12 * clamp(lv * 2);
  ctx.save(); ctx.translate(MUM.x + dx, MUM.y + dy); ctx.rotate(rot); ctx.translate(-MUM.x, -MUM.y);
  speaker(ctx, IMG.mum, MUM.x, MUM.y, MUM.r * sc, lv, t, { style: c.style });
  ctx.restore();
  line(ctx, c.text, c.pot, 1390, c.at - 0.08, t, { outK: out, IMG, size: c.style === 'whisper' ? 84 : 92 });
}

// Dialects: the same mum, now Saudi, Emirati, Lebanese, Fusha. The flag chip flips; the pot word
// on its pill is the joke: every dialect calls the pot something else.
function sceneDialect(ctx, t, c, IMG, nextAt) {
  const lv = envS(t);
  background(ctx, t, lv);
  const inK = springU(M.U(t), M.U(c.at - 0.15), SPRING.bouncy);
  const out = E.inCubic(prog(t, nextAt - 0.16, nextAt));
  chip(ctx, c.label, S.y + 70, inK * (1 - out), { flag: c.flag, IMG });
  speaker(ctx, IMG.mum, MUM.x, MUM.y, MUM.r, lv, t, {});
  // the flag also sits on the speaker, like the badge on the site's voice cards
  if (c.flag && IMG[`flag_${c.flag}`]) {
    const fk = springU(M.U(t), M.U(c.at - 0.05), SPRING.bouncy) * (1 - out);
    const fr = 46 * fk, fx = MUM.x + MUM.r * 0.72, fy = MUM.y + MUM.r * 0.72;
    if (fr > 1) {
      ctx.save(); ctx.beginPath(); ctx.arc(fx, fy, fr + 6, 0, M.TAU); ctx.fillStyle = C.bg; ctx.fill();
      ctx.beginPath(); ctx.arc(fx, fy, fr, 0, M.TAU); ctx.clip();
      const im = IMG[`flag_${c.flag}`], s = Math.max((fr * 2) / im.width, (fr * 2) / im.height);
      ctx.drawImage(im, fx - (im.width * s) / 2, fy - (im.height * s) / 2, im.width * s, im.height * s);
      ctx.restore();
    }
  }
  line(ctx, c.text, c.pot, 1390, c.at - 0.1, t, { outK: out, IMG, size: 92 });
}

// The son answers, the mum has the last word, the pot is empty.
function sceneTalk(ctx, t, c, IMG, nextAt, talks) {
  const first = talks[0], last = talks[talks.length - 1];
  const lvS = c.who === 'son' ? envS(t) : 0, lvM = c.who === 'mum' ? envS(t) : 0;
  background(ctx, t, envS(t) * 0.6);
  const kIn = springU(M.U(t), M.U(first.at - 0.3), SPRING.bouncy);
  const out = E.inCubic(prog(t, nextAt - 0.2, nextAt));
  // mum on the right (Arabic reads right to left), son on the left
  const mx = lerp(MUM.x, W * 0.7, kIn), my = lerp(MUM.y, 690, kIn), mr = lerp(MUM.r, 185, kIn);
  const sk = springU(M.U(t), M.U(first.at - 0.2), SPRING.bouncy);
  ctx.save(); ctx.globalAlpha = 1 - out;
  speaker(ctx, IMG.mum, mx, my, mr, lvM, t, {});
  speaker(ctx, IMG.son, W * 0.3, 690, 185, lvS, t, { k: sk });
  // the pot between them; the lid flies off and it shakes on the last line
  const lastK = prog(t, last.at, last.at + 0.25);
  pot(ctx, CX, 1165, 1.15, t, { k: springU(M.U(t), M.U(first.at), SPRING.bouncy), steam: 1 - lastK, lid: E.outBack(lastK, 2), shake: c === last ? clamp(envS(t) * 2) : 0 });
  // speech bubbles
  talks.forEach((tc, i) => {
    const bk = springU(M.U(t), M.U(tc.at - 0.05), SPRING.bouncy);
    if (bk <= 0) return;
    const isSon = tc.who === 'son';
    const size = 54, f = font(size, 800, F);
    const tw = Math.min(textW(ctx, tc.text, f), S.w * 0.78);
    const bw = tw + 70, bh = 112;
    const bx = isSon ? W * 0.3 - 185 + 10 : W * 0.7 + 185 - bw - 10;
    const by = isSon ? 950 : 1380;
    ctx.save();
    const ox = isSon ? bx : bx + bw, oy = by;
    ctx.translate(ox, oy); ctx.scale(bk, bk); ctx.translate(-ox, -oy);
    pill(ctx, bx, by, bw, bh, isSon ? C.panel : C.accent);
    if (isSon) { ctx.lineWidth = 3; rrect(ctx, bx, by, bw, bh, bh / 2); ctx.strokeStyle = C.line; ctx.stroke(); }
    ctx.font = f; ctx.direction = 'rtl'; ctx.textAlign = 'center'; ctx.fillStyle = C.text;
    ctx.fillText(tc.text, bx + bw / 2, by + bh / 2 + size * 0.36, S.w * 0.8);
    ctx.restore();
  });
  ctx.restore();
}

// The real studio page, desktop, light theme, exactly as users see it (header, side menu, voice
// library, the text-to-speech card), as a short how-to: choose the dialect, type the line, add a
// feeling (an emotion tag), add a laugh (a live sound tag), tap generate. Six real screen states
// (pg-<tag>-0..5.png) in a browser window; the camera pushes in on each step like a product film
// and the page changes in place. Positions come from pg-boxes.json (fractions of the page).
let PB = null;
// [time from the cue's start (s), focus x, focus y (page fractions), zoom (1 = whole window)]
const CAM = [[0, 0.5, 0.5, 1], [0.45, 0.5, 0.5, 1], [0.95, 0.5, 0.268, 3.0], [1.12, 0.5, 0.268, 3.05],
  [1.45, 0.725, 0.506, 3.1], [1.9, 0.722, 0.506, 3.15], [2.22, 0.67, 0.64, 2.6], [2.5, 0.67, 0.64, 2.62],
  [2.8, 0.76, 0.52, 3.0], [3.1, 0.76, 0.52, 3.02], [3.45, 0.73, 0.58, 2.7], [3.7, 0.73, 0.58, 2.72],
  [4.02, 0.7, 0.52, 3.0], [4.22, 0.7, 0.52, 3.02], [4.45, 0.6, 0.7, 2.0], [4.68, 0.47, 0.88, 2.8], [4.95, 0.47, 0.88, 2.85], [5.35, 0.5, 0.5, 1.02]];
const STATES = [[0, 0], [1.2, 1], [2.05, 2], [2.62, 3], [3.4, 4], [3.92, 5]];
function camera(lt) {
  let i = 0; while (i < CAM.length - 2 && lt > CAM[i + 1][0]) i++;
  const [t0, x0, y0, z0] = CAM[i], [t1, x1, y1, z1] = CAM[i + 1];
  const p = E.inOutCubic(prog(lt, t0, t1));
  return { x: lerp(x0, x1, p), y: lerp(y0, y1, p), z: z0 * (z1 / z0) ** p };
}
function ring(ctx, b, px, py, pw, ph, k, z, r = 10) {
  if (!b || k <= 0) return;
  ctx.save(); ctx.globalAlpha *= clamp(k * 1.5);
  const pad = 9 / z, g = (6 * (1 - k)) / z;
  rrect(ctx, px + b.x * pw - pad - g, py + b.y * ph - pad - g, b.w * pw + (pad + g) * 2, b.h * ph + (pad + g) * 2, r / z * 2.2);
  ctx.lineWidth = 5 / z; ctx.strokeStyle = C.accent; ctx.stroke();
  ctx.restore();
}
function sceneStudio(ctx, t, c, IMG, nextAt) {
  background(ctx, t, 0.12);
  const shots = IMG.studio, B = PB && PB[c.boxes || 'eg'];
  if (!shots || !shots[0] || !B) return;
  const lt = t - c.at;
  const rise = springU(M.U(t), M.U(c.at - 0.05), SPRING.gentle);
  const out = E.inCubic(prog(t, nextAt - 0.3, nextAt));
  // the browser window: a thin bar with the address, then the page
  const ww = 1000, bar = 40, pw = ww, ph = (shots[0].height / shots[0].width) * pw;
  const wx = CX - ww / 2, wy = H / 2 - (ph + bar) / 2, px = wx, py = wy + bar;
  const cam = camera(lt);
  const z = cam.z * lerp(0.9, 1, rise);
  ctx.save();
  ctx.globalAlpha = 1 - out;
  ctx.translate(CX, H / 2 + 40 + (1 - rise) * 1000); ctx.scale(z, z); ctx.rotate((1 - rise) * 0.06);
  ctx.translate(-(px + cam.x * pw), -(py + cam.y * ph));
  ctx.shadowColor = 'rgba(118, 89, 250, 0.42)'; ctx.shadowBlur = 110; ctx.shadowOffsetY = 40;
  rrect(ctx, wx, wy, ww, ph + bar, 22); ctx.fillStyle = '#FFFFFF'; ctx.fill();
  ctx.shadowColor = 'transparent';
  ctx.save(); rrect(ctx, wx, wy, ww, ph + bar, 22); ctx.clip();
  ctx.fillStyle = '#F1F0F7'; ctx.fillRect(wx, wy, ww, bar);
  ['#FF5F57', '#FEBC2E', '#28C840'].forEach((col, i) => { ctx.beginPath(); ctx.arc(wx + 24 + i * 20, wy + bar / 2, 6, 0, M.TAU); ctx.fillStyle = col; ctx.fill(); });
  rrect(ctx, CX - 170, wy + 9, 340, bar - 18, 11); ctx.fillStyle = '#FFFFFF'; ctx.fill();
  ctx.font = font(13, 500, F); ctx.direction = 'ltr'; ctx.textAlign = 'center'; ctx.fillStyle = '#6B6880';
  ctx.fillText('listenai.studio/ar/dashboard', CX, wy + bar / 2 + 5);
  // the page: each new state fades in over 0.12 s
  let si = 0; STATES.forEach(([from], i) => { if (lt >= from) si = i; });
  const prevShot = shots[STATES[Math.max(0, si - 1)][1]], curShot = shots[STATES[si][1]];
  const fade = si === 0 ? 1 : E.outCubic(prog(lt, STATES[si][0], STATES[si][0] + 0.12));
  if (fade < 1 && prevShot) ctx.drawImage(prevShot, px, py, pw, ph);
  ctx.save(); ctx.globalAlpha *= fade; ctx.drawImage(curShot, px, py, pw, ph); ctx.restore();
  // typing: the line appears right to left (Arabic), the caret at its end
  if (si === 1) {
    const L = B.line, typed = E.outCubic(prog(lt, 1.22, 1.85));
    const lx = px + L.x * pw, lw = L.w * pw, ly = py + L.y * ph, lh = L.h * ph;
    ctx.fillStyle = '#FFFFFF'; ctx.fillRect(lx - 3, ly, (lw + 4) * (1 - typed), lh);
    if (typed < 1) { ctx.fillStyle = C.accent; ctx.fillRect(lx + lw * (1 - typed) - 2, ly + lh * 0.15, 1.2, lh * 0.7); }
  }
  ctx.restore();
  // rings on what each step touches
  const R = (b, a, d, r) => ring(ctx, b, px, py, pw, ph, springU(M.U(t), M.U(c.at + a), SPRING.bouncy) * (1 - prog(lt, d, d + 0.15)), z, r);
  R(B.dialect, 0.75, 1.15, 10);
  R(B.emoItem, 2.22, 2.6, 6);
  R(B.emoTag, 2.7, 3.2, 4);
  R(B.laughItem, 3.55, 3.85, 6);
  R(B.laughTag, 3.98, 4.35, 4);
  R(B.button, 4.55, 5.0, 8);
  // the tap on generate
  const Bt = B.button, bx = px + (Bt.x + Bt.w / 2) * pw, by = py + (Bt.y + Bt.h / 2) * ph;
  const tap = prog(lt, 4.72, 5.2);
  if (tap > 0 && tap < 1) {
    ctx.beginPath(); ctx.arc(bx, by, (3 + tap * 26), 0, M.TAU);
    ctx.strokeStyle = C.accent; ctx.lineWidth = 2.2 * (1 - tap); ctx.globalAlpha = (1 - tap) * (1 - out); ctx.stroke();
  }
  ctx.restore();
  // the step, in words, above the screen
  const steps = c.steps || ['اختار اللهجة', 'اكتب جملتك', 'زوّد إحساس', 'وصوت ضحكة', 'ودوس توليد'];
  const when = [0.6, 1.25, 2.0, 3.35, 4.45];
  let k = -1; when.forEach((a, i) => { if (lt >= a) k = i; });
  if (k >= 0) chip(ctx, steps[k], S.y - 20, springU(M.U(t), M.U(c.at + when[k]), SPRING.bouncy) * (1 - out), { IMG });
}

// End card: the brand, the invitation, the address, and the question that starts the comments.
function sceneEnd(ctx, t, c, IMG) {
  background(ctx, t, 0.2 + 0.1 * Math.sin(t * 3));
  const k = (d) => springU(M.U(t), M.U(c.at + d), SPRING.bouncy);
  // the brand mark: the logo, big
  if (IMG.logo) {
    const lk = k(0), h = 190, w = (IMG.logo.width / IMG.logo.height) * h;
    ctx.save(); ctx.globalAlpha = clamp(lk * 1.4);
    ctx.translate(CX, 640); ctx.scale(lerp(0.7, 1, lk), lerp(0.7, 1, lk)); ctx.translate(-CX, -640);
    ctx.drawImage(IMG.logo, CX - w / 2, 640 - h / 2, w, h);
    ctx.restore();
  }
  const ck = k(0.18);
  ctx.save(); ctx.globalAlpha = clamp(ck * 1.4);
  ctx.font = font(118, 800, F); ctx.direction = 'rtl'; ctx.textAlign = 'center'; ctx.fillStyle = C.text;
  ctx.fillText(c.title || 'جرّب لهجتك', CX, 960 + (1 - ck) * 60);
  ctx.restore();
  // the address on a violet pill: the action
  const uk = k(0.32);
  if (uk > 0) {
    const f = font(60, 700, F), url = 'listenai.studio', tw = textW(ctx, url, f), pw = tw + 110, ph = 120;
    ctx.save(); ctx.globalAlpha = clamp(uk * 1.4);
    ctx.translate(CX, 1110 + ph / 2); ctx.scale(lerp(0.6, 1, uk), lerp(0.6, 1, uk)); ctx.translate(-CX, -(1110 + ph / 2));
    pill(ctx, CX - pw / 2, 1110, pw, ph, C.accent);
    ctx.font = f; ctx.direction = 'ltr'; ctx.textAlign = 'center'; ctx.fillStyle = '#FFFFFF';
    ctx.fillText(url, CX, 1110 + ph / 2 + 21);
    ctx.restore();
  }
  const qk = k(0.55);
  ctx.save(); ctx.globalAlpha = clamp(qk * 1.4);
  ctx.font = font(58, 600, F); ctx.direction = 'rtl'; ctx.textAlign = 'center'; ctx.fillStyle = C.tint;
  ctx.fillText(c.question || 'إنت لهجتك إيه؟ اكتبها في الكومنتات', CX, 1340 + (1 - qk) * 40);
  ctx.restore();
}

// ---------------------------------------------------------------- film
M.film({
  fonts: [font(80, 800, F), font(50, 600, F)],
  images: {},
  hits: HITS,
  async init(ctx, IMG) {
    const cfg = M.CFG;
    CUES = cfg.cues || []; CAST = cfg.cast || {}; END = cfg.duration;
    const load = (src) => new Promise((res) => { const im = new Image(); im.onload = () => res(im); im.onerror = () => { console.error('image failed: ' + src); res(null); }; im.src = src; });
    const flags = [...new Set(CUES.map((c) => c.flag).filter(Boolean))];
    const [mum, son, logo, studio, button, ...fl] = await Promise.all([
      load(`../shared/assets/speakers/${CAST.mum}.webp`), load(`../shared/assets/speakers/${CAST.son}.webp`),
      load('../shared/assets/logo-ar-dark.svg'), Promise.all([0, 1, 2, 3, 4, 5].map((i) => load(`../shared/assets/${cfg.studio}-${i}.png`))), Promise.resolve(null),
      ...flags.map((f) => load(`../shared/assets/flags/${f}.svg`)),
    ]);
    Object.assign(IMG, { mum, son, logo, studio, button });
    flags.forEach((f, i) => { IMG[`flag_${f}`] = fl[i]; });
    // sounds on the accents, from the same cue times as the picture
    const B = (sec) => M.U(sec);
    CUES.forEach((c, i) => {
      if (c.type === 'hook') { HITS.push([B(0.02), 'mum lands', 'thud', { pitch: 'C3' }], [B(0.15), 'headline', 'pop', { pitch: 'E5' }], [B(0.24), 'headline 2', 'pop', { pitch: 'A5' }]); }
      if (c.type === 'emotion') HITS.push([B(c.at - 0.12), `emotion: ${c.label}`, 'blip', { pitch: ['E6', 'G6', 'B6'][i % 3] }]);
      if (c.type === 'emotion' && c.style === 'laughcry') HITS.push([B(c.at + 0.1), 'laughing', 'boing', { pitch: 'G4', to: 'C5' }]);
      if (c.type === 'dialect') HITS.push([B(c.at - 0.2), `to ${c.label}`, 'whoosh', { len: 0.3, from: 900, to: 3200 }], [B(c.at - 0.05), `flag ${c.label}`, 'pop', { pitch: 'C6' }]);
      if (c.type === 'talk' && c.who === 'son') HITS.push([B(c.at - 0.3), 'son arrives', 'whoosh', { len: 0.35 }], [B(c.at), 'pot', 'tok', { pitch: 'G4' }]);
      if (c.type === 'talk' && c.who === 'mum') HITS.push([B(c.at), 'lid flies', 'boing', { pitch: 'C5', to: 'G5' }], [B(c.at + 0.3), 'pot shakes', 'thud']);
      if (c.type === 'studio') HITS.push([B(c.at), 'studio rises', 'whoosh', { len: 0.45, from: 400, to: 2000 }], [B(c.at + 0.7), 'dialect', 'tick'], [B(c.at + 1.22), 'typing', 'type', { len: 0.6 }], [B(c.at + 2.05), 'emotions menu', 'tick'], [B(c.at + 2.62), 'emotion tag', 'blip', { pitch: 'E6' }], [B(c.at + 3.4), 'sounds menu', 'tick'], [B(c.at + 3.92), 'laugh tag', 'blip', { pitch: 'A6' }], [B(c.at + 4.72), 'generate', 'click']);
      if (c.type === 'end') HITS.push([B(c.at), 'end card', 'impact'], [B(c.at + 0.18), 'title', 'pop', { pitch: 'D6' }], [B(c.at + 0.32), 'address', 'bell', { pitch: 'A6' }]);
    });
    PB = await fetch('../shared/assets/pg-boxes.json').then((r) => r.json()).catch(() => null);
    ENV = await fetch('audio/vo_env.json').then((r) => (r.ok ? r.json() : null)).catch(() => null);
  },
  draw(ctx, u, t, IMG) {
    const { cue, i, next } = at(t);
    if (!cue) { background(ctx, t, 0); return; }
    const nextAt = next ? next.at - (next.lead ?? 0) : END + 1;
    const talks = CUES.filter((c) => c.type === 'talk');
    switch (cue.type) {
      case 'hook': return sceneHook(ctx, t, cue, IMG, nextAt);
      case 'emotion': return sceneEmotion(ctx, t, cue, IMG, nextAt);
      case 'dialect': return sceneDialect(ctx, t, cue, IMG, nextAt);
      case 'talk': return sceneTalk(ctx, t, cue, IMG, nextAt, talks);
      case 'studio': return sceneStudio(ctx, t, cue, IMG, nextAt);
      case 'end': return sceneEnd(ctx, t, cue, IMG);
      default: background(ctx, t, 0);
    }
  },
});
