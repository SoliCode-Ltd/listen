// The homepage's reach globe (components/home/reach): just the globe, turning, with its spots lit. Land is fine dots
// like a night map; every country with members lights the land around it like cities seen from space (brighter and
// wider where there are more members), under a glassy rim and a soft atmosphere. The sphere is deep violet on a dark
// page and a lighter brand violet on a light one. Canvas 2D, no dependencies.
//
// Nothing waits to appear: the globe is there and lit from the start, turning slowly, and can be dragged; the
// numbers beside it count up (the small script beside the section does that). The land fades in as it arrives. It
// draws only while it can be seen; with reduced motion it stays still.

const RAD = Math.PI / 180;
const TAU = Math.PI * 2;
const clamp = (v) => Math.max(0, Math.min(1, v));
const mix = (a, b, k) => a.map((v, i) => Math.round(v + (b[i] - v) * k));
const unit = (lat, lon) => { const a = lat * RAD, b = lon * RAD; return [Math.cos(a) * Math.sin(b), Math.sin(a), Math.cos(a) * Math.cos(b)]; };
function seeded(a) { return () => { a |= 0; a = (a + 0x6d2b79f5) | 0; let t = Math.imul(a ^ (a >>> 15), 1 | a); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; }

// The sphere, per page theme. On a white page it is a lighter brand violet, so it reads as part of the page.
const SPHERE = {
  dark: { body: ['#3b2b9c', '#1b125a', '#0b0826'], rimInner: 'rgba(201,184,255,0.55)', rimLine: 'rgba(214,202,255,0.75)',
    dot: [146, 126, 252], dotAlpha: [0.06, 0.64], city: [246, 242, 255] },
  light: { body: ['#7f69f6', '#4b35c9', '#271a7e'], rimInner: 'rgba(225,215,255,0.6)', rimLine: 'rgba(235,228,255,0.85)',
    dot: [214, 204, 255], dotAlpha: [0.1, 0.74], city: [255, 255, 255] },
};
// The atmosphere around the sphere, per page theme.
const OUTER = { dark: { atmosphere: 0.42 }, light: { atmosphere: 0.24 } };
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
  const sprite = glowSprite(LIGHT.glow);
  const themeName = () => (document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark');
  let SP = SPHERE[themeName()], O = OUTER[themeName()];
  new MutationObserver(() => { SP = SPHERE[themeName()]; O = OUTER[themeName()]; })
    .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  // The view: which longitude faces us (it turns), and a fixed tilt that shows a little more of the north.
  let lon0 = 30, lat0 = 18, spin = 0, drag = null, last = 0, frameId = 0, dpr = 1, W = 0, H = 0;
  let land = null, landLit = null, landOwner = null, landGrain = null, landIn = 0;
  // Degrees a second, westward: about once round a minute.
  const TURN = reduce ? 0 : -6;
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

  const draw = (now) => {
    const cx = W / 2, cy = H / 2, R = Math.min(W, H) * 0.4;
    const P = (p) => { const q = view(p); return [cx + q[0] * R, cy - q[1] * R, q[2]]; };
    ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.globalCompositeOperation = 'source-over'; ctx.globalAlpha = 1;
    ctx.clearRect(0, 0, W, H);

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
          const v = landLit[n] * landGrain[n];
          if (v > 0.06) lv = Math.min(LV - 1, 1 + Math.floor(v * (LV - 1)));
        }
        buckets[Math.min(5, Math.floor(z * 6)) * LV + lv].push(cx + x1 * R, cy - y * R);
      }
      const [lo, hi] = SP.dotAlpha;
      buckets.forEach((pts, b) => {
        if (!pts.length) return;
        const k = Math.floor(b / LV), lv = b % LV, f = lv / (LV - 1), depth = Math.pow((k + 0.5) / 6, 0.85);
        const base = lo + (hi - lo) * depth, alpha = (base + ((0.3 + 0.7 * depth) - base) * f) * landIn;
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
    markers.forEach((m) => {
      const [x, y, z] = P(m.v);
      if (z < 0.02) return;
      const edge = clamp((z - 0.02) / 0.3);
      const s = R * (0.035 + 0.03 * m.tier);
      ctx.globalAlpha = clamp(LIGHT.spot[m.tier] * edge * landIn);
      ctx.drawImage(sprite, x - s, y - s, s * 2, s * 2);
    });

    ctx.globalAlpha = 1; ctx.globalCompositeOperation = 'source-over';
  };

  const frame = (now) => {
    const dt = last ? Math.min(0.05, (now - last) / 1000) : 0;
    last = now;
    if (land && landIn < 1) landIn = reduce ? 1 : Math.min(1, landIn + dt / 0.5); // the land fades in as it arrives
    if (!drag) { lon0 += TURN * dt + spin; spin *= Math.pow(0.04, dt); }
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
  }, { rootMargin: '800px' });
  near.observe(section);
  new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting) { if (!frameId) { last = 0; frameId = requestAnimationFrame(frame); } }
    else if (frameId) { cancelAnimationFrame(frameId); frameId = 0; }
  }, { rootMargin: '120px' }).observe(wrap);
}

const section = document.querySelector('[data-reach]');
if (section) mountReach(section);
