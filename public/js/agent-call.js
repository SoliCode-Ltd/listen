/*
 * Listen Agents: one live call with an agent, in the browser.
 *
 *   var call = ListenAgentCall.start({
 *     begin: () => fetch(...),        // asks Listen for the call: { token, ws, model, call_key, api, max_seconds, speaks_first }
 *     onState(state) {},              // connecting, waiting, listening, speaking, ended, busy, error, mic
 *     onTurn(turn) {},                // { role: 'agent' | 'caller', text }; an agent line that showed as it
 *                                     // was said has `live`, the n of its onWords
 *     onWords(words) {},              // { n, text }: the agent's line so far, while it says it; { n, gone }
 *                                     // when that line is dropped (said in the wrong language)
 *     onHold(turn) {},                // a line being written again (transcribe, agent-line): its place in the
 *                                     // chat now, its words with onTurn
 *     onHear(on) {},                  // the caller has started a line (true: its place in the chat while they
 *                                     // speak, filled by its onHold/onTurn), or it came to nothing (false)
 *     onLevel(agent, caller) {},      // loudness 0..1 of the agent and of the caller, every frame
 *     onTool(name, args, result) {},  // what the agent did (save_contact ...)
 *     onLinks(item) {},               // { links: [{ url, label, network }] }: the business's own links,
 *                                     // to write in the chat where they came in the call (show_links)
 *     onWait({ position, waited }) {},// every line busy: the caller's place in line (1 is next)
 *     patience: 60,                   // seconds to wait for a line before "busy"
 *   });
 *   call.mute(true); call.end(); call.giveUp();
 *
 * When every line on the business's plan is taken, begin() answers { error: 'lines' }: the call
 * waits (state waiting) and asks again every few seconds, and the first caller to wait gets the
 * next free line. After `patience` seconds, or giveUp(), it stops (state busy) and lets go of the
 * microphone, so the page can offer to leave a number instead.
 *
 * The microphone is read at 16 kHz and sent as it comes; the agent's voice
 * (24 kHz) is played as it arrives and stops the moment the caller talks over
 * it. Listen hears every few seconds that the call is on (ping), what the agent
 * asked for (tool) and how it ended (end), with what was said.
 *
 * The caller's words: the live transcription sometimes writes Arabic as English ("unbelievable")
 * or in Latin letters ("Shukran khaseer"). When Listen offers it (transcribe), each caller line is
 * written again from the caller's own voice, in the agent's dialect (POST …/transcribe). The line
 * waits a moment for it, then shows what the live transcription heard; a better line that comes
 * later replaces it (onFix(turn), the same turn object onTurn gave).
 *
 * The agent's words: the live transcription of its own voice sometimes writes its Arabic in Latin
 * letters ("tfaḍḍal, kammil su'alak"). Such a line of an Arabic agent (arabic_lines) is written in
 * Arabic letters, word for word, before it shows (POST …/agent-line), the same way (onFix).
 *
 * The agent's words show as it says them (onWords). Its transcription comes at speaking pace, in uneven
 * pieces; only whole words show (a word cut between two pieces waits for its end), a number read out
 * digit by digit shows once it is whole, and nothing shows until the line is plainly in the call's
 * language. They come a word at a time, as far as the agent's voice has gone (its words come
 * seconds before its voice says them, and its turn ends while that voice still plays): the line
 * grows with the voice, never ahead of it, and never shows whole before it is said (a real call's
 * answers waited up to 13.7 s, then showed 171 letters at once). A line cut short (the caller
 * talked over it) ends with the words its voice reached, on the page and in what Listen keeps. An Arabic agent's
 * line that turns to Latin letters stops growing there and shows again when written in Arabic ones.
 *
 * The agent's language: a line it starts in a language it should not speak (a test call on live:
 * an Arabic agent answered in Spanish after three minutes) is stopped as soon as its first words
 * show it, never written in the chat, and the agent is asked to say it again in the call's
 * language (speaks). tongue() sorts a line the same way as App\Support\Agents\Tongue.
 *
 * The office behind the agent (background, App\Support\Agents\CallBackground; Design → Call
 * sound): colleagues talking quietly far off, as on a real office line. A seamless loop played
 * straight to the speaker, apart from the agent's own sound (the orb and the level never move with
 * it) and steady, never dipping while the agent speaks; it fades in as the call connects and out
 * as it ends. It sits `under` dB below the agent's voice, as that voice is heard on this call
 * (officeLevel); Design → Call sound → Hear the difference plays the agent's preview line with the
 * office at the same depth, measured the same way (spokenPower).
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
  // Egyptians read their mobile prefix as a number (010 is "زيرو عشرة"), so those words are digits too.
  var AR_DIGITS = { 'صفر': 0, 'زيرو': 0, 'واحد': 1, 'اثنين': 2, 'إثنين': 2, 'اتنين': 2, 'اثنان': 2, 'إثنان': 2, 'اتنان': 2, 'تنين': 2, 'ثنين': 2, 'تنتين': 2,
    'ثلاثة': 3, 'ثلاثه': 3, 'تلاتة': 3, 'تلاته': 3, 'ثلاث': 3, 'تلات': 3, 'أربعة': 4, 'اربعة': 4, 'أربعه': 4, 'اربعه': 4, 'أربع': 4, 'اربع': 4,
    'خمسة': 5, 'خمسه': 5, 'خمس': 5, 'ستة': 6, 'سته': 6, 'ست': 6, 'سبعة': 7, 'سبعه': 7, 'سبع': 7,
    'ثمانية': 8, 'ثمانيه': 8, 'تمانية': 8, 'تمانيه': 8, 'تمنية': 8, 'تمنيه': 8, 'ثماني': 8, 'تماني': 8, 'ثمان': 8, 'تمن': 8, 'تسعة': 9, 'تسعه': 9, 'تسع': 9,
    'عشرة': '10', 'عشره': '10', 'حداشر': '11', 'احداشر': '11', 'إحداشر': '11', 'اتناشر': '12', 'إتناشر': '12', 'اطناشر': '12', 'اثناشر': '12', 'خمستاشر': '15', 'خمسطاشر': '15' };
  var INDIC = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' };
  var EN_DIGITS = { zero: '0', oh: '0', one: '1', two: '2', three: '3', four: '4', five: '5', six: '6', seven: '7', eight: '8', nine: '9' };
  // The words at the end of a line still being said that may yet join a number (digits, or the
  // words for them): they wait for the next word, so a number never shows half written.
  var DIGIT_TAIL = (function () {
    var words = Object.keys(AR_DIGITS).concat(Object.keys(EN_DIGITS), ['double', 'triple']).sort(function (a, b) { return b.length - a.length; }).join('|');
    try { return new RegExp('(?:(?:^|\\s)(?:' + words + '|[+\\d][\\d\\-]*)[\\s،,\\-]*)+$', 'iu'); } catch (e) { return /(?:(?:^|\s)[+\d][\d\-]*[\s,\-]*)+$/; }
  })();
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
      // A tool call the model wrote out instead of making it (<metadata>{"action": "end_call", …}
      // </metadata>, seen once on a test call) was never said either. Braces may nest.
      text = text.replace(/<!--[\s\S]*?-->/g, '').replace(/<(metadata|tool_call|function_call)\b[^>]*>[\s\S]*?<\/\1>/gi, '')
        .replace(/<(metadata|tool_call|function_call)\b[^>]*>[\s\S]*$/gi, '').replace(/<[^>]*>/g, '');
      for (var i = 0; i < 3 && /\{[^{}]*\}/.test(text); i++) text = text.replace(/\{[^{}]*\}/g, '');
      text = text.replace(/[{}]/g, '');
      // Arabic-Indic digits as the site writes every number (٠١٠ is 010).
      text = text.replace(/[\u0660-\u0669\u06F0-\u06F9]/g, function (d) { return INDIC[d]; });
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
      // A number the transcription spaced out digit by digit ("0 10 6 8 1 2 1 7 0 5"): one number.
      // On an Arabic line each spaced digit is laid out right to left, so it read backwards.
      // Groups the speaker made (087 123 4567) have three digits or more and stay.
      text = text.replace(/(^|[^\d+])(\d{1,2}(?:[ \u00a0]+\d{1,2}){3,})(?!\d)/g, function (m, pre, run) {
        var digits = run.replace(/\D/g, '');
        return digits.length >= 6 ? pre + digits : m;
      });
      return text.replace(/\s+/g, ' ');
    };
  })();
  /*
   * A line as the page shows it: a number with spaces or dashes inside (050 123 4567), or with
   * a plus sign (+966…), kept in one left-to-right piece, so an Arabic line shows it in its own
   * order. plain() takes those marks out again for what Listen keeps.
   */
  var isolate = (function () {
    var re = null;
    try { re = new RegExp('(^|[^\\p{L}\\p{N}\\u2066])(\\+\\d{6,15}|\\+?\\d[\\d\\- ]*[\\- ]\\d[\\d\\- ]*\\d)(?=$|[^\\p{L}\\p{N}])', 'gu'); } catch (e) { re = null; }
    return function (text) { return re ? text.replace(re, '$1\u2066$2\u2069') : text; };
  })();
  function plain(text) { return String(text || '').replace(/[\u2066\u2069]/g, ''); }

  // An Arabic agent's line that the live transcription wrote mostly in Latin letters ("tfaḍḍal,
  // kammil su'alak"): it is written again in Arabic ones (agent-line) before it shows.
  function latinArabic(text) {
    var latin = (String(text).match(/[A-Za-z\u00C0-\u024F\u1E00-\u1EFF]/g) || []).length;
    var arabic = (String(text).match(/[\u0600-\u06FF\u0750-\u077F]/g) || []).length;
    return latin >= 6 && latin > arabic;
  }

  /*
   * Which language a line is in (the same rules as App\Support\Agents\Tongue): ar, short (too
   * few letters to tell), latin (Arabic in Latin letters, a name), en, or other (Spanish, French,
   * Portuguese, Italian or German from their words, accents and ¿ ¡; or another alphabet).
   * Words that are also Arabic in Latin letters (el, la, de, ya, nos…) are left out.
   */
  function wordSet(list) { var o = {}; list.split(' ').forEach(function (w) { o[w] = 1; }); return o; }
  var EU_WORDS = wordSet('que qué los las por para una está están puedo puede usted gracias hola también pero cómo cuándo dónde muy más información ahora momento ayudar ayudarte je vous nous est sont les des une pour avec pas oui merci bonjour très votre você não obrigado obrigada uma sono della grazie ich und ist nicht bitte danke');
  var EN_WORDS = wordSet("the and you your are is would can have with this that what please thank thanks hello our will for it's i'm don't help sure let check");
  function tongue(text) {
    text = String(text || '');
    var arabic = (text.match(/[\u0600-\u06FF\u0750-\u077F]/g) || []).length;
    var latin = (text.match(/[A-Za-z\u00C0-\u024F\u1E00-\u1EFF]/g) || []).length;
    var script = (text.match(/[\u0370-\u03FF\u0400-\u04FF\u0590-\u05FF\u0900-\u097F\u0E00-\u0E7F\u3040-\u30FF\u4E00-\u9FFF\uAC00-\uD7AF]/g) || []).length;
    if (script >= 4 && script > arabic && script > latin) return 'other';
    if (arabic >= latin) return 'ar';
    if (latin < 8) return 'short';
    var lower = text.toLowerCase(), eu = 0, en = 0, seen = {};
    (lower.match(/[a-z\u00C0-\u024F\u1E00-\u1EFF']+/g) || []).forEach(function (w) {
      if (seen[w]) return;
      seen[w] = 1;
      if (EU_WORDS[w] === 1) eu++;
      if (EN_WORDS[w] === 1) en++;
    });
    if (/[¿¡ñçßãõœ]/.test(lower)) eu += 2;
    if ((lower.match(/[áéíóúàèìòùâêîôûäëïöü]/g) || []).length >= 2) eu += 1;
    if (eu >= 2 && eu > en) return 'other';
    return en >= 2 ? 'en' : 'latin';
  }
  // How many lines a call stops for their language at most, and how far apart it asks again (ms).
  var MAX_REDO = 4, REDO_GAP = 8000;

  // How long a caller line waits for its better writing (ms), and how loud a 0.1 s piece of the
  // microphone must be to count as speech (16-bit RMS; a quiet room under noise suppression is ~100).
  var WAIT_FIX = 3500, VOICED = 450;
  // The agent's voice on a call, before it has said a word (RMS of its spoken 0.05 s pieces; a real
  // call measured -15 dBFS): the background starts under this, then follows the voice as it is heard.
  var VOICE_USUAL = 0.178;
  // A 0.05 s piece of the agent's voice counts as speech when louder than -40 dBFS (its mean power).
  var SPOKEN = 1e-4;
  // How loud the office plays under a voice whose spoken pieces have the mean power `pow` (0 before
  // it has said a word): `under` dB below that voice. A call and the Design tab's sample both use it.
  function officeLevel(pow, bg) {
    var voice = pow > 0 ? Math.sqrt(pow) : VOICE_USUAL;
    voice = Math.max(VOICE_USUAL / 2.5, Math.min(VOICE_USUAL * 2.5, voice));
    return voice * Math.pow(10, -(+bg.under || 49) / 20) / (+bg.rms || 0.05);
  }
  // The mean power of a recording's spoken 0.05 s pieces, as a call measures its agent (hearVoice).
  function spokenPower(f, rate) {
    var size = Math.max(1, Math.round(rate * 0.05)), sum = 0, n = 0;
    for (var i = 0; i + size <= f.length; i += size) {
      var p = 0;
      for (var j = i; j < i + size; j++) p += f[j] * f[j];
      p /= size;
      if (p < SPOKEN) continue;
      sum += p;
      n++;
    }
    return n ? sum / n : 0;
  }

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
  /*
   * The agent's voice comes at 24 kHz and plays in the microphone's own engine, at the speaker's rate:
   * one engine for both, as every phone plays it (a second engine of the voice's own rate stayed
   * silent on phones). Each piece is brought to the engine's rate here, carrying on from the piece
   * before, with the kind of converter a browser uses for a whole stream: a 64-tap Blackman-windowed
   * sinc, cut at 0.92 of the lower rate's top. Pieces played at 24 kHz in a faster engine would each
   * be converted on their own, roughly: a metallic shimmer above the voice and a dull top.
   * push(piece) gives what is ready (it holds 0.67 ms back for the next piece), end() the rest of
   * a line, reset() drops what it holds. Null for a rate it has no table for (then the browser
   * converts, as before).
   */
  function converter(from, to) {
    from = Math.round(from); to = Math.round(to);
    if (from === to) return { rate: to, push: function (x) { return x; }, end: function () { return new Float32Array(0); }, reset: function () {} };
    var a = from, b = to;
    while (b) { var t = a % b; a = b; b = t; }
    var L = to / a, M = from / a, H = 32, W = 2 * H;
    if (!(L >= 1 && L <= 4096)) return null;
    var fc = 0.92 * Math.min(1, L / M), table = new Float32Array(L * W);
    for (var p = 0; p < L; p++) {
      var sum = 0;
      for (var k = 0; k < W; k++) {
        var d = k - H + 1 - p / L, u = d / H, x = Math.PI * fc * d;
        var h = (x ? Math.sin(x) / x : 1) * (0.42 + 0.5 * Math.cos(Math.PI * u) + 0.08 * Math.cos(2 * Math.PI * u));
        table[p * W + k] = h; sum += h;
      }
      for (k = 0; k < W; k++) table[p * W + k] /= sum;
    }
    var rest, phase;
    function reset() { rest = new Float32Array(H - 1); phase = 0; }
    function push(x) {
      var all = new Float32Array(rest.length + x.length);
      all.set(rest); all.set(x, rest.length);
      var y = new Float32Array(Math.ceil(all.length * L / M) + 2), j = 0, cur = 0;
      while (cur + W <= all.length) {
        var o = phase * W, s = 0;
        for (var k = 0; k < W; k++) s += table[o + k] * all[cur + k];
        y[j++] = s;
        phase += M;
        cur += Math.floor(phase / L);
        phase %= L;
      }
      rest = all.slice(cur);
      return y.subarray(0, j);
    }
    reset();
    return { rate: to, push: push, end: function () { var y = push(new Float32Array(H)); reset(); return y; }, reset: reset };
  }
  /*
   * What the microphone hears while the agent's voice plays (and ECHO_TAIL ms after) is mostly that
   * voice coming back from the speakers. The voice model took it for the caller: on a laptop it cut
   * the agent's greeting and answered the echo of one of its own words ("إسمع"), so the greeting
   * came twice. So it is sent as silence unless the caller plainly speaks over the agent: louder than
   * BARGE_MIN and three times what the speakers usually leave in the microphone, for BARGE_PIECES
   * pieces (0.1 s each) in a row, after the first BARGE_LEARN ms of the agent's line. Then the pieces
   * held back (0.4 s) go first, so their first word is not lost, and everything goes until the agent
   * stops. A caller who starts as the agent stops keeps what was held back too.
   */
  var ECHO_TAIL = 300, BARGE_MIN = 1000, BARGE_PIECES = 3, BARGE_LEARN = 400, BARGE_HOLD = 4;
  function micLevel(buffer) {
    var a = new Int16Array(buffer), sum = 0, n = 0;
    for (var i = 0; i < a.length; i += 4) { sum += a[i] * a[i]; n++; }
    return n ? Math.sqrt(sum / n) : 0;
  }
  function micGate() {
    var echo = 0, loud = 0, held = [], open = false, since = 0;
    return function (buffer, agentOn, now) {
      if (!agentOn) {
        // The agent has just stopped: a caller who had started speaking keeps what was held back.
        var keep = !open && loud ? held.concat([buffer]) : [buffer];
        open = false; loud = 0; held = []; since = 0;
        return keep;
      }
      if (open) return [buffer];
      if (!since) since = now;
      var l = micLevel(buffer);
      held.push(buffer);
      if (held.length > BARGE_HOLD) held.shift();
      if (now - since >= BARGE_LEARN && l > Math.max(BARGE_MIN, 3 * echo)) loud++;
      else { loud = 0; echo = echo ? echo + (l - echo) * 0.15 : l; }
      if (loud >= BARGE_PIECES) { open = true; var out = held; held = []; return out; }
      return [new ArrayBuffer(buffer.byteLength)];
    };
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
    var ws = null, ctx = null, vctx = null, mic = null, node = null, micSource = null;
    var out = null, outAnalyser = null, micAnalyser = null;
    // The agent's voice: each piece plays straight after the one before. A new line (its first piece,
    // with nothing playing for a moment) starts with a little of the voice in hand, START_LEAD, so a
    // short pause in the voice model's stream early in the line does not break a word: a test call's
    // greeting stopped arriving for 0.8 s after its first 0.76 s, and 30 ms in hand left 0.18 s of
    // silence mid-word. Within a line a late piece plays at once (any wait would only lengthen a gap).
    var playFrame = 0, sources = new Set(), voiceEndAt = 0, START_LEAD = 0.15, conv = null;
    // The echo guard (micGate, above), and when the caller last spoke with the agent quiet.
    var gate = micGate(), callerVoiceAt = 0;
    // The caller's words come on their own, in no set order, often after the agent has started its
    // answer. Words of a line they said before the agent's line (lineAt, when its voice started)
    // that was not cut short (cutLine) are written above that line, and it stays whole; they end
    // LATE_GAP ms after their last piece, or with the agent's turn.
    var lineAt = 0, cutLine = false, lateCaller = false, lateTimer = 0, LATE_GAP = 900;
    var info = null, pending = [], agentText = '', callerText = '', usage = null;
    // The agent's line on the page while it says it: its number, its whole words, how many show.
    var live = null, liveSeq = 0;
    // The sound of the agent's line that is coming now (voice): the engine frames it plays from and
    // to; each live line keeps its own. Lines whose turn came while their voice still plays (holding).
    var voice = null, holding = [];
    var pingTimer = 0, stopTimer = 0, raf = 0;
    // The agent hangs up (end_call): what it is still saying plays to the end of its turn (closing; the
    // goodbye's last words can come after the hang-up), then nothing more (hungUp).
    var hungUp = false, closing = false, closingDone = false;
    var buf = new Float32Array(1024);
    // A caller who goes quiet: asked once if they are still there, then the agent says goodbye
    // (Listen pays for every second the line stays open).
    var quietTimer = 0, heardAt = Date.now(), nudged = 0, quietEndAt = 0;
    var waitTimer = 0;
    // The caller's own voice since their last line (Int16 pieces of 0.1 s, each marked voiced or
    // not), for writing that line again (transcribe). What the microphone hears while the agent
    // speaks waits apart (overlap): it is the caller only when they talk over the agent.
    var heard = [], overlap = [], callerSeq = 0, agentSeq = 0, queue = [], lastSaid = '';
    // The language guard: the caller's last language, whether the agent's answer now coming is
    // being dropped for its language (until that turn ends), and how often it happened.
    var callerTongue = '', dropTurn = false, redone = 0, redoneAt = 0;
    // The office behind the agent: its sound once fetched, where it plays, whether the call is
    // on, and the agent's voice it follows (the mean power of its spoken 0.05 s pieces).
    var bg = null, bgBuffer = null, bgSrc = null, bgGain = null, bgOn = false, bgLevel = 0, voicePow = 0, voiceN = 0;

    function set(s) { if (state !== s) { state = s; opts.onState && opts.onState(s); } }

    function closeEngines(delay) {
      var list = [ctx, vctx].filter(function (c, i, all) { return c && all.indexOf(c) === i; });
      var close = function () { list.forEach(function (c) { try { c.close(); } catch (e) {} }); };
      if (delay) setTimeout(close, delay); else close();
    }

    // Every line taken: ask again every few seconds, keeping the caller's place, until a line is
    // free, the caller gives up, or `patience` runs out. A call that starts after the caller left
    // is ended at once, so it never holds a line.
    function inLine() {
      var since = Date.now(), patience = Math.max(10, opts.patience || 60) * 1000;
      return new Promise(function (resolve, reject) {
        (function attempt() {
          if (ended) { resolve(null); return; }
          Promise.resolve(opts.begin()).then(function (data) {
            if (ended) {
              if (data && data.token && data.api) post(data.api + '/end', { call_key: data.call_key, turns: [], reason: 'caller_left' }, true);
              resolve(null);
              return;
            }
            if (!data || data.error !== 'lines') {
              if (state === 'waiting') set('connecting');
              resolve(data);
              return;
            }
            if (Date.now() - since >= patience) { toBusy(); resolve(null); return; }
            opts.onWait && opts.onWait({ position: data.position || 0, waited: Math.round((Date.now() - since) / 1000) });
            set('waiting');
            waitTimer = setTimeout(attempt, Math.max(3, Math.min(10, data.retry_after || 5)) * 1000);
          }, function (e) {
            // No answer at all (the internet dropped): the page says so in its own words, never
            // the browser's "Failed to fetch".
            if (e && (e.name === 'TypeError' || (typeof navigator !== 'undefined' && navigator.onLine === false))) e.offline = true;
            reject(e);
          });
        })();
      });
    }
    // Still no free line: let go of the microphone; the page offers to leave a number.
    function toBusy() {
      if (ended) return;
      ended = true;
      clearTimeout(waitTimer);
      try { mic && mic.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
      try { node && node.disconnect(); } catch (e) {}
      closeEngines(300);
      set('busy');
    }
    function turn(role, text) {
      text = spoken(String(text || '')).trim();
      // Cut short: only the words its voice reached.
      if (role === 'agent' && live && live.cut) text = plain(live.units.join(' '));
      if (!text) {
        if (role === 'agent') endLive(null);
        else if (role === 'caller' && opts.onHear) opts.onHear(false);
        return;
      }
      var t = { role: role, text: isolate(text) };
      if (role === 'agent') {
        var v = (live && live.voice) || voice;
        endLive(t);
        if (!t.hold && !t.live && voicing(v)) holdFor(t, v);
        if (voice === v) voice = null;
      }
      queue.push(t);
      if (role === 'agent') lastSaid = text;
      if (role === 'caller') { var k = tongue(text); if (k !== 'short') callerTongue = k; }
      if (role === 'caller' && info && info.transcribe && info.api) hearAgain(t, text);
      // An Arabic agent's line in Latin letters is written in Arabic ones; plainly English words to a
      // caller who speaks English are English, and stay as they are.
      if (role === 'agent' && info && info.arabic_lines && info.api && latinArabic(text) && !english(text)) writeAgain(t, text);
      // A line being written again has its place in the chat now, before what is said after it.
      if (t.wait && opts.onHold) opts.onHold(t);
      drain();
    }
    // What of the agent's line so far may show: its whole words, once it is plainly in the call's
    // language, without the end of a number still being read out (whole: the line is said).
    function liveUnits(text, whole) {
      text = String(text || '');
      if (!whole && !/[\s.,!?؟،؛…]$/.test(text)) text = text.replace(/\S+$/, '');
      var k = tongue(spoken(text));
      if ((k !== 'ar' && k !== 'en') || wrongTongue(text)) return null;
      if (info && info.arabic_lines && latinArabic(text) && k !== 'en') return null;
      text = spoken(text);
      if (!whole) text = text.replace(DIGIT_TAIL, '');
      text = isolate(text.trim());
      return text ? text.match(/\u2066[^\u2069]*\u2069\S*|\S+/g) || [] : [];
    }
    function showLive() {
      if (!opts.onWords || dropTurn || (live && live.cut)) return;
      var units = liveUnits(agentText, false);
      if (!units || !units.length) return;
      if (!live) live = { n: ++liveSeq, units: [], shown: 0, timer: 0, voice: voice || (voice = { from: -1, to: -1 }) };
      // Words already shown that read differently now (three digits written as one number): the line
      // shows as it reads now from there.
      var same = 0;
      while (same < live.shown && same < units.length && units[same] === live.units[same]) same++;
      live.units = units;
      if (same < live.shown) { live.shown = same; opts.onWords({ n: live.n, text: units.slice(0, same).join(' ') }); }
      if (!live.timer) pace(live);
    }
    // How much of a line's sound has played (0 to 1), and whether some of it is still to play. Its
    // words come faster than it says them (a whole answer's text, then its turn, while its voice
    // plays on for seconds), so they show as far as its voice has gone, never ahead of it.
    function voiced(v) {
      if (!v || v.from < 0 || !vctx) return 0;
      if (v.cut || v.to <= v.from) return 1;
      return Math.max(0, Math.min(1, (vctx.currentTime * vctx.sampleRate - v.from) / (v.to - v.from)));
    }
    function voicing(v) { return !!(v && v.from >= 0 && !v.cut && vctx && vctx.currentTime * vctx.sampleRate < v.to); }
    // The next word once the voice has reached it: the share of the words that the share of the
    // sound played says (a word at a time, each with its fade). A line whose turn came (l.done) ends
    // when its voice does.
    function pace(l) {
      clearTimeout(l.timer);
      l.timer = 0;
      var due = Math.min(l.units.length, Math.round(l.units.length * voiced(l.voice)));
      if (due > l.shown) {
        l.shown++;
        opts.onWords({ n: l.n, text: l.units.slice(0, l.shown).join(' ') });
      }
      if (l.done && (l.shown >= l.units.length || !voicing(l.voice))) return said(l);
      if (l.shown < l.units.length) l.timer = setTimeout(pace, l.shown < due ? 70 : 110, l);
    }
    // The line is said: what showed of it goes with its turn (t.live), or goes away when it is
    // dropped. A turn that came while its voice still plays waits for it (t.hold), and the lines after it.
    function endLive(t) {
      if (!live) return;
      var l = live;
      live = null;
      if (voice === l.voice) voice = null;
      clearTimeout(l.timer);
      l.timer = 0;
      if (t && l.shown && voicing(l.voice)) {
        var whole = liveUnits(plain(t.text), true);
        if (whole && whole.length) l.units = whole;
        l.done = t;
        t.hold = true;
        holding.push(l);
        pace(l);
        return;
      }
      if (l.shown && t) t.live = l.n;
      else if (l.shown && opts.onWords) opts.onWords({ n: l.n, text: '', gone: true });
    }
    function said(l) {
      clearTimeout(l.timer);
      l.timer = 0;
      var i = holding.indexOf(l);
      if (i >= 0) holding.splice(i, 1);
      var t = l.done;
      if (!t || !t.hold) return;
      t.hold = false;
      if (l.shown) t.live = l.n;
      drain();
    }
    // A turn with no live words whose voice still plays (a line being written in Arabic letters):
    // it shows when its voice ends.
    function holdFor(t, v) {
      t.hold = true;
      (function wait() {
        if (!t.hold) return;
        if (voicing(v)) { setTimeout(wait, 150); return; }
        t.hold = false;
        drain();
      })();
    }
    // The agent was cut short (the caller talked over it): its line on the page and in what Listen
    // keeps ends with the words its voice had reached, never the ones it did not say.
    function cutVoice() {
      var reached = function (l) {
        var due = Math.min(l.units.length, Math.max(l.shown, Math.round(l.units.length * voiced(l.voice))));
        l.voice.cut = true;
        if (due > l.shown) { l.shown = due; opts.onWords({ n: l.n, text: l.units.slice(0, due).join(' ') }); }
        l.units = l.units.slice(0, l.shown);
        l.cut = true;
        clearTimeout(l.timer);
        l.timer = 0;
      };
      if (live && !live.cut) reached(live);
      if (voice) voice.cut = true;
      holding.slice().forEach(function (l) {
        var t = l.done, all = l.units.length;
        reached(l);
        if (t && l.shown < all) { t.text = l.units.join(' '); lastSaid = plain(t.text); }
        said(l);
      });
    }
    // The agent's line in Arabic letters, word for word. It waits WAIT_FIX at most, like a caller's
    // line; a writing that comes later still replaces it on the page and in what Listen keeps.
    function writeAgain(t, latin) {
      t.a = ++agentSeq;
      t.wait = true;
      var done = false;
      var letGo = function () { if (t.wait) { t.wait = false; drain(); } };
      var timer = setTimeout(letGo, WAIT_FIX);
      post(info.api + '/agent-line', { call_key: info.call_key, n: t.a, text: latin, wrong: wrongTongue(latin) ? 1 : 0 }).then(function (r) {
        if (done) return;
        done = true;
        var fixed = r && typeof r.text === 'string' ? spoken(r.text).trim() : '';
        if (fixed && !latinArabic(fixed)) {
          t.text = isolate(fixed);
          t.heard = latin;
          lastSaid = fixed;
          if (t.kept) { t.kept.text = fixed; t.kept.heard = latin; }
          if (t.shown && opts.onFix) opts.onFix(t);
        }
        clearTimeout(timer);
        letGo();
      }, function () { clearTimeout(timer); letGo(); });
    }
    // The lines in their order: a caller line waiting for its better writing holds the ones after it.
    function drain() {
      while (queue.length && !queue[0].wait && !queue[0].hold) {
        var t = queue.shift();
        t.shown = true;
        if (t.role === 'links') {
          // Kept with the call's words as the agent's line, marked as links.
          pending.push({ role: 'agent', kind: 'links', text: t.links.map(function (l) { return l.label; }).join(' · ') });
          opts.onLinks && opts.onLinks(t);
          continue;
        }
        t.kept = { role: t.role, text: plain(t.text) };
        if (t.n) t.kept.n = t.n;
        if (t.a) t.kept.a = t.a;
        if (t.heard) t.kept.heard = t.heard;
        pending.push(t.kept);
        opts.onTurn && opts.onTurn(t);
      }
    }
    function release() {
      holding.slice().forEach(said);
      queue.forEach(function (t) { t.wait = false; t.hold = false; });
      drain();
    }
    // The caller's line written again from their voice, in the agent's dialect. It waits WAIT_FIX
    // at most; a better line that comes later still replaces it on the page and in what Listen keeps.
    function hearAgain(t, heardText) {
      t.n = ++callerSeq;
      var audio = takeHeard();
      if (!audio) return;
      t.wait = true;
      var done = false;
      var letGo = function () { if (t.wait) { t.wait = false; drain(); } };
      var timer = setTimeout(letGo, WAIT_FIX);
      post(info.api + '/transcribe', { call_key: info.call_key, n: t.n, audio: audio.data, rate: audio.rate, heard: heardText, said: lastSaid }).then(function (r) {
        if (done) return;
        done = true;
        var fixed = r && typeof r.text === 'string' ? spoken(r.text).trim() : '';
        if (fixed && fixed !== heardText) {
          t.text = isolate(fixed);
          t.heard = heardText;
          if (t.kept) { t.kept.text = fixed; t.kept.heard = heardText; }
          if (t.shown && opts.onFix) opts.onFix(t);
        }
        clearTimeout(timer);
        letGo();
      }, function () { clearTimeout(timer); letGo(); });
    }
    function keepHeard(buffer) {
      var a = new Int16Array(buffer), sum = 0, n = 0;
      for (var i = 0; i < a.length; i += 4) { sum += a[i] * a[i]; n++; }
      var piece = { b: buffer, v: n > 0 && Math.sqrt(sum / n) > VOICED };
      if (sources.size) {
        overlap.push(piece);
        if (overlap.length > 10) overlap.shift();
        return;
      }
      heard.push(piece);
      if (heard.length > 400) heard.shift();
    }
    // The voiced stretch of what the caller said since their last line, as 16-bit PCM in base64:
    // at 16 kHz up to 15 s, at 8 kHz up to 30 s; nothing when they hardly spoke or spoke longer.
    function takeHeard() {
      var all = heard, first = -1, last = -1, voiced = 0;
      heard = [];
      for (var i = 0; i < all.length; i++) if (all[i].v) { if (first < 0) first = i; last = i; voiced++; }
      if (voiced < 3) return null;
      var from = Math.max(0, first - 3), to = Math.min(all.length, last + 4);
      // Longer than 30 s: written again it would lose its start, so the live line stays.
      if (to - from > 300) return null;
      var half = to - from > 150, size = 0;
      for (var j = from; j < to; j++) size += all[j].b.byteLength;
      var out = new Int16Array((half ? size / 4 : size / 2) | 0), at = 0;
      for (var k = from; k < to; k++) {
        var p = new Int16Array(all[k].b);
        if (!half) { out.set(p, at); at += p.length; continue; }
        for (var m = 0; m + 1 < p.length; m += 2) out[at++] = (p[m] + p[m + 1]) >> 1;
      }
      return { data: b64(out.buffer.slice(0, at * 2)), rate: half ? 8000 : 16000 };
    }
    // The caller's words in their bubble as they say them (onHearWords), whole words only, when they
    // are plainly Arabic or English: their bubble no longer waits with dots while the agent's answer
    // already shows. Their line written again from their voice (hearAgain) puts the better words in place.
    // A line in Latin letters that is not plainly English (an Arabic dialect, or another language) waits
    // for its writing again.
    function heardWords() {
      if (!opts.onHearWords) return;
      var text = spoken(callerText);
      if (!/[\s.,!?؟،؛…]$/.test(text)) text = text.replace(/\S+$/, '');
      text = text.trim();
      if (!text) return;
      var k = tongue(text);
      if (k === 'latin' || k === 'other' || (k === 'short' && !/[\u0600-\u06FF]/.test(text) && ownTongue() !== 'en')) return;
      opts.onHearWords(isolate(text));
    }
    // A caller line whose words came late: it ends (endLate) after its last piece, before the agent's line.
    function endLate() {
      clearTimeout(lateTimer);
      if (!lateCaller) return;
      lateCaller = false;
      if (callerText.trim()) { turn('caller', callerText); callerText = ''; }
    }
    function flushTurns() {
      endLate();
      if (callerText.trim()) { turn('caller', callerText); callerText = ''; }
      if (agentText.trim()) {
        // A hang-up the model wrote out instead of calling end_call: its goodbye plays, then the
        // call ends as if the tool had been called.
        var endAsText = !ended && !hungUp && /["']?(?:action|name|tool)["']?\s*:\s*["']end_call["']/.test(agentText);
        var why = (agentText.match(/["']?reason["']?\s*:\s*["']([a-z_]+)["']/) || [])[1];
        turn('agent', agentText); agentText = '';
        if (endAsText) runTool({ id: null, name: 'end_call', args: { reason: ['done', 'caller_left', 'out_of_scope', 'handoff', 'abusive'].indexOf(why) >= 0 ? why : 'done' } });
      }
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
      if (hungUp || dropTurn) return;
      var f = pcmFrom(data);
      if (!f.length) return;
      if (bg) hearVoice(f);
      var fresh = !sources.size && Date.now() - voiceEndAt > 400;
      if (fresh) { lineAt = Date.now(); cutLine = false; }
      if (fresh && conv) conv.reset();
      queueVoice(conv ? conv.push(f) : f, fresh);
      set('speaking');
    }
    // A piece of the voice, straight after the one before, starting on one of the engine's frames
    // (so it plays sample for sample). A converted piece is at the engine's rate; without a converter
    // it stays at 24 kHz and the browser converts it.
    function queueVoice(y, fresh) {
      if (!y.length) return;
      var rate = conv ? conv.rate : 24000, R = vctx.sampleRate;
      var b = vctx.createBuffer(1, y.length, rate);
      b.copyToChannel(y, 0);
      var src = vctx.createBufferSource();
      src.buffer = b;
      src.connect(out);
      var at = Math.max(Math.ceil((vctx.currentTime + (fresh ? START_LEAD : 0.03)) * R), playFrame);
      src.start(at / R);
      playFrame = at + Math.round(b.duration * R);
      // The line's sound: a new one when the voice starts again after a pause, else it grows.
      if (!voice || (fresh && voice.from >= 0)) { voice = { from: at, to: playFrame }; if (live && !live.cut) live.voice = voice; }
      else { if (voice.from < 0) voice.from = at; voice.to = playFrame; }
      sources.add(src);
      heardAt = Date.now();
      src.onended = function () {
        sources.delete(src);
        heardAt = Date.now();
        if (!sources.size) {
          voiceEndAt = Date.now();
          overlap = [];
          if (state === 'speaking') set('listening');
        }
      };
    }
    // The end of the agent's turn: the last 0.67 ms the converter holds, straight after the rest
    // while it still plays (afterwards it is only the silence after the voice).
    function endVoice() {
      if (!conv) return;
      if (sources.size) queueVoice(conv.end(), false); else conv.reset();
    }
    // Whether an agent line is in a language it should not speak: never another language; an
    // Arabic agent speaks English only after the caller did, and an English agent Arabic.
    function ownTongue() { return info && info.agent && info.agent.language === 'en' ? 'en' : 'ar'; }
    function wrongTongue(text) {
      if (!info) return false;
      var own = ownTongue(), caller = callerTongue || own, k = tongue(spoken(String(text || '')));
      if (k === 'other') return true;
      if (k === 'en') return own !== 'en' && caller !== 'en' && caller !== 'latin';
      if (k === 'ar') return own === 'en' && caller !== 'ar' && caller !== 'latin';
      return false;
    }
    // The language the agent is asked to speak: the call's, or the caller's English (or Arabic).
    // A line plainly in English that the call may have (the caller speaks English).
    function english(text) { return tongue(spoken(String(text || ''))) === 'en' && !wrongTongue(text); }
    function callTongue() {
      var own = ownTongue();
      if (own === 'en') return callerTongue === 'ar' ? 'Arabic' : 'English';
      return callerTongue === 'en' ? 'English' : (info && info.speaks) || 'Arabic';
    }
    // An answer the agent starts in a wrong language: its sound stops at once, it is not written
    // in the chat, and the agent is asked (once in a while) to say it again in the call's language.
    // What it sends until that answer ends is dropped too (play, onMessage).
    function stopWrong() {
      dropTurn = true;
      redone++;
      agentText = '';
      endLive(null);
      overlap = [];
      hush();
      if (Date.now() - redoneAt < REDO_GAP || !ws || ws.readyState !== 1) return;
      redoneAt = Date.now();
      var say = callTongue();
      ws.send(JSON.stringify({ realtimeInput: { text: '(You started that answer in the wrong language, and the caller cannot follow it. Say the whole answer again now in ' + say + ', without mentioning this note, and keep to ' + say + '.)' } }));
    }
    // The office's sound, fetched as the call starts (from any website: it is open to all) and
    // decoded once; it starts when the call connects (startBackground), or as soon as it is ready.
    function loadBackground() {
      bg = info && info.background && info.background.url ? info.background : null;
      if (!bg || !vctx || !window.fetch) { bg = null; return; }
      fetch(bg.url, { credentials: 'omit' }).then(function (r) {
        if (!r.ok) throw new Error('sound');
        return r.arrayBuffer();
      }).then(function (data) {
        // Older Safari decodes with callbacks only.
        return new Promise(function (resolve, reject) {
          var p = vctx.decodeAudioData(data, resolve, reject);
          if (p && p.then) p.then(resolve, reject);
        });
      }).then(function (buffer) {
        bgBuffer = buffer;
        if (bgOn) startBackground();
      }).catch(function () { bg = null; });
    }
    function backgroundLevel() {
      return officeLevel(voiceN ? voicePow : 0, bg);
    }
    function startBackground() {
      bgOn = true;
      if (!bg || !bgBuffer || bgSrc || ended || !vctx) return;
      try {
        var loop = bg.loop || [0, bgBuffer.duration], from = +loop[0] || 0, to = Math.min(+loop[1] || bgBuffer.duration, bgBuffer.duration);
        bgGain = vctx.createGain();
        bgGain.gain.value = 0;
        bgSrc = vctx.createBufferSource();
        bgSrc.buffer = bgBuffer;
        bgSrc.loop = true;
        bgSrc.loopStart = from;
        bgSrc.loopEnd = to;
        bgSrc.connect(bgGain);
        bgGain.connect(vctx.destination);
        // From a different moment of the office on every call; it fades in over about two seconds.
        var at = vctx.currentTime + 0.05;
        bgSrc.start(at, from + Math.random() * (to - from));
        bgLevel = backgroundLevel();
        bgGain.gain.setTargetAtTime(bgLevel, at, 0.7);
      } catch (e) { bgSrc = null; }
    }
    // The agent's voice as it arrives: its 0.05 s pieces louder than -40 dBFS, averaged over its
    // first 20 s of speech and slowly after. The office follows it when it moves by more than 1 dB.
    function hearVoice(f) {
      for (var i = 0; i + 1200 <= f.length; i += 1200) {
        var p = 0;
        for (var j = i; j < i + 1200; j++) p += f[j] * f[j];
        p /= 1200;
        if (p < SPOKEN) continue;
        voiceN++;
        voicePow += (p - voicePow) / Math.min(voiceN, 400);
      }
      if (!bgGain || !voiceN) return;
      var level = backgroundLevel();
      if (Math.abs(level / bgLevel - 1) < 0.12) return;
      bgLevel = level;
      try { bgGain.gain.setTargetAtTime(level, vctx.currentTime, 2); } catch (e) {}
    }
    function stopBackground() {
      bgOn = false;
      if (!bgGain || !vctx) return;
      try {
        var now = vctx.currentTime;
        bgGain.gain.cancelScheduledValues(now);
        bgGain.gain.setTargetAtTime(0, now, 0.06);
        bgSrc.stop(now + 0.4);
      } catch (e) {}
    }
    function hush() {
      // The caller talked over the agent: what the microphone heard just before is their voice.
      if (overlap.length) { heard = heard.concat(overlap); overlap = []; }
      cutVoice();
      sources.forEach(function (s) { try { s.stop(); } catch (e) {} });
      sources.clear();
      voiceEndAt = Date.now();
      playFrame = 0;
      if (conv) conv.reset();
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
        ws.send(JSON.stringify({ realtimeInput: { text: '(The caller has been quiet for a while. Ask briefly, in ' + callTongue() + ', whether they are still there.)' } }));
      } else {
        ws.send(JSON.stringify({ realtimeInput: { text: '(The caller is still quiet. Say a short, warm goodbye now in ' + callTongue() + ' and call end_call with the reason caller_left.)' } }));
        quietEndAt = Date.now() + 12000;
      }
    }

    function onMessage(ev) {
      var handle = function (raw) {
        var m;
        try { m = JSON.parse(raw); } catch (e) { return; }
        if (m.setupComplete) {
          set('listening');
          startBackground();
          if (info.speaks_first) ws.send(JSON.stringify({ realtimeInput: { text: '(The caller has just joined the call. Greet them now, briefly, in ' + callTongue() + '.)' } }));
          return;
        }
        if (m.usageMetadata) addUsage(m.usageMetadata);
        if (m.toolCall) {
          // What the agent says after a tool's answer is a new piece, judged again for its language.
          dropTurn = false;
          (m.toolCall.functionCalls || []).forEach(runTool);
        }
        var sc = m.serverContent;
        if (!sc) return;
        if (sc.interrupted) { endLate(); cutLine = true; hush(); dropTurn = false; if (closing) closingDone = true; }
        if (sc.inputTranscription && sc.inputTranscription.text) {
          heardAt = Date.now(); nudged = 0; quietEndAt = 0;
          // Said before the agent's line that is coming now (they did not talk over it): above it.
          var late = !!agentText.trim() && !cutLine && callerVoiceAt < lineAt;
          if (agentText.trim() && !late) { turn('agent', agentText); agentText = ''; }
          // A new line from the caller (its first real words, not a noise mark): its place in the chat
          // now, while they speak.
          var saidBefore = spoken(callerText).trim();
          callerText += sc.inputTranscription.text;
          if (!saidBefore && spoken(callerText).trim() && opts.onHear) opts.onHear(true, late);
          heardWords();
          if (late) { lateCaller = true; clearTimeout(lateTimer); lateTimer = setTimeout(endLate, LATE_GAP); }
        }
        if (sc.outputTranscription && sc.outputTranscription.text && !hungUp && !dropTurn) {
          if (callerText.trim() && !lateCaller) { turn('caller', callerText); callerText = ''; }
          agentText += sc.outputTranscription.text;
          if (redone < MAX_REDO && wrongTongue(agentText)) stopWrong();
          else showLive();
        }
        (sc.modelTurn && sc.modelTurn.parts || []).forEach(function (p) { if (p.inlineData && p.inlineData.data) play(p.inlineData.data); });
        if (sc.turnComplete) { endVoice(); flushTurns(); dropTurn = false; if (closing) closingDone = true; }
      };
      if (typeof ev.data === 'string') handle(ev.data);
      else if (ev.data && ev.data.text) ev.data.text().then(handle);
    }

    // The business's own links (show_links), written in the chat after what was said before them.
    function showLinks(list) {
      var links = (list || []).filter(function (l) { return l && typeof l.url === 'string' && /^https?:\/\//i.test(l.url); })
        .map(function (l) { return { url: l.url, label: String(l.label || l.url), network: String(l.network || '') }; });
      if (!links.length) return;
      endLate();
      if (callerText.trim()) { turn('caller', callerText); callerText = ''; }
      if (agentText.trim()) { turn('agent', agentText); agentText = ''; }
      queue.push({ role: 'links', links: links, text: '' });
      drain();
    }

    function runTool(fc) {
      // end_call gets no answer: an answer makes the model speak again ("The call has ended.",
      // sometimes in English on an Arabic call). Its goodbye came before the call, so it plays.
      var hangUp = fc.name === 'end_call';
      if (hangUp) hangUpAfterGoodbye();
      post(info.api + '/tool', { call_key: info.call_key, name: fc.name, args: fc.args || {} }).then(function (result) {
        var heard = result || { result: 'ok' };
        if (fc.name === 'show_links' && result && result.links) {
          showLinks(result.links);
          // The model hears that they were written, never the addresses (it would read them out).
          heard = {};
          for (var k in result) if (k !== 'links' && Object.prototype.hasOwnProperty.call(result, k)) heard[k] = result[k];
        }
        opts.onTool && opts.onTool(fc.name, fc.args || {}, result);
        if (hangUp) return;
        if (ws && ws.readyState === 1) ws.send(JSON.stringify({ toolResponse: { functionResponses: [{ id: fc.id, name: fc.name, response: heard } ] } }));
      });
    }

    // The goodbye plays to the end of the agent's turn (4 s at most after the hang-up), then the call ends
    // once its sound has played out.
    function hangUpAfterGoodbye() {
      if (closing) return;
      closing = true;
      var since = Date.now();
      (function check() {
        if (ended) return;
        if (closingDone || Date.now() - since > 4000) hungUp = true;
        if (hungUp && !sources.size) { setTimeout(function () { finish('done'); }, 400); return; }
        setTimeout(check, 150);
      })();
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
      release();
      clearInterval(pingTimer); clearInterval(quietTimer); clearTimeout(stopTimer); clearTimeout(waitTimer); clearTimeout(lateTimer); cancelAnimationFrame(raf);
      stopBackground();
      try { ws && ws.close(); } catch (e) {}
      try { mic && mic.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
      try { node && node.disconnect(); } catch (e) {}
      closeEngines(300);
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
      // One engine for the microphone and the voice (converter(), above).
      vctx = ctx;
      conv = converter(24000, ctx.sampleRate);
      out = vctx.createGain();
      outAnalyser = vctx.createAnalyser(); outAnalyser.fftSize = 1024;
      out.connect(outAnalyser); outAnalyser.connect(vctx.destination);
      micSource = ctx.createMediaStreamSource(stream);
      micAnalyser = ctx.createAnalyser(); micAnalyser.fftSize = 1024;
      micSource.connect(micAnalyser);
      var url = URL.createObjectURL(new Blob([WORKLET], { type: 'application/javascript' }));
      return ctx.audioWorklet.addModule(url).then(function () {
        node = new AudioWorkletNode(ctx, 'listen-pcm');
        micSource.connect(node);
        return inLine();
      });
    }, function () {
      set('mic');
      ended = true;
      return null;
    }).then(function (data) {
      if (!data || ended) return;
      if (!data.token) throw new Error(data.message || '');
      info = data;
      loadBackground();
      ws = new WebSocket(data.ws + '?access_token=' + encodeURIComponent(data.token));
      ws.onopen = function () { ws.send(JSON.stringify({ setup: { model: data.model } })); };
      ws.onmessage = onMessage;
      ws.onerror = function () { if (!ended) finish('error'); };
      ws.onclose = function () { if (!ended) finish(state === 'connecting' ? 'error' : 'caller_left'); };
      node.port.onmessage = function (e) {
        if (muted || !ws || ws.readyState !== 1 || state === 'connecting') return;
        var now = Date.now(), agentOn = sources.size > 0 || now - voiceEndAt < ECHO_TAIL;
        if (!agentOn && micLevel(e.data) > VOICED) callerVoiceAt = now;
        gate(e.data, agentOn, now).forEach(function (b) {
          ws.send(JSON.stringify({ realtimeInput: { audio: { data: b64(b), mimeType: 'audio/pcm;rate=16000' } } }));
        });
        if (info.transcribe) keepHeard(e.data);
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
        closeEngines(0);
        opts.onError && opts.onError(err && !err.offline ? err.message : '', err);
        set('error');
      }
    });

    return {
      end: function () { finish('done'); },
      // While waiting for a line: stop waiting (the caller would rather leave a number).
      giveUp: function () { if (state === 'waiting') toBusy(); },
      mute: function (on) { muted = !!on; },
      get state() { return state; },
    };
  }

  window.ListenAgentCall = { start: start, officeLevel: officeLevel, spokenPower: spokenPower, converter: converter, micGate: micGate };
})();
