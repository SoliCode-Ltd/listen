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
  // Pauses of 200 ms or more split the speech into phrases.
  function phrases(info) {
    var out = [];
    info.segments.forEach(function (s) {
      var last = out[out.length - 1];
      if (last && s[0] - last[1] < 0.2) last[1] = s[1]; else out.push([s[0], s[1]]);
    });
    return out;
  }
  function paintBars(track, info) {
    var groups = track.querySelectorAll('.auth-wave-bars');
    if (!groups.length || !info) return;
    var count = groups[0].children.length;
    var heights = [];
    var per = info.rms.length / count;
    for (var b = 0; b < count; b++) {
      var peak = 0;
      for (var k = Math.floor(b * per); k < Math.floor((b + 1) * per); k++) peak = Math.max(peak, info.rms[k] || 0);
      heights.push(Math.round(5 + 27 * Math.sqrt(Math.min(1, peak / info.loud))));
    }
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
  // How far through the script (in letters) the voice is at time t.
  function spokenChars(el, words, t, info, ratio) {
    var total = parseFloat(el.getAttribute('data-chars')) || 1;
    if (!info || !info.segments.length) return ratio * total;
    var spans = phrases(info);
    var textPhrases = parseInt(words[words.length - 1].getAttribute('data-phrase'), 10) + 1;
    if (spans.length === textPhrases) {
      // One pause in the audio for each phrase in the text: follow phrase by phrase.
      for (var p = 0; p < spans.length; p++) {
        if (t < spans[p][0]) return phraseStart(words, p);
        if (t <= spans[p][1]) {
          var a = phraseStart(words, p), z = phraseStart(words, p + 1, total);
          return a + (z - a) * (t - spans[p][0]) / Math.max(0.01, spans[p][1] - spans[p][0]);
        }
      }
      return total;
    }
    // Otherwise follow the time the voice is speaking, skipping the silences.
    var voiced = 0, before = 0;
    info.segments.forEach(function (s) {
      voiced += s[1] - s[0];
      if (t >= s[1]) before += s[1] - s[0];
      else if (t > s[0]) before += t - s[0];
    });
    return voiced > 0 ? total * before / voiced : ratio * total;
  }
  function phraseStart(words, p, fallback) {
    for (var i = 0; i < words.length; i++) {
      if (parseInt(words[i].getAttribute('data-phrase'), 10) >= p) return parseFloat(words[i].getAttribute('data-start'));
    }
    return fallback;
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
      if (fill > 0 && fill < 1) w.style.setProperty('--wp', (fill * 100).toFixed(1) + '%');
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

  function paintWave(track, ratio, t) {
    if (t === undefined) t = audio.currentTime;
    paintWords(track, ratio, t);
    var wave = track ? track.querySelector('.nt-wave') : null;
    // Waves with a fill layer sweep smoothly instead of lighting bar by bar.
    if (wave) wave.style.setProperty('--nt-progress', String(Math.max(0, Math.min(1, ratio))));
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
