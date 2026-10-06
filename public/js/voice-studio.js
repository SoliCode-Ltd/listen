/*
 * Voice studio panel (components/voice-studio/panel.blade.php), shared by the
 * dashboard generator and the demo page:
 *  - tabs (language / sound effects / delivery), hidden for standard voices;
 *  - search across both tag tabs, forgiving Arabic spelling;
 *  - tags inserted at the cursor and highlighted inside the text box;
 *  - dialects: a country list, then that country's dialects as buttons,
 *    kept in step with the dialect select, which stays the form field;
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

    function syncLabel() {
      var picked = select.options[select.selectedIndex];
      label.textContent = picked ? picked.textContent : '';
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

  function initPanel(panel) {
    if (panel.__vs) return;
    panel.__vs = true;
    var form = panel.closest('form') || document;
    var textarea = form.querySelector('[data-vs-editor] textarea');
    var tabs = panel.querySelectorAll('[data-vs-tab]');
    var panes = panel.querySelectorAll('[data-vs-pane]');
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
    panel.querySelectorAll('[data-vocal-tag]').forEach(function (chip) {
      chip.addEventListener('click', function () {
        if (!textarea) return;
        var isDelivery = chip.dataset.tagKind === 'delivery';
        var tag = isDelivery ? '[' + chip.dataset.vocalTag + ']' : '<' + chip.dataset.vocalTag + '>';
        var value = textarea.value;
        var start = textarea.selectionStart != null ? textarea.selectionStart : value.length;
        var end = textarea.selectionEnd != null ? textarea.selectionEnd : start;
        var before = value.slice(0, start);
        var after = value.slice(end);
        var insert = (before && !/\s$/.test(before) ? ' ' : '') + tag + (after && !/^\s/.test(after) ? ' ' : (isDelivery && !after ? ' ' : ''));
        textarea.value = before + insert + after;
        var caret = before.length + insert.length;
        textarea.focus();
        textarea.setSelectionRange(caret, caret);
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        chip.classList.add('is-added');
        setTimeout(function () { chip.classList.remove('is-added'); }, 450);
      });
    });

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
      if (!hd && active !== 'settings') showTab('settings');
    }
    if (tier) { tier.addEventListener('change', applyTier); applyTier(); }

    // ---------- Dialect picker: country, then that country's dialects ----------
    var picker = panel.querySelector('[data-vs-dialects]');
    var select = form.querySelector('select[name="dialect"]');
    var countrySelect = panel.querySelector('[data-vs-country]');
    var countryWrap = panel.querySelector('[data-vs-country-wrap]');
    if (picker && select) {
      var countries = [];
      var countryOf = {};
      try { countries = JSON.parse(picker.dataset.countries || '[]'); } catch (e) { countries = []; }
      try { countryOf = JSON.parse(picker.dataset.countryOf || '{}'); } catch (e) { countryOf = {}; }
      var shownCountry = null;

      var optionsOf = function (country) {
        return Array.prototype.filter.call(select.options, function (opt) {
          return opt.value !== '' && (countryOf[opt.value] || '') === country;
        });
      };
      var hasCountries = function () {
        return Array.prototype.some.call(select.options, function (opt) { return !!countryOf[opt.value]; });
      };
      var sync = function () {
        picker.querySelectorAll('[data-dialect]').forEach(function (b) {
          b.setAttribute('aria-checked', b.dataset.dialect === select.value ? 'true' : 'false');
        });
      };
      // Inside a country the country is already shown: "Saudi (Hejazi)" reads as "Hejazi".
      var shortLabel = function (text) {
        var m = /\(([^()]+)\)\s*$/.exec(text || '');
        return m ? m[1].trim() : text;
      };
      var chip = function (opt, short) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'vs-chip vs-chip-dialect';
        b.setAttribute('role', 'radio');
        b.dataset.dialect = opt.value;
        b.textContent = short ? shortLabel(opt.textContent) : opt.textContent;
        b.title = opt.textContent;
        b.disabled = select.disabled;
        b.addEventListener('click', function () {
          select.value = opt.value;
          select.dispatchEvent(new Event('change', { bubbles: true }));
          sync();
        });
        return b;
      };
      var drawChips = function (list, short) {
        picker.innerHTML = '';
        if (!list.length) { picker.hidden = true; return; }
        picker.hidden = false;
        var chips = document.createElement('div');
        chips.className = 'vs-chips';
        list.forEach(function (opt) { chips.appendChild(chip(opt, short)); });
        picker.appendChild(chips);
      };
      var render = function () {
        if (!countrySelect || !hasCountries()) {
          // No countries for this language (English accents): every option as a button.
          if (countryWrap) countryWrap.hidden = true;
          drawChips(Array.prototype.slice.call(select.options));
          shownCountry = null;
          sync();
          return;
        }
        if (countryWrap) countryWrap.hidden = false;
        var country = countryOf[select.value] || '';
        countrySelect.innerHTML = '';
        var auto = document.createElement('option');
        auto.value = '';
        auto.textContent = picker.dataset.autoLabel || '';
        countrySelect.appendChild(auto);
        countries.forEach(function (pair) {
          if (!optionsOf(pair[0]).length) return;
          var o = document.createElement('option');
          o.value = pair[0];
          o.textContent = pair[1];
          countrySelect.appendChild(o);
        });
        countrySelect.value = country;
        countrySelect.disabled = select.disabled;
        shownCountry = country;
        // One dialect only (Fusha, Kuwait…) needs no buttons; the country says it all.
        var list = country ? optionsOf(country) : [];
        drawChips(list.length > 1 ? list : [], true);
        sync();
      };
      if (countrySelect) {
        countrySelect.addEventListener('change', function () {
          var country = countrySelect.value;
          var first = country ? optionsOf(country)[0] : null;
          select.value = first ? first.value : '';
          select.dispatchEvent(new Event('change', { bubbles: true }));
          render();
        });
      }
      // The page rebuilds the options when the language changes, and sets values from code.
      if (window.MutationObserver) {
        new MutationObserver(render).observe(select, { childList: true });
        new MutationObserver(render).observe(select, { attributes: true, attributeFilter: ['disabled'] });
      }
      var onValue = function () {
        if (countrySelect && hasCountries() && (countryOf[select.value] || '') !== shownCountry) render();
        else sync();
      };
      select.addEventListener('change', onValue);
      var lastValue = select.value;
      setInterval(function () { if (select.value !== lastValue) { lastValue = select.value; onValue(); } }, 300);
      render();
    }

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
