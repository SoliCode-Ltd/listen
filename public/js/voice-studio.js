/*
 * Voice studio panel (components/voice-studio/panel.blade.php), shared by the
 * dashboard generator and the demo page:
 *  - tabs (voice / sound effects / delivery), hidden for standard voices;
 *  - search across both tag tabs, forgiving Arabic spelling;
 *  - tags inserted at the cursor and highlighted inside the text box;
 *  - every dialect as a button, grouped by region, kept in step with the
 *    dialect select, which stays the form field.
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

    // ---------- Search: one query, results from both tag tabs ----------
    function applySearch() {
      var q = fold(searchInput ? searchInput.value : '');
      var tagPanes = panel.querySelectorAll('[data-vs-pane="sounds"], [data-vs-pane="delivery"]');
      if (!q) {
        tagPanes.forEach(function (pane) {
          pane.querySelectorAll('.vs-chip').forEach(function (c) { c.hidden = false; });
          pane.querySelectorAll('[data-vs-group]').forEach(function (g) { g.hidden = false; });
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
        pane.querySelectorAll('[data-vs-group]').forEach(function (group) {
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

    // ---------- Dialect picker ----------
    var picker = panel.querySelector('[data-vs-dialects]');
    var select = form.querySelector('select[name="dialect"]');
    var current = panel.querySelector('[data-vs-dialect-current]');
    if (picker && select) {
      var regions = {};
      var regionOf = {};
      try { regions = JSON.parse(picker.dataset.regions || '{}'); } catch (e) { regions = {}; }
      try { regionOf = JSON.parse(picker.dataset.regionOf || '{}'); } catch (e) { regionOf = {}; }
      var sync = function () {
        picker.querySelectorAll('[data-dialect]').forEach(function (b) {
          b.setAttribute('aria-checked', b.dataset.dialect === select.value ? 'true' : 'false');
        });
        var picked = select.options[select.selectedIndex];
        if (current) current.textContent = picked && picked.value ? picked.textContent : '';
      };
      var render = function () {
        var groups = [];
        var byKey = {};
        Array.prototype.forEach.call(select.options, function (opt) {
          var key = opt.value === '' ? '_auto' : (regionOf[opt.value] || '_other');
          if (!byKey[key]) { byKey[key] = []; groups.push(key); }
          byKey[key].push(opt);
        });
        picker.innerHTML = '';
        groups.forEach(function (key) {
          var group = document.createElement('div');
          group.className = 'vs-group';
          if (regions[key]) {
            var label = document.createElement('div');
            label.className = 'vs-group-label';
            label.textContent = regions[key];
            group.appendChild(label);
          }
          var chips = document.createElement('div');
          chips.className = 'vs-chips';
          byKey[key].forEach(function (opt) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'vs-chip vs-chip-dialect';
            b.setAttribute('role', 'radio');
            b.dataset.dialect = opt.value;
            b.textContent = opt.textContent;
            b.disabled = select.disabled;
            b.addEventListener('click', function () {
              select.value = opt.value;
              select.dispatchEvent(new Event('change', { bubbles: true }));
              sync();
            });
            chips.appendChild(b);
          });
          group.appendChild(chips);
          picker.appendChild(group);
        });
        sync();
      };
      // The page rebuilds the options when the language changes, and sets values from code.
      if (window.MutationObserver) {
        new MutationObserver(render).observe(select, { childList: true });
        new MutationObserver(render).observe(select, { attributes: true, attributeFilter: ['disabled'] });
      }
      select.addEventListener('change', sync);
      var lastValue = select.value;
      setInterval(function () { if (select.value !== lastValue) { lastValue = select.value; sync(); } }, 300);
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

    showTab('settings');
  }

  function boot() {
    document.querySelectorAll('[data-vs-panel]').forEach(initPanel);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  window.VoiceStudio = { init: boot, fold: fold };
})();
