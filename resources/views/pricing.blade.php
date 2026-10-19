@php
  $faqNumbers = [1, 2, 5, 6, 3, 4];
@endphp
@include('components.page-head', ['pageTitle' => __('ui.seo.pricing_title'), 'page' => 'pricing', 'faqItems' => array_map(fn ($n) => ['question' => __('ui.pricing.faq'.$n.'_q'), 'answer' => __('ui.pricing.faq'.$n.'_a')], $faqNumbers)])
  <style>
    /* ── Pricing page ───────────────────────────────────────────────
       Plans in one aligned row: every card's name, price, button, minutes and
       lines start at the same height (CSS subgrid), so plans compare at a glance. */
    .pricing-page {
      width: min(1440px, 94%);
      margin: 0 auto;
    }

    /* The title on the page's own background: no extra light or texture behind it. */
    .page-hero {
      text-align: center;
      padding: 4.75rem 1rem 3rem;
    }
    /* One calm line on wide screens, the size every inner page's title uses. */
    .page-hero h1 {
      margin: 0 auto .9rem;
      max-width: 26ch;
      font-size: var(--page-title-size);
      font-weight: 600;
      line-height: 1.12;
      letter-spacing: -.03em;
      text-wrap: balance;
    }
    html[dir="rtl"] .page-hero h1 {
      letter-spacing: 0;
      line-height: 1.35;
    }
    .page-hero .lead {
      color: var(--muted);
      max-width: 58ch;
      margin: 0 auto 2rem;
      font-size: 1.08rem;
      line-height: 1.65;
      text-wrap: pretty;
    }

    /* Monthly / yearly switch: a quiet segmented control like the language switch,
       the chosen half on a raised chip, the saving on the yearly one. */
    .billing-wrapper {
      display: flex;
      justify-content: center;
    }
    .billing-toggle {
      display: inline-grid;
      grid-template-columns: 1fr 1fr;
      background: color-mix(in oklab, var(--text) 5%, transparent);
      border-radius: var(--radius-full);
      padding: 4px;
      position: relative;
      isolation: isolate;
    }
    .billing-indicator {
      position: absolute;
      top: 4px;
      bottom: 4px;
      left: 4px;
      width: calc(50% - 4px);
      border-radius: var(--radius-full);
      background: var(--surface);
      box-shadow: 0 1px 2px rgba(15, 23, 42, .14), 0 0 0 1px rgba(15, 23, 42, .04);
      transition: transform .25s cubic-bezier(.4, 0, .2, 1);
      z-index: 0;
    }
    html:not([data-theme="light"]) .billing-indicator { background: #2E2E3A; box-shadow: none; }
    .billing-toggle.yearly .billing-indicator { transform: translateX(100%); }
    html[dir="rtl"] .billing-indicator { left: auto; right: 4px; }
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
    .billing-toggle button.active { color: var(--text); }
    .billing-toggle button:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
    .toggle-save {
      padding: .12rem .45rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--ok) 16%, transparent);
      color: color-mix(in oklab, var(--ok) 50%, var(--text));
      font-size: .74rem;
      font-weight: 700;
    }

    /* ── Plan cards ──
       Monthly plans in one aligned row (CSS subgrid: every card's badge, name, price,
       button, minutes and lines start at the same height). Never narrower than they
       read well: four plans fill the row, five or six go three to a row, and smaller
       screens take two, then one. */
    .plans-grid {
      --cols: 4;
      display: grid;
      grid-template-columns: repeat(var(--cols), minmax(0, 1fr));
      column-gap: 1.25rem;
      row-gap: 0;
      align-items: stretch;
      margin-inline: auto;
    }
    .plans-grid[data-count="1"] { --cols: 1; max-width: 440px; }
    .plans-grid[data-count="2"] { --cols: 2; max-width: 900px; }
    .plans-grid[data-count="3"] { --cols: 3; max-width: 1200px; }
    .plans-grid[data-count="5"],
    .plans-grid[data-count="6"] { --cols: 6; max-width: 1200px; }
    .plans-grid[data-count="5"] > .plan-card,
    .plans-grid[data-count="6"] > .plan-card { grid-column: span 2; }
    /* Five plans: the last two sit centred under the first three. */
    .plans-grid[data-count="5"] > .plan-card:nth-child(4) { grid-column: 2 / span 2; }
    .plan-card {
      position: relative;
      display: grid;
      grid-row: span 5;
      grid-template-rows: subgrid;
      row-gap: 0;
      margin-bottom: 1.25rem;
      padding: 1.75rem 1.6rem 1.6rem;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--card-radius);
      box-shadow: var(--card-shadow);
      transition: border-color .2s ease;
    }
    .plan-card:hover {
      border-color: color-mix(in oklab, var(--text) 18%, var(--line));
    }
    /* The recommended plan: the same white card, held in a violet ring, with its orb alive. */
    .plan-card.popular {
      border-color: transparent;
      box-shadow: 0 0 0 2px var(--brand-accent), 0 30px 60px -36px rgba(76, 45, 235, .5);
    }
    .plan-card.popular:hover { border-color: transparent; box-shadow: 0 0 0 2px var(--brand-accent), 0 34px 64px -34px rgba(76, 45, 235, .55); }
    .plan-top { display: flex; flex-direction: column; align-items: flex-start; }
    /* Every card opens with its orb, so the plan names line up. */
    .plan-badges { display: flex; align-items: center; justify-content: space-between; align-self: stretch; gap: .75rem; margin-bottom: 1rem; }
    .plan-orb { --orb-size: 48px; box-shadow: 0 10px 20px -12px color-mix(in srgb, var(--o0) 70%, transparent); }
    .popular-badge {
      display: inline-flex;
      align-items: center;
      padding: .3rem .75rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--brand-accent) 12%, transparent);
      color: var(--accent-text);
      font-size: .78rem;
      font-weight: 700;
    }
    .plan-name {
      margin: 0;
      font-size: 1.35rem;
      font-weight: 600;
      letter-spacing: -.02em;
    }
    html[dir="rtl"] .plan-name { letter-spacing: 0; }
    .plan-desc {
      margin: .4rem 0 0;
      color: var(--muted);
      font-size: .9rem;
      line-height: 1.55;
      min-height: 3.1em;
    }

    .plan-pricing {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: .3rem;
      padding: 1.35rem 0 1.4rem;
    }
    .price-original-row { display: inline-flex; align-items: center; gap: .5rem; }
    .price-original {
      color: var(--muted);
      font-size: .95rem;
      text-decoration: line-through;
      text-decoration-thickness: 1.5px;
    }
    .price-off {
      padding: .12rem .5rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--ok) 15%, transparent);
      color: color-mix(in oklab, var(--ok) 50%, var(--text));
      font-size: .76rem;
      font-weight: 700;
    }
    .price-row { display: flex; align-items: baseline; gap: .35rem; }
    .price-amount {
      font-size: clamp(2.5rem, 3.2vw, 3.1rem);
      font-weight: 600;
      line-height: 1;
      letter-spacing: -.045em;
      font-variant-numeric: tabular-nums;
    }
    .price-period { color: var(--muted); font-size: .95rem; font-weight: 500; }
    .price-note { color: var(--muted); font-size: .84rem; min-height: 0; }
    .price-note:empty { display: none; }
    .price-note.savings { color: color-mix(in oklab, var(--ok) 50%, var(--text)); font-weight: 600; }

    /* The offer in one box: what it is, the price after it, and when it ends. */
    .discount-terms {
      display: flex;
      flex-direction: column;
      gap: .15rem;
      align-self: stretch;
      margin-top: .55rem;
      padding: .75rem .9rem .8rem;
      border-radius: .75rem;
      background: var(--surface2);
    }
    .discount-terms-offer { color: var(--accent-text); font-size: .88rem; font-weight: 700; line-height: 1.45; }
    .discount-terms-then { color: var(--muted); font-size: .82rem; line-height: 1.45; }
    .discount-terms-then[hidden] { display: none; }
    .offer-ends {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      margin-top: .45rem;
      padding-top: .5rem;
      border-top: 1px dashed var(--line-strong);
      color: var(--text);
      font-size: .82rem;
      font-weight: 600;
    }
    .offer-ends[hidden] { display: none; }
    .offer-ends svg { flex: none; width: 15px; height: 15px; fill: none; stroke: var(--accent-text); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    .plan-trial-chip {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      margin-top: .35rem;
      padding: .24rem .65rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--ok) 14%, transparent);
      color: color-mix(in oklab, var(--ok) 50%, var(--text));
      font-size: .78rem;
      font-weight: 700;
    }
    .plan-trial-chip svg { width: .85rem; height: .85rem; fill: none; stroke: currentColor; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
    .plans-grid.is-yearly [data-trial-monthly-only],
    .plans-grid:not(.is-yearly) [data-trial-yearly-only] {
      display: none !important;
    }

    .plan-action { padding-bottom: 1.4rem; }
    .plan-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      min-height: 48px;
      padding: .6rem 1rem;
      border-radius: var(--btn-radius);
      font-size: .95rem;
      font-weight: 600;
      text-align: center;
      text-decoration: none;
      transition: background-color .2s ease, border-color .2s ease, color .2s ease;
    }
    .plan-btn.btn-primary { background: var(--brand-accent); color: #fff; border: 1px solid transparent; box-shadow: var(--lift); }
    .plan-btn.btn-primary:hover { background: var(--brand-hover); }
    .plan-btn.btn-outline {
      background: transparent;
      color: var(--text);
      border: 1px solid var(--line-strong);
    }
    .plan-btn.btn-outline:hover { border-color: color-mix(in oklab, var(--text) 45%, transparent); background: color-mix(in oklab, var(--text) 4%, transparent); }
    .plan-btn:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
    .plan-btn.plan-btn-sm { min-height: 38px; padding: .4rem .75rem; font-size: .84rem; }
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
      gap: .4rem;
      padding: 1.2rem 0;
      border-top: 1px solid var(--line);
      font-size: .9rem;
      font-weight: 600;
    }
    .plan-minutes { display: flex; align-items: baseline; flex-wrap: wrap; gap: .1rem .45rem; }
    .plan-minutes-number {
      font-size: 1.6rem;
      font-weight: 600;
      line-height: 1.1;
      letter-spacing: -.02em;
      font-variant-numeric: tabular-nums;
    }
    .plan-minutes-unit { color: var(--muted); font-size: .86rem; font-weight: 500; }
    .plan-quota-credits {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      color: var(--muted);
      font-size: .82rem;
      font-weight: 500;
      cursor: help;
    }
    .plan-quota-coin { font-size: 1rem; flex: none; }
    .plan-quota-amount { display: inline-flex; flex-wrap: wrap; align-items: baseline; gap: .25rem; }
    .plan-quota-number { color: var(--text); font-weight: 700; font-variant-numeric: tabular-nums; }

    /* The plan's lines: the label on one side, its value on the other. */
    .plan-features {
      list-style: none;
      margin: 0;
      padding: 1.1rem 0 0;
      border-top: 1px solid var(--line);
      display: flex;
      flex-direction: column;
      gap: .7rem;
    }
    /* A long label wraps on its own side; the value stays on one line, on the other. */
    .plan-features li {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: .75rem;
      font-size: .88rem;
    }
    .feature-label {
      display: inline-flex;
      align-items: flex-start;
      flex: 1 1 auto;
      min-width: 0;
      gap: .5rem;
      color: var(--muted);
      line-height: 1.4;
    }
    .feature-label svg {
      flex: none;
      width: 1rem;
      height: 1rem;
      margin-top: .12rem;
      fill: none;
      stroke: currentColor;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .feature-value {
      flex: none;
      max-width: 55%;
      color: var(--text);
      font-weight: 650;
      line-height: 1.4;
      text-align: end;
    }
    .plan-features li.is-tick .feature-label { color: var(--text); font-weight: 600; }
    .plan-features li.is-tick .feature-label svg { color: var(--ok); stroke-width: 2.6; }


    /* Under the cards, as wide as their row: more minutes or a whole team, by talking to us. */
    .team-band {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr) auto;
      align-items: center;
      gap: 1.25rem 1.5rem;
      margin: .25rem auto 0;
      padding: 1.35rem 1.6rem;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--card-radius);
      box-shadow: var(--card-shadow);
    }
    .team-band[data-count="1"] { max-width: 440px; }
    .team-band[data-count="2"] { max-width: 900px; }
    .team-band[data-count="3"],
    .team-band[data-count="5"],
    .team-band[data-count="6"] { max-width: 1200px; }
    .team-orb { --orb-size: 52px; }
    .team-band h2 { margin: 0 0 .2rem; font-size: 1.15rem; font-weight: 600; letter-spacing: -.01em; }
    html[dir="rtl"] .team-band h2 { letter-spacing: 0; }
    .team-band p { margin: 0; color: var(--muted); font-size: .93rem; line-height: 1.55; }
    .team-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 48px;
      padding: 0 1.4rem;
      border-radius: var(--btn-radius);
      border: 1px solid var(--line-strong);
      background: var(--surface);
      color: var(--text);
      font-size: .95rem;
      font-weight: 600;
      text-decoration: none;
      white-space: nowrap;
      transition: border-color .2s ease, background-color .2s ease;
    }
    .team-btn:hover { border-color: color-mix(in oklab, var(--text) 45%, transparent); background: color-mix(in oklab, var(--text) 4%, transparent); }
    .team-btn:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }


    /* ── Section headings ── */
    .section-title {
      text-align: center;
      margin: 0 0 .55rem;
      font-size: clamp(1.7rem, 3.2vw, 2.4rem);
      font-weight: 600;
      letter-spacing: -.03em;
    }
    html[dir="rtl"] .section-title { letter-spacing: 0; }
    .section-subtitle {
      text-align: center;
      color: var(--muted);
      margin: 0 0 2rem;
      font-size: 1rem;
    }

    /* ── Comparison table: each plan's name, price and button on top ── */
    .compare { padding: 6.5rem 0 1rem; }
    .compare-scroll {
      overflow-x: auto;
      border: 1px solid var(--line);
      border-radius: var(--card-radius);
      background: var(--surface);
      box-shadow: var(--card-shadow);
    }
    .compare-scroll:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
    .compare-table {
      width: 100%;
      min-width: 820px;
      border-collapse: separate;
      border-spacing: 0;
      font-size: .9rem;
    }
    /* Each row's divider is drawn on its own top edge: the pinned first column is painted
       row by row, and a bottom edge could be covered by the row below at fractional sizes. */
    .compare-table th,
    .compare-table td {
      padding: .85rem 1rem;
      text-align: center;
      border-top: 1px solid var(--line);
      vertical-align: middle;
    }
    .compare-table thead th { border-top: 0; border-bottom: 1px solid var(--line); }
    .compare-table tbody tr:first-child th,
    .compare-table tbody tr:first-child td { border-top: 0; }
    .compare-table thead th {
      position: sticky;
      top: 0;
      z-index: 2;
      padding: 1.15rem .9rem 1.1rem;
      background: var(--surface);
      vertical-align: bottom;
    }
    .compare-plan { min-width: 136px; }
    .compare-plan-badge {
      display: block;
      min-height: 1.3rem;
      color: var(--accent-text);
      font-size: .76rem;
      font-weight: 600;
    }
    .compare-plan-name { display: block; font-size: 1rem; font-weight: 600; color: var(--text); }
    .compare-plan-price { display: flex; justify-content: center; align-items: baseline; gap: .2rem; margin: .2rem 0 .8rem; }
    .compare-plan-price b { font-size: 1.6rem; font-weight: 600; letter-spacing: -.035em; font-variant-numeric: tabular-nums; color: var(--text); }
    .compare-plan-price small { color: var(--muted); font-size: .8rem; font-weight: 500; }
    .compare-table thead th.is-popular {
      background: color-mix(in oklab, var(--a) 5%, var(--surface));
      box-shadow: inset 0 2px 0 var(--brand-accent);
    }
    /* Rows in two groups: how much you get, then what the voices can do. */
    .compare-table tbody th.compare-group {
      position: static;
      padding: 1.6rem 1rem .6rem;
      background: transparent;
      color: var(--text);
      font-size: .95rem;
      font-weight: 600;
      text-align: start;
      border-top: 0;
    }
    .compare-table tbody:not(:first-of-type) th.compare-group { border-top: 1px solid var(--line); }
    .compare-group span { display: inline-block; position: sticky; inset-inline-start: 1rem; }
    .compare-table tbody tr.compare-group-row:hover th { background: transparent; }
    .compare-table tbody tr.compare-group-row + tr th,
    .compare-table tbody tr.compare-group-row + tr td { border-top: 0; }
    .compare-table th[scope="row"],
    .compare-table thead th:first-child {
      text-align: start;
      font-weight: 500;
      color: var(--muted);
      position: sticky;
      inset-inline-start: 0;
      background: var(--surface);
      z-index: 1;
      min-width: 210px;
    }
    .compare-table thead th:first-child { z-index: 3; color: var(--muted); font-size: .85rem; font-weight: 500; }
    .compare-row-label { display: inline-flex; align-items: center; gap: .6rem; }
    .compare-table td { font-weight: 500; color: var(--text); font-variant-numeric: tabular-nums; }
    .compare-table td.is-popular { background: color-mix(in oklab, var(--a) 4%, var(--surface)); }
    .compare-table tbody tr:not(.compare-group-row):hover th,
    .compare-table tbody tr:not(.compare-group-row):hover td { background: color-mix(in oklab, var(--text) 2.5%, var(--surface)); }
    .compare-table tbody tr:not(.compare-group-row):hover td.is-popular { background: color-mix(in oklab, var(--a) 7%, var(--surface)); }
    .compare-tick { width: 1.15rem; height: 1.15rem; fill: none; stroke: var(--ok); stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; vertical-align: middle; }
    .compare-no { color: color-mix(in oklab, var(--muted) 70%, transparent); font-weight: 500; }
    /* Wide screens: the plan names, prices and buttons stay in view while you read down. */
    @media (min-width: 1100px) {
      .compare-scroll { overflow: visible; }
      .compare-table thead th { top: 64px; }
      .compare-table thead th:first-child { border-start-start-radius: var(--card-radius); }
      .compare-table thead th:last-child { border-start-end-radius: var(--card-radius); }
      .compare-table tbody:last-of-type tr:last-child th { border-end-start-radius: var(--card-radius); }
      .compare-table tbody:last-of-type tr:last-child td:last-child { border-end-end-radius: var(--card-radius); }
    }

    /* ── Included in every plan ── */
    /* The same cards as the homepage's. */
    .features-section { padding: 6.5rem 0 1rem; }
    .features-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1rem;
    }
    .feature-card {
      padding: 1.6rem 1.5rem;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--card-radius);
      box-shadow: var(--card-shadow);
    }
    .feature-icon {
      width: 2.5rem;
      height: 2.5rem;
      padding: .6rem;
      margin-bottom: 1.1rem;
      border-radius: .75rem;
      color: var(--accent-text);
      background: color-mix(in oklab, var(--a) 10%, transparent);
    }
    .feature-card h3 { font-size: 1.08rem; font-weight: 600; margin: 0 0 .5rem; }
    .feature-card p { color: var(--muted); font-size: .94rem; margin: 0; line-height: 1.65; max-width: 40ch; }

    /* ── FAQ: the heading on one side, questions that open on the other ── */
    .faq-section {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1.7fr);
      gap: 3rem;
      align-items: start;
      padding: 6.5rem 0 1rem;
    }
    .faq-intro { position: sticky; top: 6rem; }
    .faq-intro .section-title { text-align: start; }
    .faq-intro p { margin: 0 0 1rem; color: var(--muted); font-size: 1rem; line-height: 1.6; }
    .faq-contact {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      min-height: 44px;
      color: var(--accent-text);
      font-weight: 700;
      text-decoration: none;
    }
    .faq-contact:hover { text-decoration: underline; }
    .faq-contact svg { width: 1rem; height: 1rem; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    html[dir="rtl"] .faq-contact svg { transform: scaleX(-1); }
    /* Questions on hairlines: no boxes, the answer opens under its question. */
    .faq-list { border-top: 1px solid var(--line); }
    .faq-item { border-bottom: 1px solid var(--line); }
    .faq-item summary {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1.5rem;
      min-height: 44px;
      padding: 1.35rem 0;
      font-size: 1.05rem;
      font-weight: 600;
      cursor: pointer;
      list-style: none;
    }
    .faq-item summary::-webkit-details-marker { display: none; }
    .faq-item summary:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; border-radius: .5rem; }
    .faq-toggle {
      flex: none;
      width: 2rem;
      height: 2rem;
      padding: .5rem;
      border-radius: 50%;
      border: 1px solid var(--line-strong);
      fill: none;
      stroke: var(--text);
      stroke-width: 2;
      stroke-linecap: round;
      transition: transform .2s ease, background-color .2s ease, border-color .2s ease;
    }
    .faq-item summary:hover .faq-toggle { border-color: color-mix(in oklab, var(--text) 35%, var(--line)); }
    .faq-item[open] .faq-toggle { transform: rotate(45deg); background: color-mix(in oklab, var(--text) 5%, transparent); }
    .faq-item p { margin: 0; padding: 0 0 1.5rem; padding-inline-end: 3.5rem; color: var(--muted); font-size: .97rem; line-height: 1.7; max-width: 68ch; }

    .trial-pill {
      display: inline-flex;
      align-items: center;
      gap: .6rem;
      margin: 0 auto 1.1rem;
      padding: .35rem 1.05rem .35rem .35rem;
      border-radius: 999px;
      border: 1px solid var(--line);
      background: var(--surface);
      box-shadow: var(--card-shadow);
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
    @media (max-width: 1240px) {
      .plans-grid[data-count="4"],
      .plans-grid[data-count="5"],
      .plans-grid[data-count="6"] { --cols: 2; max-width: 900px; }
      .plans-grid[data-count="5"] > .plan-card,
      .plans-grid[data-count="6"] > .plan-card,
      .plans-grid[data-count="5"] > .plan-card:nth-child(4) { grid-column: auto; }
    }
    @media (max-width: 1000px) {
      .plans-grid[data-count="3"] { --cols: 2; max-width: 900px; }
    }
    /* Two to a row with an odd plan left over: it sits centred under the pair. */
    @media (min-width: 641px) and (max-width: 1240px) {
      .plans-grid[data-count="5"] > .plan-card:last-child,
      .plans-grid[data-count="3"] > .plan-card:last-child { grid-column: 1 / -1; width: calc(50% - .625rem); justify-self: center; }
    }
    @media (min-width: 1001px) {
      .plans-grid[data-count="3"] > .plan-card:last-child { grid-column: auto; width: auto; }
    }
    @media (max-width: 1240px) {
      .team-band[data-count="4"],
      .team-band[data-count="5"],
      .team-band[data-count="6"] { max-width: 900px; }
    }
    @media (max-width: 1000px) {
      .team-band[data-count="3"] { max-width: 900px; }
    }
    @media (max-width: 860px) {
      .features-grid { grid-template-columns: 1fr; }
      .faq-section { grid-template-columns: 1fr; gap: 1.25rem; }
      .faq-intro { position: static; }
      .faq-intro .section-title, .faq-intro { text-align: center; }
    }
    @media (max-width: 600px) {
      .pricing-page { width: min(100% - 2rem, 1320px); }
      .page-hero { padding: 2.25rem 0 1.5rem; }
      .plans-grid[data-count] { --cols: 1; max-width: 520px; }
      .plans-grid[data-count] > .plan-card { grid-column: auto !important; width: auto !important; }
      .plan-card { padding: 1.35rem 1.15rem 1.2rem; }
      .plan-desc { min-height: 0; }
      .page-hero { padding-top: 2.75rem; }
      .billing-toggle button { padding: 0 .9rem; }
      .compare, .features-section, .faq-section { padding-top: 4rem; }
      .compare-table th[scope="row"], .compare-table thead th:first-child { min-width: 150px; }
      .team-band[data-count] { max-width: 520px; grid-template-columns: auto minmax(0, 1fr); padding: 1.25rem; }
      .team-btn { grid-column: 1 / -1; }
      .faq-item p { padding-inline-end: 0; }
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
      $trialPromo = $trialPromo ?? [];

      // Where a plan stands for this visitor: shared by its card and the comparison table.
      $planState = static function (array $plan, int $index) use ($activePlanSlug, $currentPlanIndex, $trialPromo): array {
        $isPack = !empty($plan['is_pack']);
        $isCurrent = $activePlanSlug === $plan['slug'];
        $isFree = max(0, (int) ($plan['price_monthly_cents'] ?? 0)) <= 0;
        $isLower = auth()->check() && $index < $currentPlanIndex && $plan['slug'] !== 'free' && !$isPack;
        $trialCycles = !empty($trialPromo['show']) && !$isFree && !$isCurrent && !$isLower
          ? ($trialPromo['plan_cycles'][$plan['slug']] ?? [])
          : [];

        return [
          'is_pack' => $isPack,
          'is_current' => $isCurrent,
          'is_free' => $isFree,
          'is_lower' => $isLower,
          'is_popular' => !empty($plan['is_popular']) && !$isCurrent,
          'trial_monthly' => in_array('monthly', $trialCycles, true),
          'trial_yearly' => in_array('yearly', $trialCycles, true),
        ];
      };
      // When an offer ends, in words: "Offer ends in 4 days", "… in 7 hours", "… in 12 minutes".
      $offerEndsText = static function (?string $endsAt): string {
        $seconds = $endsAt ? \Illuminate\Support\Carbon::parse($endsAt)->getTimestamp() - now()->getTimestamp() : 0;
        if ($seconds <= 0) return '';
        if ($seconds >= 2 * 86400) return trans_choice('ui.pricing.offer_ends_days', intdiv($seconds, 86400));
        if ($seconds >= 3600) return trans_choice('ui.pricing.offer_ends_hours', intdiv($seconds, 3600));
        return trans_choice('ui.pricing.offer_ends_minutes', max(1, (int) ceil($seconds / 60)));
      };
      // The cards are the monthly plans; the plan to try the voices and one-time packs are not shown here.
      $mainPlans = array_filter($pricingPlans, fn ($pricingPlan) => empty($pricingPlan['is_pack']) && (int) ($pricingPlan['price_monthly_cents'] ?? 0) > 0);
      if ($mainPlans === []) {
        $mainPlans = $pricingPlans;
      }
      // "$9.00" reads as "$9"; "$86.40" keeps its cents.
      $wholePrice = static fn (string $price): string => (string) preg_replace('/[.,]00(?=\D*$)/u', '', $price);
      // The icon drawn beside each line, on the cards and in the comparison table.
      $lineIcon = static fn (?string $icon): string => match ($icon ?? 'check') {
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
        'heart' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'sliders' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        default => '<path d="M20 6 9 17l-5-5"/>',
      };

      // The comparison table is written from the plans' own settings (Admin → Plans),
      // not from their card text, which each plan may word in its own way: every row
      // is one line with each plan's value, the admin's extra lines, and the credits
      // each card shows under its minutes.
      $includedMark = __('ui.pricing.included_mark');
      $minutesLabel = __('ui.pricing.lines.label_voice_minutes');
      $creditsLabel = __('ui.pricing.compare_credits');
      $comparePlans = array_values(array_filter($pricingPlans, fn ($p) => empty($p['is_pack'])));
      // One row per line even when a plan spells an extra line's label a little differently
      // ("التوليدات يومياً" / "التوليدات يوميا"): the row takes the site's own label when
      // there is one, else the first plan's.
      $compareLabels = [];
      foreach (array_merge(array_map(fn ($key) => __('ui.pricing.lines.label_'.$key), array_keys(\App\Support\PlanPricingLines::LINES)), [$creditsLabel]) as $siteLabel) {
        $compareLabels[\App\Support\PlanPricingLines::labelKey($siteLabel)] = $siteLabel;
      }
      $compareRows = [];
      foreach ($comparePlans as $comparePlan) {
        foreach ($comparePlan['compare_lines'] as $line) {
          $label = trim((string) ($line['label'] ?? ''));
          if ($label !== '') {
            $label = $compareLabels[\App\Support\PlanPricingLines::labelKey($label)] ??= $label;
            // A plan listing the same line twice keeps the first.
            $compareRows[$label][$comparePlan['slug']] ??= (string) ($line['value'] ?? $includedMark);
          }
          if ($label === $minutesLabel && empty($comparePlan['free_try_chars'])) {
            $compareRows[$creditsLabel][$comparePlan['slug']] = number_format((int) ($comparePlan['monthly_chars'] ?? 0));
          }
        }
        // Right under the minutes, or last when the minutes line is switched off.
        if (empty($comparePlan['free_try_chars'])) {
          $compareRows[$creditsLabel][$comparePlan['slug']] ??= number_format((int) ($comparePlan['monthly_chars'] ?? 0));
        }
      }
      // The table in two groups: how much each plan gives, then what its voices can do
      // (the admin's own extra lines go with the voices).
      $usageLabels = [$minutesLabel, $creditsLabel];
      foreach (['max_chars_per_request', 'monthly_requests', 'daily_requests', 'processing_priority', 'audio_kept', 'topup'] as $usageKey) {
        $usageLabels[] = __('ui.pricing.lines.label_'.$usageKey);
      }
      $compareGroups = array_filter([
        __('ui.pricing.compare_group_usage') => array_intersect_key($compareRows, array_flip($usageLabels)),
        __('ui.pricing.compare_group_features') => array_diff_key($compareRows, array_flip($usageLabels)),
      ]);
      // A line every monthly plan has with the same value is left off the cards, which show
      // what sets each plan apart; the comparison table below lists every line.
      $commonLines = [];
      $mainSlugs = array_column($mainPlans, 'slug');
      if (count($mainSlugs) > 1) {
        foreach ($mainPlans as $mainPlan) {
          foreach ($mainPlan['features'] as $line) {
            $label = (string) ($line['label'] ?? '');
            $mainValues[$label][$mainPlan['slug']] = (string) ($line['value'] ?? $includedMark);
          }
        }
        foreach ($mainValues ?? [] as $label => $values) {
          if ($label !== '' && count($values) === count($mainSlugs) && count(array_unique($values)) === 1) {
            $commonLines[$label] = reset($values);
          }
        }
      }
      // The cards show the minutes on their own, above the lines.
      $pricingHiddenLabels = array_merge(array_keys($commonLines), [__('ui.pricing.lines.label_voice_minutes')]);
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

    @php $planCount = min(6, max(1, count($mainPlans))); @endphp
    <section class="plans-grid" data-count="{{ $planCount }}">
      @foreach($mainPlans as $index => $plan)
        @include('components._plan-card', ['plan' => $plan, 'index' => $index])
      @endforeach
    </section>

    {{-- More than the biggest plan, or a whole team: by talking to us. --}}
    <section class="team-band reveal" data-count="{{ $planCount }}" aria-labelledby="teamTitle" data-orb-host>
      <x-orb name="teams" class="team-orb" />
      <div>
        <h2 id="teamTitle">{{ __('ui.pricing.team_title') }}</h2>
        <p>{{ __('ui.pricing.team_body') }}</p>
      </div>
      <a class="team-btn" href="{{ route('contact', ['topic' => 'billing']) }}">{{ __('ui.pricing.team_cta') }}</a>
    </section>

    @if(count($comparePlans) > 1 && $compareRows !== [])
      <section class="compare" id="compare" aria-labelledby="compareTitle">
        <h2 class="section-title reveal" id="compareTitle">{{ __('ui.pricing.compare_title') }}</h2>
        <p class="section-subtitle reveal">{{ __('ui.pricing.compare_subtitle') }}</p>
        <div class="compare-scroll reveal" role="region" aria-labelledby="compareTitle" tabindex="0">
          <table class="compare-table">
            <thead>
              <tr>
                <th scope="col" class="compare-corner">{{ __('ui.pricing.compare_feature') }}</th>
                @foreach($comparePlans as $compareIndex => $comparePlan)
                  @php
                    // Its place among all the plans, as on the cards (for "current plan" and "downgrade").
                    $compareAt = (int) array_search($comparePlan['slug'], array_column($pricingPlans, 'slug'), true);
                    $comparePopular = $planState($comparePlan, $compareAt)['is_popular'];
                    $compareMonthly = $wholePrice((string) ($comparePlan['display_price_monthly_formatted'] ?? ''));
                    $compareYearly = $wholePrice((string) ($comparePlan['display_price_yearly_monthly_formatted'] ?? $compareMonthly));
                  @endphp
                  <th scope="col" class="compare-plan {{ $comparePopular ? 'is-popular' : '' }}">
                    <span class="compare-plan-badge">{{ $comparePopular ? __('ui.pricing.most_popular') : '' }}</span>
                    <span class="compare-plan-name">{{ $planDisplayName($comparePlan) }}</span>
                    <span class="compare-plan-price" dir="ltr">
                      <b data-compare-price data-monthly="{{ $compareMonthly }}" data-yearly="{{ $compareYearly }}">{{ $compareMonthly }}</b><small>{{ __('ui.pricing.per_month_short') }}</small>
                    </span>
                    @include('components._plan-button', ['plan' => $comparePlan, 'index' => $compareAt, 'small' => true])
                  </th>
                @endforeach
              </tr>
            </thead>
            @foreach($compareGroups as $groupTitle => $groupRows)
              <tbody>
                <tr class="compare-group-row">
                  <th scope="rowgroup" class="compare-group" colspan="{{ count($comparePlans) + 1 }}"><span>{{ $groupTitle }}</span></th>
                </tr>
                @foreach($groupRows as $label => $values)
                  <tr>
                    <th scope="row"><span class="compare-row-label">{{ $label }}</span></th>
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
            @endforeach
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

    <section class="faq-section" aria-labelledby="faqTitle">
      <div class="faq-intro reveal">
        <h2 class="section-title" id="faqTitle">{{ __('ui.pricing.faq_title') }}</h2>
        <p>{{ __('ui.pricing.faq_subtitle') }}</p>
        <a class="faq-contact" href="{{ route('contact') }}">{{ __('ui.pricing.faq_contact') }}<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
      </div>
      <div class="faq-list reveal">
        @foreach($faqNumbers as $faq)
          <details class="faq-item" @if($loop->first) open @endif>
            <summary>
              <span>{{ __('ui.pricing.faq'.$faq.'_q') }}</span>
              <svg class="faq-toggle" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            </summary>
            <p>{{ __('ui.pricing.faq'.$faq.'_a') }}</p>
          </details>
        @endforeach
      </div>
    </section>

    @auth
      <x-closing-panel :title="__('ui.pricing.cta_title')" :body="__('ui.pricing.cta_subtitle')"
        :primary="['href' => route('dashboard'), 'label' => __('ui.pricing.go_to_dashboard')]" />
    @else
      <x-closing-panel :title="__('ui.pricing.cta_title')" :body="__('ui.pricing.cta_subtitle')"
        :primary="['href' => route('demo.page'), 'label' => __('ui.landing.try_demo')]"
        :secondary="['href' => route('register'), 'label' => __('ui.pricing.create_free_account')]" />
    @endauth
  </main>

  @include('components.footer')
  @include('components.scripts')
  @include('components.orb-script')
  @php
    $pricingI18n = [
      'free_forever' => __('ui.pricing.free_forever'),
      'one_time_payment' => __('ui.pack.one_time_payment'),
      'billed_yearly_save' => __('ui.pricing.billed_yearly_save'),
      'billed_monthly' => __('ui.pricing.billed_monthly'),
      'year' => __('ui.pricing.year'),
      'billed_yearly_total' => __('ui.pricing.billed_yearly_total'),
      'or_yearly' => __('ui.pricing.or_yearly'),
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

      // A price inside an Arabic sentence keeps its own order ("3.20 US$", never "$US 3.20").
      function ltr(text) { return '\u2066' + text + '\u2069'; }

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
            priceNote.textContent = i18n.billed_yearly_total.replace(':total', ltr(displayYearlyTotal));
            priceNote.className = 'price-note price-note-text';
          } else {
            // The switch above already says "monthly": point to the yearly price instead,
            // unless the card is busy with its own offer.
            priceValue.textContent = displayMonthly;
            priceNote.textContent = !terms && displayYearlyMonthly !== displayMonthly
              ? i18n.or_yearly.replace(':price', ltr(displayYearlyMonthly))
              : '';
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

      function updateComparePrices() {
        document.querySelectorAll('[data-compare-price]').forEach(function(el) {
          el.textContent = String(el.getAttribute(isYearly ? 'data-yearly' : 'data-monthly') || '');
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
        updateComparePrices();
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
  @php
    // The offer-ends wording in every plural form, for the minute-by-minute update.
    $offerEndsForms = [
      'days' => explode('|', __('ui.pricing.offer_ends_days')),
      'hours' => explode('|', __('ui.pricing.offer_ends_hours')),
      'minutes' => explode('|', __('ui.pricing.offer_ends_minutes')),
    ];
  @endphp
  <script>
    /* ── When each offer ends, in words, kept current ── */
    (function() {
      var lines = document.querySelectorAll('.offer-ends[data-ends]');
      if (!lines.length) return;
      var forms = @json($offerEndsForms);
      var arabic = document.documentElement.lang === 'ar';
      // The same plural rules the server uses (Laravel's): six forms in Arabic, two in English.
      function form(list, n) {
        var i;
        if (arabic) {
          var m = n % 100;
          i = n === 0 ? 0 : n === 1 ? 1 : n === 2 ? 2 : (m >= 3 && m <= 10) ? 3 : (m >= 11 && m <= 99) ? 4 : 5;
        } else {
          i = n === 1 ? 0 : 1;
        }
        return String(list[Math.min(i, list.length - 1)] || '').replace(':count', String(n));
      }
      function refresh() {
        var any = false;
        lines.forEach(function(el) {
          var seconds = Math.floor((new Date(el.getAttribute('data-ends')).getTime() - Date.now()) / 1000);
          if (!(seconds > 0)) { el.hidden = true; return; }
          any = true;
          var text = seconds >= 172800 ? form(forms.days, Math.floor(seconds / 86400))
            : seconds >= 3600 ? form(forms.hours, Math.floor(seconds / 3600))
            : form(forms.minutes, Math.max(1, Math.ceil(seconds / 60)));
          var out = el.querySelector('.offer-ends-text');
          if (out && out.textContent !== text) out.textContent = text;
        });
        if (any) setTimeout(refresh, 30000);
      }
      refresh();
    })();
  </script>
</body>
</html>
