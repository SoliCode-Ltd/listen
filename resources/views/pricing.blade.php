@include('components.page-head', ['pageTitle' => __('ui.seo.pricing_title'), 'page' => 'pricing'])
  <style>
    .bg-orb {
      position: fixed;
      z-index: -1;
      pointer-events: none;
      border-radius: 999px;
      filter: blur(36px);
      opacity: .25;
      animation: float 10s ease-in-out infinite;
    }
    .bg-orb.a {
      width: 16rem;
      height: 16rem;
      background: color-mix(in oklab, var(--a) 74%, white 26%);
      left: -4rem;
      top: 15vh;
    }
    .bg-orb.b {
      width: 20rem;
      height: 20rem;
      background: color-mix(in oklab, var(--b) 75%, white 25%);
      right: -4rem;
      bottom: 8vh;
      animation-delay: -3s;
    }

    .page-hero {
      text-align: center;
      padding: 3.5rem 1rem 2rem;
    }
    .eyebrow {
      display: inline-block;
      padding: .35rem .7rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .75rem;
      color: var(--muted);
      letter-spacing: .1em;
      text-transform: uppercase;
      margin-bottom: .75rem;
    }
    .page-hero h1 {
      margin: 0 0 .75rem;
      font-size: clamp(2rem, 4vw, 2.75rem);
      letter-spacing: -.02em;
    }
    html[dir="rtl"] .page-hero h1 {
      letter-spacing: 0;
      line-height: 1.2;
      text-wrap: balance;
    }
    .page-hero .lead {
      color: var(--muted);
      max-width: 48ch;
      margin: 0 auto 1.5rem;
      font-size: 1.05rem;
      line-height: 1.6;
    }

    .billing-wrapper {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: .6rem;
    }
    .billing-toggle {
      display: inline-flex;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius-full);
      padding: .22rem;
      position: relative;
      isolation: isolate;
      overflow: hidden;
    }
    .billing-indicator {
      position: absolute;
      top: .22rem;
      left: .22rem;
      width: calc(50% - .22rem);
      height: calc(100% - .44rem);
      border-radius: var(--radius-full);
      background: linear-gradient(135deg, var(--a), var(--b));
      transition: transform .18s ease;
      z-index: 0;
    }
    .billing-toggle.yearly .billing-indicator {
      transform: translateX(100%);
    }
    .billing-toggle button {
      border: none;
      background: transparent;
      color: var(--muted);
      padding: .55rem 1.1rem;
      min-width: 6.2rem;
      border-radius: var(--radius-full);
      cursor: pointer;
      font-weight: 600;
      font-size: .9rem;
      transition: color .18s ease;
      position: relative;
      z-index: 1;
    }
    html[dir="rtl"] .billing-toggle button {
      min-width: 6.8rem;
      font-family: var(--font-body-ar);
    }
    .billing-toggle button:hover {
      color: var(--text);
    }
    .billing-toggle button.active { color: white; }

    html[dir="rtl"] .billing-indicator {
      left: auto;
      right: .22rem;
    }
    html[dir="rtl"] .billing-toggle.yearly .billing-indicator {
      transform: translateX(-100%);
    }
    .billing-savings {
      font-size: .78rem;
      color: color-mix(in oklab, var(--ok) 88%, var(--text));
      background: color-mix(in oklab, var(--ok) 14%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--ok) 45%, var(--line));
      padding: .24rem .62rem;
      border-radius: var(--radius-full);
      opacity: .85;
      transform: translateY(-2px);
      transition: opacity .18s ease, transform .18s ease;
    }
    .billing-savings.show {
      opacity: 1;
      transform: translateY(0);
    }

    .plans-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1.25rem;
      margin: 2.5rem 0 3rem;
    }
    .plan-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.75rem;
      box-shadow: var(--shadow);
      transition: transform .25s ease, border-color .25s ease, box-shadow .25s ease;
      position: relative;
      display: flex;
      flex-direction: column;
    }
    .plan-card:hover {
      transform: translateY(-6px);
      box-shadow: 0 20px 40px rgba(0,0,0,.25);
    }
    .plan-card.popular {
      border-color: var(--a);
      box-shadow: 0 8px 32px color-mix(in oklab, var(--a) 20%, transparent);
    }
    .plan-card.popular:hover {
      box-shadow: 0 20px 40px color-mix(in oklab, var(--a) 25%, transparent);
    }
    .popular-badge {
      position: absolute;
      top: -.65rem;
      left: 50%;
      transform: translateX(-50%);
      background: linear-gradient(135deg, var(--a), var(--b));
      color: white;
      font-size: .7rem;
      font-weight: 700;
      padding: .3rem .85rem;
      border-radius: var(--radius-full);
      text-transform: uppercase;
      letter-spacing: .06em;
      white-space: nowrap;
    }

    .plan-header {
      margin-bottom: .5rem;
    }
    .plan-name {
      font-size: 1.25rem;
      font-weight: 700;
      margin: 0 0 .3rem;
    }
    .plan-desc {
      color: var(--muted);
      font-size: .85rem;
      margin: 0;
      line-height: 1.5;
    }

    .plan-pricing {
      margin: 1.25rem 0;
      min-height: 72px;
    }
    .price-row {
      display: flex;
      align-items: baseline;
      gap: .15rem;
    }
    .price-main {
      display: inline-flex;
      align-items: baseline;
      gap: .15rem;
      direction: ltr;
      unicode-bidi: isolate;
    }
    .price-currency {
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--text);
    }
    .price-amount {
      font-size: 2.75rem;
      font-weight: 800;
      line-height: 1;
      transition: transform .16s ease, opacity .16s ease;
    }
    .price-period {
      font-size: .95rem;
      color: var(--muted);
      font-weight: 500;
      margin-inline-start: .1rem;
    }
    .price-note {
      font-size: .8rem;
      color: var(--muted);
      margin-top: .35rem;
      min-height: 1.2em;
    }
    .price-note.savings {
      color: var(--ok);
    }

    .plan-quota {
      display: inline-block;
      padding: .4rem .75rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .82rem;
      color: var(--text);
      font-weight: 600;
      margin-bottom: 1.25rem;
      background: var(--surface);
    }

    .plan-features {
      list-style: none;
      padding: 0;
      margin: 0 0 1.5rem;
      flex: 1;
    }
    .plan-features li {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: .6rem 0;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 50%, transparent);
      font-size: .88rem;
    }
    .plan-features li:first-child {
      padding-top: 0;
    }
    .plan-features li:last-child {
      border-bottom: none;
      padding-bottom: 0;
    }
    .feature-label {
      color: var(--muted);
      display: flex;
      align-items: center;
      gap: .4rem;
    }
    .feature-label svg {
      width: .9rem;
      height: .9rem;
      stroke: var(--muted);
      fill: none;
      stroke-width: 2;
      flex-shrink: 0;
    }
    .feature-value {
      font-weight: 600;
      text-align: right;
    }

    .plan-action {
      margin-top: auto;
    }
    .plan-btn {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: .85rem 1rem;
      border-radius: var(--radius);
      text-decoration: none;
      font-weight: 600;
      font-size: .92rem;
      transition: all .2s ease;
      border: none;
      cursor: pointer;
    }
    .plan-btn.btn-primary {
      background: linear-gradient(135deg, var(--a), var(--b));
      color: white;
    }
    .plan-btn.btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px color-mix(in oklab, var(--b) 30%, transparent);
    }
    .plan-btn.btn-disabled {
      background: var(--surface2);
      color: var(--muted);
      cursor: not-allowed;
    }

    .features-section {
      padding: 2.5rem 0;
    }
    .section-title {
      text-align: center;
      margin: 0 0 .5rem;
      font-size: clamp(1.5rem, 3vw, 2rem);
    }
    .section-subtitle {
      text-align: center;
      color: var(--muted);
      margin: 0 0 2rem;
      font-size: 1rem;
    }
    .features-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1rem;
    }
    .feature-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
      transition: transform .25s ease, border-color .25s ease;
    }
    .feature-card:hover {
      transform: translateY(-3px);
      border-color: color-mix(in oklab, var(--a) 45%, var(--line));
    }
    .feature-icon {
      width: 2.5rem;
      height: 2.5rem;
      margin-bottom: 1rem;
      color: var(--a);
    }
    .feature-card h3 {
      font-size: 1.05rem;
      margin: 0 0 .5rem;
    }
    .feature-card p {
      color: var(--muted);
      font-size: .88rem;
      margin: 0;
      line-height: 1.55;
    }

    .faq-section {
      padding: 2.5rem 0;
    }
    .faq-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }
    .faq-item {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
      transition: transform .25s ease, border-color .25s ease;
    }
    .faq-item:hover {
      transform: translateY(-2px);
      border-color: color-mix(in oklab, var(--a) 40%, var(--line));
    }
    .faq-item h3 {
      font-size: 1rem;
      margin: 0 0 .6rem;
      font-weight: 600;
    }
    .faq-item p {
      color: var(--muted);
      font-size: .88rem;
      margin: 0;
      line-height: 1.55;
    }

    .cta-section {
      text-align: center;
      padding: 3rem 2rem;
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      margin: 2rem 0 3rem;
      box-shadow: var(--shadow);
    }
    .cta-section h2 {
      margin: 0 0 .6rem;
      font-size: clamp(1.5rem, 3vw, 2rem);
    }
    .cta-section p {
      color: var(--muted);
      margin: 0 0 1.5rem;
      font-size: 1rem;
    }
    .cta-badges {
      display: flex;
      justify-content: center;
      gap: 1.75rem;
      margin-top: 1.5rem;
      padding-top: 1.5rem;
      border-top: 1px solid var(--line);
      flex-wrap: wrap;
    }
    .cta-badge {
      color: var(--muted);
      font-size: .85rem;
      display: flex;
      align-items: center;
      gap: .4rem;
    }
    .cta-badge svg {
      width: 1rem;
      height: 1rem;
      stroke: var(--ok);
      stroke-width: 2.5;
      fill: none;
    }

    .reveal {
      opacity: 0;
      transform: translateY(16px);
      transition: opacity .5s ease, transform .5s ease;
    }
    .reveal.show {
      opacity: 1;
      transform: translateY(0);
    }

    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-14px); }
    }

    @media (max-width: 960px) {
      .plans-grid {
        grid-template-columns: 1fr;
        max-width: 420px;
        margin-left: auto;
        margin-right: auto;
      }
      .features-grid,
      .faq-grid {
        grid-template-columns: 1fr;
      }
      .page-hero {
        padding: 2.5rem 1rem 1.5rem;
      }
    }
  </style>
</head>
<body>
  <div class="bg-orb a"></div>
  <div class="bg-orb b"></div>

  @include('components.header')

  <main class="container">
    @php
      $planDisplayName = static function (array $plan): string {
        return match ((string) ($plan['slug'] ?? '')) {
          'free' => __('ui.pricing.plan_name_free'),
          'creator' => __('ui.pricing.plan_name_creator'),
          'pro' => __('ui.pricing.plan_name_pro'),
          default => (string) ($plan['name'] ?? '-'),
        };
      };
    @endphp
    <section class="page-hero reveal">
      <!-- <span class="eyebrow">Flexible pricing</span> -->
      <h1>{{ __('ui.pricing.hero_title') }}</h1>
      <p class="lead">{{ __('ui.pricing.hero_subtitle') }}</p>

      <div class="billing-wrapper">
        <div class="billing-toggle" id="billingToggle">
          <span class="billing-indicator" aria-hidden="true"></span>
          <button id="monthlyBtn" class="active">{{ __('ui.pricing.monthly') }}</button>
          <button id="yearlyBtn">{{ __('ui.pricing.yearly') }}</button>
        </div>
        <span class="billing-savings" id="billingSavings">{{ __('ui.pricing.annual_saves') }}</span>
      </div>
    </section>

    <section class="plans-grid">
      @php
        $planSlugs = collect($pricingPlans)->pluck('slug')->values();
        $currentPlanIndex = $planSlugs->search($activePlanSlug);
        if ($currentPlanIndex === false) $currentPlanIndex = 0;
      @endphp

      @foreach($pricingPlans as $index => $plan)
        @php
          $isCurrent = $activePlanSlug === $plan['slug'];
          $isLower = auth()->check() && $index < $currentPlanIndex && $plan['slug'] !== 'free';
          $monthlyCents = max(0, (int) ($plan['price_monthly_cents'] ?? 0));
          $yearlyCents = max(0, (int) ($plan['price_yearly_cents'] ?? 0));
          if ($yearlyCents <= 0 && $monthlyCents > 0) {
            $yearlyCents = (int) round($monthlyCents * 12 * 0.8);
          }
          $isFree = $monthlyCents <= 0;
          $savingsCents = $monthlyCents > 0 ? max(0, ($monthlyCents * 12) - $yearlyCents) : 0;
        @endphp
        <div class="plan-card {{ $plan['is_popular'] && !$isCurrent ? 'popular' : '' }} reveal"
             data-plan-slug="{{ $plan['slug'] }}"
             data-is-free="{{ $isFree ? '1' : '0' }}"
             data-monthly-cents="{{ $monthlyCents }}"
             data-yearly-cents="{{ $yearlyCents }}"
             data-savings-cents="{{ $savingsCents }}">

          @if($plan['is_popular'] && !$isCurrent)
            <span class="popular-badge">{{ __('ui.pricing.most_popular') }}</span>
          @endif

          <div class="plan-header">
            <h2 class="plan-name">{{ $planDisplayName($plan) }}</h2>
            <p class="plan-desc">
    {{
        $plan['slug'] === 'free' ? __('ui.pricing.plan_desc_free') :
        ($plan['slug'] === 'creator' ? __('ui.pricing.plan_desc_creator') :
        __('ui.pricing.plan_desc_pro'))
    }}
</p>
          </div>

          <div class="plan-pricing">
            <div class="price-row">
              <span class="price-main" dir="ltr">
                <span class="price-currency">$</span>
                <span class="price-amount price-value">0</span>
              </span>
              <span class="price-period">{{ __('ui.pricing.per_month_short') }}</span>
            </div>
            <div class="price-note price-note-text"></div>
          </div>

          <div class="plan-quota">{{ number_format($plan['monthly_chars']) }} {{ __('ui.pricing.characters_per_month') }}</div>

          <ul class="plan-features">
            @foreach($plan['features'] as $feature)
              @php
                $iconSvg = match($feature['icon'] ?? 'check') {
                  'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
                  'type' => '<polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/>',
                  'lock' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
                  'bolt' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
                  'mic' => '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>',
                  'globe' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
                  'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/>',
                  'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
                  'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
                  'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
                  'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                  default => '<polyline points="20 6 9 17 4 12"/>',
                };
              @endphp
            <li>
              <span class="feature-label">
                <svg viewBox="0 0 24 24">{!! $iconSvg !!}</svg>
                {{ $feature['label'] ?? '' }}
              </span>
              <span class="feature-value">{{ $feature['value'] ?? __('ui.pricing.included_mark') }}</span>
            </li>
            @endforeach
          </ul>

          <div class="plan-action">
            @auth
              @if($isCurrent)
                <span class="plan-btn btn-disabled">{{ __('ui.pricing.current_plan') }}</span>
              @elseif($isLower || $plan['slug'] === 'free')
                <span class="plan-btn btn-disabled">{{ __('ui.pricing.downgrade') }}</span>
              @else
                <a class="plan-btn btn-primary" href="{{ route('billing.page', ['plan' => $plan['slug']]) }}">{{ __('ui.pricing.upgrade_now') }}</a>
              @endif
            @else
              <a class="plan-btn btn-primary" href="{{ route('register') }}">{{ $plan['slug'] === 'free' ? __('ui.pricing.start_free') : __('ui.pricing.get_started') }}</a>
            @endauth
          </div>
        </div>
      @endforeach
    </section>

    <section class="features-section">
      <h2 class="section-title reveal">{{ __('ui.pricing.included_title') }}</h2>
      <p class="section-subtitle reveal">{{ __('ui.pricing.included_subtitle') }}</p>
      <div class="features-grid">
        <article class="feature-card reveal">
          <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
            <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
            <line x1="12" y1="19" x2="12" y2="23"/>
            <line x1="8" y1="23" x2="16" y2="23"/>
          </svg>
          <h3>{{ __('ui.pricing.feature1_title') }}</h3>
          <p>{{ __('ui.pricing.feature1_body') }}</p>
        </article>
        <article class="feature-card reveal">
          <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            <line x1="11" y1="8" x2="11" y2="14"/>
            <line x1="8" y1="11" x2="14" y2="11"/>
          </svg>
          <h3>{{ __('ui.pricing.feature2_title') }}</h3>
          <p>{{ __('ui.pricing.feature2_body') }}</p>
        </article>
        <article class="feature-card reveal">
          <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="5" y="2" width="14" height="20" rx="2" ry="2"/>
            <line x1="12" y1="18" x2="12.01" y2="18"/>
          </svg>
          <h3>{{ __('ui.pricing.feature3_title') }}</h3>
          <p>{{ __('ui.pricing.feature3_body') }}</p>
        </article>
      </div>
    </section>

    <section class="faq-section">
      <h2 class="section-title reveal">{{ __('ui.pricing.faq_title') }}</h2>
      <div class="faq-grid">
        <article class="faq-item reveal">
          <h3>{{ __('ui.pricing.faq1_q') }}</h3>
          <p>{{ __('ui.pricing.faq1_a') }}</p>
        </article>
        <article class="faq-item reveal">
          <h3>{{ __('ui.pricing.faq2_q') }}</h3>
          <p>{{ __('ui.pricing.faq2_a') }}</p>
        </article>
        <article class="faq-item reveal">
          <h3>{{ __('ui.pricing.faq3_q') }}</h3>
          <p>{{ __('ui.pricing.faq3_a') }}</p>
        </article>
        <article class="faq-item reveal">
          <h3>{{ __('ui.pricing.faq4_q') }}</h3>
          <p>{{ __('ui.pricing.faq4_a') }}</p>
        </article>
      </div>
    </section>

    <section class="cta-section reveal">
      <h2>{{ __('ui.pricing.cta_title') }}</h2>
      <p>{{ __('ui.pricing.cta_subtitle') }}</p>
      @auth
        <a class="btn btn-primary" href="{{ route('dashboard') }}">{{ __('ui.pricing.go_to_dashboard') }}</a>
      @else
        <a class="btn btn-primary" href="{{ route('register') }}">{{ __('ui.pricing.create_free_account') }}</a>
      @endauth
      <div class="cta-badges">
        <span class="cta-badge"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>{{ __('ui.pricing.badge_no_card') }}</span>
        <span class="cta-badge"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>{{ __('ui.pricing.badge_cancel_anytime') }}</span>
        <span class="cta-badge"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>{{ __('ui.pricing.badge_secure_billing') }}</span>
      </div>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @php
    $pricingI18n = [
      'free_forever' => __('ui.pricing.free_forever'),
      'billed_yearly_save' => __('ui.pricing.billed_yearly_save'),
      'billed_monthly' => __('ui.pricing.billed_monthly'),
      'year' => __('ui.pricing.year'),
      'save' => __('ui.pricing.save'),
    ];
  @endphp
  <script>
    (function() {
      var monthlyBtn = document.getElementById('monthlyBtn');
      var yearlyBtn = document.getElementById('yearlyBtn');
      var billingToggle = document.getElementById('billingToggle');
      var billingSavings = document.getElementById('billingSavings');
      var planCards = document.querySelectorAll('.plan-card');
      var isYearly = false;
      var i18n = @json($pricingI18n);

      function formatUsd(cents) {
        var dollars = (Number(cents || 0) / 100);
        return dollars.toLocaleString('en-US', {
          minimumFractionDigits: dollars % 1 === 0 ? 0 : 2,
          maximumFractionDigits: 2
        });
      }

      function formatUsdLabel(cents) {
        return '\u2066$' + formatUsd(cents) + '\u2069';
      }

      function updatePrices() {
        planCards.forEach(function(card) {
          var isFree = card.getAttribute('data-is-free') === '1';
          var monthlyCents = parseInt(card.getAttribute('data-monthly-cents'), 10) || 0;
          var yearlyCents = parseInt(card.getAttribute('data-yearly-cents'), 10) || 0;
          var savingsCents = parseInt(card.getAttribute('data-savings-cents'), 10) || 0;
          var yearlyMonthlyCents = yearlyCents > 0 ? Math.round(yearlyCents / 12) : 0;

          var priceValue = card.querySelector('.price-value');
          var priceNote = card.querySelector('.price-note-text');
          if (!priceValue || !priceNote) return;

          if (isFree) {
            priceValue.textContent = '0';
            priceNote.textContent = i18n.free_forever;
            priceNote.className = 'price-note price-note-text';
          } else if (isYearly) {
            priceValue.textContent = formatUsd(yearlyMonthlyCents);
            var yearlyLabel = formatUsdLabel(yearlyCents);
            var savingsLabel = formatUsdLabel(savingsCents);
            priceNote.textContent = i18n.billed_yearly_save + ' ' + yearlyLabel + '/' + i18n.year + ' (' + i18n.save + ' ' + savingsLabel + ')';
            priceNote.className = 'price-note price-note-text savings';
          } else {
            priceValue.textContent = formatUsd(monthlyCents);
            priceNote.textContent = i18n.billed_monthly;
            priceNote.className = 'price-note price-note-text';
          }
        });
      }

      function setBillingMode(yearly) {
        isYearly = yearly;
        monthlyBtn.classList.toggle('active', !yearly);
        yearlyBtn.classList.toggle('active', yearly);
        billingSavings.classList.toggle('show', yearly);
        billingToggle.classList.toggle('yearly', yearly);
        updatePrices();
      }

      monthlyBtn.addEventListener('click', function() { setBillingMode(false); });
      yearlyBtn.addEventListener('click', function() { setBillingMode(true); });

      updatePrices();

      var revealElements = document.querySelectorAll('.reveal');
      var revealObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry, index) {
          if (entry.isIntersecting) {
            setTimeout(function() {
              entry.target.classList.add('show');
            }, index * 60);
            revealObserver.unobserve(entry.target);
          }
        });
      }, { threshold: 0.1 });

      revealElements.forEach(function(el) { revealObserver.observe(el); });
    })();
  </script>
</body>
</html>
