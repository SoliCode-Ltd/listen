{{-- Shared player for New Theme play buttons ([data-nt-track] > [data-nt-play]), dialect chips and library filters. --}}
<script>
(function () {
  if (window.__ntPlayerBooted) return;
  window.__ntPlayerBooted = true;
  var root = document.body;
  var audio = new Audio();
  audio.preload = 'none';
  var current = null;

  function fmt(t) {
    if (!isFinite(t) || t < 0) t = 0;
    var m = Math.floor(t / 60), s = Math.floor(t % 60);
    return m + ':' + (s < 10 ? '0' : '') + s;
  }
  function setState(track, playing) {
    if (!track) return;
    track.classList.toggle('is-playing', playing);
    var btn = track.querySelector('[data-nt-play]');
    if (btn) {
      btn.setAttribute('aria-pressed', playing ? 'true' : 'false');
      var label = playing ? btn.getAttribute('data-label-pause') : btn.getAttribute('data-label-play');
      if (label) btn.setAttribute('aria-label', label);
    }
  }
  function paintWave(track, ratio) {
    var wave = track ? track.querySelector('.nt-wave') : null;
    // Waves with a fill layer sweep smoothly instead of lighting bar by bar.
    if (wave) wave.style.setProperty('--nt-progress', String(Math.max(0, Math.min(1, ratio))));
    var bars = track ? track.querySelectorAll('.nt-wave > span') : [];
    var lit = Math.round(bars.length * ratio);
    for (var i = 0; i < bars.length; i++) bars[i].classList.toggle('is-lit', i < lit);
    var time = track ? track.querySelector('[data-nt-time]') : null;
    if (time) time.textContent = fmt(audio.currentTime) + (isFinite(audio.duration) ? ' / ' + fmt(audio.duration) : '');
  }
  function stop() {
    if (!current) return;
    setState(current, false);
    current = null;
  }
  function play(track) {
    var src = track.getAttribute('data-src');
    if (!src) return;
    if (current === track && !audio.paused) { audio.pause(); return; }
    if (current !== track) {
      stop();
      paintWave(track, 0);
      audio.src = src;
    }
    current = track;
    var p = audio.play();
    if (p && p.catch) p.catch(function () { setState(track, false); });
    setState(track, true);
  }

  // Pause any other player on the page (e.g. the shared audio widgets) while ours plays.
  audio.addEventListener('play', function () {
    document.querySelectorAll('audio').forEach(function (el) { if (el !== audio && !el.paused) el.pause(); });
  });
  audio.addEventListener('pause', function () { if (current) setState(current, false); });
  audio.addEventListener('ended', function () { if (current) { paintWave(current, 1); setState(current, false); } });
  // timeupdate fires only about four times a second, so progress is painted every frame while playing.
  var frame = 0;
  function tick() {
    if (current && isFinite(audio.duration) && audio.duration > 0) paintWave(current, audio.currentTime / audio.duration);
    frame = audio.paused ? 0 : requestAnimationFrame(tick);
  }
  audio.addEventListener('playing', function () { if (!frame) frame = requestAnimationFrame(tick); });
  audio.addEventListener('timeupdate', function () { if (!frame) tick(); });

  root.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-nt-play]');
    if (btn && root.contains(btn)) {
      play(btn.closest('[data-nt-track]'));
      return;
    }
    var chip = event.target.closest('[data-nt-chip]');
    if (chip) {
      var card = chip.closest('[data-nt-try]');
      var track = card.querySelector('[data-nt-track]');
      card.querySelectorAll('[data-nt-chip]').forEach(function (c) { c.setAttribute('aria-checked', c === chip ? 'true' : 'false'); });
      var text = card.querySelector('[data-nt-try-text]');
      var voice = card.querySelector('[data-nt-try-voice]');
      if (text) text.textContent = chip.getAttribute('data-text') || '';
      if (voice) voice.textContent = chip.getAttribute('data-voice') || '';
      var wasPlaying = current === track && !audio.paused;
      if (current === track) { audio.pause(); stop(); }
      track.setAttribute('data-src', chip.getAttribute('data-src') || '');
      paintWave(track, 0);
      if (wasPlaying) play(track);
      return;
    }
    var filter = event.target.closest('[data-nt-filter]');
    if (filter) {
      var value = filter.getAttribute('data-nt-filter');
      filter.parentElement.querySelectorAll('[data-nt-filter]').forEach(function (f) { f.setAttribute('aria-selected', f === filter ? 'true' : 'false'); });
      root.querySelectorAll('.nt-library-row').forEach(function (row) {
        row.hidden = value !== 'all' && row.getAttribute('data-lang') !== value;
      });
    }
  });
})();
</script>
