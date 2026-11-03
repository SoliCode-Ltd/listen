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

  /*
   * A real call, recorded live (components/agents/real-call): the caller's lines appear, the agent's
   * own voice plays with its words lit as it says them, the orb follows its loudness (the stage holds
   * it for js/listen-orbs.js), and what the business is left with rises at the end. One clip at a time
   * on the page: another player starting stops it, and it stops them.
   */
  function realCall(box) {
    var raw = $('[data-agl-real-data]', box);
    var data = null;
    try { data = JSON.parse(raw ? raw.textContent : 'null'); } catch (e) { data = null; }
    if (!data || !data.order || !data.order.length) return;
    var key = data.order[0];
    var lines = $('[data-agl-real-lines]', box), play = $('[data-agl-real-play]', box), label = $('[data-agl-real-label]', box);
    var bar = $('[data-agl-real-bar]', box), face = $('[data-agl-real-face]', box), name = $('[data-agl-real-name]', box);
    var dialect = $('[data-agl-real-dialect]', box), biz = $('[data-agl-real-biz]', box), rTitle = $('[data-agl-real-result-title]', box), rText = $('[data-agl-real-result-text]', box);
    var clips = {}, levels = {}, run = null, frame = 0, photo = $('[data-agl-real-photo]', box);
    // The voice's loudness, for the orb (it reads the stage) and for the face's rings (a CSS value).
    function level(v) { box.__vpLevel = v; if (photo) box.style.setProperty('--agl-lvl', v.toFixed(3)); }

    function call() { return data.calls[key]; }
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
    function setLabel(which) {
      label.textContent = play.getAttribute('data-label-' + which) || label.textContent;
      play.setAttribute('aria-pressed', which === 'pause' ? 'true' : 'false');
    }

    // A line as the stage shows it: the words (each its own span for an agent line) and, on the
    // English pages, what they mean.
    function lineEl(turn, words) {
      var li = el('li', 'agl-real-line is-' + turn.role);
      var p = el('p');
      p.setAttribute('dir', call().dir || 'rtl'); p.setAttribute('lang', call().lang || 'ar');
      if (words) {
        turn.text.split(/(\s+)/).forEach(function (part) {
          if (!part) return;
          if (/^\s+$/.test(part)) { p.appendChild(document.createTextNode(part)); return; }
          p.appendChild(el('span', 'agl-real-w', part));
        });
      } else p.textContent = turn.text;
      li.appendChild(p);
      if (turn.gloss) li.appendChild(el('small', null, turn.gloss));
      return li;
    }
    // Before it plays: the first exchange, as it was said.
    function preview() {
      lines.textContent = '';
      call().turns.slice(0, data.rest || 2).forEach(function (t) { var li = lineEl(t, false); li.classList.add('is-shown'); lines.appendChild(li); });
      lines.scrollTop = 0; lines.classList.remove('is-full');
      face.src = call().avatar; name.textContent = call().agent; dialect.textContent = call().dialect;
      if (biz) biz.textContent = call().business || '';
      if (photo) photo.src = call().avatar;
      level(0);
      rTitle.textContent = call().result[0]; rText.textContent = call().result[1];
      bar.style.width = '0%';
      box.classList.remove('is-playing', 'is-done', 'is-voicing');
    }

    function clip(src) {
      if (!clips[src]) { var a = new Audio(); a.preload = 'auto'; a.src = src; clips[src] = a; }
      return clips[src];
    }
    // Each clip's loudness, decoded once, in 10 ms steps (what the orb follows).
    function analyse(src) {
      if (levels[src] || !window.fetch || !(window.AudioContext || window.webkitAudioContext)) return;
      levels[src] = [];
      fetch(src, { credentials: 'same-origin' }).then(function (r) { return r.arrayBuffer(); }).then(function (buf) {
        var Ctx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
        var ctx = Ctx ? new Ctx(1, 2, 24000) : new (window.AudioContext || window.webkitAudioContext)();
        return new Promise(function (ok, bad) { ctx.decodeAudioData(buf, ok, bad); });
      }).then(function (decoded) {
        var d = decoded.getChannelData(0), hop = Math.max(1, Math.round(decoded.sampleRate * 0.01)), out = [];
        for (var i = 0; i < d.length; i += hop) {
          var sum = 0, n = Math.min(hop, d.length - i);
          for (var j = 0; j < n; j++) sum += d[i + j] * d[i + j];
          out.push(Math.min(1, Math.sqrt(sum / n) * 4.2));
        }
        levels[src] = out;
      }).catch(function () {});
    }
    function durations(turns) {
      return Promise.all(turns.map(function (t) {
        if (t.role !== 'agent') return Promise.resolve(gap(t));
        // A line shown in words only (the English pages): as long as it takes to say it.
        if (!t.audio) return Promise.resolve(Math.max(2.4, Math.min(7, t.text.length * 0.058)));
        var a = clip(t.audio);
        analyse(t.audio);
        if (a.readyState >= 1 && isFinite(a.duration)) return Promise.resolve(a.duration);
        return new Promise(function (ok) {
          var done = function () { ok(isFinite(a.duration) && a.duration > 0 ? a.duration : 6); };
          a.addEventListener('loadedmetadata', done, { once: true });
          a.addEventListener('error', function () { ok(6); }, { once: true });
          setTimeout(function () { ok(isFinite(a.duration) && a.duration > 0 ? a.duration : 6); }, 4000);
        });
      }));
    }
    // A caller line stays as long as it takes to read.
    function gap(t) { return Math.max(1.1, Math.min(2.6, t.text.length * 0.032)); }

    function stopFrame() { cancelAnimationFrame(frame); frame = 0; }
    function halt(reset) {
      if (run) {
        run.stopped = true;
        clearTimeout(run.timer);
        if (run.audio) { run.audio.pause(); run.audio.currentTime = 0; }
      }
      run = null;
      stopFrame();
      box.classList.remove('is-voicing');
      level(0);
      setLabel('play');
      if (reset) preview();
    }

    function start() {
      var turns = call().turns;
      document.dispatchEvent(new CustomEvent('listen:audio-play', { detail: { source: box } }));
      play.disabled = true;
      durations(turns).then(function (lengths) {
        play.disabled = false;
        var total = lengths.reduce(function (s, v) { return s + v; }, 0) || 1;
        run = { i: -1, lengths: lengths, total: total, before: 0, audio: null, timer: 0, stopped: false, paused: false, started: 0 };
        lines.textContent = ''; lines.classList.remove('is-full');
        box.classList.add('is-playing');
        box.classList.remove('is-done');
        setLabel('pause');
        next();
      });
    }
    function next() {
      if (!run || run.stopped) return;
      if (run.i >= 0) run.before += run.lengths[run.i];
      run.i++;
      var turns = call().turns;
      if (run.i >= turns.length) { finish(); return; }
      var t = turns[run.i];
      var li = lineEl(t, t.role === 'agent');
      lines.appendChild(li);
      run.words = []; run.starts = []; run.audio = null;
      requestAnimationFrame(function () { li.classList.add('is-shown'); });
      // The newest line stays in sight; older ones move up under the fade.
      requestAnimationFrame(function () { lines.scrollTop = lines.scrollHeight; lines.classList.toggle('is-full', lines.scrollHeight > lines.clientHeight + 4); });
      var words = $$('.agl-real-w', li);
      // Each word lights when it is said: the line's time shared out by the words' lengths.
      // A number is said digit by digit, so it takes as long as its digits' words.
      var weights = words.map(function (w) {
        var t = w.textContent, digits = (t.match(/\d/g) || []).length;
        return (digits >= 3 ? digits * 4.6 : t.length) + (/[،,.؟?!]$/.test(t) ? 4 : 1);
      });
      var sum = weights.reduce(function (s, v) { return s + v; }, 0) || 1, acc = 0;
      run.words = words;
      run.starts = weights.map(function (v) { var at = acc / sum; acc += v; return at; });
      if (t.role !== 'agent' || !t.audio) {
        // A caller's line, or an agent's line in words only: timed, with the orb moving as if it spoke.
        run.started = performance.now();
        run.wait = run.lengths[run.i] * 1000;
        run.timer = setTimeout(function () {
          if (t.role === 'agent') { words.forEach(function (w) { w.classList.add('is-said'); }); box.classList.remove('is-voicing'); level(0); }
          next();
        }, run.wait);
        if (t.role === 'agent') { box.classList.add('is-voicing'); if (window.ListenOrbs && window.ListenOrbs.wake) window.ListenOrbs.wake(); }
        tick();
        return;
      }
      var a = clip(t.audio);
      run.audio = a;
      a.currentTime = 0;
      a.onended = function () { box.classList.remove('is-voicing'); level(0); words.forEach(function (w) { w.classList.add('is-said'); }); next(); };
      var p = a.play();
      if (p && p.catch) p.catch(function () { halt(true); });
      box.classList.add('is-voicing');
      if (window.ListenOrbs && window.ListenOrbs.wake) window.ListenOrbs.wake();
      tick();
    }
    function tick() {
      stopFrame();
      (function step() {
        if (!run || run.stopped) return;
        var t = call().turns[run.i], into = 0;
        if (t && t.role === 'agent' && run.audio) {
          var a = run.audio, d = run.lengths[run.i] || a.duration || 1;
          into = Math.min(d, a.currentTime || 0);
          var share = into / d;
          (run.words || []).forEach(function (w, k) { if (run.starts[k] <= share * 1.04) w.classList.add('is-said'); });
          var lv = levels[t.audio] || [];
          level(lv.length ? (lv[Math.min(lv.length - 1, Math.floor(into * 100))] || 0) : 0.35);
        } else if (run.wait) {
          into = Math.min(run.lengths[run.i], (performance.now() - run.started) / 1000);
          if (t && t.role === 'agent') {
            var part = into / (run.lengths[run.i] || 1);
            (run.words || []).forEach(function (w, k) { if (run.starts[k] <= part * 1.04) w.classList.add('is-said'); });
            // A voice-like rise and fall for the orb while the words go by.
            var now = performance.now() / 1000;
            level(Math.max(0, 0.32 + 0.22 * Math.sin(now * 7.3) * Math.sin(now * 2.1 + 1) + 0.08 * Math.sin(now * 19)));
          }
        }
        bar.style.width = Math.min(100, (run.before + into) / run.total * 100).toFixed(2) + '%';
        if (!run.paused) frame = requestAnimationFrame(step);
      })();
    }
    function finish() {
      stopFrame();
      bar.style.width = '100%';
      box.classList.remove('is-voicing');
      box.classList.add('is-done');
      level(0);
      run = null;
      setLabel('again');
    }
    function pause() {
      if (!run) return;
      run.paused = true;
      if (run.audio && !run.audio.paused) run.audio.pause();
      if (run.timer) { clearTimeout(run.timer); run.left = Math.max(0, run.wait - (performance.now() - run.started)); run.timer = 0; }
      box.classList.remove('is-voicing');
      stopFrame();
      setLabel('play');
    }
    function resume() {
      if (!run) return;
      run.paused = false;
      document.dispatchEvent(new CustomEvent('listen:audio-play', { detail: { source: box } }));
      setLabel('pause');
      var t = call().turns[run.i];
      if (t && t.role === 'agent' && run.audio) { run.audio.play(); box.classList.add('is-voicing'); }
      else if (run.left != null) {
        run.started = performance.now() - (run.wait - run.left);
        run.timer = setTimeout(function () {
          if (t && t.role === 'agent') { (run.words || []).forEach(function (w) { w.classList.add('is-said'); }); box.classList.remove('is-voicing'); level(0); }
          next();
        }, run.left);
        run.left = null;
        if (t && t.role === 'agent') box.classList.add('is-voicing');
      }
      tick();
    }

    play.addEventListener('click', function () {
      if (!run) { start(); return; }
      if (run.paused) resume(); else pause();
    });
    $$('[data-agl-real-pick]', box).forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-agl-real-pick');
        if (!data.calls[k]) return;
        $$('[data-agl-real-pick]', box).forEach(function (o) { o.setAttribute('aria-checked', o === b ? 'true' : 'false'); });
        var wasPlaying = run && !run.paused;
        halt(false);
        key = k;
        preview();
        if (wasPlaying) start();
      });
    });
    // Another player on the page started: this one stops (and it stops them, above).
    document.addEventListener('listen:audio-play', function (e) { if (e.detail && e.detail.source !== box && run && !run.paused) pause(); });
    document.addEventListener('visibilitychange', function () { if (document.hidden && run && !run.paused) pause(); });
    preview();
  }

  function boot() {
    $$('[data-agl-real]').forEach(realCall);
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
