// The homepage's reach globe (components/home/reach). Land is fine dots like a night map; every country with members
// lights the land around it like cities seen from space (brighter and wider where there are more members); faint
// network arcs and travelling trails link the Arab world to members far away; a glassy rim and an atmosphere. The
// sphere is deep violet on a dark page and a lighter brand violet on a light one; what sits around it (trails, the
// atmosphere) is pale light on dark and brand violet on light. Canvas 2D, no dependencies.
//
// The section's entrance is started by the small script beside it (it adds .reach-on as the section comes into
// view, and the text rises in with CSS); this script plays the globe's part of the same moment: the lights spread
// from the Arab world outward and the numbers climb with them. Then the globe turns slowly, trails come and go, and
// it can be dragged. It draws only while it can be seen; with reduced motion it stays still and lit.

const RAD = Math.PI / 180;
const TAU = Math.PI * 2;
const ARAB = new Set(['EG', 'SA', 'DZ', 'YE', 'OM', 'JO', 'IQ', 'MA', 'PS', 'LY', 'SY', 'TN', 'SD', 'KW', 'AE', 'LB', 'BH', 'QA', 'MR', 'SO', 'DJ', 'KM']);
const clamp = (v) => Math.max(0, Math.min(1, v));
const mix = (a, b, k) => a.map((v, i) => Math.round(v + (b[i] - v) * k));
const easeOutQuart = (t) => 1 - Math.pow(1 - clamp(t), 4);
const easeOutCubic = (t) => 1 - Math.pow(1 - clamp(t), 3);
const easeInOut = (t) => -(Math.cos(Math.PI * clamp(t)) - 1) / 2;
const unit = (lat, lon) => { const a = lat * RAD, b = lon * RAD; return [Math.cos(a) * Math.sin(b), Math.sin(a), Math.cos(a) * Math.cos(b)]; };
function seeded(a) { return () => { a |= 0; a = (a + 0x6d2b79f5) | 0; let t = Math.imul(a ^ (a >>> 15), 1 | a); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; }

// The sphere, per page theme. On a white page it is a lighter brand violet, so it reads as part of the page.
const SPHERE = {
  dark: { body: ['#3b2b9c', '#1b125a', '#0b0826'], rimInner: 'rgba(201,184,255,0.55)', rimLine: 'rgba(214,202,255,0.75)',
    dot: [146, 126, 252], dotAlpha: [0.06, 0.64], city: [246, 242, 255] },
  light: { body: ['#7f69f6', '#4b35c9', '#271a7e'], rimInner: 'rgba(225,215,255,0.6)', rimLine: 'rgba(235,228,255,0.85)',
    dot: [214, 204, 255], dotAlpha: [0.1, 0.74], city: [255, 255, 255] },
};
// Around the sphere, per page theme: pale light on dark, brand violet on white (white light would vanish there).
const OUTER = {
  dark: { trail: [228, 220, 255], line: [196, 182, 255], atmosphere: 0.42, glow: ['rgba(255,255,255,1)', 'rgba(214,202,255,0.75)', 'rgba(118,89,250,0.28)'] },
  light: { trail: [112, 80, 250], line: [150, 128, 255], atmosphere: 0.24, glow: ['rgba(98,66,245,0.95)', 'rgba(118,89,250,0.5)', 'rgba(118,89,250,0.12)'] },
};
// The lights on the sphere itself are the same in both themes.
const LIGHT = { glow: ['rgba(255,255,255,1)', 'rgba(214,202,255,0.75)', 'rgba(118,89,250,0.28)'], spot: { 1: 0.45, 2: 0.7, 3: 1 } };

function glowSprite(stops) {
  const c = document.createElement('canvas'); c.width = c.height = 64;
  const g = c.getContext('2d'); const r = g.createRadialGradient(32, 32, 0, 32, 32, 32);
  r.addColorStop(0, stops[0]); r.addColorStop(0.16, stops[1]); r.addColorStop(0.45, stops[2]); r.addColorStop(1, 'rgba(0,0,0,0)');
  g.fillStyle = r; g.fillRect(0, 0, 64, 64);
  return c;
}

export function mountReach(section) {
  const wrap = section.querySelector('.reach-globe');
  const canvas = wrap && wrap.querySelector('canvas');
  if (!canvas || !canvas.getContext) { section.classList.add('reach-flat'); return; }
  const ctx = canvas.getContext('2d');
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const markers = JSON.parse(wrap.dataset.markers || '[]').map((m) => ({ ...m, v: unit(m.at[0], m.at[1]) }));
  const counters = [...section.querySelectorAll('[data-count]')];
  const fmt = (n, el) => { const s = Math.round(n).toLocaleString('en-US'); return el.dataset.plus === 'before' ? '+' + s : el.dataset.plus === 'after' ? s + '+' : s; };
  const lastDelay = Math.max(0, ...markers.map((m) => m.delay));
  const sprite = glowSprite(LIGHT.glow);
  const themeName = () => (document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark');
  let SP = SPHERE[themeName()], O = OUTER[themeName()], outer = glowSprite(O.glow);
  new MutationObserver(() => { SP = SPHERE[themeName()]; O = OUTER[themeName()]; outer = glowSprite(O.glow); })
    .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  // The view: which longitude faces us (it turns), and a fixed tilt that shows a little more of the north.
  let lon0 = 30, lat0 = 18, spin = 0, turn = 0, drag = null, last = 0, frameId = 0, shownAt = null, dpr = 1, W = 0, H = 0;
  let land = null, landLit = null, landOwner = null, landGrain = null;
  // Degrees a second, westward, so the Americas come round as the trails cross to them; about once a minute. The
  // turn eases in from standstill rather than starting at full speed.
  const TURN = reduce ? 0 : -5.5;
  if (!reduce && !section.classList.contains('reach-on')) counters.forEach((el) => { el.textContent = fmt(0, el); });

  const beat = markers.map((m, i) => (m.tier >= 2 ? { phase: (i * 0.37) % 1, period: 2400 + (i % 5) * 320 } : null));
  const hubs = markers.filter((m) => ARAB.has(m.code) && m.tier >= 2);
  const hubMid = unit(26, 38);
  const far = (m) => Math.acos(Math.max(-1, Math.min(1, m.v[0] * hubMid[0] + m.v[1] * hubMid[1] + m.v[2] * hubMid[2])));
  const targets = markers.filter((m) => !ARAB.has(m.code) && far(m) > 0.55).sort((a, b) => b.tier - a.tier || far(b) - far(a)).slice(0, 16);
  // A few lasting links between the Arab world and members far away, faint, under the moving trails.
  const links = [];
  if (hubs.length && targets.length) {
    const r = seeded(5);
    for (let i = 0; i < Math.min(9, targets.length); i++) {
      const from = hubs[Math.floor(r() * hubs.length)].v, to = targets[i].v;
      const ang = Math.acos(Math.max(-1, Math.min(1, from[0] * to[0] + from[1] * to[1] + from[2] * to[2])));
      links.push({ from, to, lift: 0.05 + 0.2 * (ang / Math.PI) });
    }
  }
  const trails = [];
  const rnd = seeded(11);
  let nextTrail = 0;

  // Each land dot near a member country takes that country's light: strongest at its spot, fading with distance,
  // wider for countries with more members, with a little grain so it reads as many small lights. Worked out once.
  const lightLand = () => {
    const n = land.length / 3, reach = { 1: 3, 2: 4.6, 3: 6.6 }, weight = { 1: 0.62, 2: 0.84, 3: 1 };
    landLit = new Float32Array(n); landOwner = new Int16Array(n).fill(-1); landGrain = new Float32Array(n);
    const r = seeded(3);
    for (let i = 0; i < n; i++) {
      landGrain[i] = 0.45 + 0.55 * r();
      const x = land[i * 3], y = land[i * 3 + 1], z = land[i * 3 + 2];
      let best = 0, who = -1;
      for (let k = 0; k < markers.length; k++) {
        const m = markers[k], d = x * m.v[0] + y * m.v[1] + z * m.v[2];
        if (d < 0.985) continue; // further than about 10 degrees
        const s = reach[m.tier] * RAD, a2 = 2 * (1 - d);
        const v = weight[m.tier] * Math.exp(-a2 / (s * s));
        if (v > best) { best = v; who = k; }
      }
      if (best > 0.05) { landLit[i] = best; landOwner[i] = who; }
    }
  };

  const resize = () => {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = Math.round(canvas.offsetWidth * dpr); H = Math.round(canvas.offsetHeight * dpr);
    if (canvas.width !== W || canvas.height !== H) { canvas.width = W; canvas.height = H; }
  };
  new ResizeObserver(resize).observe(canvas);
  resize();

  const view = (v) => {
    const a = lon0 * RAD, t = lat0 * RAD;
    const x1 = v[0] * Math.cos(a) - v[2] * Math.sin(a), z1 = v[0] * Math.sin(a) + v[2] * Math.cos(a);
    return [x1, v[1] * Math.cos(t) - z1 * Math.sin(t), v[1] * Math.sin(t) + z1 * Math.cos(t)];
  };
  const slerp = (a, b, t) => {
    const d = Math.max(-1, Math.min(1, a[0] * b[0] + a[1] * b[1] + a[2] * b[2])), w = Math.acos(d), s = Math.sin(w) || 1;
    const k1 = Math.sin((1 - t) * w) / s, k2 = Math.sin(t * w) / s;
    return [a[0] * k1 + b[0] * k2, a[1] * k1 + b[1] * k2, a[2] * k1 + b[2] * k2];
  };

  const draw = (now) => {
    const cx = W / 2, cy = H / 2, R = Math.min(H * 0.41, W * 0.39);
    const P = (p) => { const q = view(p); return [cx + q[0] * R, cy - q[1] * R, q[2]]; };
    const seen = (x, y, z) => z > 0 || Math.hypot(x - cx, y - cy) > R;
    const t = shownAt === null ? -1 : now - shownAt;
    ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.globalCompositeOperation = 'source-over'; ctx.globalAlpha = 1;
    ctx.clearRect(0, 0, W, H);

    const grown = markers.map((m) => (reduce ? 1 : t < 0 ? 0 : easeOutCubic((t - m.delay) / 900)));
    const breath = markers.map((m, i) => {
      if (!beat[i] || reduce || t < m.delay + 900) return 0;
      const k = ((t - m.delay) / beat[i].period + beat[i].phase) % 1;
      return k < 0.4 ? Math.pow(Math.sin(Math.PI * (k / 0.4)), 2) : 0;
    });

    // Atmosphere, then the sphere: lit from the upper left, darker at the edge.
    let g = ctx.createRadialGradient(cx, cy, R * 0.9, cx, cy, R * 1.3);
    g.addColorStop(0, 'rgba(118,89,250,0)'); g.addColorStop(0.2, `rgba(118,89,250,${O.atmosphere})`); g.addColorStop(0.5, `rgba(118,89,250,${O.atmosphere * 0.3})`); g.addColorStop(1, 'rgba(118,89,250,0)');
    ctx.fillStyle = g; ctx.beginPath(); ctx.arc(cx, cy, R * 1.3, 0, TAU); ctx.fill();
    g = ctx.createRadialGradient(cx - R * 0.3, cy - R * 0.36, R * 0.05, cx, cy, R);
    g.addColorStop(0, SP.body[0]); g.addColorStop(0.55, SP.body[1]); g.addColorStop(1, SP.body[2]);
    ctx.fillStyle = g; ctx.beginPath(); ctx.arc(cx, cy, R, 0, TAU); ctx.fill();

    // Land: fine dots, fainter towards the edge; the land near a member country glows like a city at night.
    if (land) {
      const d0 = Math.max(1.1, R * 0.0062);
      const LV = 6, buckets = Array.from({ length: 6 * LV }, () => []);
      const a = lon0 * RAD, tl = lat0 * RAD, ca = Math.cos(a), sa = Math.sin(a), ct = Math.cos(tl), st = Math.sin(tl);
      for (let i = 0, n = 0; i < land.length; i += 3, n++) {
        const x1 = land[i] * ca - land[i + 2] * sa, z1 = land[i] * sa + land[i + 2] * ca;
        const y = land[i + 1] * ct - z1 * st, z = land[i + 1] * st + z1 * ct;
        if (z <= 0.04) continue;
        let lv = 0;
        if (landLit && landOwner[n] >= 0) {
          const o = landOwner[n], v = landLit[n] * landGrain[n] * grown[o] * (1 + 0.3 * breath[o]);
          if (v > 0.06) lv = Math.min(LV - 1, 1 + Math.floor(v * (LV - 1)));
        }
        buckets[Math.min(5, Math.floor(z * 6)) * LV + lv].push(cx + x1 * R, cy - y * R);
      }
      const [lo, hi] = SP.dotAlpha;
      buckets.forEach((pts, b) => {
        if (!pts.length) return;
        const k = Math.floor(b / LV), lv = b % LV, f = lv / (LV - 1), depth = Math.pow((k + 0.5) / 6, 0.85);
        const base = lo + (hi - lo) * depth, alpha = base + ((0.3 + 0.7 * depth) - base) * f;
        const c = mix(SP.dot, SP.city, Math.pow(f, 0.8)), d = d0 * (1 + 0.45 * f), h = d / 2;
        ctx.fillStyle = `rgba(${c[0]},${c[1]},${c[2]},${alpha.toFixed(3)})`;
        ctx.beginPath();
        // Plain land as tiny squares (they read as dots and cost little); lit land as round dots.
        if (lv === 0) for (let j = 0; j < pts.length; j += 2) ctx.rect(pts[j] - h, pts[j + 1] - h, d, d);
        else for (let j = 0; j < pts.length; j += 2) { ctx.moveTo(pts[j] + h, pts[j + 1]); ctx.arc(pts[j], pts[j + 1], h, 0, TAU); }
        ctx.fill();
      });
    }

    // The glassy edge: a bright inner rim fading inward, and a thin line round the sphere.
    g = ctx.createRadialGradient(cx, cy, R * 0.78, cx, cy, R);
    g.addColorStop(0, 'rgba(201,184,255,0)'); g.addColorStop(0.82, 'rgba(201,184,255,0.06)'); g.addColorStop(1, SP.rimInner);
    ctx.globalCompositeOperation = 'lighter'; ctx.fillStyle = g; ctx.beginPath(); ctx.arc(cx, cy, R, 0, TAU); ctx.fill();
    ctx.globalCompositeOperation = 'source-over';
    ctx.strokeStyle = SP.rimLine; ctx.lineWidth = 1.2 * dpr; ctx.beginPath(); ctx.arc(cx, cy, R, 0, TAU); ctx.stroke();

    // Spots: a soft bloom of light over each member country.
    ctx.globalCompositeOperation = 'lighter';
    markers.forEach((m, i) => {
      if (grown[i] <= 0) return;
      const [x, y, z] = P(m.v);
      if (z < 0.02) return;
      const edge = clamp((z - 0.02) / 0.3);
      const twinkle = m.tier === 1 && !reduce ? 0.85 + 0.15 * Math.sin(now * 0.0021 + i * 1.7) : 1;
      const s = R * (0.035 + 0.03 * m.tier) * (0.6 + 0.4 * grown[i]) * (1 + 0.28 * breath[i]);
      ctx.globalAlpha = clamp(LIGHT.spot[m.tier] * edge * grown[i] * twinkle * (0.82 + 0.18 * breath[i]));
      ctx.drawImage(sprite, x - s, y - s, s * 2, s * 2);
    });

    // Lasting links, faint, fading in once the lights are up.
    const linkIn = reduce ? 1 : easeOutCubic((t - lastDelay) / 1400);
    if (linkIn > 0) {
      const [lr, lg, lb] = O.line;
      ctx.globalCompositeOperation = 'source-over'; ctx.globalAlpha = 1; ctx.lineWidth = 0.9 * dpr;
      ctx.strokeStyle = `rgba(${lr},${lg},${lb},${(0.24 * linkIn).toFixed(3)})`;
      links.forEach((l) => {
        let open = false;
        ctx.beginPath();
        for (let j = 0; j <= 40; j++) {
          const u = j / 40, p = slerp(l.from, l.to, u), lift = 1 + l.lift * Math.sin(Math.PI * u);
          const [x, y, z] = P([p[0] * lift, p[1] * lift, p[2] * lift]);
          if (seen(x, y, z)) { if (open) ctx.lineTo(x, y); else { ctx.moveTo(x, y); open = true; } } else open = false;
        }
        ctx.stroke();
      });
    }

    // Trails: a bright head with a fading tail, arcing above the globe; a soft flash where each lands.
    if (!reduce && t > lastDelay + 1200 && hubs.length && targets.length) {
      if (now >= nextTrail && trails.length < 3) {
        const from = hubs[Math.floor(rnd() * hubs.length)], to = targets[Math.floor(rnd() * targets.length)];
        const ang = Math.acos(Math.max(-1, Math.min(1, from.v[0] * to.v[0] + from.v[1] * to.v[1] + from.v[2] * to.v[2])));
        trails.push({ from: from.v, to: to.v, at: now, dur: 1700 + 1200 * (ang / Math.PI), lift: 0.06 + 0.22 * (ang / Math.PI) });
        nextTrail = now + 900 + rnd() * 900;
      }
      const [tr, tg, tb] = O.trail;
      ctx.globalCompositeOperation = 'lighter'; ctx.lineCap = 'round';
      for (let k = trails.length - 1; k >= 0; k--) {
        const a = trails[k], age = (now - a.at) / a.dur;
        if (age > 1.5) { trails.splice(k, 1); continue; }
        const head = easeInOut(Math.min(1, age)), fade = age > 1 ? 1 - (age - 1) / 0.5 : clamp(age / 0.12), tail = 0.42;
        let prev = null;
        for (let j = 0; j <= 56; j++) {
          const u = j / 56;
          if (u > head || u < head - tail) { prev = null; continue; }
          const p = slerp(a.from, a.to, u), lift = 1 + a.lift * Math.sin(Math.PI * u);
          const [x, y, z] = P([p[0] * lift, p[1] * lift, p[2] * lift]);
          const vis = seen(x, y, z);
          if (prev && vis && prev[2]) {
            const w = (u - (head - tail)) / tail;
            ctx.globalAlpha = clamp(fade * w * w);
            ctx.strokeStyle = `rgb(${tr},${tg},${tb})`; ctx.lineWidth = (0.9 + 1.9 * w) * dpr;
            ctx.beginPath(); ctx.moveTo(prev[0], prev[1]); ctx.lineTo(x, y); ctx.stroke();
          }
          prev = [x, y, vis];
        }
        if (age <= 1) {
          const p = slerp(a.from, a.to, head), lift = 1 + a.lift * Math.sin(Math.PI * head);
          const [x, y, z] = P([p[0] * lift, p[1] * lift, p[2] * lift]);
          if (seen(x, y, z)) { const s = R * 0.07; ctx.globalAlpha = fade; ctx.drawImage(z > 0 ? sprite : outer, x - s, y - s, s * 2, s * 2); }
        } else {
          const [x, y, z] = P(a.to);
          if (z > 0) { const s = R * (0.07 + 0.12 * (age - 1)); ctx.globalAlpha = clamp(fade); ctx.drawImage(sprite, x - s, y - s, s * 2, s * 2); }
        }
      }
    }
    ctx.globalAlpha = 1; ctx.globalCompositeOperation = 'source-over';
  };

  const frame = (now) => {
    const dt = last ? Math.min(0.05, (now - last) / 1000) : 0;
    last = now;
    // The turn eases up to speed over about two seconds once the section is in view.
    if (shownAt !== null) turn += (TURN - turn) * (1 - Math.exp(-dt / 0.7));
    if (!drag) { lon0 += turn * dt + spin; spin *= Math.pow(0.04, dt); }
    draw(now);
    frameId = requestAnimationFrame(frame);
  };

  canvas.addEventListener('pointerdown', (e) => { drag = { x: e.clientX, lon: lon0, lastX: e.clientX, lastT: performance.now(), v: 0 }; canvas.setPointerCapture(e.pointerId); wrap.classList.add('is-dragging'); });
  canvas.addEventListener('pointermove', (e) => {
    if (!drag) return;
    const now = performance.now(), k = 220 / (canvas.offsetWidth || 400);
    lon0 = drag.lon - (e.clientX - drag.x) * k;
    drag.v = (-(e.clientX - drag.lastX) * k) / Math.max(1, now - drag.lastT) * 16;
    drag.lastX = e.clientX; drag.lastT = now;
  });
  const release = () => { if (drag) { spin = Math.max(-6, Math.min(6, drag.v)); drag = null; wrap.classList.remove('is-dragging'); } };
  canvas.addEventListener('pointerup', release);
  canvas.addEventListener('pointercancel', release);

  // The numbers climb with the lights and land as the last one lights.
  const countUp = () => {
    const total = lastDelay + 700;
    const step = (now) => {
      const t = now - shownAt;
      counters.forEach((el) => {
        const target = Number(el.dataset.count);
        const v = el.dataset.kind === 'countries' && markers.length
          ? markers.reduce((a, m) => a + clamp((t - m.delay) / 260), 0) * (target / markers.length)
          : target * easeOutQuart(t / total);
        el.textContent = fmt(Math.min(target, v), el);
      });
      if (t < total + 300) requestAnimationFrame(step);
      else counters.forEach((el) => { el.textContent = fmt(Number(el.dataset.count), el); });
    };
    requestAnimationFrame(step);
  };

  // The globe's part of the entrance starts when the section's does (the script beside it adds .reach-on).
  const start = () => {
    if (shownAt !== null) return;
    shownAt = performance.now();
    if (!reduce) countUp();
  };
  if (section.classList.contains('reach-on')) start();
  else section.addEventListener('reach:on', start, { once: true });

  // Load the land when the section is near; draw only while it can be seen.
  const near = new IntersectionObserver(async (entries) => {
    if (!entries[0].isIntersecting) return;
    near.disconnect();
    try {
      const flat = await (await fetch(wrap.dataset.land)).json(); // tenths of a degree: lat, lon, lat, lon...
      const xyz = new Float32Array((flat.length / 2) * 3);
      for (let i = 0, j = 0; i < flat.length; i += 2, j += 3) { const v = unit(flat[i] / 10, flat[i + 1] / 10); xyz[j] = v[0]; xyz[j + 1] = v[1]; xyz[j + 2] = v[2]; }
      land = xyz;
      lightLand();
    } catch (e) { land = new Float32Array(0); }
  }, { rootMargin: '600px' });
  near.observe(section);
  new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting) { if (!frameId) { last = 0; frameId = requestAnimationFrame(frame); } }
    else if (frameId) { cancelAnimationFrame(frameId); frameId = 0; }
  }, { rootMargin: '120px' }).observe(wrap);
}

const section = document.querySelector('[data-reach]');
if (section) mountReach(section);
