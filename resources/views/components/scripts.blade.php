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
      
      var progTrack = document.createElement('div');
      progTrack.className = 'custom-audio-track';

      var progBar = document.createElement('div');
      progBar.className = 'custom-audio-progress';
      
      var progThumb = document.createElement('div');
      progThumb.className = 'custom-audio-thumb';

      progTrack.appendChild(progBar);
      progTrack.appendChild(progThumb);
      progContainer.appendChild(progTrack);
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
      var sanitizeFileSegment = function(value, fallback) {
        var segment = String(value == null ? '' : value).trim();
        if (!segment) return fallback;
        segment = segment.replace(/\s+/g, '-');
        segment = segment.replace(/[\\/:*?"<>|]/g, '');
        segment = segment.replace(/[\u0000-\u001f\u007f]/g, '');
        try {
          segment = segment.replace(new RegExp('[^\\p{L}\\p{N}_+\\-]+', 'gu'), '');
        } catch (_e) {
          segment = segment.replace(/[^A-Za-z0-9_+\-\u0600-\u06FF]+/g, '');
        }
        segment = segment.replace(/[-_.]{2,}/g, '-');
        segment = segment.replace(/^[-_.]+|[-_.]+$/g, '');
        if (!segment) return fallback;
        return segment.slice(0, 32);
      };
      var getDownloadExtension = function(src) {
        var fallback = 'mp3';
        if (!src) return fallback;
        try {
          var parsed = new URL(src, window.location.href);
          var match = String(parsed.pathname || '').match(/\.([a-z0-9]+)$/i);
          if (match && match[1]) return String(match[1]).toLowerCase();
        } catch (_e) {}
        var plain = String(src).split('?')[0].split('#')[0];
        var fallbackMatch = plain.match(/\.([a-z0-9]+)$/i);
        if (fallbackMatch && fallbackMatch[1]) return String(fallbackMatch[1]).toLowerCase();
        return fallback;
      };
      var buildDownloadFilename = function() {
        var src = audio.getAttribute('src') || '';
        var language = sanitizeFileSegment(audio.dataset.downloadLanguage, 'audio');
        var textPart = sanitizeFileSegment(audio.dataset.downloadText, 'sample');
        var speaker = sanitizeFileSegment(audio.dataset.downloadSpeaker, 'speaker');
        var quality = sanitizeFileSegment(audio.dataset.downloadQuality, 'standard');
        var extension = getDownloadExtension(src);
        return [language, textPart, speaker, quality].join('_') + '.' + extension;
      };

      var isDragging = false;
      var progressRafId = 0;
      var getEffectiveDuration = function() {
        var d = Number(audio.duration || 0);
        if (isFinite(d) && d > 0) return d;
        try {
          if (audio.seekable && audio.seekable.length > 0) {
            var seekableEnd = Number(audio.seekable.end(audio.seekable.length - 1) || 0);
            if (isFinite(seekableEnd) && seekableEnd > 0) return seekableEnd;
          }
        } catch (e) {}
        return 0;
      };
      var updateProgress = function() {
        if (isDragging) return;
        var current = Number(audio.currentTime || 0);
        var duration = getEffectiveDuration();
        if (duration <= 0) {
          currTime.textContent = formatTime(current);
          return;
        }
        var progress = Math.max(0, Math.min(100, (current / duration) * 100));
        progBar.style.width = progress + '%';
        progThumb.style.left = progress + '%';
        currTime.textContent = formatTime(current);
      };

      var updateDuration = function() {
        var duration = getEffectiveDuration();
        if (duration > 0) {
          durTime.textContent = formatTime(duration);
        }
      };

      var cancelProgressLoop = function() {
        if (progressRafId) {
          window.cancelAnimationFrame(progressRafId);
          progressRafId = 0;
        }
      };

      var startProgressLoop = function() {
        cancelProgressLoop();
        var tick = function() {
          updateProgress();
          if (!audio.paused && !audio.ended) {
            progressRafId = window.requestAnimationFrame(tick);
          } else {
            progressRafId = 0;
          }
        };
        progressRafId = window.requestAnimationFrame(tick);
      };

      audio.addEventListener('timeupdate', updateProgress);
      audio.addEventListener('loadedmetadata', updateDuration);
      audio.addEventListener('loadeddata', function() {
        updateDuration();
        updateProgress();
      });
      audio.addEventListener('durationchange', function() {
        updateDuration();
        updateProgress();
      });
      audio.addEventListener('canplay', function() {
        updateDuration();
        updateProgress();
      });
      audio.addEventListener('play', function() {
        updateDuration();
        startProgressLoop();
      });
      audio.addEventListener('pause', cancelProgressLoop);
      audio.addEventListener('seeking', updateProgress);
      audio.addEventListener('seeked', updateProgress);
      updateDuration(); // in case already loaded

      audio.addEventListener('ended', function() {
        cancelProgressLoop();
        playBtn.classList.remove('playing');
        playBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
        progBar.style.width = '0%';
        progThumb.style.left = '0%';
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

      var getClientX = function(e) {
        if (e.touches && e.touches.length > 0) return e.touches[0].clientX;
        if (e.changedTouches && e.changedTouches.length > 0) return e.changedTouches[0].clientX;
        return e.clientX || 0;
      };

      var setProgressFromEvent = function(e) {
        var d = getEffectiveDuration();
        if (!d) return 0;
        var rect = progTrack.getBoundingClientRect();
        if (!rect.width) return 0;
        var clientX = getClientX(e);
        var percent = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
        
        progBar.style.width = (percent * 100) + '%';
        progThumb.style.left = (percent * 100) + '%';
        currTime.textContent = formatTime(percent * d);
        return percent;
      };

      var onMove = function(e) {
        if (isDragging) {
           e.preventDefault();
           setProgressFromEvent(e);
        }
      };

      var onUp = function(e) {
        if (isDragging) {
          isDragging = false;
          var percent = setProgressFromEvent(e);
          var d = getEffectiveDuration();
          if (d) {
             audio.currentTime = Math.max(0, Math.min(d, percent * d));
             updateProgress();
          }
          window.removeEventListener('mousemove', onMove);
          window.removeEventListener('touchmove', onMove);
          window.removeEventListener('mouseup', onUp);
          window.removeEventListener('touchend', onUp);
        }
      };

      var onDown = function(e) {
        if (e.cancelable) e.preventDefault();
        var d = getEffectiveDuration();
        if (!d) {
           audio.load();
           return;
        }
        isDragging = true;
        setProgressFromEvent(e);
        window.addEventListener('mousemove', onMove, {passive: false});
        window.addEventListener('touchmove', onMove, {passive: false});
        window.addEventListener('mouseup', onUp);
        window.addEventListener('touchend', onUp);
      };

      progContainer.addEventListener('mousedown', onDown);
      progContainer.addEventListener('touchstart', onDown, {passive: false});
      progContainer.addEventListener('click', function(e) {
        if (isDragging) return;
        var d = getEffectiveDuration();
        if (!d) return;
        var percent = setProgressFromEvent(e);
        audio.currentTime = Math.max(0, Math.min(d, percent * d));
        updateProgress();
      });
      menuBtn.addEventListener('click', function(e) {
         e.preventDefault();
         e.stopPropagation();
         var a = document.createElement('a');
         var src = audio.getAttribute('src');
         if (!src) return;
         a.href = src;
         a.download = buildDownloadFilename();
         a.target = '_blank';
         document.body.appendChild(a);
         a.click();
         document.body.removeChild(a);
      });
      
      var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
          if (mutation.type === 'attributes' && mutation.attributeName === 'src') {
             cancelProgressLoop();
             audio.pause();
             playBtn.classList.remove('playing');
             playBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
             progBar.style.width = '0%';
             progThumb.style.left = '0%';
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
