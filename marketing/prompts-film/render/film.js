// The prompts film: every frame is window.seek(t), a pure function of time.
// Faces are drawn in dots from the speakers' avatars; the dots fly in from the
// Listen orb, ripple with the voice, act out the tags, and fly back into it.
// The prompt is shown as written, lighting up word by word as it is said.
(function () {
  const T = window.TIMELINE;
  const AR = T.film === 'ar';
  const W = T.W, H = T.H;
  const cv = document.getElementById('dots');
  const ctx = cv.getContext('2d');
  const ui = document.getElementById('ui');

  // ── Helpers ──
  const clamp = (x, a = 0, b = 1) => Math.min(b, Math.max(a, x));
  const lerp = (a, b, t) => a + (b - a) * t;
  const prog = (t, a, d) => clamp((t - a) / d);
  const sm = (a, b, x) => { const t = clamp((x - a) / (b - a)); return t * t * (3 - 2 * t); };
  const E = {
    out3: p => 1 - Math.pow(1 - p, 3), out5: p => 1 - Math.pow(1 - p, 5), in3: p => p * p * p,
    inOut3: p => (p < .5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2),
    inOut5: p => (p < .5 ? 16 * Math.pow(p, 5) : 1 - Math.pow(-2 * p + 2, 5) / 2),
    back: p => { const c = 1.6; return 1 + (c + 1) * Math.pow(p - 1, 3) + c * Math.pow(p - 1, 2); },
  };
  const hash = n => { const s = Math.sin(n * 127.1 + 311.7) * 43758.5453; return s - Math.floor(s); };
  const el = (tag, cls, parent, html) => { const e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; (parent || ui).appendChild(e); return e; };
  const css = (e, s) => Object.assign(e.style, s);
  const ampAt = t => T.amp[clamp(Math.round(t * T.fps), 0, T.amp.length - 1)] || 0;
  const VIOLET = [0x76, 0x59, 0xFA];
  const INK = [0x15, 0x13, 0x1F], FUTURE = [0xC9, 0xC5, 0xD6];
  const rgbStr = c => `rgb(${c[0] | 0},${c[1] | 0},${c[2] | 0})`;
  const mixC = (a, b, t) => [lerp(a[0], b[0], t), lerp(a[1], b[1], t), lerp(a[2], b[2], t)];

  // ── Portraits: every dot's colour, size and timing, worked out once ──
  // Colours come from the Listen orb's palette by the photo's lightness (deep
  // indigo for hair and eyes, violet, lilac, the warm light for skin), with a
  // little of the photo's own colour kept.
  const PAL = [[0x24, 0x13, 0x8A], [0x76, 0x59, 0xFA], [0xC9, 0xBE, 0xFF], [0xFF, 0xE3, 0xD3]];
  function palette(l) {
    let c = mixC(PAL[0], PAL[1], sm(0.05, 0.42, l));
    c = mixC(c, PAL[2], sm(0.4, 0.75, l));
    return mixC(c, PAL[3], sm(0.72, 0.95, l) * 0.9);
  }
  const PORTRAITS = {};
  function portrait(name) {
    if (PORTRAITS[name]) return PORTRAITS[name];
    const D = window.DOTS[name], S = D.size, n = D.dots.length;
    const P = { S, n, x: new Float32Array(n), y: new Float32Array(n), r: new Float32Array(n), a: new Float32Array(n),
      col: [], grey: [], seed: new Float32Array(n), s2: new Float32Array(n), s3: new Float32Array(n), order: new Float32Array(n) };
    D.dots.forEach(([x, y, r, g, b, al, lum, s], i) => {
      const dark = 1 - lum;
      const fy = y / S;
      // The bust fades out towards the bottom, and the clothes are lighter than the face.
      const dx = (x / S - 0.5) / 0.42, dy = (fy - 0.4) / 0.5;
      const body = 1 - 0.5 * sm(0.55, 1.05, Math.sqrt(dx * dx + dy * dy));
      const fade = (1 - sm(0.72, 0.98, fy)) * body;
      P.x[i] = x; P.y[i] = y;
      P.r[i] = (0.5 + 1.75 * Math.pow(dark, 1.15)) * (0.78 + 0.44 * s) * (0.75 + 0.25 * body);
      P.a[i] = al * (0.5 + 0.5 * dark) * fade;
      const c = mixC(palette(lum), [r, g, b], 0.22);
      P.col.push(c);
      const gr = (c[0] + c[1] + c[2]) / 3;
      P.grey.push(mixC(c, [lerp(gr, 214, .55), lerp(gr, 212, .55), lerp(gr, 222, .55)], 0.82));
      P.seed[i] = s; P.s2[i] = hash(i * 1.7 + 3.3); P.s3[i] = hash(i * 2.9 + 7.1);
      const cx = x / S - 0.5, cy = fy - 0.42;
      P.order[i] = 0.62 * hash(i * 0.37 + 1.1) + 0.38 * clamp(Math.sqrt(cx * cx + cy * cy) * 1.6);
    });
    // Pre-made colour strings, in 12 steps from grey (silent) to full colour (speaking).
    P.styles = P.col.map((c, i) => Array.from({ length: 13 }, (_, k) => rgbStr(mixC(P.grey[i], c, k / 12))));
    PORTRAITS[name] = P;
    return P;
  }

  // ── Layout ──
  const CENTRE = { x: W / 2, y: 860 };
  const LAYOUT = {
    duo: [{ x: 283, top: 470, size: 500 }, { x: 797, top: 470, size: 500 }],
    solo: [{ x: 540, top: 395, size: 660 }],
  };
  const BIG_K = 2.6;   // the logo bars in the intro, in px per logo unit
  const BARS = [10, 44, 72, 100, 72, 44, 10];

  // ── The page's text ──
  const rtl = AR ? ' rtl' : '';
  const logoSrc = AR ? 'assets/logo-ar-light.svg' : 'assets/logo-light.svg';
  const intro = el('div', 'intro' + (AR ? ' ar rtl' : ''));
  const introWords = [];
  T.intro.text.split(' ').forEach(w => { if (w === '|') el('br', '', intro); else introWords.push(el('span', 'w', intro, w + ' ')); });
  const brand = el('img', 'brand', intro); brand.src = logoSrc;
  const topLogo = el('img', 'toplogo'); topLogo.src = logoSrc;

  // Per scene: the speakers' labels, the prompts and the title.
  const scenes = T.scenes.map((sc, si) => {
    const lay = LAYOUT[sc.kind];
    const faces = sc.faces.map((f, fi) => {
      const L = lay[fi];
      const who = el('div', 'who' + rtl);
      const name = el('div', 'name', who, f.name);
      // Where they are from: it stays the same whatever they speak.
      const row = el('div', 'row', who);
      const flag = el('img', '', row); flag.src = `assets/${f.flag}.svg`;
      el('span', '', row, f.origin);
      css(who, { left: L.x + 'px', top: (L.top + L.size * 0.93) + 'px', transform: 'translateX(-50%)' });
      return { ...f, L, P: portrait(f.avatar), who, name };
    });
    const lines = sc.lines.map(l => {
      const box = el('div', 'prompt' + (l.lang === 'ar' ? ' ar rtl' : ''));
      css(box, { top: (sc.kind === 'duo' ? 1240 : 1290) + 'px' });
      // Tags are written as in the editor, <like this>, in the run of the words.
      // A tag never ends a line: it is kept with the word it comes before.
      let group = null;
      const toks = l.tokens.map(tok => {
        if (tok.t === 'tag' && !group) group = el('span', 'grp', box);
        const span = el('span', tok.t === 'w' ? 'w' : 'tag', group || box);
        span.textContent = (tok.t === 'w' ? tok.text : `<${tok.text}>`) + ' ';
        if (tok.t === 'w') group = null;
        return { ...tok, span };
      });
      // How the line is spoken, when the speaker changes it within the scene.
      let spoken = null;
      if (l.spoken) {
        spoken = el('div', 'spoken' + (l.lang === 'ar' ? ' rtl' : ''));
        if (l.flag) { const img = el('img', '', spoken); img.src = `assets/${l.flag}.svg`; } else el('span', 'fusha', spoken, 'ع');
        el('span', '', spoken, l.spoken);
        css(spoken, { top: '1206px' });
      }
      return { ...l, box, toks, spoken };
    });
    // Where we are: a small label above the faces (a call also shows its time).
    const where = el('div', 'where' + rtl);
    el('span', '', where, sc.context);
    const clock = sc.timer ? el('span', 'time', where, '00:00') : null;
    css(where, { top: (sc.kind === 'duo' ? 352 : 300) + 'px' });
    let title = null;
    if (sc.title) {
      const box = el('div', 'title' + (AR ? ' ar rtl' : ''));
      const chars = AR ? [el('span', 'ch', box, sc.title.text)] : [...sc.title.text].map(c => el('span', 'ch', box, c));
      const stop = el('span', 'stop', box);
      title = { ...sc.title, box, chars, stop };
    }
    return { ...sc, faces, lines, title, where, clock };
  });

  const tagline = el('div', 'tagline' + (AR ? ' ar rtl' : ''));
  const phrases = T.outro.tagline.map(p => el('span', 'ph', tagline, p));
  const wordmark = el('img', 'wordmark'); wordmark.src = AR ? 'assets/wordmark-ar-light.svg' : 'assets/wordmark-light.svg';
  const url = el('div', 'url', null, 'listenai.studio');
  // The end logo: the SVG's bars and wordmark, 205 px tall, centred.
  const VB = AR ? 307 : 400, LK = 205 / 128;
  const endLeft = W / 2 - VB * LK / 2, endTop = 760;
  css(wordmark, { left: endLeft + 'px', top: endTop + 'px', width: VB * LK + 'px', height: 128 * LK + 'px' });
  const endBars = { x: endLeft + (14 + 64) * LK, y: endTop + 64 * LK, k: LK };

  // ── Drawing ──
  const ORB_T = t => t * 0.9;
  // The site's Listen orb, a shade deeper so it holds its own on the light ground,
  // and deeper still when it fills the frame behind white type.
  const hex = h => { const n = parseInt(h.slice(1), 16); return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255]; };
  const ORB_C = ['#24138A', '#6747F7', '#B3A2FF', '#FFE3D3'].map(hex);
  const DEEP_C = ['#12094A', '#3A22C2', '#7659FA', '#E3B9FF'].map(hex);
  const orbColors = k => ORB_C.map((c, i) => c.map((v, j) => lerp(v, DEEP_C[i][j], k)));

  // The logo's seven bars, morphing out of a circle of radius R (m = 0) into the
  // bars at k px per unit (m = 1). fill: 'orb' clips the orb into them, else violet.
  function bars(cx, cy, R, k, m, o = {}) {
    const e = E.inOut3(clamp(m));
    const sw = (2 * R) / 7;
    ctx.save();
    ctx.beginPath();
    for (let i = 0; i < 7; i++) {
      const x0 = -R + (i + 0.5) * sw;
      const h0 = 2 * Math.sqrt(Math.max(0, R * R - x0 * x0)) * 0.98;
      const x1 = (18 * i - 54) * k;
      let h1 = BARS[i] * k;
      if (o.pulse) h1 *= 1 + o.pulse * Math.sin(o.time * 9 - i * 0.9) * (BARS[i] > 20 ? 1 : 0.4);
      const w = lerp(sw * 0.94, 10 * k, e) * (o.thin == null ? 1 : o.thin);
      const h = Math.max(w, lerp(h0, h1, e) * (o.shrink == null ? 1 : o.shrink));
      const x = cx + lerp(x0, x1, e);
      ctx.roundRect(x - w / 2, cy - h / 2, w, h, w / 2);
    }
    ctx.clip();
    const solid = o.solid || 0;
    if (solid < 1) {
      ctx.globalAlpha = (o.alpha == null ? 1 : o.alpha);
      ORB.draw(ctx, cx, cy, 2 * Math.max(R, 64 * k) * 1.05, { time: ORB_T(o.time || 0), colors: ORB_C });
    }
    if (solid > 0) {
      ctx.globalAlpha = solid * (o.alpha == null ? 1 : o.alpha);
      ctx.fillStyle = '#7659FA';
      ctx.fillRect(cx - 80 * k, cy - 64 * k, 160 * k, 128 * k);
    }
    ctx.restore();
    ctx.globalAlpha = 1;
  }

  // Where a face's dot is on screen.
  function placeOf(face, zoom) {
    const L = face.L, sc = L.size / face.P.S * zoom;
    return { sc, ox: L.x - L.size * zoom / 2, oy: L.top - (L.size * (zoom - 1)) * 0.42 };
  }

  // What the active tag does to the dots (the dots act it out, gently).
  function tagMotion(line, t) {
    const m = { jx: 0, jy: 0, pulse: 0, hush: 0, bounce: 0, sink: 0 };
    if (!line) return m;
    for (const tok of line.toks) {
      if (tok.t !== 'tag' || t < tok.on - 0.1 || t > tok.off + 0.4) continue;
      const k = sm(tok.on - 0.1, tok.on + 0.1, t) * (1 - sm(tok.off, tok.off + 0.4, t));
      const since = t - tok.on;
      switch (tok.key) {
        case 'laugh': case 'chuckle': case 'snicker': m.bounce = Math.max(m.bounce, k * (tok.key === 'laugh' ? 1 : 0.6)); break;
        case 'gasp': m.pulse += k * Math.exp(-Math.max(0, since) * 5) * 0.05; break;
        case 'clap': m.pulse += k * Math.exp(-Math.max(0, since) * 9) * 0.06; break;
        case 'whispers': m.hush = Math.max(m.hush, k); break;
        case 'sob': case 'trembling': m.jx = Math.max(m.jx, k * 1.4); break;
        case 'nervous': m.jx = Math.max(m.jx, k * 0.8); break;
        case 'sigh': m.sink = Math.max(m.sink, k); break;
        case 'tense': m.hush = Math.max(m.hush, k * 0.4); break;
        default: break;
      }
    }
    return m;
  }

  // One face. st: { act 0..1, amp, enter: {u, from}, leave: {v, to}, zoom, line, t }
  function drawFace(face, st) {
    const P = face.P;
    const { sc, ox, oy } = placeOf(face, st.zoom);
    const t = st.t;
    const mx = face.mouth[0], my = face.mouth[1];
    const amp = st.amp * st.act;
    const tm = st.motion;
    const ci = Math.round(clamp(st.act) * 12);
    for (let i = 0; i < P.n; i++) {
      const s = P.seed[i];
      let x = P.x[i], y = P.y[i];
      // Breathing: each dot drifts on its own small loop.
      x += 1.1 * Math.sin(t * 0.9 + s * 40);
      y += 1.1 * Math.cos(t * 0.8 + P.s2[i] * 31);
      // The voice ripples out from the mouth.
      const dxm = P.x[i] - mx, dym = P.y[i] - my;
      const dist = Math.sqrt(dxm * dxm + dym * dym) + 1e-3;
      const fall = Math.exp(-dist / 230);
      const phase = dist * 0.05 - t * 7.5;
      const wave = Math.sin(phase);
      const push = amp * 3.4 * wave * fall + tm.pulse * dist;
      x += (dxm / dist) * push; y += (dym / dist) * push;
      if (tm.bounce) y -= tm.bounce * 3.2 * Math.abs(Math.sin(t * 15 + P.s3[i] * 0.6)) * (0.4 + fall);
      if (tm.jx) x += tm.jx * 1.3 * Math.sin(t * 43 + s * 60);
      if (tm.sink) y += tm.sink * 5 * (0.3 + P.y[i] / P.S);
      let r = P.r[i] * (1 + 0.32 * amp * Math.max(0, wave) * fall) * (1 - 0.16 * tm.hush) * lerp(0.9, 1, st.act);
      let a = P.a[i] * lerp(0.5, 1, st.act) * (1 - 0.3 * tm.hush);
      let X = ox + x * sc, Y = oy + y * sc;
      // Flying in from the orb (or the logo's bars).
      if (st.enter) {
        const p = clamp((st.enter.u - P.order[i] * 0.55) / 0.75);
        if (p <= 0) continue;
        const e = E.inOut3(p);
        const from = st.enter.from(i, P);
        const nx = -(Y - from.y), ny = X - from.x;
        const curl = (P.s2[i] - 0.5) * 0.5 * Math.sin(Math.PI * e);
        X = lerp(from.x, X, e) + nx * curl; Y = lerp(from.y, Y, e) + ny * curl;
        r *= lerp(0.55, 1, e); a *= sm(0, 0.25, p);
      }
      // Gathering back into the orb.
      if (st.leave) {
        const p = clamp((st.leave.v - (1 - P.order[i]) * 0.35) / 0.55);
        if (p >= 1) continue;
        const e = E.in3(p);
        const ang = P.s2[i] * 6.2832, rad = st.leave.r * Math.sqrt(P.s3[i]) * 0.8;
        const tx = st.leave.x + Math.cos(ang) * rad, ty = st.leave.y + Math.sin(ang) * rad;
        const nx = -(ty - Y), ny = tx - X;
        const curl = 0.35 * Math.sin(Math.PI * e);
        X = lerp(X, tx, e) + nx * curl; Y = lerp(Y, ty, e) + ny * curl;
        r *= lerp(1, 0.4, e); a *= 1 - sm(0.7, 1, p);
      }
      if (a < 0.01) continue;
      ctx.globalAlpha = Math.min(1, a);
      ctx.fillStyle = P.styles[i][ci];
      ctx.beginPath();
      ctx.arc(X, Y, Math.max(0.35, r * sc * 1.08), 0, 6.2832);
      ctx.fill();
    }
    ctx.globalAlpha = 1;
    if (window.DEBUG) { ctx.fillStyle = 'red'; ctx.beginPath(); ctx.arc(ox + mx * sc, oy + my * sc, 6, 0, 6.2832); ctx.fill(); }
  }

  // Where each dot of a face comes from: a point in the orb, or in one of the bars.
  const fromOrb = (cx, cy, R) => (i, P) => {
    const ang = P.s2[i] * 6.2832, rad = R * Math.sqrt(P.s3[i]);
    return { x: cx + Math.cos(ang) * rad, y: cy + Math.sin(ang) * rad };
  };
  const fromBars = (cx, cy, k) => (i, P) => {
    const b = Math.floor(P.s2[i] * 7) % 7;
    const h = BARS[b] * k;
    return { x: cx + (18 * b - 54) * k + (P.s3[i] - 0.5) * 10 * k, y: cy + (P.seed[i] - 0.5) * h };
  };

  // ── Text states ──
  function wordState(span, t, tIn, tOut, i) {
    const pin = E.out3(prog(t, tIn + i * 0.016, 0.34));
    const pout = E.inOut3(prog(t, tOut, 0.24));
    const o = pin * (1 - pout);
    css(span, { opacity: o, transform: `translateY(${(1 - pin) * 14 - pout * 12}px)`, filter: `blur(${(1 - pin) * 6 + pout * 5}px)` });
    return o;
  }

  function promptFrame(line, t) {
    const visible = t >= line.show - 0.01 && t <= line.out + 0.3;
    css(line.box, { display: visible ? 'block' : 'none' });
    if (line.spoken) {
      css(line.spoken, { display: visible ? 'flex' : 'none' });
      if (visible) wordState(line.spoken, t, line.show - 0.1, line.out, 0);
      if (visible) line.spoken.style.transform = `translateX(-50%) ${line.spoken.style.transform}`;
    }
    if (!visible) return;
    line.toks.forEach((tok, i) => {
      wordState(tok.span, t, line.show, line.out, i);
      if (tok.t === 'w') {
        // Faint until said, violet while said, ink after.
        const now = sm(tok.s - 0.06, tok.s + 0.02, t) * (1 - sm(tok.e, tok.e + 0.14, t));
        const said = sm(tok.e, tok.e + 0.14, t);
        const c = mixC(mixC(FUTURE, INK, said), VIOLET, now);
        tok.span.style.color = rgbStr(c);
      } else {
        // Tags: pale violet before, full violet while they happen, a soft violet after.
        const on = sm(tok.on - 0.08, tok.on + 0.06, t) * (1 - sm(tok.off, tok.off + 0.2, t));
        const done = sm(tok.off, tok.off + 0.2, t);
        tok.span.style.color = rgbStr(mixC(mixC([0xCF, 0xC6, 0xFB], [0x9D, 0x8B, 0xF8], done), [0x5B, 0x3D, 0xF5], on));
      }
    });
  }

  // ── The frame ──
  function seek(t) {
    ctx.clearRect(0, 0, W, H);
    const I = T.intro, O = T.outro;
    const firstEnter = scenes[0].enter;

    // Intro sentence, word by word.
    const introOut = E.inOut3(prog(t, I.text_out, 0.5));
    css(intro, { display: t < I.text_out + 0.6 ? 'block' : 'none' });
    introWords.forEach((w, i) => {
      const p = E.out3(prog(t, I.text_in + i * I.step, 0.5));
      css(w, { opacity: p * (1 - introOut), transform: `translateY(${(1 - p) * 16 - introOut * 30}px)`, filter: `blur(${(1 - p) * 8 + introOut * 8}px)` });
    });
    const pb = E.out3(prog(t, I.text_in + introWords.length * I.step + 0.05, 0.6));
    css(brand, { opacity: pb * (1 - introOut), transform: `translateY(${(1 - pb) * 16 - introOut * 30}px)`, filter: `blur(${(1 - pb) * 8 + introOut * 8}px)` });

    // Intro orb, then the bars, then the burst.
    if (t >= I.orb_in && t < I.burst + 0.5) {
      const grow = E.back(prog(t, I.orb_in, 0.85));
      const R = 175 * grow;
      const m = prog(t, I.bars_at, 0.6);
      const pulse = Math.sin(Math.PI * prog(t, I.pulse_at, 0.55)) * 0.32;
      const gone = E.in3(prog(t, I.burst - 0.05, 0.35));
      if (m <= 0) ORB.draw(ctx, CENTRE.x, CENTRE.y, 2 * R, { time: ORB_T(t), level: 0.3 * grow, colors: ORB_C });
      else bars(CENTRE.x, CENTRE.y, R, BIG_K, m, { time: t, solid: sm(0.55, 1.0, m) * 0.85 + 0.15 * sm(I.pulse_at, I.pulse_at + 0.4, t), pulse, shrink: 1 - gone, thin: 1 - gone * 0.6, alpha: 1 - gone });
    }

    // The scenes.
    topLogoFrame(t);
    scenes.forEach((sc, si) => sceneFrame(sc, si, t));

    // Outro: dots into the orb, the orb fills the screen, the tagline, back to the logo.
    const o = O.start;
    if (t >= o + O.expand - 0.05) {
      const ex = E.inOut5(prog(t, o + O.expand, O.full - O.expand));
      const back = E.inOut5(prog(t, o + O.contract, O.bars - O.contract));
      const bigD = 2 * Math.hypot(W, H) * 0.62;
      const startD = 300, endD = 2 * 64 * LK * 1.0;
      let d = startD * Math.pow(bigD / startD, ex);
      if (back > 0) d = bigD * Math.pow(endD / bigD, back);
      const cx = back > 0 ? lerp(CENTRE.x, endBars.x, back) : CENTRE.x;
      const cy = back > 0 ? lerp(CENTRE.y, endBars.y, back) : CENTRE.y;
      const m = prog(t, o + O.bars, 0.7);
      if (m <= 0) ORB.draw(ctx, cx, cy, d, { time: ORB_T(t), level: 0.25 + 0.5 * ex * (1 - back), grain: d > ORB.MAX ? 0 : 1, colors: orbColors(ex * (1 - back)) });
      else bars(endBars.x, endBars.y, d / 2, LK, m, { time: t, solid: sm(0.5, 1, m) });
    }
    css(tagline, { display: t > o + O.tag - 0.1 && t < o + O.contract + 0.6 ? 'block' : 'none' });
    phrases.forEach((p, i) => {
      const pin = E.out3(prog(t, o + O.tag + i * O.tag_step, 0.55));
      const pout = E.inOut3(prog(t, o + O.contract - 0.35, 0.4));
      css(p, { opacity: pin * (1 - pout), transform: `translateY(${(1 - pin) * 26}px) scale(${1 - pout * 0.04})`, filter: `blur(${(1 - pin) * 10 + pout * 8}px)` });
    });
    const wm = E.out5(prog(t, o + O.word, 0.8));
    // The wordmark is revealed from the bars outwards.
    const clip = (1 - wm) * 100;
    css(wordmark, { display: t > o + O.word - 0.05 ? 'block' : 'none', opacity: wm, clipPath: `inset(0 ${clip}% 0 0)` });
    const u = E.out5(prog(t, o + O.word + 0.4, 0.8));
    css(url, { opacity: u, transform: `translateY(${(1 - u) * 18}px)` });
  }

  function topLogoFrame(t) {
    const first = scenes[0].enter + T.assemble;
    const a = E.out3(prog(t, first - 0.6, 0.6)) * (1 - E.inOut3(prog(t, T.outro.start, 0.5)));
    css(topLogo, { opacity: a * 0.92, display: a > 0 ? 'block' : 'none' });
  }

  function sceneFrame(sc, si, t) {
    const next = scenes[si + 1];
    const end = sc.exit + T.gather + 0.05;
    const startT = sc.title ? sc.title.start : sc.enter;
    if (t < startT - 0.05 || t > (next && next.title ? next.title.start + 0.01 : end) && t > end) {
      sc.faces.forEach(f => css(f.who, { display: 'none' }));
      sc.lines.forEach(l => { css(l.box, { display: 'none' }); if (l.spoken) css(l.spoken, { display: 'none' }); });
      if (sc.title) css(sc.title.box, { display: 'none' });
      css(sc.where, { display: 'none' });
      return;
    }

    // Title card: the scene before gathers into the orb; the word appears and the
    // orb becomes its full stop; then the orb bursts into this scene's faces.
    if (sc.title) titleFrame(sc, t);

    // Faces.
    const span = sc.exit - sc.enter;
    const zoom = 1 + 0.035 * clamp((t - sc.enter) / span);
    const lineNow = (() => {
      let cur = null;
      for (const l of sc.lines) if (t >= l.show - 0.3) cur = l;
      return cur;
    })();
    const leaving = t > sc.exit;
    const R = 150;
    sc.faces.forEach((f, fi) => {
      let act = 1;
      if (sc.kind === 'duo') {
        // The speaker is in colour, the listener in soft grey; they hand over as the prompt changes.
        const prevLine = sc.lines.filter(l => t >= l.show - 0.3);
        const speaker = prevLine.length ? prevLine[prevLine.length - 1] : sc.lines[0];
        const before = prevLine.length > 1 ? prevLine[prevLine.length - 2] : null;
        const k = sm(speaker.show - 0.3, speaker.show + 0.05, t);
        const was = before ? (before.face === fi ? 1 : 0) : 0.6;
        act = lerp(was, speaker.face === fi ? 1 : 0, k);
        if (t < sc.lines[0].show - 0.3) act = 0.6;
      }
      const from = si === 0 ? fromBars(CENTRE.x, CENTRE.y, BIG_K) : fromOrb(CENTRE.x, CENTRE.y, R);
      const st = {
        t, act, zoom, amp: ampAt(t),
        motion: lineNow && lineNow.face === fi ? tagMotion(lineNow, t) : tagMotion(null, t),
        enter: t < sc.enter + T.assemble + 0.1 ? { u: t - sc.enter, from } : null,
        leave: leaving ? { v: t - sc.exit, x: CENTRE.x, y: CENTRE.y, r: R * E.out3(prog(t, sc.exit, T.gather)) } : null,
      };
      if (t >= sc.enter) drawFace(f, st);

      // Label under the face.
      const lin = E.out3(prog(t, sc.enter + 0.7, 0.6));
      const lout = E.inOut3(prog(t, sc.exit - 0.05, 0.35));
      const vis = lin * (1 - lout);
      css(f.who, { display: vis > 0 ? 'flex' : 'none', opacity: vis * lerp(0.42, 1, act),
        transform: `translateX(-50%) translateY(${(1 - lin) * 14}px)` });
    });

    // Gathered into the orb (drawn here unless the next scene's title or the outro draws it).
    if (leaving && t < sc.exit + T.gather + 0.05) {
      const g = E.out3(prog(t, sc.exit + 0.1, T.gather - 0.1));
      if (!next) { if (t < T.outro.start + T.outro.expand) ORB.draw(ctx, CENTRE.x, CENTRE.y, 300 * g, { time: ORB_T(t), level: 0.3, colors: ORB_C }); }
    }

    sc.lines.forEach(l => promptFrame(l, t));

    const win = E.out3(prog(t, sc.enter + 0.55, 0.6)) * (1 - E.inOut3(prog(t, sc.exit - 0.05, 0.35)));
    css(sc.where, { display: win > 0 ? 'flex' : 'none', opacity: win, transform: `translateX(-50%) translateY(${(1 - win) * -10}px)` });
    if (sc.clock) {
      const secs = Math.max(0, Math.floor(t - sc.lines[0].at + 1));
      sc.clock.textContent = `00:${String(secs).padStart(2, '0')}`;
    }
  }

  // Title card: the orb gathers at the centre, drops to where the word starts and
  // writes it as it travels (the letters appear behind it), then stays as its full stop.
  function titleFrame(sc, t) {
    const ti = sc.title, s = ti.start, TT = T.title;
    const box = ti.box;
    const visible = t >= s + TT.write - 0.05 && t < s + TT.out + 0.45;
    css(box, { display: visible ? 'block' : 'none' });
    if (visible && !ti.geo) {
      const st = ti.stop.getBoundingClientRect();
      const first = ti.chars[0].getBoundingClientRect(), last = ti.chars[ti.chars.length - 1].getBoundingClientRect();
      const left = Math.min(first.left, last.left), right = Math.max(first.right, last.right);
      ti.geo = { x: st.left + st.width / 2, y: st.top + st.height / 2, d: st.width * 1.15, from: AR ? right : left };
    }
    const g = E.out3(prog(t, s + 0.1, TT.word - 0.1));
    const drop = E.inOut3(prog(t, s + TT.word, TT.write - TT.word));
    const write = E.inOut3(prog(t, s + TT.write, TT.hold - TT.write));
    const back = E.inOut3(prog(t, s + TT.out + 0.05, 0.45));
    const burst = E.in3(prog(t, s + TT.burst, 0.3));
    let x = CENTRE.x, y = CENTRE.y, d = 300 * g;
    if (ti.geo) {
      const G = ti.geo, pen = 70;
      if (drop > 0) { x = lerp(CENTRE.x, G.from, drop); y = lerp(CENTRE.y, G.y, drop); d = 300 * Math.pow(pen / 300, drop); }
      if (write > 0) { x = lerp(G.from, G.x, write); y = G.y; d = pen * Math.pow(G.d / pen, write); }
      if (back > 0) { x = lerp(G.x, CENTRE.x, back); y = lerp(G.y, CENTRE.y, back); d = G.d * Math.pow(300 / G.d, back); }
      // The word is revealed up to the orb, with a soft edge.
      const edge = 50;
      const grad = AR ? `linear-gradient(to right, transparent ${x - edge * 0.6}px, #000 ${x + edge * 0.4}px)`
        : `linear-gradient(to right, #000 ${x - edge * 0.4}px, transparent ${x + edge * 0.6}px)`;
      const mask = write >= 1 || back > 0 ? 'none' : (write > 0 ? grad : 'linear-gradient(transparent, transparent)');
      css(box, { webkitMaskImage: mask, maskImage: mask });
    }
    const pout = E.inOut3(prog(t, s + TT.out, 0.35));
    css(box, { opacity: 1 - pout, filter: `blur(${pout * 10}px)` });
    d *= 1 - burst;
    if (t < s + TT.burst + 0.3) ORB.draw(ctx, x, y, d, { time: ORB_T(t), level: 0.3, colors: ORB_C });
  }

  // A prompt longer than three lines is set a little smaller, so it stays clear of
  // the bottom of the frame (where phones put their captions and buttons).
  function fitPrompts() {
    scenes.forEach(sc => sc.lines.forEach(l => {
      css(l.box, { display: 'block' });
      const lh = parseFloat(getComputedStyle(l.box).lineHeight);
      if (l.box.getBoundingClientRect().height > lh * 3.4) l.box.style.fontSize = (parseFloat(getComputedStyle(l.box).fontSize) * 0.86) + 'px';
      css(l.box, { display: 'none' });
    }));
  }

  window.seek = seek;
  window.ready = document.fonts.ready.then(() => Promise.all([...document.images].map(i => i.complete ? 1 : new Promise(r => { i.onload = i.onerror = r; })))).then(() => { fitPrompts(); seek(0); return true; });
})();
