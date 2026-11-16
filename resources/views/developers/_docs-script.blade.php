<script>
  (function () {
    var STORE = 'listen-docs-language';
    var root = document.querySelector('[data-docs]');
    if (!root) { return; }

    // The bar and the side contents sit just under the site's header, whatever its height.
    var siteHeader = document.querySelector('header.app-header');
    function measureHeader() {
      if (!siteHeader) { return; }
      var box = siteHeader.getBoundingClientRect();
      var fixed = /fixed|sticky/.test(getComputedStyle(siteHeader).position);
      root.style.setProperty('--docs-top', (fixed ? Math.round(box.height) : 0) + 'px');
    }
    measureHeader();
    window.addEventListener('resize', measureHeader);

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

    // The voice at the top: play and pause; the waveform fills up to the playhead on every
    // animation frame, a click on it jumps there, and the time beside it counts while it plays.
    function clock(seconds) {
      var s = Math.max(0, Math.floor(seconds));
      return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
    }
    document.querySelectorAll('[data-sample]').forEach(function (sample) {
      var button = sample.querySelector('[data-sample-play]');
      var audio = sample.querySelector('[data-sample-audio]');
      var wave = sample.querySelector('[data-sample-wave]');
      var time = sample.querySelector('[data-sample-time]');
      if (!button || !audio || !wave) { return; }
      var total = time ? parseInt(time.getAttribute('data-total'), 10) || 0 : 0;
      var frame = 0;
      var pendingSeek = null;
      function paint() {
        var ratio = audio.duration ? Math.min(1, audio.currentTime / audio.duration) : 0;
        wave.style.setProperty('--p', (ratio * 100).toFixed(3) + '%');
        if (time) { time.textContent = sample.classList.contains('is-started') ? clock(audio.currentTime) : (total ? clock(total) : ''); }
      }
      function loop() {
        paint();
        if (!audio.paused && !audio.ended) { frame = requestAnimationFrame(loop); }
      }
      function state(playing) {
        sample.classList.toggle('is-playing', playing);
        button.setAttribute('aria-pressed', playing ? 'true' : 'false');
        button.setAttribute('aria-label', button.getAttribute(playing ? 'data-label-pause' : 'data-label-play'));
      }
      button.addEventListener('click', function () {
        if (audio.paused) { audio.play().catch(function () { state(false); }); } else { audio.pause(); }
      });
      wave.addEventListener('click', function (event) {
        var box = wave.getBoundingClientRect();
        var ratio = Math.max(0, Math.min(1, (event.clientX - box.left) / box.width));
        if (audio.duration) {
          audio.currentTime = ratio * audio.duration;
          paint();
        } else {
          pendingSeek = ratio;
        }
        if (audio.paused) { audio.play().catch(function () { state(false); }); }
      });
      audio.addEventListener('loadedmetadata', function () {
        if (pendingSeek !== null) { audio.currentTime = pendingSeek * audio.duration; pendingSeek = null; }
        paint();
      });
      audio.addEventListener('play', function () {
        sample.classList.add('is-started');
        state(true);
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(loop);
      });
      audio.addEventListener('pause', function () { state(false); cancelAnimationFrame(frame); paint(); });
      audio.addEventListener('seeked', paint);
      audio.addEventListener('ended', function () {
        state(false);
        cancelAnimationFrame(frame);
        sample.classList.remove('is-started');
        audio.currentTime = 0;
        paint();
      });
    });

    // The section being read: marked in the contents, and named in the bar at the top.
    var links = Array.prototype.slice.call(document.querySelectorAll('[data-nav-link]'));
    var current = root.querySelector('[data-docs-current]');
    function nameOf(link) {
      var spans = link.querySelectorAll('span');
      return (spans.length ? spans[spans.length - 1].textContent : link.textContent).trim();
    }
    if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) { return; }
          var id = entry.target.id;
          var named = false;
          links.forEach(function (link) {
            var on = link.getAttribute('data-nav-link') === id;
            link.classList.toggle('is-active', on);
            if (on) {
              link.setAttribute('aria-current', 'location');
              if (current && !named) { current.textContent = nameOf(link); named = true; }
            } else {
              link.removeAttribute('aria-current');
            }
          });
        });
      }, { rootMargin: '-140px 0px -60% 0px' });
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
          group.querySelectorAll('[data-nav-resource]').forEach(function (resource) {
            // A resource whose name matches shows all its endpoints; otherwise only those that match.
            var whole = query !== '' && resource.querySelector('.docs-nav-resource-name').textContent.toLowerCase().indexOf(query) !== -1;
            var shown = 0;
            resource.querySelectorAll('.docs-nav-endpoint').forEach(function (link) {
              var match = query === '' || whole || link.textContent.toLowerCase().indexOf(query) !== -1;
              link.style.display = match ? '' : 'none';
              if (match) { shown++; }
            });
            resource.style.display = shown || whole ? '' : 'none';
            visible += shown + (whole ? 1 : 0);
          });
          group.querySelectorAll(':scope > [data-nav-link]').forEach(function (link) {
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

    // Phones and tablets: the contents open as a sheet from the side, over the page.
    var sheet = root.querySelector('[data-docs-sheet]');
    var opener = root.querySelector('[data-docs-open]');
    function closeSheet(focusBack) {
      if (!sheet || sheet.hidden) { return; }
      sheet.hidden = true;
      document.documentElement.classList.remove('docs-sheet-open');
      if (opener) { opener.setAttribute('aria-expanded', 'false'); if (focusBack) { opener.focus(); } }
    }
    if (sheet && opener) {
      opener.addEventListener('click', function () {
        sheet.hidden = false;
        document.documentElement.classList.add('docs-sheet-open');
        opener.setAttribute('aria-expanded', 'true');
        var active = sheet.querySelector('a.is-active');
        if (active) { active.scrollIntoView({ block: 'center' }); }
        var close = sheet.querySelector('[data-docs-close]');
        if (close) { close.focus(); }
      });
      sheet.addEventListener('click', function (event) {
        if (event.target === sheet || event.target.closest('[data-docs-close]')) { closeSheet(true); return; }
        if (event.target.closest('a[href^="#"]')) { closeSheet(false); }
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !sheet.hidden) { closeSheet(true); }
        // Tab stays inside the sheet while it is open.
        if (event.key === 'Tab' && !sheet.hidden) {
          var stops = Array.prototype.filter.call(sheet.querySelectorAll('a, button, input'), function (el) { return el.offsetParent !== null; });
          if (!stops.length) { return; }
          var first = stops[0], last = stops[stops.length - 1];
          if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
          else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
      });
      // Back to the wide layout: never leave the page locked under a closed sheet.
      window.addEventListener('resize', function () { if (window.innerWidth >= 1200) { closeSheet(false); } });
    }
  })();
</script>
