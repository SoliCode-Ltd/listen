@include('components.page-head', ['pageTitle' => __('ui.seo.upgrade_title'), 'page' => 'upgrade'])
  <style>
    .main { padding: 1.8rem 0 3rem; }
    .hero { text-align: center; margin-bottom: 1.5rem; }
    .hero h1 { margin: 0 0 .5rem; font-size: clamp(2rem, 4vw, 3rem); }
    .hero p { margin: 0; color: var(--muted); }
    .plans { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem; }
    .plan { position: relative; background: linear-gradient(170deg, var(--surface2), var(--surface)); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: 1.3rem; box-shadow: var(--shadow); }
    .plan.popular { border-color: color-mix(in oklab, var(--a) 50%, var(--line)); }
    .badge { position: absolute; top: .8rem; right: .8rem; padding: .25rem .6rem; border-radius: var(--radius-full); font-size: .74rem; border: 1px solid color-mix(in oklab, var(--a) 45%, var(--line)); background: color-mix(in oklab, var(--a) 14%, var(--surface)); color: var(--text); }
    .plan h2 { margin: 0 0 .3rem; }
    .price { font-size: 2rem; font-weight: 800; margin: .4rem 0 .2rem; }
    .spec { display: grid; gap: .45rem; margin-top: .75rem; }
    .row { display: flex; justify-content: space-between; gap: .8rem; padding: .55rem .6rem; border: 1px solid color-mix(in oklab, var(--line) 70%, transparent); border-radius: var(--radius); background: color-mix(in oklab, var(--surface) 88%, transparent); font-size: .86rem; }
    .row span:first-child { color: var(--muted); }
    .actions { margin-top: .9rem; display: flex; gap: .55rem; }
    .btn[disabled] { opacity: .55; pointer-events: none; }
    @media (max-width: 980px) { .plans { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  @php
    $planNameBySlug = static function (?string $slug, ?string $fallback = null): string {
      return match ((string) $slug) {
        'free' => __('ui.pricing.plan_name_free'),
        'creator' => __('ui.pricing.plan_name_creator'),
        'pro' => __('ui.pricing.plan_name_pro'),
        default => (string) ($fallback ?: '-'),
      };
    };
    $priorityLabel = static function (?string $value): string {
      return strtolower((string) $value) === 'high'
        ? __('ui.upgrade.processing_priority_high')
        : __('ui.upgrade.processing_priority_standard');
    };
    $formatTierLabel = static function (?string $label): string {
      $text = trim((string) $label);
      if ($text !== '' && preg_match('/[A-Za-z0-9+]/', $text) && !preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
        return "\u{2066}{$text}\u{2069}";
      }
      return $text;
    };
  @endphp
  @include('components.header')
  <main class="container main">
    <section class="hero">
      <h1>{{ __('ui.upgrade.hero_title') }}</h1>
      <p>{{ __('ui.upgrade.hero_subtitle') }}</p>
    </section>

    <section class="plans">
      @foreach($plans as $plan)
        <article class="plan {{ $plan['is_popular'] ? 'popular' : '' }}">
          @if($plan['is_popular'])
            <span class="badge">{{ __('ui.pricing.most_popular') }}</span>
          @endif
          <h2>{{ $planNameBySlug((string) $plan['slug'], (string) $plan['name']) }}</h2>
          <div class="price">
            @if($plan['price_monthly'] <= 0)
              $0
            @else
              ${{ rtrim(rtrim(number_format($plan['price_monthly'], 2, '.', ''), '0'), '.') }}
              <small style="font-size:.85rem; color:var(--muted); font-weight:600;">{{ __('ui.upgrade.per_month') }}</small>
            @endif
          </div>
          <small style="color:var(--muted);">{{ __('ui.upgrade.yearly_equivalent') }}: ${{ rtrim(rtrim(number_format($plan['price_yearly'], 2, '.', ''), '0'), '.') }} {{ __('ui.upgrade.per_month') }}</small>

          <div class="spec">
            <div class="row"><span>{{ __('ui.upgrade.characters_per_month') }}</span><span>{{ number_format($plan['monthly_chars']) }}</span></div>
            <div class="row"><span>{{ __('ui.upgrade.requests') }}</span><span>{{ $plan['requests_unlimited'] ? __('ui.upgrade.unlimited') : number_format($plan['monthly_requests']).' '. __('ui.upgrade.per_month') }}</span></div>
            <div class="row"><span>{{ __('ui.upgrade.commercial_use') }}</span><span>{{ $plan['commercial_use_allowed'] ? __('ui.upgrade.allowed') : __('ui.upgrade.not_allowed') }}</span></div>
            <div class="row"><span>{{ __('ui.upgrade.processing_priority') }}</span><span>{{ $priorityLabel((string) $plan['processing_priority']) }}</span></div>
            <div class="row"><span>{{ __('ui.upgrade.overage') }}</span><span>{{ $plan['overage_label'] }}</span></div>
            <div class="row"><span>{{ __('ui.upgrade.voice_quality') }}</span><span>{{ $formatTierLabel((string) $plan['voice_tier']) }}</span></div>
          </div>

          <div class="actions">
            @if($plan['slug'] === $currentPlanSlug)
              <button class="btn" disabled>{{ __('ui.upgrade.current_plan') }}</button>
            @elseif($plan['slug'] === 'creator')
              <a class="btn btn-primary" href="{{ route('billing.page', ['plan' => 'creator']) }}">{{ __('ui.upgrade.upgrade_to_creator') }}</a>
            @elseif($plan['slug'] === 'pro')
              <a class="btn btn-primary" href="{{ route('billing.page', ['plan' => 'pro']) }}">{{ __('ui.upgrade.upgrade_to_pro') }}</a>
            @else
              <a class="btn" href="{{ route('dashboard') }}">{{ __('ui.upgrade.current_free_access') }}</a>
            @endif
          </div>
        </article>
      @endforeach
    </section>
  </main>
  @include('components.footer')
  @include('components.scripts')
</body>
</html>

