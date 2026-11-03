/*
 * Listen: the call button and its panel on a business's website, loaded by
 * listen-agent.js with the agent's settings. Everything sits in a shadow root,
 * so the website's styles never reach it and it never reaches the website's.
 * The call itself is ListenAgentCall (agent-call.js), loaded on first use.
 */
(function () {
  'use strict';
  if (window.ListenAgentWidget) return;

  var CSS = [
    ':host{all:initial}',
    '.lva{position:fixed;bottom:20px;z-index:2147483000;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,"Noto Sans Arabic","Geeza Pro",Arial,sans-serif;font-size:15px;line-height:1.45;-webkit-font-smoothing:antialiased;',
    '  --a:#7659FA;--a-2:#6446F2;--bg:#ffffff;--text:#16132F;--muted:#5F5A7E;--line:rgba(23,20,74,.12);--soft:rgba(118,89,250,.10);--shadow:0 24px 60px -18px rgba(23,20,74,.38),0 2px 6px rgba(23,20,74,.08)}',
    '.lva[data-theme="dark"]{--bg:#17142E;--text:#F3F1FF;--muted:#B5AED9;--line:rgba(255,255,255,.12);--soft:rgba(160,140,255,.14);--shadow:0 24px 60px -18px rgba(0,0,0,.7),0 2px 6px rgba(0,0,0,.3)}',
    '.lva{color:var(--text)}',
    '.lva *,.lva *::before,.lva *::after{box-sizing:border-box}',
    '.lva [hidden]{display:none !important}',
    '.lva button{font:inherit;cursor:pointer;-webkit-tap-highlight-color:transparent}',
    '.lva-mute{color:var(--text)}',
    '.lva button:focus-visible,.lva a:focus-visible{outline:3px solid rgba(118,89,250,.45);outline-offset:2px}',
    /* The launcher: a pill with a small living orb. */
    '.lva-launch{display:flex;align-items:center;gap:10px;min-height:52px;padding:8px 18px 8px 8px;border:0;border-radius:999px;background:var(--bg);color:var(--text);box-shadow:var(--shadow);font-weight:700;font-size:15px;transition:transform .25s cubic-bezier(.22,1,.36,1),box-shadow .25s ease}',
    '.lva[dir="rtl"] .lva-launch{padding:8px 8px 8px 18px}',
    '.lva-launch:hover{transform:translateY(-2px)}',
    '.lva-launch:active{transform:scale(.97)}',
    '.lva-launch.is-round{padding:8px}',
    '.lva-mini{position:relative;width:36px;height:36px;flex:0 0 auto}',
    /* The status dot, on the photo's lower corner like a messenger's: green while the agent can take a call, pink and pulsing during one. */
    '.lva-live-dot{position:absolute;bottom:-1px;inset-inline-end:-1px;z-index:2;width:13px;height:13px;border-radius:50%;background:#22c55e;border:2.5px solid var(--bg);transition:background .3s ease}',
    '.lva.is-live .lva-live-dot{background:#ee4a77;animation:lva-blink 1.4s ease-in-out infinite}',
    /* The orb, drawn in CSS from the agent's colours (--c1 deep … --c4 light). */
    '.lva-orb{position:absolute;inset:0;border-radius:50%;background:radial-gradient(circle at 34% 28%,var(--c4) 0 7%,var(--c3) 24%,var(--c2) 55%,var(--c1) 100%);box-shadow:inset -8px -12px 24px rgba(0,0,0,.28),0 10px 28px -10px var(--c2);transform:scale(var(--s,1));transition:transform .09s linear;overflow:hidden;animation:lva-breathe 5s ease-in-out infinite}',
    '.lva-orb::before{content:"";position:absolute;inset:-12%;border-radius:50%;background:conic-gradient(from 0deg,transparent 0 18%,var(--c3) 32%,transparent 48%,var(--c4) 66%,transparent 82%);opacity:.55;filter:blur(7px);mix-blend-mode:screen;animation:lva-spin 10s linear infinite}',
    '.lva-orb::after{content:"";position:absolute;inset:9% 20% 54% 22%;border-radius:50%;background:radial-gradient(ellipse at 50% 40%,rgba(255,255,255,.6),transparent 70%)}',
    '.lva.is-speaking .lva-orb::before{animation-duration:3.2s;opacity:.8}',
    '.lva.is-connecting .lva-orb{animation:lva-pulse 1.1s ease-in-out infinite}',
    /* The panel. */
    '.lva.is-right{right:20px}.lva.is-left{left:20px}.lva.is-right .lva-panel{right:0}.lva.is-left .lva-panel{left:0}',
    '.lva-panel{position:absolute;bottom:calc(100% + 14px);width:344px;max-width:calc(100vw - 24px);padding:18px;border-radius:24px;background:var(--bg);color:var(--text);box-shadow:var(--shadow);border:1px solid var(--line);display:grid;gap:12px;transform-origin:bottom var(--side);opacity:0;transform:translateY(10px) scale(.96);pointer-events:none;visibility:hidden;transition:opacity .22s ease,transform .32s cubic-bezier(.22,1,.36,1),visibility 0s linear .32s}',
    '.lva.is-open .lva-panel{opacity:1;transform:none;pointer-events:auto;visibility:visible;transition:opacity .22s ease,transform .32s cubic-bezier(.22,1,.36,1),visibility 0s}',
    '.lva-head{display:flex;align-items:center;gap:10px}',
    '.lva-face{width:42px;height:42px;border-radius:50%;overflow:hidden;flex:0 0 auto;background:var(--soft)}',
    '.lva-face img{width:100%;height:100%;object-fit:cover;display:block}',
    '.lva-who{flex:1;min-width:0;display:grid}',
    '.lva-who strong{font-size:15.5px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
    '.lva-who small{color:var(--muted);font-size:12.5px}',
    '.lva-x{width:36px;height:36px;border-radius:50%;border:0;background:transparent;display:grid;place-items:center;color:var(--muted)}',
    '.lva-x:hover{background:var(--soft);color:var(--text)}',
    '.lva-x svg,.lva-call svg,.lva-mute svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}',
    /* The voice: a small orb, and the brand's waves either side that move as it talks. */
    '.lva-stage{display:flex;align-items:center;justify-content:center;gap:12px;height:104px;margin-top:4px}',
    '.lva-stage-orb{position:relative;width:78px;height:78px;flex:0 0 auto}',
    '.lva-ring{position:absolute;inset:-8px;border-radius:50%;border:2px solid var(--c3);opacity:var(--mo,0);transform:scale(var(--ms,1));transition:opacity .12s linear,transform .12s linear}',
    '.lva-wave{display:flex;align-items:center;gap:4px;height:64px}',
    '.lva-wave i{display:block;width:4px;height:5px;border-radius:4px;background:linear-gradient(180deg,var(--c3),var(--c2));opacity:.9;transition:height .07s linear}',
    '.lva:not(.is-live) .lva-wave i{animation:lva-idle 2.4s ease-in-out infinite}',
    '.lva-wave i:nth-child(2n){animation-delay:-.6s}.lva-wave i:nth-child(3n){animation-delay:-1.2s}.lva-wave i:nth-child(5n){animation-delay:-1.8s}',
    '@keyframes lva-idle{0%,100%{height:5px}50%{height:11px}}',
    '.lva-face.is-photo{box-shadow:0 0 0 2px var(--bg),0 0 0 4px var(--c2)}',
    '.lva-mini img{width:100%;height:100%;border-radius:50%;object-fit:cover;display:block}',
    '.lva-mini.is-photo::after{content:"";position:absolute;inset:-3px;border-radius:50%;border:2px solid var(--c2)}',
    '.lva.is-speaking .lva-mini.is-photo::after{animation:lva-pulse-ring 1s ease-in-out infinite}',
    '@keyframes lva-pulse-ring{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.12);opacity:.5}}',
    '.lva-state{margin:0;text-align:center;font-weight:700;font-size:15px;min-height:22px}',
    '.lva-state.is-error{color:#d0335f}',
    /* What was said: older lines fade out at the top as new ones come in. */
    '.lva-lines{display:grid;gap:6px;max-height:160px;overflow:auto;scrollbar-width:thin;padding-top:12px;-webkit-mask-image:linear-gradient(to bottom,transparent 0,#000 18px);mask-image:linear-gradient(to bottom,transparent 0,#000 18px)}',
    '.lva-lines:empty{display:none}',
    '.lva-line{padding:8px 11px;border-radius:14px;background:var(--soft);font-size:13.5px;max-width:88%;justify-self:start;animation:lva-in .3s cubic-bezier(.22,1,.36,1)}',
    '.lva-line.is-caller{justify-self:end;background:transparent;border:1px solid var(--line)}',
    '.lva-buttons{display:flex;gap:8px;justify-content:center}',
    '.lva-call{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 22px;border:0;border-radius:999px;background:var(--a);color:#fff !important;font-weight:800;font-size:15.5px;box-shadow:0 10px 24px -10px var(--a);transition:background .2s ease,transform .18s ease}',
    '.lva-call:hover{background:var(--a-2)}',
    '.lva-call:active{transform:scale(.97)}',
    '.lva-call.is-live{background:#e2445f;box-shadow:0 10px 24px -10px #e2445f}',
    '.lva-mute{width:48px;height:48px;border-radius:50%;border:1px solid var(--line);background:transparent;display:grid;place-items:center}',
    '.lva-mute[aria-pressed="true"]{background:var(--soft);color:var(--a)}',
    '.lva-note{margin:0;color:var(--muted);font-size:12px;text-align:center}',
    '.lva-powered{justify-self:center;color:var(--muted);font-size:11.5px;text-decoration:none;font-weight:600}',
    '.lva-powered:hover{color:var(--a)}',
    '@media (max-width:480px){.lva{bottom:14px}.lva.is-right{right:14px}.lva.is-left{left:14px}.lva .lva-panel{position:fixed;left:10px !important;right:10px !important;bottom:80px;width:auto;max-width:none}}',
    '@keyframes lva-spin{to{transform:rotate(360deg)}}',
    '@keyframes lva-breathe{0%,100%{scale:1}50%{scale:1.035}}',
    '@keyframes lva-pulse{0%,100%{opacity:1}50%{opacity:.65}}',
    '@keyframes lva-blink{0%,100%{opacity:1}50%{opacity:.35}}',
    '@keyframes lva-in{from{opacity:0;transform:translateY(6px)}}',
    '@media (prefers-reduced-motion:reduce){.lva *,.lva *::before,.lva *::after{animation:none !important;transition:none !important}}',
  ].join('\n');

  var ICONS = {
    mic: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>',
    micOff: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M9 9v3a3 3 0 0 0 5.1 2.1M15 9.3V6a3 3 0 0 0-5.7-1.3"/><path d="M19 11a7 7 0 0 1-1.2 3.9M5 11a7 7 0 0 0 10.8 5.9M12 18v3"/></svg>',
    end: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 15.2c5-4.6 13-4.6 18 0l-2.3 2.7-3.7-1.4v-2.8a12.4 12.4 0 0 0-6 0v2.8l-3.7 1.4z"/></svg>',
    close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
  };

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function orb() {
    var o = el('i', 'lva-orb');
    o.setAttribute('aria-hidden', 'true');
    return o;
  }
  function isDarkPage() {
    var pick = function (node) {
      if (!node) return null;
      var c = getComputedStyle(node).backgroundColor;
      var m = c && c.match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/);
      if (!m || (m[4] !== undefined && +m[4] < 0.5)) return null;
      return (0.2126 * m[1] + 0.7152 * m[2] + 0.0722 * m[3]) / 255 < 0.45;
    };
    var v = pick(document.body);
    if (v === null) v = pick(document.documentElement);
    return v === true;
  }
  function visitorId() {
    try {
      var id = localStorage.getItem('listen_agent_visitor');
      if (!id) { id = Math.random().toString(36).slice(2) + Date.now().toString(36); localStorage.setItem('listen_agent_visitor', id); }
      return id;
    } catch (e) { return ''; }
  }

  function mount(cfg, ctx) {
    if (!document.body || document.querySelector('[data-listen-agent]')) return;
    var text = cfg.text || {}, states = text.states || {}, look = cfg.look || {}, colors = cfg.colors || ['#24138A', '#7659FA', '#C9BEFF', '#FFE3D3'];
    var pageRtl = getComputedStyle(document.documentElement).direction === 'rtl' || document.documentElement.dir === 'rtl';
    var side = (look.position === 'start') !== pageRtl ? 'left' : 'right';
    var theme = look.theme === 'dark' || (look.theme !== 'light' && isDarkPage()) ? 'dark' : 'light';

    var host = el('div');
    host.setAttribute('data-listen-agent', '');
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    var style = el('style');
    style.textContent = CSS;
    root.appendChild(style);

    var box = el('div', 'lva');
    box.dir = cfg.dir === 'rtl' ? 'rtl' : 'ltr';
    box.lang = cfg.language === 'en' ? 'en' : 'ar';
    box.setAttribute('data-theme', theme);
    box.classList.add('is-' + side);
    box.style.setProperty('--side', side);
    ['--c1', '--c2', '--c3', '--c4'].forEach(function (v, i) { box.style.setProperty(v, colors[i]); });

    // The panel.
    var panel = el('div', 'lva-panel');
    panel.id = 'lva-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', cfg.business || cfg.name || '');
    var head = el('div', 'lva-head');
    var face = el('span', 'lva-face');
    var photo = cfg.avatar && (/^https:\/\//.test(cfg.avatar) || cfg.avatar.indexOf(ctx.origin + '/') === 0) ? cfg.avatar : null;
    if (photo) { var img = el('img'); img.alt = ''; img.src = photo; face.appendChild(img); face.classList.add('is-photo'); }
    else { face.style.position = 'relative'; face.appendChild(orb()); }
    var who = el('div', 'lva-who');
    who.appendChild(el('strong', '', cfg.business || cfg.name));
    who.appendChild(el('small', '', cfg.name));
    var close = el('button', 'lva-x');
    close.type = 'button';
    close.setAttribute('aria-label', text.close || 'Close');
    close.innerHTML = ICONS.close;
    head.appendChild(face); head.appendChild(who); head.appendChild(close);

    var stage = el('div', 'lva-stage');
    var waves = [el('span', 'lva-wave'), el('span', 'lva-wave')];
    var bars = [];
    waves.forEach(function (w) { for (var b = 0; b < 7; b++) { var bar = el('i'); w.appendChild(bar); bars.push(bar); } });
    var orbBox = el('span', 'lva-stage-orb');
    var bigOrb = orb();
    var ring = el('i', 'lva-ring');
    orbBox.appendChild(ring);
    orbBox.appendChild(bigOrb);
    stage.appendChild(waves[0]);
    stage.appendChild(orbBox);
    stage.appendChild(waves[1]);
    var stateEl = el('p', 'lva-state', states.idle || '');
    stateEl.setAttribute('aria-live', 'polite');
    var lines = el('div', 'lva-lines');
    lines.setAttribute('aria-live', 'polite');
    var buttons = el('div', 'lva-buttons');
    var callBtn = el('button', 'lva-call');
    callBtn.type = 'button';
    var callIcon = el('span');
    callIcon.style.display = 'contents';
    callIcon.innerHTML = ICONS.mic;
    var callLabel = el('span', '', text.start);
    callBtn.appendChild(callIcon); callBtn.appendChild(callLabel);
    var muteBtn = el('button', 'lva-mute');
    muteBtn.type = 'button';
    muteBtn.hidden = true;
    muteBtn.setAttribute('aria-pressed', 'false');
    muteBtn.setAttribute('aria-label', text.mute || 'Mute');
    muteBtn.innerHTML = ICONS.micOff;
    buttons.appendChild(callBtn); buttons.appendChild(muteBtn);
    var note = el('p', 'lva-note', text.note);
    var powered = el('a', 'lva-powered', text.powered);
    powered.href = ctx.origin + '/';
    powered.target = '_blank';
    powered.rel = 'noopener';
    [head, stage, stateEl, lines, buttons, note, powered].forEach(function (n) { panel.appendChild(n); });

    // The launcher.
    var launch = el('button', 'lva-launch' + (text.label ? '' : ' is-round'));
    launch.type = 'button';
    launch.setAttribute('aria-expanded', 'false');
    launch.setAttribute('aria-controls', 'lva-panel');
    launch.setAttribute('aria-label', text.label || cfg.business || '');
    var mini = el('span', 'lva-mini');
    var miniOrb = orb();
    if (photo) { var mimg = el('img'); mimg.alt = ''; mimg.src = photo; mini.appendChild(mimg); mini.classList.add('is-photo'); }
    else mini.appendChild(miniOrb);
    mini.appendChild(el('i', 'lva-live-dot'));
    launch.appendChild(mini);
    if (text.label) launch.appendChild(el('span', '', text.label));

    box.appendChild(panel);
    box.appendChild(launch);
    root.appendChild(box);
    document.body.appendChild(host);

    // Open, close.
    var open = false;
    function setOpen(v) {
      open = v;
      box.classList.toggle('is-open', v);
      launch.setAttribute('aria-expanded', v ? 'true' : 'false');
      if (v) setTimeout(function () { callBtn.focus(); }, 60); else launch.focus();
    }
    launch.addEventListener('click', function () { setOpen(!open); });
    launch.addEventListener('pointerenter', function () { loadCall().catch(function () {}); }, { once: true });
    close.addEventListener('click', function () { setOpen(false); });
    root.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) setOpen(false); });

    // The call.
    var call = null, starting = false, muted = false, pendingError = '';
    function loadCall() {
      if (window.ListenAgentCall) return Promise.resolve();
      if (loadCall.p) return loadCall.p;
      loadCall.p = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = ctx.callScript;
        s.async = true;
        s.onload = function () { window.ListenAgentCall ? resolve() : reject(new Error('call')); };
        s.onerror = function () { loadCall.p = null; reject(new Error('call')); };
        (document.head || document.documentElement).appendChild(s);
      });
      return loadCall.p;
    }
    function setState(s, message) {
      var live = s === 'connecting' || s === 'listening' || s === 'speaking';
      if (s === 'error' && !message && pendingError) { message = pendingError; }
      if (s !== 'error') pendingError = '';
      stateEl.textContent = message || (s === 'mic' ? text.mic : states[s] || '');
      stateEl.classList.toggle('is-error', s === 'error' || s === 'mic');
      box.classList.toggle('is-live', live);
      box.classList.toggle('is-connecting', s === 'connecting');
      box.classList.toggle('is-speaking', s === 'speaking');
      callBtn.classList.toggle('is-live', live);
      callLabel.textContent = live ? text.end : text.start;
      callIcon.innerHTML = live ? ICONS.end : ICONS.mic;
      muteBtn.hidden = !live;
      if (!live) {
        call = null;
        muted = false;
        muteBtn.setAttribute('aria-pressed', 'false');
        level(0, 0);
      }
    }
    // The brand's waves: tallest in the middle of each group, each bar on its own beat.
    var shape = [0.35, 0.6, 0.85, 1, 0.8, 0.55, 0.3];
    function level(a, c) {
      a = Math.min(1, a || 0); c = Math.min(1, c || 0);
      var s = (1 + a * 0.14).toFixed(3);
      bigOrb.style.setProperty('--s', s);
      miniOrb.style.setProperty('--s', s);
      ring.style.setProperty('--mo', Math.min(0.9, c * 2.2).toFixed(2));
      ring.style.setProperty('--ms', (1 + c * 0.12).toFixed(3));
      if (!box.classList.contains('is-live')) { bars.forEach(function (b) { b.style.height = ''; }); return; }
      var v = Math.max(a, c * 0.55), t = Date.now() / 95;
      bars.forEach(function (b, i) {
        var k = i % 7, side = i < 7 ? k : 6 - k;
        var h = 5 + v * 54 * shape[side] * (0.55 + 0.45 * Math.abs(Math.sin(t + i * 1.7)));
        b.style.height = h.toFixed(1) + 'px';
      });
    }
    function line(t) {
      var n = el('div', 'lva-line is-' + t.role, t.text);
      n.dir = 'auto';
      lines.appendChild(n);
      while (lines.children.length > 6) lines.removeChild(lines.firstChild);
      lines.scrollTop = lines.scrollHeight;
    }
    callBtn.addEventListener('click', function () {
      if (call) { call.end(); return; }
      if (starting) return;
      starting = true;
      lines.innerHTML = '';
      setState('connecting');
      loadCall().then(function () {
        starting = false;
        call = window.ListenAgentCall.start({
          begin: function () {
            return fetch(ctx.origin + '/agent-api/calls', {
              method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
              body: JSON.stringify({ key: ctx.key, page_url: location.href, visitor: visitorId() }),
            }).then(function (r) { return r.json(); });
          },
          onState: function (s) { setState(s); },
          onTurn: line,
          onLevel: level,
          onError: function (msg) { pendingError = msg || ''; },
        });
      }, function () { starting = false; setState('error'); });
    });
    muteBtn.addEventListener('click', function () {
      muted = !muted;
      if (call) call.mute(muted);
      muteBtn.setAttribute('aria-pressed', muted ? 'true' : 'false');
      muteBtn.setAttribute('aria-label', muted ? (text.unmute || 'Unmute') : (text.mute || 'Mute'));
    });

    // The page can open it too ("Talk to our agent"), and start the call at once.
    window.ListenAgentWidget.open = function (o) {
      setOpen(true);
      if (o && o.call && !call && !starting) callBtn.click();
    };
    try { document.dispatchEvent(new CustomEvent('listen-agent:ready')); } catch (e) {}
  }

  window.ListenAgentWidget = { mount: mount };
})();
