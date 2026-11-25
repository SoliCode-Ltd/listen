{{-- Where Listen's members are, straight on the page: the two numbers beside a turning globe of fine dotted land where
     every country with members glows like a city at night, brighter where there are more, with network arcs and light
     trails (public/js/home-globe.js, no dependencies). Counted live from sign-ups (App\Support\MemberReach), cached. One
     orchestrated moment as it comes into view: the heading, the text, the numbers, the line and the note rise in one
     after another while the globe fades up, its lights spread from the Arab world outward and the numbers climb. The land loads only near the screen; the globe draws only while seen; reduced motion
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
  // The section's entrance, read as the page arrives: hold the section's parts back until it comes into view, then let
  // them rise in one after another (CSS) and tell the globe to start its part. Without this script, or with reduced
  // motion, everything simply shows.
  (function () {
    var section = document.currentScript.previousElementSibling;
    if (!section) return;
    var on = function () { section.classList.remove('reach-ready'); section.classList.add('reach-on'); section.dispatchEvent(new Event('reach:on')); };
    if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) { on(); return; }
    section.classList.add('reach-ready');
    var seen = new IntersectionObserver(function (entries) {
      if (entries[0].isIntersecting) { seen.disconnect(); requestAnimationFrame(on); }
    }, { threshold: 0.12, rootMargin: '0px 0px -12% 0px' });
    seen.observe(section);
  })();
</script>
<script type="module" src="{{ \App\Support\VersionedAsset::url('js/home-globe.js') }}"></script>
