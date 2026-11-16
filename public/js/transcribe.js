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
  function show(name) {
    $$('[data-tr-view]').forEach(function (v) { v.hidden = v.getAttribute('data-tr-view') !== name; });
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
  function working(step, percent, title, note) {
    show('working');
    $('[data-tr-working-title]').textContent = title || '';
    $('[data-tr-working-note]').textContent = note || '';
    var p = Math.max(0, Math.min(100, Math.round(percent)));
    $('[data-tr-percent]').textContent = p + '%';
    $('[data-tr-bar-fill]').style.width = p + '%';
    $('[data-tr-bar]').setAttribute('aria-valuenow', String(p));
    var order = ['upload', 'prepare', 'listen', 'done'];
    var at = order.indexOf(step);
    $$('[data-tr-step]').forEach(function (li) {
      var i = order.indexOf(li.getAttribute('data-tr-step'));
      li.classList.toggle('is-done', i < at);
      li.classList.toggle('is-on', i === at);
    });
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
  function choose(file, source) {
    showError('');
    if (!file) return;
    if (!extensionOk(file.name)) return showError(t.not_audio);
    if (file.size > c.maxMb * 1024 * 1024) return showError(fill(t.too_big, { mb: c.maxMb }));
    chosen = { file: file, name: file.name, source: source, seconds: 0 };
    if (preview.src) URL.revokeObjectURL(preview.src);
    preview.src = URL.createObjectURL(file);
    $('[data-tr-file-name]').textContent = file.name;
    $('[data-tr-file-length]').textContent = '';
    show('chosen');
    setMeter([t.checking]);
    ready(false);
    lengthOf(file).then(function (seconds) {
      if (!chosen || chosen.file !== file) return;
      chosen.seconds = seconds;
      $('[data-tr-file-length]').textContent = seconds > 0 ? clock(seconds) : '';
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
  var rec = { recorder: null, stream: null, chunks: [], started: 0, timer: null };
  var recButton = $('[data-tr-rec]');
  $('[data-tr-go-record]').addEventListener('click', function () {
    showError('');
    show('record');
    $('[data-tr-rec-time]').textContent = '00:00';
    recButton.focus();
  });
  function mime() {
    var types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
    for (var i = 0; i < types.length; i++) {
      if (window.MediaRecorder && MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) return types[i];
    }
    return '';
  }
  function stopRecording(discard) {
    if (rec.timer) { clearInterval(rec.timer); rec.timer = null; }
    if (rec.recorder && rec.recorder.state !== 'inactive') {
      rec.discard = !!discard;
      rec.recorder.stop();
    }
    recButton.setAttribute('aria-pressed', 'false');
    recButton.setAttribute('aria-label', recButton.getAttribute('data-label-start'));
  }
  recButton.addEventListener('click', function () {
    if (rec.recorder && rec.recorder.state === 'recording') return stopRecording(false);
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) return showError(t.mic_none);
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var type = mime();
      rec.stream = stream;
      rec.chunks = [];
      rec.discard = false;
      rec.recorder = type ? new MediaRecorder(stream, { mimeType: type }) : new MediaRecorder(stream);
      rec.recorder.ondataavailable = function (e) { if (e.data && e.data.size) rec.chunks.push(e.data); };
      rec.recorder.onstop = function () {
        stream.getTracks().forEach(function (tr) { tr.stop(); });
        if (rec.discard || !rec.chunks.length) return;
        var kind = (rec.recorder.mimeType || type || 'audio/webm').split(';')[0];
        var ext = kind.indexOf('mp4') !== -1 ? 'm4a' : (kind.indexOf('ogg') !== -1 ? 'ogg' : 'webm');
        var d = new Date();
        var pad = function (n) { return (n < 10 ? '0' : '') + n; };
        var name = 'Recording ' + d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + '.' + pad(d.getMinutes()) + '.' + ext;
        var file = new File(rec.chunks, name, { type: kind });
        choose(file, 'record');
      };
      rec.recorder.start(250);
      rec.started = Date.now();
      recButton.setAttribute('aria-pressed', 'true');
      recButton.setAttribute('aria-label', recButton.getAttribute('data-label-stop'));
      rec.timer = setInterval(function () {
        var s = Math.floor((Date.now() - rec.started) / 1000);
        $('[data-tr-rec-time]').textContent = (Math.floor(s / 60) < 10 ? '0' : '') + Math.floor(s / 60) + ':' + (s % 60 < 10 ? '0' : '') + (s % 60);
      }, 250);
    }).catch(function (e) {
      showError(e && e.name === 'NotFoundError' ? t.mic_none : t.mic_denied);
    });
  });

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
    working('upload', 0, t.sending, '');
    var start = new FormData();
    start.set('name', file.name);
    start.set('size', String(file.size));
    request('POST', c.urls.upload, start, {}).then(function (r) {
      if (r.xhr.status !== 201) return Promise.reject(r);
      return sendPieces(file, r.body).then(function () {
        working('prepare', 2, t.working, t.working_note);
        return json('POST', c.urls.store, { upload_id: r.body.id, language: language.value, source: chosen.source, title: file.name });
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
        if (inBox) {
          reset();
          if (tr.status === 'ready') open(tr);
          else showError(tr.error);
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
    var used = item.status === 'failed' ? t.used_none : (item.status === 'ready' || item.used_seconds > 0 ? item.used : '—');
    var L = c.columns || {};
    return '<td class="num" data-label="' + esc(L.date) + '">' + esc(item.date) + '</td>'
      + '<td class="tr-name"><button type="button" class="dub-link" data-tr-open dir="auto">' + esc(item.title) + '</button></td>'
      + '<td data-label="' + esc(L.language) + '">' + esc(item.language_name || '—') + '</td>'
      + '<td class="num" data-label="' + esc(L.length) + '"><span dir="ltr">' + esc(clock(item.seconds)) + '</span></td>'
      + '<td class="num" data-label="' + esc(L.used) + '">' + esc(used) + '</td>'
      + '<td data-label="' + esc(L.status) + '">' + badge(item) + '</td>'
      + '<td class="tr-actions-cell"><div class="tr-row-actions">'
      + '<div class="tr-menu" data-tr-menu><button type="button" class="tr-icon" aria-haspopup="true" aria-expanded="false" data-tr-menu-button aria-label="' + esc(fill(t.more_actions, { name: item.title })) + '">'
      + '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/></svg></button>'
      + '<div class="tr-menu-list" role="menu" hidden>'
      + (item.status === 'ready' ? '<button type="button" role="menuitem" data-tr-open>' + ICONS.open + esc(t.open) + '</button>'
        + '<a role="menuitem" href="' + esc(item.urls.export_txt) + '">' + ICONS.download + esc(t.txt) + '</a>'
        + '<a role="menuitem" href="' + esc(item.urls.export_srt) + '">' + ICONS.download + esc(t.srt) + '</a>'
        + '<a role="menuitem" href="' + esc(item.urls.export_docx) + '">' + ICONS.download + esc(t.docx) + '</a>' : '')
      + '<button type="button" role="menuitem" data-tr-rename>' + ICONS.rename + esc(t.rename) + '</button>'
      + (item.status === 'queued' || item.status === 'processing' ? '' : '<hr aria-hidden="true"><button type="button" role="menuitem" class="is-danger" data-tr-remove>' + ICONS.remove + esc(t.delete) + '</button>')
      + '</div></div></div></td>';
  }
  function bindRow(tr, item) {
    tr._item = item;
    tr.setAttribute('data-tr-row', item.id);
    tr.innerHTML = rowHtml(item);
    $$('[data-tr-open]', tr).forEach(function (b) {
      b.addEventListener('click', function () { if (tr._item.status === 'ready') open(tr._item); else if (tr._item.status === 'failed') showError(tr._item.error); });
    });
    var rename = $('[data-tr-rename]', tr);
    if (rename) rename.addEventListener('click', function () { renameItem(tr._item); });
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
      if (current && current.id === item.id) { current.title = r.body.transcription.title; $('[data-tr-reader-title]').textContent = current.title; }
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
  function menuItems(m) { return $$('a, button', $('.tr-menu-list', m)); }
  function setMenu(m, open, focusFirst) {
    var list = $('.tr-menu-list', m);
    var button = $('[data-tr-menu-button]', m);
    if (open) {
      $$('[data-tr-menu].is-open').forEach(function (other) { if (other !== m) setMenu(other, false); });
      list.hidden = false;
      var room = window.innerHeight - button.getBoundingClientRect().bottom;
      m.classList.toggle('is-up', room < list.offsetHeight + 16 && button.getBoundingClientRect().top > list.offsetHeight + 16);
      if (focusFirst) { var first = menuItems(m)[0]; if (first) first.focus(); }
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
    // A choice in a menu closes it; a click anywhere else closes them all.
    $$('[data-tr-menu].is-open').forEach(function (m) { if (!inMenu || m === inMenu) setMenu(m, false); });
  });
  root.addEventListener('keydown', function (e) {
    var m = e.target.closest && e.target.closest('[data-tr-menu]');
    if (!m) return;
    var items = menuItems(m);
    var onButton = e.target.matches('[data-tr-menu-button]');
    var at = items.indexOf(e.target);
    if (onButton && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
      e.preventDefault();
      setMenu(m, true, false);
      (e.key === 'ArrowDown' ? items[0] : items[items.length - 1]).focus();
    } else if (!onButton && at !== -1) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
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
      // The choices made again, without the transcript's own language (the studio's dropdown follows the select).
      var select = $('[data-tr-translate]');
      var original = select.options[0].textContent;
      select.innerHTML = '';
      select.appendChild(new Option(original, ''));
      (c.targets || []).forEach(function (target) {
        if (target.value !== current.language) select.appendChild(new Option(t.translate + ': ' + target.label, target.value));
      });
      select.value = '';
      $('[data-tr-translate-note]').hidden = true;
      render(current);
      reader.hidden = false;
      reader.focus();
      reader.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
  function close() {
    if (audio) audio.pause();
    reader.hidden = true;
    current = null;
  }
  $('[data-tr-close]').addEventListener('click', close);

  function render(tr) {
    $('[data-tr-reader-title]').textContent = tr.title;
    var bits = [tr.length, fill(t.used, { time: tr.free ? t.status_free : tr.used })];
    if (tr.language_name) bits.push(tr.language_name);
    bits.push(tr.date);
    $('[data-tr-reader-meta]').textContent = bits.join(' · ');
    // The recording, in the site's own player, while it is kept.
    var player = $('[data-tr-player]');
    player.innerHTML = '';
    audio = null;
    if (tr.audio) {
      audio = document.createElement('audio');
      audio.controls = true;
      audio.preload = 'metadata';
      audio.src = tr.urls.audio;
      audio.setAttribute('data-download-disabled', '1');
      player.appendChild(audio);
      if (typeof window.initCustomAudioPlayers === 'function') window.initCustomAudioPlayers();
      audio.addEventListener('timeupdate', light);
    } else {
      player.innerHTML = '<p class="tr-note">' + esc(fill(t.audio_gone, { days: c.keepDays })) + '</p>';
    }
    renderSpeakers(tr);
    shown = { paragraphs: tr.paragraphs, segments: tr.segments };
    renderText(tr.rtl, true);
  }
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
  // The paragraphs, each sentence a span to click; editable in the original only.
  function renderText(rtl, editable) {
    var box = $('[data-tr-text]');
    box.innerHTML = '';
    box.classList.toggle('tr-rtl', !!rtl);
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
        + '<span class="tr-para-time" dir="ltr">' + esc(clock(p.start || 0)) + '</span>'
        + '<span class="tr-para-tools">'
        + (audio ? '<button type="button" class="tr-icon" data-tr-play aria-label="' + esc(t.play_from) + '"><svg viewBox="0 0 24 24"><path d="M7 5l12 7-12 7z"/></svg></button>' : '')
        + (editable ? '<button type="button" class="tr-icon" data-tr-edit aria-label="' + esc(t.edit) + '"><svg viewBox="0 0 24 24"><path d="M4 20h4l10.5-10.5a2.8 2.8 0 0 0-4-4L4 16v4z"/></svg></button>' : '')
        + '</span></div><div class="tr-para-body" dir="auto">' + body + '</div>';
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
  function light() {
    if (!audio) return;
    var now = audio.currentTime;
    var spans = $$('.tr-s', $('[data-tr-text]'));
    var found = null;
    for (var i = 0; i < spans.length; i++) {
      var s = parseFloat(spans[i].getAttribute('data-start'));
      var e = parseFloat(spans[i].getAttribute('data-end'));
      if (now >= s && now < e + 0.15) { found = spans[i]; break; }
    }
    if (found === lit) return;
    if (lit) lit.classList.remove('is-now');
    lit = found;
    if (lit) lit.classList.add('is-now');
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
    var button = e.currentTarget;
    var label = button.textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
      button.textContent = t.copied;
      setTimeout(function () { button.textContent = label; }, 1500);
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
  $('[data-tr-translate]').addEventListener('change', function (e) {
    var target = e.target.value;
    var note = $('[data-tr-translate-note]');
    if (!target) {
      shownLanguage = '';
      note.hidden = true;
      shown = { paragraphs: current.paragraphs, segments: current.segments };
      return renderText(current.rtl, true);
    }
    note.hidden = false;
    note.textContent = t.translating;
    json('POST', current.urls.translate, { language: target }).then(function (r) {
      if (r.xhr.status !== 200) { note.textContent = (r.body && r.body.message) || t.network; return; }
      shownLanguage = target;
      note.textContent = t.translate_free;
      shown = { paragraphs: r.body.paragraphs, segments: r.body.segments };
      renderText((c.rtl || []).indexOf(target) !== -1, false);
    });
  });
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
