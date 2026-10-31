/*
 * The voice cloning stage's examples (dashboard.blade.php, #voiceCloningSection): a cloned
 * voice saying what it is, in Arabic and in English. The orb's button plays them one after
 * the other, each chip plays its own, and the orb ripples with the voice ([data-vs-cast],
 * __vpLevel, as js/listen-orbs.js follows). A recorder starting stops them at once, so an
 * example never ends up in a member's own recording.
 */
(function () {
  'use strict';

  function init(stage) {
    var chips = Array.prototype.slice.call(stage.querySelectorAll('[data-vc-example]'));
    if (!chips.length || stage.__vcExamples) return;
    stage.__vcExamples = true;
    var orbButton = stage.querySelector('[data-vc-examples-play]');
    var nowLine = stage.querySelector('[data-vc-example-now]');
    var template = nowLine ? nowLine.getAttribute('data-template') || ':name' : ':name';
    var audio = new Audio();
    audio.preload = 'none';
    var now = null;
    var frame = 0;
    var levels = null;

    function stop() {
      cancelAnimationFrame(frame);
      if (!audio.paused) audio.pause();
      if (!now) return;
      now = null;
      stage.classList.remove('is-voicing', 'is-playing-example');
      stage.__vpLevel = 0;
      chips.forEach(function (chip) { chip.classList.remove('is-playing'); chip.setAttribute('aria-pressed', 'false'); });
      if (orbButton) orbButton.classList.remove('is-playing');
    }

    function play(index, chain) {
      stop();
      var chip = chips[index];
      if (!chip) return;
      var url = chip.getAttribute('data-vc-example');
      now = { index: index, chain: chain };
      levels = null;
      if (window.ListenVoiceAnalyse) {
        Promise.resolve(window.ListenVoiceAnalyse(url)).then(function (info) { if (now && now.index === index) levels = info; }).catch(function () {});
      }
      audio.src = url;
      var started = audio.play();
      if (started && started.catch) started.catch(function () { stop(); });
      chip.classList.add('is-playing');
      chip.setAttribute('aria-pressed', 'true');
      if (orbButton && chain) orbButton.classList.add('is-playing');
      stage.classList.add('is-voicing', 'is-playing-example');
      if (nowLine) nowLine.textContent = template.replace(':name', chip.getAttribute('data-vc-example-name') || '');
      document.dispatchEvent(new CustomEvent('listen:audio-play', { detail: { source: 'voice-cloning-examples' } }));
      (function step() {
        if (!now) return;
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

    audio.addEventListener('ended', function () {
      var was = now;
      stop();
      if (was && was.chain && was.index + 1 < chips.length) play(was.index + 1, true);
    });
    audio.addEventListener('error', stop);

    if (orbButton) {
      orbButton.addEventListener('click', function () {
        if (now) stop();
        else play(0, true);
      });
    }
    chips.forEach(function (chip, index) {
      chip.addEventListener('click', function () {
        if (now && now.index === index) stop();
        else play(index, false);
      });
    });

    // Another player on the page starting stops these.
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
    // Leaving the section stops them too.
    document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); });
  }

  function boot() { document.querySelectorAll('[data-vc-stage]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
