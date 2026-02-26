<script id="listen-shared-scripts">
(function() {
  if (window.__listenSharedScriptsBooted) return;
  window.__listenSharedScriptsBooted = true;

  function safeGetTheme() {
    try {
      return localStorage.getItem('listen_theme');
    } catch (e) {
      return null;
    }
  }

  function safeSetTheme(theme) {
    try {
      localStorage.setItem('listen_theme', theme);
    } catch (e) {}
  }

  var serverDefault = document.documentElement.getAttribute('data-theme') || 'dark';
  var savedTheme = safeGetTheme();
  document.documentElement.setAttribute(
    'data-theme',
    savedTheme || serverDefault
  );

  function initThemeToggle() {
    var themeToggles = document.querySelectorAll('[data-theme-toggle]');
    if (!themeToggles.length) return;

    Array.prototype.forEach.call(themeToggles, function(themeToggle) {
      if (themeToggle.dataset.listenThemeBound === '1') return;
      themeToggle.dataset.listenThemeBound = '1';

      themeToggle.addEventListener('click', function(e) {
        e.preventDefault();
        var current = document.documentElement.getAttribute('data-theme') || 'dark';
        var next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        safeSetTheme(next);
      });
    });
  }

  function initUserMenu() {
    var userMenuTrigger = document.getElementById('userMenuTrigger');
    var userMenu = document.getElementById('userMenu');
    if (!userMenuTrigger || !userMenu) return;

    userMenuTrigger.addEventListener('click', function(e) {
      e.stopPropagation();
      var isOpen = userMenu.classList.toggle('open');
      userMenuTrigger.setAttribute('aria-expanded', isOpen);
    });

    document.addEventListener('click', function(e) {
      if (!userMenu.contains(e.target)) {
        userMenu.classList.remove('open');
        userMenuTrigger.setAttribute('aria-expanded', 'false');
      }
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && userMenu.classList.contains('open')) {
        userMenu.classList.remove('open');
        userMenuTrigger.setAttribute('aria-expanded', 'false');
        userMenuTrigger.focus();
      }
    });
  }

  function initMobileMenu() {
    var mobileToggle = document.getElementById('mobileMenuToggle');
    var mobileNav = document.getElementById('mobileNav');
    var mobileClose = document.getElementById('mobileMenuClose');
    if (!mobileToggle || !mobileNav) return;
    if (mobileToggle.dataset.listenBound === '1') return;
    mobileToggle.dataset.listenBound = '1';

    function setMobileMenuState(isOpen) {
      if (isOpen) {
        mobileNav.classList.add('open');
        document.documentElement.classList.add('mobile-menu-open');
        document.body.classList.add('mobile-menu-open');
        document.body.style.overflow = 'hidden';
      } else {
        mobileNav.classList.remove('open');
        document.documentElement.classList.remove('mobile-menu-open');
        document.body.classList.remove('mobile-menu-open');
        document.body.style.overflow = '';
      }
      mobileToggle.setAttribute('aria-expanded', String(isOpen));
      mobileNav.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    }

    function closeMobileMenu(focusToggle) {
      setMobileMenuState(false);
      if (focusToggle) {
        mobileToggle.focus();
      }
    }

    setMobileMenuState(false);

    mobileToggle.addEventListener('click', function() {
      setMobileMenuState(!mobileNav.classList.contains('open'));
    });

    var mobileLinks = mobileNav.querySelectorAll('a, button');
    Array.prototype.forEach.call(mobileLinks, function(link) {
      link.addEventListener('click', function() {
        if (link.hasAttribute('data-mobile-menu-stay-open')) {
          return;
        }
        closeMobileMenu(false);
      });
    });

    if (mobileClose) {
      mobileClose.addEventListener('click', function() {
        closeMobileMenu(true);
      });
    }

    mobileNav.addEventListener('click', function(e) {
      if (e.target === mobileNav) {
        closeMobileMenu(false);
      }
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && mobileNav.classList.contains('open')) {
        closeMobileMenu(true);
      }
    });

    window.addEventListener('resize', function() {
      if (window.innerWidth > 960 && mobileNav.classList.contains('open')) {
        closeMobileMenu(false);
      }
    });

    window.addEventListener('pageshow', function() {
      closeMobileMenu(false);
    });
  }

  function initRevealAnimations() {
    if (window.innerWidth <= 960) {
      var mobileRevealElements = document.querySelectorAll('.reveal');
      Array.prototype.forEach.call(mobileRevealElements, function(el) {
        el.classList.add('show');
      });
      return;
    }

    if (!('IntersectionObserver' in window)) {
      var revealFallback = document.querySelectorAll('.reveal');
      Array.prototype.forEach.call(revealFallback, function(el) {
        el.classList.add('show');
      });
      return;
    }

    var observer = new IntersectionObserver(function(entries) {
      for (var i = 0; i < entries.length; i++) {
        if (entries[i].isIntersecting) {
          entries[i].target.classList.add('show');
        }
      }
    }, { threshold: 0.12 });

    var revealElements = document.querySelectorAll('.reveal');
    Array.prototype.forEach.call(revealElements, function(el) {
      observer.observe(el);
    });
  }

  window.initCustomAudioPlayers = function() {
    var audios = document.querySelectorAll('audio:not(.initialized)');
    Array.prototype.forEach.call(audios, function(audio) {
      audio.classList.add('initialized');
      audio.style.display = 'none';
      audio.removeAttribute('controls');

      var wrapper = document.createElement('div');
      wrapper.className = 'custom-audio-wrapper';

      var playBtn = document.createElement('button');
      playBtn.className = 'custom-audio-play';
      playBtn.type = 'button';
      playBtn.setAttribute('aria-label', 'Play');
      playBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';

      var currTime = document.createElement('span');
      currTime.className = 'custom-audio-time';
      currTime.textContent = '0:00';

      var progContainer = document.createElement('div');
      progContainer.className = 'custom-audio-progress-container';
      
      var progBar = document.createElement('div');
      progBar.className = 'custom-audio-progress';
      progContainer.appendChild(progBar);

      var durTime = document.createElement('span');
      durTime.className = 'custom-audio-time end';
      durTime.textContent = '0:00';

      var menuBtn = document.createElement('button');
      menuBtn.className = 'custom-audio-menu';
      menuBtn.type = 'button';
      menuBtn.title = 'Download Audio';
      menuBtn.setAttribute('aria-label', 'Download Audio');
      menuBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';

      wrapper.appendChild(playBtn);
      wrapper.appendChild(currTime);
      wrapper.appendChild(progContainer);
      wrapper.appendChild(durTime);
      wrapper.appendChild(menuBtn);

      audio.parentNode.insertBefore(wrapper, audio.nextSibling);

      var formatTime = function(seconds) {
        if (!seconds || isNaN(seconds) || seconds === Infinity) return '0:00';
        var m = Math.floor(seconds / 60);
        var s = Math.floor(seconds % 60);
        return m + ':' + (s < 10 ? '0' : '') + s;
      };

      var updateProgress = function() {
        var duration = audio.duration || 1;
        var progress = (audio.currentTime / duration) * 100;
        progBar.style.width = progress + '%';
        currTime.textContent = formatTime(audio.currentTime);
      };

      var updateDuration = function() {
        if (audio.duration && audio.duration !== Infinity) {
          durTime.textContent = formatTime(audio.duration);
        }
      };

      audio.addEventListener('timeupdate', updateProgress);
      audio.addEventListener('loadedmetadata', updateDuration);
      audio.addEventListener('durationchange', updateDuration);
      updateDuration(); // in case already loaded

      audio.addEventListener('ended', function() {
        playBtn.classList.remove('playing');
        playBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
        progBar.style.width = '0%';
        currTime.textContent = '0:00';
      });

      playBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (audio.paused) {
          var others = document.querySelectorAll('audio.initialized');
          for (var i = 0; i < others.length; i++) {
             if (others[i] !== audio) others[i].pause();
          }
          var otherBtns = document.querySelectorAll('.custom-audio-play.playing');
          for (var j = 0; j < otherBtns.length; j++) {
             otherBtns[j].classList.remove('playing');
             otherBtns[j].innerHTML = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
          }

          var playPromise = audio.play();
          if (playPromise !== undefined) {
             playPromise.then(function() {
                playBtn.classList.add('playing');
                playBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
             }).catch(function(error) {
                console.log('Playback prevented', error);
             });
          }
        } else {
          audio.pause();
          playBtn.classList.remove('playing');
          playBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
        }
      });

      progContainer.addEventListener('click', function(e) {
        if (!audio.duration || audio.duration === Infinity) return;
        var rect = progContainer.getBoundingClientRect();
        var percent = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        audio.currentTime = percent * audio.duration;
        updateProgress();
      });

      menuBtn.addEventListener('click', function(e) {
         e.preventDefault();
         e.stopPropagation();
         var a = document.createElement('a');
         var src = audio.getAttribute('src');
         if (!src) return;
         a.href = src;
         a.download = src.substring(src.lastIndexOf('/') + 1) || 'download.mp3';
         a.target = '_blank';
         document.body.appendChild(a);
         a.click();
         document.body.removeChild(a);
      });
      
      var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
          if (mutation.type === 'attributes' && mutation.attributeName === 'src') {
             audio.pause();
             playBtn.classList.remove('playing');
             playBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
             progBar.style.width = '0%';
             currTime.textContent = '0:00';
             durTime.textContent = '0:00';
          }
        });
      });
      observer.observe(audio, { attributes: true });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      initThemeToggle();
      initUserMenu();
      initMobileMenu();
      initRevealAnimations();
      window.initCustomAudioPlayers();
    });
  } else {
    initThemeToggle();
    initUserMenu();
    initMobileMenu();
    initRevealAnimations();
    window.initCustomAudioPlayers();
  }
})();
</script>
