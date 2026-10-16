/* Listen launch ad, light studio design. Every frame is a pure function of
 * time: window.seek(t) sets the whole stage to its state at t seconds. */
(() => {
  const T = window.TIMELINE;
  const AR = T.ui === 'ar';
  const LAYOUTS = {
    '9x16': { W: 1080, H: 1920, brandY: 150, pr: { x: 540, y: 600, d: 400 }, cardY: 745, cardX: 60, cardW: 960, wmY: 1330, wmS: 520,
      hookY: 300, tilesTop: 790, tileS: 196, tileGap: 40, switchY: 560, revealY: 330, bannerY: 1000,
      envTitleY: 330, envCardY: 560, introY: 520, introIconsY: 980,
      studioY: 560, tagY: 420, logoY: 540, logoH: 190, statsY: 820, urlY: 1080, btnY: 1170, facesY: 1345 },
    '4x5': { W: 1080, H: 1350, brandY: 56, pr: { x: 540, y: 300, d: 250 }, cardY: 380, cardX: 80, cardW: 920, wmY: 1010, wmS: 300,
      hookY: 120, tilesTop: 500, tileS: 160, tileGap: 30, switchY: 380, revealY: 120, bannerY: 700,
      envTitleY: 120, envCardY: 290, introY: 330, introIconsY: 700,
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
    outExpo: x => (x >= 1 ? 1 : 1 - Math.pow(2, -10 * x)),
    in3: x => x * x * x,
    inOut3: x => (x < .5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2),
    outBack: (x, s = 1.4) => 1 + (s + 1) * Math.pow(x - 1, 3) + s * Math.pow(x - 1, 2),
  };
  const amp = t => { const f = t * T.fps; const i = Math.floor(f); return lerp(T.amp[i] || 0, T.amp[i + 1] || 0, f - i); };
  const hash = i => { const x = Math.sin(i * 127.1 + 311.7) * 43758.5453; return x - Math.floor(x); };
  const el = (tag, cls, parent, html) => { const n = document.createElement(tag); if (cls) n.className = cls; if (html != null) n.innerHTML = html; (parent || stage).appendChild(n); return n; };
  const css = (n, o) => { for (const k in o) n.style[k] = o[k]; };
  const isAr = s => /[؀-ۿ]/.test(s);
  const scenes = T.scenes;
  const sceneOf = k => scenes.find(s => s.kind === k);
  const LOGO = AR ? 'assets/logo-ar-light.svg' : 'assets/logo-light.svg';
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
  const PLAY = '<svg viewBox="0 0 24 24" fill="#fff"><path d="M7 4.5v15l13-7.5z"/></svg>';
  const PAUSE = '<svg viewBox="0 0 24 24" fill="#fff"><rect x="5.5" y="4.5" width="4.5" height="15" rx="1.4"/><rect x="14" y="4.5" width="4.5" height="15" rx="1.4"/></svg>';
  const fmt = s => `0:${String(Math.max(0, Math.floor(s))).padStart(2, '0')}`;

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
    return { root: t, badge: t.querySelector('.badge'), flag: t.querySelector('.flag'), avatar };
  });

  // ── Titles ─────────────────────────────────────────────────
  const wordsHtml = (ws, accentFrom = Infinity) => ws.map((w, i) => `<span class="w${i >= accentFrom ? ' accent' : ''}">${w.w} </span>`).join('');
  const hook = sceneOf('hook');
  const hookTitle = el('div', 'title display' + (isAr(hook.words.map(w => w.w).join('')) ? ' ar' : ''), stage, wordsHtml(hook.words, hook.words.length - 1));
  css(hookTitle, { top: L.hookY + 'px', fontSize: (T.ratio === '4x5' ? 84 : 104) + 'px' });
  const hookWords = [...hookTitle.querySelectorAll('.w')];

  const sw = sceneOf('switch') || { start: -9, end: -9, words: [] };
  const swTitle = el('div', 'title display' + (isAr(sw.words.map(w => w.w).join('')) ? ' ar' : ''), stage, wordsHtml(sw.words, sw.words.length - 1));
  css(swTitle, { top: L.switchY + 'px', fontSize: (T.ratio === '4x5' ? 110 : 128) + 'px' });
  const swWords = [...swTitle.querySelectorAll('.w')];
  const swFlags = (() => { const i = scenes.indexOf(sw); if (i < 0) return []; return scenes.slice(i + 1).filter(s => s.kind === 'speaker').map(s => s.flag).filter((f, k, a) => a.indexOf(f) === k); })()
    .map(f => el('div', 'flagchip', stage, `<img src="assets/${f}.svg">`));

  const reveal = sceneOf('reveal');
  const phrases = [];
  reveal.words.forEach(w => { if (!phrases.length || /[؟?!.]$/.test(phrases[phrases.length - 1].text.trim())) phrases.push({ words: [], text: '' }); const p = phrases[phrases.length - 1]; p.words.push(w); p.text += w.w + ' '; });
  const revealEls = phrases.map((p, i) => {
    if (i === 2) {
      const n = el('div', 'banner' + (isAr(p.text) ? ' ar' : ''), stage, `<img src="assets/icon-128.svg"><span>${p.text.trim()}</span>`);
      return n;
    }
    const n = el('div', 'title display' + (isAr(p.text) ? ' ar' : ''), stage, wordsHtml(p.words, i === 1 ? 0 : Infinity));
    css(n, { fontSize: (i === 1 ? (T.ratio === '4x5' ? 112 : 132) : (T.ratio === '4x5' ? 84 : 100)) + 'px' });
    return n;
  });

  const intro = sceneOf('envintro');
  let introTitle = null; let introWords = []; let introIcons = [];
  if (intro) {
    const split = intro.words.findIndex(w => /^(في|anywhere)/.test(w.w));
    introTitle = el('div', 'title display' + (isAr(intro.words.map(w => w.w).join('')) ? ' ar' : ''), stage, wordsHtml(intro.words, split < 0 ? Infinity : split));
    css(introTitle, { top: L.introY + 'px', fontSize: (T.ratio === '4x5' ? 80 : 96) + 'px' });
    introWords = [...introTitle.querySelectorAll('.w')];
    introIcons = ['announcement', 'phone', 'radio', 'hall', 'cave', 'church'].map(k => el('div', 'envchip', stage, icon(k)));
  }

  // ── Player cards ───────────────────────────────────────────
  const players = scenes.filter(s => s.kind === 'speaker' || s.kind === 'env').map((s, idx) => {
    const root = el('div', 'spk' + (AR ? ' ar' : ''), stage);
    const isEnv = s.kind === 'env';
    let halo = null; let portrait = null;
    if (!isEnv) {
      const D = L.pr.d;
      halo = el('div', 'halo', root);
      css(halo, { left: (L.pr.x - D) + 'px', top: (L.pr.y - D) + 'px', width: D * 2 + 'px', height: D * 2 + 'px',
        background: `radial-gradient(circle, hsla(${s.hue}, 90%, 78%, .75) 0%, hsla(${s.hue}, 90%, 82%, .35) 38%, transparent 68%)` });
      portrait = el('div', 'portrait', root, `<img src="assets/${s.avatar}.png">`);
      css(portrait, { left: (L.pr.x - D / 2) + 'px', top: (L.pr.y - D / 2) + 'px', width: D + 'px', height: D + 'px' });
    }
    let wm = null;
    if (isEnv) {
      wm = el('div', 'envwm', root, icon(s.icon));
      css(wm, { left: (L.W / 2 - L.wmS / 2) + 'px', top: L.wmY + 'px', width: L.wmS + 'px', height: L.wmS + 'px' });
    }
    const card = el('div', 'card', root);
    const top = isEnv ? L.envCardY : L.cardY;
    css(card, { left: L.cardX + 'px', top: top + 'px', width: L.cardW + 'px', padding: isEnv ? '48px 52px 46px' : `${L.pr.d / 2 - (L.cardY - L.pr.y) + 56}px 56px 46px` });
    const dir = s.lang === 'ar' ? 'rtl' : 'ltr';
    const tokens = (s.tokens || s.words.map((w, i) => ({ word: i })));
    const script = tokens.map(tk => tk.tag ? `<span class="tag">${TAG_ICONS[tk.key] || TAG_ICONS.laugh}${tk.tag}</span>` : `<span class="w">${s.words[tk.word].w} </span>`).join('');
    const bars = (s.bars || []).map(v => `<i style="height:${Math.round(14 + v * 78)}px"></i>`).join('');
    let head;
    if (isEnv) {
      head = `<div class="envhead"><div class="envicon">${icon(s.icon)}</div><div class="envid"><div class="place">${s.place}</div><div class="scene">${s.scene_label}</div></div></div>` +
        `<div class="seg"><div class="knob"></div><span class="c">${AR ? 'صوت نقي' : 'Clean'}</span><span class="p">${AR ? 'في المكان' : 'In the place'}</span></div>`;
    } else {
      head = `<div class="head"><div class="who"><img src="assets/${s.flag}.svg"><div class="name">${s.name}</div></div>` +
        `<div class="chips-row"><div class="chip">${s.dialect}</div>${s.emotion ? `<div class="chip emo"><i></i>${s.emotion}</div>` : ''}</div></div>`;
    }
    card.innerHTML = head + `<div class="script ${s.lang === 'ar' ? 'ar' : ''}" dir="${dir}">${script}</div>` +
      (s.tr ? `<div class="tr ${AR ? 'ar' : ''}" dir="${AR ? 'rtl' : 'ltr'}">${s.tr}</div>` : '') +
      `<div class="player"><div class="btn">${PAUSE}</div><div class="bars">${bars}</div><div class="time">0:00</div></div>`;
    const seg = card.querySelector('.seg');
    return { s, idx, root, halo, portrait, card, isEnv, wm, words: [...card.querySelectorAll('.script .w')], bars: [...card.querySelectorAll('.bars i')],
      time: card.querySelector('.time'), seg, knob: seg && seg.querySelector('.knob'), segC: seg && seg.querySelector('.c'), segP: seg && seg.querySelector('.p') };
  });
  const firstEnv = scenes.find(s => s.kind === 'env');
  const lastEnv = [...scenes].reverse().find(s => s.kind === 'env');
  let envTitle = null;
  if (firstEnv) {
    envTitle = el('div', 'title display' + (AR ? ' ar' : ''), stage, AR ? 'ضع صوتك في <span class="accent">أي مكان</span>' : 'Put your voice <span class="accent">anywhere</span>');
    css(envTitle, { top: L.envTitleY + 'px', fontSize: (T.ratio === '4x5' ? 68 : 82) + 'px' });
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
    `<div class="player"><div class="btn">${PLAY}</div><div class="bars">${Array.from({ length: 40 }, (_, i) => `<i style="height:${Math.round(14 + 70 * (.3 + .7 * Math.abs(Math.sin(i * .7) * Math.sin(i * .23 + 1))))}px"></i>`).join('')}</div><div class="time">0:04</div></div>`;
  const typedEl = studio.querySelector('.typed'); const caret = studio.querySelector('.caret');
  const chipEls = [...studio.querySelectorAll('.chip')]; const studioBtn = studio.querySelector('.btn'); const studioBars = [...studio.querySelectorAll('.bars i')];
  const tagline = el('div', 'title display' + (AR ? ' ar' : ''), ctaRoot, AR ? 'اكتب… <span class="accent">واسمع</span>' : 'Type it. <span class="accent">Hear it.</span>');
  css(tagline, { top: L.tagY + 'px', fontSize: (T.ratio === '4x5' ? 64 : 76) + 'px' });
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
  const tintAt = t => {
    let h = 255;
    for (const s of scenes) if ((s.kind === 'speaker' || s.kind === 'env') && t >= s.start - .2) h = s.hue;
    if (t >= reveal.start && (!firstEnv || t < firstEnv.start - .2)) h = 255;
    if (t >= cta.start - .2) h = 255;
    return h;
  };

  function seek(t) {
    const fi = Math.round(t * T.fps);
    // Background light.
    const h = tintAt(t);
    orbs.forEach((o, i) => {
      const d = ORBS[i]; const size = L.W * d.s * 1.25;
      const x = d.x * L.W + Math.sin(t * .17 + i * 1.9) * 70 - size / 2;
      const y = d.y * L.H + Math.cos(t * .13 + i * 1.3) * 90 - size / 2;
      const c = i === 1 ? `hsla(${h}, 95%, 85%, .95)` : `rgba(${d.c.join(',')}, .9)`;
      css(o, { width: size + 'px', height: size + 'px', transform: `translate(${x}px, ${y}px)`, background: `radial-gradient(circle at 50% 50%, ${c} 0%, transparent 60%)` });
    });
    grain.style.backgroundPosition = `${Math.floor(hash(fi) * 512)}px ${Math.floor(hash(fi + 7) * 512)}px`;
    brand.style.opacity = 1 - prog(t, cta.logo_at - .5, .4);

    // Hook and reveal tiles.
    const toFirst = E.inOut3(prog(t, hook.end - .55, .55));
    const revealOut = prog(t, reveal.end - .35, .4);
    const third = phrases[2] ? phrases[2].words[0].s : reveal.end;
    tiles.forEach((tile, i) => {
      const p = tilePos[i];
      let x = p.x; let y = p.y; let s = 0; let o = 1; let size = L.tileS; let rad = 30;
      if (t < hook.end) {
        const pop = E.outBack(prog(t, T.tile_times[i], .55), 1.3);
        y += Math.sin(t * 1.6 + i * .9) * 6 + (1 - E.out3(prog(t, T.tile_times[i], .6))) * 50;
        s = pop;
        if (tile.avatar === firstSpk.avatar) {
          x = lerp(p.x, L.pr.x - L.pr.d / 2, toFirst); y = lerp(y, L.pr.y - L.pr.d / 2, toFirst); size = lerp(L.tileS, L.pr.d, toFirst); rad = lerp(30, 50, toFirst);
        } else { s *= 1 - toFirst * .25; o = 1 - toFirst; }
        tile.badge.style.opacity = 0;
      } else if (t >= reveal.start - .1 && t < reveal.end + .1) {
        s = E.outBack(prog(t, reveal.start + .05 + i * .04, .5), 1.3) * (1 - revealOut * .2);
        o = 1 - revealOut;
        y += Math.sin(t * 1.6 + i * .9) * 5;
        const bd = E.outBack(prog(t, T.reveal_tiles[i], .4), 1.8);
        tile.badge.style.opacity = clamp(bd * 1.4);
        tile.badge.style.transform = `translateX(-50%) scale(${bd})`;
        const dim = E.inOut3(prog(t, third - .25, .45));
        o *= 1 - dim * .55; s *= 1 - dim * .05;
      } else { o = 0; }
      css(tile.root, { left: x + 'px', top: y + 'px', width: size + 'px', height: size + 'px', transform: `scale(${s})`, opacity: o, display: s > .001 && o > .001 ? 'block' : 'none' });
      tile.root.querySelector('.face').style.borderRadius = rad + '%';
      tile.flag.style.opacity = tile.avatar === firstSpk.avatar && t < hook.end ? 1 - toFirst : 1;
    });

    hookWords.forEach((w, i) => {
      const a = E.out5(prog(t, hook.words[i].s - .08, .45)); const out = E.out3(prog(t, hook.end - .8, .35));
      w.style.opacity = a * (1 - out); w.style.transform = `translateY(${(1 - a) * 34 - out * 40}px)`;
    });
    hookTitle.style.display = t < hook.end + .1 ? 'block' : 'none';

    // Switch.
    {
      const vis = t >= sw.start - .1 && t <= sw.end + .3; swTitle.style.display = vis ? 'block' : 'none';
      const out = E.in3(prog(t, sw.end - .15, .3));
      swWords.forEach((w, i) => { const a = E.out5(prog(t, sw.words[i].s - .12, .4)); w.style.opacity = a * (1 - out); w.style.transform = `translateY(${(1 - a) * 40 - out * 30}px)`; });
      const n = swFlags.length; const gap = 136; const x0 = (L.W - (n * 112 + (n - 1) * (gap - 112))) / 2;
      swFlags.forEach((f, i) => {
        const a = E.outBack(prog(t, sw.start + .1 + i * .05, .45), 1.6);
        css(f, { display: vis ? 'block' : 'none', left: (x0 + i * gap) + 'px', top: (L.switchY + (T.ratio === '4x5' ? 230 : 300) + Math.sin(t * 2 + i) * 6) + 'px', opacity: clamp(a) * (1 - out), transform: `scale(${a})` });
      });
    }

    // Reveal titles and banner.
    revealEls.forEach((n, i) => {
      const ph = phrases[i]; const st = ph.words[0].s; const vis = t >= st - .25 && t <= reveal.end + .3;
      n.style.display = vis ? (i === 2 ? 'flex' : 'block') : 'none';
      if (!vis) return;
      const out = E.in3(prog(t, reveal.end - .3, .35));
      if (i === 0) {
        const a = E.out5(prog(t, st - .15, .45)); const next = phrases[1] ? phrases[1].words[0].s : reveal.end;
        const gone = prog(t, next - .1, .15);
        css(n, { top: L.revealY + 'px', opacity: a * (1 - gone), transform: `translateY(${(1 - a) * 30}px)` });
      } else if (i === 1) {
        const a = E.out5(prog(t, st - .06, .35));
        css(n, { top: L.revealY + 'px', opacity: a * (1 - out), transform: `scale(${lerp(1.18, 1, a)})` });
      } else {
        const a = E.outBack(prog(t, st - .15, .55), 1.3);
        css(n, { top: L.bannerY + 'px', opacity: clamp(a * 1.3) * (1 - out), transform: `translateX(-50%) scale(${lerp(.85, 1, a)})` });
      }
    });

    // Environment intro.
    if (intro) {
      const vis = t >= intro.start - .1 && t <= intro.end + .3;
      introTitle.style.display = vis ? 'block' : 'none';
      const out = E.in3(prog(t, intro.end - .2, .35));
      introWords.forEach((w, i) => { const a = E.out5(prog(t, intro.words[i].s - .1, .4)); w.style.opacity = a * (1 - out); w.style.transform = `translateY(${(1 - a) * 34 - out * 30}px)`; });
      const n = introIcons.length; const gap = 160; const x0 = (L.W - ((n - 1) * gap + 136)) / 2;
      introIcons.forEach((c, i) => {
        const a = E.outBack(prog(t, intro.start + .2 + i * .07, .5), 1.6);
        css(c, { display: vis ? 'grid' : 'none', left: (x0 + i * gap) + 'px', top: (L.introIconsY + Math.sin(t * 2.2 + i * 1.3) * 10 + (i % 2) * 40) + 'px', opacity: clamp(a) * (1 - out), transform: `scale(${a}) rotate(${(i % 2 ? 4 : -4)}deg)` });
      });
    }
    if (envTitle) {
      const a = E.out5(prog(t, firstEnv.start, .5)); const out = E.in3(prog(t, lastEnv.end - .2, .35));
      envTitle.style.display = t >= firstEnv.start - .1 && t <= lastEnv.end + .3 ? 'block' : 'none';
      css(envTitle, { opacity: a * (1 - out), transform: `translateY(${(1 - a) * 24}px)` });
    }

    // Player cards.
    players.forEach((p, k) => {
      const s = p.s;
      if (t < s.start - .15 || t > s.end + .4) { p.root.style.display = 'none'; return; }
      p.root.style.display = 'block';
      const dIn = s.fast ? .38 : .55;
      const inP = E.out5(prog(t, s.start - .08, dIn));
      const outP = E.in3(prog(t, s.end - .14, .34));
      const fromTile = s === firstSpk;
      p.root.style.opacity = 1 - outP;
      p.card.style.transform = `translateY(${(1 - inP) * 200 - outP * 120}px) scale(${lerp(.96, 1, inP) * lerp(1, .95, outP)})`;
      p.card.style.opacity = clamp(inP * 1.3);
      const a = t >= s.vo_start - .05 && t <= s.vo_end + .1 ? amp(t) : 0;
      if (p.portrait) {
        const pin = fromTile ? 1 : E.outBack(prog(t, s.start - .05, dIn + .1), 1.25);
        p.portrait.style.opacity = fromTile ? (t >= hook.end - .02 ? 1 : 0) : clamp(pin * 1.3);
        p.portrait.style.transform = `translateY(${-a * 5 - outP * 60}px) scale(${(fromTile ? 1 : lerp(.8, 1, pin)) * (1 + a * .018)})`;
        p.halo.style.opacity = clamp(prog(t, s.start, .5)) * (1 - outP);
        p.halo.style.transform = `scale(${1 + a * .07 + Math.sin(t * 1.4) * .02})`;
      }
      // Karaoke: said words in ink, the current word in brand, upcoming words light.
      p.words.forEach((w, i) => {
        const wd = s.words[i];
        w.classList.toggle('now', t >= wd.s - .04 && t <= wd.e + .04);
        w.classList.toggle('said', t > wd.e + .04);
      });
      // Player: progress through the clip's own waveform.
      const pr = prog(t, s.vo_start, Math.max(.1, s.vo_end - s.vo_start));
      const on = Math.floor(pr * p.bars.length);
      p.bars.forEach((b, i) => b.classList.toggle('on', i < on));
      p.time.textContent = fmt(pr * (s.vo_end - s.vo_start));
      if (p.wm) {
        const placedNow = s.ab_at == null || t >= s.ab_at;
        const w = E.out5(prog(t, s.ab_at == null ? s.start + .1 : s.ab_at - .1, .6));
        p.wm.style.opacity = (placedNow ? w : 0) * (1 - outP);
        p.wm.style.transform = `translateY(${(1 - w) * 40 + Math.sin(t * 1.3) * 8}px) scale(${lerp(.9, 1, w) * (1 + a * .03)})`;
      }
      if (p.seg) {
        const placed = s.ab_at == null ? 1 : E.inOut3(prog(t, s.ab_at - .12, .3));
        const half = (L.cardW - 104 - 16) / 2;
        const right = AR ? 0 : 1; // the knob sits on the active option
        const pos = AR ? lerp(half, 0, placed) : lerp(0, half, placed);
        css(p.knob, { left: (8 + pos) + 'px', width: half + 'px' });
        p.segC.classList.toggle('on', placed < .5); p.segP.classList.toggle('on', placed >= .5);
      }
    });

    // CTA: the studio types, picks a dialect and plays; then the end card.
    const cvis = t >= cta.start - .3; ctaRoot.style.display = cvis ? 'block' : 'none';
    if (cvis) {
      const t0 = cta.start;
      const sin = E.out5(prog(t, t0 - .1, .6)); const sout = E.in3(prog(t, cta.logo_at - .4, .35));
      css(studio, { opacity: sin * (1 - sout), transform: `translateY(${(1 - sin) * 160 - sout * 80}px) scale(${lerp(.96, 1, sin) * lerp(1, .95, sout)})` });
      const t1 = T.typing[0]; const t2 = T.typing[T.typing.length - 1];
      const typeP = prog(t, t1 - .02, t2 - t1 + .1);
      typedEl.textContent = typed.slice(0, Math.round(typed.length * typeP));
      caret.style.opacity = typeP < 1 || Math.floor(t * 3) % 2 === 0 ? 1 : 0;
      const pick = Math.min(4, Math.floor(prog(t, t2 - .25, .8) * 5));
      chipEls.forEach((c, i) => c.classList.toggle('on', typeP > .6 && i === pick));
      const playing = t > t2 + .3;
      studioBtn.innerHTML = playing ? PAUSE : PLAY;
      const sp = prog(t, t2 + .3, Math.max(.3, cta.logo_at - t2 - .6));
      studioBars.forEach((b, i) => b.classList.toggle('on', i < Math.floor(sp * studioBars.length)));

      const lg = E.out5(prog(t, cta.logo_at, .6));
      css(endLogo, { opacity: lg, transform: `translateX(-50%) translateY(${(1 - lg) * 30}px) scale(${lerp(.9, 1, lg)})` });
      const tg = E.out5(prog(t, cta.logo_at + .2, .5)); css(tagline, { opacity: tg, transform: `translateY(${(1 - tg) * 24}px)` });
      statEls.forEach((s, i) => {
        const a = E.out5(prog(t, T.stats_at + i * .15, .55));
        s.style.opacity = a; s.style.transform = `translateY(${(1 - a) * 40}px)`;
        statN[i].textContent = '+' + Math.round((i === 0 ? 140 : 60) * E.out3(prog(t, T.stats_at + i * .15, .9)));
      });
      const u = E.out5(prog(t, T.stats_at + .5, .5)); css(url, { opacity: u, transform: `translateY(${(1 - u) * 20}px)` });
      const b = E.outBack(prog(t, T.stats_at + .65, .55), 1.4); css(btn, { opacity: clamp(b * 1.3), transform: `translateX(-50%) scale(${b})` });
      faceEls.forEach((f, i) => { const a = E.outBack(prog(t, T.stats_at + .85 + i * .035, .45), 1.6); f.style.opacity = clamp(a * 1.3); f.style.transform = `translateY(${(1 - a) * 24}px) scale(${a})`; });
      const sh = ((t - T.stats_at - 1.4) % 1.8) / 1.8;
      shine.style.left = (t > T.stats_at + 1.4 ? lerp(-40, 140, sh) : -40) + '%';
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
