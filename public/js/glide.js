/*
 * Segmented toggles whose highlight glides to the chosen button instead of jumping: the demo's
 * mode switch (components/dub/mode-switch.blade.php) and the voice library's tabs, gender and age
 * (components/voice-studio/panel.blade.php). A container marked data-glide="aria-selected" (or
 * "aria-pressed") gets one thumb behind its buttons, moved with a transform to whichever button has
 * that attribute "true"; it follows the choice however it changes (a click, a script, a restored
 * choice), buttons that come and go (hidden ages), resizing and the two-row layout on phones.
 * The look of each thumb is in its control's CSS (.glide-thumb); without this script, or with
 * reduced motion, the chosen button keeps its own highlight as before.
 */
(function () {
  'use strict';

  if (!('ResizeObserver' in window) || !('MutationObserver' in window)) return;

  function initGlide(box) {
    if (box.dataset.glideReady) return;
    box.dataset.glideReady = '1';
    var attr = box.getAttribute('data-glide') || 'aria-selected';
    var thumb = document.createElement('span');
    thumb.className = 'glide-thumb';
    thumb.setAttribute('aria-hidden', 'true');
    box.insertBefore(thumb, box.firstChild);
    var placed = false;
    var frame = 0;

    function active() {
      var items = box.querySelectorAll('[' + attr + '="true"]');
      for (var i = 0; i < items.length; i++) {
        if (items[i] !== thumb && items[i].parentNode === box && !items[i].hidden && items[i].offsetWidth > 0) return items[i];
      }
      return null;
    }

    function place() {
      frame = 0;
      var item = active();
      if (!item) { thumb.style.opacity = '0'; return; }
      // Offsets are from the container's own padding box, the same in Arabic and English.
      var w = item.offsetWidth;
      var h = item.offsetHeight;
      var x = item.offsetLeft;
      var y = item.offsetTop;
      if (!placed) {
        // The first place, and a toggle that was hidden until now: no slide from nowhere.
        thumb.style.transition = 'none';
      }
      thumb.style.width = w + 'px';
      thumb.style.height = h + 'px';
      thumb.style.transform = 'translate3d(' + x + 'px,' + y + 'px,0)';
      thumb.style.opacity = '1';
      if (!placed) {
        void thumb.offsetWidth; // commit the first place before slides may animate
        thumb.style.transition = '';
        placed = true;
        box.classList.add('has-glide');
      }
    }

    function later() { if (!frame) frame = requestAnimationFrame(place); }

    new MutationObserver(later).observe(box, { subtree: true, childList: true, attributes: true, attributeFilter: [attr, 'hidden', 'class'] });
    var sizes = new ResizeObserver(function () {
      // A container that was hidden (another mode, a closed panel) starts over without a slide.
      if (box.offsetWidth === 0) { placed = false; return; }
      later();
    });
    sizes.observe(box);
    Array.prototype.forEach.call(box.children, function (child) { if (child !== thumb) sizes.observe(child); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(later);
    place();
  }

  function boot() { document.querySelectorAll('[data-glide]').forEach(initGlide); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
