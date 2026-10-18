{{-- Voice player for the homepage and the samples page: voice and dialogue cards ([data-vp-track] with a
     [data-vp-play] button) and region or mood chips ([data-vp-region]). One clip plays at a time. --}}
<script>
(function () {
  if (window.__vpPlayerBooted) return;
  window.__vpPlayerBooted = true;
  var root = document.body;
  var audio = new Audio();
  audio.preload = 'none';
  var current = null;

  function setState(track, playing) {
    if (!track) return;
    track.classList.toggle('is-playing', playing);
    var btn = track.querySelector('[data-vp-play]');
    if (btn) {
      btn.setAttribute('aria-pressed', playing ? 'true' : 'false');
      var label = playing ? btn.getAttribute('data-label-pause') : btn.getAttribute('data-label-play');
      if (label) btn.setAttribute('aria-label', label);
    }
  }

  // ---------- Audio analysis: the clip's real loudness, decoded once ----------
  var analyses = {};
  function analyse(src) {
    if (!src || !(window.AudioContext || window.webkitAudioContext) || !window.fetch) return Promise.resolve(null);
    if (analyses[src]) return analyses[src];
    analyses[src] = fetch(src, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('audio ' + r.status); return r.arrayBuffer(); })
      .then(function (buf) {
        var Ctx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
        var ctx = Ctx ? new Ctx(1, 2, 44100) : new (window.AudioContext || window.webkitAudioContext)();
        return new Promise(function (resolve, reject) { ctx.decodeAudioData(buf, resolve, reject); });
      })
      .then(function (decoded) {
        var data = decoded.getChannelData(0);
        var hop = Math.max(1, Math.round(decoded.sampleRate * 0.01)); // 10 ms frames
        var rms = [];
        for (var i = 0; i < data.length; i += hop) {
          var sum = 0, n = Math.min(hop, data.length - i);
          for (var j = 0; j < n; j++) sum += data[i + j] * data[i + j];
          rms.push(Math.sqrt(sum / Math.max(1, n)));
        }
        var sorted = rms.slice().sort(function (a, b) { return a - b; });
        return { duration: decoded.duration, rms: rms, loud: sorted[Math.floor(sorted.length * 0.95)] || 1 };
      })
      .catch(function () { return null; });
    return analyses[src];
  }
  // Shared with the hero conversation, which plays its own lines: its orb ripples with them too.
  window.ListenVoiceAnalyse = analyse;
  function infoOf(track) {
    return track && track.__vpInfo && track.__vpInfo.src === track.getAttribute('data-src') ? track.__vpInfo.data : null;
  }

  // Bars take the shape of the clip's real loudness once it has been analysed.
  function shapeBars(track, info) {
    var groups = track.querySelectorAll('.vs-wave-bars');
    if (!groups.length || !info) return;
    var count = groups[0].children.length, per = info.rms.length / count, levels = [];
    for (var b = 0; b < count; b++) {
      var sum = 0, n = 0;
      for (var k = Math.floor(b * per); k < Math.floor((b + 1) * per); k++) { sum += info.rms[k] || 0; n++; }
      levels.push(n ? sum / n : 0);
    }
    var ordered = levels.slice().sort(function (x, y) { return x - y; });
    var low = ordered[Math.floor(count * 0.08)] || 0, high = ordered[Math.floor(count * 0.96)] || 1;
    groups.forEach(function (g) {
      for (var i = 0; i < count; i++) {
        var x = Math.max(0, Math.min(1, (levels[i] - low) / Math.max(1e-6, high - low)));
        g.children[i].style.height = Math.round(16 + 84 * Math.pow(x, 0.85)) + '%';
      }
    });
  }

  // While playing, the bars around the playhead rise and fall with the voice.
  // Levels are eased frame to frame so the motion stays smooth.
  function liftBars(track, ratio, t) {
    var info = infoOf(track);
    var playing = track.classList.contains('is-playing');
    var target = 0;
    if (playing && info) {
      var frame = Math.min(info.rms.length - 1, Math.max(0, Math.round(t * 100)));
      target = Math.min(1, Math.sqrt((info.rms[frame] || 0) / info.loud));
    }
    // The live loudness is kept on the card too: a voice orb in it ripples with the voice.
    track.__vpLevel = (track.__vpLevel || 0) + (target - (track.__vpLevel || 0)) * 0.25;
    var groups = track.querySelectorAll('.vs-wave-bars');
    if (!groups.length) return;
    var level = track.__vpLevel, count = groups[0].children.length, head = ratio * count;
    groups.forEach(function (g) {
      for (var i = 0; i < count; i++) {
        var near = Math.exp(-Math.pow((i + 0.5 - head) / 3, 2));
        var lift = 1 + 0.6 * level * near;
        g.children[i].style.transform = lift > 1.002 ? 'scaleY(' + lift.toFixed(3) + ')' : '';
      }
    });
  }

  // Dialogue lines: the line being spoken lights up, from the times each line is
  // spoken (data-start / data-end). A line stays lit through the short pause
  // before the next one, so the highlight hands over without flicker.
  function paintTurns(track, ratio, t) {
    if (!track || !track.hasAttribute('data-vp-dialogue')) return;
    var turns = track.querySelectorAll('[data-vp-turn]');
    if (!turns.length) return;
    var active = -1, timed = turns[0].hasAttribute('data-start');
    if (ratio > 0 && ratio < 1) {
      if (timed) {
        for (var i = 0; i < turns.length; i++) {
          if (t + 0.08 >= parseFloat(turns[i].getAttribute('data-start'))) active = i;
        }
        var last = turns[turns.length - 1];
        if (active === turns.length - 1 && t > parseFloat(last.getAttribute('data-end')) + 0.4) active = turns.length;
      } else {
        var weights = [], sum = 0, acc = 0;
        turns.forEach(function (el) { var w = parseFloat(el.getAttribute('data-weight')) || 1; weights.push(w); sum += w; });
        for (var j = 0; j < weights.length; j++) { if (ratio * sum < acc + weights[j]) { active = j; break; } acc += weights[j]; }
      }
    }
    turns.forEach(function (el, i) {
      var isActive = i === active;
      el.classList.toggle('is-active', isActive);
      el.classList.toggle('is-done', ratio >= 1 || i < active);
      if (isActive && timed) {
        var s = parseFloat(el.getAttribute('data-start')), e = parseFloat(el.getAttribute('data-end'));
        el.style.setProperty('--turn-progress', String(Math.max(0, Math.min(1, (t - s) / Math.max(0.1, e - s)))));
      } else {
        el.style.setProperty('--turn-progress', i < active || ratio >= 1 ? '1' : '0');
      }
    });
  }

  function paint(track, ratio, t) {
    if (!track) return;
    track.style.setProperty('--vp-progress', String(Math.max(0, Math.min(1, ratio))));
    paintTurns(track, ratio, t);
    liftBars(track, ratio, t);
  }
  // A track that stops being the current one goes back to its resting look.
  function rest(track) {
    if (!track) return;
    track.__vpLevel = 0;
    paint(track, 0, 0);
  }
  function prepare(track) {
    var src = track.getAttribute('data-src');
    analyse(src).then(function (info) {
      if (!info || track.getAttribute('data-src') !== src) return;
      track.__vpInfo = { src: src, data: info };
      shapeBars(track, info);
    });
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
      var previous = current;
      stop();
      rest(previous);
      paint(track, 0, 0);
      audio.src = src;
      clock = { media: 0, at: performance.now(), shown: 0 };
    }
    prepare(track);
    current = track;
    var p = audio.play();
    if (p && p.catch) p.catch(function () { setState(track, false); });
    setState(track, true);
  }

  // currentTime moves in steps in some browsers; between steps the position is
  // carried forward with the clock, and never steps back, so motion stays smooth.
  var clock = { media: 0, at: 0, shown: 0 };
  function smoothTime() {
    var t = audio.currentTime, now = performance.now();
    if (t !== clock.media) { clock.media = t; clock.at = now; }
    var guess = audio.paused ? t : Math.min(t + (now - clock.at) / 1000 * (audio.playbackRate || 1), t + 0.25);
    if (guess < clock.shown && clock.shown - t < 0.3) guess = clock.shown;
    clock.shown = guess;
    return guess;
  }

  // Pause any other audio on the page while ours plays, and stop when another player starts.
  audio.addEventListener('play', function () {
    document.querySelectorAll('audio').forEach(function (el) { if (el !== audio && !el.paused) el.pause(); });
    document.dispatchEvent(new CustomEvent('listen:audio-play', { detail: { source: audio } }));
  });
  document.addEventListener('listen:audio-play', function (e) {
    if (e.detail && e.detail.source !== audio && !audio.paused) audio.pause();
  });
  audio.addEventListener('pause', function () { if (current) setState(current, false); });
  audio.addEventListener('ended', function () { if (current) { var done = current; stop(); paint(done, 1, audio.duration); } });
  var frame = 0;
  function tick() {
    if (current && isFinite(audio.duration) && audio.duration > 0) {
      var t = smoothTime();
      paint(current, Math.min(1, t / audio.duration), t);
    }
    frame = audio.paused ? 0 : requestAnimationFrame(tick);
  }
  audio.addEventListener('playing', function () { if (!frame) frame = requestAnimationFrame(tick); });
  audio.addEventListener('timeupdate', function () { if (!frame) tick(); });

  root.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-vp-play]');
    if (btn && root.contains(btn)) {
      play(btn.closest('[data-vp-track]'));
      return;
    }
    // Sound environments: hear the same line clean or in the place. Switching
    // while it plays carries on from the same moment.
    var mode = event.target.closest('[data-vp-mode]');
    if (mode && root.contains(mode)) {
      var envTrack = mode.closest('[data-vp-track]');
      var next = envTrack && envTrack.getAttribute('data-src-' + mode.getAttribute('data-vp-mode'));
      if (!next) return;
      envTrack.querySelectorAll('[data-vp-mode]').forEach(function (b) { b.setAttribute('aria-pressed', b === mode ? 'true' : 'false'); });
      envTrack.setAttribute('data-vp-current-mode', mode.getAttribute('data-vp-mode'));
      if (envTrack.getAttribute('data-src') === next) return;
      var wasPlaying = current === envTrack && !audio.paused;
      var at = current === envTrack ? audio.currentTime : 0;
      envTrack.setAttribute('data-src', next);
      prepare(envTrack);
      if (current === envTrack) {
        audio.src = next;
        var resume = function () {
          audio.removeEventListener('loadedmetadata', resume);
          try { audio.currentTime = Math.min(at, Math.max(0, (audio.duration || at) - 0.05)); } catch (e) {}
          clock = { media: audio.currentTime, at: performance.now(), shown: audio.currentTime };
          if (wasPlaying) { var p2 = audio.play(); if (p2 && p2.catch) p2.catch(function () {}); }
        };
        audio.addEventListener('loadedmetadata', resume);
        audio.load();
      } else {
        play(envTrack);
      }
      return;
    }
    // A voice or dialogue card plays when pressed anywhere, not only on its button.
    var pressed = event.target.closest('.vs-card, .vs-dialogue, .vs-env');
    if (pressed && !event.target.closest('a, button')) {
      play(pressed);
      return;
    }
    var chip = event.target.closest('[data-vp-region]');
    if (chip) {
      var region = chip.getAttribute('data-vp-region');
      var section = chip.closest('section');
      var grid = section.querySelector('.vs-grid');
      chip.parentElement.querySelectorAll('[data-vp-region]').forEach(function (c) { c.setAttribute('aria-selected', c === chip ? 'true' : 'false'); });
      grid.classList.toggle('is-filtered', region !== 'all');
      grid.querySelectorAll('.vs-card').forEach(function (card) {
        card.hidden = region !== 'all' && card.getAttribute('data-region') !== region;
        if (card.hidden && current === card) { audio.pause(); stop(); rest(card); }
      });
    }
  });
})();
</script>
