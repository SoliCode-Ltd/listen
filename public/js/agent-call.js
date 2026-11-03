/*
 * Listen Agents: one live call with an agent, in the browser.
 *
 *   var call = ListenAgentCall.start({
 *     begin: () => fetch(...),        // asks Listen for the call: { token, ws, model, call_key, api, max_seconds, speaks_first }
 *     onState(state) {},              // connecting, listening, speaking, ended, error, mic
 *     onTurn(turn) {},                // { role: 'agent' | 'caller', text }
 *     onLevel(agent, caller) {},      // loudness 0..1 of the agent and of the caller, every frame
 *     onTool(name, args, result) {},  // what the agent did (save_contact ...)
 *   });
 *   call.mute(true); call.end();
 *
 * The microphone is read at 16 kHz and sent as it comes; the agent's voice
 * (24 kHz) is played as it arrives and stops the moment the caller talks over
 * it. Listen hears every few seconds that the call is on (ping), what the agent
 * asked for (tool) and how it ended (end), with what was said.
 */
(function () {
  'use strict';
  if (window.ListenAgentCall) return;

  /*
   * A line as people read it: the transcription's marks taken out (<no speech detected>, {pause})
   * and a number read back digit by digit written as the number ("صفر خمسة صفر…" is 050…,
   * "five five five, two one zero…" is 555 210…). Three spoken digits or more make a number, so
   * "الساعة أربعة" stays. The same rules as App\Support\Agents\SpokenNumbers.
   */
  var AR_DIGITS = { 'صفر': 0, 'واحد': 1, 'اثنين': 2, 'إثنين': 2, 'اتنين': 2, 'اثنان': 2, 'إثنان': 2, 'اتنان': 2,
    'ثلاثة': 3, 'ثلاثه': 3, 'تلاتة': 3, 'تلاته': 3, 'ثلاث': 3, 'تلات': 3, 'أربعة': 4, 'اربعة': 4, 'أربعه': 4, 'اربعه': 4, 'أربع': 4, 'اربع': 4,
    'خمسة': 5, 'خمسه': 5, 'خمس': 5, 'ستة': 6, 'سته': 6, 'ست': 6, 'سبعة': 7, 'سبعه': 7, 'سبع': 7,
    'ثمانية': 8, 'ثمانيه': 8, 'تمانية': 8, 'تمانيه': 8, 'ثماني': 8, 'تماني': 8, 'ثمان': 8, 'تمن': 8, 'تسعة': 9, 'تسعه': 9, 'تسع': 9 };
  var EN_DIGITS = { zero: '0', oh: '0', one: '1', two: '2', three: '3', four: '4', five: '5', six: '6', seven: '7', eight: '8', nine: '9' };
  var spoken = (function () {
    var arAlt = Object.keys(AR_DIGITS).sort(function (a, b) { return b.length - a.length; }).join('|');
    var arSeq, arWord;
    try {
      arSeq = new RegExp('(^|[^\\p{L}\\p{M}])((?:' + arAlt + ')(?:[\\s،,\\-]+(?:' + arAlt + ')){2,})(?=$|[^\\p{L}\\p{M}])', 'gu');
      arWord = new RegExp(arAlt, 'gu');
    } catch (e) { arSeq = null; }
    var enWord = '(?:(?:double|triple)\\s+)?(?:zero|oh|one|two|three|four|five|six|seven|eight|nine)';
    var enSeq = new RegExp('\\b' + enWord + '(?:(?:\\s*[,\\-]\\s*|\\s+)' + enWord + '){2,}\\b', 'gi');
    return function (text) {
      text = text.replace(/<!--[\s\S]*?-->/g, '').replace(/<[^>]*>/g, '').replace(/\{[^}]*\}/g, '');
      if (arSeq) text = text.replace(arSeq, function (m, pre, seq) { return pre + seq.match(arWord).map(function (w) { return AR_DIGITS[w]; }).join(''); });
      text = text.replace(enSeq, function (seq) {
        return seq.split(/\s*,\s*/).map(function (group) {
          var out = '';
          group.replace(/(?:(double|triple)\s+)?\b(zero|oh|one|two|three|four|five|six|seven|eight|nine)\b/gi, function (m, times, word) {
            var d = EN_DIGITS[word.toLowerCase()];
            out += times ? new Array((times.toLowerCase() === 'double' ? 2 : 3) + 1).join(d) : d;
            return m;
          });
          return out;
        }).filter(Boolean).join(' ');
      });
      text = text.replace(/(^|[^\d\-])(\d(?:-\d){2,})(?![\d\-])/g, function (m, pre, seq) { return pre + seq.replace(/-/g, ''); });
      return text.replace(/\s+/g, ' ');
    };
  })();

  var WORKLET = [
    'class ListenPcm extends AudioWorkletProcessor {',
    '  constructor() { super(); this.step = sampleRate / 16000; this.pos = 0; this.prev = 0; this.out = new Int16Array(1600); this.n = 0; }',
    '  process(inputs) {',
    '    var x = inputs[0] && inputs[0][0];',
    '    if (!x) return true;',
    '    while (this.pos < x.length) {',
    '      var i = Math.floor(this.pos), f = this.pos - i;',
    '      var a = i < 0 ? this.prev : x[i], b = i + 1 < x.length ? x[i + 1] : x[i];',
    '      var s = a + (b - a) * f; s = s < -1 ? -1 : s > 1 ? 1 : s;',
    '      this.out[this.n++] = s < 0 ? s * 32768 : s * 32767;',
    '      if (this.n === this.out.length) { this.port.postMessage(this.out.buffer.slice(0)); this.n = 0; }',
    '      this.pos += this.step;',
    '    }',
    '    this.pos -= x.length; this.prev = x[x.length - 1];',
    '    return true;',
    '  }',
    '}',
    "registerProcessor('listen-pcm', ListenPcm);",
  ].join('\n');

  function b64(buffer) {
    var bytes = new Uint8Array(buffer), s = '';
    for (var i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    return btoa(s);
  }
  function pcmFrom(b64s) {
    var bin = atob(b64s), n = bin.length >> 1, out = new Float32Array(n);
    for (var i = 0; i < n; i++) {
      var v = bin.charCodeAt(2 * i) | (bin.charCodeAt(2 * i + 1) << 8);
      out[i] = (v >= 32768 ? v - 65536 : v) / 32768;
    }
    return out;
  }
  function rms(analyser, buf) {
    analyser.getFloatTimeDomainData(buf);
    var s = 0;
    for (var i = 0; i < buf.length; i++) s += buf[i] * buf[i];
    return Math.min(1, Math.sqrt(s / buf.length) * 3.2);
  }
  function post(url, body, keepalive) {
    // text/plain: no preflight from other websites; Listen reads the JSON either way.
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, body: JSON.stringify(body), keepalive: !!keepalive, credentials: 'omit' })
      .then(function (r) { return r.json().catch(function () { return {}; }); });
  }

  function start(opts) {
    var state = 'idle', ended = false, muted = false;
    var ws = null, ctx = null, mic = null, node = null, micSource = null;
    var out = null, outAnalyser = null, micAnalyser = null;
    var playHead = 0, sources = new Set();
    var info = null, pending = [], agentText = '', callerText = '', usage = null;
    var pingTimer = 0, stopTimer = 0, raf = 0, endAfterSpeech = false;
    // Set when the agent hangs up: its goodbye (already received) still plays, nothing after it.
    var hungUp = false;
    var buf = new Float32Array(1024);
    // A caller who goes quiet: asked once if they are still there, then the agent says goodbye
    // (Listen pays for every second the line stays open).
    var quietTimer = 0, heardAt = Date.now(), nudged = 0, quietEndAt = 0;

    function set(s) { if (state !== s) { state = s; opts.onState && opts.onState(s); } }
    function turn(role, text) {
      text = spoken(String(text || '')).trim();
      if (!text) return;
      var t = { role: role, text: text };
      pending.push(t);
      opts.onTurn && opts.onTurn(t);
    }
    function flushTurns() {
      if (callerText.trim()) { turn('caller', callerText); callerText = ''; }
      if (agentText.trim()) { turn('agent', agentText); agentText = ''; }
    }
    function addUsage(u) {
      if (!u) return;
      usage = usage || { promptTokenCount: 0, responseTokenCount: 0, thoughtsTokenCount: 0, promptTokensDetails: {}, responseTokensDetails: {} };
      usage.promptTokenCount += u.promptTokenCount || 0;
      usage.responseTokenCount += u.responseTokenCount || 0;
      usage.thoughtsTokenCount += u.thoughtsTokenCount || 0;
      (u.promptTokensDetails || []).forEach(function (d) { usage.promptTokensDetails[d.modality] = (usage.promptTokensDetails[d.modality] || 0) + (d.tokenCount || 0); });
      (u.responseTokensDetails || []).forEach(function (d) { usage.responseTokensDetails[d.modality] = (usage.responseTokensDetails[d.modality] || 0) + (d.tokenCount || 0); });
    }
    function usageOut() {
      if (!usage) return null;
      var o = { promptTokenCount: usage.promptTokenCount, responseTokenCount: usage.responseTokenCount, thoughtsTokenCount: usage.thoughtsTokenCount, promptTokensDetails: [], responseTokensDetails: [] };
      Object.keys(usage.promptTokensDetails).forEach(function (m) { o.promptTokensDetails.push({ modality: m, tokenCount: usage.promptTokensDetails[m] }); });
      Object.keys(usage.responseTokensDetails).forEach(function (m) { o.responseTokensDetails.push({ modality: m, tokenCount: usage.responseTokensDetails[m] }); });
      return o;
    }

    function play(data) {
      if (hungUp) return;
      var f = pcmFrom(data);
      if (!f.length) return;
      var b = ctx.createBuffer(1, f.length, 24000);
      b.copyToChannel(f, 0);
      var src = ctx.createBufferSource();
      src.buffer = b;
      src.connect(out);
      var at = Math.max(ctx.currentTime + 0.03, playHead);
      src.start(at);
      playHead = at + b.duration;
      sources.add(src);
      heardAt = Date.now();
      src.onended = function () {
        sources.delete(src);
        heardAt = Date.now();
        if (!sources.size) {
          if (state === 'speaking') set('listening');
          if (endAfterSpeech) finish('done');
        }
      };
      set('speaking');
    }
    function hush() {
      sources.forEach(function (s) { try { s.stop(); } catch (e) {} });
      sources.clear();
      playHead = ctx ? ctx.currentTime : 0;
      if (state === 'speaking') set('listening');
    }

    function loop() {
      raf = requestAnimationFrame(loop);
      if (!outAnalyser) return;
      var a = sources.size ? rms(outAnalyser, buf) : 0;
      var c = micAnalyser && !muted ? rms(micAnalyser, buf) : 0;
      if (c > 0.2) heardAt = Date.now();
      opts.onLevel && opts.onLevel(a, c);
    }

    // Every second, also in a background tab (where animation frames stop).
    function checkQuiet() {
      if (ended || !info || state === 'connecting') return;
      if (sources.size) { heardAt = Date.now(); return; }
      if (micAnalyser && !muted && rms(micAnalyser, buf) > 0.2) heardAt = Date.now();
      var quiet = (Date.now() - heardAt) / 1000;
      var limit = Math.max(8, info.silence_seconds || 20);
      if (quietEndAt && Date.now() > quietEndAt) { finish('silence'); return; }
      if (quiet < limit || !ws || ws.readyState !== 1) return;
      heardAt = Date.now();
      nudged++;
      if (nudged === 1) {
        ws.send(JSON.stringify({ realtimeInput: { text: '(The caller has been quiet for a while. Ask briefly, in the language of the call, whether they are still there.)' } }));
      } else {
        ws.send(JSON.stringify({ realtimeInput: { text: '(The caller is still quiet. Say a short, warm goodbye now and call end_call with the reason caller_left.)' } }));
        quietEndAt = Date.now() + 12000;
      }
    }

    function onMessage(ev) {
      var handle = function (raw) {
        var m;
        try { m = JSON.parse(raw); } catch (e) { return; }
        if (m.setupComplete) {
          set('listening');
          if (info.speaks_first) ws.send(JSON.stringify({ realtimeInput: { text: '(The caller has just joined the call. Greet them now, briefly.)' } }));
          return;
        }
        if (m.usageMetadata) addUsage(m.usageMetadata);
        if (m.toolCall) (m.toolCall.functionCalls || []).forEach(runTool);
        var sc = m.serverContent;
        if (!sc) return;
        if (sc.interrupted) hush();
        if (sc.inputTranscription && sc.inputTranscription.text) {
          heardAt = Date.now(); nudged = 0; quietEndAt = 0;
          if (agentText.trim()) { turn('agent', agentText); agentText = ''; }
          callerText += sc.inputTranscription.text;
        }
        if (sc.outputTranscription && sc.outputTranscription.text && !hungUp) {
          if (callerText.trim()) { turn('caller', callerText); callerText = ''; }
          agentText += sc.outputTranscription.text;
        }
        (sc.modelTurn && sc.modelTurn.parts || []).forEach(function (p) { if (p.inlineData && p.inlineData.data) play(p.inlineData.data); });
        if (sc.turnComplete) flushTurns();
      };
      if (typeof ev.data === 'string') handle(ev.data);
      else if (ev.data && ev.data.text) ev.data.text().then(handle);
    }

    function runTool(fc) {
      // end_call gets no answer: an answer makes the model speak again ("The call has ended.",
      // sometimes in English on an Arabic call). Its goodbye came before the call, so it plays.
      var hangUp = fc.name === 'end_call';
      if (hangUp) hungUp = true;
      post(info.api + '/tool', { call_key: info.call_key, name: fc.name, args: fc.args || {} }).then(function (result) {
        opts.onTool && opts.onTool(fc.name, fc.args || {}, result);
        if (hangUp) {
          if (sources.size) endAfterSpeech = true; else setTimeout(function () { finish('done'); }, 1200);
          return;
        }
        if (ws && ws.readyState === 1) ws.send(JSON.stringify({ toolResponse: { functionResponses: [{ id: fc.id, name: fc.name, response: result || { result: 'ok' } }] } }));
      });
    }

    function ping() {
      if (ended) return;
      var turns = pending.splice(0);
      post(info.api + '/ping', { call_key: info.call_key, turns: turns }).then(function (r) {
        if (r && r.stop) finish('limit');
      }).catch(function () { pending = turns.concat(pending); });
    }

    function finish(reason) {
      if (ended) return;
      ended = true;
      flushTurns();
      clearInterval(pingTimer); clearInterval(quietTimer); clearTimeout(stopTimer); cancelAnimationFrame(raf);
      try { ws && ws.close(); } catch (e) {}
      try { mic && mic.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
      try { node && node.disconnect(); } catch (e) {}
      if (ctx) setTimeout(function () { try { ctx.close(); } catch (e) {} }, 300);
      if (info) post(info.api + '/end', { call_key: info.call_key, turns: pending.splice(0), usage: usageOut(), reason: reason || 'done' }, true);
      opts.onLevel && opts.onLevel(0, 0);
      set(reason === 'error' ? 'error' : 'ended');
      window.removeEventListener('pagehide', onLeave);
    }
    function onLeave() { finish('caller_left'); }

    set('connecting');
    var micReady = navigator.mediaDevices && navigator.mediaDevices.getUserMedia
      ? navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } })
      : Promise.reject(new Error('no microphone'));

    micReady.then(function (stream) {
      mic = stream;
      if (ended) { stream.getTracks().forEach(function (t) { t.stop(); }); return null; }
      ctx = new (window.AudioContext || window.webkitAudioContext)();
      out = ctx.createGain();
      outAnalyser = ctx.createAnalyser(); outAnalyser.fftSize = 1024;
      out.connect(outAnalyser); outAnalyser.connect(ctx.destination);
      micSource = ctx.createMediaStreamSource(stream);
      micAnalyser = ctx.createAnalyser(); micAnalyser.fftSize = 1024;
      micSource.connect(micAnalyser);
      var url = URL.createObjectURL(new Blob([WORKLET], { type: 'application/javascript' }));
      return ctx.audioWorklet.addModule(url).then(function () {
        node = new AudioWorkletNode(ctx, 'listen-pcm');
        micSource.connect(node);
        return opts.begin();
      });
    }, function () {
      set('mic');
      ended = true;
      return null;
    }).then(function (data) {
      if (!data || ended) return;
      if (!data.token) throw new Error(data.message || 'no call');
      info = data;
      ws = new WebSocket(data.ws + '?access_token=' + encodeURIComponent(data.token));
      ws.onopen = function () { ws.send(JSON.stringify({ setup: { model: data.model } })); };
      ws.onmessage = onMessage;
      ws.onerror = function () { if (!ended) finish('error'); };
      ws.onclose = function () { if (!ended) finish(state === 'connecting' ? 'error' : 'caller_left'); };
      node.port.onmessage = function (e) {
        if (muted || !ws || ws.readyState !== 1 || state === 'connecting') return;
        ws.send(JSON.stringify({ realtimeInput: { audio: { data: b64(e.data), mimeType: 'audio/pcm;rate=16000' } } }));
      };
      pingTimer = setInterval(ping, 12000);
      heardAt = Date.now();
      quietTimer = setInterval(checkQuiet, 1000);
      stopTimer = setTimeout(function () { finish('limit'); }, Math.max(30, data.max_seconds || 600) * 1000);
      window.addEventListener('pagehide', onLeave);
      if (ctx.state === 'suspended') ctx.resume();
      loop();
    }).catch(function (err) {
      if (!ended) {
        ended = true;
        try { mic && mic.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
        try { ctx && ctx.close(); } catch (e) {}
        opts.onError && opts.onError(err && err.message, err);
        set('error');
      }
    });

    return {
      end: function () { finish('done'); },
      mute: function (on) { muted = !!on; },
      get state() { return state; },
    };
  }

  window.ListenAgentCall = { start: start };
})();
