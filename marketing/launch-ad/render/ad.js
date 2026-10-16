/* Listen launch ad: every frame is a pure function of time.
 * window.seek(t) puts the whole stage in its state at t seconds, so the
 * capture script can step through the ad frame by frame. */
(() => {
  const T = window.TIMELINE;
  const AR = T.ui === 'ar';
  const LAYOUTS = {
    '9x16': { W: 1080, H: 1920, hudY: 196, av: { x: 540, y: 770, d: 560 }, plateY: 1112, capY: 1300, capW: 990,
      titleY: 340, tilesTop: 650, tileS: 220, tileGap: 26, cardY: 520, cardW: 960, statsY: 1080, featsY: 1310,
      logoY: 700, logoH: 210, urlY: 990, btnY: 1110, revealY: 380, waveY: 1560, waveH: 200 },
    '4x5': { W: 1080, H: 1350, hudY: 36, av: { x: 540, y: 470, d: 420 }, plateY: 722, capY: 885, capW: 990,
      titleY: 150, tilesTop: 380, tileS: 176, tileGap: 22, cardY: 230, cardW: 940, statsY: 760, featsY: 975,
      logoY: 420, logoH: 170, urlY: 655, btnY: 760, revealY: 150, waveY: 1150, waveH: 160 },
  };
  const L = LAYOUTS[T.ratio] || LAYOUTS['9x16'];
  document.documentElement.style.setProperty('--W', L.W + 'px');
  document.documentElement.style.setProperty('--H', L.H + 'px');
  const stage = document.getElementById('stage');
  if (AR) document.documentElement.setAttribute('dir', 'rtl');

  // ── Helpers ────────────────────────────────────────────────
  const clamp = (x, a = 0, b = 1) => Math.min(b, Math.max(a, x));
  const lerp = (a, b, x) => a + (b - a) * x;
  const prog = (t, a, d) => clamp((t - a) / d);
  const E = {
    out3: x => 1 - Math.pow(1 - x, 3),
    outExpo: x => (x >= 1 ? 1 : 1 - Math.pow(2, -10 * x)),
    inExpo: x => (x <= 0 ? 0 : Math.pow(2, 10 * x - 10)),
    inOut3: x => (x < .5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2),
    outBack: (x, s = 1.9) => 1 + (s + 1) * Math.pow(x - 1, 3) + s * Math.pow(x - 1, 2),
    in3: x => x * x * x,
  };
  // A damped spring from 0 to 1.
  const spring = (x, k = 9, z = .42) => (x <= 0 ? 0 : 1 - Math.exp(-z * k * x) * Math.cos(k * Math.sqrt(1 - z * z) * x));
  const amp = t => {
    const f = t * T.fps; const i = Math.floor(f); const a = T.amp[i] || 0; const b = T.amp[i + 1] || 0;
    return lerp(a, b, f - i);
  };
  const el = (tag, cls, parent, html) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    (parent || stage).appendChild(n);
    return n;
  };
  const css = (n, o) => { for (const k in o) n.style[k] = o[k]; };
  const hash = i => { const x = Math.sin(i * 127.1 + 311.7) * 43758.5453; return x - Math.floor(x); };
  const ICONS = {
    laugh: '<svg viewBox="0 0 24 24" fill="none" stroke="#ffd54a" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9.5"/><path d="M7.5 13.5c1 2.4 2.6 3.6 4.5 3.6s3.5-1.2 4.5-3.6z" fill="#ffd54a" stroke="none"/><path d="M8 9.5l1.6 1M16 9.5l-1.6 1"/></svg>',
    stadium: '<svg viewBox="0 0 24 24" fill="none" stroke="#8fd3ff" stroke-width="2" stroke-linejoin="round"><ellipse cx="12" cy="14" rx="9.5" ry="5"/><ellipse cx="12" cy="13" rx="5" ry="2.2"/><path d="M4 7v5M20 7v5M4 7l2 1.2M20 7l-2 1.2"/></svg>',
    play: '<svg viewBox="0 0 24 24" fill="#fff"><path d="M7 4.5v15l13-7.5z"/></svg>',
  };
  const speakers = T.scenes.filter(s => s.kind === 'speaker');
  const sceneOf = kind => T.scenes.find(s => s.kind === kind);
  const isArabicText = s => /[؀-ۿ]/.test(s);

  // ── Background ─────────────────────────────────────────────
  const bg = el('div', 'layer', stage);
  const blobs = [0, 1, 2].map(() => el('div', 'blob', bg));
  const grid = el('div', 'layer', bg); grid.id = 'grid';
  const beams = el('div', 'layer', bg);
  const beamEls = [0, 1, 2, 3].map(i => {
    const b = el('div', 'beam', beams);
    css(b, { left: ['-12%', '66%', '8%', '46%'][i] });
    return b;
  });
  const bokeh = Array.from({ length: 26 }, (_, i) => {
    const b = el('div', 'bokeh', bg);
    const size = 10 + hash(i + 3) * 46;
    css(b, { width: size + 'px', height: size + 'px', opacity: .08 + hash(i + 11) * .22 });
    return { el: b, x: hash(i + 1), y: hash(i + 2), sp: .01 + hash(i + 5) * .03, size, depth: .3 + hash(i + 9) * .7 };
  });
  const vignette = el('div', 'layer', stage); vignette.id = 'vignette';

  // ── HUD ────────────────────────────────────────────────────
  const hud = el('div', 'abs', stage); hud.id = 'hud';
  css(hud, { left: 0, right: 0, top: L.hudY + 'px' });
  const hudLogo = el('img', 'logo', hud); hudLogo.src = AR ? 'assets/logo-ar-dark.svg' : 'assets/logo-dark.svg';
  const pill = el('div', 'pill', hud, `<span class="dot"></span><span>${AR ? 'مين فيهم الحقيقي؟' : 'Which one is real?'}</span><span class="count"></span>`);
  const pillCount = pill.querySelector('.count');

  // ── Tiles ──────────────────────────────────────────────────
  const tilesLayer = el('div', 'layer', stage);
  const rows = (() => {
    const n = T.tiles.length; if (n === 11) return [4, 3, 4];
    const r = Math.ceil(n / 4); const base = Math.floor(n / r); const extra = n % r;
    return Array.from({ length: r }, (_, i) => base + (i < extra ? 1 : 0));
  })();
  const tilePos = [];
  { let k = 0; rows.forEach((n, r) => {
      const w = n * L.tileS + (n - 1) * L.tileGap; const x0 = (L.W - w) / 2;
      for (let i = 0; i < n && k < T.tiles.length; i++, k++) tilePos.push({ x: x0 + i * (L.tileS + L.tileGap), y: L.tilesTop + r * (L.tileS + L.tileGap) });
  }); }
  const tiles = T.tiles.map((avatar, i) => {
    const t = el('div', 'tile', tilesLayer);
    t.innerHTML = `<div class="glow"></div><div class="face"><img src="assets/${avatar}.png"></div><div class="dim"></div><div class="flag"><img src="assets/${T.tile_flag[avatar]}.svg"></div>` +
      `<div class="stamp"><img src="assets/icon-128.svg">AI</div>`;
    css(t, { width: L.tileS + 'px', height: L.tileS + 'px' });
    return { root: t, glow: t.querySelector('.glow'), dim: t.querySelector('.dim'), stamp: t.querySelector('.stamp'), flag: t.querySelector('.flag'), avatar };
  });

  // ── Titles ─────────────────────────────────────────────────
  const words = (sc, cls = 'w') => sc.words.map(w => `<span class="${cls}">${w.w} </span>`).join('');
  const hook = sceneOf('hook');
  const hookTitle = el('div', 'title ' + (isArabicText(hook.words.map(w => w.w).join('')) ? 'ar' : ''), stage, words(hook));
  css(hookTitle, { top: L.titleY + 'px', fontSize: (T.ratio === '4x5' ? 76 : 92) + 'px', lineHeight: 1.25, padding: '0 60px' });
  const hookWords = [...hookTitle.querySelectorAll('.w')];
  hookWords.forEach((w, i) => { if (i >= hookWords.length - 2) w.classList.add('grad'); });

  const sw = sceneOf('switch') || { start: -9, end: -9, vo_start: -9, vo_end: -9, words: [] };
  const swTitle = el('div', 'title', stage, words(sw));
  css(swTitle, { top: (L.av.y - 330) + 'px', fontSize: '124px', lineHeight: 1.1 });
  const swWords = [...swTitle.querySelectorAll('.w')];
  swWords.forEach(w => w.classList.add('grad'));
  const nextLangFlags = (() => {
    const i = T.scenes.indexOf(sw);
    if (i < 0) return [];
    return T.scenes.slice(i + 1).filter(s => s.kind === 'speaker').map(s => s.flag).filter((f, k, a) => a.indexOf(f) === k);
  })();
  const swFlags = nextLangFlags.map(f => { const n = el('img', 'abs', stage); n.src = `assets/${f}.svg`; css(n, { width: '120px', height: '120px', borderRadius: '50%', objectFit: 'cover', boxShadow: '0 0 0 5px rgba(255,255,255,.9), 0 14px 40px rgba(0,0,0,.5)' }); return n; });

  const reveal = sceneOf('reveal');
  const phrases = [];
  reveal.words.forEach(w => {
    if (!phrases.length || /[؟?!.]$/.test(phrases[phrases.length - 1].text.trim())) phrases.push({ words: [], text: '' });
    const p = phrases[phrases.length - 1]; p.words.push(w); p.text += w.w + ' ';
  });
  const revealEls = phrases.map((p, i) => {
    const n = el('div', 'title' + (isArabicText(p.text) ? ' ar' : ''), stage, p.words.map(w => `<span class="w">${w.w} </span>`).join(''));
    css(n, { fontSize: (i === 1 ? 118 : i === 0 ? 96 : 54) + 'px', lineHeight: 1.15, padding: '0 50px' });
    const ws = [...n.querySelectorAll('.w')];
    if (i === 1) ws.forEach(w => w.classList.add('grad'));
    if (i === 2) {
      n.classList.add('banner');
      ws[ws.length - 1].classList.add('grad2');
    }
    return n;
  });

  // ── Speaker scenes ─────────────────────────────────────────
  const D = L.av.d;
  const vizSize = Math.round(D * 1.9);
  const spk = speakers.map((s, idx) => {
    const root = el('div', 'spk', stage);
    const viz = el('canvas', 'viz', root); viz.width = vizSize; viz.height = vizSize;
    css(viz, { left: (L.av.x - vizSize / 2) + 'px', top: (L.av.y - vizSize / 2) + 'px', width: vizSize + 'px', height: vizSize + 'px' });
    const glow = el('div', 'ringglow', root);
    css(glow, { left: (L.av.x - D / 2 - 18) + 'px', top: (L.av.y - D / 2 - 18) + 'px', width: (D + 36) + 'px', height: (D + 36) + 'px' });
    const ring = el('div', 'ring', root);
    css(ring, { left: (L.av.x - D / 2 - 16) + 'px', top: (L.av.y - D / 2 - 16) + 'px', width: (D + 32) + 'px', height: (D + 32) + 'px' });
    const av = el('div', 'av', root, `<img src="assets/${s.avatar}.png"><div class="glint"></div>`);
    css(av, { left: (L.av.x - D / 2) + 'px', top: (L.av.y - D / 2) + 'px', width: D + 'px', height: D + 'px' });
    let feature = null;
    if (s.chip) {
      feature = el('div', 'feature' + (AR ? ' ar' : ''), root, `${ICONS[s.chip_icon] || ''}<span>${s.chip}</span>`);
      css(feature, { top: (L.av.y - D / 2 - 10) + 'px', [AR ? 'right' : 'left']: (L.av.x + D * .18) + 'px' });
    }
    const plate = el('div', 'plate' + (AR ? ' ar' : ''), root, `<div class="who"><img src="assets/${s.flag}.svg"><div class="name">${s.name}</div></div><div class="dialect">${s.dialect}</div>`);
    css(plate, { top: L.plateY + 'px' });
    const capDir = s.lang === 'ar' ? 'rtl' : 'ltr';
    const cap = el('div', 'cap' + (s.lang === 'ar' ? ' ar' : ''), root, `<div class="line" dir="${capDir}">${s.words.map(w => `<span class="w">${w.w} </span>`).join('')}</div>` +
      (s.tr ? `<div class="tr ${AR ? 'ar' : ''}" dir="${AR ? 'rtl' : 'ltr'}">${s.tr}</div>` : ''));
    css(cap, { top: L.capY + 'px', width: L.capW + 'px', marginLeft: (-L.capW / 2) + 'px' });
    if (s.stadium) cap.querySelector('.line').style.fontSize = '64px';
    return { s, idx, root, viz, ctx: viz.getContext('2d'), glow, ring, av, glint: av.querySelector('.glint'), feature, plate, cap, words: [...cap.querySelectorAll('.w')], tr: cap.querySelector('.tr') };
  });

  // ── CTA ────────────────────────────────────────────────────
  const cta = sceneOf('cta');
  const ctaRoot = el('div', 'layer' + (AR ? ' ar' : ''), stage);
  const card = el('div', 'card', ctaRoot);
  css(card, { left: ((L.W - L.cardW) / 2) + 'px', top: L.cardY + 'px', width: L.cardW + 'px' });
  const typed = AR ? 'أهلاً بيك… ده صوتك الجديد!' : 'Hey there… meet your new voice!';
  const chipsData = AR
    ? [['sa', 'سعودي'], ['eg', 'مصري'], ['lb', 'لبناني'], ['ae', 'إماراتي'], ['ma', 'مغربي'], ['us', 'أمريكي'], ['gb', 'بريطاني'], ['au', 'أسترالي'], ['in', 'هندي']]
    : [['us', 'American'], ['gb', 'British'], ['au', 'Australian'], ['in', 'Indian'], ['ng', 'Nigerian'], ['sa', 'Saudi'], ['eg', 'Egyptian'], ['lb', 'Lebanese'], ['ma', 'Moroccan']];
  card.innerHTML = `<div class="bar"><i></i><i></i><i></i><b>${AR ? 'استوديو إسمع' : 'Listen Studio'}</b></div>` +
    `<div class="text" dir="${AR ? 'rtl' : 'ltr'}"><span class="typed"></span><span class="caret"></span></div>` +
    `<div class="chips">${chipsData.map(([f, n]) => `<span class="chip"><img src="assets/${f}.svg">${n}</span>`).join('')}</div>` +
    `<div class="play"><div class="btn">${ICONS.play}</div><div class="wave">${Array.from({ length: 34 }, () => '<i></i>').join('')}</div></div>`;
  const typedEl = card.querySelector('.typed'); const caret = card.querySelector('.caret');
  const chipsEl = card.querySelector('.chips'); const chipEls = [...card.querySelectorAll('.chip')];
  const playBtn = card.querySelector('.btn'); const waveBars = [...card.querySelectorAll('.wave i')];
  const stats = el('div', 'stats', ctaRoot,
    `<div class="stat"><div class="n" dir="ltr">+140</div><div class="l">${AR ? 'لهجة عربية' : 'Arabic dialects'}</div></div>` +
    `<div class="stat"><div class="n" dir="ltr">+60</div><div class="l">${AR ? 'لكنة إنجليزية' : 'English accents'}</div></div>`);
  css(stats, { top: L.statsY + 'px' });
  const statN = [...stats.querySelectorAll('.n')]; const statEls = [...stats.querySelectorAll('.stat')];
  const feats = el('div', 'feats', ctaRoot, (AR ? ['مشاعر', 'حوارات', 'بيئات صوت', 'استنساخ صوتك'] : ['Emotions', 'Dialogues', 'Sound environments', 'Voice cloning']).map(f => `<span class="chip">${f}</span>`).join(''));
  css(feats, { top: L.featsY + 'px' });
  const featEls = [...feats.querySelectorAll('.chip')];
  const endLogo = el('img', 'endlogo', ctaRoot); endLogo.src = AR ? 'assets/logo-ar-dark.svg' : 'assets/logo-dark.svg';
  css(endLogo, { height: L.logoH + 'px', top: L.logoY + 'px' });
  const url = el('div', 'url', ctaRoot, 'listenai.studio'); css(url, { top: L.urlY + 'px' }); url.setAttribute('dir', 'ltr');
  const btn = el('div', 'cta-btn', ctaRoot, `${AR ? 'ابدأ الآن' : 'Start now'}<span class="shine"></span>`);
  css(btn, { top: L.btnY + 'px' });
  const shine = btn.querySelector('.shine');
  const faces = el('div', 'faces', ctaRoot, T.tiles.map(a => `<img src="assets/${a}.png">`).join(''));
  css(faces, { top: (L.btnY + (T.ratio === '4x5' ? 150 : 200)) + 'px' });
  const faceEls = [...faces.querySelectorAll('img')];
  const tagline = el('div', 'title', ctaRoot, AR ? 'اكتب… واسمع' : 'Type it. Hear it.');
  tagline.classList.add('grad');
  css(tagline, { top: (L.logoY - 170) + 'px', fontSize: '76px' });

  const wave = el('canvas', 'abs', stage); wave.width = L.W; wave.height = L.waveH * 2;
  css(wave, { left: 0, top: (L.waveY - L.waveH) + 'px', width: L.W + 'px', height: (L.waveH * 2) + 'px' });
  const wctx = wave.getContext('2d');
  function drawWave(t, a, on) {
    const c = wctx; const Wd = L.W; const mid = L.waveH; c.clearRect(0, 0, Wd, mid * 2);
    if (on <= 0.01) return;
    const N = 54; const gap = Wd / N;
    for (let i = 0; i < N; i++) {
      const x = gap * (i + .5);
      const edge = Math.sin((i + .5) / N * Math.PI);
      const n1 = .5 + .5 * Math.sin(i * .77 + t * 7.1) * Math.cos(i * .29 - t * 4.3);
      const hgt = (6 + (a * 0.85 + .08) * mid * .82 * n1) * edge * on;
      const g = c.createLinearGradient(0, mid - hgt, 0, mid + hgt);
      g.addColorStop(0, 'rgba(201,184,255,.0)'); g.addColorStop(.35, 'rgba(160,140,255,.55)');
      g.addColorStop(.5, 'rgba(118,89,250,.9)'); g.addColorStop(.65, 'rgba(76,141,255,.45)'); g.addColorStop(1, 'rgba(76,141,255,0)');
      c.fillStyle = g; c.beginPath(); c.roundRect(x - 4.5, mid - hgt, 9, hgt * 2, 5); c.fill();
    }
  }
  const flash = el('div', 'layer', stage); flash.id = 'flash';
  const grain = el('div', 'layer', stage); grain.id = 'grain';

  // ── Viz ───────────────────────────────────────────────────
  function drawViz(p, t, a, presence) {
    const c = p.ctx; const S = vizSize; c.clearRect(0, 0, S, S);
    if (presence <= 0.01) return;
    const cx = S / 2; const cy = S / 2; const r0 = D / 2 + 34;
    const hue = p.s.hue; const N = 96;
    c.lineCap = 'round';
    for (let k = 0; k < 3; k++) {
      const ph = ((t * 0.9 + k / 3) % 1);
      const r = r0 + ph * (D * 0.42);
      c.beginPath(); c.arc(cx, cy, r, 0, Math.PI * 2);
      c.strokeStyle = `hsla(${hue}, 90%, 72%, ${(1 - ph) * 0.32 * clamp(a * 1.4) * presence})`;
      c.lineWidth = 3; c.stroke();
    }
    for (let i = 0; i < N; i++) {
      const ang = (i / N) * Math.PI * 2 - Math.PI / 2;
      const n1 = Math.sin(i * 1.7 + t * 9.3) * Math.sin(i * 0.37 - t * 5.1);
      const n2 = Math.sin(i * 0.23 + t * 2.1);
      const len = 8 + presence * a * (34 + 70 * (0.5 + 0.5 * n1)) * (0.75 + 0.25 * n2);
      const x1 = cx + Math.cos(ang) * r0; const y1 = cy + Math.sin(ang) * r0;
      const x2 = cx + Math.cos(ang) * (r0 + len); const y2 = cy + Math.sin(ang) * (r0 + len);
      c.strokeStyle = `hsla(${hue + Math.sin(ang) * 30}, 92%, ${66 + 10 * n2}%, ${0.85 * presence})`;
      c.lineWidth = 7; c.beginPath(); c.moveTo(x1, y1); c.lineTo(x2, y2); c.stroke();
    }
  }

  // ── Scene state ───────────────────────────────────────────
  const firstSpk = speakers[0];
  const lastSpk = speakers[speakers.length - 1];
  const hueAt = t => {
    let h = 262;
    for (const s of speakers) { if (t >= s.start - 0.2) h = s.hue; }
    if (t >= reveal.start) h = 262;
    return h;
  };
  let lastHue = null;
  const shakeAt = t => {
    let s = 0;
    const d = t - reveal.impact; if (d > 0 && d < 0.5) s += (1 - d / 0.5) * 22;
    for (const p of spk) if (p.s.stadium) { const a = amp(t); if (t > p.s.vo_start && t < p.s.vo_start + 2.6) s += a * 9; }
    return s;
  };

  function seek(t) {
    // Background: drifting blobs, tinted by the speaker on screen.
    const h = hueAt(t);
    if (lastHue === null) lastHue = h;
    const blobDefs = [
      { x: .18, y: .22, s: 1.15, c: `hsla(${h}, 88%, 58%, .55)` },
      { x: .86, y: .62, s: 1.25, c: 'hsla(258, 90%, 62%, .50)' },
      { x: .30, y: .95, s: 1.1, c: 'hsla(218, 95%, 58%, .42)' },
    ];
    blobs.forEach((b, i) => {
      const d = blobDefs[i]; const size = L.W * d.s;
      const x = d.x * L.W + Math.sin(t * .23 + i * 2) * 90 - size / 2;
      const y = d.y * L.H + Math.cos(t * .19 + i) * 110 - size / 2;
      css(b, { width: size + 'px', height: size + 'px', transform: `translate(${x}px, ${y}px)`, background: `radial-gradient(circle at 50% 50%, ${d.c} 0%, transparent 62%)` });
    });
    grid.style.transform = `translateY(${(t * 14) % 44}px)`;
    bokeh.forEach((b, i) => {
      const y = ((b.y - t * b.sp * b.depth) % 1 + 1) % 1;
      const x = b.x + Math.sin(t * .4 + i) * .02;
      b.el.style.transform = `translate(${x * L.W - b.size / 2}px, ${y * L.H - b.size / 2}px)`;
    });
    const fi = Math.floor(t * T.fps);
    grain.style.backgroundPosition = `${Math.floor(hash(fi) * 512)}px ${Math.floor(hash(fi + 7) * 512)}px`;

    // Stadium beams.
    const stad = spk.find(p => p.s.stadium);
    const beamOn = stad ? clamp(prog(t, stad.s.start, .3) - prog(t, stad.s.end - .1, .3)) : 0;
    beams.style.opacity = beamOn;
    beamEls.forEach((b, i) => { b.style.transform = `rotate(${(i % 2 ? -1 : 1) * (18 + Math.sin(t * 1.3 + i) * 10)}deg)`; });

    // Shake.
    const sh = shakeAt(t);
    stage.style.transform = sh > 0.1 ? `translate(${(hash(fi * 3) - .5) * sh}px, ${(hash(fi * 5) - .5) * sh}px)` : '';

    // Voice waveform along the bottom, whenever someone talks.
    const talking = T.scenes.some(s => t >= s.vo_start - .1 && t <= s.vo_end + .1);
    const waveOn = clamp(prog(t, .2, .6) - prog(t, T.duration - .6, .5));
    const playing = t > T.typing[T.typing.length - 1] + .9 && t < cta.logo_at;
    drawWave(t, talking ? amp(t) : playing ? .55 : 0, waveOn);

    // HUD: during the speakers.
    const hudOn = clamp(prog(t, firstSpk.start - .1, .4) - prog(t, reveal.start - .2, .3));
    hud.style.opacity = hudOn;
    hud.style.transform = `translateY(${(1 - E.out3(hudOn)) * -30}px)`;
    const cur = speakers.filter(s => t >= s.start - .15).length;
    pillCount.textContent = cur ? `${cur}/${speakers.length}` : '';

    // ── Hook: tiles pop in, title types in, then the first tile becomes the first speaker.
    const toFirst = prog(t, hook.end - .5, .5);
    const tf = E.inOut3(toFirst);
    const revealIn = (i) => E.outBack(prog(t, reveal.start + .1 + i * .045, .45), 1.4);
    const revealOut = prog(t, cta.start - .35, .45);
    tiles.forEach((tile, i) => {
      const p = tilePos[i];
      let x = p.x; let y = p.y; let s = 0; let o = 1; let rad = 34; let size = L.tileS;
      const pop = E.outBack(prog(t, T.tile_times[i], .42), 2.2);
      if (t < hook.end) {
        s = pop;
        const bob = Math.sin(t * 2.2 + i) * 5; y += bob;
        if (tile.avatar === firstSpk.avatar) {
          // Shared-element move into the speaker's avatar.
          x = lerp(p.x, L.av.x - D / 2, tf); y = lerp(p.y + bob, L.av.y - D / 2, tf); size = lerp(L.tileS, D, tf); rad = lerp(34, 50, tf);
        } else {
          s = pop * (1 - E.in3(toFirst) * .6); o = 1 - toFirst;
        }
        const talk = (Math.sin(t * 6 + i * 2.3) > .6 ? 1 : 0) * clamp((t - hook.vo_start) * 3) * (1 - toFirst);
        tile.glow.style.opacity = talk * .9 * clamp(amp(t) * 2);
        tile.stamp.style.opacity = 0; tile.dim.style.opacity = 0;
      } else if (t >= reveal.start - .1 && t < cta.start + .2) {
        s = revealIn(i) * (1 - E.in3(revealOut));
        o = 1 - revealOut;
        y += Math.sin(t * 2 + i) * 4;
        const suspense = clamp(prog(t, reveal.start + .4, .4) - prog(t, reveal.impact - .05, .1));
        tile.dim.style.opacity = suspense * .55;
        const st = E.outBack(prog(t, T.reveal_tiles[i], .35), 2.6);
        tile.stamp.style.opacity = clamp(st * 1.5);
        tile.stamp.style.transform = `translate(-50%, -50%) rotate(${-8 + (i % 3) * 5}deg) scale(${lerp(2.2, 1, st)})`;
        tile.glow.style.opacity = st > 0 ? .55 * (1 - prog(t, T.reveal_tiles[i] + .2, .6)) : 0;
        const third = phrases.length > 2 ? phrases[2].words[0].s : reveal.end;
        const shrink = E.inOut3(prog(t, third - .25, .45));
        s *= 1 - shrink * .08; tile.dim.style.opacity = Math.max(suspense * .55, shrink * .6);
      } else { s = 0; o = 0; }
      css(tile.root, { left: x + 'px', top: y + 'px', width: size + 'px', height: size + 'px', borderRadius: rad + '%',
        transform: `scale(${s})`, opacity: o, display: (s > 0.001 && o > 0.001) ? 'block' : 'none' });
      tile.flag.style.opacity = (tile.avatar === firstSpk.avatar && t < hook.end) ? 1 - tf : 1;
    });

    // Hook title.
    hookWords.forEach((w, i) => {
      const wd = hook.words[i]; const a = E.outExpo(prog(t, wd.s - .06, .35));
      const out = E.in3(prog(t, hook.end - .45, .4));
      w.style.opacity = a * (1 - out);
      w.style.transform = `translateY(${(1 - a) * 40 - out * 60}px) scale(${lerp(1.25, 1, a)})`;
      w.style.filter = `blur(${(1 - a) * 12}px)`;
    });
    hookTitle.style.display = t < hook.end + .1 ? 'block' : 'none';

    // ── Speakers.
    spk.forEach((p, k) => {
      const s = p.s;
      const inP = prog(t, s.start - .05, .5);
      const outP = prog(t, s.end - .16, .32);
      if (t < s.start - .1 || t > s.end + .3) { p.root.style.opacity = 0; p.root.style.display = 'none'; return; }
      p.root.style.display = 'block';
      const dir = (k % 2 ? -1 : 1) * (AR ? -1 : 1);
      const ein = E.outExpo(inP); const eout = E.inExpo(outP);
      const fromTile = k === 0;
      const push = 1 + prog(t, s.start, s.end - s.start) * .035;
      const x = fromTile || s.fast ? 0 : (1 - ein) * 260 * dir - eout * 260 * dir;
      const sc = (fromTile ? 1 : s.fast ? lerp(1.35, 1, ein) * lerp(1, .8, eout) : lerp(1.12, 1, ein) * lerp(1, .9, eout)) * push;
      p.root.style.opacity = (fromTile ? 1 : clamp(ein * 1.4)) * (1 - eout);
      p.root.style.transform = `translateX(${x}px) scale(${sc})`;
      p.root.style.filter = (1 - ein + eout) > .02 && !fromTile ? `blur(${(1 - ein) * 14 + eout * 14}px)` : 'none';

      const a = t >= s.vo_start - .05 && t <= s.vo_end + .1 ? amp(t) : amp(t) * .3;
      const pres = clamp(prog(t, s.start + .1, .35));
      drawViz(p, t, a, pres * (1 - eout));
      const ringA = (t * 70 + k * 40) % 360;
      p.ring.style.background = `conic-gradient(from ${ringA}deg, hsl(${s.hue},95%,70%), #7659FA, #4C8DFF, hsl(${s.hue},95%,70%))`;
      p.ring.style.transform = `scale(${1 + a * .035})`;
      p.ring.style.opacity = pres;
      p.glow.style.boxShadow = `0 0 ${50 + a * 70}px ${10 + a * 22}px hsla(${s.hue}, 90%, 60%, ${.35 + a * .3})`;
      p.glow.style.opacity = pres;
      const bob = Math.sin(t * 3.1 + k) * 1.1 * clamp(a);
      p.av.style.transform = `translateY(${-a * 9}px) scale(${1 + a * .03}) rotate(${bob}deg)`;
      const gl = prog(t, s.start + .15, .7);
      p.glint.style.transform = `translateX(${lerp(-120, 120, E.inOut3(gl))}%) skewX(-18deg)`;
      p.glint.style.opacity = gl > 0 && gl < 1 ? .9 : 0;

      const plateIn = E.outBack(prog(t, s.start + .12, .5), 1.6);
      p.plate.style.opacity = clamp(plateIn);
      p.plate.style.transform = `translateY(${(1 - plateIn) * 40}px)`;
      if (p.feature) {
        const f = E.outBack(prog(t, s.vo_start + .25, .45), 2.2);
        p.feature.style.opacity = clamp(f * 1.3);
        p.feature.style.transform = `scale(${f}) translateY(${Math.sin(t * 2.4) * 6}px) rotate(${AR ? 4 : -4}deg)`;
      }
      // Karaoke caption: each word rises in as it is said, the current word lit.
      p.words.forEach((w, i) => {
        const wd = s.words[i];
        const on = E.outExpo(prog(t, wd.s - .05, .28));
        const lit = t >= wd.s - .05 && t <= wd.e + .05;
        const popP = prog(t, wd.s - .05, .3);
        w.style.opacity = .22 + .78 * on;
        w.style.transform = `scale(${1 + Math.sin(popP * Math.PI) * .09})`;
        w.classList.toggle('hl', lit);
      });
      if (p.tr) p.tr.style.opacity = clamp(prog(t, s.vo_start + .2, .4));
    });

    // ── Switch title and flags.
    {
      const s = sw; const vis = t >= s.start - .1 && t <= s.end + .3;
      swTitle.style.display = vis ? 'block' : 'none';
      const inP = E.outBack(prog(t, s.start, .4), 1.8); const outP = E.inExpo(prog(t, s.end - .1, .3));
      swWords.forEach((w, i) => {
        const a = E.outBack(prog(t, s.words[i].s - .1, .35), 2);
        w.style.opacity = clamp(a) * (1 - outP);
        w.style.transform = `scale(${lerp(.4, 1, a) * (1 + outP * .6)})`;
      });
      swFlags.forEach((f, i) => {
        const a = E.outBack(prog(t, s.start + .05 + i * .06, .4), 1.6);
        const ang = (i / swFlags.length) * Math.PI * 2 + t * 1.6;
        const R = 300 * a + outP * 400;
        f.style.display = vis ? 'block' : 'none';
        css(f, { left: (L.av.x - 60 + Math.cos(ang) * R) + 'px', top: (L.av.y + 120 - 60 + Math.sin(ang) * R * .5) + 'px', opacity: clamp(a) * (1 - outP), transform: `scale(${a * (0.85 + 0.15 * Math.sin(ang))})`, zIndex: Math.sin(ang) > 0 ? 3 : 1 });
      });
    }

    // ── Reveal titles.
    revealEls.forEach((n, i) => {
      const ph = phrases[i]; const st = ph.words[0].s; const en = reveal.end;
      const vis = t >= st - .2 && t <= en + .3;
      n.style.display = vis ? 'block' : 'none';
      if (!vis) return;
      let top = L.revealY; let o = 1; let s = 1;
      if (i === 0) {
        const a = E.outExpo(prog(t, st - .1, .4)); const out = prog(t, phrases[1] ? phrases[1].words[0].s - .08 : en, .12);
        o = a * (1 - out); s = lerp(.85, 1, a) * (1 + out * .4);
      } else if (i === 1) {
        const a = prog(t, st - .04, .22); s = lerp(2.2, 1, E.outExpo(a)); o = clamp(a * 2.5);
        const third = phrases[2] ? phrases[2].words[0].s : en + 1;
        const up = E.inOut3(prog(t, third - .25, .45));
        top = L.revealY - up * 0; s *= 1 - up * .12;
      } else {
        const a = E.outBack(prog(t, st - .15, .5), 1.6); o = clamp(a); s = lerp(.8, 1, a); top = L.tilesTop + L.tileS + L.tileGap + L.tileS / 2 - 60;
      }
      const out = E.in3(prog(t, en - .25, .35)); o *= 1 - out;
      css(n, { top: top + 'px', opacity: o, transform: (i === 2 ? 'translateX(-50%) ' : '') + `scale(${s})` });
      n.querySelectorAll('.w').forEach((w, j) => {
        const wd = ph.words[j]; const a = E.outExpo(prog(t, wd.s - .08, .3));
        w.style.opacity = i === 1 ? 1 : .25 + .75 * a;
      });
    });
    const imp = t - reveal.impact;
    flash.style.opacity = imp > 0 && imp < .35 ? (1 - imp / .35) * .75 : 0;

    // ── CTA.
    const vis = t >= cta.start - .3;
    ctaRoot.style.display = vis ? 'block' : 'none';
    if (vis) {
      const t0 = cta.start;
      const cardIn = E.outExpo(prog(t, t0 - .1, .6)); const cardOut = E.inExpo(prog(t, T.stats_at - .25, .35));
      const statsOut = E.inExpo(prog(t, cta.logo_at - .3, .35));
      css(card, { opacity: cardIn * (1 - cardOut), transform: `translateY(${(1 - cardIn) * 120 - cardOut * 80}px) scale(${lerp(.92, 1, cardIn) * lerp(1, .94, cardOut)})` });
      const typeP = prog(t, T.typing[0] - .02, T.typing[T.typing.length - 1] - T.typing[0] + .1);
      const chars = Math.round(typed.length * typeP);
      typedEl.textContent = typed.slice(0, chars);
      caret.style.opacity = (Math.floor(t * 3) % 2 === 0 || typeP < 1) ? 1 : 0;
      const pick = Math.floor(clamp(prog(t, T.typing[T.typing.length - 1], .9)) * 4.99);
      chipEls.forEach((c, i) => c.classList.toggle('on', typeP >= 1 && i === pick));
      chipsEl.style.transform = `translateX(${(AR ? 1 : -1) * E.inOut3(prog(t, t0 + .4, 2.2)) * 360}px)`;
      const press = prog(t, T.typing[T.typing.length - 1] + .8, .25);
      playBtn.style.transform = `scale(${1 - Math.sin(press * Math.PI) * .12})`;
      const playing = prog(t, T.typing[T.typing.length - 1] + .9, .2);
      waveBars.forEach((b, i) => {
        const v = playing * (0.25 + 0.75 * Math.abs(Math.sin(i * .9 + t * 8) * Math.sin(i * .31 - t * 3.3)));
        b.style.height = (14 + v * 92) + 'px';
      });
      // Stats count up.
      statEls.forEach((s, i) => {
        const a = E.outBack(prog(t, T.stats_at + i * .25, .5), 1.7);
        s.style.opacity = clamp(a) * (1 - statsOut);
        s.style.transform = `translateY(${(1 - a) * 60 - statsOut * 40}px) scale(${a})`;
        const target = i === 0 ? 140 : 60;
        statN[i].textContent = '+' + Math.round(target * E.out3(prog(t, T.stats_at + i * .25, .9)));
      });
      featEls.forEach((f, i) => {
        const a = E.outBack(prog(t, T.stats_at + .5 + i * .09, .4), 2);
        f.style.opacity = clamp(a) * (1 - statsOut);
        f.style.transform = `scale(${a})`;
      });
      // End card.
      const lg = E.outBack(prog(t, cta.logo_at, .6), 1.5);
      css(endLogo, { opacity: clamp(lg * 1.3), transform: `translateX(-50%) scale(${lerp(.6, 1, lg)})`, filter: `drop-shadow(0 0 ${40 * lg}px rgba(118,89,250,.6))` });
      const tg = E.outExpo(prog(t, cta.logo_at + .25, .5));
      css(tagline, { opacity: tg, transform: `translateY(${(1 - tg) * 30}px)` });
      const u = E.outExpo(prog(t, cta.logo_at + .4, .5));
      css(url, { opacity: u, transform: `translateY(${(1 - u) * 30}px)` });
      const b = E.outBack(prog(t, cta.logo_at + .55, .5), 1.8);
      css(btn, { opacity: clamp(b * 1.4), transform: `translateX(-50%) scale(${b * (1 + Math.sin(Math.max(0, t - cta.logo_at - 1.2) * 4) * .025)})` });
      faceEls.forEach((f, i) => {
        const a = E.outBack(prog(t, cta.logo_at + .7 + i * .04, .4), 2);
        f.style.opacity = clamp(a * 1.4); f.style.transform = `translateY(${(1 - a) * 30}px) scale(${a})`;
      });
      const sp = ((t - cta.logo_at - 1.1) % 1.6) / 1.6;
      shine.style.left = (t > cta.logo_at + 1.1 ? lerp(-40, 140, sp) : -40) + '%';
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
