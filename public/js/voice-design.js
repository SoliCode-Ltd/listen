/*
 * Voice Design (components/voice-design/studio.blade.php), on the dashboard's own section and
 * as a tab of the demo. The description (with characters to start from and "Write it for me"),
 * the line, the shaping sliders and what a design costs; the stage, which plays the voices the
 * admin chose to show around its orb, then works through a design step by step, then lays out
 * the takes to compare and the one to keep; and the member's library of kept voices.
 *
 * One player plays everything. While it plays, the orb of what is playing ripples with the
 * voice: the stage, a take or a library card is a [data-vs-cast] whose live loudness
 * (__vpLevel, 0..1) the orb script (js/listen-orbs.js) follows.
 *
 * Events: listen:designed-voice ({id, key, name, language, url} or {key, removed: true}) when a
 * voice is kept or deleted, so the studio's voice list follows; listen:demo-quota (the demo's
 * quota) after a demo design.
 */
(function () {
  'use strict';

  var TAKE_ORBS = ['listen', 'emotions', 'english'];
  // As App\Support\VoiceDesign::VOICE_ORBS, for a voice the server sent without its orb.
  var LIBRARY_ORBS = ['arabic', 'emotions', 'english', 'dialogue', 'longform', 'creator', 'pro', 'places'];
  var ICON_PLAY = '<span class="vd-play-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg></span><span class="vd-pause-icon" aria-hidden="true"><i></i><i></i></span>';
  var ICON_STAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/></svg>';
  var ICON_TRASH = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 7h15M9.5 7V4.8h5V7M6.5 7l.8 12.2h9.4L17.5 7M10.2 10.5v5.5M13.8 10.5v5.5"/></svg>';

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmt(text, vars) {
    return String(text || '').replace(/:(\w+)/g, function (m, k) { return vars && Object.prototype.hasOwnProperty.call(vars, k) ? vars[k] : m; });
  }
  function clock(seconds) {
    var s = Math.max(0, Math.round(Number(seconds) || 0));
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
  }

  function init(root) {
    var configEl = root.querySelector('[data-vd-config]');
    if (!configEl || root.__vd) return;
    root.__vd = true;
    var cfg = {};
    try { cfg = JSON.parse(configEl.textContent || '{}'); } catch (e) { return; }
    var studio = cfg.studio || {};
    var t = cfg.i18n || {};
    var csrf = cfg.csrf || '';
    var palettes = cfg.palettes || {};
    function q(selector) { return root.querySelector(selector); }

    var form = q('[data-vd-form]');
    var stage = q('[data-vd-stage]');
    var idleView = q('[data-vd-idle]');
    var workView = q('[data-vd-work]');
    var resultsView = q('[data-vd-results]');
    var takesList = q('[data-vd-takes]');
    var keepForm = q('[data-vd-keep]');
    var alertBox = q('[data-vd-alert]');
    var libraryGrid = q('[data-vd-library-grid]');
    var examples = studio.examples || [];
    var voices = (studio.voices || []).slice();
    var lastExample = null;
    var chosen = null;

    function orbHtml(name) {
      var c = palettes[name] || palettes.listen || ['#24138A', '#7659FA', '#C9BEFF', '#FFE3D3'];
      return '<span class="orb" data-orb="' + esc(name) + '" data-orb-colors="' + esc(c.join(',')) + '" style="--o0: ' + c[0] + '; --o1: ' + c[1] + '; --o2: ' + c[2] + '; --o3: ' + c[3] + ';" aria-hidden="true"><canvas></canvas></span>';
    }
    function drawOrbs(container) {
      if (window.ListenOrbs && container) window.ListenOrbs.scan(container);
    }

    // ---------- One player ----------
    var audio = new Audio();
    audio.preload = 'none';
    var now = null;
    var frame = 0;
    var levels = null;

    function stop() {
      cancelAnimationFrame(frame);
      if (!audio.paused) audio.pause();
      var was = now;
      now = null;
      if (!was) return;
      if (was.button) { was.button.classList.remove('is-playing'); was.button.setAttribute('aria-label', t.play || ''); }
      if (was.cast) { was.cast.classList.remove('is-voicing'); was.cast.__vpLevel = 0; }
      if (was.bar) was.bar.style.width = '0%';
      if (was.onStop) was.onStop();
    }
    function play(url, opts) {
      opts = opts || {};
      var same = now && now.url === url && now.button === opts.button;
      stop();
      if (same || !url) return;
      now = { url: url, button: opts.button, cast: opts.cast, bar: opts.bar, onStop: opts.onStop, onEnd: opts.onEnd };
      levels = null;
      if (window.ListenVoiceAnalyse) {
        Promise.resolve(window.ListenVoiceAnalyse(url)).then(function (info) { if (now && now.url === url) levels = info; }).catch(function () {});
      }
      audio.src = url;
      var started = audio.play();
      if (started && started.catch) started.catch(function () { stop(); });
      if (opts.button) { opts.button.classList.add('is-playing'); opts.button.setAttribute('aria-label', t.pause || ''); }
      if (opts.cast) { opts.cast.classList.add('is-voicing'); opts.cast.__vpLevel = 0; }
      document.dispatchEvent(new CustomEvent('listen:audio-play', { detail: { source: 'voice-design' } }));
      (function step() {
        if (!now) return;
        if (now.cast) {
          var target = 0;
          if (!audio.paused) {
            if (levels && levels.rms && levels.rms.length) {
              var f = Math.min(levels.rms.length - 1, Math.max(0, Math.round(audio.currentTime * 100)));
              target = Math.min(1, Math.sqrt((levels.rms[f] || 0) / (levels.loud || 1)));
            } else {
              target = 0.35 + 0.2 * Math.abs(Math.sin(audio.currentTime * 7));
            }
          }
          now.cast.__vpLevel = (now.cast.__vpLevel || 0) + (target - (now.cast.__vpLevel || 0)) * 0.25;
        }
        if (now.bar && audio.duration) now.bar.style.width = Math.min(100, (audio.currentTime / audio.duration) * 100) + '%';
        frame = requestAnimationFrame(step);
      })();
    }
    audio.addEventListener('ended', function () {
      var next = now && now.onEnd;
      stop();
      if (next) next();
    });
    audio.addEventListener('error', stop);
    // Another player on the page starting stops this one.
    document.addEventListener('listen:audio-play', function (event) {
      if (event.detail && event.detail.source !== 'voice-design' && now && !audio.paused) stop();
    });

    // ---------- Requests ----------
    function send(url, method, body, retried) {
      return fetch(url, {
        method: method,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
        body: body ? JSON.stringify(body) : undefined
      }).then(function (response) {
        // An old page's form token: fetch a fresh one and send once more.
        if (response.status === 419 && !retried && cfg.csrfUrl) {
          return fetch(cfg.csrfUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { csrf = j.token || csrf; return send(url, method, body, true); });
        }
        return response.json().catch(function () { return {}; }).then(function (json) {
          if (!response.ok || json.ok === false) {
            var error = new Error(json.message || t.our_side);
            error.payload = json;
            throw error;
          }
          return json;
        });
      }, function () { throw new Error(t.network); });
    }

    function showAlert(message, ok, stay) {
      if (!alertBox) return;
      alertBox.textContent = message || '';
      alertBox.classList.toggle('is-ok', !!ok);
      alertBox.hidden = !message;
      if (message && !stay) alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ---------- The stage ----------
    var nowLine = q('[data-vd-now]');
    var idleHint = nowLine ? nowLine.textContent : '';
    var cloud = root.querySelectorAll('[data-vd-cloud]');
    var showButton = q('[data-vd-showcase]');
    var progress = q('[data-vd-progress]');
    var steps = root.querySelectorAll('[data-vd-step]');
    var stepTimers = [];

    function setStage(state) {
      stage.setAttribute('data-state', state);
      if (idleView) idleView.hidden = state !== 'idle';
      if (workView) workView.hidden = state !== 'work';
      if (resultsView) resultsView.hidden = state !== 'results';
    }
    // One voice of the card after another (chain), or just the one asked for.
    function speakShowcase(i, chain) {
      var list = studio.showcase || [];
      if (!list.length) return;
      var item = list[i % list.length];
      play(item.url, {
        button: showButton,
        cast: stage,
        onStop: function () {
          cloud.forEach(function (chip) { chip.classList.remove('is-speaking'); });
          if (nowLine) nowLine.textContent = idleHint;
        },
        onEnd: function () { if (chain !== false && i + 1 < list.length) speakShowcase(i + 1, true); }
      });
      // After play(): starting it stops the voice before, whose onStop dims every chip.
      cloud.forEach(function (chip) { chip.classList.toggle('is-speaking', chip.getAttribute('data-vd-cloud') === item.name); });
      if (nowLine) nowLine.textContent = fmt(t.now_speaking, { name: item.name });
    }
    if (showButton) {
      showButton.addEventListener('click', function () {
        if (now && now.button === showButton) stop();
        else speakShowcase(0, true);
      });
    }
    // A character picked in the form says its line on the stage, while nothing else is showing there.
    function previewExample(key) {
      var at = (studio.showcase || []).map(function (v) { return v.key; }).indexOf(key);
      if (at < 0 || stage.getAttribute('data-state') !== 'idle') return;
      speakShowcase(at, false);
    }

    function working(on) {
      stepTimers.forEach(clearTimeout);
      stepTimers = [];
      steps.forEach(function (s) { s.classList.remove('is-active', 'is-done'); });
      if (progress) progress.style.width = '0%';
      if (!on) return;
      stop();
      setStage('work');
      // The design's steps, paced as a design usually takes: reading, shaping, recording.
      [[0, 0, 8], [2500, 1, 38], [7500, 2, 72], [15000, 2, 90]].forEach(function (s) {
        stepTimers.push(setTimeout(function () {
          steps.forEach(function (el, n) {
            el.classList.toggle('is-done', n < s[1]);
            el.classList.toggle('is-active', n === s[1]);
          });
          if (progress) progress.style.width = s[2] + '%';
        }, s[0]));
      });
      if (window.matchMedia && window.matchMedia('(max-width: 1100px)').matches) stage.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ---------- The takes ----------
    function renderTakes(takes, line, response) {
      takesList.innerHTML = '';
      chosen = null;
      if (keepForm) keepForm.hidden = true;
      var quote = q('[data-vd-line-quote]');
      if (quote) quote.textContent = line || '';
      var canKeep = !studio.demo || !!(response && response.can_keep);
      var several = takes.length > 1;
      takes.forEach(function (take, n) {
        var orb = n === 0 && lastExample ? lastExample.orb : TAKE_ORBS[n % TAKE_ORBS.length];
        // With more than one take, the first follows the description as written: it is the one we recommend.
        var pick = several && (take.take || n + 1) === 1;
        var li = document.createElement('li');
        li.className = 'vd-take' + (pick ? ' is-pick' : '');
        li.setAttribute('data-vs-cast', '');
        li.style.setProperty('--n', String(n));
        li.innerHTML = orbHtml(orb)
          + '<div class="vd-take-main"><div class="vd-take-head"><strong class="vd-take-name">' + esc(fmt(t.take, { n: take.take || n + 1 })) + '</strong>'
          + (pick ? '<span class="vd-take-badge">' + ICON_STAR + esc(t.take_recommended) + '</span>' : '') + '</div>'
          + (several && take.note ? '<p class="vd-take-note">' + esc(take.note) + '</p>' : '')
          + (take.line ? '' : '<p class="vd-take-note is-sample">' + esc(t.take_sample) + '</p>')
          + '<div class="vd-take-player"><button type="button" class="vd-play" aria-label="' + esc(t.play) + '">' + ICON_PLAY + '</button>'
          + '<div class="vd-take-bar"><i></i></div>'
          + (take.seconds ? '<span class="vd-time" dir="ltr">' + clock(take.seconds) + '</span>' : '') + '</div></div>'
          + (canKeep ? '<button type="button" class="btn vd-take-keep">' + esc(t.keep) + '</button>' : '');
        var playButton = li.querySelector('.vd-play');
        playButton.addEventListener('click', function () { play(take.url, { button: playButton, cast: li, bar: li.querySelector('.vd-take-bar i') }); });
        var keepButton = li.querySelector('.vd-take-keep');
        if (keepButton) keepButton.addEventListener('click', function () { choose(take, li); });
        takesList.appendChild(li);
      });
      drawOrbs(takesList);
      var upsell = q('[data-vd-upsell]');
      if (upsell) upsell.hidden = canKeep;
      setStage('results');
      // The first take plays at once: the member asked to hear it.
      var first = takesList.querySelector('.vd-take');
      if (first && takes[0]) play(takes[0].url, { button: first.querySelector('.vd-play'), cast: first, bar: first.querySelector('.vd-take-bar i') });
    }
    function choose(take, li) {
      chosen = take;
      takesList.querySelectorAll('.vd-take').forEach(function (el) { el.classList.toggle('is-chosen', el === li); });
      if (!keepForm) return;
      keepForm.hidden = false;
      var name = keepForm.querySelector('[data-vd-name]');
      if (name && !name.value && lastExample) name.value = lastExample.name;
      if (name) { name.focus(); name.select(); }
    }
    if (keepForm) {
      keepForm.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!chosen) return;
        var input = keepForm.querySelector('[data-vd-name]');
        var name = String(input ? input.value : '').trim();
        if (name.length < 2) { if (input) input.focus(); return; }
        var save = keepForm.querySelector('[data-vd-save]');
        if (save) { save.disabled = true; save.textContent = t.saving; }
        send(String(studio.urls.keep).replace('__ID__', encodeURIComponent(chosen.id)), 'POST', { name: name }).then(function (res) {
          stop();
          if (input) input.value = '';
          keepForm.hidden = true;
          setStage('idle');
          keptMonth = res.kept_month !== undefined ? Number(res.kept_month) : keptMonth + 1;
          var kept = res.voice ? addToLibrary(res.voice, true) : null;
          showAlert(res.message || fmt(t.kept, { name: name }), true, !!kept);
          // Down to the voice just kept, in the library under the studio.
          if (kept) kept.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }).catch(function (error) {
          showAlert(error.message);
        }).then(function () {
          if (save) { save.disabled = false; save.textContent = t.save; }
        });
      });
      var cancel = keepForm.querySelector('[data-vd-keep-cancel]');
      if (cancel) cancel.addEventListener('click', function () {
        keepForm.hidden = true;
        chosen = null;
        takesList.querySelectorAll('.vd-take').forEach(function (el) { el.classList.remove('is-chosen'); });
      });
    }
    var again = q('[data-vd-again]');
    if (again) again.addEventListener('click', function () {
      stop();
      setStage('idle');
      var field = q('[data-vd-description]');
      if (field) field.focus();
    });

    // ---------- The library ----------
    function libraryCount() {
      var empty = q('[data-vd-library-empty]');
      if (empty) empty.hidden = voices.length > 0;
      refreshLimits();
    }
    // What is left: the month's new voices in the section's pill and, when no more can be kept now
    // (the month's are used, or the library is full), a notice in place of the design button, as
    // the server refuses a design then (App\Support\VoiceDesign::keepBlocker). Deleting a voice
    // makes room in the library, not in the month.
    var keptMonth = Number(studio.kept_month || 0);
    function refreshLimits() {
      if (studio.demo || !studio.allowed) return;
      var monthly = Number(studio.monthly || 0);
      var kind = voices.length >= Number(studio.max_voices || 0) ? 'full' : (monthly > 0 && keptMonth >= monthly ? 'month' : '');
      (root.closest('[data-dashboard-section]') || root).querySelectorAll('[data-vd-month-left]').forEach(function (pill) {
        pill.textContent = fmt(t.month_left, { left: Math.max(0, monthly - keptMonth) });
      });
      var box = q('[data-vd-limit]');
      if (box) {
        box.hidden = !kind;
        if (kind) {
          box.querySelector('[data-vd-limit-title]').textContent = t['limit_' + kind + '_title'];
          box.querySelector('[data-vd-limit-text]').textContent = t['limit_' + kind + '_text'];
        }
      }
      var button = q('[data-vd-submit]');
      if (button) { button.hidden = !!kind; button.disabled = !!kind; }
      var summary = q('[data-vd-summary]');
      if (summary) summary.hidden = !!kind;
    }
    function card(voice, fresh) {
      var el = document.createElement('article');
      el.className = 'vd-voice' + (fresh ? ' is-new' : '');
      el.setAttribute('data-vs-cast', '');
      el.innerHTML = '<div class="vd-voice-head">' + orbHtml(voice.orb || LIBRARY_ORBS[Number(voice.id) % LIBRARY_ORBS.length])
        + '<div class="vd-voice-name"><strong dir="auto">' + esc(voice.name) + '</strong><small>'
        + esc(voice.created ? fmt(t.voice_meta, { speaks: voice.tag || '', date: voice.created }) : (voice.tag || '')) + '</small></div></div>'
        + '<p class="vd-voice-desc" dir="auto">' + esc(voice.description) + '</p>'
        + '<div class="vd-mini"><button type="button" class="vd-play" aria-label="' + esc(t.play) + '">' + ICON_PLAY + '</button>'
        + '<div class="vd-take-bar"><i></i></div>'
        + (voice.seconds ? '<span class="vd-time" dir="ltr">' + clock(voice.seconds) + '</span>' : '') + '</div>'
        + '<div class="vd-voice-foot"><button type="button" class="btn btn-primary" data-vd-use>' + esc(t.use_in_studio) + '</button>'
        + '<button type="button" class="vd-icon-btn" data-vd-delete aria-label="' + esc(t.delete) + '" title="' + esc(t.delete) + '">' + ICON_TRASH + '</button></div>';
      var playButton = el.querySelector('.vd-play');
      playButton.addEventListener('click', function () { play(voice.url, { button: playButton, cast: el, bar: el.querySelector('.vd-take-bar i') }); });
      el.querySelector('[data-vd-use]').addEventListener('click', function () {
        stop();
        if (typeof window.openDesignedVoiceInGenerator === 'function') window.openDesignedVoiceInGenerator(voice);
        else if (studio.urls.studio) window.location.href = studio.urls.studio;
      });
      el.querySelector('[data-vd-delete]').addEventListener('click', function () {
        if (!window.confirm(t.delete_confirm)) return;
        send(String(studio.urls.destroy).replace('__ID__', encodeURIComponent(voice.id)), 'DELETE').then(function () {
          if (now && el.contains(now.button)) stop();
          voices = voices.filter(function (v) { return v.id !== voice.id; });
          el.remove();
          libraryCount();
          document.dispatchEvent(new CustomEvent('listen:designed-voice', { detail: { key: voice.key, removed: true } }));
          showAlert(t.deleted, true);
        }).catch(function (error) { showAlert(error.message); });
      });
      return el;
    }
    function addToLibrary(voice, fresh) {
      if (!libraryGrid) return null;
      if (fresh) voices.unshift(voice);
      var el = card(voice, fresh);
      if (fresh) libraryGrid.insertBefore(el, libraryGrid.firstChild);
      else libraryGrid.appendChild(el);
      drawOrbs(el);
      libraryCount();
      if (fresh) document.dispatchEvent(new CustomEvent('listen:designed-voice', { detail: voice }));
      return el;
    }
    if (libraryGrid) voices.forEach(function (voice) { addToLibrary(voice, false); });
    refreshLimits();

    // ---------- The form ----------
    if (!form) return;
    var limits = studio.limits || {};
    var description = q('[data-vd-description]');
    var descriptionCount = q('[data-vd-description-count]');
    var autoLine = q('[data-vd-auto-line]');
    var lineBox = q('[data-vd-line-box]');
    var line = q('[data-vd-line]');
    var lineCount = q('[data-vd-line-count]');
    var costValue = q('[data-vd-cost-value]');
    var submit = q('[data-vd-submit]');
    var submitLabel = q('[data-vd-submit-label]');
    var enhance = q('[data-vd-enhance]');

    function language() {
      var picked = form.querySelector('input[name="language"]:checked');
      return picked ? picked.value : 'ar';
    }
    function value(name) {
      var field = form.elements[name];
      return field ? Number(field.value) : 50;
    }
    function counters() {
      if (descriptionCount) {
        descriptionCount.textContent = description.value.length.toLocaleString() + ' / ' + Number(limits.description_max).toLocaleString();
      }
      if (lineCount && line) lineCount.textContent = line.value.length.toLocaleString() + ' / ' + Number(limits.line_max).toLocaleString();
      cost();
    }
    function cost() {
      if (!costValue) return;
      var takes = Number(studio.takes) || 1;
      var auto = !autoLine || autoLine.checked;
      var text = line ? line.value.trim() : '';
      var about = auto || !text ? '≈ ' : '';
      if (studio.demo) {
        var chars = auto || !text ? Number((studio.typical_chars || {})[language()]) || 0 : text.length;
        costValue.textContent = about + fmt(t.demo_cost, { count: chars.toLocaleString() });
        return;
      }
      var each = Number((studio.typical_credits || {})[language()]) || 0;
      if (!auto && text) {
        each = window.ListenAudioEstimate && window.ListenAudioEstimate.credits
          ? Number(window.ListenAudioEstimate.credits(text, { tier: 'hd' })) || 0
          : Math.ceil(text.length / 15) * 15;
      }
      costValue.textContent = about + fmt(t.cost, { credits: Math.round(each * takes).toLocaleString() });
    }
    // The description grows with what is written, up to its largest size.
    function grow() {
      description.style.height = 'auto';
      description.style.height = Math.min(description.scrollHeight + 2, 360) + 'px';
    }
    // The service refuses any voice of a child: say so before a design is asked for.
    var childHint = q('[data-vd-child-hint]');
    var CHILD = /(^|[^\p{L}])(?:[وفبلك]{1,2})?(child|children|kid|kids|toddler|baby|babies|infant|boy|schoolboy|schoolgirl|teen|teens|teenager|teenage|minor|طفل|طفلة|أطفال|الطفل|الطفلة|الأطفال|طفولي|طفولية|رضيع|رضيعة|صبي|صبية|مراهق|مراهقة|تلميذ|تلميذة)(?=$|[^\p{L}])|(^|[^\d])(1[0-7]|[1-9])[ -]?(year|yr)s?[ -]old/iu;
    function childCheck() {
      if (childHint) childHint.hidden = !CHILD.test(description.value);
    }
    function pickExample(example) {
      if (!example) return;
      lastExample = example;
      description.value = example.description;
      var radio = form.querySelector('input[name="language"][value="' + example.language + '"]');
      if (radio) radio.checked = true;
      form.querySelectorAll('[data-vd-example]').forEach(function (chip) {
        var picked = chip.getAttribute('data-vd-example') === example.key;
        chip.classList.toggle('is-picked', picked);
        if (picked) chip.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      });
      counters();
      grow();
      childCheck();
      description.focus({ preventScroll: true });
      previewExample(example.key);
    }
    form.querySelectorAll('[data-vd-example]').forEach(function (chip) {
      chip.addEventListener('click', function () {
        pickExample(examples.filter(function (e) { return e.key === chip.getAttribute('data-vd-example'); })[0]);
      });
    });
    var shuffle = q('[data-vd-shuffle]');
    if (shuffle) shuffle.addEventListener('click', function () {
      var others = examples.filter(function (e) { return !lastExample || e.key !== lastExample.key; });
      pickExample(others[Math.floor(Math.random() * others.length)]);
    });
    description.addEventListener('input', function () {
      if (lastExample && description.value !== lastExample.description) {
        form.querySelectorAll('[data-vd-example]').forEach(function (chip) { chip.classList.remove('is-picked'); });
      }
      counters();
      grow();
      childCheck();
    });

    // Writing tips: closed by a click outside them or by Escape.
    var tips = q('.vd-tips');
    if (tips) {
      document.addEventListener('click', function (event) { if (tips.open && !tips.contains(event.target)) tips.open = false; });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && tips.open) { tips.open = false; var s = tips.querySelector('summary'); if (s) s.focus(); }
      });
    }

    // The characters' row: arrows at the ends that have more, and the chip picked brought into view.
    var chipsWrap = q('[data-vd-chips-wrap]');
    var chipsRow = q('[data-vd-chips]');
    function chipsEdges() {
      if (!chipsWrap || !chipsRow) return;
      var max = chipsRow.scrollWidth - chipsRow.clientWidth;
      var at = Math.abs(chipsRow.scrollLeft);
      var start = max > 2 && at > 2;
      var end = max > 2 && at < max - 2;
      chipsWrap.classList.toggle('has-start', start);
      chipsWrap.classList.toggle('has-end', end);
      chipsWrap.querySelectorAll('[data-vd-chips-step]').forEach(function (arrow) {
        arrow.hidden = Number(arrow.getAttribute('data-vd-chips-step')) < 0 ? !start : !end;
      });
    }
    if (chipsWrap && chipsRow) {
      var rtl = getComputedStyle(chipsRow).direction === 'rtl';
      chipsWrap.querySelectorAll('[data-vd-chips-step]').forEach(function (arrow) {
        arrow.addEventListener('click', function () {
          var step = Number(arrow.getAttribute('data-vd-chips-step')) * Math.max(160, chipsRow.clientWidth * 0.7);
          chipsRow.scrollBy({ left: rtl ? -step : step });
        });
      });
      chipsRow.addEventListener('scroll', chipsEdges, { passive: true });
      window.addEventListener('resize', chipsEdges);
      if (window.ResizeObserver) new ResizeObserver(chipsEdges).observe(chipsRow);
      chipsEdges();
    }
    if (line) line.addEventListener('input', counters);
    form.querySelectorAll('input[name="language"]').forEach(function (radio) { radio.addEventListener('change', cost); });
    if (autoLine) {
      var syncLine = function () {
        if (lineBox) lineBox.hidden = autoLine.checked;
        if (!autoLine.checked && line) line.focus();
        cost();
      };
      autoLine.addEventListener('change', syncLine);
      if (lineBox) lineBox.hidden = autoLine.checked;
    }

    if (enhance) enhance.addEventListener('click', function () {
      var idea = description.value.trim() || (lastExample ? lastExample.name : '');
      if (idea.length < 3) { description.focus(); return; }
      var label = enhance.querySelector('span');
      enhance.disabled = true;
      if (label) label.textContent = t.writing;
      showAlert('');
      send(studio.urls.enhance, 'POST', { idea: idea, language: language() }).then(function (res) {
        if (res.description) {
          description.value = res.description;
          lastExample = null;
          form.querySelectorAll('[data-vd-example]').forEach(function (chip) { chip.classList.remove('is-picked'); });
          counters();
          grow();
          childCheck();
        }
      }).catch(function (error) { showAlert(error.message); }).then(function () {
        enhance.disabled = false;
        if (label) label.textContent = t.write_for_me;
      });
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      // A plan without Voice Design: the way to one that designs, instead of a design.
      if (!submit) {
        var unlock = q('[data-vd-unlock]');
        var upgrade = q('[data-vd-upgrade]');
        if (unlock) {
          unlock.classList.remove('is-nudged');
          void unlock.offsetWidth;
          unlock.classList.add('is-nudged');
          unlock.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        if (upgrade) upgrade.focus({ preventScroll: true });
        return;
      }
      if (submit.disabled) return;
      showAlert('');
      var text = description.value.trim();
      if (text.length < Number(limits.description_min || 0)) { showAlert(t.description_short); description.focus(); return; }
      var auto = !autoLine || autoLine.checked;
      var spoken = line ? line.value.trim() : '';
      if (!auto && spoken.length < Number(limits.line_min || 0)) { showAlert(spoken ? t.line_short : t.line_needed); if (line) line.focus(); return; }
      var gender = form.querySelector('input[name="gender"]:checked');
      var body = {
        description: text,
        language: language(),
        gender: gender && gender.value ? gender.value : null,
        auto_line: auto,
        projection: value('projection'),
        pace: value('pace'),
        variety: value('variety')
      };
      if (!auto) body.line = spoken;

      submit.disabled = true;
      if (submitLabel) submitLabel.textContent = t.designing;
      working(true);
      send(studio.urls.design, 'POST', body).then(function (res) {
        working(false);
        if (progress) progress.style.width = '100%';
        renderTakes(res.takes || (res.take ? [res.take] : []), res.line, res);
        if (studio.demo) {
          var note = q('[data-vd-demo-note]');
          if (note) note.textContent = fmt(t.demo_cost_note, { count: res.estimated_chars || 0 });
        }
        if (res.quota) document.dispatchEvent(new CustomEvent('listen:demo-quota', { detail: res.quota }));
        if (res.usage) document.dispatchEvent(new CustomEvent('listen:usage', { detail: res.usage }));
      }).catch(function (error) {
        working(false);
        setStage('idle');
        showAlert(error.message);
        if (error.payload && error.payload.quota) document.dispatchEvent(new CustomEvent('listen:demo-quota', { detail: error.payload.quota }));
      }).then(function () {
        submit.disabled = false;
        if (submitLabel) submitLabel.textContent = t.design;
        refreshLimits();
      });
    });

    counters();
    grow();
  }

  function boot() { document.querySelectorAll('[data-vd]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
