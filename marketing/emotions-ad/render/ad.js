/* Listen emotions ad. One voice, one line, a new emotion and dialect on every
 * take. Every frame is a pure function of time: window.seek(t) sets the whole
 * stage to its state at t seconds. */
(() => {
  const T = window.TIMELINE;
  const AR = T.ui === 'ar';
  const WIDE = T.ratio === '16x9';
  // Positions are written for a left-to-right page; X() mirrors them for Arabic.
  const L = WIDE
    ? { W: 1920, H: 1080, pr: { x: 560, y: 470, d: 380 }, ringR: 300, who: { x: 560, y: 760, align: 'center' },
        list: { x: 960, y: 500, pitch: 66, size: 34, cur: 44, span: 4, align: 'start' },
        line: { x: 1330, y: 500, w: 500, size: 52, align: 'start' }, logo: { x: 80, y: 64, h: 40 },
        flagsY: 620, endY: 380, endSize: 84, logoY: 400, logoH: 170, urlY: 640 }
    : { W: 1080, H: 1920, pr: { x: 540, y: 560, d: 420 }, ringR: 320, who: { x: 540, y: 880, align: 'center' },
        list: { x: 540, y: 1230, pitch: 66, size: 36, cur: 50, span: 2.4, align: 'center' },
        line: { x: 540, y: 1600, w: 920, size: 62, align: 'center' }, logo: { x: 540, y: 110, h: 46, centre: true },
        flagsY: 1080, endY: 700, endSize: 96, logoY: 760, logoH: 205, urlY: 1060 };
  document.documentElement.style.setProperty('--W', L.W + 'px');
  document.documentElement.style.setProperty('--H', L.H + 'px');
  if (AR) document.documentElement.setAttribute('dir', 'rtl');
  const X = x => (AR ? L.W - x : x);
  const stage = document.getElementById('stage');

  // ── Helpers ────────────────────────────────────────────────
  const clamp = (x, a = 0, b = 1) => Math.min(b, Math.max(a, x));
  const lerp = (a, b, x) => a + (b - a) * x;
  const prog = (t, a, d) => clamp((t - a) / d);
  const E = {
    out3: x => 1 - Math.pow(1 - x, 3),
    out5: x => 1 - Math.pow(1 - x, 5),
    inOut3: x => (x < .5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2),
    inOut5: x => (x < .5 ? 16 * Math.pow(x, 5) : 1 - Math.pow(-2 * x + 2, 5) / 2),
    spring: x => (x >= 1 ? 1 : 1 - Math.exp(-6.5 * x) * Math.cos(4.2 * x) * (1 - x * .15)),
  };
  const amp = t => { const f = t * T.fps; const i = Math.floor(f); return f < 0 ? 0 : lerp(T.amp[i] || 0, T.amp[i + 1] || 0, f - i); };
  const el = (tag, cls, parent, html) => { const n = document.createElement(tag); if (cls) n.className = cls; if (html != null) n.innerHTML = html; (parent || stage).appendChild(n); return n; };
  const css = (n, o) => { for (const k in o) n.style[k] = o[k]; };
  const mix = (a, b, x) => a.map((v, i) => v + (b[i] - v) * x);
  const rgb = c => `rgb(${c.map(Math.round).join(',')})`;
  const isAr = s => /[؀-ۿ]/.test(s);
  const INK = [16, 16, 20]; const FAINT = [201, 200, 208]; const MUTED = [154, 153, 163]; const VIOLET = [91, 61, 245];
  const S = T.scenes; const C = T.cta;
  const sceneAt = t => { let i = 0; while (i + 1 < S.length && S[i + 1].start <= t) i++; return i; };

  // ── Background: cool grey ground, one soft white light behind the face ──
  const light = el('div', '', stage); light.id = 'light';
  const grain = el('div', 'layer', stage); grain.id = 'grain';

  // ── Corners ────────────────────────────────────────────────
  const logo = el('img', 'logo', stage); logo.src = AR ? 'assets/logo-ar-light.svg' : 'assets/logo-light.svg';
  css(logo, { height: L.logo.h + 'px', top: L.logo.y + 'px' });

  // ── The speaker ────────────────────────────────────────────
  const D = L.pr.d; const R = L.ringR;
  const ring = el('canvas', '', stage); ring.id = 'ring';
  ring.width = R * 2; ring.height = R * 2;
  css(ring, { width: R * 2 + 'px', height: R * 2 + 'px', left: (X(L.pr.x) - R) + 'px', top: (L.pr.y - R) + 'px' });
  // Every take's speaker, stacked; the current one shows.
  const portrait = el('div', 'portrait', stage, S.map(s => `<img src="assets/${s.avatar}.png">`).join(''));
  const faces = [...portrait.querySelectorAll('img')];
  css(portrait, { width: D + 'px', height: D + 'px', left: (X(L.pr.x) - D / 2) + 'px', top: (L.pr.y - D / 2) + 'px' });
  const who = el('div', 'who', stage);
  who.innerHTML = `<div class="name"></div><div class="dia"></div>`;
  const nameBox = who.querySelector('.name');
  const nameSlot = el('div', 'dslot', nameBox); css(nameSlot, { height: '44px', width: '800px' });
  const nameRows = S.map(s => { const r = el('div', 'row', nameSlot, s.name); css(r, { position: 'absolute', left: 0, right: 0, top: 0, justifyContent: 'center' }); return r; });
  css(who, { left: (X(L.who.x) - 400) + 'px', width: '800px', top: L.who.y + 'px', alignItems: 'center' });
  const dia = who.querySelector('.dia');
  // One row per take (flag and dialect); they slide through the same slot.
  const diaRows = S.map(s => { const r = el('div', 'row', null, `<img src="assets/${s.flag}.svg"><span>${s.dialect}</span>`); r.setAttribute('dir', s.lang === 'ar' ? 'rtl' : 'ltr'); return r; });
  const diaSlot = el('div', 'dslot', dia); css(diaSlot, { height: '34px', width: '800px' });
  diaRows.forEach(r => { diaSlot.appendChild(r); css(r, { position: 'absolute', left: 0, right: 0, top: 0, justifyContent: 'center' }); });

  // Fine bars all the way round the face: the voice, drawn as a circular spectrum.
  const BARS = 120;
  function drawRing(t, level, hueMix) {
    const ctx = ring.getContext('2d'); const c = R;
    ctx.clearRect(0, 0, R * 2, R * 2);
    const r0 = D / 2 + 26; const room = R - r0 - 6;
    ctx.lineCap = 'round';
    for (let b = 0; b < BARS; b++) {
      const a = b / BARS * Math.PI * 2 - Math.PI / 2;
      const shape = .5 + .5 * Math.sin(b * .37 + t * 2.1) * Math.cos(b * .13 - t * 1.4);
      const fine = .5 + .5 * Math.sin(b * 1.9 + t * 7.3);
      const len = 3 + level * room * (.25 + .6 * shape + .15 * fine);
      const alpha = .16 + .7 * clamp(level * 1.2) * (.5 + .5 * shape);
      ctx.strokeStyle = `rgba(${mix(VIOLET, [150, 130, 255], hueMix).map(Math.round).join(',')},${alpha.toFixed(3)})`;
      ctx.lineWidth = 2.4;
      ctx.beginPath();
      ctx.moveTo(c + Math.cos(a) * r0, c + Math.sin(a) * r0);
      ctx.lineTo(c + Math.cos(a) * (r0 + len), c + Math.sin(a) * (r0 + len));
      ctx.stroke();
    }
  }

  // ── The list: every delivery, the current one in brackets ──
  const listItems = [...T.extra[0], ...S.map(s => s.emotion), ...T.extra[1]];
  const itemLang = [...T.extra[0].map(() => T.ui), ...S.map(s => s.lang), ...T.extra[1].map(() => T.ui)];
  const first = T.extra[0].length;
  const list = el('div', 'layer', stage);
  const items = listItems.map((label, k) => {
    const n = el('div', 'item', list, `<span class="br">[</span><span class="lb">${label}</span><span class="br">]</span>`);
    n.setAttribute('dir', itemLang[k] === 'ar' ? 'rtl' : 'ltr');
    return { n, br: [...n.querySelectorAll('.br')], lb: n.querySelector('.lb') };
  });

  // ── The line, said again in every take ──
  const lines = S.map(s => {
    const n = el('div', 'line' + (isAr(s.words.map(w => w.w).join('')) ? ' ar' : ''), stage,
      s.words.map(w => `<span class="w">${w.w} </span>`).join(''));
    const rtl = s.lang === 'ar';
    n.setAttribute('dir', rtl ? 'rtl' : 'ltr');
    css(n, { width: L.line.w + 'px', fontSize: L.line.size + 'px',
      textAlign: L.line.align === 'center' ? 'center' : (AR ? 'right' : 'left') });
    return { n, words: [...n.querySelectorAll('.w')] };
  });
  // Small up and down marks beside the line, as in a picker.
  const chevs = WIDE ? ['M6 15l6-6 6 6', 'M6 9l6 6 6-6'].map(d => el('div', 'chev', stage,
    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="${d}"/></svg>`)) : [];

  // ── End card ───────────────────────────────────────────────
  // As in the prompts films: the portrait becomes the Listen orb, which fills the
  // frame behind the end line, then shrinks back and splits into the logo's bars.
  const fx = el('canvas', 'layer', stage); fx.width = L.W; fx.height = L.H;
  const fxc = fx.getContext('2d');
  const hex = h => { const v = parseInt(h.slice(1), 16); return [((v >> 16) & 255) / 255, ((v >> 8) & 255) / 255, (v & 255) / 255]; };
  const ORB_C = ['#24138A', '#6747F7', '#B3A2FF', '#FFE3D3'].map(hex);
  const DEEP_C = ['#12094A', '#3A22C2', '#7659FA', '#E3B9FF'].map(hex);
  const orbColors = k => ORB_C.map((c, i) => c.map((v, j) => lerp(v, DEEP_C[i][j], k)));
  const BAR_H = [10, 44, 72, 100, 72, 44, 10];
  // The logo's seven bars, out of a circle of radius r (m = 0) into bars of k px per logo unit (m = 1).
  function drawBars(cx, cy, r, k, m, time) {
    const e = E.inOut3(clamp(m));
    const sw = (2 * r) / 7;
    fxc.save();
    fxc.beginPath();
    for (let i = 0; i < 7; i++) {
      const x0 = -r + (i + .5) * sw;
      const h0 = 2 * Math.sqrt(Math.max(0, r * r - x0 * x0)) * .98;
      const w = lerp(sw * .94, 10 * k, e);
      const h = Math.max(w, lerp(h0, BAR_H[i] * k, e));
      fxc.roundRect(cx + lerp(x0, (18 * i - 54) * k, e) - w / 2, cy - h / 2, w, h, w / 2);
    }
    fxc.clip();
    const solid = clamp((m - .5) / .5);
    if (solid < 1) ORB.draw(fxc, cx, cy, 2 * Math.max(r, 64 * k) * 1.05, { time: time * .9, colors: ORB_C });
    if (solid > 0) { fxc.globalAlpha = solid; fxc.fillStyle = '#7659FA'; fxc.fillRect(cx - 80 * k, cy - 64 * k, 160 * k, 128 * k); fxc.globalAlpha = 1; }
    fxc.restore();
  }
  // The end line, one phrase per line ("One line." / "Every emotion." / "Every accent…").
  const phrases = [[]];
  C.words.slice(0, C.text_words).forEach(w => { phrases[phrases.length - 1].push(w); if (/[.…؟!]$/.test(w.w)) phrases.push([]); });
  const endText = el('div', 'endtext' + (AR ? ' ar' : ''), stage,
    phrases.filter(p => p.length).map(p => `<div class="ph">${p.map(w => `<span class="w">${w.w} </span>`).join('')}</div>`).join(''));
  endText.setAttribute('dir', AR ? 'rtl' : 'ltr');
  css(endText, { top: L.endY + 'px', fontSize: L.endSize + 'px' });
  const endWords = [...endText.querySelectorAll('.w')];
  // The dialects just heard, as a row of flags under the end line.
  const seen = [...new Set(S.map(s => s.flag))];
  const flagRow = el('div', 'flagrow', stage, seen.map(f => `<img src="assets/${f}.svg">`).join(''));
  css(flagRow, { top: L.flagsY + 'px' });
  const flagEls = [...flagRow.querySelectorAll('img')];
  const everyIdx = (() => { const k = C.words.findIndex((w, j) => j > 2 && /^(كل|Every)/.test(w.w)); return k < 0 ? Math.max(0, C.words.length - 3) : k; })();
  // The end logo: the page draws the bars; the wordmark is the logo file without them.
  const VB = AR ? 307 : 400; const LK = L.logoH / 128;
  const wordmark = el('img', 'wordmark', stage); wordmark.src = AR ? 'assets/wordmark-ar-light.svg' : 'assets/wordmark-light.svg';
  const endLeft = L.W / 2 - VB * LK / 2;
  css(wordmark, { left: endLeft + 'px', top: L.logoY + 'px', width: VB * LK + 'px', height: L.logoH + 'px' });
  const endBars = { x: endLeft + (14 + 64) * LK, y: L.logoY + 64 * LK };
  const url = el('div', 'url', stage, 'listenai.studio'); css(url, { top: L.urlY + 'px' });

  // Where each list row sits: centred on the current take, easing between takes.
  const listPos = t => {
    let p = 0;
    S.forEach((s, i) => { if (i) p += E.inOut3(prog(t, s.start - .1, .45)); });
    return first + p;
  };
  // Each emotion moves the face its own way; the avatar is a still image.
  function emoMotion(sc, t, level) {
    const k = sc.emo_key; const m = { x: 0, y: 0, r: 0, s: 1, gray: 0 };
    const local = t - sc.start;
    if (k === 'Laugh') { m.y = -Math.abs(Math.sin(t * 9.5)) * level * 14; m.r = Math.sin(t * 4.8) * level * 2; }
    else if (k === 'Shout') { m.x = Math.sin(t * 47) * level * 7; m.s = 1 + level * .05; }
    else if (k === 'Sob') { m.r = Math.sin(t * 1.6) * 2.2; m.y = 8 + Math.sin(t * 3.2) * level * 4; m.gray = .35; }
    else if (k === 'Whisper') { m.s = .97; m.r = -2; }
    else if (k === 'Sarcastic') { m.r = 4 * E.inOut3(clamp(local / .8)); m.x = 6 * E.inOut3(clamp(local / .8)); }
    else if (k === 'Movie trailer') { m.s = 1 + .07 * E.inOut3(clamp(local / 3.5)); }
    else if (k === 'Excited') { m.y = -Math.abs(Math.sin(t * 12)) * level * 10; m.s = 1 + level * .035; }
    return m;
  }
  const levelIn = (s, t) => amp(t) * clamp(prog(t, s.vo_start - .14, .1)) * (1 - prog(t, s.vo_end + .02, .25));

  function seek(t) {
    const i = sceneAt(t); const s = S[i];
    const outro = E.inOut3(prog(t, C.start - .05, .45)); // the speaker steps back before the end line
    // The end line stays until the orb starts back towards the logo.
    const toLogo = E.inOut3(prog(t, C.contract_at - .3, .35));
    // The first frame is already the composed shot; it only settles.
    const intro = E.out5(prog(t, -.6, 1.1));

    // Light: a white bloom behind the face, faintly tinted by the take's emotion.
    const hue = lerp(S[Math.max(0, i - 1)].hue, s.hue, E.inOut3(prog(t, s.start - .1, .6)));
    const lr = WIDE ? 760 : 820;
    const lx = lerp(X(L.pr.x), L.W / 2, outro); const ly = lerp(L.pr.y, L.H / 2, outro);
    css(light, { width: lr * 2 + 'px', height: lr * 2 + 'px', left: (lx - lr) + 'px', top: (ly - lr) + 'px',
      background: `radial-gradient(circle, rgba(255,255,255,1) 0%, rgba(255,255,255,.92) 22%, hsla(${hue.toFixed(0)}, 70%, 93%, ${(.3 * (1 - outro)).toFixed(3)}) 46%, rgba(238,238,241,0) 70%)`,
      transform: `scale(${(1 + .05 * Math.sin(t * .6)) * lerp(.9, 1.25, outro)})` });

    // Corners.
    const lgx = L.logo.centre ? L.W / 2 : X(L.logo.x);
    css(logo, { left: lgx + 'px', transform: `translateX(${L.logo.centre || AR ? '-' : ''}${L.logo.centre ? 50 : (AR ? 100 : 0)}%)`, opacity: intro * (1 - outro) });

    // The speaker breathes and leans into the voice.
    const level = levelIn(s, t);
    const pin = E.out5(prog(t, -.6, 1.2));
    const ps = lerp(.92, 1, pin) * (1 + level * .018 + .006 * Math.sin(t * 1.3)) * lerp(1, .85, outro);
    // Blend the previous take's motion into this one's over the change.
    const w = i ? E.inOut3(prog(t, s.start, .45)) : 1;
    const ma = emoMotion(s, t, level); const mb = i ? emoMotion(S[i - 1], t, levelIn(S[i - 1], t)) : ma;
    const m = { x: lerp(mb.x, ma.x, w), y: lerp(mb.y, ma.y, w), r: lerp(mb.r, ma.r, w), s: lerp(mb.s, ma.s, w), gray: lerp(mb.gray, ma.gray, w) };
    const filt = [m.gray > .01 ? `grayscale(${m.gray.toFixed(2)})` : '', outro > .01 ? `blur(${(outro * 12).toFixed(2)}px)` : ''].join(' ').trim();
    css(portrait, { opacity: pin * (1 - outro), transform: `translate(${(AR ? -1 : 1) * m.x}px, ${(1 - pin) * 30 - level * 4 + outro * -20 + m.y}px) rotate(${(AR ? -1 : 1) * m.r}deg) scale(${ps * m.s})`,
      filter: filt || 'none' });
    css(ring, { opacity: clamp(prog(t, -.3, .6)) * (1 - outro), transform: `scale(${lerp(1, .85, outro)})` });
    drawRing(t, level, (hue % 360) / 720);
    css(who, { opacity: E.out5(prog(t, -.5, 1)) * (1 - outro), transform: `translateY(${(1 - E.out5(prog(t, -.5, 1))) * 20}px)` });
    // A new face for every take: the old one blurs away as the new one settles in.
    // A new face for every take: the old one dips into the frame's own backdrop, then the
    // new one rises out of it (never two faces at once).
    faces.forEach((f, k) => {
      const inn = k === 0 ? 1 : E.out3(prog(t, S[k].start + .02, .3));
      const out = k + 1 < S.length ? E.inOut3(prog(t, S[k + 1].start - .12, .16)) : 0;
      const o = inn * (1 - out);
      css(f, { position: 'absolute', inset: 0, display: o > .001 ? 'block' : 'none', opacity: o.toFixed(3),
        transform: `scale(${(lerp(1.05, 1, inn) * lerp(1, .97, out)).toFixed(4)})` });
    });
    [...nameRows, ...diaRows].forEach((r, kk) => {
      const k = kk % S.length;
      // The new dialect rises in as the old one lifts away.
      const inn = k === 0 ? 1 : E.out5(prog(t, S[k].start + .06, .42));
      const gone = k + 1 < S.length ? E.inOut3(prog(t, S[k + 1].start - .14, .18)) : 0;
      css(r, { opacity: clamp(inn * 1.4) * (1 - gone), transform: `translateY(${(1 - inn) * 26 - gone * 22}px)`, filter: `blur(${((1 - inn) * 6 + gone * 6).toFixed(2)}px)` });
    });

    // The list scrolls so the current take sits at the centre row.
    const pos = listPos(t);
    const lin = E.out5(prog(t, -.5, 1));
    items.forEach((it, k) => {
      const d = k - pos; const ad = Math.abs(d);
      const near = clamp(1 - ad);                    // 1 on the current row
      const y = L.list.y + d * L.list.pitch + (d > 0 ? 1 : -1) * near * 0;
      const fade = clamp(1 - Math.max(0, ad - .6) / (L.list.span + .4));
      const size = lerp(L.list.size, L.list.cur, near);
      it.n.style.fontSize = size.toFixed(1) + 'px';
      it.n.style.color = rgb(mix(mix(FAINT, MUTED, clamp(1.6 - ad * .5)), VIOLET, near));
      it.n.style.fontWeight = near > .5 ? 500 : 300;
      it.br.forEach(b => { b.style.opacity = near; });
      const x = L.list.align === 'center' ? L.W / 2 : X(L.list.x);
      const tx = L.list.align === 'center' ? '-50%' : (AR ? '-100%' : '0');
      css(it.n, { left: x + 'px', top: (y - size * .55) + 'px', transform: `translateX(${tx}) translateX(${(AR ? -1 : 1) * near * (L.list.align === 'center' ? 0 : 6)}px)`,
        opacity: fade * lin * (1 - outro), display: fade * lin * (1 - outro) > .002 ? 'block' : 'none' });
    });

    // The line: the take's words, lit as they are said.
    lines.forEach((ln, k) => {
      const sc = S[k];
      const inn = E.out5(prog(t, sc.start + .06, .45));
      const gone = k + 1 < S.length ? E.inOut3(prog(t, S[k + 1].start - .18, .22)) : outro;
      const vis = inn * (1 - gone);
      ln.n.style.display = vis > .002 ? 'block' : 'none';
      if (vis <= .002) return;
      const h = ln.n.offsetHeight;
      const lx = L.line.align === 'center' ? (L.W - L.line.w) / 2 : (AR ? L.W - L.line.x - L.line.w : L.line.x);
      css(ln.n, { left: lx + 'px', top: (L.line.y - h / 2) + 'px', opacity: vis, transform: `translateY(${(1 - inn) * 24 - gone * 18}px)`,
        filter: vis < .995 ? `blur(${((1 - inn) * 8 + gone * 8).toFixed(2)}px)` : 'none' });
      ln.words.forEach((w, j) => {
        const wd = sc.words[j];
        const said = E.out3(prog(t, wd.s - .02, Math.min(.22, Math.max(.08, wd.e - wd.s))));
        // Only the word being said is violet: it hands over to the next word as that one starts.
        const next = sc.words[j + 1];
        const now = clamp(prog(t, wd.s - .06, .08)) * (1 - prog(t, next ? next.s - .06 : wd.e + .1, next ? .08 : .25));
        w.style.color = rgb(mix(mix(FAINT, INK, said), VIOLET, now * .9));
      });
    });
    chevs.forEach((c, k) => css(c, { left: (X(L.line.x - 44) - (AR ? 22 : 0)) + 'px', top: (L.line.y + (k ? 8 : -30)) + 'px', opacity: lin * (1 - outro) }));

    // End card: the portrait turns into the orb, the orb fills the frame, the end line
    // rises in white word by word, then the orb comes back and becomes the logo.
    fxc.clearRect(0, 0, L.W, L.H);
    const appear = E.inOut3(prog(t, C.orb_at, .3));
    const grow = E.inOut5(prog(t, C.orb_at + .2, .8));
    const back = E.inOut5(prog(t, C.contract_at, .85));
    const morph = prog(t, C.logo_at - .1, .7);
    const bigD = 2 * Math.hypot(L.W, L.H) * .62, endD = 2 * 64 * LK;
    if (t >= C.orb_at) {
      if (morph <= 0) {
        let d = D * Math.pow(bigD / D, grow), cx = lerp(X(L.pr.x), L.W / 2, grow), cy = lerp(L.pr.y, L.H / 2, grow);
        if (back > 0) { d = bigD * Math.pow(endD / bigD, back); cx = lerp(L.W / 2, endBars.x, back); cy = lerp(L.H / 2, endBars.y, back); }
        fxc.globalAlpha = appear;
        ORB.draw(fxc, cx, cy, d, { time: t * .9, level: .25 + .5 * grow * (1 - back), grain: d > ORB.MAX ? 0 : 1, colors: orbColors(grow * (1 - back)) });
        fxc.globalAlpha = 1;
      } else {
        drawBars(endBars.x, endBars.y, endD / 2, LK, morph, t);
      }
    }
    const ev = t >= C.orb_at;
    endText.style.display = ev && toLogo < .999 ? 'block' : 'none';
    endWords.forEach((w, j) => {
      const a = E.out5(prog(t, Math.max(C.words[j].s - .1, C.orb_at + .85), .5));
      css(w, { opacity: a * (1 - toLogo), transform: `translateY(${(1 - a) * 36 - toLogo * 30}px)`, filter: a < .995 || toLogo > .005 ? `blur(${((1 - a) * 10 + toLogo * 8).toFixed(2)}px)` : 'none' });
    });
    flagRow.style.display = ev && toLogo < .999 ? 'flex' : 'none';
    flagEls.forEach((f, j) => {
      const a = E.spring(prog(t, Math.max(C.words[everyIdx].s, C.orb_at + 1) + j * .06, .6));
      css(f, { opacity: clamp(a * 1.4) * (1 - toLogo), transform: `translateY(${(1 - a) * 24 - toLogo * 20}px) scale(${.7 + .3 * a})` });
    });
    // The wordmark slides out of the bars, then the address.
    const wm = E.out5(prog(t, C.logo_at + .5, .8));
    css(wordmark, { display: wm > .001 ? 'block' : 'none', opacity: wm, clipPath: `inset(0 ${((1 - wm) * 100).toFixed(2)}% 0 0)` });
    const u = E.out5(prog(t, C.logo_at + .85, .8)); css(url, { opacity: u, transform: `translateY(${(1 - u) * 18}px)` });
  }

  window.seek = seek;
  window.ready = (async () => {
    await document.fonts.ready;
    await Promise.all([...document.images].map(i => (i.complete ? i.decode().catch(() => {}) : new Promise(r => { i.onload = i.onerror = () => r(); }))));
    endText.style.display = 'block';
    const h = endText.offsetHeight; const gap = WIDE ? 48 : 64; const top = Math.round((L.H - (h + gap + 54)) / 2);
    css(endText, { top: top + 'px' }); css(flagRow, { top: (top + h + gap) + 'px' });
    seek(0);
    return true;
  })();
})();
