/*
 * Listen Agents: the setup in four steps, an agent's page and its link page
 * (window.LISTEN_AGENTS says which). Calls go through ListenAgentCall
 * (agent-call.js); the orb follows the agent's voice ([data-vp-track]).
 */
(function () {
  'use strict';
  var C = window.LISTEN_AGENTS || {};
  var T = C.text || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var rtl = document.documentElement.dir === 'rtl';

  /*
   * A row of tabs that scrolls sideways on a phone: the tab tapped slides to the middle, so the
   * tab after it always shows (a caller of the page may not know the row scrolls), and the row
   * fades at whichever edge has more tabs behind it.
   */
  function sideways(bar) {
    if (!bar) return { centre: function () {}, edges: function () {} };
    function edges() {
      var max = bar.scrollWidth - bar.clientWidth, x = Math.abs(bar.scrollLeft);
      bar.classList.toggle('is-scrolled', max > 2 && x > 2);
      bar.classList.toggle('is-end', max <= 2 || x >= max - 2);
    }
    bar.addEventListener('scroll', edges, { passive: true });
    window.addEventListener('resize', edges);
    edges();
    return {
      edges: edges,
      centre: function (t, smooth) {
        if (!t || bar.scrollWidth <= bar.clientWidth + 2) { edges(); return; }
        var br = bar.getBoundingClientRect(), tr = t.getBoundingClientRect();
        var by = (tr.left + tr.width / 2) - (br.left + br.width / 2);
        if (Math.abs(by) > 2) bar.scrollBy({ left: by, behavior: smooth && !matchMedia('(prefers-reduced-motion: reduce)').matches ? 'smooth' : 'auto' });
        edges();
      },
    };
  }

  // again: times to send it once more when no answer came at all. A page left open for hours can
  // send on a connection the network already closed, and the browser sends a page's own reads
  // again by itself, never a button's request; for a request that is safe to repeat, this does.
  function api(method, url, body, again) {
    var send = function (left) {
      return fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': C.csrf || '', 'X-Requested-With': 'XMLHttpRequest' },
        body: body ? JSON.stringify(body) : undefined,
        credentials: 'same-origin',
      }).catch(function () {
        if (left > 0) return new Promise(function (r) { setTimeout(r, 1200); }).then(function () { return send(left - 1); });
        // No answer at all (offline, or refused by the browser): a sentence, never "Failed to fetch".
        throw new Error(T.network || 'error');
      });
    };
    return send(again || 0).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (!r.ok) { var e = new Error((data && data.message) || 'error'); e.data = data; throw e; }
        return data;
      });
    });
  }
  // A message that stays at the top of the page until it is closed, with an optional way forward.
  function notice(text, action) {
    var main = $('main.ag');
    if (!main) { toast(text); return; }
    var old = $('[data-ag-js-notice]', main);
    if (old) old.remove();
    var box = document.createElement('div');
    box.className = 'ag-notice is-bad';
    box.setAttribute('role', 'alert');
    box.setAttribute('data-ag-js-notice', '');
    var p = document.createElement('span');
    p.textContent = text;
    box.appendChild(p);
    if (action && action.url) {
      var a = document.createElement('a');
      a.className = 'ag-btn ag-btn-sm ag-btn-primary';
      a.href = action.url;
      a.textContent = action.plan ? ((T.balance && T.balance.upgrade) || '').replace(':plan', action.plan) : action.url;
      box.appendChild(a);
    }
    main.insertBefore(box, main.firstChild);
    box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }
  var toastTimer = 0;
  function toast(text) {
    var el = $('[data-ag-toast]');
    if (!el || !text) return;
    el.textContent = text;
    el.classList.add('is-on');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove('is-on'); }, 2200);
  }
  // A one-line box that grows with its text (the setup's rules and steps).
  function grow(el) {
    if (!el || !el.offsetParent) return;
    el.style.height = 'auto';
    el.style.height = el.scrollHeight + 2 + 'px';
  }
  function fill(s, vars) { return String(s || '').replace(/:(\w+)/g, function (m, k) { return vars && vars[k] != null ? vars[k] : m; }); }

  /* ---------- Copy, share, QR, allowed websites, going live ---------- */
  document.addEventListener('click', function (e) {
    var copy = e.target.closest('[data-ag-copy]');
    if (copy) {
      var text = copy.getAttribute('data-ag-copy');
      // The button says it worked for a moment, as well as the toast.
      var done = function () { toast(T.copied); copy.classList.add('is-copied'); clearTimeout(copy.__agCopied); copy.__agCopied = setTimeout(function () { copy.classList.remove('is-copied'); }, 1600); };
      (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(done, function () {
        var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); done(); } catch (err) {} ta.remove();
      });
    }
    var share = e.target.closest('[data-ag-share]');
    if (share) {
      var msg = share.getAttribute('data-text');
      if (navigator.share) navigator.share({ text: msg }).catch(function () {});
      else if (navigator.clipboard) navigator.clipboard.writeText(msg).then(function () { toast(T.copied); });
    }
  });

  // The go-live tiles: drawn and wired once (the QR canvas draws fine while its tab is hidden).
  function drawQr() {
    $$('[data-ag-golive]').forEach(function (box) {
      if (box.__agBound) return;
      box.__agBound = true;
      install(box);
      var canvas = $('[data-ag-qr]', box);
      if (!canvas || !window.qrcode) return;
      var qr = window.qrcode(0, 'M');
      qr.addData(box.getAttribute('data-link'));
      qr.make();
      var n = qr.getModuleCount(), size = canvas.width, cell = size / (n + 4), ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
      ctx.fillStyle = '#17144A';
      for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (qr.isDark(r, c)) ctx.fillRect(Math.round((c + 2) * cell), Math.round((r + 2) * cell), Math.ceil(cell), Math.ceil(cell));
      var dl = $('[data-ag-qr-download]', box);
      if (dl) dl.onclick = function () {
        var a = document.createElement('a'); a.href = canvas.toDataURL('image/png'); a.download = dl.getAttribute('data-name') || 'qr.png'; a.click();
      };
      var origins = $('[data-ag-origins]', box), sites = $('[data-ag-sites]', box);
      // The websites the button may show on: a row each; adding or removing one saves the list.
      var siteList = sites && $('[data-ag-sites-list]', sites), siteForm = sites && $('[data-ag-sites-add]', sites);
      var siteInput = sites && $('[data-ag-site-input]', sites), siteErr = sites && $('[data-ag-site-error]', sites);
      var siteMax = sites ? (+sites.getAttribute('data-max') || 10) : 10;
      function hostOf(v) { return String(v || '').trim().toLowerCase().replace(/^[a-z]+:\/\//, '').replace(/[\/?#].*$/, '').replace(/:\d+$/, '').replace(/^www\./, ''); }
      function siteValues() { return siteList ? $$('li', siteList).map(function (li) { return li.getAttribute('data-site'); }) : []; }
      function syncSites() {
        if (!sites) return;
        var n = siteValues().length, count = $('[data-ag-sites-count]', sites), full = $('[data-ag-sites-full]', sites);
        $('[data-ag-sites-empty]', sites).hidden = n > 0;
        if (siteForm) siteForm.hidden = n >= siteMax;
        if (full) full.hidden = n < siteMax;
        if (count) count.textContent = (count.getAttribute('data-pattern') || ':count / :max').replace(':count', n).replace(':max', siteMax);
        origins.value = siteValues().join(', ');
      }
      function saveSites(then, undo) {
        syncSites();
        api('PATCH', C.agent.urls.update, { allowed_origins: origins.value }).then(function () { toast(T.saved); if (then) then(); })
          .catch(function (err) { if (undo) undo(); syncSites(); toast(err.message); });
      }
      // A row like the page's own: the website's first letter, its address, and Remove.
      function siteRow(site) {
        var host = hostOf(site), li = document.createElement('li');
        li.setAttribute('data-site', site);
        li.innerHTML = '<span class="ag-site-mark" aria-hidden="true"></span><span class="ag-site-name"><bdi dir="ltr"></bdi></span>'
          + '<button type="button" class="ag-sites-remove" data-ag-site-remove></button>';
        $('.ag-site-mark', li).textContent = host.charAt(0).toUpperCase();
        $('bdi', li).textContent = host;
        var rm = $('button', li);
        rm.textContent = T.site_remove_short || 'Remove';
        rm.setAttribute('aria-label', (T.site_remove || ':site').replace(':site', host));
        li.classList.add('is-new');
        return li;
      }
      function addSite(raw, then) {
        var host = hostOf(raw);
        if (siteErr) siteErr.hidden = true;
        if (!/^[a-z0-9-]+(\.[a-z0-9-]+)+$/.test(host)) { if (siteErr) { siteErr.textContent = T.site_invalid; siteErr.hidden = false; } return false; }
        if (siteValues().some(function (s) { return hostOf(s) === host; })) { if (siteErr) { siteErr.textContent = T.site_exists; siteErr.hidden = false; } return false; }
        if (siteValues().length >= siteMax) { if (siteErr) { siteErr.textContent = T.site_max; siteErr.hidden = false; } return false; }
        var li = siteRow('https://' + host);
        siteList.appendChild(li);
        saveSites(then, function () { li.remove(); });
        return true;
      }
      if (sites && C.agent) {
        siteForm.addEventListener('submit', function (e) {
          e.preventDefault();
          if (addSite(siteInput.value)) { siteInput.value = ''; siteInput.focus(); }
        });
        siteInput.addEventListener('input', function () { if (siteErr) siteErr.hidden = true; });
        siteList.addEventListener('click', function (e) {
          var b = e.target.closest('[data-ag-site-remove]');
          if (!b) return;
          var li = b.closest('li'), next = li.nextElementSibling;
          li.remove();
          saveSites(null, function () { siteList.insertBefore(li, next); });
          (siteInput || document.body).focus();
        });
      }
      // "Allow it": the refused website joins the list.
      var allow = $('[data-ag-allow-origin]', box);
      if (allow && sites && C.agent) allow.addEventListener('click', function () {
        allow.disabled = true;
        var done = function () { var row = allow.closest('[data-ag-refused]'); if (row) row.remove(); };
        if (!addSite(allow.getAttribute('data-ag-allow-origin'), done)) { allow.disabled = false; done(); }
      });
    });
  }

  // The install panel: one side panel for every website builder, opened at the tile's tab, with
  // "Check installation" asking whether the call button has been seen on a website yet.
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function install(box) {
    var panel = $('[data-ag-install]', box);
    if (!panel) return;
    var tabs = $$('[data-ag-install-tab]', panel), body = $('.ag-install-body', panel), bar = $('.ag-install-tabs', panel), opener = null;
    // On phones the tabs scroll sideways: the chosen one slides to the middle, and the row fades
    // at whichever edge has more tabs behind it.
    var row = sideways(bar);
    function pick(key, focus) {
      tabs.forEach(function (t) {
        var on = t.getAttribute('data-ag-install-tab') === key;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        if (!on) return;
        if (focus) t.focus({ preventScroll: true });
        row.centre(t, true);
      });
      $$('[data-ag-install-panel]', panel).forEach(function (p) { p.hidden = p.getAttribute('data-ag-install-panel') !== key; });
      if (body) body.scrollTop = 0;
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { pick(t.getAttribute('data-ag-install-tab')); });
      t.addEventListener('keydown', function (e) {
        var step = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, Home: -i, End: tabs.length - 1 - i }[e.key];
        if (step === undefined) return;
        e.preventDefault();
        pick(tabs[(i + step + tabs.length) % tabs.length].getAttribute('data-ag-install-tab'), true);
      });
    });
    function shut() {
      if (!panel.open || panel.classList.contains('is-closing')) return;
      var done = function () {
        panel.classList.remove('is-closing');
        if (panel.close) panel.close(); else panel.removeAttribute('open');
        if (opener) opener.focus();
      };
      if (still) return done();
      panel.classList.add('is-closing');
      setTimeout(done, 200);
    }
    $$('[data-ag-install-open]', box).forEach(function (b) {
      b.addEventListener('click', function () {
        opener = b;
        if (panel.showModal) { if (!panel.open) panel.showModal(); } else panel.setAttribute('open', '');
        pick(b.getAttribute('data-ag-install-open') || 'wordpress', true);
      });
    });
    // Arabic pages: the owner's dashboard in Arabic or English decides the menu names in the steps (remembered here).
    function ui(value) {
      panel.setAttribute('data-ui', value);
      $$('[data-ag-ui]', panel).forEach(function (r) { r.checked = r.value === value; });
      try { localStorage.setItem('listen_dashboard_ui', value); } catch (e) {}
    }
    var savedUi = null;
    try { savedUi = localStorage.getItem('listen_dashboard_ui'); } catch (e) {}
    if (savedUi === 'en' || savedUi === 'ar') ui(savedUi);
    $$('[data-ag-ui]', panel).forEach(function (r) { r.addEventListener('change', function () { if (r.checked) ui(r.value); }); });
    $$('[data-ag-install-close]', panel).forEach(function (c) { c.addEventListener('click', shut); });
    panel.addEventListener('cancel', function (e) { e.preventDefault(); shut(); });
    panel.addEventListener('click', function (e) { if (e.target === panel) shut(); });

    // Check installation (in the panel's footer and above the platforms): has the button been seen on a website?
    var status = $('[data-ag-install-status]', panel);
    $$('[data-ag-install-check]', box).forEach(function (check) {
      check.addEventListener('click', function () {
        check.disabled = true;
        check.textContent = check.getAttribute('data-checking');
        var wait = new Promise(function (ok) { setTimeout(ok, still ? 0 : 650); });
        Promise.all([api('GET', panel.getAttribute('data-status-url')), wait]).then(function (r) {
          var d = r[0] || {};
          if (status) {
            status.classList.toggle('is-on', !!d.seen);
            status.classList.add('is-checked');
            $('span', status).textContent = d.message || '';
          }
          var tile = $('[data-ag-seen]', box);
          if (tile && d.tile) {
            tile.classList.toggle('is-on', !!d.seen);
            tile.classList.add('is-checked');
            tile.lastChild.textContent = ' ' + d.tile;
          }
        }).catch(function (err) { toast(err.message); }).then(function () {
          check.disabled = false;
          check.textContent = check.getAttribute('data-label');
        });
      });
    });
  }

  function goLive(btn) {
    if (!btn || !C.agent) return;
    btn.addEventListener('click', function () {
      btn.disabled = true;
      api('PATCH', C.agent.urls.update, { status: 'live' }).then(function () {
        var ok = $('[data-ag-success]');
        if (ok) ok.classList.add('is-on');
        btn.hidden = true;
      }).catch(function (err) {
        btn.disabled = false;
        var d = err.data || {};
        if (d.reason && d.reason !== 'voice') notice(err.message, d.upgrade && !d.upgrade.none ? d.upgrade : null); else toast(err.message);
      });
    });
  }

  /* ---------- Voices ---------- */
  var audio = null, playingCard = null;
  var PLAY_ICON = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
  var PAUSE_ICON = '<svg viewBox="0 0 24 24"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>';
  // The play button shows what a tap will do: pause while its voice plays, play again after.
  function playState(card, on) {
    var btn = card && $('.ag-play', card);
    if (!btn) return;
    btn.innerHTML = on ? PAUSE_ICON : PLAY_ICON;
    btn.classList.toggle('is-playing', !!on);
    card.classList.toggle('is-playing', !!on);
    var name = btn.getAttribute('data-name') || '';
    btn.setAttribute('aria-label', fill(on ? (T.pause || T.play) : T.play, { name: name }));
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  }
  // The ring round a speaker's face fills with the preview, frame by frame from the audio's own
  // clock (the clock is read between its updates, so the ring never steps), and fades when it ends.
  var ringFrame = 0;
  function stopPreview() {
    cancelAnimationFrame(ringFrame);
    if (audio) { audio.pause(); audio = null; }
    if (playingCard) {
      var card = playingCard, ring = $('circle', card);
      playState(card, false);
      playingCard = null;
      if (ring) setTimeout(function () { if (playingCard !== card) ring.style.strokeDashoffset = 289; }, 320);
    }
  }
  function runRing(card, clip) {
    var ring = $('circle', card), at = 0, seen = -1, since = 0;
    if (!ring) return;
    ring.style.strokeDashoffset = 289;
    (function frame(now) {
      if (audio !== clip || playingCard !== card) return;
      if (clip.duration && isFinite(clip.duration)) {
        // currentTime moves in steps on some browsers: carry it on with the clock until the next step.
        if (clip.currentTime !== seen) { seen = clip.currentTime; since = now; }
        at = clip.paused ? seen : seen + (now - since) / 1000 * (clip.playbackRate || 1);
        ring.style.strokeDashoffset = 289 * (1 - Math.min(1, Math.max(0, at / clip.duration)));
      }
      ringFrame = requestAnimationFrame(frame);
    })(performance.now());
  }
  function voiceGallery(box, lang, onPick) {
    if (!box) return;
    var all = (C.voices && C.voices[lang]) || [];
    var chosen = C.agent && C.agent.speaker_id;
    var dialect = (C.agent && C.agent.dialect) || ($('#ag-dialect') && $('#ag-dialect').value) || null;
    var regions = C.regions || {};
    function score(v) {
      if (dialect && v.dialect === dialect) return 0;
      if (dialect && regions[v.dialect] && regions[v.dialect] === regions[dialect]) return 1;
      return 2;
    }
    function render() {
      var scope = ($('input[name="ag-voice-scope"]:checked') || {}).value || 'all';
      var gender = ($('input[name="ag-voice-gender"]:checked') || {}).value || 'any';
      var list = all.filter(function (v) { return gender === 'any' || v.gender === gender; });
      list.sort(function (a, b) { return score(a) - score(b); });
      if (scope === 'suggested') list = list.slice(0, 8);
      box.innerHTML = '';
      list.forEach(function (v) {
        var card = document.createElement('div');
        card.className = 'ag-voice' + (v.id === chosen ? ' is-chosen' : '');
        card.setAttribute('role', 'button');
        card.setAttribute('tabindex', '0');
        card.setAttribute('aria-pressed', v.id === chosen ? 'true' : 'false');
        card.setAttribute('aria-label', fill(T.pick, { name: v.name }));
        card.innerHTML =
          '<div class="ag-avatar">' + (v.avatar ? '<img loading="lazy" alt="" src="' + v.avatar + '">' : '<img alt="">') +
          '<svg class="ag-ring" viewBox="0 0 100 100"><circle cx="50" cy="50" r="46"/></svg>' +
          (v.preview ? '<button type="button" class="ag-play" aria-pressed="false" aria-label="' + fill(T.play, { name: v.name }) + '">' + PLAY_ICON + '</button>' : '') +
          '</div><div class="ag-voice-name"></div><div class="ag-voice-dialect">' + (v.flag ? '<img alt="" src="' + v.flag + '">' : '') + '<span></span></div>';
        $('.ag-voice-name', card).textContent = v.name;
        $('.ag-voice-dialect span', card).textContent = v.dialect_label || '';
        var play = $('.ag-play', card);
        if (play) play.setAttribute('data-name', v.name);
        if (play) play.addEventListener('click', function (e) {
          e.stopPropagation();
          if (playingCard === card) { stopPreview(); return; }
          stopPreview();
          playingCard = card;
          playState(card, true);
          var clip = audio = new Audio(v.preview);
          clip.addEventListener('playing', function () { cancelAnimationFrame(ringFrame); runRing(card, clip); });
          clip.onended = stopPreview;
          clip.play().catch(stopPreview);
        });
        function pick() {
          chosen = v.id;
          $$('.ag-voice', box).forEach(function (c) { c.classList.remove('is-chosen', 'is-saving'); c.setAttribute('aria-pressed', 'false'); c.removeAttribute('aria-busy'); });
          card.classList.add('is-chosen');
          card.setAttribute('aria-pressed', 'true');
          // While it saves (a voice of the other gender also rewrites its words), its tick turns.
          var saving = onPick && onPick(v);
          if (saving && saving.then) {
            card.classList.add('is-saving');
            card.setAttribute('aria-busy', 'true');
            var saved = function () { card.classList.remove('is-saving'); card.removeAttribute('aria-busy'); };
            saving.then(saved, saved);
          }
        }
        card.addEventListener('click', pick);
        card.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); } });
        box.appendChild(card);
      });
    }
    $$('input[name="ag-voice-scope"], input[name="ag-voice-gender"]').forEach(function (i) { i.addEventListener('change', render); });
    render();
    return {
      render: render,
      setDialect: function (d) { dialect = d; render(); },
      setLanguage: function (lg, d) { all = (C.voices && C.voices[lg]) || []; chosen = C.agent && C.agent.speaker_id; if (d) dialect = d; render(); },
    };
  }

  /* ---------- Talking to the agent ---------- */
  function visitorId() {
    try {
      var id = localStorage.getItem('listen_agent_visitor');
      if (!id) { id = Math.random().toString(36).slice(2) + Date.now().toString(36); localStorage.setItem('listen_agent_visitor', id); }
      return id;
    } catch (e) { return ''; }
  }
  // hooks (the demo page): onState(state) after each change; onError(message, error) returning
  // false when it has dealt with a call that could not start.
  function talk(root, hooks) {
    if (!root) return;
    hooks = hooks || {};
    var quietError = false, lastError = '';
    var btn = $('[data-ag-call]', root), label = $('[data-ag-call-label]', root), stateEl = $('[data-ag-talk-state]', root);
    var stage = $('[data-ag-stage]', root);
    var bars = $$('.ag-wave i', root), face = $('[data-ag-talk-avatar]', root);
    var faceImg = $('[data-ag-talk-avatar-img]', root);
    var shape = [0.35, 0.6, 0.85, 1, 0.8, 0.55, 0.3];
    var list = $('[data-ag-transcript]', root);
    var call = null, lastAsked = '', lastCaller = null;
    var states = T.states || {};
    // The link page: every line busy, the caller's place in line, then a number to be called back.
    var sub = $('[data-ag-talk-sub]', root), leave = $('[data-ag-talk-leave]', root);
    var pos = $('[data-ag-talk-pos]', root), posText = $('[data-ag-talk-pos-text]', root);
    var form = $('[data-ag-callback]', root), done = $('[data-ag-callback-done]', root);
    var buttons = $('.ag-talk-buttons', root), L = T.lines || {}, B = T.callback || {};
    var waitPos = 0, sentTo = null;
    // The line that closes a call ("Call ended after 01:24"), counted from when it was answered.
    var answeredAt = 0, summary = null;
    // 01:24, as the call button counts a call.
    function clock(sec) { sec = Math.max(0, Math.round(sec)); return ('0' + Math.floor(sec / 60)).slice(-2) + ':' + ('0' + (sec % 60)).slice(-2); }
    function hideSummary() { if (summary) summary.hidden = true; }
    // One small line under what was said, where the eye already is.
    function showSummary(seconds) {
      if (!T.ended_after) return;
      if (!summary) {
        summary = document.createElement('p');
        summary.className = 'ag-talk-ended';
        summary.setAttribute('role', 'status');
        root.appendChild(summary);
      }
      var words = String(T.ended_after).split(':time');
      summary.textContent = words[0] || '';
      var t = document.createElement('bdi');
      t.dir = 'ltr';
      t.textContent = clock(seconds);
      summary.appendChild(t);
      summary.appendChild(document.createTextNode(words[1] || ''));
      summary.hidden = false;
    }
    function waitText() {
      var ahead = L.ahead || {}, k = Math.max(0, waitPos - 1);
      if (k < 1) return L.next || '';
      return ahead[k] || String(ahead.many || '').replace(':count', k);
    }
    function setState(s) {
      if (s === 'error' && quietError) { quietError = false; s = 'idle'; }
      // The call's length counts from when it was answered; a call that never was has no summary.
      if (s === 'connecting') { answeredAt = 0; hideSummary(); }
      if ((s === 'listening' || s === 'speaking') && !answeredAt) answeredAt = Date.now();
      var lasted = s === 'ended' && answeredAt ? (Date.now() - answeredAt) / 1000 : 0;
      if (s !== 'listening' && s !== 'speaking') answeredAt = 0;
      // A woman's voice has its own words in Arabic ("سارة تتحدث"); the setup steps get both forms.
      var key = C.agent && C.agent.gender === 'female' && states[s + '_female'] ? s + '_female' : s;
      if (s === 'connecting') lastError = '';
      var waiting = s === 'waiting', away = s === 'busy' || s === 'sent';
      // Before a call nothing is said: the button under the orb is enough.
      stateEl.textContent = s === 'idle' || away || (lasted >= 1 && T.ended_after) ? '' : (s === 'mic' ? (T.mic || '') : waiting ? (L.waiting || '') : s === 'error' && lastError ? lastError : fill(states[key] || '', { name: (C.agent && C.agent.name) || '' }));
      stateEl.classList.toggle('is-error', s === 'error' || s === 'mic');
      var live = s === 'connecting' || s === 'listening' || s === 'speaking';
      // While waiting the button quietly gives up the wait; "leave my number" is the main way on.
      btn.classList.toggle('is-live', live);
      btn.classList.toggle('is-quiet', waiting);
      btn.classList.toggle('ag-btn-primary', !waiting);
      root.classList.toggle('is-waiting', waiting);
      label.textContent = waiting ? (L.cancel || T.end) : live ? T.end : T.start;
      stage.classList.toggle('is-speaking', s === 'speaking');
      stage.classList.toggle('is-live', live);
      stage.classList.toggle('is-waiting', waiting);
      if (sub) sub.hidden = !waiting;
      if (pos) { pos.hidden = !waiting; posText.textContent = waiting ? waitText() : ''; }
      if (leave) leave.hidden = !waiting;
      if (form) showCallback(s);
      if (!live && !waiting) { stage.classList.remove('is-playing'); stage.__vpLevel = 0; call = null; level(0, 0); }
      if (lasted >= 1) showSummary(lasted);
      else if (s !== 'ended' && s !== 'idle') hideSummary();
      if (hooks.onState) hooks.onState(s);
    }
    // Still busy: the form in place of the call; once sent, the thanks with the number they left.
    function showCallback(s) {
      var asking = s === 'busy', thanked = s === 'sent';
      [stage, buttons, list].forEach(function (n) { if (n) n.hidden = asking || thanked; });
      if (asking && form.hidden) {
        form.reset();
        $('[data-ag-callback-error]', form).hidden = true;
        form.hidden = false;
        setTimeout(function () { form.querySelector('[name="name"]').focus(); }, 60);
      }
      if (!asking) form.hidden = true;
      done.hidden = !thanked;
      if (!thanked) return;
      $('[data-ag-callback-title]', done).textContent = sentTo && sentTo.name ? fill(B.sent_title_named, { name: sentTo.name }) : (B.sent_title || '');
      var text = $('[data-ag-callback-text]', done), words = String(B.sent_text || '').split(':phone');
      text.textContent = words[0] || '';
      if (words.length > 1) {
        var num = document.createElement('bdi');
        num.dir = 'ltr';
        num.textContent = sentTo ? sentTo.phone : '';
        text.appendChild(num);
        text.appendChild(document.createTextNode(words[1] || ''));
      }
      done.focus();
    }
    if (leave) leave.addEventListener('click', function () { if (call && call.giveUp) call.giveUp(); });
    if (form) {
      var send = $('[data-ag-callback-send]', form), errEl = $('[data-ag-callback-error]', form);
      var nameIn = $('[name="name"]', form), phoneIn = $('[name="phone"]', form);
      var bad = function (m, onPhone) {
        errEl.textContent = m || '';
        errEl.hidden = !m;
        send.disabled = false;
        send.textContent = B.send || '';
        phoneIn.setAttribute('aria-invalid', onPhone ? 'true' : 'false');
        if (onPhone) phoneIn.focus();
      };
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var number = phoneIn.value.trim(), who = nameIn.value.trim();
        if ((number.match(/[0-9٠-٩۰-۹]/g) || []).length < 6) { bad(B.invalid_phone, true); return; }
        send.disabled = true;
        send.textContent = B.sending || '';
        errEl.hidden = true;
        fetch(C.callback, { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, credentials: 'omit',
          body: JSON.stringify({ slug: C.slug, visitor: visitorId(), name: who, phone: number }) })
          .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok && d.ok, d: d || {} }; }); })
          .then(function (res) {
            if (!res.ok) { bad(res.d.message || B.not_now, res.d.error === 'phone'); return; }
            sentTo = { name: who, phone: res.d.phone || number };
            setState('sent');
          }, function () { bad(B.not_now); });
      });
      $('[data-ag-callback-again]', form).addEventListener('click', function () { setState('idle'); btn.click(); });
    }
    // The orb follows the agent's voice from inside the ball (js/listen-orbs.js reads the stage's level).
    function level(a, c) {
      a = Math.min(1, a || 0); c = Math.min(1, c || 0);
      stage.__vpLevel = a;
      stage.classList.toggle('is-playing', a > 0.01);
    }
    function bubble(role, text, extra) {
      var b = document.createElement('div');
      b.className = 'ag-bubble is-' + role;
      b.dir = 'auto';
      var who = document.createElement('small');
      who.textContent = role === 'agent' ? ((C.agent && C.agent.name) || '') : role === 'caller' ? T.you : '';
      if (role !== 'event') b.appendChild(who);
      var words = document.createTextNode(text);
      b.appendChild(words);
      if (extra) b.appendChild(extra);
      // The newest line in view, gently, unless the owner scrolled up to read.
      var follow = list.scrollHeight - list.scrollTop - list.clientHeight < 60;
      list.appendChild(b);
      if (follow) list.scrollTop = list.scrollHeight;
      return words;
    }
    // The business's own links the agent wrote in the chat (show_links), each opening in a new tab.
    function linksBubble(links) {
      var b = document.createElement('div');
      b.className = 'ag-bubble is-agent is-links';
      var who = document.createElement('small');
      who.textContent = (C.agent && C.agent.name) || '';
      b.appendChild(who);
      var box = document.createElement('div');
      box.className = 'ag-chat-links';
      links.forEach(function (l) {
        var a = document.createElement('a');
        a.className = 'ag-chat-link';
        a.href = l.url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer nofollow';
        a.dir = 'ltr';
        a.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/></svg>';
        var label = document.createElement('span');
        label.textContent = l.label;
        a.appendChild(label);
        box.appendChild(a);
      });
      b.appendChild(box);
      list.appendChild(b);
      list.scrollTop = list.scrollHeight;
      return b;
    }
    function correction(question) {
      if (!C.agent || !C.agent.urls || C.mode === 'link' || C.mode === 'demo') return null;
      var d = document.createElement('details');
      d.className = 'ag-correct';
      d.innerHTML = '<summary></summary><textarea class="ag-textarea" dir="auto"></textarea><button type="button" class="ag-btn ag-btn-sm ag-btn-primary"></button>';
      $('summary', d).textContent = T.correct;
      $('textarea', d).placeholder = T.correct_placeholder;
      var save = $('button', d);
      save.textContent = T.correct_save;
      save.addEventListener('click', function () {
        var answer = $('textarea', d).value.trim();
        if (!answer) return;
        save.disabled = true;
        api('POST', C.agent.urls.correct, { question: question, answer: answer }).then(function () {
          toast(T.correct_saved); d.open = false; d.querySelector('summary').textContent = '✓ ' + T.correct_saved;
        }).catch(function (err) { save.disabled = false; toast(err.message); });
      });
      return d;
    }
    function begin() {
      if (C.mode === 'link') {
        return fetch(C.start, { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, credentials: 'omit',
          body: JSON.stringify({ slug: C.slug, page_url: location.href, visitor: visitorId() }) })
          .then(function (r) { return r.json().catch(function () { return {}; }); });
      }
      return api('POST', C.agent.urls.test, typeof C.callBody === 'function' ? C.callBody() : undefined);
    }
    btn.addEventListener('click', function () {
      if (call) { call.end(); return; }
      if (C.mode === 'link' && C.available === false) { stateEl.textContent = T.paused || ''; return; }
      list.innerHTML = '';
      hideSummary();
      call = window.ListenAgentCall.start({
        begin: begin,
        onState: setState,
        onTurn: function (t) {
          if (t.role === 'caller') { lastAsked = t.text; lastCaller = t; t.view = bubble('caller', t.text); }
          else t.view = bubble('agent', t.text, correction(lastAsked));
        },
        // The caller's line written again from their voice, in the agent's dialect; an agent line
        // that came out in Latin letters, in Arabic ones.
        onFix: function (t) {
          if (t.view) {
            t.view.nodeValue = t.text;
            var b = t.view.parentNode;
            if (b && b.classList) { b.classList.remove('is-fixed'); void b.offsetWidth; b.classList.add('is-fixed'); }
          }
          if (t === lastCaller) lastAsked = t.text;
        },
        onLinks: function (t) { t.view = linksBubble(t.links); },
        onLevel: level,
        onTool: function (name, args) {
          if (name === 'save_contact' && C.mode !== 'link') bubble('event', fill(T.saved_contact, { details: [args.name, args.phone, args.email].filter(Boolean).join(' · ') }));
        },
        onError: function (msg, err) {
          if (hooks.onError && hooks.onError(msg, err) === false) { quietError = true; return; }
          // The server's own reason (no plan, minutes or credits left) in place of "could not start";
          // when no call can start until the plan changes, the button stays greyed out.
          lastError = msg || '';
          var reason = err && err.data && err.data.reason;
          if (reason === 'plan' || reason === 'minutes' || reason === 'credits') btn.disabled = true;
          else if (msg) toast(msg);
        },
        // Every line busy (the link page): the caller's place, kept fresh while they wait.
        onWait: function (w) { waitPos = (w && w.position) || 0; if (pos && !pos.hidden) posText.textContent = waitText(); },
        patience: 60,
      });
      if (window.ListenOrbs) window.ListenOrbs.wake();
    });
    // The call shows the orb only; the speaker's face stays in the voice gallery. A new voice
    // can change the words ("جاهزة" for a woman's voice), so the waiting line is written again.
    return {
      setAvatar: function () { if (!call) setState('idle'); },
      // Another agent picked (the demo): its page starts clean, with nothing the last one said.
      reset: function () {
        if (call) return;
        list.innerHTML = '';
        hideSummary();
        setState('idle');
      },
    };
  }

  /* ---------- The setup in four steps ---------- */
  function setup() {
    speaksPickers();
    var panes = $$('[data-ag-pane]'), links = $$('[data-ag-step-link]'), bar = $('[data-ag-bar]');
    var current = -1, furthest = C.agent ? (C.agent.speaker_id ? 3 : 1) : 0, gallery = null;
    function show(i) {
      i = Math.max(0, Math.min(3, i));
      // An agent made on this page has no publishing parts yet (they are drawn by the server): load them.
      var slot = $('[data-ag-golive-slot]');
      if (i === 3 && slot && !slot.children.length && C.agent && C.agent.urls) { location.assign(C.agent.urls.edit + '?step=3'); return; }
      var back = i < current;
      stopPreview();
      panes.forEach(function (p, n) {
        p.classList.toggle('is-current', n === i);
        p.classList.toggle('is-back', n === i && back);
      });
      links.forEach(function (l, n) {
        l.classList.toggle('is-current', n === i);
        l.classList.toggle('is-done', n < furthest && n !== i);
        l.disabled = n > furthest;
      });
      if (bar) bar.style.width = (i / 3 * 100) + '%';
      current = i;
      if (history.replaceState && C.agent) history.replaceState(null, '', location.pathname + '?step=' + i);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    links.forEach(function (l) { l.addEventListener('click', function () { show(+l.getAttribute('data-ag-step-link')); }); });
    $$('[data-ag-go]').forEach(function (b) { b.addEventListener('click', function () { var n = +b.getAttribute('data-ag-go'); furthest = Math.max(furthest, n); show(n); }); });

    // Step 1.
    var form = $('[data-ag-business-form]'), err = $('[data-ag-error]');
    var lang = function () { return ($('input[name="language"]:checked', form) || {}).value || 'ar'; };
    $$('input[name="language"]', form).forEach(function (r) { r.addEventListener('change', function () {
      var en = lang() === 'en';
      ['[data-ag-dialect-field]', '[data-ag-dialect-note]'].forEach(function (q) { var n = $(q); if (n) n.hidden = en; });
      ['[data-ag-accent-field]', '[data-ag-accent-note]'].forEach(function (q) { var n = $(q); if (n) n.hidden = !en; });
    }); });
    var writing = $('[data-ag-writing]'), businessBox = $('[data-ag-business]'), wTitle = $('[data-ag-writing-title]');
    var allChecks = $$('[data-ag-check]'), checks = allChecks, slow = $('[data-ag-slow]');
    var checkTimer = 0, slowTimer = 0;
    // What is being done, a line at a time. Reading a website comes first and only when there is one;
    // the last line stays open until the agent is ready, and only a long wait adds a word on why.
    function animateChecks(hasSite, name) {
      allChecks.forEach(function (c) { c.hidden = !hasSite && c.getAttribute('data-ag-check') === 'read'; });
      checks = allChecks.filter(function (c) { return !c.hidden; });
      checks.forEach(function (c) { c.classList.remove('is-active', 'is-done'); });
      writing.classList.remove('is-ready');
      wTitle.textContent = fill(wTitle.getAttribute('data-title'), { name: name });
      slow.textContent = slow.getAttribute(hasSite ? 'data-site' : 'data-plain');
      slow.hidden = true;
      var i = 0;
      function step() {
        if (i > 0) checks[i - 1].classList.replace('is-active', 'is-done');
        if (i < checks.length - 1) {
          checks[i].classList.add('is-active');
          // The website takes the longest: its line stays a little longer.
          var wait = (checks[i].getAttribute('data-ag-check') === 'read' ? 3600 : 1700) + Math.random() * 700;
          i++;
          checkTimer = setTimeout(step, wait);
        } else {
          checks[i].classList.add('is-active');
          slowTimer = setTimeout(function () { slow.hidden = false; }, 5000);
        }
      }
      step();
    }
    function stopChecks() { clearTimeout(checkTimer); clearTimeout(slowTimer); }
    function finishChecks(then) {
      stopChecks();
      slow.hidden = true;
      var k = 0;
      (function tick() {
        if (k < checks.length) { checks[k].classList.remove('is-active'); checks[k].classList.add('is-done'); k++; setTimeout(tick, 150); }
        else setTimeout(then, 380);
      })();
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      err.hidden = true;
      var data = {
        website_url: $('#ag-website').value.trim(),
        business_name: $('#ag-biz').value.trim(),
        name: $('#ag-name').value.trim(),
        business_type: ($('input[name="business_type"]:checked', form) || {}).value || 'other',
        jobs: $$('input[name="jobs[]"]:checked', form).map(function (i) { return i.value; }),
        language: lang(),
        dialect: lang() === 'en' && $('#ag-accent') ? $('#ag-accent').value : $('#ag-dialect').value,
        notes: $('#ag-notes').value,
      };
      if (!data.business_name || !data.name) { err.textContent = T.error_name; err.hidden = false; return; }
      businessBox.hidden = true; writing.hidden = false;
      window.scrollTo({ top: 0, behavior: 'smooth' });
      animateChecks(!!data.website_url, data.name);
      var req = C.agent ? api('POST', C.agent.urls.write, data) : api('POST', C.urls.store, data);
      req.then(function (res) {
        C.agent = Object.assign(C.agent || {}, res.agent || {});
        finishChecks(function () { ready(res); });
      }).catch(function (e2) {
        stopChecks();
        writing.hidden = true; businessBox.hidden = false;
        err.textContent = (e2.data && e2.data.message) || e2.message; err.hidden = false;
      });
    });
    // Ready: a moment to say so, then its voice, on the same page (the address becomes the agent's).
    // The answers the agent was set up with for tricky moments are on its page, under Setup.
    function ready(res) {
      wTitle.textContent = fill(wTitle.getAttribute('data-ready'), { name: C.agent.name || '' });
      writing.classList.add('is-ready');
      setTimeout(function () {
        if (history.replaceState && C.agent.urls) history.replaceState(null, '', C.agent.urls.edit + '?step=1');
        var openLink = $('[data-ag-open-agent]');
        if (openLink && C.agent.urls) openLink.href = C.agent.urls.show;
        var siteNote = $('[data-ag-site-note]');
        if (siteNote) siteNote.hidden = res.site_read !== false;
        var talkTitle = $('[data-ag-talk-title]');
        if (talkTitle && T.talk_heading) talkTitle.textContent = fill(T.talk_heading, { name: C.agent.name || '' });
        if (gallery) gallery.setLanguage(C.agent.language || lang(), C.agent.dialect);
        writing.hidden = true; businessBox.hidden = false;
        furthest = Math.max(furthest, 1);
        show(1);
      }, 900);
    }

    // Step 2.
    var voiceNext = $('[data-ag-voice-next]');
    var talker = talk($('[data-ag-talk]'));
    gallery = voiceGallery($('[data-ag-voices]'), (C.agent && C.agent.language) || lang(), function (v) {
      if (!C.agent || !C.agent.urls) return;
      return api('PATCH', C.agent.urls.update, { speaker_id: v.id }).then(function (res) {
        var a = (res && res.agent) || {};
        C.agent.speaker_id = v.id;
        C.agent.gender = v.gender;
        if (a.name && a.name !== C.agent.name) {
          C.agent.name = a.name;
          var talkTitle = $('[data-ag-talk-title]');
          if (talkTitle && T.talk_heading) talkTitle.textContent = fill(T.talk_heading, { name: a.name });
          // Step 1 shows the new name too, so going back and writing again keeps it.
          var nameField = $('#ag-name');
          if (nameField) nameField.value = a.name;
          toast(fill(v.gender === 'female' ? T.voice_renamed_female : T.voice_renamed, { name: a.name }));
        }
        voiceNext.disabled = false;
        if (talker) talker.setAvatar(v.avatar);
        var hint = $('[data-ag-talk-hint]');
        if (hint) hint.textContent = hint.getAttribute(v.gender === 'female' ? 'data-female' : 'data-male');
        furthest = Math.max(furthest, 2);
      }).catch(function (err) { toast(err.message); });
    });
    if (C.agent && C.agent.speaker_id) voiceNext.disabled = false;
    voiceNext.addEventListener('click', function () { furthest = Math.max(furthest, 2); show(2); });
    goLive($('[data-ag-golive-btn]'));
    if (C.agent && C.agent.status === 'live') { var ok = $('[data-ag-success]'); if (ok) ok.classList.add('is-on'); var gb = $('[data-ag-golive-btn]'); if (gb) gb.hidden = true; }

    var q = parseInt(new URLSearchParams(location.search).get('step') || '', 10);
    show(isNaN(q) ? (C.agent ? (C.agent.speaker_id ? 2 : 1) : 0) : Math.min(q, furthest));
  }

  /* ---------- The dialect an agent speaks: the studio's own dialect panel ---------- */
  function speaksPickers() {
    var node = $('[data-ag-picker-meta]');
    if (!node || !window.VoiceStudio || !window.VoiceStudio.picker) return;
    var meta = {};
    try { meta = JSON.parse(node.textContent || '{}') || {}; } catch (e) { return; }
    $$('[data-ag-speaks-select]').forEach(function (select) {
      var button = select.parentNode.querySelector('[data-vs-pick="dialect"]');
      if (button && !button.hasAttribute('data-ag-ready')) { button.setAttribute('data-ag-ready', ''); window.VoiceStudio.picker(button, select, meta); }
    });
  }

  /* ---------- An agent's page ---------- */
  function agentPage() {
    var tabs = $$('[data-ag-tab]'), panels = $$('[data-ag-panel]'), main = $('.ag-main');
    var row = sideways(tabs[0] && tabs[0].parentNode);
    function open(name, fromMenu) {
      if (!$('[data-ag-panel="' + name + '"]')) name = 'overview';
      tabs.forEach(function (t) {
        var on = t.getAttribute('data-ag-tab') === name;
        t.classList.toggle('is-current', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        // On phones the tabs scroll sideways: the open one slides to the middle, so the next one shows.
        if (on) row.centre(t, fromMenu);
      });
      panels.forEach(function (p) {
        var on = p.getAttribute('data-ag-panel') === name;
        p.classList.toggle('is-current', on);
        if (on) $$('.ag-grow', p).forEach(grow);
      });
      if (history.replaceState) history.replaceState(null, '', '#' + name);
      // The menu stays in view as a long section scrolls: a section opened from far down starts at its top.
      if (fromMenu && main) {
        var top = main.getBoundingClientRect().top + window.pageYOffset - (window.innerWidth > 1020 ? 88 : 125);
        if (window.pageYOffset > top + 40) window.scrollTo({ top: Math.max(0, top), behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
      }
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { open(t.getAttribute('data-ag-tab'), true); }); });
    $$('[data-ag-tab-go]').forEach(function (b) { b.addEventListener('click', function () { open(b.getAttribute('data-ag-tab-go')); }); });
    open((location.hash || '#overview').slice(1));

    var talker = talk($('[data-ag-talk]'));
    // A new name (from the Name field, or a voice of the other gender) shows wherever the page says it.
    function showName(name) {
      C.agent.name = name;
      $$('[data-ag-hero-name]').forEach(function (el) { el.textContent = name; });
      $$('[data-ag-talk-label]').forEach(function (el) { el.textContent = fill(T.talk_to, { name: name }); });
      var input = $('[name="name"]', sf);
      if (input && document.activeElement !== input) input.value = name;
    }
    // Its words, rewritten for a new voice, replace the ones on the page.
    function showWords(a) {
      if (!sf) return;
      var script = a.script || {};
      var set = function (field, value) { var el = $('[name="' + field + '"]', sf); if (el && value != null) el.value = value; };
      set('first_message', a.first_message);
      set('identity', script.identity);
      set('handoff', script.handoff);
      ['never', 'steps'].forEach(function (k) {
        var boxes = $$('[data-ag-list="' + k + '"] textarea', sf), items = script[k] || [];
        if (boxes.length === items.length) boxes.forEach(function (b, i) { b.value = items[i]; grow(b); });
      });
    }
    var sf = $('[data-ag-script-form]');
    var gallery = voiceGallery($('[data-ag-voices]'), C.agent.language, function (v) {
      return api('PATCH', C.agent.urls.update, { speaker_id: v.id }).then(function (res) {
        var a = (res && res.agent) || {};
        C.agent.speaker_id = v.id;
        C.agent.gender = v.gender;
        if (talker) talker.setAvatar(v.avatar);
        var cur = $('.ag-voice-current');
        if (cur) {
          var img = $('img', cur), name = $('[data-ag-voice-name]', cur), dialect = $('[data-ag-voice-dialect]', cur);
          if (img && v.avatar) img.src = v.avatar;
          if (name) name.textContent = v.name;
        }
        var face = $('.ag-hero-face img');
        if (face && v.avatar) face.src = v.avatar;
        $$('[data-ag-hero-voice]').forEach(function (el) { el.textContent = fill(T.voice_of, { name: v.name }); });
        if (a.name && a.name !== C.agent.name) {
          showName(a.name);
          showWords(a);
          toast(fill(v.gender === 'female' ? T.voice_renamed_female : T.voice_renamed, { name: a.name }));
        } else {
          toast(T.saved);
        }
      }).catch(function (err) { toast(err.message); });
    });
    // The dialect (or accent) it speaks, picked in the studio's panel: saved at once; its voices come first.
    speaksPickers();
    var speaks = $('[data-ag-speaks-select]');
    if (speaks) speaks.addEventListener('change', function () {
      var code = speaks.value, was = C.agent.dialect;
      var label = (speaks.options[speaks.selectedIndex] || {}).textContent || '';
      speaks.disabled = true;
      api('PATCH', C.agent.urls.update, { dialect: code }).then(function () {
        C.agent.dialect = code;
        $$('[data-ag-speaks-label]').forEach(function (el) { el.textContent = label; });
        if (gallery) gallery.setDialect(code);
        toast(T.saved);
      }).catch(function (err) {
        if (was) speaks.value = was;
        toast(err.message);
      }).then(function () { speaks.disabled = false; });
    });

    $$('[data-ag-status-set]').forEach(function (b) {
      b.addEventListener('click', function () {
        b.disabled = true;
        api('PATCH', C.agent.urls.update, { status: b.getAttribute('data-ag-status-set') }).then(function () { location.reload(); })
          .catch(function (err) {
            b.disabled = false;
            // Why it cannot go live stays on screen (the plan, the check, a suspension), with the way forward.
            var d = err.data || {};
            if (d.reason && d.reason !== 'voice') notice(err.message, d.upgrade && !d.upgrade.none ? d.upgrade : null); else toast(err.message);
          });
      });
    });

    $$('[data-ag-list-add]').forEach(function (b) {
      b.addEventListener('click', function () {
        var box = $('[data-ag-list="' + b.getAttribute('data-ag-list-add') + '"]');
        var row = box.firstElementChild.cloneNode(true);
        var field = $('input, textarea', row);
        field.value = '';
        box.appendChild(row);
        grow(field);
        field.focus();
      });
    });
    document.addEventListener('input', function (e) { if (e.target.classList && e.target.classList.contains('ag-grow')) grow(e.target); });
    document.addEventListener('click', function (e) {
      var rm = e.target.closest('[data-ag-list-remove]');
      if (!rm) return;
      var box = rm.closest('[data-ag-list]');
      if (box.children.length > 1) rm.parentElement.remove(); else $('input, textarea', rm.parentElement).value = '';
    });
    var max = $('[data-ag-max]'), maxOut = $('[data-ag-max-out]');
    var maxLabels = {};
    try { maxLabels = JSON.parse(max.getAttribute('data-labels') || '{}'); } catch (e) {}
    if (max) max.addEventListener('input', function () { maxOut.textContent = maxLabels[max.value] || max.value; });
    // Calls a day: steps of minutes (0: no limit), shown in words.
    var daily = $('[data-ag-daily]'), dailyOut = $('[data-ag-daily-out]'), dailySteps = [], dailyLabels = [];
    if (daily) {
      try { dailySteps = JSON.parse(daily.getAttribute('data-steps') || '[]'); dailyLabels = JSON.parse(daily.getAttribute('data-labels') || '[]'); } catch (e) {}
      daily.addEventListener('input', function () { dailyOut.textContent = dailyLabels[daily.value] || ''; });
    }

    if (sf) sf.addEventListener('submit', function (e) {
      e.preventDefault();
      var list = function (k) { return $$('[data-ag-list="' + k + '"] input, [data-ag-list="' + k + '"] textarea', sf).map(function (i) { return i.value.trim(); }).filter(Boolean); };
      var nameInput = $('[name="name"]', sf);
      var newName = nameInput ? nameInput.value.trim() : '';
      api('PATCH', C.agent.urls.update, {
        name: newName || undefined,
        first_message: $('[name="first_message"]', sf).value,
        script: { identity: $('[name="identity"]', sf).value, handoff: $('[name="handoff"]', sf).value, never: list('never'), steps: list('steps') },
        max_call_seconds: parseInt(max.value, 10) * 60,
        daily_minutes: daily ? (dailySteps[parseInt(daily.value, 10)] || 0) : undefined,
        choices: $$('[data-ag-choice]', sf).length ? $$('[data-ag-choice]', sf).map(function (f) {
          var picked = $('input:checked', f);
          return { question: f.getAttribute('data-question'), answer: picked ? picked.value : '' };
        }).filter(function (c) { return c.answer; }) : undefined,
      }).then(function (res) {
        if (res && res.agent && res.agent.name) showName(res.agent.name);
        toast(T.saved);
      }).catch(function (err) { toast(err.message); });
    });
    knowledgeFiles($('[data-ag-files]'));
    var kf = $('[data-ag-knowledge-form]');
    var kbText = $('[data-ag-kb-text]'), kbCount = $('[data-ag-kb-count]');
    if (kbText && kbCount) {
      var nf = new Intl.NumberFormat('en'); // the site writes numbers in Latin digits in both languages
      kbText.addEventListener('input', function () { kbCount.textContent = nf.format(kbText.value.length) + ' / ' + nf.format(40000); });
    }
    var uf = $('[data-ag-updates-form]');
    if (uf) uf.addEventListener('submit', function (e) {
      e.preventDefault();
      api('PATCH', C.agent.urls.update, { updates: $('[name="updates"]', uf).value }).then(function (res) {
        var at = $('[data-ag-updates-at]', uf);
        if (at && res && res.agent) at.textContent = res.agent.updates_at || '';
        toast(T.saved);
      }).catch(function (err) { toast(err.message); });
    });
    socialLinks($('[data-ag-links]'));
    yourSite($('[data-ag-site]'));
    if (kf) kf.addEventListener('submit', function (e) {
      e.preventDefault();
      api('PATCH', C.agent.urls.update, { knowledge: $('[name="knowledge"]', kf).value }).then(function () { toast(T.saved); }).catch(function (err) { toast(err.message); });
    });
  }

  /*
   * The Design tab: each choice redraws the preview at once (the real button,
   * drawn on a pretend copy of the website) and is saved a moment later.
   */
  /*
   * Knowledge → Social media links: a row each, like the allowed websites. Adding or removing one
   * saves the list (Listen keeps only accounts and the business's own pages, written one way), and
   * "Find them on my website" reads the website again for them. An agent made before its links were
   * read has its website searched once, on its own.
   */
  function yourSite(box) {
    if (!box || !C.agent || !C.agent.urls || !C.agent.urls.site) return;
    var text = $('[data-ag-site-text]', box), again = $('[data-ag-site-again]', box), timer = 0, polls = 0;
    function draw(site) {
      if (!site) return;
      box.setAttribute('data-state', site.state);
      text.className = 'ag-link-read is-' + site.state;
      text.textContent = site.text || '';
      again.disabled = site.state === 'reading';
      watch();
    }
    // Read in the background: the card checks back until the pages are read.
    function watch() {
      clearTimeout(timer);
      var state = box.getAttribute('data-state');
      if ((state !== 'reading' && state !== 'none') || polls >= 60) return;
      timer = setTimeout(function () {
        api('GET', C.agent.urls.site).then(function (res) { draw(res && res.site); }).catch(function () {});
      }, polls++ < 12 ? 5000 : 15000);
    }
    again.addEventListener('click', function () {
      again.disabled = true;
      polls = 0;
      // Safe to send twice: a reading already under way is not started again.
      api('POST', C.agent.urls.site, null, 1).then(function (res) {
        draw(res && res.site);
        toast(T.site_again_done || '');
      }, function (e) {
        again.disabled = false;
        // Said on the card itself, not only in a toast that is gone in two seconds.
        text.className = 'ag-link-read is-failed';
        text.textContent = (e && e.message && e.message !== 'error' ? e.message : '') || T.site_again_error || '';
        toast(T.site_again_error || '');
      });
    });
    watch();
  }

  function socialLinks(box) {
    if (!box || !C.agent || !C.agent.urls) return;
    var list = $('[data-ag-links-list]', box), form = $('[data-ag-links-add]', box), input = $('[data-ag-link-input]', box);
    var err = $('[data-ag-links-error]', box), note = $('[data-ag-links-note]', box), find = $('[data-ag-links-find]', box);
    var max = +box.getAttribute('data-max') || 10;
    var pollTimer = 0, polls = 0;
    function urls() { return $$('li', list).map(function (li) { return li.getAttribute('data-url'); }); }
    // New links are read in the background: the list checks back until each says what the agent read.
    function watch() {
      clearTimeout(pollTimer);
      if (!$('li[data-state="reading"]', list) || !C.agent.urls.link_reads || polls >= 40) { polls = 0; return; }
      pollTimer = setTimeout(function () {
        api('GET', C.agent.urls.link_reads).then(function (res) { if (res && res.links) draw(res.links); }).catch(function () {});
      }, polls++ < 10 ? 2500 : 6000);
    }
    function sync() {
      var n = urls().length, count = $('[data-ag-links-count]', box);
      $('[data-ag-links-empty]', box).hidden = n > 0;
      form.hidden = n >= max;
      if (count) count.textContent = (count.getAttribute('data-pattern') || ':count / :max').replace(':count', n).replace(':max', max);
    }
    function row(l) {
      var li = document.createElement('li');
      li.setAttribute('data-url', l.url);
      li.innerHTML = '<span class="ag-site-mark" aria-hidden="true"></span><span class="ag-site-name"><bdi dir="ltr"><a target="_blank" rel="noopener noreferrer nofollow"></a></bdi></span>'
        + '<button type="button" class="ag-sites-remove" data-ag-link-remove></button>';
      $('.ag-site-mark', li).textContent = String(l.label || '').charAt(0).toUpperCase();
      var a = $('a', li);
      a.href = l.url;
      a.textContent = l.label || l.url;
      if (l.network === 'website') {
        var mine = document.createElement('small');
        mine.textContent = T.links_your_site || '';
        $('.ag-site-name', li).appendChild(mine);
      }
      // What the agent read from it; a page of another website is only read, never written in the chat.
      li.setAttribute('data-state', l.read ? l.read.state : '');
      if (l.read || l.shown === false) {
        var read = document.createElement('small');
        read.className = 'ag-link-read is-' + (l.read ? l.read.state : 'none');
        read.setAttribute('data-ag-link-read', '');
        if (l.shown === false) {
          var tag = document.createElement('i');
          tag.className = 'ag-link-tag';
          tag.title = T.links_read_only_hint || '';
          tag.textContent = T.links_read_only || '';
          read.appendChild(tag);
        }
        read.appendChild(document.createTextNode(l.read ? l.read.text : ''));
        $('.ag-site-name', li).appendChild(read);
      }
      var rm = $('button', li);
      rm.textContent = T.site_remove_short || 'Remove';
      rm.setAttribute('aria-label', fill(T.links_remove, { link: l.label || l.url }));
      return li;
    }
    function draw(links) {
      var before = urls();
      list.innerHTML = '';
      (links || []).forEach(function (l) {
        var li = row(l);
        if (before.indexOf(l.url) < 0) li.classList.add('is-new');
        list.appendChild(li);
      });
      sync();
      watch();
    }
    function save(next, undo) {
      err.hidden = true;
      return api('PATCH', C.agent.urls.update, { links: next }).then(function (res) {
        draw(res && res.agent ? res.agent.links : []);
        toast(T.links_saved || T.saved);
        return true;
      }).catch(function (e) {
        if (undo) undo();
        err.textContent = e.message || '';
        err.hidden = !e.message;
        sync();
        return false;
      });
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var value = input.value.trim();
      if (!value) { input.focus(); return; }
      save(urls().concat([value])).then(function (ok) { if (ok) { input.value = ''; input.focus(); } });
    });
    input.addEventListener('input', function () { err.hidden = true; });
    list.addEventListener('click', function (e) {
      var b = e.target.closest('[data-ag-link-remove]');
      if (!b) return;
      var li = b.closest('li'), next = li.nextElementSibling;
      li.remove();
      sync();
      save(urls(), function () { list.insertBefore(li, next); });
      input.focus();
    });
    function search(quiet) {
      if (find) find.disabled = true;
      if (note) note.textContent = T.links_finding || '';
      // Safe to send twice: the website is read again and the same accounts are found.
      api('POST', C.agent.urls.links, { auto: !!quiet }, 1).then(function (res) {
        draw(res.links);
        if (note) note.textContent = quiet && !res.added ? '' : (res.message || '');
      }, function (e) {
        if (note) note.textContent = quiet ? '' : (e.message || '');
      }).then(function () { if (find) find.disabled = false; });
    }
    if (find) find.addEventListener('click', function () { search(false); });
    if (C.agent.links_looked === false && C.agent.has_website && C.agent.urls.links) search(true);
    else watch();
  }

  /*
   * Knowledge files: drop or choose files, each uploads with its progress, is read in the
   * background (the list checks back until it is done) and shows what the agent learned.
   * Removing takes a second tap. Every name is written as text, never as HTML.
   */
  function knowledgeFiles(box) {
    if (!box) return;
    var F = T.files || {};
    var list = $('[data-ag-files-list]', box), input = $('[data-ag-file-input]', box), drop = $('[data-ag-drop]', box), meter = $('[data-ag-files-used]', box);
    var nf = new Intl.NumberFormat('en');
    var state = { files: [], used: 0, budget: 0 }, uploads = [], open = {}, pollTimer = 0, polls = 0;
    // Each kind of file has its own drawing and colour, so the badge needs no letters in any language.
    var TYPE = { pdf: 'pdf', docx: 'doc', xlsx: 'sheet', csv: 'sheet', txt: 'text', md: 'text', jpg: 'image', jpeg: 'image', png: 'image', webp: 'image' };
    var ICON = {
      pdf: '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/><rect x="9.5" y="12.5" width="6" height="4.5" rx="1"/>',
      doc: '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4M9.5 11.5h6M9.5 14.5h6M9.5 17.5h4"/>',
      sheet: '<rect x="4.5" y="5" width="15" height="14" rx="2"/><path d="M4.5 10h15M4.5 14.5h15M10 5v14"/>',
      text: '<path d="M5 6.5h14M5 10.5h14M5 14.5h10M5 18.5h7"/>',
      image: '<rect x="4" y="5" width="16" height="14" rx="2.5"/><circle cx="9" cy="10" r="1.6"/><path d="M5 17l4.5-4.5 3 3 2.5-2.5 4 4"/>'
    };
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
    function url(id, tail) { return box.getAttribute('data-file-url').replace('__ID__', id) + (tail || ''); }
    function size(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' ' + (F.mb || 'MB') : Math.max(1, Math.round(n / 1024)) + ' ' + (F.kb || 'KB'); }
    function badge(kind) {
      var b = el('span', 'ag-file-type is-' + kind);
      b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + ICON[kind] + '</svg>';
      b.appendChild(el('span', 'sr-only', (F.kinds || {})[kind] || ''));
      return b;
    }
    function ext(name) { var m = /\.([a-z0-9]+)$/i.exec(name || ''); return m ? m[1].toLowerCase() : ''; }
    function button(cls, label, onClick) { var b = el('button', 'ag-btn ag-btn-sm ' + cls, label); b.type = 'button'; b.addEventListener('click', onClick); return b; }

    function row(f) {
      var li = el('li', 'ag-file is-' + f.status);
      li.appendChild(badge(TYPE[f.type] || 'text'));
      var main = el('div', 'ag-file-main');
      // The name as the owner gave it (the badge draws the kind; the full name on hover too).
      var name = el('b', 'ag-file-name', String(f.name || '')); name.dir = 'auto'; name.title = f.name || ''; main.appendChild(name);
      var meta = el('span', 'ag-file-meta');
      if (f.size) meta.appendChild(el('span', null, size(f.size)));
      var status;
      if (f.status === 'uploading') status = el('span', 'ag-file-state is-busy', F.uploading);
      else if (f.status === 'pending' || f.status === 'reading') status = el('span', 'ag-file-state is-busy', F.reading);
      else if (f.status === 'ready') status = el('span', 'ag-file-state is-ok', (F.ready || '').replace(':count', nf.format(f.chars || 0)));
      else status = el('span', 'ag-file-state is-bad', F.failed);
      meta.appendChild(status);
      main.appendChild(meta);
      if (f.status === 'uploading') {
        var bar = el('i', 'ag-file-progress'); var fill = el('b'); fill.style.width = Math.round((f.progress || 0) * 100) + '%'; bar.appendChild(fill); main.appendChild(bar);
      }
      if (f.partial) main.appendChild(el('p', 'ag-file-note is-warn', F.partial));
      if (f.waiting) main.appendChild(el('p', 'ag-file-note is-warn', F.waiting));
      if (f.status === 'failed' && f.reason) main.appendChild(el('p', 'ag-file-note is-bad', f.reason));
      if (open[f.id] != null) {
        var facts = el('pre', 'ag-file-facts', open[f.id]); facts.dir = 'auto'; main.appendChild(facts);
      }
      li.appendChild(main);
      var actions = el('div', 'ag-file-actions');
      if (f.status === 'ready') actions.appendChild(button('ag-btn-ghost', open[f.id] != null ? F.hide : F.view, function () { view(f); }));
      if (f.status === 'failed' && f.id) actions.appendChild(button('', F.retry, function () { retry(f); }));
      if (f.id || f.status === 'failed') {
        var rm = button('ag-btn-ghost ag-file-remove', F.remove, function () { remove(f, rm); });
        actions.appendChild(rm);
      }
      li.appendChild(actions);
      return li;
    }

    function render() {
      list.textContent = '';
      uploads.forEach(function (u) { list.appendChild(row(u)); });
      state.files.forEach(function (f) { list.appendChild(row(f)); });
      if (meter) {
        meter.hidden = !state.files.length;
        $('span', meter).textContent = (F.used || '').replace(':used', nf.format(state.used)).replace(':budget', nf.format(state.budget));
        $('i b', meter).style.width = Math.min(100, state.budget ? state.used / state.budget * 100 : 0) + '%';
      }
      var busy = state.files.some(function (f) { return f.status === 'pending' || f.status === 'reading'; });
      clearTimeout(pollTimer);
      // Reading takes a few seconds to a minute; the list checks back, slower as it goes.
      if (busy && polls < 90) pollTimer = setTimeout(load, polls++ < 10 ? 2000 : 5000);
      else if (!busy) polls = 0;
    }
    function take(d) { if (d && d.files) { state = d; render(); } }
    function load() { api('GET', box.getAttribute('data-index-url')).then(take).catch(function () {}); }

    function send(file) {
      var u = { name: file.name, size: file.size, type: ext(file.name), status: 'uploading', progress: 0 };
      uploads.push(u);
      render();
      return new Promise(function (done) {
        var form = new FormData();
        form.append('file', file);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', box.getAttribute('data-store-url'));
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', C.csrf || '');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.onprogress = function (e) { if (e.lengthComputable) { u.progress = e.loaded / e.total; render(); } };
        xhr.onload = function () {
          var d = {};
          try { d = JSON.parse(xhr.responseText || '{}'); } catch (e) {}
          if (xhr.status === 201) {
            uploads.splice(uploads.indexOf(u), 1);
            take(d);
          } else {
            u.status = 'failed';
            u.reason = (d.errors && d.errors.file && d.errors.file[0]) || d.message || F.failed;
            render();
          }
          done();
        };
        xhr.onerror = function () { u.status = 'failed'; u.reason = F.failed; render(); done(); };
        xhr.send(form);
      });
    }
    function sendAll(files) {
      var queue = Array.prototype.slice.call(files || []);
      var next = function () { if (queue.length) send(queue.shift()).then(next); };
      next();
    }

    function view(f) {
      if (open[f.id] != null) { delete open[f.id]; render(); return; }
      api('GET', url(f.id)).then(function (d) { open[f.id] = d.content || ''; render(); }).catch(function (err) { toast(err.message); });
    }
    function retry(f) { api('POST', url(f.id, '/retry')).then(take).catch(function (err) { toast(err.message); }); }
    function remove(f, b) {
      // A file that never uploaded just leaves the list.
      if (!f.id) { uploads.splice(uploads.indexOf(f), 1); render(); return; }
      if (!b.classList.contains('is-confirm')) {
        b.classList.add('is-confirm');
        b.textContent = F.remove_confirm;
        setTimeout(function () { if (b.isConnected) { b.classList.remove('is-confirm'); b.textContent = F.remove; } }, 3500);
        return;
      }
      api('DELETE', url(f.id)).then(function (d) { delete open[f.id]; take(d); toast(F.removed); }).catch(function (err) { toast(err.message); });
    }

    if (input) input.addEventListener('change', function () { sendAll(input.files); input.value = ''; });
    if (drop) {
      ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
      ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
      drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) sendAll(e.dataTransfer.files); });
    }
    load();
  }

  function design() {
    var box = $('[data-ag-design]'), D = C.design;
    if (!box || !D || !window.ListenAgentWidget || !window.ListenAgentWidget.preview) return;
    var site = $('[data-ag-design-site]', box), status = $('[data-ag-design-status]', box), play = $('[data-ag-design-play]', box);
    var look = JSON.parse(JSON.stringify(D.look)), widget = null, saveTimer = 0, playing = false;
    var val = function (name) { var i = $('input[name="' + name + '"]:checked', box); return i ? i.value : null; };

    function colors() {
      if (look.palette === 'custom' && look.orb_colors) {
        var a = look.orb_colors[0], b = look.orb_colors[1];
        return [a, mix(a, b, 0.5), b, mix(b, '#ffffff', 0.75)];
      }
      return D.palettes[look.palette] || D.palettes.listen;
    }
    function mix(a, b, t) {
      var p = function (h) { var n = parseInt(h.slice(1), 16); return [(n >> 16) & 255, (n >> 8) & 255, n & 255]; };
      var x = p(a), y = p(b);
      return '#' + x.map(function (v, i) { return ('0' + Math.round(v + (y[i] - v) * t).toString(16)).slice(-2); }).join('');
    }
    function cfg() {
      var c = JSON.parse(JSON.stringify(D.cfg));
      c.look = look;
      c.colors = colors();
      if (look.label) c.text.label = look.label;
      if (look.title) c.text.title = look.title;
      if (look.subtitle) c.text.subtitle = look.subtitle;
      if (look.ready_text) c.text.states = Object.assign({}, c.text.states, { idle: look.ready_text });
      return c;
    }
    function draw(rebuild) {
      var dark = val('site') === 'dark';
      site.classList.toggle('is-dark', dark);
      if (widget && !rebuild) { widget.update(cfg()); return; }
      if (widget) widget.destroy();
      widget = window.ListenAgentWidget.preview(site, cfg(), { rtl: D.rtl, dark: dark, origin: location.origin, siteFont: getComputedStyle(document.body).fontFamily });
      if (look.style !== 'bar') widget.open();
      setPlaying(false);
    }
    function setPlaying(on) {
      playing = on;
      $('span', play).textContent = on ? play.getAttribute('data-stop') : play.getAttribute('data-play');
    }
    function read() {
      look.style = val('style') || look.style;
      look.icon = val('icon') || look.icon;
      look.shape = val('shape') || look.shape;
      look.size = val('size') || look.size;
      var accent = val('accent');
      look.accent = accent === 'custom' ? $('[data-ag-design-color="accent"]', box).value.toUpperCase() : (accent || look.accent);
      look.palette = val('palette') || look.palette;
      look.orb_colors = look.palette === 'custom' ? [$('[data-ag-design-color="orb0"]', box).value.toUpperCase(), $('[data-ag-design-color="orb1"]', box).value.toUpperCase()] : null;
      look.label = $('[name="label"]', box).value.trim() || null;
      look.title = $('[name="title"]', box).value.trim() || null;
      look.subtitle = $('[name="subtitle"]', box).value.trim() || null;
      look.captions = $('[name="captions"]', box).checked;
      look.ready_text = $('[name="ready_text"]', box).value.trim() || null;
      look.ready_show = $('[name="ready_show"]', box).checked;
      look.photo = $('[name="photo"]', box).checked;
      look.radius = val('radius') || look.radius;
      look.font = val('font') || look.font;
      look.theme = val('theme') || look.theme;
      // The corner the owner sees: on a right-to-left website the start is the right.
      var corner = val('corner');
      if (corner) look.position = (corner === 'right') === !!D.rtl ? 'start' : 'end';
      // The custom swatches show the chosen colours once picked, a rainbow before.
      $('.ag-swatch-custom > span', box).style.background = accent === 'custom' ? look.accent : '';
      var two = $('.ag-swatch-two > span', box);
      two.style.setProperty('--c-a', $('[data-ag-design-color="orb0"]', box).value);
      two.style.setProperty('--c-b', $('[data-ag-design-color="orb1"]', box).value);
    }
    function save() {
      clearTimeout(saveTimer);
      status.textContent = (D.text || {}).saving || '';
      saveTimer = setTimeout(function () {
        var body = {};
        ['position', 'style', 'theme', 'label', 'icon', 'size', 'shape', 'accent', 'palette', 'radius', 'captions', 'photo', 'title', 'subtitle', 'font', 'ready_text', 'ready_show'].forEach(function (k) { body[k] = look[k] === null ? '' : look[k]; });
        if (look.palette === 'custom') body.orb_colors = look.orb_colors;
        api('PATCH', C.agent.urls.update, { appearance: body }).then(function () {
          status.textContent = (D.text || {}).saved || '';
        }, function () { status.textContent = (D.text || {}).failed || ''; });
      }, 600);
    }
    var styleBefore = look.style;
    function pickCustom(e) {
      if (!e.target.matches('[data-ag-design-color]')) return;
      // Picking a colour picks "your colour" too, while the picker is still open.
      var which = e.target.getAttribute('data-ag-design-color');
      var radio = $('input[name="' + (which === 'accent' ? 'accent' : 'palette') + '"][value="custom"]', box);
      if (radio) radio.checked = true;
    }
    function changed() {
      read();
      var restyled = look.style !== styleBefore;
      draw(restyled);
      styleBefore = look.style;
      // The slim bar only shows during a call: picking it plays a pretend call, so it is seen.
      if (restyled && look.style === 'bar' && widget) { widget.demo(true); setPlaying(true); stopLater(); }
      save();
    }
    var stopTimer = 0;
    function stopLater() { clearTimeout(stopTimer); stopTimer = setTimeout(function () { if (playing) setPlaying(false); }, 14000); }
    box.addEventListener('input', function (e) {
      if (e.target.name === 'site') return;
      pickCustom(e);
      changed();
    });
    box.addEventListener('change', function (e) {
      if (e.target.name === 'site') { draw(true); return; }
      pickCustom(e);
      changed();
    });
    var ready = $('[name="ready_text"]', box);
    if (ready && !ready.placeholder) ready.placeholder = ((D.cfg && D.cfg.text && D.cfg.text.states) || {}).idle || '';
    $('[data-ag-design-reset]', box).addEventListener('click', function () {
      var d = D.defaults;
      ['style', 'icon', 'shape', 'size', 'radius', 'font', 'theme', 'palette'].forEach(function (k) {
        var r = $('input[name="' + k + '"][value="' + d[k] + '"]', box);
        if (r) r.checked = true;
      });
      var acc = $('input[name="accent"][value="' + d.accent + '"]', box);
      if (acc) acc.checked = true;
      var corner = $('input[name="corner"][value="' + ((d.position === 'start') === !!D.rtl ? 'right' : 'left') + '"]', box);
      if (corner) corner.checked = true;
      ['label', 'title', 'subtitle', 'ready_text'].forEach(function (k) { $('[name="' + k + '"]', box).value = ''; });
      $('[name="captions"]', box).checked = true;
      $('[name="ready_show"]', box).checked = true;
      $('[name="photo"]', box).checked = true;
      read();
      draw(true);
      styleBefore = look.style;
      save();
    });
    play.addEventListener('click', function () {
      if (!widget) return;
      widget.demo(!playing);
      setPlaying(!playing);
      if (playing) stopLater();
    });
    read();
    draw(true);
  }

  /* ---------- The demo page's Voice agent tab (App\Support\Agents\DemoAgent) ---------- */
  // Three sample businesses: the one picked sets the agent's name, voice and lines; a call comes
  // out of the member's free test seconds, and the time left is read again after each call.
  function demo() {
    var box = $('[data-demo-agent]');
    if (!box) return;
    var picks = $$('input[name="demo-agent-preset"]', box);
    var biz = $('[data-demo-biz]', box), tryList = $('[data-demo-try]', box);
    var left = $('[data-demo-left]', box), leftText = $('[data-demo-left-text]', box);
    var used = $('[data-demo-used]', box), plans = $('[data-demo-plans]', box), callBtn = $('[data-ag-call]', box);
    var live = false, talker = null;
    function chosen() { return picks.filter(function (i) { return i.checked; })[0] || picks[0]; }
    function apply() {
      var i = chosen();
      if (!i) return;
      // Another business: nothing the last agent said stays on the page.
      if (talker) talker.reset();
      C.agent.name = i.getAttribute('data-name');
      C.agent.gender = i.getAttribute('data-gender');
      if (biz) biz.textContent = i.getAttribute('data-business');
      var lines = [];
      try { lines = JSON.parse(i.getAttribute('data-try') || '[]'); } catch (e) { lines = []; }
      if (tryList) {
        tryList.innerHTML = '';
        lines.forEach(function (t) { var li = document.createElement('li'); li.dir = 'auto'; li.textContent = t; tryList.appendChild(li); });
      }
    }
    function usedUp() {
      if (left) left.hidden = true;
      if (used) used.hidden = false;
      if (plans) plans.hidden = false;
      if (callBtn) callBtn.disabled = true;
    }
    function refresh() {
      fetch(C.agent.urls.left, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d) return;
          if (!d.can_call) { usedUp(); return; }
          if (leftText && left) leftText.textContent = fill(left.getAttribute('data-pattern'), { time: d.clock });
        })
        .catch(function () {});
    }
    C.callBody = function () { return { preset: (chosen() || {}).value }; };
    picks.forEach(function (i) { i.addEventListener('change', apply); });
    talker = talk($('[data-ag-talk]', box), {
      onState: function (s) {
        var on = s === 'connecting' || s === 'listening' || s === 'speaking';
        // While a call is on, the business stays the one being called.
        picks.forEach(function (i) { i.disabled = on; });
        if (live && !on) setTimeout(refresh, 1500);
        live = on;
      },
      onError: function (msg, err) {
        if (err && err.data && err.data.reason === 'free_used') { usedUp(); return false; }
        return true;
      },
    });
    if (used && !used.hidden) usedUp();
    apply();
  }

  function boot() {
    if (C.mode === 'setup') setup();
    else if (C.mode === 'agent') { agentPage(); design(); }
    else if (C.mode === 'link') talk($('[data-ag-talk]'));
    else if (C.mode === 'demo') demo();
    drawQr();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
