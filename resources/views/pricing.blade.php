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

    .plans-grid {
      --plans-max-width: 1100px;
      --plans-gap: 1.25rem;
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: var(--plans-gap);
      margin: 2.5rem auto 3rem;
      max-width: var(--plans-max-width);
    }
    .plans-row-2 {
      grid-column: 1 / -1;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: var(--plans-gap);
      max-width: calc((((var(--plans-max-width) - (var(--plans-gap) * 2)) / 3) * 2) + var(--plans-gap));
      margin: 0 auto;
      width: 100%;
    }
    .plan-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.6rem;
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
      top: -.8rem;
      left: 0;
      right: 0;
      margin-inline: auto;
      width: fit-content;
      background: linear-gradient(135deg, var(--a), var(--b));
      color: white;
      font-size: .7rem;
      font-weight: 700;
      padding: .3rem .85rem;
      border-radius: var(--radius-full);
      text-transform: uppercase;
      letter-spacing: .06em;
      white-space: nowrap;
      z-index: 4;
      box-shadow: 0 2px 8px color-mix(in oklab, var(--a) 30%, transparent);
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
      font-family: 'IBM Plex Sans', sans-serif;
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
        font-weight: bold;
      font-size: .8rem;
      color: var(--muted);
      margin-top: .35rem;
      min-height: 1.2em;
    }
    .price-note.savings {
      color: #F43F5E;
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
      padding: .55rem 0;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 50%, transparent);
      font-size: .95rem;
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
    .plan-trial-link {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      margin-top: .55rem;
      padding: .7rem 1rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--a) 55%, transparent);
      background: color-mix(in oklab, var(--a) 12%, var(--surface));
      color: var(--text);
      font-weight: 700;
      font-size: .9rem;
      text-decoration: none;
    }
    .plan-trial-link[hidden] {
      display: none !important;
    }
    .trial-banner {
      max-width: 46rem;
      margin: 1.25rem auto 0;
      padding: 1rem 1.15rem;
      border-radius: 16px;
      border: 1px solid color-mix(in oklab, var(--a) 55%, transparent);
      background: color-mix(in oklab, var(--a) 14%, var(--surface));
      text-align: start;
    }
    .trial-banner strong {
      display: block;
      margin-bottom: .35rem;
      font-size: 1.05rem;
    }
    .trial-banner p {
      margin: 0;
      color: var(--muted);
      line-height: 1.55;
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
      font-size: .95rem;
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
      font-size: .95rem;
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

    .topups-section {
      padding: 2rem 0;
    }
    .topups-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
      max-width: 680px;
      margin: 0 auto;
    }
    .topup-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
      text-align: center;
      transition: transform .25s ease, border-color .25s ease;
    }
    .topup-card:hover {
      transform: translateY(-3px);
      border-color: color-mix(in oklab, var(--a) 40%, var(--line));
    }
    .topup-card h3 {
      font-size: 1.05rem;
      margin: 0 0 .75rem;
    }
    .topup-prices {
      display: flex;
      flex-direction: column;
      gap: .4rem;
    }
    .topup-price-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: .95rem;
      padding: .3rem 0;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 50%, transparent);
    }
    .topup-price-row:last-child {
      border-bottom: none;
    }
    .topup-plan-name {
      color: var(--muted);
    }
    .topup-plan-price {
      font-weight: 600;
    }
    .topup-note {
      font-size: .8rem;
      color: var(--muted);
      margin-top: .75rem;
    }

    /* ── Discount styles ────────────────────────────── */
    .plan-card.has-discount {
      border-color: color-mix(in oklab, var(--a) 60%, var(--line));
      box-shadow: 0 8px 32px color-mix(in oklab, var(--a) 18%, transparent);
      position: relative;
    }
    html[dir="ltr"] .plan-card.has-discount .plan-header {
      padding-right: 5.1rem;
    }
    html[dir="ltr"] .plan-card.has-discount.popular {
      padding-top: 1.95rem;
    }

    .discount-ribbon {
      position: absolute;
      top: 0; right: 0;
      z-index: 3;
      overflow: hidden;
      width: 8.5rem; height: 8.5rem;
      pointer-events: none;
    }
    .discount-ribbon-inner {
      position: absolute;
      top: 2.25rem;
      left: -0.75rem;
      width: 13rem;
      background: linear-gradient(
135deg, #F43F5E 0%, #E11D48 45%, #7659FA 100%);
      color: white;
      font-size: .9rem;
      font-weight: 800;
      letter-spacing: .02em;
      text-align: center;
      padding: .4rem 0;
      box-shadow: 0 4px 12px color-mix(in oklab, #F43F5E 35%, transparent);
      border-bottom: 2px solid rgba(255, 255, 255, 0.2);
      white-space: nowrap;
      text-transform: uppercase;
      line-height: 1.3;
      transform: rotate(45deg);
    }
    html[dir="ltr"] .discount-ribbon-inner {
      top: 1.95rem;
      left: .15rem;
      width: 11.6rem;
      font-size: .82rem;
      padding: .34rem 0;
    }
    html[dir="rtl"] .discount-ribbon {
      right: auto; left: 0;
    }
    html[dir="rtl"] .discount-ribbon-inner {
      left: -3.75rem;
      transform: rotate(-45deg);
    }
    .plan-card.has-discount.popular .discount-ribbon {
      /* When popular badge is top-center, ribbon stays right/left */
    }

    .price-original-row {
      margin-bottom: .2rem;
    }
    .price-original {
      font-size: 1.1rem;
      font-weight: 600;
      color: var(--muted);
      text-decoration: line-through;
      text-decoration-color: color-mix(in oklab, var(--a) 70%, transparent);
      text-decoration-thickness: 2px;
      opacity: .65;
    }

    .discount-countdown {
      background: linear-gradient(135deg, #F43F5E 0%, #E11D48 45%, #7659FA 100%);
      border-radius: var(--radius-lg);
      padding: .85rem .75rem;
      margin-bottom: 1.25rem;
      box-shadow: inset 0 2px 4px rgba(255,255,255,0.05), 0 4px 12px color-mix(in oklab, var(--a) 8%, transparent);
    }
    .countdown-label {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: .4rem;
      font-size: .9rem;
      font-weight: 700;
      color: #FFF;
      margin-bottom: .75rem;
    }
    .countdown-label svg {
      flex-shrink: 0;
      stroke: #FFF;
      width: 1.15rem;
      height: 1.15rem;
    }
    .countdown-digits {
      display: flex;
      justify-content: center;
      gap: .6rem;
    }
    .countdown-sep {
      font-size: 1.2rem;
      font-weight: 800;
      color: #FFF;
      align-self: flex-start;
      padding-top: .4rem;
    }
    .countdown-unit {
      display: flex;
      flex-direction: column;
      align-items: center;
      min-width: 3rem;
    }
    .countdown-num {
      font-family: 'IBM Plex Sans', 'Inter', sans-serif;
      font-size: 1.2rem;
      font-weight: 800;
      line-height: 1.1;
      color: var(--text);
      background: var(--surface);
      border: 1px solid color-mix(in oklab, var(--a) 25%, var(--line));
      box-shadow: 0 2px 8px color-mix(in oklab, var(--a) 6%, transparent);
      border-radius: .45rem;
      padding: .35rem .5rem;
      min-width: 2.8rem;
      text-align: center;
      transition: background-color .3s ease, color .3s ease;
    }
    .countdown-num.tick {
      animation: digit-tick .3s ease-out;
    }
    @keyframes digit-tick {
      0%   { opacity: 0.8; }
      100% { opacity: 1; }
    }
    .countdown-lbl {
      font-size: .75rem;
      font-weight: 600;
      color:#FFF;
      text-transform: uppercase;
      letter-spacing: .02em;
      margin-top: .4rem;
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
      .plans-row-2 {
        grid-template-columns: 1fr;
        max-width: 420px;
      }
      .features-grid,
      .faq-grid {
        grid-template-columns: 1fr;
      }
      .page-hero {
        padding: 2.5rem 1rem 1.5rem;
      }
      .topups-grid {
        grid-template-columns: 1fr;
      }
      .discount-countdown {
        padding: .65rem .6rem;
      }
      .countdown-num {
        font-size: 1.25rem;
        min-width: 2.4rem;
        padding: .3rem .4rem;
      }
      .countdown-digits {
        gap: .4rem;
      }
      .countdown-sep {
        font-size: 1.1rem;
        padding-top: .3rem;
      }
      .discount-ribbon {
        width: 7.5rem; height: 7.5rem;
      }
      .discount-ribbon-inner {
        font-size: .75rem;
        top: 2.1rem;
        left: -0.75rem;
        width: 11rem;
        padding: .35rem 0;
        transform: rotate(45deg);
      }
      html[dir="ltr"] .plan-card.has-discount .plan-header {
        padding-right: 4.2rem;
      }
      html[dir="ltr"] .plan-card.has-discount.popular {
        padding-top: 1.8rem;
      }
      html[dir="ltr"] .discount-ribbon-inner {
        top: 1.85rem;
        left: .1rem;
        width: 10rem;
        font-size: .72rem;
      }
      html[dir="rtl"] .discount-ribbon-inner {
        left: -2.75rem;
        transform: rotate(-45deg);
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
      // Names and descriptions now come localized from the backend
      $planDisplayName = static function (array $plan): string {
        return (string) ($plan['name'] ?? $plan['slug'] ?? '-');
      };
      $planDescription = static function (array $plan): string {
        return (string) ($plan['description'] ?? '');
      };
    @endphp
    <section class="page-hero reveal">
      <!-- <span class="eyebrow">Flexible pricing</span> -->
      <h1>{{ __('ui.pricing.hero_title') }}</h1>
      <p class="lead">{{ __('ui.pricing.hero_subtitle') }}</p>
      @if(!empty($trialPromo['show']))
        <div class="trial-banner">
          <strong>{{ __('ui.pricing.trial_banner_title', ['days' => (int) ($trialPromo['days'] ?? 7)]) }}</strong>
          <p>{{ __('ui.pricing.trial_banner_body', [
            'quota' => $trialPromo['quota_plan_name'] ?? '',
            'chars' => number_format((int) ($trialPromo['chars'] ?? 0)),
            'minutes' => number_format((int) ($trialPromo['minutes'] ?? 0)),
          ]) }}</p>
        </div>
      @endif
      <div class="billing-wrapper">
        <div class="billing-toggle" id="billingToggle">
          <span class="billing-indicator" aria-hidden="true"></span>
          <button id="monthlyBtn" class="active">{{ __('ui.pricing.monthly') }}</button>
          <button id="yearlyBtn">{{ __('ui.pricing.yearly') }}</button>
        </div>
      </div>
    </section>

    <section class="plans-grid">
      @php
        $planSlugs = collect($pricingPlans)->pluck('slug')->values();
        $currentPlanIndex = $planSlugs->search($activePlanSlug);
        if ($currentPlanIndex === false) $currentPlanIndex = 0;
        $firstRow = array_slice($pricingPlans, 0, 3);
        $secondRow = array_slice($pricingPlans, 3);
      @endphp

      @foreach($firstRow as $index => $plan)
        @include('components._plan-card', ['plan' => $plan, 'index' => $index])
      @endforeach

      @if(count($secondRow) > 0)
      <div class="plans-row-2">
        @foreach($secondRow as $sIndex => $plan)
          @php $index = $sIndex + 3; @endphp
          @include('components._plan-card', ['plan' => $plan, 'index' => $index])
        @endforeach
      </div>
      @endif
    </section>

    {{-- ─── Top-ups Section ──────────────────────────────────────── --}}
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
        @php
          $premiumCheck = '<defs><linearGradient id="ctaCheckGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="var(--ok)"/><stop offset="100%" stop-color="#10B981"/></linearGradient></defs><circle cx="12" cy="12" r="12" style="fill:url(#ctaCheckGrad);stroke:none;" opacity="0.2"/><circle cx="12" cy="12" r="9" style="fill:url(#ctaCheckGrad);stroke:none;"/><path d="M8.5 12L10.5 14.5L16 9" style="stroke:white;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;fill:none;"/>';
        @endphp
        <span class="cta-badge"><svg viewBox="0 0 24 24">{!! $premiumCheck !!}</svg>{{ __('ui.pricing.badge_no_card') }}</span>
        <span class="cta-badge"><svg viewBox="0 0 24 24">{!! $premiumCheck !!}</svg>{{ __('ui.pricing.badge_cancel_anytime') }}</span>
        <span class="cta-badge"><svg viewBox="0 0 24 24">{!! $premiumCheck !!}</svg>{{ __('ui.pricing.badge_secure_billing') }}</span>
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
      var planCards = document.querySelectorAll('.plan-card');
      var upgradeLinks = document.querySelectorAll('[data-upgrade-link="1"]');
      var isYearly = false;
      var i18n = @json($pricingI18n);
      var discountI18n = @json(['badge' => __('ui.pricing.discount_badge')]);
      var yearlyDiscountPercent = {{ $yearlyDiscountPercent ?? 20 }};

      function updatePrices() {
        planCards.forEach(function(card) {
          var isFree = card.getAttribute('data-is-free') === '1';
          var displayMonthly = String(card.getAttribute('data-display-monthly') || '0');
          var displayYearlyMonthly = String(card.getAttribute('data-display-yearly-monthly') || displayMonthly);
          var displayYearlyTotal = String(card.getAttribute('data-display-yearly-total') || displayYearlyMonthly);
          var displaySavings = String(card.getAttribute('data-display-savings') || '');
          var priceValue = card.querySelector('.price-value');
          var priceNote = card.querySelector('.price-note-text');
          if (!priceValue || !priceNote) return;

          if (isFree) {
            priceValue.textContent = displayMonthly;
            priceNote.textContent = i18n.free_forever;
            priceNote.className = 'price-note price-note-text';
          } else if (isYearly) {
            priceValue.textContent = displayYearlyMonthly;
            priceNote.textContent = i18n.billed_yearly_save + ' ' + displayYearlyTotal + '/' + i18n.year + (displaySavings ? ' (' + i18n.save + ' ' + displaySavings + ')' : '');
            priceNote.className = 'price-note price-note-text savings';
          } else {
            priceValue.textContent = displayMonthly;
            priceNote.textContent = i18n.billed_monthly;
            priceNote.className = 'price-note price-note-text';
          }
        });
      }

      function updateUpgradeLinks() {
        upgradeLinks.forEach(function(link) {
          var nextHref = isYearly
            ? String(link.getAttribute('data-yearly-href') || link.getAttribute('href') || '')
            : String(link.getAttribute('data-monthly-href') || link.getAttribute('href') || '');

          if (nextHref !== '') {
            link.setAttribute('href', nextHref);
          }
        });
      }

      function updateDiscountBadges() {
        planCards.forEach(function(card) {
          var ribbonText = card.querySelector('.discount-ribbon-text');
          if (!ribbonText) return;
          var basePercent = parseInt(card.getAttribute('data-discount-percent') || '0', 10);
          if (basePercent <= 0) return;
          // Business rule: show total discount as a direct deduction from the original total.
          var effectivePercent = isYearly ? Math.min(99, basePercent + yearlyDiscountPercent) : basePercent;
          ribbonText.textContent = discountI18n.badge.replace(':percent', effectivePercent);
        });
      }

      function updateTrialLinks() {
        document.querySelectorAll('[data-trial-monthly]').forEach(function(link) {
          link.hidden = isYearly;
        });
      }

      function setBillingMode(yearly) {
        isYearly = yearly;
        monthlyBtn.classList.toggle('active', !yearly);
        yearlyBtn.classList.toggle('active', yearly);
        billingToggle.classList.toggle('yearly', yearly);
        updatePrices();
        updateUpgradeLinks();
        updateDiscountBadges();
        updateTrialLinks();
      }

      monthlyBtn.addEventListener('click', function() { setBillingMode(false); });
      yearlyBtn.addEventListener('click', function() { setBillingMode(true); });

      updatePrices();
      updateUpgradeLinks();
      updateTrialLinks();

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
  <script>
    /* ── Discount countdown timers ──────────────────── */
    (function() {
      var countdowns = document.querySelectorAll('.discount-countdown');
      if (!countdowns.length) return;

      function pad(n) { return n < 10 ? '0' + n : '' + n; }

      function updateCountdown(el) {
        var ends = el.getAttribute('data-ends');
        if (!ends) return false;
        var endTime = new Date(ends).getTime();
        var now = Date.now();
        var diff = Math.max(0, endTime - now);

        if (diff <= 0) {
          el.style.display = 'none';
          return false;
        }

        var d = Math.floor(diff / 86400000);
        var h = Math.floor((diff % 86400000) / 3600000);
        var m = Math.floor((diff % 3600000) / 60000);
        var s = Math.floor((diff % 60000) / 1000);

        var units = el.querySelectorAll('.countdown-num');
        units.forEach(function(unit) {
          var key = unit.getAttribute('data-unit');
          var val = key === 'd' ? pad(d) : key === 'h' ? pad(h) : key === 'm' ? pad(m) : pad(s);
          if (unit.textContent !== val) {
            unit.textContent = val;
            unit.classList.remove('tick');
            void unit.offsetWidth;
            unit.classList.add('tick');
            unit.addEventListener('animationend', function handler() {
              unit.classList.remove('tick');
              unit.removeEventListener('animationend', handler);
            });
          }
        });

        return true;
      }

      function tickAll() {
        var anyActive = false;
        countdowns.forEach(function(el) {
          if (updateCountdown(el)) anyActive = true;
        });
        if (anyActive) requestAnimationFrame(function() { setTimeout(tickAll, 1000); });
      }

      tickAll();
    })();
  </script>
</body>
</html>
