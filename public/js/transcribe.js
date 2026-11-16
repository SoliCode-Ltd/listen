/*
 * Speech to text on the dashboard (components/transcribe/dashboard-section.blade.php).
 *
 * The box: a file dropped, uploaded or recorded (its length read here and
 * checked with the server at once: what it will use, what is left, what will
 * remain, or why it cannot start and the plan that fits), or a link; then
 * sent in pieces as the dubbing studio sends a video (a piece lost on the way
 * is sent again) and followed until its words are written out.
 * The reader: the transcript by paragraph and speaker, each sentence played
 * with a click, the one playing lit; a paragraph corrected; speakers named;
 * translated, copied, downloaded, sent to the studio, deleted.
 * The list: every transcript with the minutes it used, searched.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-tr-root]');
  if (!root) return;
  var c = JSON.parse(root.querySelector('[data-tr-config]').textContent || '{}');
  var t = c.i18n || {};
  var $ = function (sel, el) { return (el || root).querySelector(sel); };
  var $$ = function (sel, el) { return Array.prototype.slice.call((el || root).querySelectorAll(sel)); };

  var form = $('[data-tr-form]');
  var fileInput = $('[data-tr-file]');
  var drop = $('[data-tr-drop]');
  var meter = $('[data-tr-meter]');
  var errorBox = $('[data-tr-error]');
  var submit = $('[data-tr-submit]');
  var submitText = $('[data-tr-submit-text]');
  var language = $('[data-tr-language]');
  var preview = $('[data-tr-preview]');
  var reader = $('[data-tr-reader]');
  var rows = $('[data-tr-rows]');
  var search = $('[data-tr-search]');
  var moreButton = $('[data-tr-more]');

  var chosen = null;      // {file, name, seconds, source} or {url}
  var busy = false;
  var current = null;     // the transcript open in the reader
  var shown = null;       // its paragraphs and sentences as shown (original or a translation)
  var shownLanguage = '';
  var page = 1;
  var following = {};

  // ---------- Words ----------
  function fill(text, words) {
    return String(text || '').replace(/:(\w+)/g, function (m, k) { return words && words[k] !== undefined ? words[k] : m; });
  }
  // A length in the page's words; the month's minutes in minutes only ($minutesOnly), as the header says them.
  function time(seconds, minutesOnly) {
    seconds = Math.max(0, Math.round(seconds));
    var h = minutesOnly ? 0 : Math.floor(seconds / 3600), m = minutesOnly ? Math.floor(seconds / 60) : Math.floor((seconds % 3600) / 60), s = seconds % 60;
    if (h > 0) return fill(t.hours_minutes, { h: h, m: (m < 10 ? '0' : '') + m });
    if (m > 0) return s > 0 ? fill(t.minutes_seconds, { m: m, s: s }) : fill(t.minutes, { m: m });
    return fill(t.seconds, { s: s });
  }
  function clock(seconds) {
    seconds = Math.max(0, Math.floor(seconds));
    var h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60), s = seconds % 60;
    return (h > 0 ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }
  // A file's name as a title: without its extension ("Interview.mp3" reads "Interview").
  function cleanTitle(s) {
    return String(s || '').replace(/\.(mp3|wav|m4a|ogg|oga|opus|flac|webm|mp4|m4v|mov|aac|wma|amr)$/i, '');
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; });
  }

  // ---------- Talking to the server ----------
  function csrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return (meta && meta.getAttribute('content')) || c.csrf || '';
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
      xhr.onerror = function () { reject({ xhr: { status: 0 }, body: {} }); };
      xhr.send(body);
    });
  }
  function json(method, url, data) {
    return request(method, url, data ? JSON.stringify(data) : null, data ? { 'Content-Type': 'application/json' } : {});
  }
  // What the member is told: the site's own words, never a server's raw message.
  function messageOf(r) {
    var status = r && r.xhr ? r.xhr.status : 0;
    var body = (r && r.body) || {};
    if (body.message && body.code) return body.message;
    if (status === 413) return fill(t.too_big, { mb: c.maxMb });
    if (status === 422 && body.errors) {
      var first = Object.keys(body.errors).map(function (k) { return body.errors[k][0]; })[0];
      if (first) return first;
    }
    if (status === 0) return t.network;
    return body.message || t.upload_failed;
  }
  function wait(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }
  var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // What an action did, said for a moment at the foot of the screen (and to screen readers).
  var toastBox = $('[data-tr-toast]');
  // On the page itself, so no part of the studio can hold it out of sight.
  if (toastBox) document.body.appendChild(toastBox);
  var toastTimer = 0;
  function toast(text, kind) {
    if (!toastBox || !text) return;
    toastBox.textContent = text;
    toastBox.classList.toggle('is-error', kind === 'error');
    toastBox.classList.remove('is-on');
    void toastBox.offsetWidth;
    toastBox.classList.add('is-on');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastBox.classList.remove('is-on'); }, kind === 'error' ? 4200 : 2400);
  }
  // Asked in the site's own dialog before a transcript is deleted; a press outside it is No.
  var confirmBox = $('[data-tr-confirm]');
  if (confirmBox) {
    confirmBox.addEventListener('click', function (e) { if (e.target === confirmBox) confirmBox.close('cancel'); });
  }
  function confirmDelete() {
    return new Promise(function (resolve) {
      if (!confirmBox || typeof confirmBox.showModal !== 'function') { resolve(window.confirm(t.delete_title)); return; }
      confirmBox.returnValue = '';
      confirmBox.addEventListener('close', function done() {
        confirmBox.removeEventListener('close', done);
        resolve(confirmBox.returnValue === 'ok');
      });
      confirmBox.showModal();
    });
  }

  // ---------- The box's views ----------
  // The chosen file is a card inside the add box ("chosen"), with the ways to add another below it.
  function show(name) {
    var view = name === 'chosen' ? 'drop' : name;
    $$('[data-tr-view]').forEach(function (v) {
      var was = v.hidden;
      v.hidden = v.getAttribute('data-tr-view') !== view;
      if (was && !v.hidden) appear(v);
    });
    drop.classList.toggle('has-file', name === 'chosen');
    $('[data-tr-studio]').classList.toggle('is-working', name === 'working');
    if (name !== 'chosen') stopPreview();
  }
  // A part brought into view when it is not (on a phone, the progress above the button pressed).
  function bringIntoView(el) {
    if (!el) return;
    requestAnimationFrame(function () {
      // Below whatever stays at the top of the screen (the site's bar and, on a phone, the studio's tabs).
      var clear = 16;
      $$('header, nav, [class*="tabs"], [class*="sticky"]', document).forEach(function (bar) {
        var st = getComputedStyle(bar);
        if (st.position !== 'sticky' && st.position !== 'fixed') return;
        var b = bar.getBoundingClientRect();
        if (b.top <= 1 && b.bottom > 0 && b.bottom < window.innerHeight / 2) clear = Math.max(clear, b.bottom + 12);
      });
      var r = el.getBoundingClientRect();
      if (r.top >= clear && r.bottom <= window.innerHeight - 16) return;
      window.scrollTo({ top: Math.max(0, window.scrollY + r.top - clear), behavior: calm ? 'auto' : 'smooth' });
    });
  }
  // A part that comes into view fades up into place (once; not with reduced motion).
  function appear(el) {
    if (calm || !el) return;
    el.classList.remove('tr-appear');
    void el.offsetWidth;
    el.classList.add('tr-appear');
    // Taken off once played: a transform left behind would hold the phone's menu sheets inside the part.
    el.addEventListener('animationend', function off(e) {
      if (e.target !== el) return;
      el.classList.remove('tr-appear');
      el.removeEventListener('animationend', off);
    });
  }
  function showError(text) {
    errorBox.textContent = text || '';
    errorBox.hidden = !text;
  }
  function setMeter(lines) {
    meter.innerHTML = '';
    (lines || []).forEach(function (line, i) {
      var p = document.createElement('p');
      p.className = i === 0 ? 'dub-meter-main' : 'dub-meter-aside';
      p.textContent = line;
      meter.appendChild(p);
    });
  }
  // What a file will use, as figures side by side: its length, what is left now, and after it.
  function setUsage(figures, note) {
    meter.innerHTML = '<div class="tr-usage">' + figures.map(function (f) {
      return '<div class="tr-usage-item' + (f.main ? ' is-main' : '') + '"><span>' + esc(f.label) + '</span><strong dir="auto">' + esc(f.value) + '</strong></div>';
    }).join('') + '</div>' + (note ? '<p class="tr-usage-note">' + esc(note) + '</p>' : '');
  }
  function ready(on, label) {
    submit.disabled = !on || busy;
    submitText.textContent = label || t.start_plain;
  }
  function reset() {
    chosen = null;
    busy = false;
    if (preview.src) { URL.revokeObjectURL(preview.src); preview.removeAttribute('src'); }
    fileInput.value = '';
    setMeter([]);
    showError('');
    ready(false);
    show('drop');
  }
  // One bar for the whole way, that only moves forward: sending fills its first part, getting ready
  // the next, writing out the rest (creeping on while the server works, as long as such a file
  // usually takes), and it eases to each new point instead of jumping.
  var RANGES = { upload: [0, 30], prepare: [30, 40], listen: [40, 97], done: [100, 100] };
  var order = ['upload', 'prepare', 'listen', 'done'];
  var prog = { shown: 0, target: 0, phase: '', since: 0, expect: 10, frame: 0 };
  function progressReset(seconds) {
    prog = { shown: 0, target: 0, phase: '', since: 0, expect: Math.max(8, (seconds || 30) * 0.45 + 6), frame: prog.frame };
  }
  function paintProgress() {
    var p = Math.floor(prog.shown);
    $('[data-tr-percent]').textContent = p + '%';
    $('[data-tr-bar-fill]').style.width = prog.shown.toFixed(2) + '%';
    $('[data-tr-bar]').setAttribute('aria-valuenow', String(p));
  }
  function markSteps() {
    var at = order.indexOf(prog.phase);
    $$('[data-tr-step]').forEach(function (li) {
      var i = order.indexOf(li.getAttribute('data-tr-step'));
      li.classList.toggle('is-done', i < at);
      li.classList.toggle('is-on', i === at);
    });
  }
  function progressTick() {
    prog.frame = 0;
    // Getting ready takes a moment: then the bar moves on to writing out, whether or not the
    // server has said so yet, so it never waits in one place.
    if (prog.phase === 'prepare' && (Date.now() - prog.since) / 1000 > 3) {
      prog.phase = 'listen';
      prog.since = Date.now();
      markSteps();
    }
    var r = RANGES[prog.phase];
    if (r && (prog.phase === 'prepare' || prog.phase === 'listen')) {
      var waited = (Date.now() - prog.since) / 1000;
      var share = 1 - Math.exp(-waited / (prog.phase === 'prepare' ? 4 : prog.expect / 2));
      prog.target = Math.max(prog.target, r[0] + (r[1] - r[0]) * share * 0.98);
    }
    var gap = prog.target - prog.shown;
    prog.shown = gap < 0.05 ? prog.target : prog.shown + gap * 0.07;
    paintProgress();
    if (!$('[data-tr-view="working"]').hidden) prog.frame = requestAnimationFrame(progressTick);
  }
  function working(step, percent, title, note) {
    show('working');
    $('[data-tr-working-title]').textContent = title || '';
    $('[data-tr-working-note]').textContent = note || '';
    var r = RANGES[step] || [0, 100];
    if (step !== prog.phase && (RANGES[step] || [0])[0] >= (RANGES[prog.phase] || [0])[0]) { prog.phase = step; prog.since = Date.now(); }
    prog.target = Math.min(100, Math.max(prog.target, r[0] + (r[1] - r[0]) * Math.max(0, Math.min(100, percent)) / 100));
    if (!prog.frame) prog.frame = requestAnimationFrame(progressTick);
    markSteps();
  }

  // ---------- Choosing a file ----------
  function extensionOk(name) {
    var ext = String(name || '').split('.').pop().toLowerCase();
    return (c.extensions || []).indexOf(ext) !== -1;
  }
  // The length of a file, read by the browser (a recording's, which the browser may not know at once, by seeking to its end).
  function lengthOf(file) {
    return new Promise(function (resolve) {
      var a = document.createElement('audio');
      a.preload = 'metadata';
      var done = false;
      var finish = function (s) { if (!done) { done = true; resolve(isFinite(s) && s > 0 ? s : 0); } };
      a.onloadedmetadata = function () {
        if (isFinite(a.duration) && a.duration > 0) return finish(a.duration);
        a.ontimeupdate = function () { a.ontimeupdate = null; finish(a.duration); };
        try { a.currentTime = 1e7; } catch (e) { finish(0); }
      };
      a.onerror = function () { finish(0); };
      setTimeout(function () { finish(a.duration); }, 8000);
      a.src = URL.createObjectURL(file);
    });
  }
  function whenMade(d) {
    try {
      return new Intl.DateTimeFormat(document.documentElement.lang || 'en', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(d);
    } catch (e) { return d.toLocaleString(); }
  }
  // A recording plays from its card: the button over it, the time in its corner, a thin bar along its foot.
  var playButton = $('[data-tr-preview-play]');
  function previewLength() {
    var d = preview.duration;
    return chosen && chosen.seconds > 0 ? chosen.seconds : (isFinite(d) && d > 0 ? d : 0);
  }
  function paintPreview() {
    if (!playButton) return;
    var playing = !preview.paused && !preview.ended;
    var length = previewLength();
    playButton.classList.toggle('is-playing', playing);
    playButton.setAttribute('aria-label', playButton.getAttribute(playing ? 'data-label-pause' : 'data-label-play'));
    $('[data-tr-preview-bar]').style.width = length > 0 ? Math.min(100, preview.currentTime / length * 100) + '%' : '0%';
    $('[data-tr-file-length]').textContent = length > 0 ? clock(playing || preview.currentTime > 0 ? preview.currentTime : length) : '';
  }
  function stopPreview() {
    if (!preview.paused) preview.pause();
    if (preview.currentTime) { try { preview.currentTime = 0; } catch (e) { /* not loaded yet */ } }
    paintPreview();
  }
  playButton.addEventListener('click', function () {
    if (preview.paused || preview.ended) {
      if (preview.ended) preview.currentTime = 0;
      var p = preview.play();
      if (p && p.catch) p.catch(function () { paintPreview(); });
    } else {
      preview.pause();
    }
  });
  ['play', 'pause', 'timeupdate', 'ended'].forEach(function (ev) { preview.addEventListener(ev, paintPreview); });
  preview.addEventListener('ended', function () { preview.currentTime = 0; paintPreview(); });
  function choose(file, source) {
    showError('');
    if (!file) return;
    if (!extensionOk(file.name)) return showError(t.not_audio);
    if (file.size > c.maxMb * 1024 * 1024) return showError(fill(t.too_big, { mb: c.maxMb }));
    // A recording is named for when it was made ("Recording 16 Nov, 07:25"); a file keeps its own name.
    var title = source === 'record' ? fill(t.recording_name, { date: whenMade(new Date()) }) : file.name;
    chosen = { file: file, name: file.name, title: title, source: source, seconds: 0 };
    stopPreview();
    if (preview.src) URL.revokeObjectURL(preview.src);
    preview.src = URL.createObjectURL(file);
    var name = $('[data-tr-file-name]');
    name.textContent = title;
    name.title = title;
    $('[data-tr-file-length]').textContent = '';
    // Only a recording plays here (to hear it before it is sent); a file shows its note.
    var recorded = source === 'record';
    // As an attribute: an SVG icon has no "hidden" property of its own.
    $$('[data-tr-file-icon]').forEach(function (icon) { icon.toggleAttribute('hidden', icon.getAttribute('data-tr-file-icon') !== (recorded ? 'record' : 'upload')); });
    playButton.disabled = !recorded;
    show('chosen');
    setMeter([t.checking]);
    ready(false);
    lengthOf(file).then(function (seconds) {
      if (!chosen || chosen.file !== file) return;
      chosen.seconds = seconds;
      $('[data-tr-file-length]').textContent = seconds > 0 ? clock(seconds) : '';
      paintPreview();
      if (seconds <= 0) {
        // The browser could not tell: the server measures it before anything is used.
        setMeter([]);
        return ready(true);
      }
      check(seconds, source);
    });
  }
  function check(seconds, source) {
    json('POST', c.urls.check, { seconds: seconds, source: source }).then(function (r) {
      if (!chosen) return;
      if (r.xhr.status !== 200) {
        setMeter([]);
        refused(r);
        return ready(false);
      }
      var e = r.body;
      if (e.free) {
        setUsage([{ label: t.use_file, value: time(e.seconds), main: true }], t.free_try);
      } else {
        setUsage([{ label: t.use_file, value: time(e.seconds), main: true }, { label: t.use_left, value: time(e.left, true) }, { label: t.use_after, value: time(e.remain, true) }]);
      }
      ready(true);
    }).catch(function (r) { setMeter([]); showError(messageOf(r)); });
  }
  // A refusal: its words, and the plan that fits as a link.
  function refused(r) {
    var body = r.body || {};
    showError(messageOf(r));
    if (body.upgrade && body.upgrade.url) {
      var a = document.createElement('a');
      a.className = 'dub-link';
      a.href = body.upgrade.url;
      a.textContent = ' ' + (body.upgrade.plan ? body.upgrade.plan + ' →' : '→');
      errorBox.appendChild(a);
    }
  }

  fileInput.addEventListener('change', function () { choose(fileInput.files && fileInput.files[0], 'upload'); });
  $('[data-tr-upload]').addEventListener('click', function () { fileInput.click(); });
  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
  });
  drop.addEventListener('drop', function (e) { choose(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0], 'upload'); });
  $('[data-tr-change]').addEventListener('click', reset);
  $$('[data-tr-back]').forEach(function (b) { b.addEventListener('click', function () { stopRecording(true); reset(); }); });

  // ---------- A link ----------
  var linkInput = $('[data-tr-link]');
  $('[data-tr-go-link]').addEventListener('click', function () {
    showError('');
    show('link');
    linkInput.focus();
  });
  function useLink() {
    var url = String(linkInput.value || '').trim();
    if (!/^https?:\/\//i.test(url)) return linkInput.focus();
    chosen = { url: url, source: 'url' };
    setMeter([t.fetching]);
    startLink();
  }
  $('[data-tr-link-use]').addEventListener('click', useLink);
  linkInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); useLink(); } });

  // ---------- Recording ----------
  // It starts as soon as Record is pressed: pause and resume, stop to use it, close to throw it away.
  // The bar shows the voice as it comes in; it stops by itself at the plan's longest file.
  var rec = { recorder: null, stream: null, chunks: [], elapsed: 0, since: 0, timer: null, audio: null, analyser: null, levels: [], frame: 0, discard: false };
  var recStop = $('[data-tr-rec]');
  var recPause = $('[data-tr-rec-pause]');
  var recView = $('[data-tr-view="record"]');
  var wave = $('[data-tr-rec-wave]');
  function mime() {
    var types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
    for (var i = 0; i < types.length; i++) {
      if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) return types[i];
    }
    return '';
  }
  function recSeconds() { return rec.elapsed + (rec.since ? (Date.now() - rec.since) / 1000 : 0); }
  function two(n) { return (n < 10 ? '0' : '') + n; }
  function paintClock() {
    var s = Math.floor(recSeconds());
    $('[data-tr-rec-time]').textContent = two(Math.floor(s / 60)) + ':' + two(s % 60);
  }
  // The voice as it comes in: a row of dots, those already spoken drawn as bars as tall as the voice was loud.
  function paintWave() {
    if (!wave) return;
    var ratio = window.devicePixelRatio || 1;
    var w = wave.clientWidth, h = wave.clientHeight;
    if (!w || !h) return;
    if (wave.width !== Math.round(w * ratio)) { wave.width = Math.round(w * ratio); wave.height = Math.round(h * ratio); }
    var g = wave.getContext('2d');
    g.setTransform(ratio, 0, 0, ratio, 0, 0);
    g.clearRect(0, 0, w, h);
    var css = getComputedStyle(recView);
    var on = css.getPropertyValue('--tr-wave-on').trim() || '#7659FA';
    var off = css.getPropertyValue('--tr-wave-off').trim() || 'rgba(128,128,128,.35)';
    var step = 6, count = Math.floor(w / step), start = Math.max(0, rec.levels.length - count);
    var rtl = document.documentElement.dir === 'rtl';
    for (var i = 0; i < count; i++) {
      var level = rec.levels[start + i];
      var x = (rtl ? w - (i * step + 3) : i * step + 3);
      if (level === undefined) {
        g.fillStyle = off;
        g.beginPath(); g.arc(x, h / 2, 1.2, 0, Math.PI * 2); g.fill();
      } else {
        var bar = Math.max(2.4, Math.min(h, level * h * 1.6));
        g.fillStyle = on;
        g.fillRect(x - 1.2, (h - bar) / 2, 2.4, bar);
      }
    }
  }
  function listen() {
    if (!rec.analyser) return;
    var data = new Uint8Array(rec.analyser.fftSize);
    var last = 0;
    var tick = function (now) {
      rec.frame = requestAnimationFrame(tick);
      if (now - last < 90) return;
      last = now;
      if (rec.recorder && rec.recorder.state === 'recording') {
        rec.analyser.getByteTimeDomainData(data);
        var sum = 0;
        for (var i = 0; i < data.length; i++) { var v = (data[i] - 128) / 128; sum += v * v; }
        rec.levels.push(Math.sqrt(sum / data.length));
      }
      paintWave();
    };
    rec.frame = requestAnimationFrame(tick);
  }
  function setPaused(paused) {
    recView.classList.toggle('is-paused', paused);
    recPause.setAttribute('aria-label', recPause.getAttribute(paused ? 'data-label-resume' : 'data-label-pause'));
    $('[data-tr-rec-state]').textContent = paused ? t.paused : t.recording;
  }
  function stopRecording(discard) {
    if (rec.timer) { clearInterval(rec.timer); rec.timer = null; }
    if (rec.frame) { cancelAnimationFrame(rec.frame); rec.frame = 0; }
    if (rec.since) { rec.elapsed += (Date.now() - rec.since) / 1000; rec.since = 0; }
    rec.discard = !!discard;
    if (rec.recorder && rec.recorder.state !== 'inactive') rec.recorder.stop();
    else if (rec.stream) rec.stream.getTracks().forEach(function (tr) { tr.stop(); });
    if (rec.audio && rec.audio.close) { rec.audio.close(); }
    rec.audio = null;
    rec.analyser = null;
  }
  function startRecording() {
    showError('');
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) return showError(t.mic_none);
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var type = mime();
      rec.stream = stream;
      rec.chunks = [];
      rec.levels = [];
      rec.discard = false;
      rec.elapsed = 0;
      rec.recorder = type ? new MediaRecorder(stream, { mimeType: type }) : new MediaRecorder(stream);
      rec.recorder.ondataavailable = function (e) { if (e.data && e.data.size) rec.chunks.push(e.data); };
      rec.recorder.onstop = function () {
        stream.getTracks().forEach(function (tr) { tr.stop(); });
        if (rec.discard || !rec.chunks.length) return;
        var kind = (rec.recorder.mimeType || type || 'audio/webm').split(';')[0];
        var ext = kind.indexOf('mp4') !== -1 ? 'm4a' : (kind.indexOf('ogg') !== -1 ? 'ogg' : 'webm');
        var d = new Date();
        var name = 'Recording ' + d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate()) + ' ' + two(d.getHours()) + '.' + two(d.getMinutes()) + '.' + ext;
        choose(new File(rec.chunks, name, { type: kind }), 'record');
      };
      try {
        var Ctx = window.AudioContext || window.webkitAudioContext;
        rec.audio = new Ctx();
        rec.analyser = rec.audio.createAnalyser();
        rec.analyser.fftSize = 1024;
        rec.audio.createMediaStreamSource(stream).connect(rec.analyser);
      } catch (e) { rec.analyser = null; }
      rec.recorder.start(250);
      rec.since = Date.now();
      setPaused(false);
      show('record');
      paintClock();
      listen();
      var longest = Number((c.account || {}).max_file_seconds || 0);
      rec.timer = setInterval(function () {
        paintClock();
        if (longest > 0 && recSeconds() >= longest) stopRecording(false);
      }, 250);
      recStop.focus();
    }).catch(function (e) {
      show('drop');
      showError(e && e.name === 'NotFoundError' ? t.mic_none : t.mic_denied);
    });
  }
  $$('[data-tr-go-record]').forEach(function (b) { b.addEventListener('click', function () { stopPreview(); startRecording(); }); });
  recStop.addEventListener('click', function () { stopRecording(false); });
  recPause.addEventListener('click', function () {
    if (!rec.recorder) return;
    if (rec.recorder.state === 'recording') {
      rec.recorder.pause();
      if (rec.since) { rec.elapsed += (Date.now() - rec.since) / 1000; rec.since = 0; }
      setPaused(true);
    } else if (rec.recorder.state === 'paused') {
      rec.recorder.resume();
      rec.since = Date.now();
      setPaused(false);
    }
  });
  window.addEventListener('resize', paintWave);

  // ---------- Sending and following ----------
  function sendPieces(file, upload) {
    var size = file.size;
    var stalls = 0;
    function sendOne(offset, tries) {
      var piece = file.slice(offset, Math.min(size, offset + upload.chunk_bytes));
      return request('POST', upload.chunk_url, piece, { 'Content-Type': 'application/octet-stream', 'X-Upload-Offset': String(offset) }, function (e) {
        if (e.lengthComputable) working('upload', ((offset + e.loaded) / size) * 100, t.sending, '');
      }).then(function (r) {
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
        if (stalls > 4) return Promise.reject({ xhr: { status: 0 }, body: {} });
        return from(received);
      });
    }
    return from(0);
  }
  function started(r) {
    if (r.xhr.status !== 202) return Promise.reject(r);
    syncAccount(r.body.account);
    var item = r.body.transcription;
    working('prepare', 5, t.working, t.working_note);
    prependRow(item);
    follow(item, true);
  }
  function failed(r) {
    busy = false;
    show(chosen && chosen.file ? 'chosen' : 'drop');
    refused(r);
    ready(!!(chosen && chosen.file));
  }
  function startLink() {
    busy = true;
    showError('');
    progressReset(0);
    working('upload', 3, t.fetching, '');
    bringIntoView($('[data-tr-view="working"]'));
    json('POST', c.urls.store, { url: chosen.url, language: language.value }).then(started).catch(failed);
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!chosen || busy) return;
    if (chosen.url) return startLink();
    busy = true;
    ready(false);
    showError('');
    var file = chosen.file;
    progressReset(chosen.seconds);
    working('upload', 0, t.sending, '');
    bringIntoView($('[data-tr-view="working"]'));
    var start = new FormData();
    start.set('name', file.name);
    start.set('size', String(file.size));
    request('POST', c.urls.upload, start, {}).then(function (r) {
      if (r.xhr.status !== 201) return Promise.reject(r);
      return sendPieces(file, r.body).then(function () {
        working('prepare', 2, t.working, t.working_note);
        return json('POST', c.urls.store, { upload_id: r.body.id, language: language.value, source: chosen.source, title: chosen.title || file.name });
      });
    }).then(started).catch(failed);
  });

  // A transcript working: asked about every few seconds until it is done; in the box when it was started here.
  function follow(item, inBox) {
    if (following[item.id]) return;
    following[item.id] = true;
    var tries = 0;
    var since = Date.now();
    function poll() {
      json('GET', item.urls.show).then(function (r) {
        if (r.xhr.status !== 200) throw r;
        var tr = r.body.transcription;
        updateRow(tr);
        if (r.body.account) syncAccount(r.body.account);
        if (tr.status === 'queued' || tr.status === 'processing') {
          if (inBox) working(tr.progress < 15 ? 'prepare' : 'listen', tr.progress, t.working, t.working_note);
          // Asked often at first (a short file is done in seconds), then less.
          return setTimeout(poll, Date.now() - since < 20000 ? 1200 : 2500);
        }
        delete following[item.id];
        if (inBox && tr.status === 'ready') {
          // The bar fills, then the transcript opens.
          working('done', 100, t.working, t.working_note);
          setTimeout(function () { reset(); open(tr); }, 700);
        } else if (inBox) {
          reset();
          showError(tr.error);
        }
      }).catch(function () {
        tries += 1;
        setTimeout(poll, tries > 20 ? 15000 : 6000);
      });
    }
    setTimeout(poll, inBox ? 700 : 1500);
  }

  // ---------- The account at the top ----------
  function syncAccount(a) {
    if (!a) return;
    c.account = a;
    var left = $('[data-tr-left]');
    if (left) left.textContent = a.left;
    var used = $('[data-tr-used]');
    if (used) used.textContent = a.used;
    var bar = $('[data-tr-left-bar]');
    if (bar && a.allowance_seconds > 0) bar.style.width = Math.max(0, Math.min(100, Math.round(a.left_seconds / a.allowance_seconds * 100))) + '%';
  }

  // ---------- The list ----------
  var ICONS = {
    open: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6.5 9.5-6.5 9.5 6.5 9.5 6.5-3.5 6.5-9.5 6.5S2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/></svg>',
    download: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 19h14"/></svg>',
    rename: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10.5-10.5a2.8 2.8 0 0 0-4-4L4 16v4z"/></svg>',
    remove: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 7h15M9.5 7V4.5h5V7M6.5 7l1 12.5h9l1-12.5"/></svg>'
  };
  function badge(item) {
    if (item.status === 'failed') return '<span class="dub-badge is-failed">' + esc(t.status_failed) + '</span>';
    if (item.status === 'ready') return item.free ? '<span class="dub-badge is-free">' + esc(t.status_free) + '</span>' : '<span class="dub-badge is-ready">' + esc(t.status_ready) + '</span>';
    return '<span class="dub-badge is-working">' + esc(t.status_working) + '</span>';
  }
  function rowHtml(item) {
    var L = c.columns || {};
    return '<td class="num" data-label="' + esc(L.date) + '">' + esc(item.date) + '</td>'
      + '<td class="tr-name"><button type="button" class="dub-link" data-tr-open dir="auto">' + esc(cleanTitle(item.title)) + '</button></td>'
      + '<td data-label="' + esc(L.language) + '">' + esc(item.language_name || '—') + '</td>'
      // One length: what the file is and what it used are the same seconds.
      + '<td class="num" data-label="' + esc(L.length) + '">' + esc(item.length || '—') + '</td>'
      + '<td data-label="' + esc(L.status) + '">' + badge(item) + '</td>'
      + '<td class="tr-actions-cell">'
      + (item.status === 'queued' || item.status === 'processing' ? ''
        : '<button type="button" class="tr-icon tr-row-delete" data-tr-remove aria-label="' + esc(fill(t.delete_named, { name: cleanTitle(item.title) })) + '" title="' + esc(t.delete) + '">' + ICONS.remove + '</button>')
      + '</td>';
  }
  function bindRow(tr, item) {
    tr._item = item;
    tr.setAttribute('data-tr-row', item.id);
    tr.innerHTML = rowHtml(item);
    $$('[data-tr-open]', tr).forEach(function (b) {
      b.addEventListener('click', function () { if (tr._item.status === 'ready') open(tr._item); else if (tr._item.status === 'failed') showError(tr._item.error); });
    });
    var remove = $('[data-tr-remove]', tr);
    if (remove) remove.addEventListener('click', function () { removeItem(tr._item); });
  }
  function emptyRow(text) {
    rows.innerHTML = '<tr><td colspan="7" class="dub-ledger-empty">' + esc(text) + '</td></tr>';
  }
  function load(reset) {
    if (reset) page = 1;
    var q = String(search.value || '').trim();
    json('GET', c.urls.index + '?page=' + page + (q ? '&q=' + encodeURIComponent(q) : '')).then(function (r) {
      if (r.xhr.status !== 200) return;
      if (reset) rows.innerHTML = '';
      var items = r.body.items || [];
      if (reset && !items.length) emptyRow(q ? $('[data-tr-no-match]').innerHTML : $('[data-tr-empty-text]').innerHTML);
      items.forEach(function (item) {
        var tr = document.createElement('tr');
        bindRow(tr, item);
        rows.appendChild(tr);
        if (item.status === 'queued' || item.status === 'processing') follow(item, false);
      });
      moreButton.hidden = !r.body.next;
      page = r.body.next || page;
      if (r.body.account) syncAccount(r.body.account);
    });
  }
  function prependRow(item) {
    var empty = rows.querySelector('.dub-ledger-empty');
    if (empty) rows.innerHTML = '';
    var tr = document.createElement('tr');
    bindRow(tr, item);
    rows.insertBefore(tr, rows.firstChild);
    if (!calm) tr.classList.add('is-new');
  }
  function updateRow(item) {
    var tr = rows.querySelector('[data-tr-row="' + item.id + '"]');
    if (tr) bindRow(tr, item);
  }
  function removeItem(item) {
    confirmDelete().then(function (yes) {
      if (!yes) return;
      var tr = rows.querySelector('[data-tr-row="' + item.id + '"]');
      if (current && current.id === item.id) close();
      if (tr) tr.classList.add('is-leaving');
      json('DELETE', item.urls.delete).then(function (r) {
        if (r.xhr.status !== 200) throw r;
        setTimeout(function () {
          if (tr) tr.remove();
          if (!rows.children.length) emptyRow($('[data-tr-empty-text]').innerHTML);
        }, calm ? 0 : 240);
      }).catch(function (r) {
        if (tr) tr.classList.remove('is-leaving');
        toast(messageOf(r), 'error');
      });
    });
  }
  var searchTimer = null;
  search.addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(function () { load(true); }, 300); });
  moreButton.addEventListener('click', function () { load(false); });

  // Menus: one open at a time, as the studio's dropdowns behave: opened by click, Enter, Space
  // or the down arrow; moved through with the arrows, Home and End; closed by a click
  // elsewhere, Escape (back to its button) or Tab. One near the bottom of the screen opens upwards.
  function menuItems(m) { return $$('a, button', $('.tr-menu-list', m)).filter(function (item) { return !item.hidden; }); }
  // A menu with a search box (the languages): what is typed narrows it, by either name or code.
  function fold(s) { return String(s || '').toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f\u064b-\u065f\u0670]/g, '').replace(/[أإآ]/g, 'ا').trim(); }
  function filterMenu(m, query) {
    var q = fold(query), any = false;
    $$('[data-search]', $('.tr-menu-list', m)).forEach(function (item) {
      var on = q === '' || fold(item.getAttribute('data-search')).indexOf(q) !== -1;
      item.hidden = !on;
      any = any || on;
    });
    var none = $('[data-tr-language-none]', m);
    if (none) none.hidden = any;
  }
  function setMenu(m, open, focusFirst) {
    var list = $('.tr-menu-list', m);
    var button = $('[data-tr-menu-button]', m);
    if (open) {
      $$('[data-tr-menu].is-open').forEach(function (other) { if (other !== m) setMenu(other, false); });
      var search = $('input[type=search]', m);
      if (search) { search.value = ''; filterMenu(m, ''); }
      list.hidden = false;
      var room = window.innerHeight - button.getBoundingClientRect().bottom;
      m.classList.toggle('is-up', room < list.offsetHeight + 16 && button.getBoundingClientRect().top > list.offsetHeight + 16);
      if (search) {
        // Straight into the search box, the chosen language in view below it.
        var chosen = $('[aria-checked="true"]', m);
        if (chosen) chosen.scrollIntoView({ block: 'nearest' });
        search.focus();
      } else if (focusFirst) { var first = menuItems(m)[0]; if (first) first.focus(); }
    } else {
      list.hidden = true;
      m.classList.remove('is-up');
    }
    m.classList.toggle('is-open', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  document.addEventListener('click', function (e) {
    var button = e.target.closest && e.target.closest('[data-tr-menu-button]');
    var inMenu = e.target.closest && e.target.closest('[data-tr-menu]');
    if (button) {
      var m = button.closest('[data-tr-menu]');
      // A click (e.detail > 0) opens it as it is; Enter or Space also moves into it.
      setMenu(m, !m.classList.contains('is-open'), e.detail === 0);
      return;
    }
    // On a phone a menu is a sheet over the page: a tap on the page around it closes it.
    if (inMenu && e.target === inMenu) { setMenu(inMenu, false); return; }
    // A choice in a menu closes it; a click anywhere else closes them all (not one in its search box or heading).
    if (inMenu && !(e.target.closest('a, button'))) return;
    $$('[data-tr-menu].is-open').forEach(function (m) { if (!inMenu || m === inMenu) setMenu(m, false); });
  });
  root.addEventListener('keydown', function (e) {
    var m = e.target.closest && e.target.closest('[data-tr-menu]');
    if (!m) return;
    var items = menuItems(m);
    var onButton = e.target.matches('[data-tr-menu-button]');
    var at = items.indexOf(e.target);
    if (e.target.matches('input[type=search]')) {
      if (e.key === 'ArrowDown' || e.key === 'Enter') {
        e.preventDefault();
        if (items[0]) (e.key === 'Enter' ? items[0].click() : items[0].focus());
      } else if (e.key === 'Escape') {
        e.preventDefault();
        setMenu(m, false);
        $('[data-tr-menu-button]', m).focus();
      } else if (e.key === 'Tab') {
        setMenu(m, false);
      }
      return;
    }
    if (onButton && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
      e.preventDefault();
      setMenu(m, true, false);
      (e.key === 'ArrowDown' ? items[0] : items[items.length - 1]).focus();
    } else if (!onButton && at !== -1) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        var search = $('input[type=search]', m);
        if (search && at === 0 && e.key === 'ArrowUp') { search.focus(); return; }
        items[(at + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus();
      } else if (e.key === 'Home' || e.key === 'End') {
        e.preventDefault();
        (e.key === 'Home' ? items[0] : items[items.length - 1]).focus();
      } else if (e.key === 'Escape') {
        e.preventDefault();
        setMenu(m, false);
        $('[data-tr-menu-button]', m).focus();
      } else if (e.key === 'Tab') {
        setMenu(m, false);
      }
    } else if (onButton && e.key === 'Escape' && m.classList.contains('is-open')) {
      setMenu(m, false);
    }
  });
  // A new width (a phone turned) closes them; a phone's keyboard opening (only the height) does not.
  var lastWidth = window.innerWidth;
  window.addEventListener('resize', function () {
    if (window.innerWidth === lastWidth) return;
    lastWidth = window.innerWidth;
    $$('[data-tr-menu].is-open').forEach(function (m) { setMenu(m, false); });
  });

  // ---------- The reader ----------
  var audio = null;
  var opening = '';
  function open(item) {
    // The editor takes the page at once, its text drawn in while it loads; the rest comes back with the back arrow.
    opening = item.id;
    if (audio) audio.pause();
    current = null;
    root.classList.add('is-reading');
    document.body.classList.add('tr-is-reading');
    reader.hidden = false;
    reader.classList.add('is-loading');
    bar.hidden = true;
    $('[data-tr-translate-note]').hidden = true;
    $('[data-tr-audio-gone]').hidden = true;
    $('[data-tr-reader-title]').textContent = cleanTitle(item.title);
    ['length', 'language', 'date'].forEach(function (k) { $('[data-tr-fact="' + k + '"]').textContent = k === 'length' ? (item.length || '') : k === 'date' ? (item.date || '') : (item.language_name || ''); });
    var ghost = '';
    for (var i = 0; i < 4; i++) ghost += '<div class="tr-skel" aria-hidden="true"><span class="tr-skel-head"></span><span class="tr-skel-line"></span><span class="tr-skel-line is-short"></span></div>';
    $('[data-tr-text]').innerHTML = ghost;
    appear(reader);
    reader.focus({ preventScroll: true });
    root.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' });
    json('GET', item.urls.show).then(function (r) {
      if (opening !== item.id) return;
      if (r.xhr.status !== 200) throw r;
      current = r.body.transcription;
      shownLanguage = '';
      languageMenu('');
      reader.classList.remove('is-loading');
      render(current);
      loadWave(current);
    }).catch(function (r) {
      if (opening !== item.id) return;
      close();
      toast(messageOf(r), 'error');
    });
  }
  function close() {
    opening = '';
    if (audio) audio.pause();
    bar.hidden = true;
    reader.hidden = true;
    reader.classList.remove('is-loading');
    root.classList.remove('is-reading');
    document.body.classList.remove('tr-is-reading');
    current = null;
    $$('[data-tr-root] > :not(.tr-reader):not(.tr-dialog):not(.tr-toast)', document).forEach(appear);
  }
  $('[data-tr-close]').addEventListener('click', close);
  // The name is changed where it is, as a paragraph is: a box in its place with Save and Cancel.
  function renameInline() {
    if (!current) return;
    var h = $('[data-tr-reader-title]');
    var wrap = h.parentNode;
    if ($('.tr-title-edit', wrap)) return;
    var item = current;
    var box = document.createElement('div');
    box.className = 'tr-title-edit';
    box.innerHTML = '<input type="text" class="tr-title-input" maxlength="120" dir="auto">'
      + '<button type="button" class="btn btn-primary">' + esc(t.save) + '</button>'
      + '<button type="button" class="btn btn-ghost">' + esc(t.cancel) + '</button>';
    var input = $('input', box);
    input.value = cleanTitle(item.title);
    input.setAttribute('aria-label', t.rename);
    h.hidden = true;
    wrap.insertBefore(box, h.nextSibling);
    input.focus();
    input.select();
    function done() { box.remove(); h.hidden = false; }
    function save() {
      var title = input.value.trim();
      if (!title || title === cleanTitle(item.title)) return done();
      var before = item.title;
      item.title = title;
      h.textContent = title;
      done();
      json('PATCH', item.urls.update, { title: title }).then(function (r) {
        if (r.xhr.status !== 200) throw r;
        updateRow(r.body.transcription);
      }).catch(function (r) {
        item.title = before;
        if (current === item) h.textContent = cleanTitle(before);
        toast(messageOf(r), 'error');
      });
    }
    var buttons = $$('button', box);
    buttons[0].addEventListener('click', save);
    buttons[1].addEventListener('click', done);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); save(); } else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(); }
    });
  }
  $('[data-tr-rename-open]').addEventListener('click', renameInline);
  $('[data-tr-reader-title]').addEventListener('dblclick', renameInline);
  // The keyboard while the editor is open: space plays and pauses, the arrows move 5 s (1 s with shift).
  document.addEventListener('keydown', function (e) {
    if (reader.hidden || !audio || e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return;
    var el = e.target;
    if (el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || el.closest('dialog,[role="menu"],[role="slider"]'))) return;
    if (e.key === ' ' && !(el && el.closest('button,a'))) { e.preventDefault(); togglePlay(); return; }
    if ((e.key === 'ArrowLeft' || e.key === 'ArrowRight') && !(el && el.closest('button,a'))) {
      e.preventDefault();
      audio.currentTime = Math.max(0, Math.min(lengthOfAudio(), audio.currentTime + (e.key === 'ArrowRight' ? 1 : -1) * (e.shiftKey ? 1 : 5)));
      paintBar();
    }
  });

  // ---------- The timeline: a ruler, a track for each speaker, the play head ----------
  var bar = $('[data-tr-playbar]');
  var tlScroll = $('[data-tr-tl-scroll]');
  var tlCanvas = $('[data-tr-tl-canvas]');
  var tlHead = $('[data-tr-tl-head]');
  var tlBadge = $('[data-tr-tl-badge]');
  var SPEEDS = [1, 1.25, 1.5, 2, 0.75];
  var tl = { pps: 0, frame: 0, dragging: false, clips: [], lit: null, bars: null, colors: null };
  var waveCanvas = $('[data-tr-tl-wave]');
  var hoverLine = $('[data-tr-tl-hover]');
  var waves = {};
  var WAVE_BAR = 2;
  var WAVE_STEP = 3;
  function lengthOfAudio() {
    var d = audio ? audio.duration : 0;
    return isFinite(d) && d > 0 ? d : (current ? Number(current.seconds) || 0 : 0);
  }
  // 1:05.3 under the play head: to the tenth, as an editor shows it.
  function clockTenths(seconds) {
    seconds = Math.max(0, seconds || 0);
    var whole = Math.floor(seconds);
    return clock(whole) + '.' + Math.floor((seconds - whole) * 10);
  }
  function speakersShown() {
    var list = ((current && current.speaker_list) || []).map(function (s) { return s.id; });
    (shown.segments || []).forEach(function (s) { if (list.indexOf(s.speaker) === -1) list.push(s.speaker); });
    return list.length ? list : ['A'];
  }
  function buildTimeline() {
    if (!current || bar.hidden) return;
    var total = lengthOfAudio();
    var view = tlScroll.clientWidth || 600;
    if (!total) return;
    // The whole recording in view.
    tl.pps = view / total;
    var width = Math.max(view, Math.round(total * tl.pps));
    tlCanvas.style.width = width + 'px';
    // The ruler: a label every step that leaves room for it, small marks between.
    var steps = [0.5, 1, 2, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600];
    var step = steps.filter(function (x) { return x * tl.pps >= 70; })[0] || 3600;
    var minor = step / 5;
    var ruler = '';
    for (var at = 0; at <= total + 0.001; at += minor) {
      var major = Math.abs(at / step - Math.round(at / step)) < 0.001;
      ruler += '<span class="tr-tl-tick' + (major ? ' is-major' : '') + '" style="left:' + (at * tl.pps).toFixed(1) + 'px">'
        + (major && at > 0 ? '<b>' + esc(step < 1 ? clockTenths(at) : clock(Math.round(at))) + '</b>' : '') + '</span>';
    }
    $('[data-tr-tl-ruler]').innerHTML = ruler;
    // A row for each speaker, each sentence a block where it is said (its words are in the text above).
    // One speaker: no names column. Several: each name, in its colour, renamed right there.
    var who = speakersShown();
    var several = who.length > 1;
    reader.classList.toggle('is-solo', !several);
    var tracks = '';
    var labels = '<div class="tr-tl-label tr-tl-label-head">' + esc(t.speakers) + '</div>'
      + '<div class="tr-tl-label tr-tl-label-wave" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 12h2M7 8v8M11 5v14M15 9v6M19 11v2"/></svg></div>';
    var segs = shown.segments || [];
    who.forEach(function (id) {
      var n = speakerIndex(id) % 4;
      labels += '<div class="tr-tl-label"><span class="tr-swatch" data-n="' + n + '" aria-hidden="true">' + esc(id) + '</span>'
        + '<input type="text" class="tr-tl-name" data-tr-speaker="' + esc(id) + '" maxlength="60" dir="auto" value="' + esc(nameOf(id)) + '" aria-label="' + esc(fill(t.speaker_name, { speaker: nameOf(id) })) + '"></div>';
      tracks += '<div class="tr-tl-row">';
      segs.forEach(function (seg, i) {
        if (seg.speaker !== id) return;
        var left = Math.max(0, seg.start * tl.pps);
        var w = Math.max(3, (Math.max(seg.end, seg.start + 0.2) - seg.start) * tl.pps - 2);
        tracks += '<button type="button" class="tr-tl-clip" data-n="' + n + '" data-i="' + i + '" tabindex="-1" style="left:' + left.toFixed(1) + 'px;width:' + w.toFixed(1) + 'px" aria-label="' + esc(clock(Math.floor(seg.start))) + '"></button>';
      });
      tracks += '</div>';
    });
    $('[data-tr-tl-tracks]').innerHTML = tracks;
    $('[data-tr-tl-labels]').innerHTML = labels;
    tl.clips = $$('.tr-tl-clip', tlCanvas);
    tl.byIndex = [];
    tl.clips.forEach(function (clip) {
      tl.byIndex[Number(clip.getAttribute('data-i'))] = clip;
      clip.addEventListener('click', function (e) {
        e.stopPropagation();
        var seg = segs[Number(clip.getAttribute('data-i'))];
        if (seg) seek(seg.start);
      });
    });
    tl.starts = segs.map(function (x) { return Number(x.start); });
    tl.ends = segs.map(function (x) { return Number(x.end); });
    $$('[data-tr-speaker]', $('[data-tr-tl-labels]')).forEach(function (input) {
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); input.blur(); } else if (e.key === 'Escape') { input.value = nameOf(input.getAttribute('data-tr-speaker')); input.blur(); } });
      input.addEventListener('change', function () { renameSpeaker(input.getAttribute('data-tr-speaker'), input.value); });
    });
    tlScroll.setAttribute('aria-valuemax', String(Math.round(total)));
    tl.lit = null;
    waveBars();
    paintHead();
  }
  // ---------- The waveform ----------
  // The recording decoded once, at a low rate (enough to draw it), into a peak for every 10 ms.
  function loadWave(tr) {
    if (!tr || !tr.audio || waves[tr.id] || !window.fetch || !(window.OfflineAudioContext || window.webkitOfflineAudioContext) || Number(tr.seconds) > 1800) return;
    var id = tr.id;
    waves[id] = 'loading';
    fetch(tr.urls.audio, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('audio');
      return r.arrayBuffer();
    }).then(function (buf) {
      var Ctx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
      var rate = 8000;
      var ctx = new Ctx(1, Math.max(rate, Math.ceil((Number(tr.seconds) || 1) * rate)), rate);
      return new Promise(function (ok, no) { var p = ctx.decodeAudioData(buf, ok, no); if (p && p.then) p.then(ok, no); });
    }).then(function (sound) {
      var data = sound.getChannelData(0);
      var per = 100;
      var step = Math.max(1, Math.floor(sound.sampleRate / per));
      var n = Math.ceil(data.length / step);
      var peaks = new Float32Array(n);
      var top = 0;
      for (var i = 0; i < n; i++) {
        var m = 0;
        for (var j = i * step, e = Math.min(data.length, j + step); j < e; j++) { var v = data[j] < 0 ? -data[j] : data[j]; if (v > m) m = v; }
        peaks[i] = m;
        if (m > top) top = m;
      }
      // Quiet speech kept visible beside loud: each peak against the loudest, eased.
      for (i = 0; i < n; i++) peaks[i] = top > 0 ? Math.sqrt(peaks[i] / top) : 0;
      waves[id] = { peaks: peaks, per: n / Math.max(0.001, data.length / sound.sampleRate) };
      if (current && current.id === id) { waveBars(); drawWave(); }
    }).catch(function () { waves[id] = 'none'; });
  }
  // A bar for every few pixels of the whole timeline at this zoom: worked out once, drawn as it scrolls.
  function waveBars() {
    var w = current && waves[current.id];
    tl.bars = null;
    if (!w || typeof w !== 'object' || !tl.pps) { drawWave(); return; }
    var width = Math.round(lengthOfAudio() * tl.pps);
    var count = Math.ceil(width / WAVE_STEP);
    var bars = new Float32Array(count);
    for (var b = 0; b < count; b++) {
      var i0 = Math.floor((b * WAVE_STEP / tl.pps) * w.per);
      var i1 = Math.max(i0 + 1, Math.ceil(((b * WAVE_STEP + WAVE_BAR) / tl.pps) * w.per));
      var m = 0;
      for (var i = i0; i < i1 && i < w.peaks.length; i++) if (w.peaks[i] > m) m = w.peaks[i];
      bars[b] = m;
    }
    tl.bars = bars;
    var style = getComputedStyle(waveCanvas);
    tl.colors = { base: style.getPropertyValue('--wave').trim() || '#888', lit: style.getPropertyValue('--wave-lit').trim() || '#7c5cff' };
    reader.classList.add('has-wave');
    drawWave();
  }
  function drawWave() {
    if (!waveCanvas) return;
    var view = tlScroll.clientWidth;
    var h = waveCanvas.parentNode.clientHeight || 44;
    var ratio = Math.min(2, window.devicePixelRatio || 1);
    if (waveCanvas.width !== Math.round(view * ratio) || waveCanvas.height !== Math.round(h * ratio)) {
      waveCanvas.width = Math.round(view * ratio);
      waveCanvas.height = Math.round(h * ratio);
      waveCanvas.style.width = view + 'px';
      waveCanvas.style.height = h + 'px';
    }
    var g = waveCanvas.getContext('2d');
    g.setTransform(ratio, 0, 0, ratio, 0, 0);
    g.clearRect(0, 0, view, h);
    if (!tl.bars) return;
    var left = tlScroll.scrollLeft;
    var played = (audio ? audio.currentTime : 0) * tl.pps - left;
    var first = Math.floor(left / WAVE_STEP);
    var last = Math.min(tl.bars.length, Math.ceil((left + view) / WAVE_STEP));
    for (var pass = 0; pass < 2; pass++) {
      g.fillStyle = pass ? tl.colors.lit : tl.colors.base;
      g.beginPath();
      for (var b = first; b < last; b++) {
        var x = b * WAVE_STEP - left;
        if ((x + WAVE_BAR <= played) !== !!pass) continue;
        var bh = Math.max(2, tl.bars[b] * (h - 10));
        g.rect(x, (h - bh) / 2, WAVE_BAR, bh);
      }
      g.fill();
    }
  }
  tlScroll.addEventListener('scroll', function () { if (!tl.frame) drawWave(); }, { passive: true });
  // Where a press would go: a thin line and its time, under the pointer (not on touch screens).
  tlCanvas.addEventListener('pointermove', function (e) {
    if (e.pointerType === 'touch' || tl.dragging || !tl.pps) return;
    var r = tlCanvas.getBoundingClientRect();
    var x = Math.max(0, e.clientX - r.left);
    hoverLine.hidden = false;
    hoverLine.style.transform = 'translateX(' + x.toFixed(1) + 'px)';
    $('[data-tr-tl-hover-time]').textContent = clockTenths(x / tl.pps);
  });
  tlCanvas.addEventListener('pointerleave', function () { hoverLine.hidden = true; });
  function paintHead() {
    if (!audio || !tl.pps) return;
    var now = audio.currentTime || 0;
    var x = now * tl.pps;
    tlHead.style.transform = 'translateX(' + x.toFixed(1) + 'px)';
    tlBadge.textContent = clockTenths(now);
    // The time stays whole at either end of the timeline.
    var bw = tlBadge.offsetWidth || 48;
    var edge = Math.max(0, Math.min((parseFloat(tlCanvas.style.width) || tlScroll.clientWidth) - bw, x - bw / 2));
    tlBadge.style.transform = 'translateX(' + (edge - x).toFixed(1) + 'px)';
    tlScroll.setAttribute('aria-valuenow', String(Math.round(now)));
    tlScroll.setAttribute('aria-valuetext', clock(now) + ' / ' + clock(lengthOfAudio()));
    var heard = heardAt(tl.starts, tl.ends, now);
    var hit = heard >= 0 ? tl.byIndex[heard] || null : null;
    if (hit !== tl.lit) {
      if (tl.lit) tl.lit.classList.remove('is-now');
      if (hit) hit.classList.add('is-now');
      tl.lit = hit;
    }
    // While it plays, the head stays in view.
    if (!audio.paused && !tl.dragging) {
      var view = tlScroll.clientWidth;
      if (x < tlScroll.scrollLeft + 24 || x > tlScroll.scrollLeft + view - 48) tlScroll.scrollLeft = Math.max(0, x - view * 0.25);
    }
    drawWave();
  }
  function followHead() {
    tl.frame = 0;
    paintHead();
    light(false);
    if (audio && !audio.paused && !bar.hidden) tl.frame = requestAnimationFrame(followHead);
  }
  function paintBar() {
    if (!audio) return;
    var playing = !audio.paused && !audio.ended;
    var play = $('[data-tr-pb-play]');
    play.classList.toggle('is-playing', playing);
    play.setAttribute('aria-label', play.getAttribute(playing ? 'data-label-pause' : 'data-label-play'));
    $('[data-tr-pb-now]').textContent = clock(audio.currentTime);
    // The transcript's own length, as its details and the list say it.
    $('[data-tr-pb-total]').textContent = clock(Math.round(current && Number(current.seconds) > 0 ? Number(current.seconds) : lengthOfAudio()));
    paintHead();
    if (playing && !tl.frame) tl.frame = requestAnimationFrame(followHead);
  }
  function togglePlay() {
    if (!audio) return;
    if (audio.paused || audio.ended) { var p = audio.play(); if (p && p.catch) p.catch(paintBar); } else { audio.pause(); }
  }
  $('[data-tr-pb-play]').addEventListener('click', togglePlay);
  $('[data-tr-pb-speed]').addEventListener('click', function (e) {
    if (!audio) return;
    var next = SPEEDS[(SPEEDS.indexOf(audio.playbackRate) + 1) % SPEEDS.length] || 1;
    audio.playbackRate = next;
    e.currentTarget.textContent = (next === 1 ? '1' : String(next)) + '×';
  });
  // A press or a drag anywhere on the timeline puts the recording there.
  function timeAt(clientX) {
    var r = tlCanvas.getBoundingClientRect();
    return Math.max(0, Math.min(lengthOfAudio(), (clientX - r.left) / (tl.pps || 1)));
  }
  tlCanvas.addEventListener('pointerdown', function (e) {
    if (!audio || e.button !== 0 || e.target.closest('.tr-tl-clip')) return;
    tl.dragging = true;
    try { tlCanvas.setPointerCapture(e.pointerId); } catch (err) { /* older browsers */ }
    audio.currentTime = timeAt(e.clientX);
    paintBar();
  });
  tlCanvas.addEventListener('pointermove', function (e) {
    if (!tl.dragging || !audio) return;
    audio.currentTime = timeAt(e.clientX);
    paintHead();
  });
  ['pointerup', 'pointercancel'].forEach(function (ev) {
    tlCanvas.addEventListener(ev, function () { if (tl.dragging) { tl.dragging = false; light(true); } });
  });
  tlScroll.addEventListener('keydown', function (e) {
    if (!audio) return;
    var to = null;
    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') to = audio.currentTime + (e.key === 'ArrowRight' ? 1 : -1) * (e.shiftKey ? 1 : 5);
    else if (e.key === 'Home') to = 0;
    else if (e.key === 'End') to = lengthOfAudio();
    else if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); togglePlay(); return; }
    if (to === null) return;
    e.preventDefault();
    audio.currentTime = Math.max(0, Math.min(lengthOfAudio(), to));
    paintBar();
  });
  var tlResize = 0;
  window.addEventListener('resize', function () {
    clearTimeout(tlResize);
    tlResize = setTimeout(buildTimeline, 150);
  });

  function render(tr) {
    $('[data-tr-reader-title]').textContent = cleanTitle(tr.title);
    // Its details, each said once.
    $('[data-tr-fact="length"]').textContent = tr.length || '—';
    $('[data-tr-fact="language"]').textContent = tr.language_name || '—';
    $('[data-tr-fact="date"]').textContent = tr.date || '—';
    if (audio) { audio.pause(); audio.removeAttribute('src'); audio.load(); }
    audio = $('[data-tr-audio]');
    audio.playbackRate = 1;
    $('[data-tr-pb-speed]').textContent = '1×';
    tlScroll.scrollLeft = 0;
    reader.classList.toggle('has-wave', !!(waves[tr.id] && typeof waves[tr.id] === 'object'));
    var gone = $('[data-tr-audio-gone]');
    if (tr.audio) {
      audio.src = tr.urls.audio;
      bar.hidden = false;
      gone.hidden = true;
    } else {
      audio.removeAttribute('src');
      audio = null;
      bar.hidden = true;
      gone.textContent = fill(t.audio_gone, { days: c.keepDays });
      gone.hidden = false;
    }
    shown = { paragraphs: tr.paragraphs, segments: tr.segments };
    renderText(tr.rtl, true);
    paintBar();
  }
  (function () {
    var a = $('[data-tr-audio]');
    ['play', 'pause', 'ended'].forEach(function (ev) { a.addEventListener(ev, paintBar); });
    ['loadedmetadata', 'durationchange'].forEach(function (ev) { a.addEventListener(ev, function () { buildTimeline(); paintBar(); }); });
    a.addEventListener('timeupdate', function () { if (audio && audio.paused) paintBar(); light(false); });
    a.addEventListener('seeked', function () { paintBar(); light(true); });
  })();
  function speakerIndex(letter) {
    var list = (current && current.speaker_list) || [];
    for (var i = 0; i < list.length; i++) if (list[i].id === letter) return i;
    return 0;
  }
  function nameOf(letter) {
    var list = (current && current.speaker_list) || [];
    for (var i = 0; i < list.length; i++) if (list[i].id === letter) return list[i].name;
    return letter;
  }
  // A speaker's name changed on the timeline: saved, then shown everywhere it is said.
  function renameSpeaker(id, name) {
    if (!current) return;
    var names = {};
    names[id] = String(name || '').trim();
    var item = current;
    json('PATCH', item.urls.update, { speakers: names }).then(function (r) {
      if (r.xhr.status !== 200) throw r;
      if (current !== item) return;
      current.speaker_list = r.body.transcription.speaker_list;
      renderText(current.rtl && !shownLanguage, !shownLanguage);
    }).catch(function (r) { toast(messageOf(r), 'error'); if (current === item) buildTimeline(); });
  }
  // The paragraphs as a document: who speaks and when (the time plays from there), the sentences
  // to click, and a pencil to correct one (in the original only).
  function renderText(rtl, editable) {
    var box = $('[data-tr-text]');
    box.innerHTML = '';
    lit = null;
    box.classList.toggle('tr-rtl', !!rtl);
    // The page laid out the way its language reads: who speaks, when, and what on the same side.
    box.setAttribute('dir', rtl ? 'rtl' : 'ltr');
    var several = ((current && current.speaker_list) || []).length > 1;
    (shown.paragraphs || []).forEach(function (p, i) {
      var div = document.createElement('div');
      div.className = 'tr-para';
      var sentences = (shown.segments || []).filter(function (s) { return s.paragraph === i; });
      var body = sentences.length ? sentences.map(function (s) {
        return '<span class="tr-s" data-start="' + s.start + '" data-end="' + s.end + '">' + esc(s.text) + '</span>';
      }).join(' ') : esc(p.text);
      div.innerHTML = '<div class="tr-para-head">'
        + (several ? '<span class="tr-swatch" data-n="' + (speakerIndex(p.speaker) % 4) + '" aria-hidden="true">' + esc(p.speaker) + '</span><span class="tr-para-who">' + esc(nameOf(p.speaker)) + '</span>' : '')
        + (audio ? '<button type="button" class="tr-para-time" data-tr-play dir="ltr" aria-label="' + esc(t.play_from) + ' ' + esc(clock(p.start || 0)) + '">' + esc(clock(p.start || 0)) + '</button>'
          : '<span class="tr-para-time" dir="ltr">' + esc(clock(p.start || 0)) + '</span>')
        + (editable ? '<button type="button" class="tr-icon tr-para-edit-btn" data-tr-edit aria-label="' + esc(t.edit) + '" title="' + esc(t.edit) + '"><svg viewBox="0 0 24 24"><path d="M4 20h4l10.5-10.5a2.8 2.8 0 0 0-4-4L4 16v4z"/></svg></button>' : '')
        + '</div><div class="tr-para-body" dir="auto">' + body + '</div>';
      var play = $('[data-tr-play]', div);
      if (play) play.addEventListener('click', function () { seek(p.start || 0); });
      var edit = $('[data-tr-edit]', div);
      if (edit) edit.addEventListener('click', function () { editParagraph(div, i, p); });
      $$('.tr-s', div).forEach(function (span) {
        span.addEventListener('click', function () { seek(parseFloat(span.getAttribute('data-start'))); });
      });
      box.appendChild(div);
    });
    var spans = $$('.tr-s', box);
    spanTimes = { spans: spans, starts: spans.map(function (x) { return parseFloat(x.getAttribute('data-start')); }), ends: spans.map(function (x) { return parseFloat(x.getAttribute('data-end')); }) };
    buildTimeline();
  }
  function seek(at) {
    if (!audio) return;
    audio.currentTime = Math.max(0, at);
    var p = audio.play();
    if (p && p.catch) p.catch(function () { /* the browser waits for a gesture */ });
  }
  var lit = null;
  var spanTimes = { spans: [], starts: [], ends: [] };
  // Which sentence is heard at a moment: the last one to have begun, while it lasts (and through a
  // short breath after it), so a sentence lights the moment it starts, never one late.
  function heardAt(starts, ends, now) {
    var found = -1;
    for (var i = 0; i < starts.length; i++) {
      if (starts[i] > now + 0.04) break;
      if (now < ends[i] + 0.35) found = i;
    }
    return found;
  }
  // The sentence being heard is lit, and brought into view when it leaves it while playing.
  function light(jump) {
    if (!audio) return;
    var i = heardAt(spanTimes.starts, spanTimes.ends, audio.currentTime);
    var found = i >= 0 ? spanTimes.spans[i] : null;
    if (found === lit) return;
    if (lit) lit.classList.remove('is-now');
    lit = found;
    if (!lit) return;
    lit.classList.add('is-now');
    if ((!audio.paused || jump) && !reader.hidden) {
      var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var r = lit.getBoundingClientRect();
      var doc = $('[data-tr-doc]');
      if (doc.scrollHeight > doc.clientHeight + 4 && getComputedStyle(doc).overflowY !== 'visible') {
        // The transcript scrolls in its own pane (a wide screen): the sentence a third of the way down it.
        var d = doc.getBoundingClientRect();
        if (r.top < d.top + 16 || r.bottom > d.bottom - 16) doc.scrollTo({ top: doc.scrollTop + (r.top - d.top) - doc.clientHeight / 3, behavior: calm ? 'auto' : 'smooth' });
      } else {
        var barTop = bar.hidden ? window.innerHeight : bar.getBoundingClientRect().top;
        if (r.top < 120 || r.bottom > barTop - 16) lit.scrollIntoView({ block: 'center', behavior: calm ? 'auto' : 'smooth' });
      }
    }
  }
  function editParagraph(div, index, p) {
    var body = $('.tr-para-body', div);
    body.hidden = true;
    var area = document.createElement('textarea');
    area.value = p.text;
    area.setAttribute('dir', 'auto');
    var actions = document.createElement('div');
    actions.className = 'tr-para-edit';
    actions.innerHTML = '<button type="button" class="btn btn-primary">' + esc(t.save) + '</button><button type="button" class="btn btn-ghost">' + esc(t.cancel) + '</button>';
    div.appendChild(area);
    div.appendChild(actions);
    area.focus();
    var buttons = $$('button', actions);
    buttons[1].addEventListener('click', function () { area.remove(); actions.remove(); body.hidden = false; });
    buttons[0].addEventListener('click', function () {
      json('PATCH', current.urls.update, { paragraph: index, text: area.value }).then(function (r) {
        if (r.xhr.status !== 200) { toast(messageOf(r), 'error'); return; }
        current = r.body.transcription;
        shown = { paragraphs: current.paragraphs, segments: current.segments };
        renderText(current.rtl, true);
      });
    });
  }

  // Toolbar
  $('[data-tr-copy]').addEventListener('click', function (e) {
    var text = (shown.paragraphs || []).map(function (p) {
      return (((current.speaker_list || []).length > 1) ? nameOf(p.speaker) + ': ' : '') + p.text;
    }).join('\n\n');
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
      toast(t.copied);
    }).catch(function () { toast(t.copy_failed, 'error'); });
  });
  $$('[data-tr-export]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      if (!current) return;
      var url = current.urls['export_' + a.getAttribute('data-tr-export')] + (shownLanguage ? '?lang=' + encodeURIComponent(shownLanguage) : '');
      window.location.href = url;
    });
  });
  // "Show in": the original, or any other language (made once and kept). The transcript's own language is not offered.
  var TICK = '<svg class="tr-tick" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
  var GLOBE = '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.3 2.4 3.5 5.2 3.5 8.5s-1.2 6.1-3.5 8.5c-2.3-2.4-3.5-5.2-3.5-8.5s1.2-6.1 3.5-8.5z"/></svg>';
  // The language's round flag (the studio's own flags), the globe for the original.
  function flagHtml(choice) {
    if (choice.flag) return '<span class="vs-flag tr-flag"><img src="' + esc(choice.flag) + '" alt="" width="22" height="22" loading="lazy" decoding="async"></span>';
    // A language shown by a letter of its own alphabet rather than a country's flag.
    if (choice.badge) return '<span class="vs-flag tr-flag tr-flag-letter" lang="' + esc(choice.value) + '">' + esc(choice.badge) + '</span>';
    return '<span class="vs-flag tr-flag tr-flag-none">' + GLOBE + '</span>';
  }
  function languageMenu(chosen) {
    var choices = [{ value: '', label: t.original }].concat((c.targets || []).filter(function (target) { return target.value !== current.language; }));
    $('[data-tr-language-items]').innerHTML = choices.map(function (choice) {
      return '<button type="button" role="menuitemradio" aria-checked="' + (choice.value === chosen ? 'true' : 'false') + '" data-tr-language="' + esc(choice.value) + '" data-search="' + esc(choice.search || choice.label) + '">'
        + flagHtml(choice) + '<span class="tr-language-name">' + esc(choice.label) + '</span>' + TICK + '</button>';
    }).join('');
    var shownChoice = choices.filter(function (choice) { return choice.value === chosen; })[0] || choices[0];
    var label = shownChoice.label;
    $('[data-tr-language-icon]').innerHTML = shownChoice.flag || shownChoice.badge ? flagHtml(shownChoice) : GLOBE;
    $('[data-tr-language-current]').textContent = label;
    $('[data-tr-language-button]').setAttribute('aria-label', t.translate_to + ': ' + label);
  }
  $('[data-tr-language-search]').addEventListener('input', function (e) { filterMenu(e.target.closest('[data-tr-menu]'), e.target.value); });
  $('[data-tr-language-items]').addEventListener('click', function (e) {
    var choice = e.target.closest('[data-tr-language]');
    if (choice) showIn(choice.getAttribute('data-tr-language'));
  });
  // Another language: the button turns while it is made, then shows it; nothing is left on the page after.
  function showIn(target) {
    if (!current) return;
    var note = $('[data-tr-translate-note]');
    var button = $('[data-tr-language-button]');
    note.hidden = true;
    if (!target) {
      shownLanguage = '';
      languageMenu('');
      shown = { paragraphs: current.paragraphs, segments: current.segments };
      return renderText(current.rtl, true);
    }
    var before = shownLanguage;
    languageMenu(target);
    button.classList.add('is-busy');
    button.setAttribute('aria-busy', 'true');
    reader.classList.add('is-translating');
    var asked = current.id;
    json('POST', current.urls.translate, { language: target }).then(function (r) {
      button.classList.remove('is-busy');
      button.removeAttribute('aria-busy');
      reader.classList.remove('is-translating');
      if (!current || current.id !== asked) return;
      if (r.xhr.status !== 200) {
        languageMenu(before);
        note.textContent = (r.body && r.body.message) || t.network;
        note.hidden = false;
        return;
      }
      shownLanguage = target;
      shown = { paragraphs: r.body.paragraphs, segments: r.body.segments };
      renderText((c.rtl || []).indexOf(target) !== -1, false);
    }).catch(function () {
      button.classList.remove('is-busy');
      button.removeAttribute('aria-busy');
      reader.classList.remove('is-translating');
      languageMenu(before);
      note.textContent = t.network;
      note.hidden = false;
    });
  }
  $('[data-tr-studio-send]').addEventListener('click', function () {
    var box = document.getElementById('contentTextarea');
    if (!box || !current) return;
    box.value = (shown.paragraphs || []).map(function (p) { return p.text; }).join('\n\n');
    box.dispatchEvent(new Event('input', { bubbles: true }));
    window.location.hash = '#generateVoiceSection';
    setTimeout(function () { box.focus(); }, 300);
  });
  $('[data-tr-delete]').addEventListener('click', function () { if (current) removeItem(current); });

  load(true);
})();
