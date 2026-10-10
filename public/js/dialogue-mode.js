/*
 * Dialogue mode panel (components/voice-studio/dialogue.blade.php), shared by the
 * dashboard generator and the demo page. Each page passes how to read its own
 * language, premium voices and dialects:
 *
 *   ListenDialogue.init({
 *     textarea,              // the script field
 *     language: () => 'ar',  // current target language
 *     voices: (lang) => [],  // premium voices for that language, already sorted
 *     dialects: (lang) => [],// [{value, label}] for that language
 *     voiceLabel: (voice) => '',
 *     allowed: () => true,   // false hides and disables the panel (e.g. standard voices)
 *   })
 *
 * Returns { refresh() } to call when the language or the voice quality changes.
 */
(function () {
  'use strict';

  function init(opts) {
    var root = document.querySelector('[data-dialogue]');
    var toggle = document.getElementById('dialogueToggle');
    var hidden = document.getElementById('dialogueSelect');
    if (!root || !toggle || !hidden) return { refresh: function () {} };

    var allowed = opts.allowed || function () { return true; };
    var text = {
      guide: root.dataset.guide || '',
      same: root.dataset.sameDialect || '',
      example: root.dataset.example || '',
    };
    var nameInputs = root.querySelectorAll('[data-dialogue-name]');
    var voiceSelects = root.querySelectorAll('[data-dialogue-voice]');
    var dialectSelects = root.querySelectorAll('[data-dialogue-dialect]');

    function names() {
      return Array.prototype.map.call(nameInputs, function (input, i) {
        return String(input.value || '').trim() || (i === 0 ? 'A' : 'B');
      });
    }

    function updateGuide() {
      var guide = document.getElementById('dialogueGuide');
      if (guide) guide.textContent = text.guide.replace(':example', names()[0] + ': …');
    }

    // Each speaker can speak their own dialect or accent; empty keeps the main setting.
    function renderDialects() {
      var dialects = (opts.dialects(opts.language()) || []).filter(function (d) { return d.value; });
      dialectSelects.forEach(function (select) {
        var previous = String(select.value || select.dataset.initial || '').trim();
        select.innerHTML = '';
        var same = document.createElement('option');
        same.value = '';
        same.textContent = text.same;
        select.appendChild(same);
        dialects.forEach(function (d) {
          var option = document.createElement('option');
          option.value = d.value;
          option.textContent = d.label;
          select.appendChild(option);
        });
        select.value = dialects.some(function (d) { return d.value === previous; }) ? previous : '';
        select.dataset.initial = '';
      });
    }

    // A name field the user has not typed in follows its voice's name.
    function syncNames(force) {
      nameInputs.forEach(function (input, i) {
        var option = voiceSelects[i] && voiceSelects[i].selectedOptions && voiceSelects[i].selectedOptions[0];
        if (!option) return;
        if (force || !input.dataset.touched || !String(input.value || '').trim()) {
          input.value = option.dataset.name || option.textContent;
        }
      });
      updateGuide();
    }

    function renderVoices() {
      var voices = opts.voices(opts.language()) || [];
      voiceSelects.forEach(function (select, i) {
        var previous = String(select.value || select.dataset.initial || '').trim();
        select.innerHTML = '';
        voices.forEach(function (voice) {
          var label = opts.voiceLabel(voice);
          var option = document.createElement('option');
          option.value = voice.key;
          option.textContent = label;
          option.dataset.avatar = voice.avatar || '';
          option.dataset.name = label.split(' ')[0];
          option.dataset.gender = voice.gender || '';
          select.appendChild(option);
        });
        if (voices.some(function (v) { return v.key === previous; })) {
          select.value = previous;
        } else {
          // Speaker 1 starts with a voice of one gender, speaker 2 with the other.
          var wanted = i === 0 ? 'male' : 'female';
          var pick = voices.find(function (v) { return v.gender === wanted; }) || voices[i] || voices[0];
          select.value = pick ? pick.key : '';
        }
        select.dataset.initial = '';
      });
      renderDialects();
      syncNames(false);
    }

    function refresh() {
      var usable = !!allowed();
      root.hidden = !usable;
      var on = usable && toggle.checked;
      hidden.value = on ? '1' : '0';
      var panel = document.getElementById('dialogueSpeakers');
      if (panel) panel.hidden = !on;
      [nameInputs, voiceSelects, dialectSelects].forEach(function (list) {
        list.forEach(function (el) { el.disabled = !on; });
      });
      if (on) renderVoices();
    }

    toggle.addEventListener('change', refresh);
    nameInputs.forEach(function (input) {
      input.addEventListener('input', function () { input.dataset.touched = '1'; updateGuide(); });
    });
    voiceSelects.forEach(function (select) {
      select.addEventListener('change', function () { syncNames(false); });
    });
    var exampleBtn = document.getElementById('dialogueExampleBtn');
    if (exampleBtn) {
      exampleBtn.addEventListener('click', function () {
        if (!opts.textarea) return;
        var n = names();
        opts.textarea.value = text.example.split(':a').join(n[0]).split(':b').join(n[1]);
        opts.textarea.dispatchEvent(new Event('input', { bubbles: true }));
        opts.textarea.focus();
      });
    }

    refresh();
    return { refresh: refresh };
  }

  window.ListenDialogue = { init: init };
})();
