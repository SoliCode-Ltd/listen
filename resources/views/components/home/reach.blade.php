{{-- Where Listen's members are, straight on the page: the two numbers beside a turning globe of fine dotted land where
     every country with members glows like a city at night, brighter where there are more (public/js/home-globe.js,
     no dependencies). Counted live from sign-ups (App\Support\MemberReach), cached. Nothing
     waits to appear: the globe turns from the start and the numbers count up as they come into view. The land loads only near the screen; the globe draws only while seen; reduced motion
     keeps it still. --}}
@php
  $reach = \App\Support\MemberReach::class;
  $isAr = app()->getLocale() === 'ar';
  $members = $reach::membersRoundedDown();
  $countries = $reach::countryCount();
  $markers = array_map(fn (array $m) => ['code' => $m['code'], 'at' => $m['at'], 'tier' => $m['tier'], 'delay' => $m['delay']], $reach::markers());
@endphp
<section class="reach" aria-labelledby="reachTitle" data-reach>
  <div class="reach-panel">
    <div class="reach-copy">
      <h2 id="reachTitle"><span>{{ __('ui.landing.reach_title_a') }}</span> <span class="reach-title-glow">{{ __('ui.landing.reach_title_b') }}</span></h2>
      <p class="reach-lead">{{ __('ui.landing.reach_lead') }}</p>
      <ul class="reach-stats">
        <li>
          {{-- The final number is in the page; the count-up only replays it. --}}
          <strong><bdi dir="ltr" data-count="{{ $countries }}" data-kind="countries">{{ number_format($countries) }}</bdi></strong>
          <span>{{ __('ui.landing.reach_countries') }}</span>
        </li>
        <li>
          {{-- "9,000+" in English, "+9,000" in Arabic, as the rest of the copy writes it. --}}
          <strong><bdi dir="ltr" data-count="{{ $members }}" data-plus="{{ $isAr ? 'before' : 'after' }}">{{ $isAr ? '+'.number_format($members) : number_format($members).'+' }}</bdi></strong>
          <span>{{ __('ui.landing.reach_members') }}</span>
        </li>
      </ul>
      <p class="reach-note">{{ __('ui.landing.reach_caption_a') }} <span>{{ __('ui.landing.reach_caption_b') }}</span></p>
    </div>
    <div class="reach-globe" data-markers='@json($markers)' data-land="{{ \App\Support\VersionedAsset::url('data/globe-land.json') }}">
      <canvas role="img" aria-label="{{ __('ui.landing.reach_map_label', ['countries' => $countries]) }}"></canvas>
    </div>
  </div>
</section>
<script>
  // The numbers count up once, as they come into view; everything else is there from the start. Without this script,
  // or with reduced motion, they simply show their value.
  (function () {
    var section = document.currentScript.previousElementSibling;
    var stats = section && section.querySelector('.reach-stats');
    if (!stats || !('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var els = stats.querySelectorAll('[data-count]');
    var fmt = function (n, el) {
      var s = Math.round(n).toLocaleString('en-US');
      return el.dataset.plus === 'before' ? '+' + s : el.dataset.plus === 'after' ? s + '+' : s;
    };
    var show = function (k) { for (var i = 0; i < els.length; i++) els[i].textContent = fmt(Number(els[i].dataset.count) * k, els[i]); };
    show(0);
    var seen = new IntersectionObserver(function (entries) {
      if (!entries[0].isIntersecting) return;
      seen.disconnect();
      var t0 = performance.now();
      var step = function (now) {
        var t = Math.min(1, (now - t0) / 1800);
        show(1 - Math.pow(1 - t, 4));
        if (t < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    }, { threshold: 0.6 });
    seen.observe(stats);
  })();
</script>
<script type="module" src="{{ \App\Support\VersionedAsset::url('js/home-globe.js') }}"></script>
