<script>
  (function () {
    var STORE = 'listen-docs-language';
    var root = document.querySelector('[data-docs]');
    if (!root) { return; }

    // One code language for the whole page, remembered for the next visit.
    function showLanguage(lang) {
      document.querySelectorAll('[data-code-panel]').forEach(function (panel) {
        var tabs = panel.querySelectorAll('.code-tab');
        if (!tabs.length || !panel.querySelector('[data-lang-panel="' + lang + '"]')) { return; }
        tabs.forEach(function (tab) {
          var on = tab.getAttribute('data-lang') === lang;
          tab.setAttribute('aria-selected', on ? 'true' : 'false');
          tab.setAttribute('tabindex', on ? '0' : '-1');
        });
        panel.querySelectorAll('[data-lang-panel]').forEach(function (body) { body.hidden = body.getAttribute('data-lang-panel') !== lang; });
      });
      try { localStorage.setItem(STORE, lang); } catch (e) {}
    }
    document.querySelectorAll('.code-tab').forEach(function (tab) {
      tab.addEventListener('click', function () { showLanguage(tab.getAttribute('data-lang')); });
      // Arrow keys move between the languages of a panel.
      tab.addEventListener('keydown', function (event) {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') { return; }
        var tabs = Array.prototype.slice.call(tab.parentNode.querySelectorAll('.code-tab'));
        var next = tabs[(tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
        event.preventDefault();
        showLanguage(next.getAttribute('data-lang'));
        next.focus();
      });
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

    // The voice sample in the hero: play and pause, the waveform follows.
    document.querySelectorAll('[data-sample]').forEach(function (sample) {
      var button = sample.querySelector('[data-sample-play]');
      var audio = sample.querySelector('[data-sample-audio]');
      var bars = sample.querySelectorAll('[data-sample-wave] i');
      if (!button || !audio) { return; }
      function paint() {
        var played = audio.duration ? Math.round(bars.length * audio.currentTime / audio.duration) : 0;
        bars.forEach(function (bar, index) { bar.classList.toggle('is-played', index < played); });
      }
      function state(playing) {
        button.setAttribute('aria-pressed', playing ? 'true' : 'false');
        button.setAttribute('aria-label', button.getAttribute(playing ? 'data-label-pause' : 'data-label-play'));
      }
      button.addEventListener('click', function () {
        if (audio.paused) { audio.play().catch(function () { state(false); }); } else { audio.pause(); }
      });
      audio.addEventListener('play', function () { state(true); });
      audio.addEventListener('pause', function () { state(false); });
      audio.addEventListener('timeupdate', paint);
      audio.addEventListener('ended', function () { state(false); audio.currentTime = 0; paint(); });
    });

    // Mark the section being read in the contents.
    var links = Array.prototype.slice.call(document.querySelectorAll('[data-nav-link]'));
    if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) { return; }
          var id = entry.target.id;
          links.forEach(function (link) {
            var on = link.getAttribute('data-nav-link') === id;
            link.classList.toggle('is-active', on);
            if (on) { link.setAttribute('aria-current', 'location'); } else { link.removeAttribute('aria-current'); }
          });
        });
      }, { rootMargin: '-90px 0px -65% 0px' });
      document.querySelectorAll('[data-section]').forEach(function (section) { observer.observe(section); });
    }

    // Filter the contents as the reader types.
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
