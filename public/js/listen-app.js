/*
 * The Listen Android app (mobile/): what the site's pages do only inside it.
 * Loaded by components/in-app-scripts.blade.php, and only when the page is
 * shown in the app's own web view. A browser never gets this file.
 *
 *  - saving and sharing audio through the phone (the share sheet, Downloads);
 *  - the lock-screen player for long audio, with play, pause and seek;
 *  - the microphone and notification questions, asked when they are needed;
 *  - the phone's back button, pull to refresh, the status bar's colours;
 *  - "Continue with Google" through the phone's browser;
 *  - the phone's messaging token, for "your voice is ready".
 *
 * The native side is the ListenShell plugin (mobile/BRIDGE.md). Every call is
 * guarded: on an older app without a method, the page simply works as a page.
 */
(function () {
  'use strict';

  var cfg = window.LISTEN_APP || {};
  var t = cfg.t || {};
  if (window.__listenAppBooted) return;
  window.__listenAppBooted = true;

  // ---------------------------------------------------------------- bridge

  function cap() { return window.Capacitor || null; }

  function plugin(name) {
    var c = cap();
    return c && c.Plugins && c.Plugins[name] ? c.Plugins[name] : null;
  }

  /** Calls a native method; rejects when the app has no such plugin or method. */
  function call(name, method, args) {
    var c = cap();
    if (!c) return Promise.reject(new Error('no-bridge'));
    try {
      var p = plugin(name);
      if (p && typeof p[method] === 'function') return Promise.resolve(p[method](args || {}));
      if (typeof c.nativePromise === 'function') return c.nativePromise(name, method, args || {});
    } catch (e) {
      return Promise.reject(e);
    }
    return Promise.reject(new Error('no-method'));
  }

  /**
   * Listens to a native event. The phone forgets its listeners whenever a page
   * starts, also when this page is shown again from the back/forward cache, so
   * they are kept here to be put back (resubscribe).
   */
  var subscriptions = [];
  function attach(sub) {
    var c = cap();
    if (!c) return;
    try {
      var p = plugin(sub.name);
      if (p && typeof p.addListener === 'function') sub.handle = p.addListener(sub.event, sub.handler);
      else if (typeof c.addListener === 'function') sub.handle = c.addListener(sub.name, sub.event, sub.handler);
    } catch (e) { /* an app without this event */ }
  }
  function on(name, event, handler) {
    var sub = { name: name, event: event, handler: handler, handle: null };
    subscriptions.push(sub);
    attach(sub);
  }
  function resubscribe() {
    subscriptions.forEach(function (sub) {
      try { if (sub.handle && typeof sub.handle.remove === 'function') sub.handle.remove(); } catch (e) {}
      sub.handle = null;
      attach(sub);
    });
  }

  function shell(method, args) { return call('ListenShell', method, args); }
  function quiet(promise) { return promise.catch(function () { return null; }); }

  var lang = cfg.locale === 'en' ? 'en' : 'ar';

  // ------------------------------------------------------------- small UI

  var style = document.createElement('style');
  style.textContent = [
    '.la-toast{position:fixed;inset-inline:16px;bottom:calc(20px + env(safe-area-inset-bottom));z-index:2147483000;margin:0 auto;max-width:440px;',
    'padding:12px 16px;border-radius:14px;background:#17171d;color:#fff;font-size:.95rem;line-height:1.6;text-align:center;',
    'box-shadow:0 12px 32px rgba(0,0,0,.35);opacity:0;transform:translateY(12px);transition:opacity .2s,transform .2s;pointer-events:none}',
    'html[data-theme="light"] .la-toast{background:#1c1c24}',
    '.la-toast.is-on{opacity:1;transform:none}',
    '.la-sheet-back{position:fixed;inset:0;z-index:2147483001;background:rgba(0,0,0,.5);opacity:0;transition:opacity .2s}',
    '.la-sheet-back.is-on{opacity:1}',
    '.la-sheet{position:fixed;inset-inline:0;bottom:0;z-index:2147483002;background:var(--surface,#15151b);color:var(--text,#fff);',
    'border-radius:20px 20px 0 0;padding:10px 16px calc(16px + env(safe-area-inset-bottom));box-shadow:0 -12px 40px rgba(0,0,0,.35);',
    'transform:translateY(100%);transition:transform .22s ease;max-width:560px;margin:0 auto}',
    '.la-sheet.is-on{transform:none}',
    '.la-sheet::before{content:"";display:block;width:40px;height:4px;border-radius:4px;background:var(--line-strong,#3a3a46);margin:0 auto 12px}',
    '.la-sheet h2{font-size:1.1rem;margin:4px 0 6px}',
    '.la-sheet p{color:var(--muted,#a0a0ad);font-size:.95rem;line-height:1.7;margin:0 0 14px}',
    '.la-sheet button{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;min-height:52px;margin-top:8px;border-radius:14px;',
    'border:1px solid var(--line-strong,#3a3a46);background:transparent;color:inherit;font:inherit;font-weight:600;font-size:1rem}',
    '.la-sheet button.is-primary{background:var(--brand-accent,#7659FA);border-color:var(--brand-accent,#7659FA);color:#fff}',
    '.la-sheet button.is-quiet{border-color:transparent;color:var(--muted,#a0a0ad);font-weight:500}',
    '.la-sheet button svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}',
    '.la-sheet button[disabled]{opacity:.6}',
    // Moving to another page: a thin line across the top from the first instant of the tap.
    '.la-load{position:fixed;top:0;inset-inline:0;height:3px;z-index:2147483003;pointer-events:none;opacity:0;transition:opacity .2s}',
    '.la-load.is-on{opacity:1}',
    '.la-load i{display:block;height:100%;width:100%;transform-origin:0 50%;transform:scaleX(0);background:var(--brand-accent,#7659FA);border-radius:0 3px 3px 0}',
    'html[dir="rtl"] .la-load i{transform-origin:100% 50%;border-radius:3px 0 0 3px}',
    '.la-load.is-on i{animation:la-load 6s cubic-bezier(.1,.7,.1,1) forwards}',
    '@keyframes la-load{0%{transform:scaleX(0)}8%{transform:scaleX(.35)}40%{transform:scaleX(.75)}100%{transform:scaleX(.95)}}',
    '@media (prefers-reduced-motion:reduce){.la-toast,.la-sheet,.la-sheet-back{transition:none}}'
  ].join('');
  document.head.appendChild(style);

  var toastEl = null, toastTimer = 0;
  function toast(message) {
    if (!message) return;
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'la-toast';
      toastEl.setAttribute('role', 'status');
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = message;
    requestAnimationFrame(function () { toastEl.classList.add('is-on'); });
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('is-on'); }, 3200);
  }

  var sheet = null;
  function closeSheet() {
    if (!sheet) return false;
    var s = sheet; sheet = null;
    s.box.classList.remove('is-on'); s.back.classList.remove('is-on');
    setTimeout(function () { s.box.remove(); s.back.remove(); }, 240);
    if (s.onClose) s.onClose();
    return true;
  }

  /**
   * A bottom sheet: an optional title and text, then buttons
   * [{ label, icon, kind: 'primary'|'quiet', run }]. A button's run may return
   * a promise: the sheet waits for it, then closes.
   */
  function openSheet(options) {
    closeSheet();
    var back = document.createElement('div'); back.className = 'la-sheet-back';
    var box = document.createElement('div'); box.className = 'la-sheet';
    box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true');
    if (options.title) { var h = document.createElement('h2'); h.textContent = options.title; box.appendChild(h); }
    if (options.text) { var p = document.createElement('p'); p.textContent = options.text; box.appendChild(p); }
    (options.buttons || []).forEach(function (b) {
      var btn = document.createElement('button');
      btn.type = 'button';
      if (b.kind) btn.className = 'is-' + b.kind;
      if (b.icon) btn.innerHTML = b.icon;
      btn.appendChild(document.createTextNode(b.label));
      btn.addEventListener('click', function () {
        if (!b.run) { closeSheet(); return; }
        box.querySelectorAll('button').forEach(function (x) { x.disabled = true; });
        Promise.resolve().then(b.run).catch(function () {}).then(closeSheet);
      });
      box.appendChild(btn);
    });
    back.addEventListener('click', closeSheet);
    document.body.appendChild(back); document.body.appendChild(box);
    sheet = { box: box, back: back, onClose: options.onClose || null };
    requestAnimationFrame(function () { back.classList.add('is-on'); box.classList.add('is-on'); });
    var first = box.querySelector('button'); if (first) first.focus({ preventScroll: true });
  }

  var ICON_SAVE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7 11l5 5 5-5M5 20h14"/></svg>';
  var ICON_SHARE = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="M8.2 10.8l7.6-4.6M8.2 13.2l7.6 4.6"/></svg>';

  // ------------------------------------------------- saving and sharing

  // The phone saves and shares sound and video; any other file is left to the app's own download.
  var MIME = { mp3: 'audio/mpeg', wav: 'audio/wav', m4a: 'audio/mp4', aac: 'audio/aac', ogg: 'audio/ogg', opus: 'audio/ogg', flac: 'audio/flac', mp4: 'video/mp4', webm: 'video/webm', mov: 'video/quicktime' };

  function extensionOf(name) {
    var m = /\.([a-z0-9]{2,5})$/i.exec(String(name || ''));
    return m ? m[1].toLowerCase() : '';
  }

  function blobToBase64(blob) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () { resolve(String(reader.result || '').replace(/^data:[^,]*,/, '')); };
      reader.onerror = function () { reject(reader.error || new Error('read')); };
      reader.readAsDataURL(blob);
    });
  }

  /** What a download link points to, as the native side takes it: { url } or { base64 }, with a name and a type. */
  function fileFromLink(link) {
    var href = link.href || '';
    var name = link.getAttribute('download') || '';
    if (href.indexOf('blob:') === 0 || href.indexOf('data:') === 0) {
      // The page revokes its blob address a moment later: read it now.
      return fetch(href).then(function (r) { return r.blob(); }).then(function (blob) {
        var ext = extensionOf(name) || (blob.type.indexOf('wav') > -1 ? 'wav' : 'mp3');
        if (!extensionOf(name)) name = (name || 'listen-audio') + '.' + ext;
        var type = /^(audio|video)\//.test(blob.type) ? blob.type : (MIME[ext] || 'audio/mpeg');
        return blobToBase64(blob).then(function (base64) {
          return { base64: base64, fileName: name, mimeType: type };
        });
      });
    }
    var url = new URL(href, location.href);
    var ext2 = extensionOf(name) || extensionOf(url.pathname) || (/\/dub-my-video\//.test(url.pathname) ? 'mp4' : 'mp3');
    if (!extensionOf(name)) name = (name || (ext2 === 'mp4' ? 'listen-video' : 'listen-audio')) + '.' + ext2;
    return Promise.resolve({ url: url.href, fileName: name, mimeType: MIME[ext2] || 'audio/mpeg' });
  }

  function offerFile(filePromise) {
    // Read at once (see fileFromLink), offered when ready.
    var ready = filePromise.catch(function () { return null; });
    openSheet({
      buttons: [
        { label: t.save || 'Save', icon: ICON_SAVE, kind: 'primary', run: function () {
          toast(t.preparing);
          return ready.then(function (file) {
            if (!file) throw new Error('file');
            return shell('saveAudio', file);
          }).then(function () { toast(t.saved); }, function () { toast(t.save_failed); });
        } },
        { label: t.share || 'Share', icon: ICON_SHARE, run: function () {
          return ready.then(function (file) {
            if (!file) throw new Error('file');
            return shell('shareAudio', Object.assign({ title: file.fileName }, file));
          }).catch(function (e) { if (!e || !/cancel/i.test(String(e.message || e))) toast(t.save_failed); });
        } },
        { label: t.cancel || 'Cancel', kind: 'quiet' }
      ]
    });
  }

  function isDownloadLink(link) {
    if (!link || !link.href) return false;
    try {
      if (link.href.indexOf('blob:') === 0 || link.href.indexOf('data:') === 0) return link.hasAttribute('download');
      var url = new URL(link.href, location.href);
      if (url.origin !== location.origin) return false;
      if (/\/dub-my-video\/[A-Za-z0-9]+\/download$/.test(url.pathname)) return true;
      if (!link.hasAttribute('download')) return false;
      // A file that is not sound or video (a zip, a PDF) is the app's own download to handle.
      var ext = extensionOf(link.getAttribute('download')) || extensionOf(url.pathname);
      return ext === '' || !!MIME[ext];
    } catch (e) { return false; }
  }

  // The pages save a file by clicking a link they make (components/scripts.blade.php): caught
  // here, before the web view tries a download it cannot do.
  document.addEventListener('click', function (event) {
    var link = event.target && event.target.closest ? event.target.closest('a') : null;
    if (!isDownloadLink(link) || !cap()) return;
    event.preventDefault();
    event.stopPropagation();
    offerFile(fileFromLink(link));
  }, true);

  // ------------------------------------------------- lock-screen player

  var MIN_SECONDS = 20;        // shorter clips (voice previews) play as they always did
  var current = null, lastSent = 0, engaged = false;

  function titleFor(el) {
    var own = el.dataset.downloadText || el.getAttribute('title') || el.getAttribute('aria-label') || '';
    if (!own) {
      var card = el.closest('[data-audio-title], article, li, tr, section');
      var heading = card && card.querySelector('[data-audio-title], h1, h2, h3, h4, strong');
      own = (card && card.getAttribute('data-audio-title')) || (heading && heading.textContent) || '';
    }
    own = String(own).replace(/\s+/g, ' ').trim();
    return own ? own.slice(0, 90) : (t.now_playing || 'Listen');
  }

  function long(el) { return isFinite(el.duration) && el.duration >= MIN_SECONDS; }

  function send(el, force) {
    if (!el || el !== current || !long(el)) return;
    var now = Date.now();
    if (!force && now - lastSent < 1000) return;
    lastSent = now;
    engaged = true;
    quiet(shell('mediaUpdate', {
      playing: !el.paused && !el.ended,
      title: titleFor(el),
      subtitle: t.now_playing || 'Listen',
      position: Math.max(0, el.currentTime || 0),
      duration: el.duration
    }));
  }

  function stop(el) {
    if (el && el !== current) return;
    current = null;
    if (engaged) { engaged = false; quiet(shell('mediaStop')); }
  }

  function isAudio(target) { return target && target.tagName === 'AUDIO'; }

  // Media events do not bubble, but they can be caught on the way down.
  document.addEventListener('play', function (e) { if (isAudio(e.target)) { current = e.target; send(current, true); } }, true);
  document.addEventListener('playing', function (e) { if (isAudio(e.target)) { current = e.target; send(current, true); } }, true);
  document.addEventListener('durationchange', function (e) { if (e.target === current) send(current, true); }, true);
  document.addEventListener('pause', function (e) { if (e.target === current) send(current, true); }, true);
  document.addEventListener('seeked', function (e) { if (e.target === current) send(current, true); }, true);
  document.addEventListener('ratechange', function (e) { if (e.target === current) send(current, true); }, true);
  document.addEventListener('timeupdate', function (e) { if (e.target === current) send(current, false); }, true);
  document.addEventListener('ended', function (e) { if (e.target === current) stop(current); }, true);
  document.addEventListener('emptied', function (e) { if (e.target === current) stop(current); }, true);
  window.addEventListener('pagehide', function () { stop(null); });

  on('ListenShell', 'mediaAction', function (data) {
    var el = current;
    if (!el || !data) return;
    var d = isFinite(el.duration) ? el.duration : 0;
    switch (data.action) {
      case 'play': var p = el.play(); if (p && p.catch) p.catch(function () {}); break;
      case 'pause': el.pause(); break;
      case 'stop': el.pause(); stop(el); break;
      case 'seek': if (typeof data.position === 'number') el.currentTime = Math.max(0, Math.min(d || data.position, data.position)); break;
      case 'forward': el.currentTime = Math.min(d || el.currentTime + 10, el.currentTime + 10); break;
      case 'rewind': el.currentTime = Math.max(0, el.currentTime - 10); break;
    }
  });

  // ----------------------------------------------------------- microphone

  // Recording a voice to clone and talking to an agent ask for the microphone: the phone's
  // own question comes first, with the reason, the first time it is needed.
  if (navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function') {
    var realGetUserMedia = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
    navigator.mediaDevices.getUserMedia = function (constraints) {
      if (!constraints || !constraints.audio || !cap()) return realGetUserMedia(constraints);
      return shell('ensureMic', { lang: lang }).then(function (result) {
        if (result && result.granted === false) {
          toast(t.mic_denied);
          throw new DOMException('Permission denied', 'NotAllowedError');
        }
        return realGetUserMedia(constraints);
      }, function (error) {
        // An app without the question: the web view asks in its own way.
        if (error && error.name === 'NotAllowedError') throw error;
        return realGetUserMedia(constraints);
      });
    };
  }

  // -------------------------------------------------------- notifications

  var ASKED_KEY = 'listen_app_notify_asked', TOKEN_KEY = 'listen_app_push_token';

  function stored(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }
  function store(key, value) { try { localStorage.setItem(key, value); } catch (e) {} }

  function sendToken(token) {
    if (!token || !cfg.signedIn || !cfg.devicesUrl) return;
    var stamp = token + '|' + new Date().toISOString().slice(0, 10) + '|' + lang;
    if (stored(TOKEN_KEY) === stamp) return;
    fetch(cfg.devicesUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf || '', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ token: token, locale: lang })
    }).then(function (r) { if (r.ok) store(TOKEN_KEY, stamp); }).catch(function () {});
  }

  var pushWired = false;
  function registerPush() {
    if (!pushWired) {
      pushWired = true;
      // The token arrives here, now and whenever the phone changes it.
      on('PushNotifications', 'registration', function (data) { sendToken(data && data.value); });
    }
    return quiet(call('PushNotifications', 'register'));
  }

  /** Asked once work that takes a while has been started, never at launch. */
  function offerNotifications() {
    if (!cfg.signedIn) return;
    quiet(shell('getInfo')).then(function (info) {
      if (!info || !info.pushAvailable) return;
      if (info.notificationsAllowed) { registerPush(); return; }
      var asked = Number(stored(ASKED_KEY) || 0);
      if (asked && Date.now() - asked < 14 * 24 * 3600 * 1000) return;
      store(ASKED_KEY, String(Date.now()));
      openSheet({
        title: t.notify_title,
        text: t.notify_text,
        buttons: [
          { label: t.notify_yes || 'Yes', kind: 'primary', run: function () {
            return shell('ensureNotifications', { lang: lang }).then(function (r) { if (r && r.granted) return registerPush(); });
          } },
          { label: t.notify_no || 'Not now', kind: 'quiet' }
        ]
      });
    });
  }

  // Work that finishes after the reply starts with one of these requests.
  var SLOW_WORK = /\/(dashboard\/(queue|generate)|dub-my-video\/?|dashboard\/voice-cloning\/?)$/;
  var realFetch = window.fetch;
  if (typeof realFetch === 'function') {
    window.fetch = function (input, init) {
      var promise = realFetch.apply(this, arguments);
      try {
        var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        var url = new URL(typeof input === 'string' ? input : (input && input.url) || '', location.href);
        if (method === 'POST' && url.origin === location.origin && SLOW_WORK.test(url.pathname.replace(/^\/(ar|en)(?=\/)/, ''))) {
          promise.then(function (response) { if (response && response.ok) setTimeout(offerNotifications, 1200); }, function () {});
        }
      } catch (e) { /* never in the way of the request */ }
      return promise;
    };
  }

  // --------------------------------------------------- moving between pages

  // A page takes the server most of a second: the app answers the tap at once with a line
  // across the top, and (in the page head) starts loading on touch and cross-fades when ready.
  var loadEl = null, loadTimer = 0;
  function loading(onOff) {
    if (!loadEl) {
      loadEl = document.createElement('div');
      loadEl.className = 'la-load';
      loadEl.appendChild(document.createElement('i'));
      (document.body || document.documentElement).appendChild(loadEl);
    }
    clearTimeout(loadTimer);
    loadEl.classList.toggle('is-on', !!onOff);
    // Never left running: a tap that turned out to be a download, or a page that never came.
    if (onOff) loadTimer = setTimeout(function () { loading(false); }, 12000);
  }

  function leavesPage(link) {
    if (!link || !link.href || link.target === '_blank' || link.hasAttribute('download')) return false;
    if (link.hasAttribute('data-app-open-web') || link.hasAttribute('data-app-signin')) return false;
    try {
      var url = new URL(link.href, location.href);
      if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return false;
      // The same page at another place (#section) loads nothing.
      return !(url.pathname === location.pathname && url.search === location.search && url.hash);
    } catch (e) { return false; }
  }

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button || event.metaKey || event.ctrlKey) return;
    var link = event.target && event.target.closest ? event.target.closest('a') : null;
    if (leavesPage(link) && !isDownloadLink(link)) loading(true);
  });
  document.addEventListener('submit', function (event) {
    var form = event.target;
    // The page's own forms that answer without leaving (they stop the event) show their own progress.
    setTimeout(function () { if (!event.defaultPrevented && form && form.tagName === 'FORM' && form.target !== '_blank') loading(true); }, 0);
  });
  window.addEventListener('pagehide', function () { loading(false); });

  // ------------------------------------------------- plans and payment: in the browser

  // A page that takes a payment is never shown in the app: it opens in the phone's browser,
  // signed in, with what was chosen (AppShellController::openOnWeb). The app then steps back
  // to the page the member was on.
  function openOnWeb(url, thenBack) {
    return shell('openExternal', { url: url }).then(function () {
      if (!thenBack) return;
      setTimeout(function () {
        var back = document.querySelector('[data-app-web-back]');
        if (history.length > 1) history.back(); else if (back) location.replace(back.href);
      }, 700);
    });
  }
  document.addEventListener('click', function (event) {
    var link = event.target && event.target.closest ? event.target.closest('[data-app-open-web]') : null;
    if (!link || !cap()) return;
    event.preventDefault();
    openOnWeb(link.href, false).catch(function () { toast(t.web_failed); });
  }, true);

  // ------------------------------------------- back, refresh, theme, sign-in

  function closeOverlay() {
    if (closeSheet()) return true;
    var menu = document.querySelector('.audio-format-menu');
    if (menu) { document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); if (document.contains(menu)) menu.remove(); return true; }
    var nav = document.getElementById('mobileNav');
    if (nav && nav.classList.contains('open')) { var x = document.getElementById('mobileMenuClose'); if (x) x.click(); return true; }
    var dialog = document.querySelector('dialog[open]');
    if (dialog) { dialog.close(); return true; }
    var open = document.querySelector('[role="dialog"][aria-modal="true"]:not([hidden]):not(.la-sheet), .modal.is-open, .modal.open, .ref-modal.is-open');
    if (open && open.offsetParent !== null) {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      return true;
    }
    return false;
  }

  on('ListenShell', 'backPressed', function () {
    if (closeOverlay()) return;
    // On the app's first pages there is nothing behind: the app steps aside, still running.
    if (cfg.root || history.length <= 1) { quiet(shell('minimize')); return; }
    history.back();
  });

  function syncTheme() {
    quiet(shell('setTheme', { dark: document.documentElement.getAttribute('data-theme') !== 'light' }));
  }

  function settle() {
    quiet(shell('setBackHandler', { enabled: true }));
    quiet(shell('setPullToRefresh', { enabled: !!cfg.refresh }));
    syncTheme();
  }

  // Shown again by going back: the page is as it was left, and the phone is told again what
  // this page wants (it forgot when the other page started).
  window.addEventListener('pageshow', function (event) {
    if (!event.persisted) return;
    loading(false);
    resubscribe();
    settle();
  });

  function boot() {
    settle();
    new MutationObserver(syncTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    // Reached a page that pays: straight to the browser, then back to where the member was.
    if (window.LISTEN_APP_OPEN_WEB && cap()) {
      openOnWeb(window.LISTEN_APP_OPEN_WEB, true).catch(function () {});
    }

    document.addEventListener('click', function (event) {
      var button = event.target && event.target.closest ? event.target.closest('[data-app-signin]') : null;
      if (!button) return;
      event.preventDefault();
      shell('signIn', { locale: lang }).catch(function () { toast(t.signin_failed); });
    });

    // A member who already said yes: keep this phone's token fresh, quietly.
    if (cfg.signedIn) {
      quiet(shell('getInfo')).then(function (info) { if (info && info.pushAvailable && info.notificationsAllowed) registerPush(); });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();

  window.ListenApp = { toast: toast, offerNotifications: offerNotifications };
})();
