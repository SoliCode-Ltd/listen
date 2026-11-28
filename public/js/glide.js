/*
 * Segmented toggles whose highlight glides to the chosen button instead of jumping: the demo's
 * mode switch (components/dub/mode-switch.blade.php) and the voice library's tabs, gender and age
 * (components/voice-studio/panel.blade.php). A container marked data-glide="aria-selected" (or
 * "aria-pressed") gets one thumb behind its buttons, moved with a transform to whichever button has
 * that attribute "true"; it follows the choice however it changes (a click, a script, a restored
 * choice), buttons that come and go (hidden ages), resizing and the two-row layout on phones.
 *
 * With data-glide-labels too, the chosen colour of the words is not switched on a timer: a copy of
 * the labels in that colour sits over the buttons, cut to the thumb's shape and moved with it, so a
 * word changes colour exactly where the thumb's edge passes it (no white word left on the light
 * background, no grey word on the coloured thumb, while it slides).
 *
 * The look of each thumb and of the copied labels is in its control's CSS (.glide-thumb,
 * .glide-labels); without this script the chosen button keeps its own highlight as before.
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

    var labels = null;
    if (box.hasAttribute('data-glide-labels')) {
      labels = document.createElement('span');
      labels.className = 'glide-labels';
      labels.setAttribute('aria-hidden', 'true');
      labels.inert = true;
      box.appendChild(labels);
    }

    var placed = false;
    var frame = 0;
    var copiesStale = true;

    function buttons() {
      return Array.prototype.filter.call(box.children, function (el) { return el !== thumb && el !== labels; });
    }

    function active() {
      var items = buttons();
      for (var i = 0; i < items.length; i++) {
        if (items[i].getAttribute(attr) === 'true' && !items[i].hidden && items[i].offsetWidth > 0) return items[i];
      }
      return null;
    }

    // The labels' copies: the same words and icons, without ids, data or the state attributes.
    function copyLabels() {
      copiesStale = false;
      if (!labels) return;
      labels.textContent = '';
      buttons().forEach(function (btn) {
        if (btn.hidden || btn.offsetWidth === 0) return;
        // The same element, so the control's own styles (written for its buttons) shape the copy too.
        var copy = document.createElement(btn.tagName);
        if (copy.tagName === 'BUTTON') { copy.type = 'button'; copy.tabIndex = -1; }
        copy.className = (btn.className ? btn.className + ' ' : '') + 'glide-label';
        copy.innerHTML = btn.innerHTML;
        copy.querySelectorAll('[id], [data-vs-lib-count]').forEach(function (el) { el.removeAttribute('id'); el.removeAttribute('data-vs-lib-count'); });
        copy.style.left = btn.offsetLeft + 'px';
        copy.style.top = btn.offsetTop + 'px';
        copy.style.width = btn.offsetWidth + 'px';
        copy.style.height = btn.offsetHeight + 'px';
        labels.appendChild(copy);
      });
    }

    function place() {
      frame = 0;
      if (copiesStale) copyLabels();
      var item = active();
      if (!item) {
        thumb.style.opacity = '0';
        if (labels) labels.style.opacity = '0';
        return;
      }
      // Offsets are from the container's own padding box, the same in Arabic and English.
      var w = item.offsetWidth;
      var h = item.offsetHeight;
      var x = item.offsetLeft;
      var y = item.offsetTop;
      if (!placed) {
        // The first place, and a toggle that was hidden until now: no slide from nowhere.
        thumb.style.transition = 'none';
        if (labels) labels.style.transition = 'none';
      }
      thumb.style.width = w + 'px';
      thumb.style.height = h + 'px';
      thumb.style.transform = 'translate3d(' + x + 'px,' + y + 'px,0)';
      thumb.style.opacity = '1';
      if (labels) {
        var W = Math.max(box.scrollWidth, box.clientWidth);
        var H = Math.max(box.scrollHeight, box.clientHeight);
        var r = parseFloat(getComputedStyle(thumb).borderTopLeftRadius) || 0;
        labels.style.width = W + 'px';
        labels.style.height = H + 'px';
        labels.style.clipPath = 'inset(' + y + 'px ' + (W - x - w) + 'px ' + (H - y - h) + 'px ' + x + 'px round ' + r + 'px)';
        labels.style.opacity = '1';
      }
      if (!placed) {
        void thumb.offsetWidth; // commit the first place before slides may animate
        thumb.style.transition = '';
        if (labels) labels.style.transition = '';
        placed = true;
        box.classList.add('has-glide');
      }
    }

    function later() { if (!frame) frame = requestAnimationFrame(place); }

    new MutationObserver(function (records) {
      var mine = records.every(function (rec) { return labels && labels.contains(rec.target); });
      if (mine) return;
      // Words, buttons or which buttons show changed: the copies are made again.
      if (records.some(function (rec) { return rec.type !== 'attributes' || rec.attributeName !== attr; })) copiesStale = true;
      later();
    }).observe(box, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: [attr, 'hidden', 'class'] });
    var sizes = new ResizeObserver(function () {
      // A container that was hidden (another mode, a closed panel) starts over without a slide.
      if (box.offsetWidth === 0) { placed = false; return; }
      copiesStale = true;
      later();
    });
    sizes.observe(box);
    buttons().forEach(function (child) { sizes.observe(child); });
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { copiesStale = true; later(); });
    place();
  }

  function boot() { document.querySelectorAll('[data-glide]').forEach(initGlide); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
