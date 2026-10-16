/* Listen launch ad, light studio design. Every frame is a pure function of
 * time: window.seek(t) sets the whole stage to its state at t seconds. */
(() => {
  const T = window.TIMELINE;
  const AR = T.ui === 'ar';
  // pr: the speaker's profile photo (centre and diameter); it sits over the top edge of the card.
  const LAYOUTS = {
    '9x16': { W: 1080, H: 1920, brandY: 150, pr: { x: 540, y: 716, d: 316 }, cardY: 756, cardX: 60, cardW: 960, wmY: 1330, wmS: 520,
      hookY: 300, tilesTop: 790, tileS: 196, tileGap: 40, switchY: 560, flagsY: 870, flag: 120, revealY: 330, bannerY: 1000,
      envTitleY: 390, envCardY: 610, introY: 520, introIconsY: 980, waveH: 170,
      studioY: 560, tagY: 420, logoY: 540, logoH: 190, statsY: 820, urlY: 1080, btnY: 1170, facesY: 1345 },
    '4x5': { W: 1080, H: 1350, brandY: 56, pr: { x: 540, y: 300, d: 236 }, cardY: 330, cardX: 80, cardW: 920, wmY: 1010, wmS: 300,
      hookY: 120, tilesTop: 500, tileS: 160, tileGap: 30, switchY: 300, flagsY: 560, flag: 104, revealY: 120, bannerY: 700,
      envTitleY: 120, envCardY: 290, introY: 330, introIconsY: 700, waveH: 130,
      studioY: 260, tagY: 130, logoY: 230, logoH: 160, statsY: 450, urlY: 690, btnY: 770, facesY: 920 },
  };
  const L = LAYOUTS[T.ratio] || LAYOUTS['9x16'];
  document.documentElement.style.setProperty('--W', L.W + 'px');
  document.documentElement.style.setProperty('--H', L.H + 'px');
  if (AR) document.documentElement.setAttribute('dir', 'rtl');
  const stage = document.getElementById('stage');

  // ── Helpers ────────────────────────────────────────────────
  const clamp = (x, a = 0, b = 1) => Math.min(b, Math.max(a, x));
  const lerp = (a, b, x) => a + (b - a) * x;
  const prog = (t, a, d) => clamp((t - a) / d);
  const E = {
    out3: x => 1 - Math.pow(1 - x, 3),
    out5: x => 1 - Math.pow(1 - x, 5),
    in3: x => x * x * x,
    inOut3: x => (x < .5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2),
    // A soft spring (critically damped with a touch of overshoot), as a pure function of progress.
    spring: x => (x >= 1 ? 1 : 1 - Math.exp(-6.5 * x) * Math.cos(4.2 * x) * (1 - x * .15)),
    outBack: (x, s = 1.2) => 1 + (s + 1) * Math.pow(x - 1, 3) + s * Math.pow(x - 1, 2),
  };
  const amp = t => { const f = t * T.fps; const i = Math.floor(f); return f < 0 ? 0 : lerp(T.amp[i] || 0, T.amp[i + 1] || 0, f - i); };
  const el = (tag, cls, parent, html) => { const n = document.createElement(tag); if (cls) n.className = cls; if (html != null) n.innerHTML = html; (parent || stage).appendChild(n); return n; };
  const css = (n, o) => { for (const k in o) n.style[k] = o[k]; };
  const isAr = s => /[؀-ۿ]/.test(s);
  const mix = (a, b, x) => a.map((v, i) => v + (b[i] - v) * x);
  const rgb = c => `rgb(${c.map(Math.round).join(',')})`;
  const hsl = (h, s, l) => { s /= 100; l /= 100; const k = n => (n + h / 30) % 12; const a = s * Math.min(l, 1 - l); return [0, 8, 4].map(n => 255 * (l - a * Math.max(-1, Math.min(k(n) - 3, 9 - k(n), 1)))); };
  const scenes = T.scenes;
  const sceneOf = k => scenes.find(s => s.kind === k);
  const LOGO = AR ? 'assets/logo-ar-light.svg' : 'assets/logo-light.svg';
  const INK = [17, 17, 20]; const FAINT = [207, 204, 214]; const BRAND = [91, 61, 245]; const BRAND_ICON = [118, 89, 250];
  const ENV_ICONS = {
    announcement: '<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>',
    phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
    radio: '<path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"/><path d="M19.1 4.9C23 8.8 23 15.1 19.1 19"/>',
    cave: '<path d="M2 20 9 6l4 6 2-3 7 11z"/><path d="M10 20c0-2.2 .9-3.6 2-3.6s2 1.4 2 3.6"/>',
    hall: '<path d="M3 21h18M5 21V10M9 21V10M15 21V10M19 21V10"/><path d="M2 10l10-6 10 6z"/>',
    church: '<path d="M12 2v4M10 4h4"/><path d="M6 21V11l6-5 6 5v10"/><path d="M3 21h18"/><path d="M10 21v-4a2 2 0 0 1 4 0v4"/>',
  };
  const icon = k => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${ENV_ICONS[k] || ENV_ICONS.hall}</svg>`;
  const FACE = (mouth, extra = '') => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.5"/><path d="M8.5 9.5h.01M15.5 9.5h.01" stroke-width="3"/>${mouth}${extra}</svg>`;
  const TAG_ICONS = {
    laugh: FACE('<path d="M7.5 13.5c1 2.4 2.6 3.6 4.5 3.6s3.5-1.2 4.5-3.6z" fill="currentColor" stroke="none"/>'),
    chuckle: FACE('<path d="M8 14.5c1.2 1.4 2.5 2 4 2s2.8-.6 4-2"/>'),
    giggle: FACE('<path d="M7.5 13.5c1 2.4 2.6 3.6 4.5 3.6s3.5-1.2 4.5-3.6z" fill="currentColor" stroke="none"/>'),
    snicker: FACE('<path d="M8.5 15c1.6.6 3.9.9 7-1"/>'),
    gasp: FACE('<ellipse cx="12" cy="15.5" rx="2" ry="2.5" fill="currentColor" stroke="none"/>'),
    sob: FACE('<path d="M8.5 16.5c1-1.2 2.2-1.8 3.5-1.8s2.5.6 3.5 1.8"/>', '<path d="M7.6 11.2c-.8 1.2-.8 2.1 0 2.6.8-.5.8-1.4 0-2.6z" fill="currentColor"/>'),
    sigh: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 9h11a3 3 0 1 0-3-3"/><path d="M3 14h15a3 3 0 1 1-3 3"/></svg>',
    whispers: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H3v6h3l5 4z"/><path d="M15.5 9.5a3.5 3.5 0 0 1 0 5"/></svg>',
    shout: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10v4h3l9 5V5L6 10z"/><path d="M18 8.5a5 5 0 0 1 0 7M20.5 6a8.5 8.5 0 0 1 0 12"/></svg>',
  };

  // ── Brand waves ────────────────────────────────────────────
  // The voice drawn as soft, layered waves in the brand's violet, tapering at the
  // ends like the Listen mark. Pure function of (t, level): no state between frames.
  const WAVE_LAYERS = [
    { f1: 1.15, f2: 2.3, s1: 2.1, s2: 3.3, p: 0.0, k: 1.0, a: .50, c: 'brand' },
    { f1: 1.6, f2: 3.1, s1: -2.6, s2: 2.2, p: 1.9, k: .82, a: .42, c: 'hue' },
    { f1: 0.9, f2: 2.7, s1: 1.5, s2: -2.9, p: 3.7, k: .64, a: .38, c: 'soft' },
  ];
  function drawWave(cv, t, level, o = {}) {
    const ctx = cv.getContext('2d'); const w = cv.width; const h = cv.height; const mid = h / 2;
    ctx.clearRect(0, 0, w, h);
    const hue = o.hue == null ? 255 : o.hue;
    const tint = o.tint || 0; // 0: brand violet, 1: the scene's own colour
    const cols = {
      brand: BRAND_ICON,
      hue: mix([160, 139, 255], hsl(hue, 85, 70), .3 + tint * .45),
      soft: mix([255, 168, 204], hsl(hue, 90, 80), tint * .6),
    };
    const N = 140;
    const shape = (ly, A, tt) => {
      const pts = [];
      for (let i = 0; i <= N; i++) {
        const x = i / N; const env = Math.pow(Math.sin(Math.PI * x), 2.4);
        const breathe = .82 + .18 * Math.sin(tt * .9 + ly.p);
        pts.push(A * ly.k * breathe * env * (Math.sin(2 * Math.PI * ly.f1 * x + tt * ly.s1 + ly.p) * .64 + Math.sin(2 * Math.PI * ly.f2 * x + tt * ly.s2 + ly.p * 1.7) * .36));
      }
      return pts;
    };
    const fill = (pts, color, alpha) => {
      ctx.beginPath();
      for (let i = 0; i <= N; i++) { const x = (i / N) * w; i ? ctx.lineTo(x, mid - pts[i]) : ctx.moveTo(x, mid - pts[i]); }
      for (let i = N; i >= 0; i--) ctx.lineTo((i / N) * w, mid + pts[i]);
      ctx.closePath(); ctx.fillStyle = `rgba(${color.map(Math.round).join(',')},${alpha})`; ctx.fill();
    };
    // A hairline that the waves grow out of.
    const g = ctx.createLinearGradient(0, 0, w, 0);
    g.addColorStop(0, 'rgba(118,89,250,0)'); g.addColorStop(.5, 'rgba(118,89,250,.35)'); g.addColorStop(1, 'rgba(118,89,250,0)');
    ctx.fillStyle = g; ctx.fillRect(0, mid - 1, w, 2);
    ctx.globalCompositeOperation = 'multiply';
    const A = (mid - 4) * (.05 + .95 * clamp(level / .95));
    // Echo ghosts: the cave answers back a moment later.
    if (o.echo > 0) {
      [[.2, .5], [.42, .3]].forEach(([d, g2]) => {
        const la = (o.levelAt ? o.levelAt(t - d) : level);
        fill(shape(WAVE_LAYERS[0], (mid - 4) * (.04 + .9 * clamp(la / .95)) * 1.1, t - d), cols.brand, .32 * g2 * o.echo);
      });
    }
    WAVE_LAYERS.forEach(ly => fill(shape(ly, A, t), cols[ly.c], ly.a));
    ctx.globalCompositeOperation = 'source-over';
  }

  // ── Background: warm paper with drifting pastel light ──────
  const bg = el('div', 'layer', stage);
  const ORBS = [
    { x: .12, y: .14, s: 1.05, c: [255, 214, 194] },  // peach
    { x: .92, y: .30, s: 1.0, c: [206, 196, 255] },   // lavender
    { x: .20, y: .78, s: 1.1, c: [196, 228, 255] },   // sky
    { x: .88, y: .92, s: .95, c: [205, 239, 224] },   // mint
  ];
  const orbs = ORBS.map(() => el('div', 'orb', bg));
  const grain = el('div', 'layer', stage); grain.id = 'grain';

  // Small logo at the top, through the ad.
  const brand = el('div', '', stage, `<img src="${LOGO}">`); brand.id = 'brand';
  css(brand, { top: L.brandY + 'px' });

  // ── Tiles (hook, reveal) ───────────────────────────────────
  const tilesLayer = el('div', 'layer', stage);
  const rows = (() => { const n = T.tiles.length; if (n === 11) return [4, 3, 4]; const r = Math.ceil(n / 4); const b = Math.floor(n / r); const x = n % r; return Array.from({ length: r }, (_, i) => b + (i < x ? 1 : 0)); })();
  const tilePos = [];
  { let k = 0; rows.forEach((n, r) => { const w = n * L.tileS + (n - 1) * L.tileGap; const x0 = (L.W - w) / 2; for (let i = 0; i < n && k < T.tiles.length; i++, k++) tilePos.push({ x: x0 + i * (L.tileS + L.tileGap), y: L.tilesTop + r * (L.tileS + L.tileGap) }); }); }
  const tiles = T.tiles.map(avatar => {
    const t = el('div', 'tile', tilesLayer, `<div class="face"><img src="assets/${avatar}.png"></div><div class="flag"><img src="assets/${T.tile_flag[avatar]}.svg"></div>` +
      `<div class="badge"><img src="assets/icon-light.svg">${AR ? 'إسمع' : 'Listen'}</div>`);
    css(t, { width: L.tileS + 'px', height: L.tileS + 'px' });
    return { root: t, face: t.querySelector('.face'), badge: t.querySelector('.badge'), flag: t.querySelector('.flag'), avatar };
  });

  // ── Titles ─────────────────────────────────────────────────
  const wordsHtml = (ws, accentFrom = Infinity) => ws.map((w, i) => `<span class="w${i >= accentFrom ? ' accent' : ''}">${w.w} </span>`).join('');
  // Words rise into place out of a soft blur, one after another.
  const wordIn = (w, a, out = 0, rise = 34) => { w.style.opacity = a * (1 - out); w.style.transform = `translateY(${(1 - a) * rise - out * 30}px)`; w.style.filter = a < .995 || out > .005 ? `blur(${((1 - a) * 10 + out * 6).toFixed(2)}px)` : 'none'; };
  const hook = sceneOf('hook');
  const hookTitle = el('div', 'title display' + (isAr(hook.words.map(w => w.w).join('')) ? ' ar' : ''), stage, wordsHtml(hook.words, hook.words.length - 1));
  css(hookTitle, { top: L.hookY + 'px', fontSize: (T.ratio === '4x5' ? 74 : 90) + 'px' });
  const hookWords = [...hookTitle.querySelectorAll('.w')];

  const sw = sceneOf('switch') || { start: -9, end: -9, words: [] };
  const swTitle = el('div', 'title display' + (isAr(sw.words.map(w => w.w).join('')) ? ' ar' : ''), stage, wordsHtml(sw.words, sw.words.length - 1));
  css(swTitle, { top: L.switchY + 'px', fontSize: (T.ratio === '4x5' ? 96 : 110) + 'px' });
  const swWords = [...swTitle.querySelectorAll('.w')];
  // Two rows of the next language's flags, drifting past each other.
  const swFlagRows = [];
  if (sceneOf('switch')) {
    const flags = T.switch_flags || [];
    const half = Math.ceil(flags.length / 2);
    [flags.slice(0, half), flags.slice(half)].forEach((list, r) => {
      const row = el('div', 'flagrow', stage);
      css(row, { top: (L.flagsY + r * (L.flag + 30)) + 'px', height: (L.flag + 40) + 'px' });
      const items = list.map(f => { const n = el('div', 'flagchip', row, `<img src="assets/${f}.svg">`); css(n, { width: L.flag + 'px', height: L.flag + 'px' }); return n; });
      swFlagRows.push({ row, items });
    });
  }

  const reveal = sceneOf('reveal');
  const phrases = [];
  reveal.words.forEach(w => { if (!phrases.length || /[؟?!.]$/.test(phrases[phrases.length - 1].text.trim())) phrases.push({ words: [], text: '' }); const p = phrases[phrases.length - 1]; p.words.push(w); p.text += w.w + ' '; });
  const revealEls = phrases.map((p, i) => {
    if (i === 2) return el('div', 'banner' + (isAr(p.text) ? ' ar' : ''), stage, `<img src="assets/icon-128.svg"><span>${p.text.trim()}</span>`);
    const n = el('div', 'title display' + (isAr(p.text) ? ' ar' : ''), stage, wordsHtml(p.words, i === 1 ? 0 : Infinity));
    css(n, { fontSize: (i === 1 ? (T.ratio === '4x5' ? 98 : 116) : (T.ratio === '4x5' ? 74 : 88)) + 'px' });
    return n;
  });

  const intro = sceneOf('envintro');
  let introTitle = null; let introWords = []; let introIcons = [];
  if (intro) {
    const split = intro.words.findIndex(w => /^(في|anywhere)/.test(w.w));
    introTitle = el('div', 'title display' + (isAr(intro.words.map(w => w.w).join('')) ? ' ar' : ''), stage, wordsHtml(intro.words, split < 0 ? Infinity : split));
    css(introTitle, { top: L.introY + 'px', fontSize: (T.ratio === '4x5' ? 68 : 80) + 'px' });
    introWords = [...introTitle.querySelectorAll('.w')];
    introIcons = ['announcement', 'phone', 'radio', 'hall', 'cave', 'church'].map(k => el('div', 'envchip', stage, icon(k)));
  }

  // ── Voice cards ────────────────────────────────────────────
  // A speaker card is a profile: the photo sits over the card's top edge, then
  // name, accent and emotion, the line with its delivery tags, and the voice as waves.
  const players = scenes.filter(s => s.kind === 'speaker' || s.kind === 'env').map((s, idx) => {
    const root = el('div', 'spk', stage);
    const isEnv = s.kind === 'env';
    const D = L.pr.d;
    let halo = null; let portrait = null; let ring = null; let badge = null; let wm = null;
    if (!isEnv) {
      halo = el('div', 'halo', root);
      css(halo, { left: (L.pr.x - D * 1.1) + 'px', top: (L.pr.y - D * 1.1) + 'px', width: D * 2.2 + 'px', height: D * 2.2 + 'px',
        background: `radial-gradient(circle, hsla(${s.hue}, 90%, 78%, .8) 0%, hsla(${s.hue}, 90%, 84%, .35) 40%, transparent 68%)` });
    } else {
      wm = el('div', 'envwm', root, icon(s.icon));
      css(wm, { left: (L.W / 2 - L.wmS / 2) + 'px', top: L.wmY + 'px', width: L.wmS + 'px', height: L.wmS + 'px' });
    }
    const card = el('div', 'card', root);
    const top = isEnv ? L.envCardY : L.cardY;
    const padX = isEnv ? 52 : 56;
    const padTop = isEnv ? 48 : Math.round(L.pr.y + D / 2 + 18 - L.cardY + 56); // clear of the speaking ring, with room to breathe
    css(card, { left: L.cardX + 'px', top: top + 'px', width: L.cardW + 'px', padding: `${padTop}px ${padX}px 34px` });
    if (!isEnv) {
      ring = el('div', 'ring', root);
      css(ring, { left: (L.pr.x - D / 2 - 18) + 'px', top: (L.pr.y - D / 2 - 18) + 'px', width: (D + 36) + 'px', height: (D + 36) + 'px' });
      portrait = el('div', 'portrait', root, `<img src="assets/${s.avatar}.png">`);
      css(portrait, { left: (L.pr.x - D / 2) + 'px', top: (L.pr.y - D / 2) + 'px', width: D + 'px', height: D + 'px' });
      // The accent's flag, pinned to the photo like a status badge.
      const B = Math.round(D * .27); const off = D / 2 * Math.SQRT1_2;
      badge = el('div', 'pbadge', root, `<img src="assets/${s.flag}.svg">`);
      css(badge, { left: (L.pr.x + (s.lang === 'ar' ? -off : off) - B / 2) + 'px', top: (L.pr.y + off - B / 2) + 'px', width: B + 'px', height: B + 'px' });
    }
    const dir = s.lang === 'ar' ? 'rtl' : 'ltr';
    const tokens = (s.tokens || s.words.map((w, i) => ({ word: i })));
    const script = tokens.map(tk => tk.tag ? `<span class="tag">${TAG_ICONS[tk.key] || TAG_ICONS.laugh}<b>${tk.tag}</b></span>` : `<span class="w">${s.words[tk.word].w} </span>`).join('');
    let head;
    if (isEnv) {
      head = `<div class="envhead"><div class="envicon">${icon(s.icon)}</div><div class="envid"><div class="place">${s.place}</div><div class="scene">${s.scene_label}</div></div></div>` +
        `<div class="seg"><div class="knob"></div><span class="c">${AR ? 'صوت نقي' : 'Clean'}</span><span class="p">${AR ? 'في المكان' : 'In the place'}</span></div>`;
    } else {
      head = `<div class="head"><div class="name">${s.name}</div>` +
        `<div class="chips-row"><div class="chip">${s.dialect}</div>${s.emotion ? `<div class="chip emo"><i></i>${s.emotion}</div>` : ''}</div></div>`;
    }
    card.setAttribute('dir', isEnv ? (AR ? 'rtl' : 'ltr') : dir);
    card.innerHTML = head + `<div class="script ${s.lang === 'ar' ? 'ar' : ''}" dir="${dir}">${script}</div>` +
      (s.tr ? `<div class="tr ${AR ? 'ar' : ''}" dir="${AR ? 'rtl' : 'ltr'}">${s.tr}</div>` : '') + '<canvas class="wave"></canvas>';
    const cv = card.querySelector('canvas');
    cv.width = L.cardW - padX * 2; cv.height = L.waveH; css(cv, { width: cv.width + 'px', height: cv.height + 'px' });
    // When each delivery tag is heard: between the words around it.
    const tagEls = [...card.querySelectorAll('.tag')];
    const tagWins = []; let lastWord = -1;
    tokens.forEach((tk, j) => {
      if (tk.tag) {
        const next = tokens.slice(j + 1).find(x => x.word != null);
        tagWins.push({ a: lastWord >= 0 ? s.words[lastWord].e : s.vo_start - .08, b: next ? s.words[next.word].s : s.vo_end });
      } else lastWord = tk.word;
    });
    const seg = card.querySelector('.seg');
    return { s, idx, root, halo, portrait, ring, badge, card, cv, isEnv, wm, words: [...card.querySelectorAll('.script .w')], tagEls, tagWins,
      seg, knob: seg && seg.querySelector('.knob'), segC: seg && seg.querySelector('.c'), segP: seg && seg.querySelector('.p'),
      envicon: card.querySelector('.envicon') };
  });
  // Neighbouring cards of the same kind hand over like a carousel: both on screen, one gesture.
  players.forEach(p => {
    const i = scenes.indexOf(p.s); const prev = scenes[i - 1]; const next = scenes[i + 1];
    p.linkPrev = !!prev && prev.kind === p.s.kind;
    p.linkNext = !!next && next.kind === p.s.kind;
    p.bOut = next ? next.start : p.s.end;
  });
  const firstEnv = scenes.find(s => s.kind === 'env');
  const lastEnv = [...scenes].reverse().find(s => s.kind === 'env');
  let envTitle = null;
  if (firstEnv) {
    envTitle = el('div', 'title display' + (AR ? ' ar' : ''), stage, AR ? 'ضع صوتك في <span class="accent">أي مكان</span>' : 'Put your voice <span class="accent">anywhere</span>');
    css(envTitle, { top: L.envTitleY + 'px', fontSize: (T.ratio === '4x5' ? 60 : 72) + 'px' });
  }

  // ── CTA ────────────────────────────────────────────────────
  const cta = sceneOf('cta');
  const ctaRoot = el('div', 'layer' + (AR ? ' ar' : ''), stage);
  const studio = el('div', 'studio', ctaRoot);
  css(studio, { left: L.cardX + 'px', top: L.studioY + 'px', width: L.cardW + 'px' });
  const typed = AR ? 'أهلاً بيك… ده صوتك الجديد!' : 'Hey there… meet your new voice!';
  const chipsData = AR
    ? [['sa', 'سعودي'], ['eg', 'مصري'], ['lb', 'لبناني'], ['ae', 'إماراتي'], ['ma', 'مغربي'], ['us', 'أمريكي']]
    : [['us', 'American'], ['gb', 'British'], ['au', 'Australian'], ['in', 'Indian'], ['sa', 'Saudi'], ['eg', 'Egyptian']];
  studio.innerHTML = `<div class="bar"><i></i><i></i><i></i><b>${AR ? 'استوديو إسمع' : 'Listen Studio'}</b></div>` +
    `<div class="text" dir="${AR ? 'rtl' : 'ltr'}"><span class="typed"></span><span class="caret"></span></div>` +
    `<div class="chips">${chipsData.map(([f, n]) => `<span class="chip"><img src="assets/${f}.svg">${n}</span>`).join('')}</div>` +
    '<canvas class="wave"></canvas>';
  const typedEl = studio.querySelector('.typed'); const caret = studio.querySelector('.caret');
  const chipEls = [...studio.querySelectorAll('.chip')];
  const studioWave = studio.querySelector('canvas');
  studioWave.width = L.cardW - 92; studioWave.height = L.waveH - 20; css(studioWave, { width: studioWave.width + 'px', height: studioWave.height + 'px' });
  const tagline = el('div', 'title display' + (AR ? ' ar' : ''), ctaRoot, AR ? 'اكتب… <span class="accent">واسمع</span>' : 'Type it. <span class="accent">Hear it.</span>');
  css(tagline, { top: L.tagY + 'px', fontSize: (T.ratio === '4x5' ? 56 : 66) + 'px' });
  const endLogo = el('img', 'endlogo', ctaRoot); endLogo.src = LOGO; css(endLogo, { height: L.logoH + 'px', top: L.logoY + 'px' });
  const stats = el('div', 'stats' + (T.ratio === '4x5' ? ' small' : ''), ctaRoot,
    `<div class="stat"><div class="n">+140</div><div class="l ${AR ? 'ar' : ''}">${AR ? 'لهجة عربية' : 'Arabic dialects'}</div></div>` +
    `<div class="stat"><div class="n">+60</div><div class="l ${AR ? 'ar' : ''}">${AR ? 'لكنة إنجليزية' : 'English accents'}</div></div>`);
  css(stats, { top: L.statsY + 'px' });
  if (AR) stats.style.flexDirection = 'row-reverse';
  const statEls = [...stats.querySelectorAll('.stat')]; const statN = [...stats.querySelectorAll('.n')];
  const url = el('div', 'url', ctaRoot, 'listenai.studio'); url.setAttribute('dir', 'ltr'); css(url, { top: L.urlY + 'px' });
  const btn = el('div', 'cta-btn', ctaRoot, `${AR ? 'ابدأ الآن' : 'Start now'}<span class="shine"></span>`); css(btn, { top: L.btnY + 'px' });
  const shine = btn.querySelector('.shine');
  const faces = el('div', 'faces', ctaRoot, T.tiles.map(a => `<img src="assets/${a}.png">`).join('')); css(faces, { top: L.facesY + 'px' });
  const faceEls = [...faces.querySelectorAll('img')];

  // ── Frame ──────────────────────────────────────────────────
  const speakers = scenes.filter(s => s.kind === 'speaker');
  const firstSpk = speakers[0];
  // The light takes each card's colour, easing from one to the next.
  const tintKeys = (() => {
    const keys = [];
    for (const s of scenes) {
      let h = 255;
      if (s.kind === 'speaker' || s.kind === 'env') h = s.hue;
      if (!keys.length || keys[keys.length - 1][1] !== h) keys.push([s.start - .3, h]);
    }
    return keys;
  })();
  const hueAt = t => {
    let i = 0; while (i + 1 < tintKeys.length && tintKeys[i + 1][0] <= t) i++;
    let h = tintKeys[0][1];
    for (let k = 1; k <= i; k++) { const d = ((tintKeys[k][1] - h + 540) % 360) - 180; h += d * E.inOut3(prog(t, tintKeys[k][0], .9)); }
    return h;
  };
  // The voice level for one scene, faded in and out at its edges so a card never
  // picks up its neighbour's voice.
  const levelIn = (s, t) => amp(t) * clamp(prog(t, s.vo_start - .14, .1)) * (1 - prog(t, s.vo_end + .02, .25));
  const HAND = .62; // seconds for a carousel hand-over
  const dirX = AR ? -1 : 1;

  function seek(t) {
    // Background light.
    const h = hueAt(t);
    orbs.forEach((o, i) => {
      const d = ORBS[i]; const size = L.W * d.s * 1.25;
      const x = d.x * L.W + Math.sin(t * .17 + i * 1.9) * 70 - size / 2;
      const y = d.y * L.H + Math.cos(t * .13 + i * 1.3) * 90 - size / 2;
      const c = i === 1 ? `hsla(${h.toFixed(1)}, 95%, 85%, .95)` : `rgba(${d.c.join(',')}, .9)`;
      css(o, { width: size + 'px', height: size + 'px', transform: `translate(${x}px, ${y}px)`, background: `radial-gradient(circle at 50% 50%, ${c} 0%, transparent 60%)` });
    });
    brand.style.opacity = 1 - E.inOut3(prog(t, cta.logo_at - .5, .4));

    // Hook and reveal tiles.
    const toFirst = E.inOut3(prog(t, hook.end - .6, .6));
    const revealOut = E.inOut3(prog(t, reveal.end - .35, .4));
    const third = phrases[2] ? phrases[2].words[0].s : reveal.end;
    tiles.forEach((tile, i) => {
      const p = tilePos[i];
      let x = p.x; let y = p.y; let s = 0; let o = 1; let size = L.tileS; let rad = 30;
      if (t < hook.end) {
        const pop = E.spring(prog(t, T.tile_times[i], .7));
        y += Math.sin(t * 1.6 + i * .9) * 6 + (1 - E.out3(prog(t, T.tile_times[i], .6))) * 50;
        s = pop;
        if (tile.avatar === firstSpk.avatar) {
          x = lerp(p.x, L.pr.x - L.pr.d / 2, toFirst); y = lerp(y, L.pr.y - L.pr.d / 2, toFirst); size = lerp(L.tileS, L.pr.d, toFirst); rad = lerp(30, 50, toFirst);
        } else { s *= 1 - toFirst * .25; o = 1 - toFirst; }
        tile.badge.style.opacity = 0;
      } else if (t >= reveal.start - .1 && t < reveal.end + .1) {
        s = E.spring(prog(t, reveal.start + .05 + i * .04, .7)) * (1 - revealOut * .2);
        o = 1 - revealOut;
        y += Math.sin(t * 1.6 + i * .9) * 5;
        const bd = E.spring(prog(t, T.reveal_tiles[i], .55));
        tile.badge.style.opacity = clamp(bd * 1.4);
        tile.badge.style.transform = `translateX(-50%) scale(${bd})`;
        const dim = E.inOut3(prog(t, third - .25, .45));
        o *= 1 - dim * .55; s *= 1 - dim * .05;
      } else { o = 0; }
      css(tile.root, { left: x + 'px', top: y + 'px', width: size + 'px', height: size + 'px', transform: `scale(${s})`, opacity: o, display: s > .001 && o > .001 ? 'block' : 'none' });
      tile.face.style.borderRadius = rad + '%';
      tile.flag.style.opacity = tile.avatar === firstSpk.avatar && t < hook.end ? 1 - toFirst : 1;
    });

    hookWords.forEach((w, i) => wordIn(w, E.out5(prog(t, hook.words[i].s - .08, .5)), E.inOut3(prog(t, hook.end - .8, .4))));
    hookTitle.style.display = t < hook.end + .1 ? 'block' : 'none';

    // Switch: the question, then the accents of the next language drift in.
    {
      const vis = t >= sw.start - .1 && t <= sw.end + .35; swTitle.style.display = vis ? 'block' : 'none';
      const out = E.inOut3(prog(t, sw.end - .2, .4));
      swWords.forEach((w, i) => wordIn(w, E.out5(prog(t, sw.words[i].s - .12, .45)), out, 40));
      swFlagRows.forEach(({ row, items }, r) => {
        row.style.display = vis ? 'block' : 'none';
        if (!vis) return;
        const pitch = L.flag + 30; const n = items.length; const w0 = n * pitch - 30;
        const drift = (t - sw.start) * 46 * (r ? -1 : 1);
        const x0 = (L.W - w0) / 2 + (r ? pitch / 2 : -pitch / 2) + drift;
        items.forEach((f, i) => {
          const centre = Math.abs(i - (n - 1) / 2);
          const a = E.spring(prog(t, sw.start + .05 + centre * .045 + r * .08, .6));
          css(f, { left: (x0 + i * pitch) + 'px', top: (20 + Math.sin(t * 2 + i * .8 + r) * 5) + 'px', opacity: clamp(a * 1.5) * (1 - out), transform: `translateY(${(1 - a) * 30 + out * -20}px) scale(${.6 + .4 * a})` });
        });
      });
    }

    // Reveal titles and banner.
    revealEls.forEach((n, i) => {
      const ph = phrases[i]; const st = ph.words[0].s; const vis = t >= st - .25 && t <= reveal.end + .3;
      n.style.display = vis ? (i === 2 ? 'flex' : 'block') : 'none';
      if (!vis) return;
      const out = E.inOut3(prog(t, reveal.end - .3, .35));
      if (i === 0) {
        const a = E.out5(prog(t, st - .15, .5)); const next = phrases[1] ? phrases[1].words[0].s : reveal.end;
        const gone = E.inOut3(prog(t, next - .14, .18));
        css(n, { top: L.revealY + 'px', opacity: a * (1 - gone), transform: `translateY(${(1 - a) * 30 - gone * 16}px)`, filter: `blur(${((1 - a) * 8 + gone * 8).toFixed(2)}px)` });
      } else if (i === 1) {
        const a = E.out5(prog(t, st - .06, .4));
        css(n, { top: L.revealY + 'px', opacity: a * (1 - out), transform: `scale(${lerp(1.14, 1, a)})`, filter: `blur(${((1 - a) * 10 + out * 6).toFixed(2)}px)` });
      } else {
        const a = E.spring(prog(t, st - .15, .65));
        css(n, { top: L.bannerY + 'px', opacity: clamp(a * 1.3) * (1 - out), transform: `translateX(-50%) translateY(${(1 - a) * 40}px) scale(${lerp(.9, 1, a)})` });
      }
    });

    // Environment intro.
    if (intro) {
      const vis = t >= intro.start - .1 && t <= intro.end + .35;
      introTitle.style.display = vis ? 'block' : 'none';
      const out = E.inOut3(prog(t, intro.end - .2, .4));
      introWords.forEach((w, i) => wordIn(w, E.out5(prog(t, intro.words[i].s - .1, .45)), out));
      const n = introIcons.length; const gap = 160; const x0 = (L.W - ((n - 1) * gap + 136)) / 2;
      introIcons.forEach((c, i) => {
        const a = E.spring(prog(t, intro.start + .2 + i * .07, .65));
        css(c, { display: vis ? 'grid' : 'none', left: (x0 + i * gap) + 'px', top: (L.introIconsY + Math.sin(t * 2.2 + i * 1.3) * 10 + (i % 2) * 40) + 'px', opacity: clamp(a * 1.4) * (1 - out), transform: `translateY(${(1 - a) * 40}px) scale(${.7 + .3 * a}) rotate(${(i % 2 ? 4 : -4)}deg)` });
      });
    }
    if (envTitle) {
      const a = E.out5(prog(t, firstEnv.start, .55)); const out = E.inOut3(prog(t, lastEnv.end - .25, .4));
      envTitle.style.display = t >= firstEnv.start - .1 && t <= lastEnv.end + .3 ? 'block' : 'none';
      css(envTitle, { opacity: a * (1 - out), transform: `translateY(${(1 - a) * 24}px)`, filter: `blur(${((1 - a) * 8).toFixed(2)}px)` });
    }

    // Voice cards.
    players.forEach(p => {
      const s = p.s;
      const fromTile = s === firstSpk;
      const visFrom = p.linkPrev ? s.start - HAND * .45 : fromTile ? hook.end - .05 : s.start - .05;
      const visTo = p.linkNext ? p.bOut + HAND * .55 : s.end + .05;
      if (t < visFrom || t > visTo) { p.root.style.display = 'none'; return; }
      p.root.style.display = 'block';

      // Where the card is: entering, settled, or leaving.
      let x = 0; let y = 0; let sc = 1; let op = 1; let photoIn = 1;
      if (p.linkPrev) {
        const e = E.inOut3(prog(t, s.start - HAND * .45, HAND));
        x += (1 - e) * dirX * L.W * .92; sc *= 1 - .05 * Math.sin(Math.PI * e);
      } else {
        const e = E.out5(prog(t, s.start + .02, .6));
        y += (1 - e) * 150; sc *= lerp(.96, 1, e); op *= fromTile ? 1 : clamp(e * 1.6);
        photoIn = fromTile ? 1 : E.spring(prog(t, s.start + .08, .7));
      }
      if (p.linkNext) {
        const e = E.inOut3(prog(t, p.bOut - HAND * .45, HAND));
        x -= e * dirX * L.W * .92; sc *= 1 - .05 * Math.sin(Math.PI * e);
      } else {
        const e = E.inOut3(prog(t, s.end - .32, .34));
        y -= e * 50; sc *= lerp(1, .96, e); op *= 1 - e;
      }
      p.root.style.opacity = op;
      const cardIn = fromTile ? E.out5(prog(t, s.start + .02, .6)) : 1;
      p.card.style.transform = `translate(${x}px, ${y + (1 - cardIn) * 0}px) scale(${sc})`;
      p.card.style.opacity = fromTile ? clamp(cardIn * 1.5) : 1;

      const level = levelIn(s, t);
      if (p.portrait) {
        // The photo leads the card slightly in a hand-over, so the two read as layers.
        const px = x * 1.08; const py = y * .9;
        const ps = (fromTile ? 1 : lerp(.7, 1, photoIn)) * sc;
        p.portrait.style.opacity = fromTile ? (t >= hook.end - .02 ? 1 : 0) : clamp(photoIn * 1.6);
        p.portrait.style.transform = `translate(${px}px, ${py - level * 3}px) scale(${ps * (1 + level * .012)})`;
        p.badge.style.opacity = clamp(prog(t, (fromTile ? hook.end : s.start) + .2, .3));
        const bs = E.spring(prog(t, (fromTile ? hook.end : s.start) + .2, .5));
        p.badge.style.transform = `translate(${px}px, ${py}px) scale(${ps * bs})`;
        // A speaking ring, like a live audio room: it breathes with the voice.
        const speak = clamp(level * 1.4);
        p.ring.style.opacity = (.15 + .85 * speak) * clamp(photoIn * 1.6) * (fromTile ? clamp(prog(t, hook.end, .3)) : 1);
        p.ring.style.transform = `translate(${px}px, ${py}px) scale(${ps * (1 + speak * .045)})`;
        p.halo.style.opacity = clamp(prog(t, fromTile ? hook.end - .3 : s.start, .6));
        p.halo.style.transform = `translate(${x * .9}px, ${y * .9}px) scale(${1 + level * .06 + Math.sin(t * 1.4) * .02})`;
      }

      // Karaoke: each word fills in as it is said; the word being said is in the brand colour.
      p.words.forEach((w, i) => {
        const wd = s.words[i];
        const said = E.out3(prog(t, wd.s - .02, Math.min(.22, Math.max(.08, wd.e - wd.s))));
        const now = clamp(prog(t, wd.s - .06, .08)) * (1 - prog(t, wd.e, .2));
        w.style.color = rgb(mix(mix(FAINT, INK, said), BRAND, now * .92));
      });
      // Delivery tags light up while they are heard.
      p.tagEls.forEach((g, j) => {
        const win = p.tagWins[j];
        const on = E.out3(prog(t, win.a - .06, .14)) * (1 - E.inOut3(prog(t, win.b - .02, .3)));
        g.style.transform = `scale(${1 + .04 * on})`;
        g.style.background = rgb(mix([255, 244, 214], [255, 226, 150], on));
        g.style.borderColor = rgb(mix([243, 221, 160], [230, 180, 70], on));
      });

      // Environments: start clean, then the knob slides and the voice is in the place.
      let placed = 1;
      if (p.seg) {
        placed = s.ab_at == null ? 1 : E.inOut3(prog(t, s.ab_at - .14, .34));
        const half = (L.cardW - 104 - 16) / 2;
        const pos = AR ? lerp(half, 0, placed) : lerp(0, half, placed);
        css(p.knob, { left: (8 + pos) + 'px', width: half + 'px' });
        p.segC.style.color = rgb(mix([255, 255, 255], [139, 136, 150], placed));
        p.segP.style.color = rgb(mix([139, 136, 150], [255, 255, 255], placed));
        const pc = hsl(s.hue, 75, 52);
        p.envicon.style.background = rgb(mix([239, 235, 255], mix(hsl(s.hue, 90, 93), [255, 255, 255], .1), placed));
        p.envicon.style.color = rgb(mix(BRAND_ICON, pc, placed));
      }
      if (p.wm) {
        const w = E.out5(prog(t, (s.ab_at == null ? s.start : s.ab_at) - .1, .7));
        p.wm.style.opacity = w;
        p.wm.style.color = `hsla(${s.hue}, 70%, 55%, .16)`;
        p.wm.style.transform = `translate(${x * .8}px, ${(1 - w) * 40 + Math.sin(t * 1.3) * 8}px) scale(${lerp(.9, 1, w) * (1 + level * .03)})`;
      }
      drawWave(p.cv, t, level, { hue: s.hue, tint: p.isEnv ? placed : .25, echo: p.isEnv && s.icon === 'cave' ? placed : 0, levelAt: tt => levelIn(s, tt) });
    });

    // CTA: the studio types, picks a dialect and speaks; then the end card.
    const cvis = t >= cta.start - .3; ctaRoot.style.display = cvis ? 'block' : 'none';
    if (cvis) {
      const t0 = cta.start;
      const sin = E.out5(prog(t, t0 - .1, .65)); const sout = E.inOut3(prog(t, cta.logo_at - .45, .4));
      css(studio, { opacity: clamp(sin * 1.4) * (1 - sout), transform: `translateY(${(1 - sin) * 150 - sout * 70}px) scale(${lerp(.96, 1, sin) * lerp(1, .95, sout)})` });
      const t1 = T.typing[0]; const t2 = T.typing[T.typing.length - 1];
      const typeP = prog(t, t1 - .02, t2 - t1 + .1);
      typedEl.textContent = typed.slice(0, Math.round(typed.length * typeP));
      caret.style.opacity = typeP < 1 ? 1 : .5 + .5 * Math.cos(t * Math.PI * 2.4);
      const pick = Math.min(4, Math.floor(prog(t, t2 - .25, .8) * 5));
      chipEls.forEach((c, i) => c.classList.toggle('on', typeP > .6 && i === pick));
      if (sout < 1) drawWave(studioWave, t, amp(t) * clamp(prog(t, t2 + .1, .3)), { hue: 255, tint: 0 });

      const lg = E.out5(prog(t, cta.logo_at, .65));
      css(endLogo, { opacity: lg, transform: `translateX(-50%) translateY(${(1 - lg) * 30}px) scale(${lerp(.9, 1, lg)})`, filter: `blur(${((1 - lg) * 10).toFixed(2)}px)` });
      const tg = E.out5(prog(t, cta.logo_at + .2, .55)); css(tagline, { opacity: tg, transform: `translateY(${(1 - tg) * 24}px)` });
      statEls.forEach((s, i) => {
        const a = E.out5(prog(t, T.stats_at + i * .15, .6));
        s.style.opacity = a; s.style.transform = `translateY(${(1 - a) * 40}px)`;
        statN[i].textContent = '+' + Math.round((i === 0 ? 140 : 60) * E.out3(prog(t, T.stats_at + i * .15, .9)));
      });
      const u = E.out5(prog(t, T.stats_at + .5, .55)); css(url, { opacity: u, transform: `translateY(${(1 - u) * 20}px)` });
      const b = E.spring(prog(t, T.stats_at + .65, .7)); css(btn, { opacity: clamp(b * 1.3), transform: `translateX(-50%) scale(${.85 + .15 * b})` });
      faceEls.forEach((f, i) => { const a = E.spring(prog(t, T.stats_at + .85 + i * .035, .6)); f.style.opacity = clamp(a * 1.3); f.style.transform = `translateY(${(1 - a) * 24}px) scale(${.7 + .3 * a})`; });
      const sh = ((t - T.stats_at - 1.4) % 1.8) / 1.8;
      shine.style.left = (t > T.stats_at + 1.4 ? lerp(-40, 140, E.inOut3(sh)) : -40) + '%';
    }
  }

  window.seek = seek;
  window.ready = (async () => {
    await document.fonts.ready;
    await Promise.all([...document.images].map(i => (i.complete ? i.decode().catch(() => {}) : new Promise(r => { i.onload = i.onerror = () => r(); }))));
    seek(0);
    return true;
  })();
})();
