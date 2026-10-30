/*
 * The switch above the demo's studio (components/dub/mode-switch.blade.php): Text to speech,
 * Voice Design and Dub a video. It sets data-studio on the closest [data-studio-host]
 * (css/studio-modes.css shows only that mode's panes), remembers the choice in this browser,
 * and puts it in the address: #voice-design or #dub opens that side. Each change sends
 * listen:studio-mode ({mode}).
 */
(function () {
  'use strict';

  var MODE_KEY = 'listen.studio.mode';
  var HASHES = { design: '#voice-design', dub: '#dub' };

  function initSwitch(sw) {
    var host = sw.closest('[data-studio-host]');
    if (!host) return;
    var tabs = sw.querySelectorAll('[data-studio-tab]');
    var modes = Array.prototype.map.call(tabs, function (tab) { return tab.dataset.studioTab; });
    function set(mode, remember) {
      host.setAttribute('data-studio', mode);
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
    requestAnimationFrame(function () { sw.classList.add('is-ready'); });
  }

  function boot() { document.querySelectorAll('[data-studio-switch]').forEach(initSwitch); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
