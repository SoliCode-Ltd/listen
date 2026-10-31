/*
 * The voice cloning stage's example (dashboard.blade.php, #voiceCloningSection): a cloned
 * voice saying what it is, in the page's language. The orb's button plays it, and the orb
 * ripples with the voice ([data-vs-cast], __vpLevel, as js/listen-orbs.js follows). A
 * recorder starting stops it at once, so the example never ends up in a member's own
 * recording.
 */
(function () {
  'use strict';

  function init(stage) {
    var button = stage.querySelector('[data-vc-example]');
    if (!button || stage.__vcExample) return;
    stage.__vcExample = true;
    var url = button.getAttribute('data-vc-example');
    var nowLine = stage.querySelector('[data-vc-example-now]');
    if (nowLine) {
      nowLine.textContent = (nowLine.getAttribute('data-template') || ':name').replace(':name', button.getAttribute('data-vc-example-name') || '');
    }
    var audio = new Audio();
    audio.preload = 'none';
    var playing = false;
    var frame = 0;
    var levels = null;

    function stop() {
      cancelAnimationFrame(frame);
      if (!audio.paused) audio.pause();
      if (!playing) return;
      playing = false;
      stage.classList.remove('is-voicing', 'is-playing-example');
      stage.__vpLevel = 0;
      button.classList.remove('is-playing');
      button.setAttribute('aria-pressed', 'false');
    }

    function play() {
      stop();
      playing = true;
      if (window.ListenVoiceAnalyse && !levels) {
        Promise.resolve(window.ListenVoiceAnalyse(url)).then(function (info) { levels = info; }).catch(function () {});
      }
      if (audio.getAttribute('src') !== url) audio.src = url;
      audio.currentTime = 0;
      var started = audio.play();
      if (started && started.catch) started.catch(function () { stop(); });
      button.classList.add('is-playing');
      button.setAttribute('aria-pressed', 'true');
      stage.classList.add('is-voicing', 'is-playing-example');
      document.dispatchEvent(new CustomEvent('listen:audio-play', { detail: { source: 'voice-cloning-examples' } }));
      (function step() {
        if (!playing) return;
        var target = 0;
        if (!audio.paused) {
          if (levels && levels.rms && levels.rms.length) {
            var f = Math.min(levels.rms.length - 1, Math.max(0, Math.round(audio.currentTime * 100)));
            target = Math.min(1, Math.sqrt((levels.rms[f] || 0) / (levels.loud || 1)));
          } else {
            target = 0.35 + 0.2 * Math.abs(Math.sin(audio.currentTime * 7));
          }
        }
        stage.__vpLevel = (stage.__vpLevel || 0) + (target - (stage.__vpLevel || 0)) * 0.25;
        frame = requestAnimationFrame(step);
      })();
    }

    audio.addEventListener('ended', stop);
    audio.addEventListener('error', stop);
    button.addEventListener('click', function () {
      if (playing) stop();
      else play();
    });

    // Another player on the page starting stops it.
    document.addEventListener('listen:audio-play', function (event) {
      if (event.detail && event.detail.source !== 'voice-cloning-examples') stop();
    });
    // So does a recorder: before its button is pressed through, and whenever one goes live.
    document.addEventListener('pointerdown', function (event) {
      if (event.target instanceof Element && event.target.closest('#voiceCloningSection .rec-btn')) stop();
    }, true);
    if (window.MutationObserver) {
      var watch = new MutationObserver(function () {
        if (document.querySelector('#voiceCloningSection .vc-recorder.live, #voiceCloningSection .rec-btn.recording')) stop();
      });
      document.querySelectorAll('#voiceCloningSection .vc-recorder, #voiceCloningSection .rec-btn').forEach(function (el) {
        watch.observe(el, { attributes: true, attributeFilter: ['class'] });
      });
    }
    // Leaving the page stops it too.
    document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); });
  }

  function boot() { document.querySelectorAll('[data-vc-stage]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
