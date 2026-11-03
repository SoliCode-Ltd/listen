/*
 * Listen Agents, for visitors (and the homepage's Agents section): the call in
 * the hero plays one dialect after another (the brand's waves follow its
 * voice), the steps follow the scroll over the real setup, the bento and the
 * channels play when they come into view, and "Talk to Sara now" opens a real
 * call with Listen's own agent (its call button, js/listen-agent.js). Without
 * motion, everything shows as it ends.
 */
(function () {
  'use strict';
  var D = window.LISTEN_AGENTS_LANDING || {};
  var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var canWatch = 'IntersectionObserver' in window;

  /* The bento and the channels: they play when seen; the call replays while it stays in view. */
  function reveals() {
    var parts = $$('[data-agl-reveal]');
    var count = function (root) {
      $$('[data-agl-count]', root).forEach(function (el) {
        var to = parseInt(el.getAttribute('data-agl-count'), 10) || 0, t0 = 0;
        if (reduce) { el.textContent = to; return; }
        (function step(t) {
          if (!t0) t0 = t;
          var p = Math.min(1, (t - t0) / 1300), e = 1 - Math.pow(1 - p, 3);
          el.textContent = Math.round(to * e);
          if (p < 1) requestAnimationFrame(step);
        })(performance.now());
      });
    };
    if (reduce || !canWatch) { parts.forEach(function (p) { p.classList.add('is-in'); }); return; }
    parts.forEach(function (p) {
      var timer = 0, seen = false;
      new IntersectionObserver(function (entries) {
        var visible = entries[0].isIntersecting;
        if (visible && !seen) { seen = true; p.classList.add('is-in'); count(p); }
        clearInterval(timer);
        if (visible && $('.agl-bento-call', p)) {
          // Replay the call every 11 seconds while it is on screen.
          timer = setInterval(function () {
            var call = $('.agl-bento-call', p);
            p.classList.remove('is-in');
            void call.offsetWidth;
            p.classList.add('is-in');
          }, 11000);
        }
      }, { threshold: 0.25 }).observe(p);
    });
  }

  /* Each part rises into place the first time it comes into view. */
  function rise() {
    var els = $$('[data-agl-rise]');
    if (!els.length || reduce || !canWatch) return;
    document.documentElement.classList.add('agl-js');
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add('is-risen');
        io.unobserve(e.target);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    els.forEach(function (el) { io.observe(el); });
  }

  /* Questions: the answer opens and closes smoothly instead of jumping. */
  function faq() {
    $$('.agl-faq-list details').forEach(function (d) {
      var summary = $('summary', d), body = $('p', d);
      if (!summary || !body || reduce || !body.animate) return;
      var ease = 'cubic-bezier(.22, 1, .36, 1)', busy = null;
      summary.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (busy) busy.cancel();
        var pad = getComputedStyle(body).paddingBottom;
        if (d.open) {
          d.classList.add('is-closing');
          busy = body.animate([{ height: body.offsetHeight + 'px', paddingBottom: pad, opacity: 1 }, { height: '0px', paddingBottom: '0px', opacity: 0 }], { duration: 260, easing: ease });
          busy.onfinish = function () { d.open = false; d.classList.remove('is-closing'); busy = null; };
          busy.oncancel = function () { d.classList.remove('is-closing'); };
        } else {
          d.open = true;
          busy = body.animate([{ height: '0px', paddingBottom: '0px', opacity: 0 }, { height: body.offsetHeight + 'px', paddingBottom: pad, opacity: 1 }], { duration: 340, easing: ease });
          busy.onfinish = function () { busy = null; };
        }
      });
    });
  }

  /* How it works: the step in the middle of the screen shows its screen. */
  function how() {
    var box = $('[data-agl-how]');
    if (!box) return;
    var steps = $$('[data-agl-how-step]', box), shots = $$('[data-agl-how-shot]', box);
    var dots = $$('.agl-how-dots i', box), label = $('[data-agl-how-label]', box);
    function show(n) {
      steps.forEach(function (s, i) { s.classList.toggle('is-on', i === n); });
      shots.forEach(function (s, i) { s.classList.toggle('is-on', i === n); });
      dots.forEach(function (d, i) { d.classList.toggle('is-on', i === n); });
      if (label && steps[n]) label.textContent = steps[n].getAttribute('data-label');
    }
    if (!canWatch) return;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) show(+e.target.getAttribute('data-agl-how-step'));
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    steps.forEach(function (s) { io.observe(s); });
  }

  /* "Talk to Sara now": shown once Listen's own agent is on the page; it opens its call. */
  function live() {
    var buttons = $$('[data-agl-live]');
    if (!buttons.length) return;
    function ready() {
      buttons.forEach(function (b) { b.hidden = false; });
      $$('[data-agl-live-note]').forEach(function (n) { n.hidden = false; });
      $$('[data-agl-watch]').forEach(function (w) { w.hidden = true; });
    }
    if (window.ListenAgentWidget && window.ListenAgentWidget.open) ready();
    else document.addEventListener('listen-agent:ready', ready);
    buttons.forEach(function (b) {
      b.addEventListener('click', function () {
        if (window.ListenAgentWidget && window.ListenAgentWidget.open) window.ListenAgentWidget.open({ call: true });
      });
    });
  }

  /* The call in the hero. */
  function call() {
    var box = $('[data-agl-call]');
    if (!box || !D.scenes || !D.scenes.length) return;
    var lines = $('[data-agl-lines]', box), chip = $('[data-agl-chip]', box), stage = $('[data-agl-stage]', box);
    var clock = $('[data-agl-clock]', box), face = $('[data-agl-face]', box);
    var agentEl = $('[data-agl-agent]', box), bizEl = $('[data-agl-business]', box);
    var pills = $$('[data-agl-scene]', box);
    // shape: how far each bar moves with the voice; calm: its height at rest, like the brand mark.
    var bars = $$('.agl-wave i', box), shape = [0.35, 0.6, 0.85, 1, 0.8, 0.55, 0.3], calm = [0.3, 0.55, 0.8, 1, 0.8, 0.55, 0.3];
    var scene = 0, timers = [], inView = true, waiting = false, startedAt = Date.now(), level = 0, speaking = false, raf = 0;

    function later(ms, fn) { timers.push(setTimeout(fn, ms)); }
    function clear() { timers.forEach(clearTimeout); timers = []; }
    function speak(on) { speaking = on; stage.classList.toggle('is-speaking', on); if (on) stage.classList.add('is-playing'); }

    function frame(t) {
      raf = requestAnimationFrame(frame);
      var target = speaking ? 0.32 + 0.3 * Math.abs(Math.sin(t / 150)) * (0.65 + 0.35 * Math.sin(t / 610)) : 0;
      level += (target - level) * 0.2;
      stage.__vpLevel = level;
      if (!speaking && level < 0.01) stage.classList.remove('is-playing');
      bars.forEach(function (b, i) {
        var k = i % 7, side = i < 7 ? k : 6 - k;
        var rest = (5 + 16 * calm[k]) * (0.86 + 0.14 * Math.sin(t / 800 + i * 0.8));
        var h = rest + level * 76 * shape[side] * (0.55 + 0.45 * Math.abs(Math.sin(t / 95 + i * 1.7)));
        b.style.height = Math.min(84, h).toFixed(1) + 'px';
      });
      var s = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));
      clock.textContent = ('0' + Math.floor(s / 60)).slice(-2) + ':' + ('0' + (s % 60)).slice(-2);
    }

    function lineEl(line) {
      var p = document.createElement('p');
      p.className = 'agl-line is-' + line.role;
      p.dir = 'auto';
      var span = document.createElement('span');
      p.appendChild(span);
      if (line.note) {
        var small = document.createElement('small');
        small.textContent = line.note;
        p.appendChild(small);
      }
      return p;
    }

    function type(span, text) {
      var words = text.split(' '), i = 0;
      (function next() {
        i++;
        span.textContent = words.slice(0, i).join(' ');
        if (i < words.length) later(85 + Math.random() * 70, next);
      })();
    }

    // Another dialect: the call on screen fades out first, then the next one plays.
    function show(n, animate) {
      if (animate && lines.children.length) {
        clear();
        speak(false);
        box.classList.add('is-switching');
        later(240, function () { box.classList.remove('is-switching'); paint(n, animate); });
        return;
      }
      paint(n, animate);
    }

    function paint(n, animate) {
      clear();
      scene = n;
      waiting = false;
      var sc = D.scenes[n];
      pills.forEach(function (p, i) { p.classList.toggle('is-on', i === n); p.setAttribute('aria-pressed', i === n ? 'true' : 'false'); });
      if (face) face.src = sc.face;
      agentEl.textContent = (agentEl.getAttribute('data-pattern') || ':name').replace(':name', sc.agent);
      bizEl.textContent = sc.business;
      $('span', chip).textContent = sc.chip;
      lines.innerHTML = '';
      startedAt = Date.now();
      if (!animate) {
        sc.lines.forEach(function (l) { var p = lineEl(l); $('span', p).textContent = l.text; p.classList.add('is-on'); lines.appendChild(p); });
        chip.classList.add('is-on');
        return;
      }
      chip.classList.remove('is-on');
      var t = 450;
      sc.lines.forEach(function (l) {
        var lasts = Math.max(1200, l.text.split(' ').length * 125);
        later(t, function () {
          var p = lineEl(l);
          lines.appendChild(p);
          requestAnimationFrame(function () { p.classList.add('is-on'); });
          speak(l.role === 'agent');
          type($('span', p), l.text);
        });
        later(t + lasts, function () { speak(false); });
        t += lasts + (l.role === 'agent' ? 650 : 450);
      });
      later(t, function () { chip.classList.add('is-on'); });
      later(t + 2800, function () {
        if (inView && !document.hidden) show((scene + 1) % D.scenes.length, true);
        else waiting = true;
      });
    }

    pills.forEach(function (p, i) { p.addEventListener('click', function () { show(i, !reduce); }); });
    if (reduce) return;

    if (canWatch) {
      new IntersectionObserver(function (entries) {
        inView = entries[0].isIntersecting;
        if (inView && !raf) raf = requestAnimationFrame(frame);
        if (!inView && raf) { cancelAnimationFrame(raf); raf = 0; }
        if (inView && waiting) show((scene + 1) % D.scenes.length, true);
      }, { threshold: 0.2 }).observe(box);
    } else {
      raf = requestAnimationFrame(frame);
    }
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && inView && waiting) show((scene + 1) % D.scenes.length, true);
    });
    show(0, true);
  }

  /* "+142 more dialects": the full list opens smoothly under the calls. */
  function more() {
    var btn = $('[data-agl-more]'), list = $('[data-agl-all]');
    if (!btn || !list) return;
    btn.addEventListener('click', function () {
      var open = list.hidden;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.classList.toggle('is-on', open);
      if (open) {
        list.hidden = false;
        if (!reduce && list.animate) list.animate([{ opacity: 0, transform: 'translateY(-6px)', maxHeight: '0px' }, { opacity: 1, transform: 'none', maxHeight: list.scrollHeight + 'px' }], { duration: 420, easing: 'cubic-bezier(.22, 1, .36, 1)' });
      } else if (!reduce && list.animate) {
        var a = list.animate([{ opacity: 1, maxHeight: list.scrollHeight + 'px' }, { opacity: 0, maxHeight: '0px' }], { duration: 260, easing: 'ease' });
        a.onfinish = function () { list.hidden = true; };
      } else list.hidden = true;
    });
  }

  function boot() {
    rise();
    faq();
    more();
    reveals();
    how();
    live();
    call();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
