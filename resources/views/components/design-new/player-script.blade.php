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

  // ---------- Audio analysis: real waveform and where the voice speaks ----------
  // Players with a script ([data-nt-words]) decode their sample once. The bars
  // then show the real loudness, and the script follows the moments the voice
  // is actually speaking, so pauses between sentences hold the highlight.
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
        var rate = decoded.sampleRate;
        var hop = Math.max(1, Math.round(rate * 0.01)); // 10 ms frames
        var rms = [];
        for (var i = 0; i < data.length; i += hop) {
          var sum = 0, n = Math.min(hop, data.length - i);
          for (var j = 0; j < n; j++) sum += data[i + j] * data[i + j];
          rms.push(Math.sqrt(sum / Math.max(1, n)));
        }
        var sorted = rms.slice().sort(function (a, b) { return a - b; });
        var loud = sorted[Math.floor(sorted.length * 0.95)] || 0;
        var threshold = Math.max(loud * 0.12, 0.004);
        // Voiced stretches; gaps under 120 ms are part of the same stretch.
        var segments = [], start = -1, lastVoiced = -1;
        for (var f = 0; f < rms.length; f++) {
          if (rms[f] >= threshold) {
            if (start < 0) start = f;
            else if (f - lastVoiced > 12) { segments.push([start / 100, (lastVoiced + 1) / 100]); start = f; }
            lastVoiced = f;
          }
        }
        if (start >= 0) segments.push([start / 100, (lastVoiced + 1) / 100]);
        return { duration: decoded.duration, rms: rms, loud: loud || 1, segments: segments };
      })
      .catch(function () { return null; });
    return analyses[src];
  }
  function paintBars(track, info) {
    var groups = track.querySelectorAll('.auth-wave-bars');
    if (!groups.length || !info) return;
    var count = groups[0].children.length;
    // Average loudness per bar, spread between the quiet and loud ends of this
    // sample, so the bars show the shape of the speech instead of all reaching the top.
    var levels = [];
    var per = info.rms.length / count;
    for (var b = 0; b < count; b++) {
      var sum = 0, n = 0;
      for (var k = Math.floor(b * per); k < Math.floor((b + 1) * per); k++) { sum += info.rms[k] || 0; n++; }
      levels.push(n ? sum / n : 0);
    }
    var ordered = levels.slice().sort(function (x, y) { return x - y; });
    var low = ordered[Math.floor(count * 0.08)] || 0, high = ordered[Math.floor(count * 0.96)] || 1;
    var heights = levels.map(function (l) {
      var x = Math.max(0, Math.min(1, (l - low) / Math.max(1e-6, high - low)));
      return Math.round(5 + 27 * Math.pow(x, 0.9));
    });
    groups.forEach(function (g) {
      for (var i = 0; i < g.children.length; i++) g.children[i].style.height = heights[i] + 'px';
    });
  }

  // ---------- Script that lights up as it is spoken ----------
  function wordify(el) {
    if (!el || el.getAttribute('data-nt-wordified') === el.textContent) return;
    var text = el.textContent;
    el.textContent = '';
    var seen = 0, phrase = 0;
    text.split(/(\s+)/).forEach(function (part) {
      if (!part) return;
      if (/^\s+$/.test(part)) { el.appendChild(document.createTextNode(part)); return; }
      var span = document.createElement('span');
      span.className = 'w';
      span.textContent = part;
      span.setAttribute('data-start', String(seen));
      seen += part.length;
      span.setAttribute('data-end', String(seen));
      span.setAttribute('data-phrase', String(phrase));
      // A word ending in punctuation closes a phrase the voice usually pauses after.
      if (/[.,!?;:…،؛؟]["'»”)]*$/.test(part)) phrase++;
      el.appendChild(span);
    });
    el.setAttribute('data-nt-wordified', el.textContent);
    el.setAttribute('data-chars', String(seen || 1));
  }
  // Time the voice has been speaking by time t (silences skipped).
  function voicedAt(info, t) {
    var v = 0;
    for (var i = 0; i < info.segments.length; i++) {
      var s = info.segments[i];
      if (t >= s[1]) v += s[1] - s[0];
      else { if (t > s[0]) v += t - s[0]; break; }
    }
    return v;
  }
  // Anchors that tie the script to the audio: each sentence or clause end in
  // the text is matched to the real pause that best fits it (dynamic
  // programming, in order), so a phrase finishes exactly when the voice stops.
  // Extra breaths in the audio are allowed; the highlight just waits there.
  function anchorsFor(el, words, info) {
    var key = el.getAttribute('data-nt-wordified') + '|' + info.duration;
    if (el.__ntAnchors && el.__ntAnchors.key === key) return el.__ntAnchors.list;
    var total = parseFloat(el.getAttribute('data-chars')) || 1;
    var voiced = voicedAt(info, info.duration) || info.duration;
    var bounds = [];
    for (var i = 0; i < words.length - 1; i++) {
      if (words[i + 1].getAttribute('data-phrase') !== words[i].getAttribute('data-phrase')) bounds.push(parseFloat(words[i].getAttribute('data-end')));
    }
    var pauses = [];
    for (var j = 1; j < info.segments.length; j++) {
      if (info.segments[j][0] - info.segments[j - 1][1] >= 0.15) pauses.push(voicedAt(info, info.segments[j - 1][1]));
    }
    var K = bounds.length, P = pauses.length, MISS = 0.06, MAXD = 0.15;
    // cost[k][p]: best cost placing the first k boundaries using pauses before p.
    var cost = [], pick = [];
    for (var k = 0; k <= K; k++) { cost.push([]); pick.push([]); for (var p = 0; p <= P; p++) { cost[k].push(k === 0 ? 0 : Infinity); pick[k].push(null); } }
    for (k = 1; k <= K; k++) {
      var expect = bounds[k - 1] / total;
      for (p = 0; p <= P; p++) {
        // Boundary k not matched to any pause.
        var best = cost[k - 1][p] + MISS, how = [-1, p];
        if (p > 0 && cost[k][p - 1] < best) { best = cost[k][p - 1]; how = [-2, p - 1]; }
        if (p > 0) {
          var d = Math.abs(pauses[p - 1] / voiced - expect);
          if (d <= MAXD && cost[k - 1][p - 1] + d < best) { best = cost[k - 1][p - 1] + d; how = [p - 1, p - 1]; }
        }
        cost[k][p] = best; pick[k][p] = how;
      }
    }
    var list = [[total, voiced]];
    k = K; p = P;
    while (k > 0) {
      var h = pick[k][p];
      if (!h) break;
      if (h[0] === -2) { p = h[1]; continue; }
      if (h[0] >= 0) list.push([bounds[k - 1], pauses[h[0]]]);
      k--; p = h[1];
    }
    list.push([0, 0]);
    list.reverse();
    el.__ntAnchors = { key: key, list: list };
    return list;
  }
  // How far through the script (in letters) the voice is at time t.
  function spokenChars(el, words, t, info, ratio) {
    var total = parseFloat(el.getAttribute('data-chars')) || 1;
    if (!info || !info.segments.length) return ratio * total;
    var v = voicedAt(info, t), list = anchorsFor(el, words, info);
    for (var i = 1; i < list.length; i++) {
      if (v <= list[i][1]) {
        var a = list[i - 1], b = list[i];
        return a[0] + (b[0] - a[0]) * (b[1] > a[1] ? (v - a[1]) / (b[1] - a[1]) : 1);
      }
    }
    return total;
  }
  function paintWords(track, ratio, t) {
    var card = track ? track.closest('[data-nt-try]') : null;
    var el = card ? card.querySelector('[data-nt-words]') : null;
    if (!el) return;
    wordify(el);
    var words = el.querySelectorAll('.w');
    if (!words.length) return;
    el.classList.toggle('is-reading', ratio > 0 && ratio < 1);
    var info = track.__ntInfo && track.__ntInfo.src === track.getAttribute('data-src') ? track.__ntInfo.data : null;
    var pos = ratio >= 1 ? Infinity : spokenChars(el, words, t, info, ratio);
    for (var i = 0; i < words.length; i++) {
      var w = words[i];
      var s = parseFloat(w.getAttribute('data-start')), e = parseFloat(w.getAttribute('data-end'));
      var fill = pos >= e ? 1 : pos <= s ? 0 : (pos - s) / Math.max(1, e - s);
      w.classList.toggle('is-spoken', fill >= 1);
      w.classList.toggle('is-current', fill > 0 && fill < 1);
      // The soft edge (36% wide) starts fully before the word and ends fully past it,
      // so a word goes from dim to lit without any step.
      if (fill > 0 && fill < 1) w.style.setProperty('--wp', (fill * 136 - 18).toFixed(1) + '%');
    }
  }

  // currentTime moves in small steps in some browsers; between steps the
  // position is carried forward with the clock so the motion stays smooth.
  var clock = { media: 0, at: 0, shown: 0 };
  function smoothTime() {
    var t = audio.currentTime;
    var now = performance.now();
    if (t !== clock.media) { clock.media = t; clock.at = now; }
    var guess = audio.paused ? t : Math.min(t + (now - clock.at) / 1000 * (audio.playbackRate || 1), t + 0.25);
    // Never step backwards, unless the audio really moved back (a seek or a restart).
    if (guess < clock.shown && clock.shown - t < 0.3) guess = clock.shown;
    clock.shown = guess;
    return guess;
  }

  // Bars near the playhead lift with the voice's real loudness at that moment,
  // so the waveform moves with the speech; elsewhere they rest at their shape.
  function pulseBars(track, ratio, t) {
    var info = track.__ntInfo && track.__ntInfo.src === track.getAttribute('data-src') ? track.__ntInfo.data : null;
    var groups = track.querySelectorAll('.auth-wave-bars');
    if (!info || !groups.length) return;
    var playing = track.classList.contains('is-playing');
    var frame = Math.min(info.rms.length - 1, Math.max(0, Math.round(t * 100)));
    var level = playing ? Math.min(1, Math.sqrt((info.rms[frame] || 0) / info.loud)) : 0;
    var count = groups[0].children.length, head = ratio * count;
    groups.forEach(function (g) {
      for (var i = 0; i < count; i++) {
        var near = Math.exp(-Math.pow((i + 0.5 - head) / 2.2, 2));
        var lift = 1 + 0.55 * level * near;
        g.children[i].style.transform = lift > 1.001 ? 'scaleY(' + lift.toFixed(3) + ')' : '';
      }
    });
  }
  function paintWave(track, ratio, t) {
    if (t === undefined) t = audio.currentTime;
    paintWords(track, ratio, t);
    var wave = track ? track.querySelector('.nt-wave') : null;
    // Waves with a fill layer sweep smoothly instead of lighting bar by bar.
    if (wave) wave.style.setProperty('--nt-progress', String(Math.max(0, Math.min(1, ratio))));
    if (wave) pulseBars(track, ratio, t);
    var bars = track ? track.querySelectorAll('.nt-wave > span') : [];
    var lit = Math.round(bars.length * ratio);
    for (var i = 0; i < bars.length; i++) bars[i].classList.toggle('is-lit', i < lit);
    var time = track ? track.querySelector('[data-nt-time]') : null;
    if (time) time.textContent = fmt(t) + (isFinite(audio.duration) ? ' / ' + fmt(audio.duration) : '');
  }
  function prepare(track) {
    if (!track || !track.closest('[data-nt-try]') || !track.closest('[data-nt-try]').querySelector('[data-nt-words]')) return;
    var src = track.getAttribute('data-src');
    analyse(src).then(function (info) {
      if (!info || track.getAttribute('data-src') !== src) return;
      track.__ntInfo = { src: src, data: info };
      paintBars(track, info);
      var time = track.querySelector('[data-nt-time]');
      if (time && current !== track) time.textContent = '0:00 / ' + fmt(info.duration);
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
      stop();
      paintWave(track, 0, 0);
      audio.src = src;
      clock = { media: 0, at: performance.now(), shown: 0 };
    }
    prepare(track);
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
  audio.addEventListener('ended', function () { if (current) { paintWave(current, 1, audio.duration); setState(current, false); } });
  // Progress is painted every frame while playing.
  var frame = 0;
  function tick() {
    if (current && isFinite(audio.duration) && audio.duration > 0) {
      var t = smoothTime();
      paintWave(current, Math.min(1, t / audio.duration), t);
    }
    frame = audio.paused ? 0 : requestAnimationFrame(tick);
  }
  audio.addEventListener('playing', function () { if (!frame) frame = requestAnimationFrame(tick); });
  audio.addEventListener('timeupdate', function () { if (!frame) tick(); });

  // Analyse the first sample shortly after the page settles, so its waveform is real before play.
  function warm() {
    document.querySelectorAll('[data-nt-try] [data-nt-track]').forEach(prepare);
  }
  if (document.readyState === 'complete') setTimeout(warm, 600);
  else window.addEventListener('load', function () { setTimeout(warm, 600); });

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
      if (text) { text.textContent = chip.getAttribute('data-text') || ''; text.classList.remove('is-reading'); }
      if (voice) voice.textContent = chip.getAttribute('data-voice') || '';
      var wasPlaying = current === track && !audio.paused;
      if (current === track) { audio.pause(); stop(); }
      track.setAttribute('data-src', chip.getAttribute('data-src') || '');
      paintWave(track, 0, 0);
      prepare(track);
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
