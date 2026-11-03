/*
 * Listen: a business's voice agent on its own website.
 *
 *   <script src="https://listenai.studio/js/listen-agent.js" data-agent="lw_…" async></script>
 *
 * This file stays small and rarely changes. It asks Listen for the agent's
 * button (whether it shows, its words, look and voice) on every page load, then
 * loads the button itself, versioned, so owners never paste new code.
 */
(function () {
  'use strict';
  if (window.__listenAgent) return;
  window.__listenAgent = true;

  var script = document.currentScript;
  if (!script || !script.getAttribute('data-agent')) {
    var found = document.querySelectorAll('script[data-agent]');
    for (var i = 0; i < found.length; i++) if (/listen-agent\.js/.test(found[i].src)) { script = found[i]; break; }
  }
  if (!script) return;
  var key = String(script.getAttribute('data-agent') || '').trim();
  if (!/^lw_[A-Za-z0-9]{28}$/.test(key)) {
    if (window.console) console.warn('[Listen] The call button needs its agent key: data-agent="lw_…".');
    return;
  }
  var origin;
  try { origin = new URL(script.src, location.href).origin; } catch (e) { return; }
  var via = String(script.getAttribute('data-via') || '').replace(/[^a-z0-9_-]/gi, '').slice(0, 20);

  function load(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = resolve;
      s.onerror = reject;
      (document.head || document.documentElement).appendChild(s);
    });
  }

  function start() {
    fetch(origin + '/agent-api/widgets/' + key + (via ? '?via=' + via : ''), { credentials: 'omit' })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
      .then(function (res) {
        var cfg = res.d || {};
        if (!res.ok) {
          if (window.console) console.warn('[Listen] ' + (cfg.message || 'This call button is not available.'));
          return;
        }
        if (!cfg.available) {
          if (window.console) console.info('[Listen] Your agent is not live yet. Go live from Listen to show its call button.');
          return;
        }
        return load(cfg.assets.widget).then(function () {
          if (window.ListenAgentWidget) window.ListenAgentWidget.mount(cfg, { key: key, origin: origin, callScript: cfg.assets.call });
        });
      })
      .catch(function () {});
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
