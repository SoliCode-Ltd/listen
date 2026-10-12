{{-- Brand panel beside the sign in / register form: only the Listen speakers in conversation, as large as the panel allows. --}}
<aside class="auth-showcase" aria-label="{{ __('design.auth.showcase_label') }}">
  <div class="auth-showcase-inner">
    <div class="auth-cast">
      @include('components.home.hero-art')
    </div>
  </div>
</aside>
<script>
  // The speakers keep their full layout and are scaled as a whole to fill the panel.
  (function () {
    var inner = document.querySelector('.auth-showcase-inner');
    var cast = inner && inner.querySelector('.auth-cast');
    if (!inner || !cast) return;
    function fit() {
      if (!inner.offsetParent) return;
      cast.style.setProperty('--cast-zoom', '1');
      var style = getComputedStyle(inner);
      var gap = parseFloat(style.rowGap) || 0;
      var room = inner.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom);
      Array.prototype.forEach.call(inner.children, function (child) {
        if (child !== cast) room -= child.getBoundingClientRect().height + gap;
      });
      room -= 32; // the speech bubbles under the bottom speakers reach a little past the ring
      var width = inner.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
      var natural = cast.offsetHeight + (parseFloat(getComputedStyle(cast).marginTop) || 0);
      var zoom = natural > 0 ? Math.max(0.45, Math.min(1.6, room / natural, width / cast.offsetWidth)) : 1;
      cast.style.setProperty('--cast-zoom', zoom.toFixed(3));
    }
    fit();
    window.addEventListener('resize', fit);
    window.addEventListener('load', fit);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
  })();
</script>
