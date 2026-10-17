@include('components.page-head', ['pageTitle' => __('ui.seo.pricing_title'), 'page' => 'pricing', 'faqItems' => array_map(fn ($n) => ['question' => __('ui.pricing.faq'.$n.'_q'), 'answer' => __('ui.pricing.faq'.$n.'_a')], [1, 2, 3, 4])])
  <style>
    /* ── Pricing page ───────────────────────────────────────────────
       Plans in one aligned row: every card's name, price, button, minutes and
       lines start at the same height (CSS subgrid), so plans compare at a glance. */
    .pricing-page {
      width: min(1320px, 94%);
      margin: 0 auto;
    }

    .page-hero {
      text-align: center;
      padding: 3.75rem 1rem 2.25rem;
    }
    .page-hero h1 {
      margin: 0 0 .85rem;
      font-size: clamp(2rem, 4vw, 2.9rem);
      letter-spacing: -.03em;
      text-wrap: balance;
    }
    html[dir="rtl"] .page-hero h1 {
      letter-spacing: 0;
      line-height: 1.25;
    }
    .page-hero .lead {
      color: var(--muted);
      max-width: 58ch;
      margin: 0 auto 1.75rem;
      font-size: 1.05rem;
      line-height: 1.65;
      text-wrap: pretty;
    }

    /* Monthly / yearly switch: two equal halves, the saving on the yearly one. */
    .billing-wrapper {
      display: flex;
      justify-content: center;
    }
    .billing-toggle {
      display: inline-grid;
      grid-template-columns: 1fr 1fr;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius-full);
      padding: .25rem;
      position: relative;
      isolation: isolate;
    }
    .billing-indicator {
      position: absolute;
      top: .25rem;
      bottom: .25rem;
      left: .25rem;
      width: calc(50% - .25rem);
      border-radius: var(--radius-full);
      background: var(--brand-accent);
      box-shadow: 0 6px 16px color-mix(in oklab, var(--a) 30%, transparent);
      transition: transform .2s ease;
      z-index: 0;
    }
    .billing-toggle.yearly .billing-indicator { transform: translateX(100%); }
    html[dir="rtl"] .billing-indicator { left: auto; right: .25rem; }
    html[dir="rtl"] .billing-toggle.yearly .billing-indicator { transform: translateX(-100%); }
    .billing-toggle button {
      position: relative;
      z-index: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: .45rem;
      min-height: 42px;
      padding: 0 1.15rem;
      border: 0;
      border-radius: var(--radius-full);
      background: transparent;
      color: var(--muted);
      font: inherit;
      font-size: .92rem;
      font-weight: 600;
      cursor: pointer;
      white-space: nowrap;
      transition: color .2s ease;
    }
    .billing-toggle button:hover { color: var(--text); }
    .billing-toggle button.active { color: #fff; }
    .billing-toggle button:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
    .toggle-save {
      padding: .12rem .45rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--ok) 16%, transparent);
      color: color-mix(in oklab, var(--ok) 50%, var(--text));
      font-size: .74rem;
      font-weight: 700;
    }
    .billing-toggle button.active .toggle-save {
      background: rgba(255, 255, 255, .22);
      color: #fff;
    }

    /* ── Plan cards ── */
    .plans-grid {
      --plans: 5;
      display: grid;
      grid-template-columns: repeat(var(--plans), minmax(0, 1fr));
      column-gap: 1rem;
      row-gap: 0;
      align-items: stretch;
    }
    .plan-card {
      position: relative;
      display: grid;
      grid-row: span 5;
      grid-template-rows: subgrid;
      row-gap: 0;
      margin-bottom: 1rem;
      padding: 1.5rem 1.25rem 1.35rem;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 1.1rem;
      transition: border-color .25s ease, box-shadow .25s ease;
    }
    .plan-card:hover {
      border-color: color-mix(in oklab, var(--a) 28%, var(--line));
      box-shadow: var(--shadow);
    }
    .plan-card.popular {
      border: 1.5px solid var(--brand-accent);
      background:
        linear-gradient(180deg, color-mix(in oklab, var(--a) 7%, var(--surface)), var(--surface) 42%);
      box-shadow: 0 18px 44px -18px color-mix(in oklab, var(--a) 45%, transparent);
    }
    .plan-top { display: flex; flex-direction: column; align-items: flex-start; }
    .popular-badge {
      display: inline-flex;
      align-items: center;
      gap: .3rem;
      margin-bottom: .6rem;
      padding: .22rem .6rem;
      border-radius: var(--radius-full);
      background: var(--brand-accent);
      color: #fff;
      font-size: .72rem;
      font-weight: 700;
      letter-spacing: .02em;
    }
    .popular-crown { width: .85rem; height: .85rem; fill: none; stroke: currentColor; stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
    .plan-name {
      margin: 0;
      font-size: 1.15rem;
      font-weight: 700;
      letter-spacing: -.01em;
    }
    .plan-desc {
      margin: .35rem 0 0;
      color: var(--muted);
      font-size: .86rem;
      line-height: 1.5;
      min-height: 2.6em;
    }

    .plan-pricing {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: .35rem;
      padding: 1.1rem 0 1.15rem;
    }
    .price-original-row { display: inline-flex; align-items: center; gap: .5rem; }
    .price-original {
      color: var(--muted);
      font-size: .92rem;
      text-decoration: line-through;
      text-decoration-thickness: 1.5px;
    }
    .price-off {
      padding: .1rem .45rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--ok) 15%, transparent);
      color: color-mix(in oklab, var(--ok) 50%, var(--text));
      font-size: .74rem;
      font-weight: 700;
    }
    .price-row { display: flex; align-items: baseline; gap: .3rem; }
    .price-amount {
      font-size: clamp(1.9rem, 2.6vw, 2.35rem);
      font-weight: 800;
      line-height: 1;
      letter-spacing: -.03em;
      font-variant-numeric: tabular-nums;
    }
    .price-period { color: var(--muted); font-size: .9rem; font-weight: 500; }
    .price-note { color: var(--muted); font-size: .8rem; min-height: 0; }
    .price-note:empty { display: none; }
    .price-note.savings { color: color-mix(in oklab, var(--ok) 50%, var(--text)); font-weight: 600; }

    /* The offer, in two plain lines: what it is, and the price after it. */
    .discount-terms {
      display: block;
      margin-top: .15rem;
    }
    .discount-terms-icon { display: none; }
    .discount-terms-body { display: flex; flex-direction: column; gap: .05rem; }
    .discount-terms-offer { color: var(--accent-text); font-size: .8rem; font-weight: 700; line-height: 1.45; }
    .discount-terms-then { color: var(--muted); font-size: .78rem; line-height: 1.45; }
    .discount-terms-then[hidden] { display: none; }

    .plan-badges { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; margin-bottom: .6rem; }
    .plan-badges .popular-badge { margin-bottom: 0; }
    /* When the offer ends: a small chip that counts down. */
    .discount-countdown {
      display: inline-flex;
      align-items: center;
      gap: .3rem;
      padding: .22rem .55rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--a) 10%, transparent);
      color: var(--accent-text);
      font-size: .72rem;
      font-weight: 700;
      white-space: nowrap;
    }
    .discount-countdown > svg { width: .8rem; height: .8rem; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; }
    .countdown-digits { display: inline-flex; gap: .25rem; font-variant-numeric: tabular-nums; }
    .countdown-unit { display: inline-flex; align-items: baseline; }
    .countdown-unit[hidden] { display: none; }
    .countdown-num { font-weight: 800; }
    .countdown-lbl { font-weight: 600; }

    .plan-trial-chip {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      margin-top: .2rem;
      padding: .22rem .6rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--ok) 14%, transparent);
      color: color-mix(in oklab, var(--ok) 50%, var(--text));
      font-size: .76rem;
      font-weight: 700;
    }
    .plan-trial-chip svg { width: .85rem; height: .85rem; fill: none; stroke: currentColor; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
    .plans-grid.is-yearly [data-trial-monthly-only],
    .plans-grid:not(.is-yearly) [data-trial-yearly-only] {
      display: none !important;
    }

    .plan-action { padding-bottom: 1.25rem; }
    .plan-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      min-height: 46px;
      padding: .6rem 1rem;
      border-radius: var(--btn-radius);
      font-size: .93rem;
      font-weight: 700;
      text-align: center;
      text-decoration: none;
      transition: background-color .2s ease, border-color .2s ease, color .2s ease;
    }
    .plan-btn.btn-primary { background: var(--brand-accent); color: #fff; border: 1px solid transparent; }
    .plan-btn.btn-primary:hover { background: var(--brand-hover); }
    .plan-btn.btn-outline {
      background: transparent;
      color: var(--text);
      border: 1px solid color-mix(in oklab, var(--a) 40%, var(--line));
    }
    .plan-btn.btn-outline:hover { border-color: var(--brand-accent); background: color-mix(in oklab, var(--a) 7%, transparent); }
    .plan-btn:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
    .plan-btn.btn-disabled {
      background: var(--surface2);
      color: var(--muted);
      border: 1px solid var(--line);
      cursor: default;
    }

    /* Minutes first, the credits behind them second. */
    .plan-quota {
      display: flex;
      flex-direction: column;
      gap: .35rem;
      padding: 1.05rem 0;
      border-top: 1px solid var(--line);
      border-bottom: 1px solid var(--line);
      font-size: .9rem;
      font-weight: 600;
    }
    .plan-minutes { display: flex; flex-direction: column; }
    .plan-minutes-number {
      font-size: 1.45rem;
      font-weight: 800;
      line-height: 1.15;
      letter-spacing: -.02em;
      font-variant-numeric: tabular-nums;
    }
    .plan-minutes-unit { color: var(--muted); font-size: .82rem; font-weight: 500; }
    .plan-quota-credits {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      color: var(--muted);
      font-size: .8rem;
      font-weight: 500;
      cursor: help;
    }
    .plan-quota-coin { font-size: 1rem; flex: none; }
    .plan-quota-amount { display: inline-flex; flex-wrap: wrap; align-items: baseline; gap: .25rem; }
    .plan-quota-number { color: var(--text); font-weight: 700; font-variant-numeric: tabular-nums; }

    .plan-features {
      list-style: none;
      margin: 0;
      padding: 1rem 0 0;
      display: flex;
      flex-direction: column;
      gap: .7rem;
    }
    .plan-features li {
      display: flex;
      flex-direction: column;
      gap: .1rem;
      font-size: .85rem;
    }
    .feature-label {
      display: inline-flex;
      align-items: flex-start;
      gap: .45rem;
      color: var(--muted);
      line-height: 1.4;
    }
    .feature-label svg {
      flex: none;
      width: .95rem;
      height: .95rem;
      margin-top: .1rem;
      fill: none;
      stroke: currentColor;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .feature-value {
      padding-inline-start: 1.4rem;
      color: var(--text);
      font-weight: 650;
      line-height: 1.4;
    }
    .plan-features li.is-tick .feature-label { color: var(--text); font-weight: 600; }
    .plan-features li.is-tick .feature-label svg { color: var(--ok); stroke-width: 2.6; }

    /* Trust line and what every plan includes, right under the cards. */
    .plans-trust {
      display: flex;
      justify-content: center;
      flex-wrap: wrap;
      gap: .6rem 1.75rem;
      margin: .5rem 0 0;
      color: var(--muted);
      font-size: .88rem;
    }
    .plans-trust span { display: inline-flex; align-items: center; gap: .4rem; }
    .plans-trust svg { width: 1rem; height: 1rem; fill: none; stroke: var(--ok); stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }

    .plans-common {
      margin: 2.25rem auto 0;
      padding: 1.25rem 1.5rem;
      max-width: 980px;
      border: 1px solid var(--line);
      border-radius: 1rem;
      background: var(--surface);
      text-align: center;
    }
    .plans-common-title {
      margin: 0 0 .85rem;
      font-size: .82rem;
      font-weight: 700;
      letter-spacing: .06em;
      text-transform: uppercase;
      color: var(--muted);
    }
    html[dir="rtl"] .plans-common-title { letter-spacing: 0; }
    .plans-common ul {
      list-style: none;
      margin: 0;
      padding: 0;
      display: flex;
      justify-content: center;
      flex-wrap: wrap;
      gap: .55rem;
    }
    .plans-common li {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      padding: .42rem .8rem;
      border-radius: var(--radius-full);
      background: var(--surface2);
      border: 1px solid var(--line);
      font-size: .85rem;
      color: var(--muted);
    }
    .plans-common li b { color: var(--text); font-weight: 650; }
    .plans-common li svg { width: .9rem; height: .9rem; fill: none; stroke: var(--ok); stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; }

    /* ── Section headings ── */
    .section-title {
      text-align: center;
      margin: 0 0 .55rem;
      font-size: clamp(1.5rem, 3vw, 2.1rem);
      letter-spacing: -.02em;
    }
    html[dir="rtl"] .section-title { letter-spacing: 0; }
    .section-subtitle {
      text-align: center;
      color: var(--muted);
      margin: 0 0 2rem;
      font-size: 1rem;
    }

    /* ── Comparison table ── */
    .compare { padding: 4.5rem 0 1rem; }
    .compare-scroll {
      overflow-x: auto;
      border: 1px solid var(--line);
      border-radius: 1rem;
      background: var(--surface);
    }
    .compare-scroll:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
    .compare-table {
      width: 100%;
      min-width: 760px;
      border-collapse: collapse;
      font-size: .9rem;
    }
    .compare-table th,
    .compare-table td {
      padding: .85rem 1rem;
      text-align: center;
      border-bottom: 1px solid var(--line);
      vertical-align: middle;
    }
    .compare-table tbody tr:last-child th,
    .compare-table tbody tr:last-child td { border-bottom: 0; }
    .compare-table thead th {
      position: sticky;
      top: 0;
      background: var(--surface2);
      font-size: .9rem;
      font-weight: 700;
    }
    .compare-table thead th.is-popular { color: var(--accent-text); }
    .compare-table th[scope="row"],
    .compare-table thead th:first-child {
      text-align: start;
      font-weight: 500;
      color: var(--muted);
      position: sticky;
      inset-inline-start: 0;
      background: var(--surface);
      z-index: 1;
      min-width: 200px;
    }
    .compare-table thead th:first-child { background: var(--surface2); z-index: 2; color: var(--text); font-weight: 700; }
    .compare-table td { font-weight: 600; color: var(--text); }
    .compare-table td.is-popular { background: color-mix(in oklab, var(--a) 5%, transparent); }
    .compare-tick { width: 1.1rem; height: 1.1rem; fill: none; stroke: var(--ok); stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; vertical-align: middle; }
    .compare-no { color: color-mix(in oklab, var(--muted) 70%, transparent); font-weight: 500; }

    /* ── Included in every plan ── */
    .features-section { padding: 4.5rem 0 1rem; }
    .features-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1rem;
    }
    .feature-card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 1rem;
      padding: 1.5rem;
    }
    .feature-icon {
      width: 2.6rem;
      height: 2.6rem;
      padding: .6rem;
      margin-bottom: 1rem;
      border-radius: .8rem;
      color: var(--accent-text);
      background: color-mix(in oklab, var(--a) 10%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--a) 22%, var(--line));
    }
    .feature-card h3 { font-size: 1.05rem; margin: 0 0 .5rem; }
    .feature-card p { color: var(--muted); font-size: .93rem; margin: 0; line-height: 1.6; }

    /* ── FAQ ── */
    .faq-section { padding: 4.5rem 0 1rem; }
    .faq-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }
    .faq-item {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 1rem;
      padding: 1.4rem 1.5rem;
    }
    .faq-item h3 { font-size: 1rem; margin: 0 0 .55rem; font-weight: 700; }
    .faq-item p { color: var(--muted); font-size: .93rem; margin: 0; line-height: 1.6; }

    /* ── Closing call to action ── */
    .cta-section {
      text-align: center;
      padding: clamp(2.25rem, 5vw, 3.25rem) clamp(1.25rem, 4vw, 3rem);
      background:
        radial-gradient(120% 140% at 50% 0%, color-mix(in oklab, var(--a) 14%, transparent), transparent 60%),
        var(--surface);
      border: 1px solid color-mix(in oklab, var(--a) 22%, var(--line));
      border-radius: 1.25rem;
      margin: 4.5rem 0 3.5rem;
    }
    .cta-section h2 { margin: 0 0 .6rem; font-size: clamp(1.5rem, 3vw, 2.1rem); text-wrap: balance; }
    .cta-section p { color: var(--muted); margin: 0 0 1.5rem; font-size: 1rem; }
    .cta-actions { display: flex; justify-content: center; flex-wrap: wrap; gap: .75rem; }

    .trial-pill {
      display: inline-flex;
      align-items: center;
      gap: .6rem;
      margin: 0 auto 1.1rem;
      padding: .35rem 1.05rem .35rem .35rem;
      border-radius: 999px;
      border: 1px solid color-mix(in oklab, var(--a) 30%, var(--line));
      background: color-mix(in oklab, var(--a) 8%, var(--surface));
      font-size: .92rem;
      line-height: 1.3;
    }
    [dir="rtl"] .trial-pill { padding: .35rem .35rem .35rem 1.05rem; }
    .trial-pill-icon {
      display: grid;
      place-items: center;
      flex: none;
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: var(--brand-accent);
      color: #fff;
    }
    .trial-pill-icon svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .trial-pill-text { color: var(--muted); font-weight: 500; }
    .trial-pill-text strong { color: var(--accent-text); font-weight: 800; }

    .reveal { opacity: 0; transform: translateY(14px); transition: opacity .5s ease, transform .5s ease; }
    .reveal.show { opacity: 1; transform: translateY(0); }
    @media (prefers-reduced-motion: reduce) {
      .reveal { transition: none; transform: none; }
    }

    /* ── Smaller screens ── */
    @media (max-width: 1199px) {
      .plans-grid { --plans: 3 !important; }
    }
    @media (max-width: 860px) {
      .plans-grid { --plans: 2 !important; }
      .features-grid { grid-template-columns: 1fr; }
      .faq-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 600px) {
      .pricing-page { width: min(100% - 2rem, 1320px); }
      .page-hero { padding: 2.25rem 0 1.5rem; }
      .plans-grid { --plans: 1 !important; }
      .plan-card { padding: 1.35rem 1.15rem 1.2rem; }
      .plan-desc { min-height: 0; }
      /* A full-width card has room for each label and its value on one line. */
      .plan-features li { flex-direction: row; justify-content: space-between; align-items: baseline; gap: .75rem; }
      .feature-value { padding-inline-start: 0; text-align: end; }
      .billing-toggle button { padding: 0 .9rem; }
      .compare, .features-section, .faq-section { padding-top: 3rem; }
      .compare-table th[scope="row"], .compare-table thead th:first-child { min-width: 150px; }
      .cta-section { margin-top: 3rem; }
    }
  </style>
</head>
<body>
  @include('components.header')

  <main class="pricing-page">
    @php
      // Names and descriptions now come localized from the backend
      $planDisplayName = static function (array $plan): string {
        return (string) ($plan['name'] ?? $plan['slug'] ?? '-');
      };
      $planDescription = static function (array $plan): string {
        return (string) ($plan['description'] ?? '');
      };
      $planSlugs = collect($pricingPlans)->pluck('slug')->values();
      $currentPlanIndex = $planSlugs->search($activePlanSlug);
      if ($currentPlanIndex === false) $currentPlanIndex = 0;

      // Every line on the cards, with each subscription plan's value, for the comparison table.
      $includedMark = __('ui.pricing.included_mark');
      $comparePlans = array_values(array_filter($pricingPlans, fn ($p) => empty($p['is_pack'])));
      $compareRows = [];
      foreach ($comparePlans as $comparePlan) {
        foreach ($comparePlan['features'] as $line) {
          $label = (string) ($line['label'] ?? '');
          if ($label !== '') {
            $compareRows[$label][$comparePlan['slug']] = (string) ($line['value'] ?? $includedMark);
          }
        }
      }
      // A line every plan has with the same value is said once, under the cards.
      $commonLines = [];
      if (count($comparePlans) > 1) {
        foreach ($compareRows as $label => $values) {
          if (count($values) === count($comparePlans) && count(array_unique($values)) === 1) {
            $commonLines[$label] = reset($values);
          }
        }
      }
      // The cards show the minutes on their own, above the lines.
      $pricingHiddenLabels = array_merge(array_keys($commonLines), [__('ui.pricing.lines.label_voice_minutes')]);
      $tick = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
    @endphp
    <section class="page-hero reveal">
      @if(!empty($trialPromo['show']))
        <div class="trial-pill">
          <span class="trial-pill-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>
          </span>
          <span class="trial-pill-text">
            <strong>{{ __('ui.pricing.trial_pill_highlight', ['days' => (int) ($trialPromo['days'] ?? 7)]) }}</strong>
            {{ __(!empty($trialPromo['all_plans']) ? 'ui.pricing.trial_pill_text' : 'ui.pricing.trial_pill_text_selected') }}
          </span>
        </div>
      @endif
      <h1>{{ __('ui.pricing.hero_title') }}</h1>
      <p class="lead">{{ __('ui.pricing.hero_subtitle') }}</p>
      <div class="billing-wrapper">
        <div class="billing-toggle" id="billingToggle" role="group" aria-label="{{ __('ui.pricing.monthly') }} / {{ __('ui.pricing.yearly') }}">
          <span class="billing-indicator" aria-hidden="true"></span>
          <button type="button" id="monthlyBtn" class="active" aria-pressed="true">{{ __('ui.pricing.monthly') }}</button>
          <button type="button" id="yearlyBtn" aria-pressed="false">
            {{ __('ui.pricing.yearly') }}
            @if(($yearlyDiscountPercent ?? 0) > 0)
              <span class="toggle-save">{{ __('ui.pricing.yearly_save', ['percent' => (int) $yearlyDiscountPercent]) }}</span>
            @endif
          </button>
        </div>
      </div>
    </section>

    <section class="plans-grid" style="--plans: {{ min(5, max(1, count($pricingPlans))) }}">
      @foreach($pricingPlans as $index => $plan)
        @include('components._plan-card', ['plan' => $plan, 'index' => $index])
      @endforeach
    </section>

    <div class="plans-trust reveal">
      <span>{!! $tick !!}{{ __('ui.pricing.badge_no_card') }}</span>
      <span>{!! $tick !!}{{ __('ui.pricing.badge_cancel_anytime') }}</span>
      <span>{!! $tick !!}{{ __('ui.pricing.badge_secure_billing') }}</span>
    </div>

    @if($commonLines !== [])
      <section class="plans-common reveal" aria-labelledby="plansCommonTitle">
        <h2 class="plans-common-title" id="plansCommonTitle">{{ __('ui.pricing.every_plan_includes') }}</h2>
        <ul>
          @foreach($commonLines as $label => $value)
            <li>{!! $tick !!}<span>{{ $label }}</span>@if($value !== '' && $value !== $includedMark)<b>{{ $value }}</b>@endif</li>
          @endforeach
        </ul>
      </section>
    @endif

    @if(count($comparePlans) > 1 && $compareRows !== [])
      <section class="compare" id="compare" aria-labelledby="compareTitle">
        <h2 class="section-title reveal" id="compareTitle">{{ __('ui.pricing.compare_title') }}</h2>
        <p class="section-subtitle reveal">{{ __('ui.pricing.compare_subtitle') }}</p>
        <div class="compare-scroll reveal" role="region" aria-labelledby="compareTitle" tabindex="0">
          <table class="compare-table">
            <thead>
              <tr>
                <th scope="col">{{ __('ui.pricing.compare_feature') }}</th>
                @foreach($comparePlans as $comparePlan)
                  <th scope="col" class="{{ !empty($comparePlan['is_popular']) ? 'is-popular' : '' }}">{{ $planDisplayName($comparePlan) }}</th>
                @endforeach
              </tr>
            </thead>
            <tbody>
              @foreach($compareRows as $label => $values)
                <tr>
                  <th scope="row">{{ $label }}</th>
                  @foreach($comparePlans as $comparePlan)
                    @php $cell = $values[$comparePlan['slug']] ?? null; @endphp
                    <td class="{{ !empty($comparePlan['is_popular']) ? 'is-popular' : '' }}">
                      @if($cell === null)
                        <span class="compare-no" aria-label="{{ __('ui.pricing.compare_not_included') }}">—</span>
                      @elseif($cell === '' || $cell === $includedMark)
                        <svg class="compare-tick" viewBox="0 0 24 24" role="img" aria-label="{{ $includedMark }}"><path d="M20 6 9 17l-5-5"/></svg>
                      @else
                        {{ $cell }}
                      @endif
                    </td>
                  @endforeach
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </section>
    @endif

    <section class="features-section">
      <h2 class="section-title reveal">{{ __('ui.pricing.included_title') }}</h2>
      <p class="section-subtitle reveal">{{ __('ui.pricing.included_subtitle') }}</p>
      <div class="features-grid">
        <article class="feature-card reveal">
          <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3M8 22h8"/>
          </svg>
          <h3>{{ __('ui.pricing.feature1_title') }}</h3>
          <p>{{ __('ui.pricing.feature1_body') }}</p>
        </article>
        <article class="feature-card reveal">
          <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 5h8M8 3v2M10.5 5c-.8 3.6-3 6.4-6.5 8M6.5 9c1.2 2 3 3.4 5.5 4.2"/><path d="M12.5 21l4-9 4 9M14 18h5"/>
          </svg>
          <h3>{{ __('ui.pricing.feature2_title') }}</h3>
          <p>{{ __('ui.pricing.feature2_body') }}</p>
        </article>
        <article class="feature-card reveal">
          <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M10 3.5a2 2 0 0 1 4 0V5h3.5A1.5 1.5 0 0 1 19 6.5V10h-1.5a2 2 0 0 0 0 4H19v3.5a1.5 1.5 0 0 1-1.5 1.5H14v-1.5a2 2 0 0 0-4 0V19H6.5A1.5 1.5 0 0 1 5 17.5V14h1.5a2 2 0 0 0 0-4H5V6.5A1.5 1.5 0 0 1 6.5 5H10z"/>
          </svg>
          <h3>{{ __('ui.pricing.feature3_title') }}</h3>
          <p>{{ __('ui.pricing.feature3_body') }}</p>
        </article>
      </div>
    </section>

    <section class="faq-section">
      <h2 class="section-title reveal">{{ __('ui.pricing.faq_title') }}</h2>
      <div class="faq-grid">
        @foreach([1, 2, 3, 4] as $faq)
          <article class="faq-item reveal">
            <h3>{{ __('ui.pricing.faq'.$faq.'_q') }}</h3>
            <p>{{ __('ui.pricing.faq'.$faq.'_a') }}</p>
          </article>
        @endforeach
      </div>
    </section>

    <section class="cta-section reveal">
      <h2>{{ __('ui.pricing.cta_title') }}</h2>
      <p>{{ __('ui.pricing.cta_subtitle') }}</p>
      <div class="cta-actions">
        @auth
          <a class="btn btn-primary" href="{{ route('dashboard') }}">{{ __('ui.pricing.go_to_dashboard') }}</a>
        @else
          <a class="btn btn-primary" href="{{ route('demo.page') }}">{{ __('ui.landing.try_demo') }}</a>
          <a class="btn" href="{{ route('register') }}">{{ __('ui.pricing.create_free_account') }}</a>
        @endauth
      </div>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @php
    $pricingI18n = [
      'free_forever' => __('ui.pricing.free_forever'),
      'one_time_payment' => __('ui.pack.one_time_payment'),
      'billed_yearly_save' => __('ui.pricing.billed_yearly_save'),
      'billed_monthly' => __('ui.pricing.billed_monthly'),
      'year' => __('ui.pricing.year'),
      'billed_yearly_total' => __('ui.pricing.billed_yearly_total'),
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

          // How long the discount lasts and the regular price after it, for the cycle shown.
          var terms = card.querySelector('.discount-terms');
          if (terms) {
            var cycle = isYearly ? 'yearly' : 'monthly';
            var offer = terms.querySelector('.discount-terms-offer');
            var then = terms.querySelector('.discount-terms-then');
            var thenText = String(terms.getAttribute('data-then-' + cycle) || '');
            if (offer) offer.textContent = String(terms.getAttribute('data-offer-' + cycle) || '');
            if (then) {
              then.textContent = thenText;
              then.hidden = thenText === '';
            }
          }

          if (card.getAttribute('data-is-pack') === '1') {
            // A pack is paid once: the monthly/yearly switch does not change it.
            priceValue.textContent = displayMonthly;
            priceNote.textContent = i18n.one_time_payment;
            priceNote.className = 'price-note price-note-text';
          } else if (isFree) {
            priceValue.textContent = displayMonthly;
            priceNote.textContent = i18n.free_forever;
            priceNote.className = 'price-note price-note-text';
          } else if (isYearly) {
            priceValue.textContent = displayYearlyMonthly;
            priceNote.textContent = i18n.billed_yearly_total.replace(':total', displayYearlyTotal);
            priceNote.className = 'price-note price-note-text';
          } else {
            // The switch above already says "monthly".
            priceValue.textContent = displayMonthly;
            priceNote.textContent = '';
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

          var nextLabel = isYearly
            ? link.getAttribute('data-yearly-label')
            : link.getAttribute('data-monthly-label');
          if (nextLabel) {
            link.textContent = nextLabel;
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

      function setBillingMode(yearly) {
        isYearly = yearly;
        monthlyBtn.classList.toggle('active', !yearly);
        yearlyBtn.classList.toggle('active', yearly);
        monthlyBtn.setAttribute('aria-pressed', yearly ? 'false' : 'true');
        yearlyBtn.setAttribute('aria-pressed', yearly ? 'true' : 'false');
        billingToggle.classList.toggle('yearly', yearly);
        document.querySelectorAll('.plans-grid').forEach(function(el) {
          el.classList.toggle('is-yearly', yearly);
        });
        updatePrices();
        updateUpgradeLinks();
        updateDiscountBadges();
      }

      monthlyBtn.addEventListener('click', function() { setBillingMode(false); });
      yearlyBtn.addEventListener('click', function() { setBillingMode(true); });

      updatePrices();
      updateUpgradeLinks();

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
          }
          if (key === 'd') {
            unit.parentElement.hidden = d === 0;
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
