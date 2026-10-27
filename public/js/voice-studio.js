/*
 * Voice studio panel (components/voice-studio/panel.blade.php), shared by the
 * dashboard generator and the demo page:
 *  - the voice library: every speaker as a card with avatar, gender, accent,
 *    a preview and favourites (kept on the account and in the browser);
 *  - tags inserted at the cursor from the toolbar above the text (sounds,
 *    emotions, voice and pace) and highlighted inside the text box;
 *  - pickers for dialect, performance style and emotion: a card showing the
 *    choice and a dialog with search, categories, recent picks and icons,
 *    kept in step with each select, which stays the form field;
 *  - every visible dropdown drawn as a styled list over its select.
 */
(function () {
  'use strict';

  // Arabic written either way: no vowel marks or tatweel, one form of alef, ya and ta marbuta.
  function fold(text) {
    return String(text || '')
      .toLowerCase()
      .replace(/[ً-ٰٟـ]/g, '')
      .replace(/[أإآٱ]/g, 'ا')
      .replace(/ى/g, 'ي')
      .replace(/ة/g, 'ه')
      .replace(/ؤ/g, 'و')
      .replace(/ئ/g, 'ي')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function esc(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }
  // For text placed in markup the studio builds, attributes included.
  function escAttr(text) {
    return esc(String(text == null ? '' : text)).replace(/"/g, '&quot;');
  }

  // ---------- Dropdowns: a styled button and list over each visible select ----------
  // The select stays the form field (page code fills it, reads it and sets it);
  // the list mirrors its options, and picking one sets it and fires "change".
  var openMenu = null;
  function closeOpenMenu() { if (openMenu) openMenu(false); }
  document.addEventListener('click', function (e) {
    if (openMenu && !(e.target.closest && e.target.closest('.vs-select'))) closeOpenMenu();
  });

  function enhanceSelect(select) {
    if (select.__vsSelect) return;
    select.__vsSelect = true;
    var wrap = document.createElement('div');
    wrap.className = 'vs-select';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('vs-select-native');
    select.tabIndex = -1;
    // The button below stands in for it, with the field's label.
    select.setAttribute('aria-hidden', 'true');

    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'vs-select-button';
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-expanded', 'false');
    var label = document.createElement('span');
    label.className = 'vs-select-label';
    button.appendChild(label);
    button.insertAdjacentHTML('beforeend', '<svg class="vs-select-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>');
    var labelledBy = select.id && document.querySelector('label[for="' + select.id + '"]');
    if (labelledBy) {
      if (!labelledBy.id) labelledBy.id = select.id + 'Label';
      button.setAttribute('aria-labelledby', labelledBy.id + ' ' + (button.id = select.id + 'Button'));
      labelledBy.setAttribute('for', button.id);
    } else if (select.getAttribute('aria-label')) {
      button.setAttribute('aria-label', select.getAttribute('aria-label'));
    }
    wrap.appendChild(button);

    var list = document.createElement('div');
    list.className = 'vs-select-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    wrap.appendChild(list);
    var items = [];
    var active = -1;

    // Speaker menus (data-speaker-avatar) show each speaker's avatar from the option's data-avatar.
    var withAvatars = select.hasAttribute('data-speaker-avatar');
    function avatarFor(opt) {
      var box = document.createElement('span');
      box.className = 'vs-select-avatar';
      box.setAttribute('aria-hidden', 'true');
      var url = opt ? String(opt.getAttribute('data-avatar') || '') : '';
      if (url) {
        var img = document.createElement('img');
        img.src = url; img.alt = ''; img.width = 28; img.height = 28; img.loading = 'lazy'; img.decoding = 'async';
        box.appendChild(img);
      } else {
        box.textContent = opt ? String(opt.textContent || '').trim().charAt(0).toUpperCase() : '';
      }
      return box;
    }
    function syncLabel() {
      var picked = select.options[select.selectedIndex];
      label.textContent = picked ? picked.textContent : '';
      if (withAvatars && picked) label.insertBefore(avatarFor(picked), label.firstChild);
      button.disabled = select.disabled || select.options.length === 0;
      items.forEach(function (item) {
        var on = item.__opt === picked;
        item.setAttribute('aria-selected', on ? 'true' : 'false');
      });
    }
    function build() {
      list.innerHTML = '';
      items = [];
      Array.prototype.forEach.call(select.children, function (node) {
        if (node.tagName === 'OPTGROUP') {
          var head = document.createElement('div');
          head.className = 'vs-select-group';
          head.textContent = node.label;
          list.appendChild(head);
          Array.prototype.forEach.call(node.children, addItem);
        } else if (node.tagName === 'OPTION') {
          addItem(node);
        }
      });
      syncLabel();
    }
    function addItem(opt) {
      if (opt.hidden) return;
      var item = document.createElement('div');
      item.className = 'vs-select-option';
      item.setAttribute('role', 'option');
      item.__opt = opt;
      item.textContent = opt.textContent;
      if (withAvatars) item.insertBefore(avatarFor(opt), item.firstChild);
      item.insertAdjacentHTML('beforeend', '<svg class="vs-select-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>');
      if (opt.disabled) item.setAttribute('aria-disabled', 'true');
      item.addEventListener('mousemove', function () { setActive(items.indexOf(item)); });
      item.addEventListener('click', function () { choose(item); });
      list.appendChild(item);
      items.push(item);
    }
    function setActive(i) {
      if (active >= 0 && items[active]) items[active].classList.remove('is-active');
      active = i;
      if (active >= 0 && items[active]) {
        items[active].classList.add('is-active');
        var it = items[active];
        if (it.offsetTop < list.scrollTop) list.scrollTop = it.offsetTop - 6;
        else if (it.offsetTop + it.offsetHeight > list.scrollTop + list.clientHeight) list.scrollTop = it.offsetTop + it.offsetHeight - list.clientHeight + 6;
      }
    }
    function choose(item) {
      if (!item || item.getAttribute('aria-disabled') === 'true') return;
      var changed = select.value !== item.__opt.value;
      select.value = item.__opt.value;
      if (changed) select.dispatchEvent(new Event('change', { bubbles: true }));
      syncLabel();
      toggle(false);
      button.focus();
    }
    function toggle(open) {
      if (open && button.disabled) return;
      if (open) {
        closeOpenMenu();
        list.hidden = false;
        // Open upward when the list does not fit below.
        var r = button.getBoundingClientRect();
        var below = window.innerHeight - r.bottom;
        wrap.classList.toggle('is-up', below < Math.min(list.scrollHeight, 320) + 16 && r.top > below);
        setActive(items.findIndex(function (it) { return it.__opt === select.options[select.selectedIndex]; }));
        openMenu = toggle;
      } else {
        list.hidden = true;
        if (openMenu === toggle) openMenu = null;
      }
      wrap.classList.toggle('is-open', open);
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function step(from, dir) {
      for (var i = from + dir; i >= 0 && i < items.length; i += dir) {
        if (items[i].getAttribute('aria-disabled') !== 'true') return i;
      }
      return from;
    }
    button.addEventListener('click', function () { toggle(list.hidden); });
    button.addEventListener('keydown', function (e) {
      var open = !list.hidden;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!open) { toggle(true); return; }
        setActive(step(active, e.key === 'ArrowDown' ? 1 : -1));
      } else if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        if (open && items[active]) choose(items[active]); else toggle(!open);
      } else if (e.key === 'Escape' || e.key === 'Tab') {
        if (open) { if (e.key === 'Escape') e.preventDefault(); toggle(false); }
      } else if (e.key === 'Home' || e.key === 'End') {
        if (open) { e.preventDefault(); setActive(e.key === 'Home' ? step(-1, 1) : step(items.length, -1)); }
      } else if (e.key.length === 1 && open) {
        var key = fold(e.key);
        for (var n = 1; n <= items.length; n++) {
          var idx = (active + n) % items.length;
          if (fold(items[idx].textContent).indexOf(key) === 0) { setActive(idx); break; }
        }
      }
    });
    // The browser points at the select when a required one is empty.
    select.addEventListener('focus', function () { button.focus(); });
    select.addEventListener('change', syncLabel);

    if (window.MutationObserver) {
      new MutationObserver(build).observe(select, { childList: true, subtree: true, characterData: true });
      new MutationObserver(syncLabel).observe(select, { attributes: true, attributeFilter: ['disabled'] });
    }
    var lastValue = select.value;
    setInterval(function () { if (select.value !== lastValue) { lastValue = select.value; syncLabel(); } }, 300);
    build();
  }

  // ---------- Picker data (components/voice-studio/panel.blade.php) ----------
  function readMeta(panel) {
    var node = panel.querySelector('[data-vs-meta]');
    try { return JSON.parse(node ? node.textContent : '{}') || {}; } catch (e) { return {}; }
  }
  function t(meta, key, vars) {
    var text = String((meta.i18n && meta.i18n[key]) || '');
    Object.keys(vars || {}).forEach(function (k) { text = text.replace(':' + k, vars[k]); });
    return text;
  }
  function icon(id, cls) {
    return window.VoiceStudioIcons ? window.VoiceStudioIcons.svg(id, cls) : '';
  }

  // Remembered per browser, so the last few picks are one click away.
  function recentList(kind) {
    try { return JSON.parse(window.localStorage.getItem('listen.studio.recent.' + kind) || '[]') || []; } catch (e) { return []; }
  }
  function remember(kind, value) {
    if (!value) return;
    try {
      var list = recentList(kind).filter(function (v) { return v !== value; });
      list.unshift(value);
      window.localStorage.setItem('listen.studio.recent.' + kind, JSON.stringify(list.slice(0, 6)));
    } catch (e) { /* storage off: no recent list */ }
  }

  // The choices of one picker, from its select (the page fills dialects per language).
  function pickerItems(kind, select, meta) {
    var isDialect = kind === 'dialect';
    var info = (isDialect ? meta.dialects : meta[kind]) || {};
    var groupNames = (isDialect ? meta.regions : meta[kind + 'Groups']) || {};
    var firstGroup = Object.keys(groupNames)[0] || '';
    return Array.prototype.filter.call(select.options, function (opt) { return !opt.hidden; }).map(function (opt) {
      var row = info[opt.value] || {};
      var item = { value: opt.value, label: String(opt.textContent || '').trim(), desc: row.d || '', disabled: opt.disabled };
      if (!opt.value) {
        // A page may say what its default does (the dubbing studio: "as in the video").
        item.desc = opt.getAttribute('data-desc') || t(meta, isDialect ? 'auto_desc' : kind + '_default_desc') || t(meta, 'default_desc');
        item.icon = icon('g-auto');
        item.group = '';
        return item;
      }
      if (isDialect) {
        item.group = row.r || 'other';
        item.section = row.c || '';
        item.icon = row.f
          ? '<span class="vs-flag"><img src="' + escAttr(row.f) + '" alt="" width="40" height="40" loading="lazy" decoding="async"></span>'
          : '<span class="vs-flag vs-flag-letter">' + (opt.value.indexOf('en-') === 0 ? 'ع' : 'ض') + '</span>';
      } else {
        item.group = row.g && groupNames[row.g] ? row.g : firstGroup;
        item.section = item.group;
        item.icon = icon(row.i || (kind === 'emotion' ? 'f-neutral' : 'g-wave'));
      }
      item.key = fold(item.label + ' ' + item.desc + ' ' + opt.value + ' ' + (isDialect ? (meta.countries || {})[item.section] || '' : ''));
      return item;
    });
  }

  // ---------- The card that shows a choice and opens its picker ----------
  function initPickButton(button, select, meta) {
    var kind = button.dataset.vsPick;
    var iconBox = button.querySelector('[data-vs-pick-icon]');
    var valueBox = button.querySelector('[data-vs-pick-value]');
    var descBox = button.querySelector('[data-vs-pick-desc]');
    var shown = null;
    function sync() {
      var items = pickerItems(kind, select, meta);
      var current = items.filter(function (it) { return it.value === select.value; })[0] || items[0];
      button.disabled = select.disabled || items.length === 0;
      var key = (current ? current.value + '|' + current.label : '') + '|' + button.disabled;
      if (key === shown) return;
      shown = key;
      if (!current) return;
      iconBox.innerHTML = current.icon || '';
      valueBox.textContent = current.label;
      descBox.textContent = current.desc || '';
      descBox.hidden = !current.desc;
      button.classList.toggle('is-default', !current.value);
    }
    button.addEventListener('click', function () {
      openPicker(kind, select, meta, button);
    });
    // The browser points at the select when it is invalid.
    select.addEventListener('focus', function () { button.focus(); });
    select.addEventListener('change', sync);
    if (window.MutationObserver) {
      new MutationObserver(sync).observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
    }
    // Pages also set the value from code (language switch, reuse a generation).
    setInterval(sync, 300);
    sync();
  }

  // ---------- The picker: one dialog, reused ----------
  var modal = null;
  function openPicker(kind, select, meta, opener) {
    var isDialect = kind === 'dialect';
    var items = pickerItems(kind, select, meta);
    var english = isDialect && items.some(function (it) { return it.value.indexOf('en-') === 0; });
    var groupNames = (isDialect ? meta.regions : meta[kind + 'Groups']) || {};
    var sectionNames = isDialect ? (meta.countries || {}) : groupNames;
    var countries = {};
    // Fusha and "Arabic accent" are listed like countries but are not one.
    items.forEach(function (it) { if (it.value && it.section && it.section !== 'XA' && it.section !== 'AR') countries[it.section] = true; });
    var choices = items.filter(function (it) { return it.value; });
    var titleKey = isDialect ? (english ? 'accent_title' : 'dialect_title') : kind + '_title';
    var hintKey = isDialect ? (english ? 'accent_hint' : 'dialect_hint') : kind + '_hint';
    var filter = '';
    var query = '';

    if (!modal) {
      modal = document.createElement('div');
      modal.className = 'vs-modal';
      modal.hidden = true;
      modal.innerHTML = '<div class="vs-modal-backdrop" data-vs-close></div>'
        + '<div class="vs-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="vsModalTitle" aria-describedby="vsModalHint">'
        + '<header class="vs-modal-head"><span class="vs-modal-ico" aria-hidden="true"></span><div class="vs-modal-titles"><h2 id="vsModalTitle"></h2><p id="vsModalHint"></p></div>'
        + '<button type="button" class="vs-modal-close" data-vs-close><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></header>'
        + '<div class="vs-modal-tools"><label class="vs-modal-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg><input type="search" autocomplete="off" spellcheck="false"></label>'
        + '<div class="vs-filters" role="group"></div></div>'
        + '<div class="vs-modal-body" tabindex="-1"></div>'
        + '<p class="vs-modal-empty" hidden></p>'
        + '</div>';
      document.body.appendChild(modal);
    }
    var dialog = modal.querySelector('.vs-modal-dialog');
    var input = modal.querySelector('input');
    var filters = modal.querySelector('.vs-filters');
    var body = modal.querySelector('.vs-modal-body');
    var empty = modal.querySelector('.vs-modal-empty');
    modal.dataset.kind = kind;
    modal.querySelector('h2').textContent = t(meta, titleKey);
    modal.querySelector('#vsModalHint').textContent = t(meta, hintKey, { count: choices.length, countries: Object.keys(countries).length });
    modal.querySelector('.vs-modal-ico').innerHTML = icon(isDialect ? 'g-globe' : kind === 'emotion' ? 'f-smile' : kind === 'audio_effect' ? 'g-hall' : 'g-mic');
    modal.querySelector('.vs-modal-close').setAttribute('aria-label', t(meta, 'close'));
    input.placeholder = t(meta, isDialect ? (english ? 'search_accent' : 'search_dialect') : 'search');
    input.setAttribute('aria-label', input.placeholder);
    input.value = '';
    empty.textContent = t(meta, 'no_results');

    // Category filters, with how many choices each holds.
    var counts = {};
    choices.forEach(function (it) { counts[it.group] = (counts[it.group] || 0) + 1; });
    filters.setAttribute('aria-label', t(meta, 'all'));
    filters.innerHTML = '';
    var addFilter = function (key, label, count) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'vs-filter';
      b.dataset.filter = key;
      b.setAttribute('aria-pressed', key === filter ? 'true' : 'false');
      b.innerHTML = '<span>' + escAttr(label) + '</span><small>' + count + '</small>';
      b.addEventListener('click', function () {
        filter = key;
        filters.querySelectorAll('.vs-filter').forEach(function (f) { f.setAttribute('aria-pressed', f.dataset.filter === key ? 'true' : 'false'); });
        render();
        body.scrollTop = 0;
      });
      filters.appendChild(b);
    };
    addFilter('', t(meta, 'all'), choices.length);
    Object.keys(groupNames).forEach(function (key) { if (counts[key]) addFilter(key, groupNames[key], counts[key]); });

    function card(it) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'vs-card' + (it.value ? '' : ' vs-card-auto');
      b.dataset.value = it.value;
      b.setAttribute('aria-pressed', it.value === select.value ? 'true' : 'false');
      if (it.disabled) b.disabled = true;
      b.innerHTML = '<span class="vs-card-ico" aria-hidden="true">' + (it.icon || '') + '</span>'
        + '<span class="vs-card-text"><span class="vs-card-name">' + escAttr(it.label) + '</span>'
        + (it.desc ? '<span class="vs-card-desc">' + escAttr(it.desc) + '</span>' : '') + '</span>'
        + '<svg class="vs-card-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
      b.addEventListener('click', function () { choose(it.value); });
      return b;
    }
    function section(title, list, flagHtml) {
      var wrap = document.createElement('section');
      wrap.className = 'vs-modal-section';
      var head = document.createElement('h3');
      head.innerHTML = (flagHtml || '') + '<span>' + escAttr(title) + '</span><small>' + list.length + '</small>';
      var grid = document.createElement('div');
      grid.className = 'vs-cards';
      list.forEach(function (it) { grid.appendChild(card(it)); });
      wrap.appendChild(head);
      wrap.appendChild(grid);
      body.appendChild(wrap);
    }
    function render() {
      body.innerHTML = '';
      var q = fold(query);
      var shown = choices.filter(function (it) {
        return (!filter || it.group === filter) && (!q || it.key.indexOf(q) !== -1);
      });
      if (!q && !filter) {
        // The automatic / default choice, then what was picked lately.
        var top = items.filter(function (it) { return !it.value; });
        var recent = recentList(kind).map(function (v) {
          return choices.filter(function (it) { return it.value === v; })[0];
        }).filter(Boolean);
        if (top.length || recent.length) {
          var grid = document.createElement('div');
          grid.className = 'vs-cards vs-cards-top';
          top.forEach(function (it) { grid.appendChild(card(it)); });
          body.appendChild(grid);
          if (recent.length) section(t(meta, 'recent'), recent);
        }
      }
      var order = [];
      var bySection = {};
      shown.forEach(function (it) {
        var key = it.section || '';
        if (!bySection[key]) { bySection[key] = []; order.push(key); }
        bySection[key].push(it);
      });
      // Countries and categories in their own order, not the catalog's.
      var rank = Object.keys(sectionNames);
      order.sort(function (a, b) {
        var ra = rank.indexOf(a); var rb = rank.indexOf(b);
        return (ra === -1 ? 999 : ra) - (rb === -1 ? 999 : rb);
      });
      order.forEach(function (key) {
        var first = bySection[key][0];
        var flagHtml = isDialect && first.icon ? first.icon.replace('vs-flag', 'vs-flag vs-flag-sm') : '';
        section(sectionNames[key] || key || '', bySection[key], flagHtml);
      });
      empty.hidden = shown.length > 0 || (!q && !filter);
    }
    function choose(value) {
      var changed = select.value !== value;
      select.value = value;
      if (changed) select.dispatchEvent(new Event('change', { bubbles: true }));
      remember(kind, value);
      close();
    }

    var lastFocus = opener || document.activeElement;
    function close() {
      modal.hidden = true;
      document.documentElement.classList.remove('vs-modal-open');
      document.removeEventListener('keydown', onKey, true);
      modal.removeEventListener('click', onClick);
      input.removeEventListener('input', onInput);
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    function onClick(e) {
      if (e.target.closest && e.target.closest('[data-vs-close]')) close();
    }
    function onInput() { query = input.value; render(); }
    function cards() { return Array.prototype.slice.call(body.querySelectorAll('.vs-card:not([disabled])')); }
    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); if (input.value) { input.value = ''; onInput(); } else close(); return; }
      if (e.key === 'Tab') {
        // Keep focus inside the dialog.
        var focusables = Array.prototype.slice.call(dialog.querySelectorAll('button:not([disabled]), input'));
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        return;
      }
      var list = cards();
      var at = list.indexOf(document.activeElement);
      if (e.key === 'ArrowDown' && document.activeElement === input && list.length) { e.preventDefault(); list[0].focus(); return; }
      if (at === -1 || ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].indexOf(e.key) === -1) return;
      e.preventDefault();
      var rtl = getComputedStyle(dialog).direction === 'rtl';
      var next = at;
      if (e.key === 'Home') next = 0;
      else if (e.key === 'End') next = list.length - 1;
      else if (e.key === 'ArrowRight') next = at + (rtl ? -1 : 1);
      else if (e.key === 'ArrowLeft') next = at + (rtl ? 1 : -1);
      else {
        // Up and down: the nearest card in the row above or below.
        var here = list[at].getBoundingClientRect();
        var down = e.key === 'ArrowDown';
        var best = -1;
        var bestScore = Infinity;
        list.forEach(function (c, i) {
          var r = c.getBoundingClientRect();
          var dy = down ? r.top - here.top : here.top - r.top;
          if (dy < 4) return;
          var score = dy * 1000 + Math.abs(r.left - here.left);
          if (score < bestScore) { bestScore = score; best = i; }
        });
        if (best === -1) { if (!down) input.focus(); return; }
        next = best;
      }
      next = Math.max(0, Math.min(list.length - 1, next));
      list[next].focus();
      list[next].scrollIntoView({ block: 'nearest' });
    }

    render();
    modal.hidden = false;
    body.scrollTop = 0;
    document.documentElement.classList.add('vs-modal-open');
    document.addEventListener('keydown', onKey, true);
    modal.addEventListener('click', onClick);
    input.addEventListener('input', onInput);
    var picked = body.querySelector('.vs-card[aria-pressed="true"]');
    // On phones, opening the keyboard would hide the list: start on the current choice.
    var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    if (touch) { (picked || body).focus({ preventScroll: true }); }
    else { input.focus({ preventScroll: true }); }
    if (picked && picked.scrollIntoView && !picked.closest('.vs-cards-top')) {
      // Show the current choice in its country or category.
      var inSection = body.querySelectorAll('.vs-modal-section .vs-card[aria-pressed="true"]');
      var target = inSection[inSection.length - 1] || picked;
      target.scrollIntoView({ block: 'center' });
    }
  }

  // ---------- Toolbar above the text: tags by what they do ----------
  // Built from the panel's tag chips, so the two always offer the same tags.
  function buildToolbar(form, meta, insertTag) {
    var editor = form.querySelector('[data-vs-editor]');
    var tags = meta.tagMenus || [];
    if (!editor || !tags.length || !editor.parentNode) return null;
    var menus = [
      { key: 'reactions', icon: 'f-laugh', rows: [] },
      { key: 'emotions', icon: 'f-starstruck', rows: [] },
      { key: 'voice', icon: 'g-volume', rows: [] }
    ];
    tags.forEach(function (tag) {
      var row = {
        kind: tag.k,
        label: tag.l,
        key: tag.t,
        group: tag.g,
        groupLabel: tag.gl || '',
        search: fold(tag.l + ' ' + tag.t + ' ' + (tag.o || ''))
      };
      var menu = row.kind === 'delivery' ? (row.group === 'mood' ? menus[1] : menus[2]) : (row.group === 'pauses' ? menus[2] : menus[0]);
      menu.rows.push(row);
    });

    var bar = document.createElement('div');
    bar.className = 'vs-toolbar';
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', t(meta, 'toolbar_label'));
    var openOne = null;
    function closeAll() { if (openOne) openOne(false); }
    document.addEventListener('click', function (e) { if (openOne && !(e.target.closest && e.target.closest('.vs-tb-item'))) closeAll(); });

    menus.forEach(function (menu) {
      if (!menu.rows.length) return;
      var item = document.createElement('div');
      item.className = 'vs-tb-item';
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'vs-tb-btn';
      button.setAttribute('aria-haspopup', 'true');
      button.setAttribute('aria-expanded', 'false');
      button.innerHTML = icon(menu.icon) + '<span>' + escAttr(t(meta, 'toolbar_' + menu.key)) + '</span>'
        + '<svg class="vs-tb-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5"/></svg>';
      var pop = document.createElement('div');
      pop.className = 'vs-tb-pop';
      pop.hidden = true;
      pop.innerHTML = '<p class="vs-tb-hint">' + escAttr(t(meta, 'toolbar_' + menu.key + '_hint')) + '</p>'
        + '<label class="vs-tb-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>'
        + '<input type="search" autocomplete="off" placeholder="' + escAttr(t(meta, 'search_tags')) + '" aria-label="' + escAttr(t(meta, 'search_tags')) + '"></label>';
      var list = document.createElement('div');
      list.className = 'vs-tb-list';
      var lastGroup = null;
      menu.rows.forEach(function (row) {
        if (row.group !== lastGroup && row.groupLabel) {
          var head = document.createElement('div');
          head.className = 'vs-tb-group';
          head.dataset.group = row.group;
          head.textContent = row.groupLabel;
          list.appendChild(head);
          lastGroup = row.group;
        }
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'vs-tb-row';
        b.dataset.group = row.group;
        b.__search = row.search;
        var written = row.kind === 'delivery' ? '[' + row.label + ']' : '<' + row.label + '>';
        b.innerHTML = icon((meta.tags && meta.tags[row.key]) || (row.kind === 'delivery' ? 'f-neutral' : 'g-wave'))
          + '<span class="vs-tb-name">' + escAttr(row.label) + '</span><code dir="auto">' + escAttr(written) + '</code>';
        b.addEventListener('click', function () {
          toggle(false);
          insertTag(row.kind, row.label);
        });
        list.appendChild(b);
      });
      var none = document.createElement('p');
      none.className = 'vs-tb-empty';
      none.hidden = true;
      none.textContent = t(meta, 'no_results');
      pop.appendChild(list);
      pop.appendChild(none);
      var search = pop.querySelector('input');
      search.addEventListener('input', function () {
        var q = fold(search.value);
        var any = 0;
        var groupsShown = {};
        list.querySelectorAll('.vs-tb-row').forEach(function (r) {
          var on = !q || r.__search.indexOf(q) !== -1;
          r.hidden = !on;
          if (on) { any++; groupsShown[r.dataset.group] = true; }
        });
        list.querySelectorAll('.vs-tb-group').forEach(function (h) { h.hidden = !groupsShown[h.dataset.group]; });
        none.hidden = any > 0;
      });

      function toggle(open) {
        if (open) {
          closeAll();
          pop.hidden = false;
          pop.style.transform = '';
          // Keep the menu on screen.
          var r = pop.getBoundingClientRect();
          var shift = r.right > window.innerWidth - 8 ? window.innerWidth - 8 - r.right : (r.left < 8 ? 8 - r.left : 0);
          if (shift) pop.style.transform = 'translateX(' + shift + 'px)';
          openOne = toggle;
          if (!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches)) search.focus({ preventScroll: true });
        } else {
          pop.hidden = true;
          if (openOne === toggle) openOne = null;
        }
        item.classList.toggle('is-open', open);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
      button.addEventListener('click', function () { toggle(pop.hidden); });
      item.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !pop.hidden) { e.preventDefault(); toggle(false); button.focus(); return; }
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !pop.hidden) {
          var rows = Array.prototype.filter.call(list.querySelectorAll('.vs-tb-row'), function (r) { return !r.hidden; });
          if (!rows.length) return;
          e.preventDefault();
          var at = rows.indexOf(document.activeElement);
          var next = e.key === 'ArrowDown' ? Math.min(rows.length - 1, at + 1) : at - 1;
          if (next < 0) search.focus(); else rows[next].focus();
        }
      });
      item.appendChild(button);
      item.appendChild(pop);
      bar.appendChild(item);
    });
    editor.parentNode.insertBefore(bar, editor);
    return bar;
  }

  // ---------- How words are said: the member's own pronunciations ----------
  // A word as it is written, and how every new voice should say it (with vowel
  // marks, or spelled the way it sounds). The list is the member's; the server
  // applies it before a voice is read. Words from the list are underlined in
  // the text box, and a selected word can be added in one click.
  // Every Arabic vowel mark, in three groups; each button shows the mark and its Arabic name.
  // Every Arabic vowel mark, in three groups; each button shows the mark on a letter, with its Arabic name.
  var PRON_MARKS = [
    ['short', [['َ', 'fatha', 'ب'], ['ُ', 'damma', 'ب'], ['ِ', 'kasra', 'ب'], ['ْ', 'sukun', 'ب']]],
    ['tanween', [['ً', 'tanween_fath', 'ب'], ['ٌ', 'tanween_damm', 'ب'], ['ٍ', 'tanween_kasr', 'ب']]],
    ['other', [['ّ', 'shadda', 'ب'], ['ٓ', 'madda', 'ا'], ['ٰ', 'dagger_alef', 'ه'], ['ٔ', 'hamza_above', 'ا'], ['ٕ', 'hamza_below', 'ا']]]
  ];
  var PRON_DIACRITICS = '\\u064B-\\u065F\\u0670\\u0640';

  // A word matches however it is written: with or without vowel marks, any form of alef, ى/ي and ة/ه.
  function pronPattern(word) {
    var bare = String(word || '').replace(new RegExp('[' + PRON_DIACRITICS + ']', 'g'), '').trim();
    if (!bare) return null;
    var body = '';
    Array.prototype.forEach.call(bare, function (ch) {
      var cls = /[اأإآٱ]/.test(ch) ? '[اأإآٱ]' : /[يى]/.test(ch) ? '[يى]' : /[هة]/.test(ch) ? '[هة]'
        : /\s/.test(ch) ? '\\s+' : ch.replace(/[.*+?^${}()|[\]\\\/-]/g, '\\$&');
      body += cls + '[' + PRON_DIACRITICS + ']*';
    });
    try {
      return new RegExp('(?<![\\p{L}\\p{M}\\p{N}])' + body + '(?![\\p{L}\\p{M}\\p{N}])', 'giu');
    } catch (e) {
      return null;
    }
  }

  function buildPron(form, meta, toolbar, textarea) {
    var cfg = meta.pron;
    var editor = form.querySelector('[data-vs-editor]');
    if (!cfg || !editor || !textarea) return null;
    var tr = function (key, vars) { return t({ i18n: cfg.i18n || {} }, key, vars); };
    var items = Array.isArray(cfg.items) ? cfg.items.slice() : [];
    var token = (form.querySelector('input[name="_token"]') || {}).value || '';

    if (!toolbar) {
      toolbar = document.createElement('div');
      toolbar.className = 'vs-toolbar';
      toolbar.setAttribute('role', 'toolbar');
      editor.parentNode.insertBefore(toolbar, editor);
    }
    var item = document.createElement('div');
    item.className = 'vs-tb-item vs-tb-pron';
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'vs-tb-btn';
    button.setAttribute('aria-haspopup', 'dialog');
    item.appendChild(button);
    var upgrade = toolbar.querySelector('.vs-tb-upgrade');
    toolbar.insertBefore(item, upgrade || null);

    // The button says which word it will set when one word is selected.
    var picked = '';
    function selectedWord() {
      var s = textarea.selectionStart, e = textarea.selectionEnd;
      if (s == null || e == null || e <= s || e - s > 60) return '';
      var w = textarea.value.slice(s, e).replace(/^[\s«»"'“”(\[]+|[\s«»"'“”)\].,،؛:!?؟]+$/g, '');
      return w && w.split(/\s+/).length <= 3 && /[\p{L}]/u.test(w) && !/[<>\[\]\n]/.test(w) ? w : '';
    }
    function drawButton() {
      var count = items.length;
      button.innerHTML = icon('g-pron') + '<span class="vs-tb-label">' + escAttr(picked ? tr('button_word', { word: picked }) : tr('button')) + '</span>'
        + (!picked && count ? '<small class="vs-tb-count">' + count + '</small>' : '');
      button.classList.toggle('is-word', !!picked);
    }
    function onSelect() {
      var w = selectedWord();
      if (w !== picked) { picked = w; drawButton(); }
    }
    ['select', 'keyup', 'mouseup', 'touchend'].forEach(function (ev) { textarea.addEventListener(ev, onSelect); });
    document.addEventListener('selectionchange', function () { if (document.activeElement === textarea) onSelect(); });
    textarea.addEventListener('blur', function () {
      // Kept while the button is pressed, so the click still knows the word.
      setTimeout(function () { if (document.activeElement !== button && picked) { picked = ''; drawButton(); } }, 250);
    });

    // ---------- The dialog ----------
    var modal = document.createElement('div');
    modal.className = 'vs-modal vs-pron';
    modal.hidden = true;
    var marks = PRON_MARKS.map(function (group) {
      return '<div class="vs-pron-mark-group"><span class="vs-pron-mark-group-name">' + escAttr(tr('marks_' + group[0])) + '</span><div class="vs-pron-mark-row">'
        + group[1].map(function (m) {
          return '<button type="button" class="vs-pron-mark" data-mark="' + m[0] + '" aria-label="' + escAttr(tr('mark_' + m[1])) + '">'
            + '<span class="vs-pron-mark-glyph" aria-hidden="true" dir="rtl">' + m[2] + m[0] + '</span><span class="vs-pron-mark-name" dir="rtl">' + escAttr(tr('mark_' + m[1])) + '</span></button>';
        }).join('') + '</div></div>';
    }).join('');
    // Hear the difference: the same letters read differently, from recordings made once.
    var examples = cfg.examples || {};
    modal.innerHTML = '<div class="vs-modal-backdrop" data-vs-close></div>'
      + '<div class="vs-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="vsPronTitle" aria-describedby="vsPronHint">'
      + '<header class="vs-modal-head"><span class="vs-modal-ico" aria-hidden="true">' + icon('g-pron') + '</span><div class="vs-modal-titles"><h2 id="vsPronTitle">' + escAttr(tr('title')) + '</h2><p id="vsPronHint">' + escAttr(tr('hint')) + '</p></div>'
      + '<button type="button" class="vs-modal-close" data-vs-close aria-label="' + escAttr(tr('close')) + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></header>'
      + '<div class="vs-pron-scroll">'
      + '<section class="vs-pron-example" aria-labelledby="vsPronExTitle" hidden><h3 id="vsPronExTitle">' + escAttr(tr('example_title')) + '</h3><p class="vs-pron-example-text"></p><div class="vs-pron-example-row"></div></section>'
      + '<form class="vs-pron-add" id="vsPronForm" novalidate>'
      + '<div class="vs-pron-fields">'
      + '<label class="vs-pron-field"><span>' + escAttr(tr('word_label')) + '</span><input name="word" maxlength="60" autocomplete="off" spellcheck="false" dir="auto" placeholder="' + escAttr(tr('word_placeholder')) + '"></label>'
      + '<svg class="vs-pron-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>'
      + '<label class="vs-pron-field"><span>' + escAttr(tr('spoken_label')) + '</span><input name="spoken" maxlength="80" autocomplete="off" spellcheck="false" dir="auto" placeholder="' + escAttr(tr('spoken_placeholder')) + '"></label>'
      + '</div>'
      + '<div class="vs-pron-marks" role="group" aria-labelledby="vsPronMarksLabel"><span class="vs-pron-marks-label" id="vsPronMarksLabel">' + escAttr(tr('marks_label')) + '</span>' + marks + '</div>'
      + '<p class="vs-pron-tip">' + escAttr(tr('tip')) + '</p>'
      + '</form>'
      + '<section class="vs-pron-saved" aria-labelledby="vsPronListTitle"><h3 id="vsPronListTitle">' + escAttr(tr('list_title')) + ' <small class="vs-pron-total"></small></h3>'
      + '<ul class="vs-pron-list"></ul><p class="vs-pron-empty" hidden>' + escAttr(tr('empty')) + '</p>'
      + '<p class="vs-pron-note">' + escAttr(tr('foot')) + '</p></section>'
      + '</div>'
      // The add button stays in view however far the panel is scrolled.
      + '<footer class="vs-pron-foot"><p class="vs-pron-error" role="alert" hidden></p>'
      + '<div class="vs-pron-actions"><button type="submit" form="vsPronForm" class="vs-pron-save">' + escAttr(tr('add')) + '</button><button type="button" class="vs-pron-cancel" hidden>' + escAttr(tr('cancel')) + '</button></div></footer>'
      + '</div>';
    document.body.appendChild(modal);
    var addForm = modal.querySelector('.vs-pron-add');
    var wordIn = addForm.querySelector('input[name="word"]');
    var spokenIn = addForm.querySelector('input[name="spoken"]');
    var errorEl = modal.querySelector('.vs-pron-error');
    var saveBtn = modal.querySelector('.vs-pron-save');
    var cancelBtn = modal.querySelector('.vs-pron-cancel');
    var list = modal.querySelector('.vs-pron-list');
    var empty = modal.querySelector('.vs-pron-empty');
    var total = modal.querySelector('.vs-pron-total');
    var editing = null;
    var opener = null;

    // The example follows the voice's language: Arabic letters with vowel marks, or an English spelling.
    var exBox = modal.querySelector('.vs-pron-example');
    var exText = exBox.querySelector('.vs-pron-example-text');
    var exRow = exBox.querySelector('.vs-pron-example-row');
    var exAudio = null;
    var exPlaying = null;
    function stopExample() {
      if (exAudio) exAudio.pause();
      if (exPlaying) exPlaying.classList.remove('is-playing');
      exPlaying = null;
    }
    function drawExample() {
      stopExample();
      var langSelect = form.querySelector('select[name="target_language"]');
      var lang = langSelect && langSelect.value === 'en' ? 'en' : 'ar';
      var ex = examples[lang];
      exBox.hidden = !ex || !ex.items || !ex.items.length;
      if (exBox.hidden) return;
      exText.textContent = ex.text || '';
      exRow.innerHTML = '';
      ex.items.forEach(function (it) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'vs-pron-example-item';
        b.innerHTML = '<span class="vs-pron-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path class="vs-pron-play-on" d="M8 5.5v13l11-6.5z"/><path class="vs-pron-play-off" d="M7.5 5.5h3v13h-3zM13.5 5.5h3v13h-3z"/></svg></span>'
          + '<span class="vs-pron-example-words"><strong dir="auto">' + escAttr(it.spoken) + '</strong><small>' + escAttr(it.gloss || '') + '</small></span>';
        b.setAttribute('aria-label', tr('example_play', { word: it.spoken }));
        b.addEventListener('click', function () {
          if (exPlaying === b) { stopExample(); return; }
          stopExample();
          exAudio = exAudio || new Audio();
          exAudio.src = it.audio;
          exAudio.onended = stopExample;
          exAudio.play().then(function () { exPlaying = b; b.classList.add('is-playing'); }).catch(stopExample);
        });
        exRow.appendChild(b);
      });
    }

    function inText(entry) {
      var re = pronPattern(entry.word);
      return !!(re && re.test(textarea.value));
    }
    function drawList() {
      list.innerHTML = '';
      // Words in the text box now come first.
      var sorted = items.slice().sort(function (a, b) { return (inText(b) ? 1 : 0) - (inText(a) ? 1 : 0); });
      sorted.forEach(function (entry) {
        var li = document.createElement('li');
        li.className = 'vs-pron-row' + (editing && editing.id === entry.id ? ' is-editing' : '');
        li.innerHTML = '<span class="vs-pron-pair"><span class="vs-pron-word" dir="auto">' + escAttr(entry.word) + '</span>'
          + '<svg class="vs-pron-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>'
          + '<span class="vs-pron-spoken" dir="auto">' + escAttr(entry.spoken) + '</span></span>'
          + (inText(entry) ? '<span class="vs-pron-here">' + escAttr(tr('in_text')) + '</span>' : '')
          + '<span class="vs-pron-row-actions"><button type="button" data-edit>' + escAttr(tr('edit')) + '</button>'
          + '<button type="button" data-delete class="is-danger">' + escAttr(tr('delete')) + '</button></span>';
        li.querySelector('[data-edit]').addEventListener('click', function () { startEdit(entry); });
        li.querySelector('[data-delete]').addEventListener('click', function () { remove(entry); });
        list.appendChild(li);
      });
      empty.hidden = items.length > 0;
      total.textContent = items.length ? String(items.length) : '';
    }
    function showError(key) {
      errorEl.textContent = key ? tr(key) : '';
      errorEl.hidden = !key;
    }
    function resetForm() {
      editing = null;
      wordIn.value = '';
      spokenIn.value = '';
      saveBtn.textContent = tr('add');
      cancelBtn.hidden = true;
      showError('');
    }
    function startEdit(entry) {
      editing = entry;
      wordIn.value = entry.word;
      spokenIn.value = entry.spoken;
      saveBtn.textContent = tr('save');
      cancelBtn.hidden = false;
      showError('');
      drawList();
      spokenIn.focus();
    }
    cancelBtn.addEventListener('click', function () { resetForm(); drawList(); wordIn.focus(); });

    function send(method, url, body) {
      return fetch(url, {
        method: method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined
      }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
          if (!res.ok) { var err = new Error('save'); err.data = data; throw err; }
          return data;
        });
      });
    }
    function applyItems(data) {
      if (data && Array.isArray(data.items)) items = data.items;
      drawList();
      drawButton();
      repaint();
    }

    addForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var word = wordIn.value.trim();
      var spoken = spokenIn.value.trim();
      if (!word || !spoken) { showError('error_empty'); (word ? spokenIn : wordIn).focus(); return; }
      if (word === spoken) { showError('error_same'); spokenIn.focus(); return; }
      if (!editing && items.length >= (cfg.limit || 200)) { showError('error_full'); return; }
      saveBtn.disabled = true;
      send('POST', cfg.url, { id: editing ? editing.id : null, word: word, spoken: spoken })
        .then(function (data) { resetForm(); applyItems(data); wordIn.focus(); })
        .catch(function (err) {
          var msg = err.data && err.data.message;
          errorEl.textContent = msg || tr('error_save');
          errorEl.hidden = false;
        })
        .then(function () { saveBtn.disabled = false; });
    });
    function remove(entry) {
      send('DELETE', cfg.url + '/' + encodeURIComponent(entry.id))
        .then(applyItems)
        .catch(function () { showError('error_save'); });
    }

    // Vowel marks go in at the cursor of the "say it as" field.
    var spokenCaret = null;
    spokenIn.addEventListener('blur', function () { spokenCaret = [spokenIn.selectionStart, spokenIn.selectionEnd]; });
    modal.querySelectorAll('.vs-pron-mark').forEach(function (b) {
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        var at = document.activeElement === spokenIn ? [spokenIn.selectionStart, spokenIn.selectionEnd] : (spokenCaret || [spokenIn.value.length, spokenIn.value.length]);
        spokenIn.value = spokenIn.value.slice(0, at[0]) + b.dataset.mark + spokenIn.value.slice(at[1]);
        var caret = at[0] + b.dataset.mark.length;
        spokenIn.focus();
        spokenIn.setSelectionRange(caret, caret);
        spokenCaret = [caret, caret];
      });
    });

    function open() {
      opener = document.activeElement;
      resetForm();
      if (picked) {
        // The selected word, ready to be given its marks.
        var known = items.filter(function (it) { var re = pronPattern(it.word); return re && re.test(picked); })[0];
        if (known) startEdit(known); else { wordIn.value = picked; spokenIn.value = picked; }
      }
      drawList();
      drawExample();
      modal.hidden = false;
      document.documentElement.classList.add('vs-modal-open');
      setTimeout(function () {
        var target = picked ? spokenIn : wordIn;
        target.focus();
        if (picked) target.setSelectionRange(target.value.length, target.value.length);
      }, 30);
    }
    function close() {
      stopExample();
      modal.hidden = true;
      document.documentElement.classList.remove('vs-modal-open');
      picked = '';
      // Back to writing: the word stays where it was, no longer selected.
      if (textarea.selectionEnd > textarea.selectionStart) textarea.setSelectionRange(textarea.selectionEnd, textarea.selectionEnd);
      drawButton();
      if (opener && opener.focus) opener.focus({ preventScroll: true });
    }
    modal.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-vs-close]')) close(); });
    modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); close(); } });
    button.addEventListener('mousedown', function (e) { e.preventDefault(); });
    button.addEventListener('click', open);

    // ---------- Underline the words in the text box ----------
    var repaint = function () {};
    drawButton();
    return {
      item: item,
      onPaint: function (fn) { repaint = fn; },
      // Called on the text box's coloured copy: underlines every word from the list.
      mark: function (html) {
        if (!items.length) return html;
        return html.split(/(<mark[^>]*>[\s\S]*?<\/mark>)/).map(function (part) {
          if (part.indexOf('<mark') === 0) return part;
          items.forEach(function (entry) {
            var re = pronPattern(esc(entry.word));
            if (re) part = part.replace(re, function (m) { return '<mark class="t-pron">' + m + '</mark>'; });
          });
          return part;
        }).join('');
      }
    };
  }

  // The tags need the premium voices: the menus show, dimmed, beside an upgrade button.
  function lockToolbar(bar, lock, meta) {
    bar.classList.add('is-locked');
    bar.querySelectorAll('.vs-tb-item').forEach(function (item) {
      item.setAttribute('inert', '');
      item.setAttribute('aria-hidden', 'true');
    });
    var link = document.createElement('a');
    link.className = 'vs-upgrade vs-upgrade-inline vs-tb-upgrade';
    link.href = lock.url;
    link.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2.2"/><path d="M8.5 10.5V7.8a3.5 3.5 0 0 1 7 0v2.7"/></svg><span>'
      + escAttr(lock.plan ? t(meta, 'upgrade_to_plan').replace(':plan', lock.plan) : t(meta, 'upgrade_to_unlock')) + '</span>';
    bar.appendChild(link);
  }

  // ---------- Voice library: every speaker as a card ----------
  // The voice select stays the form field; the page fills it per language
  // and tier, and the library draws one card per option.
  function initLibrary(library, meta) {
    var select = library.querySelector('select[name="voice_key"]');
    var grid = library.querySelector('[data-vs-lib-grid]');
    var empty = library.querySelector('[data-vs-lib-empty]');
    var search = library.querySelector('[data-vs-lib-search]');
    var tabs = library.querySelectorAll('[data-vs-lib-tab]');
    var genders = library.querySelectorAll('[data-vs-lib-gender]');
    var ages = library.querySelectorAll('[data-vs-lib-age]');
    if (!select || !grid) return;
    var voices = meta.voices || {};
    var state = { tab: 'all', gender: '', age: '', query: '' };
    var STORE = 'listen.studio.favorites';
    var favorites = {};
    (meta.favorites || []).forEach(function (k) { favorites[k] = true; });
    try { (JSON.parse(window.localStorage.getItem(STORE) || '[]') || []).forEach(function (k) { favorites[k] = true; }); } catch (e) { /* no storage */ }
    var audio = null;
    var playing = '';
    var loadingTimer = 0;
    var frame = 0;
    var warmed = {};
    // Errors only: a screen reader is never told "playing" over the voice itself.
    var status = document.createElement('p');
    status.className = 'vs-sr';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    library.appendChild(status);

    function items() {
      return Array.prototype.filter.call(select.options, function (o) { return o.value && !o.hidden; }).map(function (o) {
        var v = voices[o.value] || {};
        var mine = o.value.indexOf('cloned_') === 0;
        var label = String(o.textContent || '').trim();
        // "Name (Cloned voice)": the card says it with its own badge.
        if (mine) label = label.replace(/\s*\([^()]*\)\s*$/, '');
        return {
          value: o.value, label: label, mine: mine, gender: v.g || '', age: v.y || '', avatar: v.a || o.dataset.avatar || '',
          flag: v.f || '', dialect: v.d || '', preview: v.p || '', featured: !!v.s, disabled: o.disabled,
          key: fold(label + ' ' + (v.d || '') + ' ' + (v.g ? t(meta, v.g) : '') + ' ' + (v.y ? t(meta, 'age_' + v.y) : '') + ' ' + o.value)
        };
      });
    }
    function heart(on) {
      return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5s-7.5-4.4-7.5-10A4.3 4.3 0 0 1 12 7.8a4.3 4.3 0 0 1 7.5 2.7c0 5.6-7.5 10-7.5 10z"' + (on ? ' fill="currentColor"' : '') + '/></svg>';
    }
    function saveFavorites() {
      var list = Object.keys(favorites).filter(function (k) { return favorites[k]; }).slice(0, 100);
      try { window.localStorage.setItem(STORE, JSON.stringify(list)); } catch (e) { /* no storage */ }
      if (!meta.favoritesUrl || !window.fetch) return;
      var tokenNode = document.querySelector('meta[name="csrf-token"]') || document.querySelector('input[name="_token"]');
      var token = tokenNode ? (tokenNode.content || tokenNode.value || '') : '';
      fetch(meta.favoritesUrl, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ voices: list })
      }).catch(function () { /* kept in this browser */ });
    }
    // Previews: one shared player. Hovering a card shows the play button on the face;
    // a click, tap, Enter or Space plays it (hover alone may not start sound), and a
    // ring around the face shows how much is left. Stopping rewinds.
    function playingCard() {
      return playing ? grid.querySelector('.vs-voice[data-value="' + playing.replace(/["\\]/g, '') + '"]') : null;
    }
    function stopPreview() {
      clearTimeout(loadingTimer);
      cancelAnimationFrame(frame);
      if (audio) { audio.pause(); try { audio.currentTime = 0; } catch (e) { /* not loaded yet */ } }
      playing = '';
      grid.querySelectorAll('.vs-voice.is-playing, .vs-voice.is-loading').forEach(function (c) {
        c.classList.remove('is-playing', 'is-loading');
        c.style.removeProperty('--vs-progress');
        var b = c.querySelector('.vs-voice-play');
        if (b) b.setAttribute('aria-pressed', 'false');
      });
    }
    function progress() {
      var card = playingCard();
      if (card && audio && audio.duration) card.style.setProperty('--vs-progress', String(Math.min(1, audio.currentTime / audio.duration)));
      frame = requestAnimationFrame(progress);
    }
    function failed() {
      if (!playing) return;
      stopPreview();
      status.textContent = t(meta, 'preview_error');
    }
    function warm(item) {
      if (!item.preview || warmed[item.value] || !window.fetch) return;
      if (navigator.connection && navigator.connection.saveData) return;
      warmed[item.value] = true;
      fetch(item.preview, { credentials: 'same-origin', priority: 'low' }).catch(function () { warmed[item.value] = false; });
    }
    function togglePreview(item, card) {
      if (playing === item.value) { stopPreview(); return; }
      stopPreview();
      status.textContent = '';
      if (!audio) {
        audio = new Audio();
        audio.preload = 'none';
        audio.addEventListener('ended', stopPreview);
        audio.addEventListener('error', failed);
      }
      audio.src = item.preview;
      playing = item.value;
      var value = item.value;
      // A spinner only if the clip is slow to start, so a cached one never flashes it.
      loadingTimer = setTimeout(function () { var c = playingCard(); if (c && playing === value) c.classList.add('is-loading'); }, 180);
      var started = audio.play();
      var begin = function () {
        if (playing !== value) return;
        clearTimeout(loadingTimer);
        var c = playingCard() || card;
        c.classList.remove('is-loading');
        c.classList.add('is-playing');
        var b = c.querySelector('.vs-voice-play');
        if (b) b.setAttribute('aria-pressed', 'true');
        document.dispatchEvent(new CustomEvent('listen:audio-play', { detail: { source: audio } }));
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(progress);
      };
      if (started && started.then) started.then(begin, function (e) { if (playing === value && (!e || e.name !== 'AbortError')) failed(); });
      else begin();
    }
    // Another voice on the page, a new generation, a hidden tab or Esc: the preview stops.
    document.addEventListener('listen:audio-play', function (e) { if (playing && (!e.detail || e.detail.source !== audio)) stopPreview(); });
    document.addEventListener('visibilitychange', function () { if (document.hidden && playing) stopPreview(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && playing) stopPreview(); });
    var form = library.closest('form');
    if (form) form.addEventListener('submit', function () { if (playing) stopPreview(); });
    function choose(value) {
      if (select.value === value) return;
      select.value = value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      render();
    }
    function card(item) {
      var el = document.createElement('div');
      el.className = 'vs-voice' + (item.value === select.value ? ' is-selected' : '') + (playing === item.value ? ' is-playing' : '');
      el.setAttribute('role', 'option');
      el.setAttribute('aria-selected', item.value === select.value ? 'true' : 'false');
      el.tabIndex = item.value === select.value ? 0 : -1;
      el.dataset.value = item.value;
      if (item.disabled) el.setAttribute('aria-disabled', 'true');
      var initial = escAttr(item.label.charAt(0).toUpperCase());
      var avatar = item.avatar
        ? '<img src="' + escAttr(item.avatar) + '" alt="" width="64" height="64" loading="lazy" decoding="async">'
        : '<span class="vs-voice-initial">' + initial + '</span>';
      // The play button sits on the face; the ring around it fills as the preview plays.
      var play = item.preview
        ? '<span class="vs-voice-ring" aria-hidden="true"></span>'
          + '<button type="button" class="vs-voice-play" aria-pressed="' + (playing === item.value ? 'true' : 'false') + '" aria-label="' + escAttr(t(meta, 'play')) + ' ' + escAttr(item.label) + '">'
          + '<span class="vs-voice-play-dot"><svg class="i-play" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg>'
          + '<svg class="i-stop" viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="7" width="10" height="10" rx="1.5"/></svg></span></button>'
        : '';
      var metaLine = [];
      if (item.mine) metaLine.push('<span class="vs-voice-badge">' + escAttr(t(meta, 'mine_badge')) + '</span>');
      // The gender filter above the cards already sorts by gender: no label on each card.
      el.innerHTML = '<button type="button" class="vs-voice-fav" aria-pressed="' + (favorites[item.value] ? 'true' : 'false') + '" aria-label="'
          + escAttr(t(meta, favorites[item.value] ? 'favorite_remove' : 'favorite_add')) + '">' + heart(favorites[item.value]) + '</button>'
        + '<span class="vs-voice-avatar">' + avatar + play + (item.flag ? '<span class="vs-voice-flag"><img src="' + escAttr(item.flag) + '" alt="" width="18" height="18" loading="lazy"></span>' : '') + '</span>'
        + '<span class="vs-voice-name">' + escAttr(item.label) + '</span>'
        + (metaLine.length ? '<span class="vs-voice-meta">' + metaLine.join('') + '</span>' : '')
        + (item.dialect ? '<span class="vs-voice-dialect">' + escAttr(item.dialect) + '</span>' : '')
        + '<svg class="vs-voice-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
      el.querySelector('.vs-voice-fav').addEventListener('click', function (e) {
        e.stopPropagation();
        favorites[item.value] = !favorites[item.value];
        if (!favorites[item.value]) delete favorites[item.value];
        saveFavorites();
        render();
      });
      var button = el.querySelector('.vs-voice-play');
      if (button) {
        button.addEventListener('click', function (e) { e.stopPropagation(); togglePreview(item, el); });
        button.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') e.stopPropagation(); });
        // Fetch the clip while the pointer rests on the card, so the click plays at once.
        var hover = 0;
        el.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse') hover = setTimeout(function () { warm(item); }, 65); });
        el.addEventListener('pointerleave', function () { clearTimeout(hover); });
        button.addEventListener('pointerdown', function () { warm(item); });
      }
      el.addEventListener('click', function () { if (!item.disabled) choose(item.value); });
      el.addEventListener('keydown', function (e) {
        if (e.target !== el) return;
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (!item.disabled) choose(item.value); return; }
        var cards = Array.prototype.slice.call(grid.querySelectorAll('.vs-voice'));
        var at = cards.indexOf(el);
        var rtl = getComputedStyle(grid).direction === 'rtl';
        var cols = Math.max(1, Math.round(grid.clientWidth / Math.max(1, el.offsetWidth)));
        var next = { ArrowRight: rtl ? at - 1 : at + 1, ArrowLeft: rtl ? at + 1 : at - 1, ArrowDown: at + cols, ArrowUp: at - cols, Home: 0, End: cards.length - 1 }[e.key];
        if (next === undefined) return;
        e.preventDefault();
        var target = cards[Math.max(0, Math.min(cards.length - 1, next))];
        if (target) { target.tabIndex = 0; el.tabIndex = -1; target.focus(); }
      });
      return el;
    }
    var lastKey = '';
    function render() {
      var all = items();
      var counts = {
        all: all.filter(function (i) { return !i.mine; }).length,
        favorites: all.filter(function (i) { return favorites[i.value]; }).length,
        mine: all.filter(function (i) { return i.mine; }).length
      };
      tabs.forEach(function (tab) {
        var key = tab.dataset.vsLibTab;
        if (key === 'mine') tab.hidden = counts.mine === 0;
        var n = tab.querySelector('[data-vs-lib-count]');
        if (n) n.textContent = counts[key] ? String(counts[key]) : '';
      });
      if (state.tab === 'mine' && counts.mine === 0) state.tab = 'all';
      // An age shows only when the language has speakers of that age (children, elders ...).
      var hasAge = {};
      all.forEach(function (i) { if (i.age) hasAge[i.age] = true; });
      ages.forEach(function (b) { if (b.dataset.vsLibAge) b.hidden = !hasAge[b.dataset.vsLibAge]; });
      if (state.age && !hasAge[state.age]) {
        state.age = '';
        ages.forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.vsLibAge === '' ? 'true' : 'false'); });
      }
      tabs.forEach(function (tab) { tab.setAttribute('aria-selected', tab.dataset.vsLibTab === state.tab ? 'true' : 'false'); });
      var q = fold(state.query);
      var shown = all.filter(function (i) {
        if (state.tab === 'all' && i.mine) return false;
        if (state.tab === 'mine' && !i.mine) return false;
        if (state.tab === 'favorites' && !favorites[i.value]) return false;
        if (state.gender && i.gender && i.gender !== state.gender) return false;
        if (state.gender && !i.gender && !i.mine) return false;
        if (state.age && i.age !== state.age) return false;
        return !q || i.key.indexOf(q) !== -1;
      });
      var key = JSON.stringify([state, select.value, shown.map(function (i) { return i.value + (favorites[i.value] ? '*' : ''); })]);
      if (key === lastKey) return;
      var focusedValue = grid.contains(document.activeElement) && document.activeElement.closest('.vs-voice') ? document.activeElement.closest('.vs-voice').dataset.value : null;
      lastKey = key;
      grid.innerHTML = '';
      shown.forEach(function (i) { grid.appendChild(card(i)); });
      if (!grid.querySelector('.vs-voice[tabindex="0"]') && grid.firstChild) grid.firstChild.tabIndex = 0;
      if (focusedValue) {
        var again = grid.querySelector('.vs-voice[data-value="' + focusedValue.replace(/"/g, '') + '"]');
        if (again) again.focus({ preventScroll: true });
      }
      empty.hidden = shown.length > 0;
      empty.textContent = state.tab === 'favorites' && !q && !state.gender && !state.age ? t(meta, 'no_favorites') : (all.length ? t(meta, 'no_results') : t(meta, 'no_voices'));
    }
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () { state.tab = tab.dataset.vsLibTab; render(); });
    });
    genders.forEach(function (b) {
      b.addEventListener('click', function () {
        state.gender = b.dataset.vsLibGender;
        genders.forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        render();
      });
    });
    ages.forEach(function (b) {
      b.addEventListener('click', function () {
        state.age = b.dataset.vsLibAge;
        ages.forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
        render();
      });
    });
    if (search) {
      search.addEventListener('input', function () { state.query = search.value; render(); });
      search.addEventListener('keydown', function (e) { if (e.key === 'Escape') { search.value = ''; state.query = ''; render(); } });
    }
    select.addEventListener('change', render);
    // The page focuses the speaker field (a speaker chosen on the home page): show that card.
    select.addEventListener('focus', function () {
      var picked = grid.querySelector('.vs-voice.is-selected') || grid.querySelector('.vs-voice');
      if (picked) { picked.focus({ preventScroll: true }); picked.scrollIntoView({ block: 'nearest' }); }
    });
    if (window.MutationObserver) {
      new MutationObserver(function () { stopPreview(); render(); }).observe(select, { childList: true, subtree: true });
    }
    // Pages also set the speaker from code (language switch, reuse a generation).
    var lastValue = select.value;
    setInterval(function () { if (select.value !== lastValue) { lastValue = select.value; render(); } }, 300);
    render();
  }

  function initPanel(panel) {
    if (panel.__vs) return;
    panel.__vs = true;
    var form = panel.closest('form') || document;
    var textarea = form.querySelector('[data-vs-editor] textarea');
    var meta = readMeta(panel);

    // ---------- Insert tags at the cursor ----------
    // The cursor is remembered when the text box loses focus, so a menu
    // click still inserts where the member was writing.
    var caretAt = null;
    if (textarea) {
      textarea.addEventListener('blur', function () { caretAt = [textarea.selectionStart, textarea.selectionEnd]; });
    }
    function insertTag(kind, label) {
      if (!textarea) return false;
      var isDelivery = kind === 'delivery';
      var tag = isDelivery ? '[' + label + ']' : '<' + label + '>';
      var value = textarea.value;
      var start = document.activeElement === textarea || !caretAt ? textarea.selectionStart : caretAt[0];
      var end = document.activeElement === textarea || !caretAt ? textarea.selectionEnd : caretAt[1];
      if (start == null) start = value.length;
      if (end == null) end = start;
      var before = value.slice(0, start);
      var after = value.slice(end);
      var insert = (before && !/\s$/.test(before) ? ' ' : '') + tag + (after && !/^\s/.test(after) ? ' ' : (isDelivery && !after ? ' ' : ''));
      textarea.value = before + insert + after;
      var caret = before.length + insert.length;
      textarea.focus();
      textarea.setSelectionRange(caret, caret);
      caretAt = [caret, caret];
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    }
    var toolbar = buildToolbar(form, meta, insertTag);
    if (toolbar && meta.tagsLock) lockToolbar(toolbar, meta.tagsLock, meta);
    // Pronunciations work on every voice and plan, so they stay when the tags are locked or hidden.
    var pron = buildPron(form, meta, toolbar, textarea);
    if (pron && !toolbar) toolbar = pron.item.parentNode;

    // Options the plan does not include stay visible but are never sent.
    form.querySelectorAll('[data-vs-locked] input, [data-vs-locked] select, [data-vs-locked] textarea, [data-vs-locked] button')
      .forEach(function (el) { el.disabled = true; });

    // ---------- Colour the tags inside the text box ----------
    var backdrop = form.querySelector('[data-vs-editor] [data-vs-backdrop]');
    if (backdrop && textarea) {
      var paint = function () {
        var html = esc(textarea.value)
          .replace(/&lt;([^&\n]{1,40}?)&gt;/g, '<mark class="t-sound">&lt;$1&gt;</mark>')
          .replace(/\[([^\[\]\n]{1,40})\]/g, '<mark class="t-delivery">[$1]</mark>');
        backdrop.innerHTML = (pron ? pron.mark(html) : html) + '\n ';
        backdrop.scrollTop = textarea.scrollTop;
      };
      textarea.addEventListener('input', paint);
      if (pron) pron.onPaint(paint);
      textarea.addEventListener('scroll', function () { backdrop.scrollTop = textarea.scrollTop; });
      if (window.ResizeObserver) new ResizeObserver(paint).observe(textarea);
      // Pages also fill the box from code (samples, reuse), without an input event.
      var last = textarea.value;
      setInterval(function () { if (textarea.value !== last) { last = textarea.value; paint(); } }, 400);
      paint();
    }

    // ---------- Standard voices: no tags, dialect, style or emotion ----------
    var tier = form.querySelector('select[name="voice_tier"]');
    function applyTier() {
      var hd = !tier || tier.value === 'hd';
      // Standard voices take no tags; the pronunciation button stays.
      if (toolbar && pron) toolbar.querySelectorAll('.vs-tb-item:not(.vs-tb-pron), .vs-tb-upgrade').forEach(function (el) { el.hidden = !hd; });
      else if (toolbar) toolbar.hidden = !hd;
      form.querySelectorAll('[data-vs-hd-only]').forEach(function (el) { el.classList.toggle('vs-off', !hd); });
    }
    if (tier) tier.addEventListener('change', applyTier);
    applyTier();

    // ---------- Pickers: dialect, performance style and emotion ----------
    form.querySelectorAll('[data-vs-pick]').forEach(function (button) {
      var select = form.querySelector('select[name="' + button.dataset.vsPick + '"]');
      if (select) initPickButton(button, select, meta);
    });

    // ---------- Voice library ----------
    var library = panel.querySelector('[data-vs-library]');
    if (library) initLibrary(library, meta);

    // ---------- Sliders: the value badge follows the slider ----------
    panel.querySelectorAll('input[type="range"]').forEach(function (range) {
      var badge = range.id ? document.getElementById(range.id + 'Val') : null;
      var step = parseFloat(range.step || '1');
      var show = function () {
        var n = parseFloat(range.value);
        if (isNaN(n)) return;
        var text = step < 1 ? n.toFixed(1) : (n > 0 && parseFloat(range.min) < 0 ? '+' : '') + Math.round(n);
        if (badge && badge.textContent !== text) badge.textContent = text;
        range.setAttribute('aria-valuetext', text + (badge && badge.dataset.unit ? badge.dataset.unit : ''));
      };
      range.addEventListener('input', show);
      range.addEventListener('change', show);
      // Pages reset the sliders and badges from code (tier switch, reuse a generation).
      setInterval(show, 300);
      show();
    });

    // Every visible dropdown in the panel and on the text box (hidden form fields stay as they are).
    var selects = Array.prototype.slice.call(panel.querySelectorAll('select'))
      .concat(Array.prototype.slice.call(form.querySelectorAll('[data-vs-setbar] select')));
    selects.forEach(function (select) {
      if (select.classList.contains('vs-hidden-field') || select.getAttribute('aria-hidden') === 'true') return;
      enhanceSelect(select);
    });
  }

  function boot() {
    document.querySelectorAll('[data-vs-panel]').forEach(initPanel);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  window.VoiceStudio = { init: boot, fold: fold };
})();
