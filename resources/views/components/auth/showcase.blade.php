{{-- Brand panel beside the sign in / register form: the headline, and the Listen speakers in conversation. --}}
@php
  $authMode = $authMode ?? 'login';
  $authFreeChars = $authMode === 'register' ? \App\Support\FreePlan::monthlyChars() : null;
@endphp
<aside class="auth-showcase" aria-label="{{ __('design.auth.showcase_label') }}">
  <div class="auth-showcase-inner">
    @if($authMode === 'register')
      <h2 class="auth-headline">{{ __('design.auth.register_headline') }}</h2>
      <ul class="auth-perks">
        @if($authFreeChars)
          <li>{{ __('design.auth.perk_chars', ['chars' => number_format($authFreeChars)]) }}</li>
        @endif
        <li>{{ __('design.auth.perk_voices') }}</li>
        <li>{{ __('design.auth.perk_extension') }}</li>
      </ul>
    @else
      <h2 class="auth-headline">{{ __('design.auth.login_headline') }}</h2>
      <p class="auth-subline">{{ __('design.auth.login_body') }}</p>
    @endif

    <div class="auth-cast">
      @include('components.home.hero-art')
    </div>
  </div>
</aside>
<script>
  // The speakers keep their full layout and are scaled as a whole, as large as
  // the panel allows, with the headline and the lines under it always in view.
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
      var width = inner.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
      var natural = cast.offsetHeight + (parseFloat(getComputedStyle(cast).marginTop) || 0);
      var zoom = natural > 0 ? Math.max(0.45, Math.min(1.4, room / natural, width / cast.offsetWidth)) : 1;
      cast.style.setProperty('--cast-zoom', zoom.toFixed(3));
    }
    fit();
    window.addEventListener('resize', fit);
    window.addEventListener('load', fit);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
  })();
</script>
