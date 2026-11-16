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

  // ---------- The box's views ----------
  // The chosen file is a card inside the add box ("chosen"), with the ways to add another below it.
  function show(name) {
    var view = name === 'chosen' ? 'drop' : name;
    $$('[data-tr-view]').forEach(function (v) { v.hidden = v.getAttribute('data-tr-view') !== view; });
    drop.classList.toggle('has-file', name === 'chosen');
    $('[data-tr-studio]').classList.toggle('is-working', name === 'working');
    if (name !== 'chosen') stopPreview();
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
        setMeter([fill(t.will_use, { time: time(e.seconds) }), t.free_try]);
      } else {
        setMeter([fill(t.will_use, { time: time(e.seconds) }), fill(t.left, { time: time(e.left, true) }) + ' ' + fill(t.remain, { time: time(e.remain, true) })]);
      }
      ready(true, fill(t.start, { time: time(e.seconds) }));
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
    function poll() {
      json('GET', item.urls.show).then(function (r) {
        if (r.xhr.status !== 200) throw r;
        var tr = r.body.transcription;
        updateRow(tr);
        if (r.body.account) syncAccount(r.body.account);
        if (tr.status === 'queued' || tr.status === 'processing') {
          if (inBox) working(tr.progress < 15 ? 'prepare' : 'listen', tr.progress, t.working, t.working_note);
          return setTimeout(poll, 2500);
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
    setTimeout(poll, 1500);
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
  }
  function updateRow(item) {
    var tr = rows.querySelector('[data-tr-row="' + item.id + '"]');
    if (tr) bindRow(tr, item);
  }
  function renameItem(item) {
    var title = window.prompt(t.rename_prompt, item.title);
    if (!title || !title.trim()) return;
    json('PATCH', item.urls.update, { title: title.trim() }).then(function (r) {
      if (r.xhr.status !== 200) return;
      updateRow(r.body.transcription);
      if (current && current.id === item.id) { current.title = r.body.transcription.title; $('[data-tr-reader-title]').textContent = cleanTitle(current.title); }
    });
  }
  function removeItem(item) {
    if (!window.confirm(t.delete_confirm)) return;
    json('DELETE', item.urls.delete).then(function (r) {
      if (r.xhr.status !== 200) return;
      var tr = rows.querySelector('[data-tr-row="' + item.id + '"]');
      if (tr) tr.remove();
      if (current && current.id === item.id) close();
      if (!rows.children.length) emptyRow($('[data-tr-empty-text]').innerHTML);
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
  function open(item) {
    json('GET', item.urls.show).then(function (r) {
      if (r.xhr.status !== 200) return;
      current = r.body.transcription;
      shownLanguage = '';
      languageMenu('');
      $('[data-tr-translate-note]').hidden = true;
      render(current);
      reader.hidden = false;
      reader.focus();
      reader.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
  function close() {
    if (audio) audio.pause();
    bar.hidden = true;
    reader.hidden = true;
    current = null;
  }
  $('[data-tr-close]').addEventListener('click', close);
  $('[data-tr-rename-open]').addEventListener('click', function () { if (current) renameItem(current); });

  // The recording plays from the bar along the reader's foot: play, the time, a seek bar and the speed.
  var bar = $('[data-tr-playbar]');
  var seekInput = $('[data-tr-pb-seek]');
  var SPEEDS = [1, 1.25, 1.5, 2, 0.75];
  function lengthOfAudio() {
    var d = audio ? audio.duration : 0;
    return isFinite(d) && d > 0 ? d : (current ? Number(current.seconds) || 0 : 0);
  }
  function paintBar() {
    if (!audio) return;
    var playing = !audio.paused && !audio.ended;
    var play = $('[data-tr-pb-play]');
    play.classList.toggle('is-playing', playing);
    play.setAttribute('aria-label', play.getAttribute(playing ? 'data-label-pause' : 'data-label-play'));
    var total = lengthOfAudio();
    $('[data-tr-pb-now]').textContent = clock(audio.currentTime);
    // The transcript's own length, as its header and the list say it.
    $('[data-tr-pb-total]').textContent = clock(Math.round(current && Number(current.seconds) > 0 ? Number(current.seconds) : total));
    if (!seekInput._dragging) {
      var share = total > 0 ? Math.min(1, audio.currentTime / total) : 0;
      seekInput.value = String(Math.round(share * 1000));
      seekInput.style.setProperty('--p', (share * 100).toFixed(2) + '%');
    }
    seekInput.setAttribute('aria-valuetext', clock(audio.currentTime) + ' / ' + clock(total));
  }
  $('[data-tr-pb-play]').addEventListener('click', function () {
    if (!audio) return;
    if (audio.paused || audio.ended) { var p = audio.play(); if (p && p.catch) p.catch(paintBar); } else { audio.pause(); }
  });
  seekInput.addEventListener('input', function () {
    seekInput._dragging = true;
    var share = Number(seekInput.value) / 1000;
    seekInput.style.setProperty('--p', (share * 100).toFixed(2) + '%');
    $('[data-tr-pb-now]').textContent = clock(share * lengthOfAudio());
  });
  seekInput.addEventListener('change', function () {
    seekInput._dragging = false;
    if (audio) { audio.currentTime = Number(seekInput.value) / 1000 * lengthOfAudio(); light(true); }
  });
  $('[data-tr-pb-speed]').addEventListener('click', function (e) {
    if (!audio) return;
    var next = SPEEDS[(SPEEDS.indexOf(audio.playbackRate) + 1) % SPEEDS.length] || 1;
    audio.playbackRate = next;
    e.currentTarget.textContent = (next === 1 ? '1' : String(next)) + '×';
  });

  function render(tr) {
    $('[data-tr-reader-title]').textContent = cleanTitle(tr.title);
    // The length, the language and the day: once each.
    var bits = [tr.length];
    if (tr.language_name) bits.push(tr.language_name);
    bits.push(tr.date);
    $('[data-tr-reader-meta]').textContent = bits.join(' · ');
    if (audio) { audio.pause(); audio.removeAttribute('src'); audio.load(); }
    audio = $('[data-tr-audio]');
    audio.playbackRate = 1;
    $('[data-tr-pb-speed]').textContent = '1×';
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
    paintBar();
    renderSpeakers(tr);
    shown = { paragraphs: tr.paragraphs, segments: tr.segments };
    renderText(tr.rtl, true);
  }
  (function () {
    var a = $('[data-tr-audio]');
    ['play', 'pause', 'ended', 'loadedmetadata', 'durationchange'].forEach(function (ev) { a.addEventListener(ev, paintBar); });
    a.addEventListener('timeupdate', function () { paintBar(); light(false); });
    a.addEventListener('seeked', function () { light(true); });
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
  function renderSpeakers(tr) {
    var box = $('[data-tr-speakers]');
    var list = $('[data-tr-speaker-list]');
    list.innerHTML = '';
    box.hidden = (tr.speaker_list || []).length < 2;
    (tr.speaker_list || []).forEach(function (s, i) {
      var label = document.createElement('label');
      label.innerHTML = '<span class="tr-swatch" data-n="' + (i % 4) + '" aria-hidden="true">' + esc(s.id) + '</span>'
        + '<input type="text" maxlength="60" dir="auto" value="' + esc(s.name) + '" aria-label="' + esc(fill(t.speaker_name, { speaker: s.name })) + '">';
      var input = $('input', label);
      // Browsers that cannot size a box to its text: as many letters wide as the name.
      var fit = function () { input.size = Math.max(4, Math.min(28, input.value.length + 1)); };
      fit();
      input.addEventListener('input', fit);
      input.addEventListener('change', function () {
        var names = {};
        names[s.id] = input.value;
        json('PATCH', current.urls.update, { speakers: names }).then(function (r) {
          if (r.xhr.status !== 200) return;
          current.speaker_list = r.body.transcription.speaker_list;
          renderText(current.rtl && !shownLanguage, !shownLanguage);
        });
      });
      list.appendChild(label);
    });
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
  }
  function seek(at) {
    if (!audio) return;
    audio.currentTime = Math.max(0, at);
    var p = audio.play();
    if (p && p.catch) p.catch(function () { /* the browser waits for a gesture */ });
  }
  var lit = null;
  // The sentence being heard is lit, and brought into view when it leaves it while playing.
  function light(jump) {
    if (!audio) return;
    var now = audio.currentTime;
    var spans = $$('.tr-s', $('[data-tr-text]'));
    var found = null;
    for (var i = 0; i < spans.length; i++) {
      var s = parseFloat(spans[i].getAttribute('data-start'));
      var e = parseFloat(spans[i].getAttribute('data-end'));
      if (now >= s - 0.05 && now < e + 0.15) { found = spans[i]; break; }
    }
    if (found === lit) return;
    if (lit) lit.classList.remove('is-now');
    lit = found;
    if (!lit) return;
    lit.classList.add('is-now');
    if ((!audio.paused || jump) && !reader.hidden) {
      var r = lit.getBoundingClientRect();
      var barTop = bar.hidden ? window.innerHeight : bar.getBoundingClientRect().top;
      if (r.top < 120 || r.bottom > barTop - 16) {
        var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        lit.scrollIntoView({ block: 'center', behavior: calm ? 'auto' : 'smooth' });
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
        if (r.xhr.status !== 200) return;
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
    var label = $('[data-tr-copy-label]');
    var was = label.textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
      label.textContent = t.copied;
      setTimeout(function () { label.textContent = was; }, 1500);
    }).catch(function () { /* nothing to copy into */ });
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
    var asked = current.id;
    json('POST', current.urls.translate, { language: target }).then(function (r) {
      button.classList.remove('is-busy');
      button.removeAttribute('aria-busy');
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
