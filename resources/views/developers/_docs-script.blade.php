<script>
  (function () {
    var STORE = 'listen-docs-language';
    var root = document.querySelector('[data-docs]');
    if (!root) { return; }

    // One code language for the whole page, remembered for the next visit.
    function showLanguage(lang) {
      document.querySelectorAll('[data-code-panel]').forEach(function (panel) {
        var tabs = panel.querySelectorAll('.code-tab');
        if (!tabs.length) { return; }
        var has = panel.querySelector('[data-lang-panel="' + lang + '"]');
        if (!has) { return; }
        tabs.forEach(function (tab) { tab.setAttribute('aria-selected', tab.getAttribute('data-lang') === lang ? 'true' : 'false'); });
        panel.querySelectorAll('[data-lang-panel]').forEach(function (body) { body.hidden = body.getAttribute('data-lang-panel') !== lang; });
      });
      document.querySelectorAll('[data-lang-select]').forEach(function (select) { select.value = lang; });
      try { localStorage.setItem(STORE, lang); } catch (e) {}
    }
    document.querySelectorAll('.code-tab').forEach(function (tab) {
      tab.addEventListener('click', function () { showLanguage(tab.getAttribute('data-lang')); });
    });
    document.querySelectorAll('[data-lang-select]').forEach(function (select) {
      select.addEventListener('change', function () { showLanguage(select.value); });
    });
    try {
      var saved = localStorage.getItem(STORE);
      if (saved) { showLanguage(saved); }
    } catch (e) {}

    // Copy the visible code of a panel.
    document.querySelectorAll('[data-copy]').forEach(function (button) {
      button.addEventListener('click', function () {
        var panel = button.closest('[data-code-panel]');
        var body = panel ? panel.querySelector('.code-body:not([hidden])') : null;
        if (!body || !navigator.clipboard) { return; }
        navigator.clipboard.writeText(body.textContent.replace(/\s+$/, '')).then(function () {
          var label = button.querySelector('span');
          if (!label) { return; }
          label.textContent = button.getAttribute('data-copied-label');
          setTimeout(function () { label.textContent = button.getAttribute('data-copy-label'); }, 1600);
        });
      });
    });

    // Highlight the section being read in the table of contents.
    var links = Array.prototype.slice.call(document.querySelectorAll('[data-nav-link]'));
    if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) { return; }
          var id = entry.target.id;
          links.forEach(function (link) { link.classList.toggle('is-active', link.getAttribute('data-nav-link') === id); });
        });
      }, { rootMargin: '-90px 0px -65% 0px' });
      document.querySelectorAll('[data-section]').forEach(function (section) { observer.observe(section); });
    }

    // Filter the table of contents as the reader types.
    document.querySelectorAll('[data-docs-search]').forEach(function (input) {
      input.addEventListener('input', function () {
        var nav = input.closest('.docs-nav');
        var query = input.value.trim().toLowerCase();
        var any = false;
        nav.querySelectorAll('[data-nav-group]').forEach(function (group) {
          var visible = 0;
          group.querySelectorAll('[data-nav-link]').forEach(function (link) {
            var match = query === '' || link.textContent.toLowerCase().indexOf(query) !== -1;
            link.style.display = match ? '' : 'none';
            if (match) { visible++; }
          });
          group.style.display = visible ? '' : 'none';
          if (visible) { any = true; }
        });
        var empty = nav.querySelector('[data-nav-empty]');
        if (empty) { empty.style.display = any ? 'none' : 'block'; }
      });
    });

    // On phones, close the contents after a link is chosen.
    document.querySelectorAll('.docs-nav-mobile a').forEach(function (link) {
      link.addEventListener('click', function () { link.closest('details').open = false; });
    });
  })();
</script>
