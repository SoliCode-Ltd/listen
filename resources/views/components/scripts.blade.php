@php
  $sharedI18n = [
    'audio_play' => __('ui.scripts.audio_play'),
    'audio_download' => __('ui.scripts.audio_download'),
    'audio_download_as' => __('ui.scripts.audio_download_as'),
    'audio_download_failed' => __('ui.scripts.audio_download_failed'),
    'audio_play_failed' => __('ui.scripts.audio_play_failed'),
    'audio_download_unavailable' => __('ui.scripts.audio_download_unavailable'),
    'audio_pause' => __('ui.scripts.audio_pause'),
    'audio_seek' => __('ui.scripts.audio_seek'),
    'audio_position' => __('ui.scripts.audio_position'),
  ];
  // The file types this member's plan offers for download (Plans → Downloads).
  $downloadFormats = rescue(fn () => \App\Support\AudioFormats::menuFor(
    auth()->check() ? app(\App\Services\PlanAccessService::class)->resolveActivePlanForUserId((int) auth()->id()) : null
  ), \App\Support\AudioFormats::menuFor(null), false);
@endphp

<script id="listen-shared-scripts">
(function() {
  if (window.__listenSharedScriptsBooted) return;
  window.__listenSharedScriptsBooted = true;
  var i18n = @json($sharedI18n);
  var downloadFormats = @json($downloadFormats);
  var serverDownloadUrl = @json(route('audio.download', [], false));

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
        if (link.classList && link.classList.contains('locale-link')) {
          return;
        }
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
      // Same width as the header's menu-button breakpoint (styles: max-width 1180px).
      if (window.innerWidth > 1180 && mobileNav.classList.contains('open')) {
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

  var lameScriptUrl = @json(asset('js/lame.min.js'));
  var lameLoader = null;

  function ensureMp3Encoder() {
    if (window.lamejs && window.lamejs.Mp3Encoder) return Promise.resolve();
    if (lameLoader) return lameLoader;
    lameLoader = new Promise(function(resolve, reject) {
      var script = document.createElement('script');
      script.src = lameScriptUrl;
      script.async = true;
      script.onload = function() {
        if (window.lamejs && window.lamejs.Mp3Encoder) resolve();
        else reject(new Error('encoder'));
      };
      script.onerror = function() { reject(new Error('encoder')); };
      document.head.appendChild(script);
    });
    return lameLoader;
  }

  function audioContainer(bytes) {
    if (bytes.length >= 12 && bytes[0] === 0x52 && bytes[1] === 0x49 && bytes[2] === 0x46 && bytes[3] === 0x46 && bytes[8] === 0x57 && bytes[9] === 0x41 && bytes[10] === 0x56 && bytes[11] === 0x45) return 'wav';
    if (bytes.length >= 3 && bytes[0] === 0x49 && bytes[1] === 0x44 && bytes[2] === 0x33) return 'mp3';
    if (bytes.length >= 2 && bytes[0] === 0xFF && (bytes[1] & 0xE0) === 0xE0) return 'mp3';
    if (bytes.length >= 8 && bytes[4] === 0x66 && bytes[5] === 0x74 && bytes[6] === 0x79 && bytes[7] === 0x70) return 'm4a';
    if (bytes.length >= 4 && bytes[0] === 0x4F && bytes[1] === 0x67 && bytes[2] === 0x67 && bytes[3] === 0x53) return 'ogg';
    return 'wav';
  }

  function floatToPcm16(samples) {
    var pcm = new Int16Array(samples.length);
    for (var i = 0; i < samples.length; i++) {
      var value = Math.max(-1, Math.min(1, samples[i]));
      pcm[i] = value < 0 ? value * 0x8000 : value * 0x7FFF;
    }
    return pcm;
  }

  function encodePcmToMp3(samples, sampleRate) {
    var encoder = new window.lamejs.Mp3Encoder(1, sampleRate, 192);
    var block = 1152;
    var chunks = [];
    for (var offset = 0; offset < samples.length; offset += block) {
      var slice = samples.subarray(offset, offset + block);
      var encoded = encoder.encodeBuffer(slice, slice);
      if (encoded && encoded.length) chunks.push(encoded);
    }
    var end = encoder.flush();
    if (end && end.length) chunks.push(end);
    return new Blob(chunks, { type: 'audio/mpeg' });
  }

  function decodeToMono(buffer) {
    var Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return Promise.reject(new Error('audio'));
    var context = new Ctx();
    return context.decodeAudioData(buffer.slice(0)).then(function(decoded) {
      var mixed = new Float32Array(decoded.length);
      var channels = decoded.numberOfChannels || 1;
      for (var channel = 0; channel < channels; channel++) {
        var data = decoded.getChannelData(channel);
        for (var i = 0; i < decoded.length; i++) mixed[i] += data[i] / channels;
      }
      if (context.close) context.close();
      return { samples: mixed, sampleRate: decoded.sampleRate || 24000 };
    }).catch(function(error) {
      if (context.close) context.close();
      throw error;
    });
  }

  // 16-bit mono WAV, made in the browser.
  function toWavAudio(buffer) {
    var bytes = new Uint8Array(buffer);
    if (audioContainer(bytes) === 'wav' && bytes.length > 44) {
      return Promise.resolve({ blob: new Blob([buffer], { type: 'audio/wav' }), extension: 'wav' });
    }
    return decodeToMono(buffer).then(function(audio) {
      var pcm = floatToPcm16(audio.samples);
      var header = new DataView(new ArrayBuffer(44));
      var writeText = function(offset, text) { for (var i = 0; i < text.length; i++) header.setUint8(offset + i, text.charCodeAt(i)); };
      writeText(0, 'RIFF');
      header.setUint32(4, 36 + pcm.length * 2, true);
      writeText(8, 'WAVE');
      writeText(12, 'fmt ');
      header.setUint32(16, 16, true);
      header.setUint16(20, 1, true);
      header.setUint16(22, 1, true);
      header.setUint32(24, audio.sampleRate, true);
      header.setUint32(28, audio.sampleRate * 2, true);
      header.setUint16(32, 2, true);
      header.setUint16(34, 16, true);
      writeText(36, 'data');
      header.setUint32(40, pcm.length * 2, true);
      return { blob: new Blob([header.buffer, pcm.buffer], { type: 'audio/wav' }), extension: 'wav' };
    });
  }

  window.toWhatsAppAudio = toWhatsAppAudio;
  function toWhatsAppAudio(buffer) {
    var bytes = new Uint8Array(buffer);
    var kind = audioContainer(bytes);
    if (kind === 'mp3' || kind === 'm4a' || kind === 'ogg') {
      var type = kind === 'mp3' ? 'audio/mpeg' : (kind === 'm4a' ? 'audio/mp4' : 'audio/ogg');
      return Promise.resolve({ blob: new Blob([buffer], { type: type }), extension: kind });
    }

    return ensureMp3Encoder().then(function() {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) throw new Error('audio');
      var context = new Ctx();
      return context.decodeAudioData(buffer.slice(0)).then(function(decoded) {
        var mixed = new Float32Array(decoded.length);
        var channels = decoded.numberOfChannels || 1;
        for (var channel = 0; channel < channels; channel++) {
          var data = decoded.getChannelData(channel);
          for (var i = 0; i < decoded.length; i++) mixed[i] += data[i] / channels;
        }
        var blob = encodePcmToMp3(floatToPcm16(mixed), decoded.sampleRate || 24000);
        if (context.close) context.close();
        return { blob: blob, extension: 'mp3' };
      }).catch(function(error) {
        if (context.close) context.close();
        throw error;
      });
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
      playBtn.setAttribute('aria-label', i18n.audio_play || 'Play');
      playBtn.innerHTML = '<svg viewBox="0 0 24 24"><path d="M7.5 5v14l11-7z"/></svg>';
      var allowDownload = audio.dataset.downloadDisabled !== '1' && audio.dataset.downloadAllowed !== '0';

      var currTime = document.createElement('span');
      currTime.className = 'custom-audio-time';
      currTime.textContent = '0:00';

      var progContainer = document.createElement('div');
      progContainer.className = 'custom-audio-progress-container';
      // A slider for the keyboard and screen readers too (keys below).
      progContainer.tabIndex = 0;
      progContainer.setAttribute('role', 'slider');
      progContainer.setAttribute('aria-label', i18n.audio_seek);
      progContainer.setAttribute('aria-valuemin', '0');
      progContainer.setAttribute('aria-valuemax', '0');
      progContainer.setAttribute('aria-valuenow', '0');

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

      var menuBtn = null;
      if (allowDownload) {
        menuBtn = document.createElement('button');
        menuBtn.className = 'custom-audio-menu';
        menuBtn.type = 'button';
        menuBtn.title = i18n.audio_download || 'Download Audio';
        menuBtn.setAttribute('aria-label', i18n.audio_download || 'Download Audio');
        menuBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';
      }

      wrapper.appendChild(playBtn);
      wrapper.appendChild(currTime);
      wrapper.appendChild(progContainer);
      wrapper.appendChild(durTime);
      if (menuBtn) {
        wrapper.appendChild(menuBtn);
      }

      // Says on the player's own row when its audio cannot be played or saved.
      var notice = document.createElement('small');
      notice.className = 'custom-audio-notice';
      notice.setAttribute('role', 'status');
      var showNotice = function(text) {
        notice.textContent = text || '';
      };

      audio.parentNode.insertBefore(wrapper, audio.nextSibling);
      wrapper.parentNode.insertBefore(notice, wrapper.nextSibling);

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
      var buildDownloadFilename = function(extension) {
        var src = audio.getAttribute('src') || '';
        var language = sanitizeFileSegment(audio.dataset.downloadLanguage, 'audio');
        var textPart = sanitizeFileSegment(audio.dataset.downloadText, 'sample');
        var speaker = sanitizeFileSegment(audio.dataset.downloadSpeaker, 'speaker');
        var quality = sanitizeFileSegment(audio.dataset.downloadQuality, 'standard');
        var chosen = String(extension || '').toLowerCase() || getDownloadExtension(src);
        return [language, textPart, speaker, quality].join('_') + '.' + chosen;
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
      // What the slider tells screen readers, e.g. "0:12 of 1:30"; written only when a whole second changes.
      var setSliderValue = function(current, duration) {
        var now = String(Math.floor(current || 0));
        var max = String(Math.floor(duration || 0));
        if (progContainer.getAttribute('aria-valuenow') === now && progContainer.getAttribute('aria-valuemax') === max) return;
        progContainer.setAttribute('aria-valuemax', max);
        progContainer.setAttribute('aria-valuenow', now);
        progContainer.setAttribute('aria-valuetext', String(i18n.audio_position).replace(':current', formatTime(current)).replace(':total', formatTime(duration)));
      };
      var updateProgress = function() {
        if (isDragging) return;
        var current = Number(audio.currentTime || 0);
        var duration = getEffectiveDuration();
        setSliderValue(current, duration);
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
          setSliderValue(Number(audio.currentTime || 0), duration);
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
      var playIconMarkup = '<svg viewBox="0 0 24 24"><path d="M7.5 5v14l11-7z"/></svg>';
      var pauseIconMarkup = '<svg viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
      var setPlayButtonState = function(isPlaying) {
        playBtn.classList.toggle('playing', !!isPlaying);
        playBtn.innerHTML = isPlaying ? pauseIconMarkup : playIconMarkup;
        playBtn.setAttribute('aria-label', isPlaying ? i18n.audio_pause : i18n.audio_play);
      };
      var playRequestId = 0;
      var pendingPlayPromise = null;

      audio.addEventListener('play', function() {
        updateDuration();
        startProgressLoop();
        setPlayButtonState(true);
      });
      audio.addEventListener('pause', function() {
        cancelProgressLoop();
        setPlayButtonState(false);
      });
      audio.addEventListener('seeking', updateProgress);
      audio.addEventListener('seeked', updateProgress);
      updateDuration(); // in case already loaded

      audio.addEventListener('ended', function() {
        cancelProgressLoop();
        setPlayButtonState(false);
        progBar.style.width = '0%';
        progThumb.style.left = '0%';
        currTime.textContent = '0:00';
        setSliderValue(0, getEffectiveDuration());
      });

      // A file that is gone or cannot be read: the row says so instead of a
      // button that does nothing. Code 1 is a load the page itself stopped.
      audio.addEventListener('error', function() {
        if (!audio.getAttribute('src') || !audio.error || audio.error.code === 1) return;
        cancelProgressLoop();
        setPlayButtonState(false);
        showNotice(i18n.audio_play_failed);
      });
      audio.addEventListener('playing', function() {
        showNotice('');
      });

      playBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (pendingPlayPromise) {
          playRequestId += 1;
          pendingPlayPromise = null;
          audio.pause();
          setPlayButtonState(false);
          return;
        }
        if (audio.paused) {
          var others = document.querySelectorAll('audio.initialized');
          for (var i = 0; i < others.length; i++) {
             if (others[i] !== audio) others[i].pause();
          }
          var otherBtns = document.querySelectorAll('.custom-audio-play.playing');
          for (var j = 0; j < otherBtns.length; j++) {
             otherBtns[j].classList.remove('playing');
             otherBtns[j].innerHTML = playIconMarkup;
             otherBtns[j].setAttribute('aria-label', i18n.audio_play);
          }

          var requestId = ++playRequestId;
          // After a failed load, pressing play tries the file again.
          if (audio.error) audio.load();
          var playPromise = audio.play();
          if (playPromise !== undefined) {
             pendingPlayPromise = playPromise;
             playPromise.then(function() {
                if (requestId !== playRequestId) return;
                setPlayButtonState(!audio.paused);
             }).catch(function(error) {
                if (requestId !== playRequestId) return;
                if (!(error && error.name === 'AbortError')) {
                  console.warn('Audio playback failed', error);
                }
                if (error && error.name === 'NotSupportedError') showNotice(i18n.audio_play_failed);
                setPlayButtonState(false);
             }).finally(function() {
                if (requestId !== playRequestId) return;
                pendingPlayPromise = null;
             });
          } else {
            setPlayButtonState(!audio.paused);
          }
        } else {
          playRequestId += 1;
          pendingPlayPromise = null;
          audio.pause();
          setPlayButtonState(false);
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
      // On the bar: Space or Enter plays and pauses, the arrows move 5 seconds,
      // Home and End go to the start and the end.
      progContainer.addEventListener('keydown', function(e) {
        if (e.key === ' ' || e.key === 'Enter') {
          e.preventDefault();
          playBtn.click();
          return;
        }
        var step = { ArrowRight: 5, ArrowUp: 5, ArrowLeft: -5, ArrowDown: -5 }[e.key];
        if (step === undefined && e.key !== 'Home' && e.key !== 'End') return;
        e.preventDefault();
        var d = getEffectiveDuration();
        if (!d) {
          audio.load();
          return;
        }
        var target = e.key === 'Home' ? 0 : (e.key === 'End' ? d : Number(audio.currentTime || 0) + step);
        audio.currentTime = Math.max(0, Math.min(d, target));
        updateProgress();
      });
      if (menuBtn) {
        var saveBlob = function(file) {
          var url = URL.createObjectURL(file.blob);
          var a = document.createElement('a');
          a.href = url;
          a.download = buildDownloadFilename(file.extension);
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          window.setTimeout(function() { URL.revokeObjectURL(url); }, 2000);
        };
        var saveOriginal = function(src) {
          var a = document.createElement('a');
          a.href = src;
          a.download = buildDownloadFilename();
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
        };
        // Only audio is saved: an error page (a file that is gone, a session that
        // ended) never becomes an ".mp3" on the member's computer.
        var isAudioResponse = function(response) {
          var type = String(response.headers.get('Content-Type') || '').toLowerCase();
          return type === '' || /^(audio|video)\//.test(type) || /^application\/(octet-stream|ogg)/.test(type);
        };
        // M4A, OGG and FLAC are converted on the server from the file being played.
        var fetchFromServer = function(src, format) {
          var path = '';
          try { path = decodeURIComponent(new URL(src, window.location.href).pathname); } catch (_e) { path = src; }
          path = path.replace(/^\/+/, '').replace(/^media\//, '');
          var name = buildDownloadFilename(format).replace(/\.[a-z0-9]+$/i, '');
          var url = serverDownloadUrl + '?path=' + encodeURIComponent(path) + '&format=' + encodeURIComponent(format) + '&name=' + encodeURIComponent(name);
          return fetch(url, { credentials: 'same-origin' }).then(function(response) {
            // 503: this file type cannot be made right now. Anything else: the file cannot be had.
            if (!response.ok || !isAudioResponse(response)) throw new Error(response.status === 503 ? 'format' : 'missing');
            return response.blob();
          }).then(function(blob) { return { blob: blob, extension: format }; });
        };
        var downloadAs = function(format) {
          var src = audio.getAttribute('src');
          if (!src || menuBtn.dataset.busy === '1') return;
          menuBtn.dataset.busy = '1';
          menuBtn.disabled = true;
          showNotice('');
          var work;
          var match = downloadFormats.find(function(f) { return f.key === format; });
          if (match && !match.browser) {
            work = fetchFromServer(src, format).catch(function(error) {
              showNotice(error && error.message === 'format' ? i18n.audio_download_failed : i18n.audio_download_unavailable);
              return null;
            });
          } else {
            work = fetch(src, { credentials: 'same-origin' })
              .then(function(response) {
                if (!response.ok || !isAudioResponse(response)) throw new Error('missing');
                return response.arrayBuffer()
                  .then(function(buffer) { return format === 'wav' ? toWavAudio(buffer) : toWhatsAppAudio(buffer); })
                  // The file is fine but this browser cannot convert it: save it as it is.
                  .catch(function() { saveOriginal(src); return null; });
              })
              .catch(function(error) {
                if (error && error.message === 'missing') {
                  showNotice(i18n.audio_download_unavailable);
                } else {
                  // No answer the page may read (offline, or another site's file): the browser tries the link itself.
                  saveOriginal(src);
                }
                return null;
              });
          }
          work.then(function(file) { if (file) saveBlob(file); })
            .finally(function() {
              menuBtn.dataset.busy = '0';
              menuBtn.disabled = false;
            });
        };
        var closeFormatMenu = function() {
          var open = document.querySelector('.audio-format-menu');
          if (open) open.remove();
          document.removeEventListener('click', closeFormatMenu, true);
          window.removeEventListener('scroll', closeFormatMenu, true);
        };
        var openFormatMenu = function() {
          closeFormatMenu();
          var menu = document.createElement('div');
          menu.className = 'audio-format-menu';
          menu.setAttribute('role', 'menu');
          var title = document.createElement('div');
          title.className = 'audio-format-title';
          title.textContent = i18n.audio_download_as || 'Download as';
          menu.appendChild(title);
          downloadFormats.forEach(function(f) {
            var item = document.createElement('button');
            item.type = 'button';
            item.setAttribute('role', 'menuitem');
            item.dataset.format = f.key;
            item.textContent = f.label;
            item.addEventListener('click', function(ev) {
              ev.preventDefault();
              ev.stopPropagation();
              closeFormatMenu();
              downloadAs(f.key);
            });
            menu.appendChild(item);
          });
          // Escape closes it and gives focus back to the download button.
          menu.addEventListener('keydown', function(ev) {
            if (ev.key !== 'Escape') return;
            ev.preventDefault();
            closeFormatMenu();
            menuBtn.focus();
          });
          document.body.appendChild(menu);
          var rect = menuBtn.getBoundingClientRect();
          var width = menu.offsetWidth;
          var left = Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8);
          var top = rect.bottom + 6;
          if (top + menu.offsetHeight > window.innerHeight - 8) top = Math.max(8, rect.top - menu.offsetHeight - 6);
          menu.style.left = left + 'px';
          menu.style.top = top + 'px';
          var first = menu.querySelector('button');
          if (first) first.focus();
          window.setTimeout(function() {
            document.addEventListener('click', closeFormatMenu, true);
            window.addEventListener('scroll', closeFormatMenu, true);
          }, 0);
        };
        menuBtn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          var redirectUrl = String(audio.dataset.downloadRedirectUrl || audio.dataset.downloadRedirect || '').trim();
          if (redirectUrl) {
            window.location.href = redirectUrl;
            return;
          }
          if (downloadFormats.length > 1) {
            if (document.querySelector('.audio-format-menu')) { closeFormatMenu(); return; }
            openFormatMenu();
            return;
          }
          downloadAs(downloadFormats.length ? downloadFormats[0].key : 'mp3');
        });
      }
      
      var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
          if (mutation.type === 'attributes' && mutation.attributeName === 'src') {
             playRequestId += 1;
             pendingPlayPromise = null;
             cancelProgressLoop();
             audio.pause();
             setPlayButtonState(false);
             progBar.style.width = '0%';
             progThumb.style.left = '0%';
             currTime.textContent = '0:00';
             durTime.textContent = '0:00';
             setSliderValue(0, 0);
             showNotice('');
          }
        });
      });
      observer.observe(audio, { attributes: true });
    });
  };

  function initLocaleSwitchTransition() {
    var localeNavigationPending = false;
    var shouldAnimateIn = false;
    try {
      shouldAnimateIn = sessionStorage.getItem('listen_locale_switch') === '1';
      if (shouldAnimateIn) {
        sessionStorage.removeItem('listen_locale_switch');
      }
    } catch (e) {}

    if (shouldAnimateIn) {
      document.body.classList.add('locale-fade-in');
      setTimeout(function() {
        document.body.classList.remove('locale-fade-in');
      }, 260);
    }

    // Prefetch alternate locale links
    var localeLinks = document.querySelectorAll('.locale-link:not(.active)');
    Array.prototype.forEach.call(localeLinks, function(link) {
      var href = link.getAttribute('href');
      if (href && !document.querySelector('link[rel="prefetch"][href="' + href + '"]')) {
        var prefetch = document.createElement('link');
        prefetch.rel = 'prefetch';
        prefetch.href = href;
        document.head.appendChild(prefetch);
      }
    });

    // Intercept locale link clicks for smooth transition
    document.addEventListener('click', function(e) {
      var target = e.target.closest('.locale-link:not(.active)');
      if (!target) return;
      if (localeNavigationPending) return;
      e.preventDefault();
      var href = target.getAttribute('href');
      if (!href) return;
      localeNavigationPending = true;

      try {
        sessionStorage.setItem('listen_locale_switch', '1');
      } catch (_err) {}

      // Animate only the locale switch shape/indicator before navigation.
      var switcher = target.closest('.locale-switch, .mobile-locale-switch');
      if (switcher) {
        switcher.classList.add('locale-switch-prep');
        var targetLocale = String(target.getAttribute('hreflang') || '').toLowerCase();
        if (!targetLocale) {
          var hrefLower = String(href || '').toLowerCase();
          targetLocale = hrefLower.indexOf('/ar') !== -1 ? 'ar' : 'en';
        }
        switcher.classList.toggle('ar-active', targetLocale === 'ar');

        // Make selected locale label white immediately before navigation.
        var localeLinksInSwitcher = switcher.querySelectorAll('.locale-link');
        Array.prototype.forEach.call(localeLinksInSwitcher, function(linkEl) {
          var linkLocale = String(linkEl.getAttribute('hreflang') || '').toLowerCase();
          var shouldBeActive = linkLocale
            ? linkLocale === targetLocale
            : linkEl === target;
          linkEl.classList.toggle('active', shouldBeActive);
        });
      }

      // Smooth fade-out without mutating locale toggle state in-place.
      var reduceMotion = false;
      try {
        reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      } catch (_err) {}

      document.body.classList.add('locale-switching');
      document.documentElement.classList.add('locale-switching');
      document.body.classList.remove('locale-fade-in');

      if (reduceMotion) {
        window.location.assign(href);
        return;
      }

      // Let the fade-out render before leaving the page.
      window.requestAnimationFrame(function() {
        window.setTimeout(function() {
          window.location.assign(href);
        }, 170);
      });
    });
  }

  // Handle bfcache (back/forward): remove switching class
  window.addEventListener('pageshow', function(e) {
    document.body.classList.remove('locale-switching');
    document.documentElement.classList.remove('locale-switching');
    document.body.classList.remove('locale-fade-in');
    if (e.persisted) {
      document.body.classList.add('locale-fade-in');
      setTimeout(function() {
        document.body.classList.remove('locale-fade-in');
      }, 260);
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      initThemeToggle();
      initUserMenu();
      initMobileMenu();
      initRevealAnimations();
      initLocaleSwitchTransition();
      window.initCustomAudioPlayers();
    });
  } else {
    initThemeToggle();
    initUserMenu();
    initMobileMenu();
    initRevealAnimations();
    initLocaleSwitchTransition();
    window.initCustomAudioPlayers();
  }
})();
</script>
{{-- In the dashboard, help sits in the sidebar instead of a button over the studio. --}}
@unless(request()->routeIs('dashboard'))
@include('components.whatsapp-widget')
@endunless
