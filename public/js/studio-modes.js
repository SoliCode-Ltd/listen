/*
 * The switch above the demo's studio (components/dub/mode-switch.blade.php): Text to speech,
 * Voice Design, Speech to text, Dub a video and a Voice agent. It sets data-studio on the closest
 * [data-studio-host] (css/studio-modes.css shows only that mode's panes), remembers the choice in
 * this browser, and puts it in the address: #voice-design, #speech-to-text, #dub or #voice-agent
 * opens that side.
 * Each change sends listen:studio-mode ({mode}).
 */
(function () {
  'use strict';

  var MODE_KEY = 'listen.studio.mode';
  var HASHES = { design: '#voice-design', stt: '#speech-to-text', dub: '#dub', agent: '#voice-agent' };

  function initSwitch(sw) {
    var host = sw.closest('[data-studio-host]');
    if (!host) return;
    var tabs = sw.querySelectorAll('[data-studio-tab]');
    var modes = Array.prototype.map.call(tabs, function (tab) { return tab.dataset.studioTab; });
    var panes = host.querySelectorAll('[data-studio-pane]');
    function shows(pane, mode) { return (' ' + pane.getAttribute('data-studio-pane') + ' ').indexOf(' ' + mode + ' ') >= 0; }
    function set(mode, remember) {
      var was = host.getAttribute('data-studio');
      host.setAttribute('data-studio', mode);
      // What this mode shows and the last one did not comes in gently (css/studio-modes.css); what both
      // show stays still. Not when the page first opens.
      if (sw.classList.contains('is-ready') && was && was !== mode) {
        panes.forEach(function (pane) {
          if (!shows(pane, mode) || shows(pane, was)) return;
          pane.classList.remove('studio-pane-in');
          void pane.offsetWidth;
          pane.classList.add('studio-pane-in');
        });
      }
      tabs.forEach(function (tab) { tab.setAttribute('aria-selected', tab.dataset.studioTab === mode ? 'true' : 'false'); });
      if (remember) {
        try { window.localStorage.setItem(MODE_KEY, mode); } catch (e) { /* no storage */ }
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', HASHES[mode] || window.location.pathname + window.location.search);
        }
      }
      document.dispatchEvent(new CustomEvent('listen:studio-mode', { detail: { mode: mode } }));
    }
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () { set(tab.dataset.studioTab, true); });
    });
    var start = 'tts';
    var fromHash = Object.keys(HASHES).filter(function (mode) { return HASHES[mode] === window.location.hash; })[0];
    if (fromHash && modes.indexOf(fromHash) >= 0) start = fromHash;
    else {
      try {
        var saved = window.localStorage.getItem(MODE_KEY);
        if (saved && modes.indexOf(saved) >= 0) start = saved;
      } catch (e) { /* no storage */ }
    }
    set(start, false);
    // A link to #voice-design, #dub or #voice-agent on this same page opens that side too.
    window.addEventListener('hashchange', function () {
      var mode = Object.keys(HASHES).filter(function (m) { return HASHES[m] === window.location.hash; })[0];
      if (mode && modes.indexOf(mode) >= 0) set(mode, false);
    });
    requestAnimationFrame(function () { sw.classList.add('is-ready'); });
  }

  document.addEventListener('animationend', function (e) {
    if (e.animationName === 'studio-pane-in' && e.target.classList) e.target.classList.remove('studio-pane-in');
  });

  function boot() { document.querySelectorAll('[data-studio-switch]').forEach(initSwitch); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
