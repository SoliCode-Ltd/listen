/*
 * Voice studio panel (components/voice-studio/panel.blade.php), shared by the
 * dashboard generator and the demo page:
 *  - tabs (language / sound effects / delivery), hidden for standard voices;
 *  - search across both tag tabs, forgiving Arabic spelling;
 *  - tags inserted at the cursor and highlighted inside the text box, from
 *    the panel or the toolbar above the text (reactions, emotions, voice);
 *  - pickers for dialect, performance style and emotion: a card showing the
 *    choice and a dialog with search, categories, recent picks and icons,
 *    kept in step with each select, which stays the form field;
 *  - tag tabs: a category list, then that category's tags;
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
        item.desc = t(meta, isDialect ? 'auto_desc' : 'default_desc');
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
    modal.querySelector('.vs-modal-ico').innerHTML = icon(isDialect ? 'g-globe' : kind === 'emotion' ? 'f-smile' : 'g-mic');
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
  function buildToolbar(panel, form, meta, insertTag) {
    var editor = form.querySelector('[data-vs-editor]');
    var chips = panel.querySelectorAll('[data-vocal-tag]');
    if (!editor || !chips.length || !editor.parentNode) return null;
    var menus = [
      { key: 'reactions', icon: 'f-laugh', rows: [] },
      { key: 'emotions', icon: 'f-starstruck', rows: [] },
      { key: 'voice', icon: 'g-volume', rows: [] }
    ];
    Array.prototype.forEach.call(chips, function (chip) {
      var group = chip.closest('[data-vs-group]');
      var groupKey = group ? group.dataset.vsGroup : '';
      var groupLabelNode = group && group.querySelector('.vs-group-label');
      var row = {
        kind: chip.dataset.tagKind,
        label: chip.dataset.vocalTag,
        key: chip.dataset.tagKey,
        group: groupKey,
        groupLabel: groupLabelNode ? groupLabelNode.textContent.trim() : '',
        search: fold(chip.dataset.search || chip.dataset.vocalTag)
      };
      var menu = row.kind === 'delivery' ? (groupKey === 'mood' ? menus[1] : menus[2]) : (groupKey === 'pauses' ? menus[2] : menus[0]);
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

  function initPanel(panel) {
    if (panel.__vs) return;
    panel.__vs = true;
    var form = panel.closest('form') || document;
    var textarea = form.querySelector('[data-vs-editor] textarea');
    var tabs = panel.querySelectorAll('[data-vs-tab]');
    var panes = panel.querySelectorAll('[data-vs-pane]');
    var meta = readMeta(panel);
    var search = panel.querySelector('[data-vs-search]');
    var searchInput = search ? search.querySelector('input') : null;
    var active = 'settings';

    function showTab(name) {
      active = name;
      tabs.forEach(function (t) { t.setAttribute('aria-selected', t.dataset.vsTab === name ? 'true' : 'false'); });
      if (searchInput && searchInput.value.trim()) { applySearch(); return; }
      panes.forEach(function (p) { p.hidden = p.dataset.vsPane !== name; });
      if (search) search.hidden = name === 'settings';
      panel.querySelectorAll('[data-vs-results-title]').forEach(function (h) { h.hidden = true; });
    }

    // ---------- Categories: one group of tags at a time ----------
    function showCategory(pane) {
      var picker = pane.querySelector('[data-vs-category]');
      if (!picker) return;
      pane.querySelectorAll('.vs-groups [data-vs-group]').forEach(function (g) { g.hidden = g.dataset.vsGroup !== picker.value; });
    }
    panel.querySelectorAll('[data-vs-category]').forEach(function (picker) {
      var pane = picker.closest('[data-vs-pane]');
      picker.addEventListener('change', function () { showCategory(pane); });
      showCategory(pane);
    });

    // ---------- Search: one query, results from both tag tabs ----------
    function applySearch() {
      var q = fold(searchInput ? searchInput.value : '');
      var tagPanes = panel.querySelectorAll('[data-vs-pane="sounds"], [data-vs-pane="delivery"]');
      tagPanes.forEach(function (pane) {
        pane.classList.toggle('is-searching', !!q);
        pane.querySelectorAll('[data-vs-whole], [data-vs-category-wrap], [data-vs-tag-hint]').forEach(function (el) { el.hidden = !!q; });
      });
      if (!q) {
        tagPanes.forEach(function (pane) {
          pane.querySelectorAll('.vs-chip').forEach(function (c) { c.hidden = false; });
          showCategory(pane);
          var empty = pane.querySelector('.vs-empty');
          if (empty) empty.hidden = true;
        });
        panes.forEach(function (p) { p.hidden = p.dataset.vsPane !== active; });
        panel.querySelectorAll('[data-vs-results-title]').forEach(function (h) { h.hidden = true; });
        return;
      }
      var total = 0;
      tagPanes.forEach(function (pane) {
        var inPane = 0;
        pane.querySelectorAll('.vs-groups [data-vs-group]').forEach(function (group) {
          var inGroup = 0;
          group.querySelectorAll('.vs-chip').forEach(function (chip) {
            var match = (chip.__vsKey || (chip.__vsKey = fold(chip.dataset.search || chip.textContent))).indexOf(q) !== -1;
            chip.hidden = !match;
            if (match) inGroup++;
          });
          group.hidden = inGroup === 0;
          inPane += inGroup;
        });
        pane.hidden = inPane === 0;
        var title = pane.querySelector('[data-vs-results-title]');
        if (title) title.hidden = inPane === 0;
        var empty = pane.querySelector('.vs-empty');
        if (empty) empty.hidden = true;
        total += inPane;
      });
      panel.querySelectorAll('[data-vs-pane="settings"]').forEach(function (p) { p.hidden = true; });
      if (total === 0) {
        var target = panel.querySelector('[data-vs-pane="' + (active === 'settings' ? 'sounds' : active) + '"]');
        if (target) {
          target.hidden = false;
          var empty = target.querySelector('.vs-empty');
          if (empty) empty.hidden = false;
          var title = target.querySelector('[data-vs-results-title]');
          if (title) title.hidden = true;
        }
      }
    }
    if (searchInput) {
      searchInput.addEventListener('input', applySearch);
      searchInput.addEventListener('search', applySearch);
      searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { searchInput.value = ''; applySearch(); }
      });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { showTab(t.dataset.vsTab); }); });

    // ---------- Insert tags at the cursor ----------
    // The cursor is remembered when the text box loses focus, so a menu or
    // panel click still inserts where the member was writing.
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
    panel.querySelectorAll('[data-vocal-tag]').forEach(function (chip) {
      var icon = meta.tags && meta.tags[chip.dataset.tagKey];
      if (icon && window.VoiceStudioIcons) {
        chip.insertAdjacentHTML('afterbegin', window.VoiceStudioIcons.svg(icon, 'vs-chip-ico'));
        chip.classList.add('has-ico');
      }
      chip.addEventListener('click', function () {
        if (!insertTag(chip.dataset.tagKind, chip.dataset.vocalTag)) return;
        chip.classList.add('is-added');
        setTimeout(function () { chip.classList.remove('is-added'); }, 450);
      });
    });
    var toolbar = buildToolbar(panel, form, meta, insertTag);

    // ---------- Colour the tags inside the text box ----------
    var backdrop = form.querySelector('[data-vs-editor] [data-vs-backdrop]');
    if (backdrop && textarea) {
      var paint = function () {
        backdrop.innerHTML = esc(textarea.value)
          .replace(/&lt;([^&\n]{1,40}?)&gt;/g, '<mark class="t-sound">&lt;$1&gt;</mark>')
          .replace(/\[([^\[\]\n]{1,40})\]/g, '<mark class="t-delivery">[$1]</mark>') + '\n ';
        backdrop.scrollTop = textarea.scrollTop;
      };
      textarea.addEventListener('input', paint);
      textarea.addEventListener('scroll', function () { backdrop.scrollTop = textarea.scrollTop; });
      if (window.ResizeObserver) new ResizeObserver(paint).observe(textarea);
      // Pages also fill the box from code (samples, reuse), without an input event.
      var last = textarea.value;
      setInterval(function () { if (textarea.value !== last) { last = textarea.value; paint(); } }, 400);
      paint();
    }

    // ---------- Standard voices: only the voice tab ----------
    var tier = form.querySelector('select[name="voice_tier"]');
    function applyTier() {
      var hd = !tier || tier.value === 'hd';
      panel.querySelectorAll('[data-vs-tab="sounds"], [data-vs-tab="delivery"]').forEach(function (t) { t.hidden = !hd; });
      if (toolbar) toolbar.hidden = !hd;
      if (!hd && active !== 'settings') showTab('settings');
    }
    if (tier) { tier.addEventListener('change', applyTier); applyTier(); }

    // ---------- Pickers: dialect, performance style and emotion ----------
    panel.querySelectorAll('[data-vs-pick]').forEach(function (button) {
      var select = form.querySelector('select[name="' + button.dataset.vsPick + '"]');
      if (select) initPickButton(button, select, meta);
    });

    // A required setting left empty in a hidden tab: open it so the browser can point at it.
    if (form.addEventListener) {
      form.addEventListener('invalid', function (event) {
        var pane = event.target.closest && event.target.closest('[data-vs-pane]');
        if (pane && pane.hidden) {
          if (searchInput) searchInput.value = '';
          showTab(pane.dataset.vsPane);
        }
      }, true);
    }

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

    // Every visible dropdown in the panel (hidden form fields stay as they are).
    panel.querySelectorAll('select').forEach(function (select) {
      if (select.closest('.vs-hidden-field') || select.classList.contains('vs-hidden-field') || select.getAttribute('aria-hidden') === 'true') return;
      enhanceSelect(select);
    });

    showTab('settings');
  }

  function boot() {
    document.querySelectorAll('[data-vs-panel]').forEach(initPanel);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  window.VoiceStudio = { init: boot, fold: fold };
})();
