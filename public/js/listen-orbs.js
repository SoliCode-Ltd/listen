/*
 * Listen voice orbs: spheres of soft, grainy, flowing colour. While a voice
 * plays, rings ripple out from the centre with its loudness, so the voice can
 * be seen as well as heard.
 *
 * Markup (components/orb):
 *   <span class="orb" data-orb="arabic" data-orb-colors="#..,#..,#..,#.."><canvas></canvas></span>
 *
 * One WebGL context draws every orb and copies each frame into the orb's own
 * canvas, so a page with many orbs stays light. Without WebGL the CSS sphere
 * stays as it is; with reduced motion every orb keeps a still frame.
 *
 * How an orb behaves, on every page: in view, its colour drifts gently on its
 * own (the chosen one, .is-live, a little more); hovering or focusing it or its
 * card ([data-orb-host]) quickens and brightens it; a voice playing in its card
 * ([data-vp-track]), on its stage or anywhere in its section ([data-orb-follow])
 * makes it light up, flow with the voice, ripple with the loudness and breathe
 * a halo around it. An orb in a hidden part of the page ([inert]) is not drawn,
 * and a page can hold every orb still for a moment (ListenOrbs.hold) while
 * something else needs each frame, such as a slider being dragged.
 */
(function () {
  'use strict';
  if (window.ListenOrbs) return;

  var MAX = 640;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  var VERT = 'attribute vec2 p; void main(){ gl_Position = vec4(p, 0.0, 1.0); }';
  var FRAG = [
    'precision highp float;',
    'uniform vec2 uRes; uniform float uSize; uniform float uTime; uniform float uLevel; uniform float uSeed; uniform float uLift;',
    'uniform vec3 uC0; uniform vec3 uC1; uniform vec3 uC2; uniform vec3 uC3;',
    'float hash(vec3 p){ p = fract(p * 0.1031); p += dot(p, p.zyx + 31.32); return fract((p.x + p.y) * p.z); }',
    'float noise(vec3 x){',
    '  vec3 i = floor(x); vec3 f = fract(x); f = f * f * (3.0 - 2.0 * f);',
    '  return mix(mix(mix(hash(i), hash(i + vec3(1,0,0)), f.x), mix(hash(i + vec3(0,1,0)), hash(i + vec3(1,1,0)), f.x), f.y),',
    '             mix(mix(hash(i + vec3(0,0,1)), hash(i + vec3(1,0,1)), f.x), mix(hash(i + vec3(0,1,1)), hash(i + vec3(1,1,1)), f.x), f.y), f.z);',
    '}',
    'float fbm(vec3 p){ float a = 0.55; float s = 0.0; for (int i = 0; i < 3; i++){ s += a * noise(p); p = p * 2.07 + vec3(1.7, 9.2, 3.1); a *= 0.45; } return s; }',
    'void main(){',
    '  vec2 uv = (gl_FragCoord.xy / uSize) * 2.0 - 1.0;',
    '  float r = length(uv);',
    '  if (r > 1.0) { gl_FragColor = vec4(0.0); return; }',
    '  vec3 n = vec3(uv, sqrt(max(0.0, 1.0 - r * r)));',
    // The colour turns slowly with the sphere, in large soft fields like light inside glass.
    '  float a = uTime * 0.16 + uSeed;',
    '  vec3 q = vec3(n.x * cos(a) - n.z * sin(a), n.y, n.x * sin(a) + n.z * cos(a));',
    '  vec3 s = q * 0.7 + uSeed;',
    '  vec3 w = vec3(fbm(s + vec3(0.0, 0.0, uTime * 0.11)), fbm(s + vec3(5.2, 1.3, 2.8 + uTime * 0.05)), fbm(s + vec3(1.7, 9.2, uTime * 0.08)));',
    '  float v = fbm(s * 0.8 + 1.7 * w);',
    // Listen's mark: rings that ripple out from the centre with the voice.
    '  float ring = sin(r * 17.0 + (w.x - 0.5) * 7.0 - uTime * 5.0);',
    '  v += ring * 0.1 * uLevel * smoothstep(0.08, 0.4, r) * (1.0 - r * 0.6);',
    '  v = clamp((v - 0.26) / 0.42, 0.0, 1.0);',
    '  vec3 col = mix(uC0, uC1, smoothstep(0.0, 0.38, v));',
    '  col = mix(col, uC2, smoothstep(0.35, 0.8, v));',
    '  col = mix(col, uC3, smoothstep(0.75, 1.0, v) * 0.85);',
    // Soft light from the top left: a glow, not a planet's dark side.
    '  vec3 L = normalize(vec3(-0.45, 0.55, 0.8));',
    '  float diff = dot(n, L) * 0.5 + 0.5;',
    '  col *= 0.8 + 0.32 * diff;',
    '  float glow = pow(max(dot(n, L), 0.0), 6.0);',
    '  col = mix(col, uC3, glow * 0.22);',
    // Glass: the edge catches the light.
    '  float rim = pow(1.0 - n.z, 2.6);',
    '  col = mix(col, mix(uC2, uC3, 0.6), rim * 0.55);',
    '  col += uLevel * 0.05 * uC3;',
    // Lit up: hovered a little, playing more, with the voice.
    '  col = mix(col, mix(uC2, uC3, 0.7), uLift);',
    // Fine film grain, so the light reads as a surface rather than a gradient.
    '  float g = hash(vec3(floor(gl_FragCoord.xy), floor(uTime * 10.0)));',
    '  col += (g - 0.5) * 0.05;',
    '  float edge = 1.0 - smoothstep(1.0 - 1.6 / uSize, 1.0, r);',
    '  gl_FragColor = vec4(clamp(col, 0.0, 1.0) * edge, edge);',
    '}'
  ].join('\n');

  var gl = null, glCanvas = null, prog = null, loc = {};

  function setup() {
    glCanvas = document.createElement('canvas');
    glCanvas.width = MAX;
    glCanvas.height = MAX;
    try {
      gl = glCanvas.getContext('webgl', { premultipliedAlpha: true, preserveDrawingBuffer: true, antialias: false, alpha: true });
    } catch (e) { gl = null; }
    if (!gl) return false;
    function shader(type, src) {
      var s = gl.createShader(type);
      gl.shaderSource(s, src);
      gl.compileShader(s);
      return gl.getShaderParameter(s, gl.COMPILE_STATUS) ? s : null;
    }
    var vs = shader(gl.VERTEX_SHADER, VERT), fs = shader(gl.FRAGMENT_SHADER, FRAG);
    if (!vs || !fs) { gl = null; return false; }
    prog = gl.createProgram();
    gl.attachShader(prog, vs);
    gl.attachShader(prog, fs);
    gl.linkProgram(prog);
    if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) { gl = null; return false; }
    gl.useProgram(prog);
    var buf = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buf);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
    var pos = gl.getAttribLocation(prog, 'p');
    gl.enableVertexAttribArray(pos);
    gl.vertexAttribPointer(pos, 2, gl.FLOAT, false, 0, 0);
    ['uRes', 'uSize', 'uTime', 'uLevel', 'uSeed', 'uLift', 'uC0', 'uC1', 'uC2', 'uC3'].forEach(function (k) { loc[k] = gl.getUniformLocation(prog, k); });
    gl.clearColor(0, 0, 0, 0);
    return true;
  }

  function rgb(hex) {
    var h = String(hex || '#7659FA').replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    var n = parseInt(h, 16);
    return [((n >> 16) & 255) / 255, ((n >> 8) & 255) / 255, (n & 255) / 255];
  }

  var orbs = [];
  function state(el) {
    if (el.__orb) return el.__orb;
    var colors = String(el.getAttribute('data-orb-colors') || '').split(',').map(rgb);
    while (colors.length < 4) colors.push(colors[colors.length - 1] || rgb('#7659FA'));
    var name = el.getAttribute('data-orb') || '';
    var seed = 0;
    for (var i = 0; i < name.length; i++) seed = (seed * 31 + name.charCodeAt(i)) % 997;
    el.__orb = {
      el: el,
      canvas: el.querySelector('canvas'),
      colors: colors,
      seed: seed / 97,
      time: 0,
      level: 0,
      lift: 0,
      speed: 1,
      size: 0,
      css: 0,
      glow: '',
      visible: false,
      hover: false,
    };
    orbs.push(el.__orb);
    return el.__orb;
  }

  // An orb's size in the layout, measured when it changes (not on every frame, which
  // would make the browser lay the page out again for each orb).
  function measure(o) {
    o.css = Math.max(o.el.offsetWidth, o.el.offsetHeight);
  }
  function sizeOf(o) {
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    return Math.max(8, Math.min(MAX, Math.round(o.css * dpr)));
  }

  function draw(o) {
    if (!gl || !o.canvas) return;
    var s = sizeOf(o);
    if (o.canvas.width !== s || o.canvas.height !== s) { o.canvas.width = s; o.canvas.height = s; }
    gl.viewport(0, 0, s, s);
    gl.clear(gl.COLOR_BUFFER_BIT);
    gl.uniform2f(loc.uRes, MAX, MAX);
    gl.uniform1f(loc.uSize, s);
    gl.uniform1f(loc.uTime, o.time);
    gl.uniform1f(loc.uLevel, o.level);
    gl.uniform1f(loc.uSeed, o.seed);
    gl.uniform1f(loc.uLift, o.lift);
    gl.uniform3fv(loc.uC0, o.colors[0]);
    gl.uniform3fv(loc.uC1, o.colors[1]);
    gl.uniform3fv(loc.uC2, o.colors[2]);
    gl.uniform3fv(loc.uC3, o.colors[3]);
    gl.drawArrays(gl.TRIANGLES, 0, 3);
    var ctx = o.canvas.getContext('2d');
    ctx.clearRect(0, 0, s, s);
    // GL draws from the bottom left: the orb is the bottom-left square of the shared canvas.
    ctx.drawImage(glCanvas, 0, MAX - s, s, s, 0, 0, s, s);
    o.size = s;
    o.el.classList.add('is-drawn');
  }

  // The voice playing in this orb's card, from the shared voice player (0..1).
  function voiceLevel(o) {
    var track = o.el.closest('[data-vp-track]');
    if (track && track.classList.contains('is-playing')) return Math.max(0, Math.min(1, track.__vpLevel || 0));
    // The homepage conversation plays its own lines and keeps their loudness on its stage.
    var cast = o.el.closest('[data-vs-cast]');
    if (cast && cast.classList.contains('is-voicing')) return Math.max(0, Math.min(1, cast.__vpLevel || 0));
    // A section's orb follows whichever card in the section is playing.
    var section = o.el.closest('[data-orb-follow]');
    var playing = section && section.querySelector('[data-vp-track].is-playing');
    if (playing) return Math.max(0, Math.min(1, playing.__vpLevel || 0));
    return -1;
  }

  var frame = 0, last = 0, idle = 0, beat = false, held = false;
  function tick(now) {
    var dt = last ? Math.min(0.05, (now - last) / 1000) : 0.016;
    last = now;
    if (held) { frame = requestAnimationFrame(tick); return; }
    beat = !beat;
    var any = false;
    orbs.forEach(function (o) {
      if (!o.visible || o.el.closest('[inert]')) return;
      any = true;
      var voice = voiceLevel(o);
      var playing = voice >= 0;
      var chosen = o.el.classList.contains('is-live');
      var targetLevel = playing ? voice : 0;
      var targetLift = playing ? 0.1 + 0.22 * voice : (o.hover ? 0.07 : 0);
      var targetSpeed = playing ? 2.2 + 2.6 * voice : (o.hover ? 2.1 : (chosen ? 1 : 0.7));
      var k = Math.min(1, dt * 8);
      o.level += (targetLevel - o.level) * k;
      o.lift += (targetLift - o.lift) * k;
      o.speed += (targetSpeed - o.speed) * Math.min(1, dt * 4);
      o.time += dt * o.speed;
      // The halo around a playing orb breathes with it (CSS reads --orb-glow).
      var glow = o.level.toFixed(3);
      if (glow !== o.glow) { o.glow = glow; o.el.style.setProperty('--orb-glow', glow); }
      // A resting orb is drawn every other frame: the same slow drift for half the work.
      if (beat && !chosen && !playing && !o.hover && o.lift < 0.005 && o.level < 0.005) return;
      draw(o);
    });
    if (any) { idle = 0; } else { idle += dt; }
    frame = idle < 0.5 ? requestAnimationFrame(tick) : 0;
    if (!frame) last = 0;
  }
  function wake() {
    if (!gl || reduced || frame) return;
    idle = 0;
    frame = requestAnimationFrame(tick);
  }

  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      var o = state(entry.target);
      o.visible = entry.isIntersecting;
      if (o.visible) measure(o);
      if (o.visible && sizeOf(o) !== o.size) draw(o);
    });
    wake();
  }, { rootMargin: '200px' }) : null;

  // An orb that grows or shrinks (a picked orb rises) is drawn again at its new size.
  var resized = [], resizeFrame = 0;
  var ro = 'ResizeObserver' in window ? new ResizeObserver(function (entries) {
    entries.forEach(function (entry) { var o = entry.target.__orb; if (o) { measure(o); if (o.visible) resized.push(o); } });
    if (!resizeFrame) {
      resizeFrame = requestAnimationFrame(function () {
        resizeFrame = 0;
        resized.splice(0).forEach(function (o) { if (sizeOf(o) !== o.size) draw(o); });
      });
    }
  }) : null;

  function attach(el) {
    var o = state(el);
    if (o.attached) return;
    o.attached = true;
    // Hovering or focusing the orb's card counts as hovering the orb.
    var host = el.closest('[data-orb-host]') || el;
    host.addEventListener('pointerenter', function () { o.hover = true; wake(); });
    host.addEventListener('pointerleave', function () { o.hover = false; });
    host.addEventListener('focusin', function () { o.hover = true; wake(); });
    host.addEventListener('focusout', function (e) { if (!host.contains(e.relatedTarget)) o.hover = false; });
    measure(o);
    if (io) { io.observe(el); } else { o.visible = true; draw(o); }
    if (ro) ro.observe(el);
  }

  function scan(root) {
    if (!gl) return;
    (root || document).querySelectorAll('.orb[data-orb-colors]').forEach(attach);
  }

  function boot() {
    if (!setup()) return;
    document.documentElement.classList.add('has-orbs');
    scan(document);
    // A voice starting anywhere on the page, or a card being picked, wakes the loop.
    document.addEventListener('listen:audio-play', wake);
    document.addEventListener('click', function () { setTimeout(wake, 0); }, true);
    var resizeTimer = 0;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () { orbs.forEach(function (o) { if (o.visible) { measure(o); draw(o); } }); }, 150);
    });
  }

  // Every orb keeps its frame while held, and goes on from there when let go.
  function hold(on) {
    held = !!on;
    if (!held) wake();
  }

  window.ListenOrbs = { scan: scan, wake: wake, hold: hold };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
