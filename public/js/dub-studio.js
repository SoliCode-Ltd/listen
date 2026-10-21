/*
 * The dubbing studio (components/dub/studio.blade.php) and the switch between
 * it and the text studio (components/dub/mode-switch.blade.php), on the
 * "Dub my video" page, in the demo and on the dashboard.
 *
 * The studio's card goes from the drop zone to the chosen video, then its
 * upload (with the percentage sent) and its dubbing (the server's own steps
 * and percentage, asked every few seconds), then the finished video. What
 * the visitor may not do yet (sign up, confirm the email, the free dub used,
 * the month's videos used, too few credits) shows in its place with the way
 * forward, while the pickers and the voice library stay to be tried.
 * The dialect, style and emotion pickers and the voice library are the voice
 * studio's own (js/voice-studio.js); this fills their selects first.
 */
(function () {
  'use strict';

  var MODE_KEY = 'listen.studio.mode';

  // ---------- Text to speech | Dub a video ----------
  function initSwitch(sw) {
    var host = sw.closest('[data-studio-host]');
    if (!host) return;
    var tabs = sw.querySelectorAll('[data-studio-tab]');
    function set(mode, remember) {
      host.setAttribute('data-studio', mode);
      tabs.forEach(function (tab) { tab.setAttribute('aria-selected', tab.dataset.studioTab === mode ? 'true' : 'false'); });
      if (remember) {
        try { window.localStorage.setItem(MODE_KEY, mode); } catch (e) { /* no storage */ }
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', mode === 'dub' ? '#dub' : window.location.pathname + window.location.search);
        }
      }
      document.dispatchEvent(new CustomEvent('listen:studio-mode', { detail: { mode: mode } }));
    }
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () { set(tab.dataset.studioTab, true); });
    });
    var start = 'tts';
    if (window.location.hash === '#dub') start = 'dub';
    else {
      try { if (window.localStorage.getItem(MODE_KEY) === 'dub') start = 'dub'; } catch (e) { /* no storage */ }
    }
    set(start, false);
    requestAnimationFrame(function () { sw.classList.add('is-ready'); });
  }

  // ---------- The device, as the demo works it out (App\Services\DemoLinkedAccounts) ----------
  var device = null;
  function sha256(text) {
    if (!window.crypto || !window.crypto.subtle || !window.TextEncoder) return Promise.resolve('');
    return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)).then(function (buf) {
      return Array.prototype.map.call(new Uint8Array(buf), function (v) { return v.toString(16).padStart(2, '0'); }).join('');
    });
  }
  function canvasPrint() {
    try {
      var canvas = document.createElement('canvas'); canvas.width = 200; canvas.height = 50;
      var ctx = canvas.getContext('2d'); if (!ctx) return '';
      ctx.textBaseline = 'top'; ctx.font = '14px Arial'; ctx.fillStyle = '#f60'; ctx.fillRect(125, 1, 62, 20);
      ctx.fillStyle = '#069'; ctx.fillText('Listen Demo FP 🎵', 2, 15);
      ctx.fillStyle = 'rgba(102, 204, 0, 0.7)'; ctx.fillText('Listen Demo FP 🎵', 4, 17);
      ctx.strokeStyle = '#2a4'; ctx.arc(50, 25, 20, 0, Math.PI * 2, true); ctx.stroke();
      return canvas.toDataURL();
    } catch (e) { return ''; }
  }
  function webglPrint() {
    try {
      var gl = document.createElement('canvas').getContext('webgl');
      if (!gl) return '';
      var info = gl.getExtension('WEBGL_debug_renderer_info');
      return [info ? gl.getParameter(info.UNMASKED_VENDOR_WEBGL) : '', info ? gl.getParameter(info.UNMASKED_RENDERER_WEBGL) : '',
        gl.getParameter(gl.VERSION) || '', gl.getParameter(gl.SHADING_LANGUAGE_VERSION) || '', (gl.getSupportedExtensions() || []).sort().join(',')].join('|');
    } catch (e) { return ''; }
  }
  function fontsPrint() {
    var fonts = ['Arial', 'Arial Black', 'Calibri', 'Cambria', 'Comic Sans MS', 'Courier New', 'Georgia', 'Helvetica', 'Impact', 'Lucida Console', 'Tahoma', 'Times New Roman', 'Trebuchet MS', 'Verdana', 'Wingdings'];
    var bases = ['monospace', 'sans-serif', 'serif'];
    var span = document.createElement('span');
    span.style.cssText = 'position:absolute;left:-9999px;font-size:72px';
    span.textContent = 'mmmmmmmmmmlli';
    document.body.appendChild(span);
    var widths = {};
    bases.forEach(function (b) { span.style.fontFamily = b; widths[b] = span.offsetWidth; });
    var found = fonts.filter(function (f) {
      return bases.some(function (b) { span.style.fontFamily = "'" + f + "'," + b; return span.offsetWidth !== widths[b]; });
    });
    document.body.removeChild(span);
    return found.join(',');
  }
  function browserFamily() {
    var ua = navigator.userAgent || '';
    var browser = /Edg\//.test(ua) ? 'edge' : /OPR\/|Opera/.test(ua) ? 'opera' : /Firefox\//.test(ua) ? 'firefox'
      : /Chrome\/|CriOS\//.test(ua) ? 'chrome' : /Safari\//.test(ua) ? 'safari' : 'other';
    var system = /Windows/.test(ua) ? 'windows' : /Android/.test(ua) ? 'android' : /iPhone|iPad|iPod/.test(ua) ? 'ios'
      : /Mac OS X|Macintosh/.test(ua) ? 'mac' : /CrOS/.test(ua) ? 'chromeos' : /Linux/.test(ua) ? 'linux' : 'other';
    return browser + ':' + system;
  }
  function deviceSignal() {
    if (device) return device;
    var touch = (navigator.maxTouchPoints || 0) > 0 || /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '');
    device = Promise.all([sha256(canvasPrint()), sha256(webglPrint()), sha256(fontsPrint())]).then(function (parts) {
      return sha256(parts.concat([String(navigator.hardwareConcurrency || ''), String(navigator.deviceMemory || ''), String(navigator.maxTouchPoints || ''),
        navigator.platform || '', (Intl.DateTimeFormat().resolvedOptions().timeZone || ''), browserFamily()]).join('|'));
    }).then(function (id) { return { id: id, touch: touch }; }).catch(function () { return { id: '', touch: touch }; });
    return device;
  }

  // ---------- Helpers ----------
  function fmtTime(seconds) {
    var s = Math.max(0, Math.round(seconds));
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
  }
  function fmtLong(seconds, t) {
    var s = Math.max(0, Math.round(seconds));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60);
    if (h > 0) return t.hours_minutes.replace(':h', h).replace(':m', m);
    if (m > 0 && s % 60) return t.minutes_seconds.replace(':m', m).replace(':s', s % 60);
    if (m > 0) return t.minutes.replace(':m', m);
    return t.seconds.replace(':s', s);
  }
  // Western digits in both languages, as the rest of the site writes numbers.
  function num(n) { return Number(n || 0).toLocaleString('en-US'); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  // The form token: the page's meta tag where it has one, else the studio's own (its config).
  var studioToken = '';
  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    return m && m.content ? m.content : studioToken;
  }

  // ---------- One studio ----------
  function initStudio(root) {
    var configNode = root.querySelector('[data-dub-config]');
    if (!configNode) return;
    var c = JSON.parse(configNode.textContent || '{}');
    if (c.csrf) studioToken = c.csrf;
    var t = c.i18n || {};
    var form = root.querySelector('[data-dub-form]');
    var q = function (sel) { return root.querySelector(sel); };
    var views = {};
    root.querySelectorAll('[data-dub-view]').forEach(function (v) { views[v.dataset.dubView] = v; });
    var fileInput = q('[data-dub-file]');
    var preview = q('[data-dub-preview]');
    var submit = q('[data-dub-submit]');
    var submitText = q('[data-dub-submit-text]');
    var meter = q('[data-dub-meter]');
    var errorBox = q('[data-dub-error]');
    var keep = q('[data-dub-keep]');
    var voiceSelect = form.querySelector('select[name="voice_key"]');
    var dialectSelect = form.querySelector('select[name="dialect"]');
    var chosen = null;
    var seconds = 0;
    var current = null;
    var timer = null;

    // The studio's selects, filled before js/voice-studio.js draws its pickers and voice cards,
    // and again when the language changes (they redraw themselves from the selects).
    var languageSelect = q('[data-dub-language]');
    var allDialects = {};
    Object.keys(c.languages || {}).forEach(function (code) { Object.assign(allDialects, c.languages[code].dialects || {}); });
    function lang() { return (c.languages || {})[languageSelect.value] || { voices: [], dialects: {} }; }
    function fillLanguage() {
      var l = lang();
      voiceSelect.innerHTML = '';
      (l.voices || []).forEach(function (v) {
        var o = new Option(v.label, v.key);
        if (v.avatar) o.dataset.avatar = v.avatar;
        voiceSelect.appendChild(o);
      });
      if (l.defaultVoice) voiceSelect.value = l.defaultVoice;
      dialectSelect.innerHTML = '';
      Object.keys(l.dialects || {}).forEach(function (code) { dialectSelect.appendChild(new Option(l.dialects[code], code)); });
      dialectSelect.value = l.defaultDialect || Object.keys(l.dialects || {})[0] || '';
      voiceSelect.dispatchEvent(new Event('change', { bubbles: true }));
      dialectSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
    languageSelect.value = c.language || 'ar';
    fillLanguage();
    languageSelect.addEventListener('change', fillLanguage);

    // The chosen voice, at the foot of the card; on a phone it leads to the library below.
    var chip = q('[data-dub-voice-chip]');
    function showVoice() {
      var v = (lang().voices || []).find(function (x) { return x.key === voiceSelect.value; }) || {};
      var face = q('[data-dub-voice-face]');
      face.innerHTML = '';
      if (v.avatar) { var img = el('img'); img.src = v.avatar; img.alt = ''; face.appendChild(img); }
      else face.textContent = (v.label || '?').charAt(0);
      var dialect = dialectSelect.selectedOptions[0] ? dialectSelect.selectedOptions[0].textContent : '';
      q('[data-dub-voice-name]').textContent = [v.label, dialect].filter(Boolean).join(' · ');
    }
    voiceSelect.addEventListener('change', showVoice);
    dialectSelect.addEventListener('change', showVoice);
    chip.addEventListener('click', function () {
      var side = root.querySelector('.vs-side');
      if (side) side.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    showVoice();

    function show(name) {
      Object.keys(views).forEach(function (k) { views[k].hidden = k !== name; });
      root.classList.toggle('is-working', name === 'working');
      var canSend = name === 'chosen' && allowed();
      submit.disabled = !canSend;
      keep.closest('.dub-toggle').hidden = name === 'lock' || name === 'done';
    }
    function allowed() { return c.state === 'free' || c.state === 'paid'; }
    function showError(message) {
      errorBox.textContent = message || '';
      errorBox.hidden = !message;
    }

    // ---------- What it costs, and what is left ----------
    function renderMeter() {
      meter.innerHTML = '';
      // On the dashboard the account above already says what is left: only what this video uses.
      meter.hidden = !(c.state === 'free' || c.state === 'paid') || (c.compactMeter && !(c.state === 'paid' && chosen && seconds));
      if (meter.hidden) return;
      if (c.compactMeter) {
        var only = el('p', 'dub-meter-main', t.meter_video.replace(':time', fmtLong(Math.ceil(Math.min(seconds, c.maxSeconds)), t)).replace(':credits', num(Math.ceil(Math.min(seconds, c.maxSeconds)) * c.creditsPerSecond)));
        meter.appendChild(only);
        return;
      }
      var main = el('p', 'dub-meter-main');
      var aside = el('p', 'dub-meter-aside');
      if (c.state === 'free') {
        main.textContent = t.meter_free.replace(':seconds', c.maxSeconds);
        aside.textContent = t.meter_free_note;
      } else {
        var u = c.usage || {};
        main.textContent = chosen && seconds
          ? t.meter_video.replace(':time', fmtLong(Math.ceil(Math.min(seconds, c.maxSeconds)), t)).replace(':credits', num(Math.ceil(Math.min(seconds, c.maxSeconds)) * c.creditsPerSecond))
          : t.meter_rule.replace(':credits', num(c.creditsPerSecond));
        var bits = [t.meter_left.replace(':time', fmtLong(u.seconds_left, t)).replace(':credits', num(u.credits_left))];
        if (u.dubs_limit > 0) bits.push(t.meter_videos.replace(':used', num(u.dubs_used)).replace(':limit', num(u.dubs_limit)));
        bits.push(t.meter_longest.replace(':seconds', c.maxSeconds));
        aside.textContent = bits.join(' · ');
      }
      meter.appendChild(main);
      meter.appendChild(aside);
    }

    // ---------- What may not be done yet ----------
    function renderLock() {
      var actions = q('[data-dub-lock-actions]');
      actions.innerHTML = '';
      var title = '', text = '';
      function link(label, href, primary) {
        var a = el('a', 'btn ' + (primary ? 'btn-primary' : 'btn-ghost'), label);
        a.href = href;
        actions.appendChild(a);
      }
      var plan = c.upgrade && c.upgrade.plan;
      switch (c.state) {
        case 'guest':
          title = t.lock_guest_title; text = t.lock_guest_text.replace(':seconds', c.maxSeconds);
          link(t.lock_guest_register, c.urls.register, true); link(t.lock_guest_login, c.urls.login, false);
          break;
        case 'verify':
          title = t.lock_verify_title; text = t.lock_verify_text.replace(':email', c.email || '');
          var f = el('form'); f.method = 'POST'; f.action = c.urls.verify;
          var token = el('input'); token.type = 'hidden'; token.name = '_token'; token.value = csrf();
          var b = el('button', 'btn btn-primary', t.lock_verify_resend); b.type = 'submit';
          f.appendChild(token); f.appendChild(b); actions.appendChild(f);
          break;
        case 'closed':
          // A paid plan without dubbing: no free dub there, the way to a plan with it.
          title = t.lock_closed_title;
          text = plan ? t.lock_closed_text_plan.replace(':plan', plan) : t.lock_closed_text;
          link(t.see_plans, c.upgrade.url, true);
          break;
        case 'used':
          title = t.lock_used_title;
          text = plan ? t.lock_used_text_plan.replace(':plan', plan) : t.lock_used_text;
          link(t.see_plans, c.upgrade.url, true);
          // Off the dashboard, the way to the video they made.
          if (!c.onDashboard && c.hasVideos) link(t.watch_mine, c.urls.dashboard, false);
          break;
        case 'day_full':
          title = t.lock_day_title; text = t.lock_day_text;
          link(t.see_plans, c.upgrade.url, true);
          break;
        case 'limit':
          title = t.lock_limit_title.replace(':count', num((c.usage || {}).dubs_limit));
          text = t.lock_limit_text;
          link(t.see_plans, c.upgrade.url, true);
          break;
        case 'no_credits':
          title = t.lock_credits_title; text = t.lock_credits_text.replace(':credits', num(c.creditsPerSecond));
          link(t.get_more, c.upgrade.url, true);
          break;
      }
      q('[data-dub-lock-title]').textContent = title;
      q('[data-dub-lock-text]').textContent = text;
      root.dataset.dubState = c.state;
    }

    // The demo's free-video box, where the text studio shows its characters.
    function syncAllowance() {
      var left = c.state === 'used' || c.state === 'closed' ? 0 : 1;
      document.querySelectorAll('[data-dub-allowance]').forEach(function (box) {
        var value = box.querySelector('[data-dub-allowance-value]');
        var fill = box.querySelector('[data-dub-allowance-fill]');
        if (value && t.allowance_value) value.textContent = t.allowance_value.replace(':left', left);
        if (fill) fill.style.width = (left ? 100 : 0) + '%';
      });
    }

    function idle() {
      syncAllowance();
      renderLock();
      renderMeter();
      if (!allowed()) { show('lock'); return; }
      show(chosen ? 'chosen' : 'drop');
      submitText.textContent = c.state === 'free' ? t.submit_free : t.submit;
    }

    // ---------- Choosing a video ----------
    function reset() {
      chosen = null; seconds = 0;
      fileInput.value = '';
      preview.removeAttribute('src');
      try { preview.load(); } catch (e) { /* nothing loaded */ }
      idle();
    }
    function pick(file) {
      showError('');
      if (!file) return;
      if (!/^video\//.test(file.type) && !/\.(mp4|mov|webm|mkv|m4v|3gp)$/i.test(file.name)) { showError(t.not_video); return; }
      if (file.size > c.maxMb * 1024 * 1024) { showError(t.too_big.replace(':mb', c.maxMb)); return; }
      chosen = file; seconds = 0;
      preview.src = URL.createObjectURL(file);
      q('[data-dub-file-name]').textContent = file.name;
      q('[data-dub-file-length]').textContent = '';
      preview.onloadedmetadata = function () {
        seconds = preview.duration || 0;
        q('[data-dub-file-length]').textContent = seconds ? fmtTime(seconds) : '';
        if (seconds && seconds < c.minSeconds) { reset(); showError(t.too_short.replace(':seconds', c.minSeconds)); return; }
        if (seconds > c.maxSeconds + 0.5) { reset(); showError(t.too_long.replace(':seconds', c.maxSeconds)); return; }
        renderMeter();
      };
      idle();
    }
    fileInput.addEventListener('change', function () { pick(fileInput.files[0]); });
    q('[data-dub-change]').addEventListener('click', function () { reset(); fileInput.click(); });
    var drop = views.drop;
    drop.addEventListener('click', function (ev) { if (ev.target !== fileInput) fileInput.click(); });
    drop.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); fileInput.click(); } });
    ['dragenter', 'dragover'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (e) { drop.addEventListener(e, function (ev) { ev.preventDefault(); drop.classList.remove('is-over'); }); });
    drop.addEventListener('drop', function (ev) { if (ev.dataTransfer && ev.dataTransfer.files[0]) pick(ev.dataTransfer.files[0]); });

    // ---------- Upload, then dubbing ----------
    var STEPS = ['upload', 'listening', 'voicing', 'mixing'];
    function working(step, percent, title, note) {
      show('working');
      q('[data-dub-working-title]').textContent = title;
      q('[data-dub-working-note]').textContent = note || '';
      var p = Math.max(0, Math.min(100, Math.round(percent)));
      q('[data-dub-percent]').textContent = p + '%';
      q('[data-dub-bar]').setAttribute('aria-valuenow', String(p));
      q('[data-dub-bar-fill]').style.width = p + '%';
      var at = STEPS.indexOf(step);
      root.querySelectorAll('[data-dub-step]').forEach(function (li) {
        var i = STEPS.indexOf(li.dataset.dubStep);
        li.className = i < at ? 'is-done' : (i === at ? 'is-on' : '');
      });
    }
    // The progress card in the middle of the screen, with the focus: the tall preview it takes the place of
    // left the page scrolled down past it, the button just pressed in sight and the progress out of it.
    function reveal(view) {
      requestAnimationFrame(function () {
        var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        view.scrollIntoView({ behavior: still ? 'auto' : 'smooth', block: 'center' });
        view.focus({ preventScroll: true });
      });
    }
    function stageOf(dub) {
      switch (dub.stage) {
        case 'voicing': return ['voicing', t.working_voicing, t.working_voicing_note];
        case 'separating': return ['mixing', t.working_separating, t.working_separating_note];
        case 'mixing': case 'marking': return ['mixing', t.working_mixing, dub.kept_background === false ? t.working_mixing_replace : t.working_mixing_note];
        default: return ['listening', t.working_listening, t.working_listening_note];
      }
    }
    function follow(dub) {
      current = dub;
      clearTimeout(timer);
      if (dub.status === 'queued' || dub.status === 'processing') {
        var s = stageOf(dub);
        working(s[0], dub.progress || 3, s[1], s[2]);
        timer = setTimeout(function () { poll(dub); }, 2500);
        return;
      }
      if (dub.status === 'failed') {
        showError(dub.error || t.failed);
        current = null;
        // A failed dub gives back what it took: the free dub, or the video and its credits.
        if (dub.free) c.state = 'free';
        else if (c.usage) {
          c.usage.dubs_used = Math.max(0, (c.usage.dubs_used || 0) - 1);
          c.usage.credits_left = (c.usage.credits_left || 0) + (dub.credits || 0);
          c.usage.seconds_left = Math.floor(c.usage.credits_left / c.creditsPerSecond);
          if (c.state === 'limit' || c.state === 'no_credits') c.state = 'paid';
        }
        addToHistory(dub);
        idle();
        return;
      }
      finished(dub);
    }
    function poll(dub) {
      fetch(dub.status_url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
        .then(follow)
        .catch(function () { timer = setTimeout(function () { poll(dub); }, 6000); });
    }
    function downloadLabel(dub) {
      if (dub.free && c.free) return c.upgrade && c.upgrade.plan ? t.download_clean_plan.replace(':plan', c.upgrade.plan) : t.download_clean;
      return dub.free && !dub.paid_clean ? t.download_clean_paid.replace(':credits', num(Math.ceil(dub.seconds) * c.creditsPerSecond)) : t.download;
    }
    function finished(dub) {
      show('done');
      var a = q('[data-dub-download]');
      if (!c.onDashboard) {
        // Finished videos are watched on the dashboard only.
        q('[data-dub-done-note]').textContent = dub.free ? t.done_elsewhere_free.replace(':hours', c.keepHours) : t.done_elsewhere;
        a.hidden = false;
        a.href = c.urls.dashboard;
        a.textContent = t.open_dashboard;
        c.hasVideos = true;
        return;
      }
      var video = q('[data-dub-result]');
      if (dub.watch_url && video.getAttribute('src') !== dub.watch_url) video.src = dub.watch_url;
      var bits = [];
      if (dub.lines) bits.push(t.done_lines.replace(':count', dub.lines));
      if (dub.speakers > 1) bits.push(t.done_voices.replace(':count', dub.speakers));
      if (dub.kept_background) bits.push(t.done_music);
      if (dub.free) bits.push(t.done_free);
      q('[data-dub-done-note]').textContent = bits.join(' · ');
      a.hidden = !dub.download_url;
      if (dub.download_url) { a.href = dub.download_url; a.textContent = downloadLabel(dub); }
    }
    q('[data-dub-again]').addEventListener('click', function () {
      if (current) addToHistory(current);
      current = null;
      chosen = null;
      reset();
    });

    // The video goes in pieces of a few MB (the server says how big), so no
    // limit on one request's size can stop a large one, and a piece lost on
    // the way is sent again rather than the whole video. Then the dub is
    // asked for with the upload's id.
    // What the member is told: the site's own words in their language, never a server's raw message.
    function errorOf(xhr, body) {
      if (xhr.status === 413) return t.too_big.replace(':mb', c.maxMb);
      if (xhr.status === 419) return t.expired_page;
      if (xhr.status === 429) return t.too_many;
      if (xhr.status === 422 && body.errors) {
        var first = Object.keys(body.errors).map(function (k) { return body.errors[k][0]; })[0];
        if (first) return first;
      }
      if (xhr.status === 0) return t.network;
      return t.failed_server;
    }
    function request(method, url, body, headers, onProgress) {
      return new Promise(function (resolve, reject) {
        var xhr = new XMLHttpRequest();
        xhr.open(method, url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        Object.keys(headers || {}).forEach(function (k) { xhr.setRequestHeader(k, headers[k]); });
        if (onProgress) xhr.upload.onprogress = onProgress;
        xhr.onload = function () {
          var json = {};
          try { json = JSON.parse(xhr.responseText || '{}'); } catch (e) { /* not JSON */ }
          resolve({ xhr: xhr, body: json });
        };
        xhr.onerror = function () { reject(new Error('network')); };
        xhr.send(body);
      });
    }
    function wait(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }
    function sendPieces(file, upload) {
      var size = file.size;
      var stalls = 0;
      // One piece, sent again after a pause when it did not get through (a few times).
      function sendOne(offset, tries) {
        var piece = file.slice(offset, Math.min(size, offset + upload.chunk_bytes));
        return request('POST', upload.chunk_url, piece, { 'Content-Type': 'application/octet-stream', 'X-Upload-Offset': String(offset) }, function (e) {
          if (e.lengthComputable) working('upload', ((offset + e.loaded) / size) * 100, t.working_upload, t.working_upload_note);
        }).then(function (r) {
          // 409: the server has a different amount; it says how much, and the next piece starts there.
          if ((r.xhr.status === 200 || r.xhr.status === 409) && typeof r.body.received === 'number') return r.body.received;
          return Promise.reject(r);
        }).catch(function (r) {
          var status = r && r.xhr ? r.xhr.status : 0;
          if (tries >= 4 || (status > 0 && status < 500)) return Promise.reject(r);
          return wait(1500 * (tries + 1)).then(function () { return sendOne(offset, tries + 1); });
        });
      }
      function from(offset) {
        if (offset >= size) return Promise.resolve();
        return sendOne(offset, 0).then(function (received) {
          stalls = received > offset ? 0 : stalls + 1;
          if (stalls > 4) return Promise.reject(null);
          return from(received);
        });
      }
      return from(0);
    }

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (!chosen || !allowed()) return;
      showError('');
      var file = chosen;
      var data = new FormData(form);
      data.delete('video');
      data.set('background', keep.checked ? 'keep' : 'replace');
      working('upload', 0, t.working_upload, t.working_upload_note);
      reveal(views.working);
      deviceSignal().then(function (d) {
        var device = d.id ? { 'X-Client-Device': d.id, 'X-Client-Device-Touch': d.touch ? '1' : '0' } : {};
        var start = new FormData();
        start.set('name', file.name || 'video.mp4');
        start.set('size', String(file.size));
        return request('POST', c.urls.upload, start, device).then(function (r) {
          if (r.xhr.status !== 201) return Promise.reject(r);
          return sendPieces(file, r.body).then(function () {
            working('listening', 2, t.working_checking, t.working_checking_note);
            data.set('upload_id', r.body.id);
            return request('POST', c.urls.store, data, device);
          });
        }).then(function (r) {
          if (r.xhr.status !== 202) return Promise.reject(r);
          if (r.body.studio) { c.state = r.body.studio.state; c.usage = r.body.studio.usage; syncAllowance(); }
          chosen = null;
          follow(r.body);
        });
      }).catch(function (r) {
        showError(r && r.xhr ? errorOf(r.xhr, r.body || {}) : t.network);
        idle();
      });
    });

    // ---------- Earlier dubs ----------
    var history = q('[data-dub-history]');
    var items = q('[data-dub-items]');
    function addToHistory(dub) {
      if (!history) return;
      var old = items.querySelector('[data-dub-id="' + dub.id + '"]');
      var card = el('article', 'dub-item');
      card.dataset.dubId = dub.id;
      var media = el('div', 'dub-item-media');
      if (dub.watch_url) {
        var v = el('video'); v.controls = true; v.playsInline = true; v.preload = 'metadata'; v.src = dub.watch_url;
        media.appendChild(v);
      } else {
        media.appendChild(el('span', 'dub-item-empty', dub.status === 'failed' ? t.failed_short : t.deleted));
      }
      card.appendChild(media);
      var body = el('div', 'dub-item-body');
      body.appendChild(el('strong', 'dub-item-name', dub.name || t.your_video));
      body.appendChild(el('small', 'dub-item-meta', [allDialects[dub.dialect] || dub.dialect, fmtTime(dub.seconds)].join(' · ')));
      if (dub.status === 'failed' && dub.error) body.appendChild(el('small', 'dub-item-error', dub.error));
      if (dub.download_url) {
        var a = el('a', 'dub-link', downloadLabel(dub));
        a.href = dub.download_url;
        body.appendChild(a);
      }
      card.appendChild(body);
      if (old) old.replaceWith(card); else items.prepend(card);
      history.hidden = false;
    }

    // ---------- Start ----------
    keep.addEventListener('change', renderMeter);
    var dubs = c.dubs || [];
    var running = dubs.find(function (d) { return d.status === 'queued' || d.status === 'processing'; });
    renderMeter();
    dubs.slice().reverse().forEach(function (d) { if (d !== running) addToHistory(d); });
    if (running) follow(running);
    else idle();
  }

  function boot() {
    document.querySelectorAll('[data-studio-switch]').forEach(initSwitch);
    document.querySelectorAll('[data-dub-studio]').forEach(initStudio);
  }
  // Pages load this file just before js/voice-studio.js (both deferred), so the selects
  // are filled before the pickers and the voice library are drawn from them.
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
