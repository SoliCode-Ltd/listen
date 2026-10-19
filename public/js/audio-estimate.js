/*
 * How long a text's audio will be, and what it costs, while it is typed: the
 * same estimate as App\Support\UsageEstimator, which the server reserves, with
 * its numbers (UsageEstimator::clientConfig(), given to configure()). The
 * text is read as the speech pipeline reads it (App\Support\GeminiVocalTags):
 * sounds such as <laugh>, pauses, and the extra takes a mood tag in the
 * middle starts. Keep the two in step: tests/Feature/AudioEstimateScriptTest.php
 * runs both on the same texts.
 *
 *   ListenAudioEstimate.configure(config);
 *   ListenAudioEstimate.seconds(text, { tier: 'hd', speed: 1.2, performance_style: 'cinematic' });
 */
(function (root) {
  'use strict';

  const MAX_CUE = 40;
  let config = null;

  // PHP's trim(): only these, so a text of other spaces reads as the server reads it.
  function trim(text) {
    return String(text).replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
  }

  // GeminiVocalTags::plainArabic().
  function plainArabic(text) {
    return String(text).replace(/[ً-ٰٟـ]/g, '').replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه');
  }

  function fold(cue) {
    return trim(String(cue).replace(/\s+/gu, ' ')).toLowerCase();
  }

  function lookup(index, cue) {
    const key = fold(cue);
    if (Object.prototype.hasOwnProperty.call(index, key)) return index[key];
    const plain = plainArabic(key);

    return Object.prototype.hasOwnProperty.call(index, plain) ? index[plain] : null;
  }

  const canonical = (cue) => lookup(config.sounds, cue);
  const delivery = (cue) => lookup(config.deliveries, cue);

  function looksLikeCue(inner) {
    const value = trim(inner);

    return value !== '' && /^\p{L}[\p{L}\p{M}\s\-,&'+،]*$/u.test(value) && !/^(?:sic)$/iu.test(value);
  }

  function normalizeMarkup(text) {
    text = String(text).replace(/\r\n/g, '\n');
    const name = '[^<>\\/\\n]{1,' + MAX_CUE + '}';
    text = text.replace(new RegExp('<\\s*(' + name + ')\\s*>([\\s\\S]*?)<\\s*\\/\\s*\\1\\s*>', 'gu'), function (whole, cue, words) {
      const inner = trim(cue);
      const sound = canonical(inner);
      if (sound !== null && sound !== 'whispers') return '<' + inner + '>' + words;
      if (sound === 'whispers' || delivery(inner) !== null || looksLikeCue(inner)) {
        return '[' + (sound === 'whispers' ? 'whispering' : inner) + ']' + words + ' [/] ';
      }

      return whole;
    });
    text = text.replace(new RegExp('<\\s*\\/\\s*(' + name + ')\\s*>', 'gu'), function (whole, cue) {
      const inner = trim(cue);

      return canonical(inner) !== null || delivery(inner) !== null || looksLikeCue(inner) ? ' ' : whole;
    });

    return text.replace(new RegExp('<([^<>\\s\\/](?:[^<>\\n]{0,' + (MAX_CUE - 2) + '}[^<>\\s])?)>', 'gu'), function (whole, cue) {
      return canonical(cue) === null && looksLikeCue(cue) ? '[' + cue + ']' : whole;
    });
  }

  function strip(text) {
    return text.replace(new RegExp('<([^<>\\s](?:[^<>\\n]{0,' + (MAX_CUE - 2) + '}[^<>\\s])?)>', 'gu'), function (whole, cue) {
      return canonical(cue) !== null ? ' ' : whole;
    });
  }

  function tidy(text) {
    return trim(text.split(/\r\n|\n|\r|\v|\f|\u0085|\u2028|\u2029/u).map(function (line) {
      return trim(line.replace(/[ \t]{2,}/gu, ' ').replace(/\s+([,.!?؟،؛…])/gu, '$1'));
    }).join('\n'));
  }

  // GeminiVocalTags::prepare(): sounds become <tag>, other bracketed moods are taken out.
  function prepare(text) {
    const pattern = new RegExp('\\[([^\\[\\]\\n]{1,' + MAX_CUE + '})\\]|<([^<>\\s](?:[^<>\\n]{0,' + (MAX_CUE - 2) + '}[^<>\\s])?)>|\\(([^()\\n]{1,' + MAX_CUE + '})\\)', 'gu');

    return tidy(text.replace(pattern, function (whole, square, angle, round) {
      const inner = trim(round || '') !== '' ? round : (trim(angle || '') !== '' ? angle : square);
      const tag = canonical(inner);
      if (tag !== null) return '<' + tag + '>';
      if ((round !== undefined && round !== '') || !looksLikeCue(inner)) return whole;

      return ' ';
    }));
  }

  // GeminiVocalTags::parts($text, true): the takes a script is read in.
  function parts(text) {
    const pieces = normalizeMarkup(text).split(new RegExp('(\\[[^\\[\\]\\n]{1,' + MAX_CUE + '}\\])', 'u'));
    const out = [];
    let buffer = '';
    const flush = function () {
      const prepared = prepare(buffer);
      const sounds = strip(prepared) !== prepared;
      if (trim(strip(prepared)) !== '' || sounds) out.push(prepared);
      buffer = '';
    };
    pieces.forEach(function (piece) {
      const match = /^\[([^\[\]\n]+)\]$/u.exec(piece);
      if (match) {
        const inner = match[1];
        if (trim(inner) === '/') {
          flush();

          return;
        }
        if (delivery(inner) !== null || (canonical(inner) === null && looksLikeCue(inner))) {
          flush();

          return;
        }
      }
      buffer += piece;
    });
    flush();

    return out;
  }

  const count = (text, pattern) => (text.match(pattern) || []).length;

  // UsageEstimator::features().
  function features(text) {
    const takes = parts(text);
    const sounds = { sound: 0, short_pause: 0, long_pause: 0 };
    takes.forEach(function (take) {
      (take.match(/<([a-z][a-z -]*)>/g) || []).forEach(function (tag) {
        const name = tag.slice(1, -1);
        sounds[name === 'short pause' ? 'short_pause' : (name === 'long pause' ? 'long_pause' : 'sound')]++;
      });
    });
    const spoken = takes.map(strip).join(' ');
    const letters = count(spoken, /[\p{L}\p{N}]/gu);
    const arabic = count(spoken, /(?=[\p{L}\p{N}])\p{Script=Arabic}/gu);
    const cjk = count(spoken, /(?=[\p{L}\p{N}])[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\p{Script=Hangul}]/gu);

    return {
      arabic: arabic,
      latin: letters - arabic - cjk,
      cjk: cjk,
      mark: count(spoken, /\p{Mn}/gu),
      number: count(spoken, /\p{N}+/gu),
      sound: sounds.sound,
      short_pause: sounds.short_pause,
      long_pause: sounds.long_pause,
      sentence: count(spoken, /[.!?؟…]+/gu),
      clause: count(spoken, /[,،;؛:]/gu),
      line: count(spoken, /\n\s*/gu),
      mood: Math.max(0, takes.length - 1),
    };
  }

  // UsageEstimator::tier().
  function levelOf(tier) {
    return tier === undefined || tier === null ? 'hd' : (['hd', 'hd_plus'].includes(trim(tier).toLowerCase()) ? 'hd' : 'standard');
  }

  // UsageEstimator::rawSeconds().
  function rawSeconds(found, tier, speed, studio) {
    const level = levelOf(tier);
    const model = config.model[level][found.arabic >= found.latin + found.cjk ? 'ar' : 'en'];
    let seconds = model.base;
    Object.keys(found).forEach(function (name) {
      seconds += (model[name] || 0) * found[name];
    });
    if (level === 'hd') {
      Object.keys(config.studio_factors).forEach(function (setting) {
        const factor = config.studio_factors[setting][String(studio[setting] || '')];
        seconds *= typeof factor === 'number' ? factor : 1;
      });
      if (trim(studio.style_prompt || '') !== '') seconds *= config.direction_factor;
      const pace = Number(speed || 1);
      if (pace > 0 && Math.abs(pace - 1) > 0.001) {
        seconds *= Math.pow(pace, pace < 1 ? -config.slower_power : -config.faster_power);
      }
    }

    return seconds;
  }

  // UsageEstimator::estimate()['seconds']; options: tier, speed, and the
  // premium voice's performance_style, emotion, dialect and style_prompt.
  function seconds(text, options) {
    const plain = trim(text || '');
    if (plain === '' || !config) return 0;
    const opts = options || {};

    return Math.max(1, Math.round(rawSeconds(features(plain), opts.tier, opts.speed, opts) * config.margin[levelOf(opts.tier)]));
  }

  root.ListenAudioEstimate = {
    configure: function (value) { config = value; },
    features: features,
    seconds: seconds,
    credits: function (text, options) { return seconds(text, options) * (config ? config.credits_per_second : 0); },
  };
})(typeof window !== 'undefined' ? window : globalThis);
