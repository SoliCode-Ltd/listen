@php
  $faqNumbers = [1, 2, 5, 6, 3, 4];
@endphp
@include('components.page-head', ['pageTitle' => __('ui.seo.pricing_title'), 'page' => 'pricing', 'faqItems' => array_map(fn ($n) => ['question' => __('ui.pricing.faq'.$n.'_q'), 'answer' => __('ui.pricing.faq'.$n.'_a')], $faqNumbers)])
  <style>
    /* ── Pricing page ───────────────────────────────────────────────
       Every plan as a card, its parts lined up with its neighbours' (CSS subgrid),
       then a calculator: pick the minutes you need and see the plan that fits. */
    .pricing-page {
      width: min(1440px, 94%);
      margin: 0 auto;
    }

    /* The title on the page's own background, short, so the plans' prices and buttons
       come into view on the first screen. */
    .page-hero {
      text-align: center;
      padding: 2.4rem 1rem 1.8rem;
    }
    /* One line, one colour: the words carry it. */
    .page-hero h1 {
      margin: 0 auto .7rem;
      max-width: none;
      font-size: clamp(2rem, 3.3vw, 2.75rem);
      font-weight: 700;
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
      max-width: 64ch;
      margin: 0 auto;
      font-size: 1.05rem;
      line-height: 1.65;
      text-wrap: pretty;
    }

    /* Monthly / yearly switch: a quiet segmented control like the language switch,
       the chosen half on a raised chip, the saving on the yearly one. */
    .billing-toggle {
      display: inline-grid;
      /* Both halves as wide as the wider one (yearly, with its saving), so neither is cut. */
      grid-template-columns: repeat(2, minmax(max-content, 1fr));
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
    /* Once the page has measured the chosen half, the chip takes exactly its place and size. */
    .billing-toggle.is-measured .billing-indicator,
    html[dir="rtl"] .billing-toggle.is-measured .billing-indicator {
      left: 0;
      right: auto;
      width: var(--chip-w);
      transform: translateX(var(--chip-x));
      transition: transform .25s cubic-bezier(.4, 0, .2, 1), width .25s cubic-bezier(.4, 0, .2, 1);
    }
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

    /* ── The plans ──
       Every plan as a card: three in a row and the others centred under them. A card's
       parts (name, offer, price, button, minutes, lines) start at the same height as its
       neighbours' (CSS subgrid), so the plans compare at a glance. */
    .pricing-page { --ease-out: cubic-bezier(.22, 1, .36, 1); }
    .page-hero .billing-toggle { margin-top: 1.6rem; }
    .page-hero .billing-toggle button { min-width: 7.5rem; }
    .plans { padding-top: clamp(1.2rem, 2.5vw, 2rem); }
    .plans-title { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    /* Phones: one card under another. */
    .plan-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      column-gap: 20px;
      max-width: 520px;
      margin: 2.2rem auto 0;
    }
    .plan-card {
      position: relative;
      display: flex;
      flex-direction: column;
      min-width: 0;
      margin-bottom: 24px;
      padding: 1.9rem 1.5rem 1.6rem;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 24px;
      box-shadow: var(--card-shadow);
      scroll-margin-top: 92px;
      transition: border-color .3s ease, box-shadow .3s ease;
    }
    .plan-card:focus { outline: none; }
    /* Tablets: two in a row, the last one centred when it is on its own. Desktops: three in a
       row, and two plans centred under three. Each card spans six rows of the grid and shares
       them with its neighbours, so their parts line up. */
    @media (min-width: 641px) {
      .plan-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); max-width: 820px; }
      .plan-grid[data-count="1"] { grid-template-columns: repeat(2, minmax(0, 1fr)); max-width: 420px; }
      .plan-card { grid-column: span 2; grid-row: span 6; display: grid; grid-template-rows: subgrid; row-gap: 0; }
    }
    @media (min-width: 641px) and (max-width: 1000px) {
      .plan-grid:not([data-count="1"]) > .plan-card:last-child:nth-child(odd) { grid-column: 2 / span 2; }
    }
    @media (min-width: 1001px) {
      .plan-grid:not([data-count="1"]):not([data-count="2"]):not([data-count="4"]) { grid-template-columns: repeat(6, minmax(0, 1fr)); max-width: 1200px; }
      .plan-grid[data-count="5"] > .plan-card:nth-child(4) { grid-column: 2 / span 2; }
    }
    /* The recommended plan: a violet edge and its badge on the card's top edge. */
    .plan-card.popular {
      border-color: var(--brand-accent);
      box-shadow: 0 0 0 1px var(--brand-accent), 0 28px 56px -34px color-mix(in srgb, var(--brand-accent) 60%, transparent);
    }
    /* The plan the minutes below fit, once the visitor has used them: a soft ring around its card. */
    .plan-card.is-fit { border-color: var(--brand-accent); box-shadow: 0 0 0 1px var(--brand-accent), 0 0 0 6px color-mix(in srgb, var(--brand-accent) 30%, transparent); }
    .plan-top { display: flex; flex-direction: column; align-items: center; text-align: center; }
    .plan-badges { display: flex; justify-content: center; margin-bottom: .75rem; }
    .plan-orb { --orb-size: 60px; box-shadow: 0 16px 30px -18px color-mix(in srgb, var(--o0) 75%, transparent); }
    .popular-badge {
      position: absolute;
      top: 0;
      left: 50%;
      z-index: 1;
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: .34rem .9rem;
      border-radius: var(--radius-full);
      background: var(--brand-hover);
      color: #fff;
      font-size: .8rem;
      font-weight: 700;
      line-height: 1.3;
      white-space: nowrap;
      translate: -50% -50%;
      box-shadow: 0 6px 14px -6px rgba(76, 45, 235, .55);
    }
    .popular-badge .crown { width: 14px; height: 14px; }
    .crown { flex: none; width: 15px; height: 15px; fill: #FFD36B; stroke: #E8A41F; stroke-width: 1.2; stroke-linejoin: round; }
    .plan-name { margin: 0; font-size: 1.5rem; font-weight: 700; line-height: 1.25; letter-spacing: -.02em; }
    html[dir="rtl"] .plan-name { letter-spacing: 0; }
    .plan-desc { margin: .35rem auto 0; max-width: 30ch; color: var(--muted); font-size: .92rem; line-height: 1.55; text-wrap: balance; }

    /* The offer: a label above the price drawn like the monthly / yearly switch ("30% off your
       first month" on its grey track, the day it ends in a white chip inside it), the full price
       struck through beside the price. */
    .plan-offer { display: flex; justify-content: center; align-items: flex-end; padding-top: 1.05rem; }
    /* Side by side, a card without an offer keeps the row empty so its price lines up. */
    .plan-offer.is-empty { display: none; padding-top: 0; }
    @media (min-width: 641px) { .plan-offer.is-empty { display: flex; } }
    .offer-label {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      max-width: 100%;
      padding: .34rem .8rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--text) 5%, transparent);
      color: var(--text);
      font-size: .84rem;
      font-weight: 700;
      line-height: 1.4;
      text-align: center;
    }
    .offer-label svg { flex: none; width: 15px; height: 15px; fill: none; stroke: var(--brand-accent); stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    /* The day the offer ends, in a white chip like the switch's chosen half ("Until 30 Oct"). */
    .offer-label:has(.offer-until) { padding: 3px; padding-inline-start: .8rem; }
    .offer-until {
      display: inline-flex;
      align-items: center;
      margin-inline-start: .35rem;
      padding: .26rem .7rem;
      border-radius: var(--radius-full);
      background: var(--surface);
      box-shadow: 0 1px 2px rgba(15, 23, 42, .14), 0 0 0 1px rgba(15, 23, 42, .04);
      color: var(--muted);
      font-weight: 600;
      white-space: nowrap;
    }
    html:not([data-theme="light"]) .offer-until { background: #2E2E3A; box-shadow: none; color: var(--text); }
    @media (max-width: 400px) {
      .offer-label { gap: .3rem; padding-inline: .65rem; font-size: .78rem; }
      .offer-label:has(.offer-until) { padding-inline-end: 3px; }
      .offer-until { margin-inline-start: .25rem; padding-inline: .6rem; }
    }

    /* The price with "/month" beside it, the price before the offer struck through next to them
       (under them when the card is too narrow), then a short note. */
    .plan-pricing { display: flex; flex-direction: column; align-items: center; gap: .3rem; padding: .8rem 0 1.15rem; text-align: center; }
    .price-line { display: flex; flex-wrap: wrap; justify-content: center; align-items: baseline; gap: .15rem .6rem; }
    .price-line > .price-row { order: -1; }
    .price-original-row { display: inline-flex; align-items: baseline; }
    /* The full price, crossed out in red so it reads at a glance as the price before the offer. */
    .pricing-page { --price-strike: color-mix(in oklab, var(--danger) 85%, transparent); }
    .price-original { color: color-mix(in oklab, var(--muted) 70%, var(--text)); font-size: 1.2rem; font-weight: 600; font-variant-numeric: tabular-nums; text-decoration: line-through; text-decoration-thickness: 2px; text-decoration-color: var(--price-strike); }
    .price-row { display: flex; align-items: baseline; justify-content: center; gap: .35rem; }
    .price-amount { display: inline-block; font-size: clamp(2.1rem, 2.7vw, 2.55rem); font-weight: 700; line-height: 1.05; letter-spacing: -.03em; font-variant-numeric: tabular-nums; }
    .price-period { color: var(--muted); font-size: .95rem; font-weight: 500; }
    /* The prices change with a short lift when the billing cycle changes. */
    .price-amount.is-changing { animation: price-in .45s var(--ease-out); }
    @keyframes price-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    .price-note { color: var(--muted); font-size: .86rem; line-height: 1.5; }
    .price-note:empty { display: none; }
    .price-note.savings { color: color-mix(in oklab, var(--ok) 50%, var(--text)); font-weight: 600; }
    /* An offer is for monthly billing only: on yearly the label, the offer's struck price and the
       time left go, on the cards, in the rows, in the table and in the calculator. Yearly shows its
       price per month with the usual monthly price struck through beside it instead. */
    .pricing-page.is-yearly .plan-offer,
    .pricing-page.is-yearly .price-original-row.is-offer,
    .pricing-page:not(.is-yearly) .price-original-row.is-yearly,
    .pricing-page.is-yearly .compare-plan-offer { display: none; }

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
    .pricing-page.is-yearly [data-trial-monthly-only],
    .pricing-page:not(.is-yearly) [data-trial-yearly-only] {
      display: none !important;
    }

    .plan-action { padding-bottom: 1.3rem; }
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
      transition: background-color .2s ease, border-color .2s ease, color .2s ease, transform .2s ease, box-shadow .2s ease;
    }
    .plan-btn.btn-primary { background: var(--brand-accent); color: #fff; border: 1px solid transparent; box-shadow: var(--lift); }
    .plan-btn.btn-primary:hover { background: var(--brand-hover); }
    .plan-btn.btn-outline { background: transparent; color: var(--text); border: 1px solid var(--line-strong); }
    .plan-btn.btn-outline:hover { border-color: color-mix(in oklab, var(--text) 45%, transparent); background: color-mix(in oklab, var(--text) 4%, transparent); }
    .plan-btn:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
    .plan-btn.plan-btn-sm { min-height: 38px; padding: .4rem .75rem; font-size: .84rem; }
    .plan-btn.btn-disabled { background: var(--surface2); color: var(--muted); border: 1px solid var(--line); cursor: default; }
    /* On the cards and in the calculator: the recommended plan's button filled, the others drawn in violet. */
    .plan-card .plan-btn,
    .fit-plan .plan-btn { min-height: 50px; font-size: 1rem; }
    .plan-card .plan-btn.btn-outline,
    .fit-plan .plan-btn.btn-outline { color: var(--accent-text); border: 1.5px solid color-mix(in srgb, var(--brand-accent) 70%, transparent); }
    .plan-card .plan-btn.btn-outline:hover,
    .fit-plan .plan-btn.btn-outline:hover { border-color: var(--brand-accent); background: color-mix(in srgb, var(--brand-accent) 8%, transparent); }
    .plan-card .plan-btn.btn-primary:hover,
    .fit-plan .plan-btn.btn-primary:hover { transform: translateY(-1px); }

    /* Minutes and the credits behind them on one line, minutes first, each number a size
       above its unit; a narrow card sets them smaller to keep the line (the credits go under
       the minutes only when even that is not enough). */
    .plan-quota {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: baseline;
      gap: .3rem .6rem;
      white-space: nowrap;
      padding: 1rem 0;
      border-top: 1px solid var(--line);
      font-size: .9rem;
      font-weight: 600;
      line-height: 1.5;
    }
    .plan-minutes,
    .plan-quota-amount { display: inline-flex; flex-wrap: wrap; align-items: baseline; gap: 0 .35rem; }
    .plan-minutes-number { color: var(--text); font-size: 1.15rem; font-weight: 700; font-variant-numeric: tabular-nums; }
    .plan-quota-number { color: var(--text); font-size: .95rem; font-weight: 700; font-variant-numeric: tabular-nums; }
    .plan-minutes-unit,
    .plan-quota-unit { color: var(--muted); font-size: .86rem; font-weight: 500; }
    .plan-quota-credits { display: inline-flex; align-items: center; gap: .3rem; cursor: help; }
    .plan-quota-coin { font-size: 1rem; flex: none; }
    .plan-minutes, .plan-quota-amount { flex-wrap: nowrap; }
    .plan-quota { container-type: inline-size; }
    @container (max-width: 360px) {
      .plan-minutes-number { font-size: 1rem; }
      .plan-quota-number { font-size: .86rem; }
      .plan-minutes-unit, .plan-quota-unit { font-size: .76rem; }
      .plan-quota-coin { font-size: .9rem; }
    }
    @container (max-width: 300px) {
      .plan-quota { gap: .45rem; }
      .plan-minutes-number { font-size: .94rem; }
      .plan-quota-number { font-size: .82rem; }
      .plan-minutes-unit, .plan-quota-unit { font-size: .7rem; }
      .plan-quota-coin { display: none !important; }
    }

    /* The plan's lines, all of them: the label on one side, its value on the other. */
    .plan-features {
      list-style: none;
      margin: 0;
      padding: 1rem 0 0;
      border-top: 1px solid var(--line);
      display: flex;
      flex-direction: column;
      gap: .7rem;
    }
    .plan-features li { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; font-size: .9rem; }
    .feature-label { display: inline-flex; align-items: flex-start; flex: 1 1 auto; min-width: 0; gap: .5rem; color: var(--muted); line-height: 1.4; }
    .feature-label svg { flex: none; width: 1rem; height: 1rem; margin-top: .12rem; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .feature-value { flex: none; max-width: 55%; color: var(--text); font-weight: 650; line-height: 1.4; text-align: end; }
    .plan-features li.is-tick .feature-label { color: var(--text); font-weight: 600; }
    .plan-features li.is-tick .feature-label svg { color: var(--ok); stroke-width: 2.6; }

    /* On phones a long card shows its first four lines, and a button for the rest. */
    .plan-more { display: none; }
    @media (max-width: 640px) {
      .can-fold .plan-card:not(.is-open) .plan-features.is-long li:nth-child(n+5) { display: none; }
      .can-fold .plan-card:not(.is-open) .plan-more { display: flex; }
      .plan-more {
        align-items: center;
        justify-content: center;
        gap: .35rem;
        width: 100%;
        min-height: 44px;
        margin-top: .75rem;
        padding: 0;
        border: 0;
        border-top: 1px solid var(--line);
        background: none;
        color: var(--accent-text);
        font: inherit;
        font-size: .9rem;
        font-weight: 600;
        cursor: pointer;
      }
      .plan-more svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
      .plan-more:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; border-radius: 8px; }
    }

    /* Phones: every plan's name in one switch, drawn like the monthly / yearly one, on a band
       that stays under the site's bar while the cards scroll by (a hairline under it once it
       is held there). The plan being read is the chosen one. */
    .plan-tabs-bar { display: none; }
    @media (max-width: 640px) {
      .plan-tabs-bar {
        position: sticky;
        top: 64px;
        z-index: 20;
        display: block;
        margin: 1.1rem calc(50% - 50vw) 0;
        padding: 8px calc(50vw - 50%);
        background: var(--bg);
        transition: box-shadow .2s ease;
      }
      html[data-theme="light"] .plan-tabs-bar { background: var(--surface); }
      .plan-tabs-bar.is-stuck { box-shadow: 0 1px 0 var(--line), 0 8px 16px -14px rgba(15, 23, 42, .3); }
      .plan-tabs {
        display: flex;
        width: fit-content;
        max-width: 100%;
        margin: 0 auto;
        padding: 4px;
        overflow-x: auto;
        overscroll-behavior-x: contain;
        scrollbar-width: none;
        border-radius: var(--radius-full);
        background: color-mix(in oklab, var(--text) 5%, var(--bg));
      }
      .plan-tabs::-webkit-scrollbar { display: none; }
      .plan-tab {
        flex: none;
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        min-height: 38px;
        padding: 0 .62rem;
        border-radius: var(--radius-full);
        color: var(--muted);
        font-size: .84rem;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
        transition: background-color .2s ease, color .2s ease, box-shadow .2s ease;
      }
      .plan-tab .crown { width: 13px; height: 13px; }
      /* The narrowest phones: a little tighter, so all five names still fit. */
      @media (max-width: 380px) {
        .plan-tab { padding: 0 .5rem; font-size: .8rem; }
        .plan-tab .crown { width: 12px; height: 12px; }
      }
      .plan-tab.is-on { background: var(--surface); color: var(--text); box-shadow: 0 1px 2px rgba(15, 23, 42, .14), 0 0 0 1px rgba(15, 23, 42, .04); }
      html:not([data-theme="light"]) .plan-tab.is-on { background: #2E2E3A; box-shadow: none; }
      .plan-tab:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: -2px; }
      /* A card reached from the switch stops under it. */
      .plan-card { scroll-margin-top: 132px; }
    }

    /* How much is off, as a small green chip (the comparison table's header). */
    .compare-plan-offer s { color: color-mix(in oklab, var(--muted) 70%, var(--text)); font-size: .84rem; font-weight: 600; text-decoration-thickness: 1.5px; text-decoration-color: var(--price-strike); white-space: nowrap; }
    .offer-chip {
      padding: .1rem .5rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--ok) 15%, transparent);
      color: color-mix(in oklab, var(--ok) 45%, var(--text));
      font-size: .72rem;
      font-weight: 700;
      white-space: nowrap;
    }

    /* ── The minutes calculator ──
       Under the cards: the visitor sets how many minutes of voice they need on a voice track
       (the plans sit on it as orbs) and sees the plan that fits beside it, with its price and
       button; that plan's card above gets a ring. One script moves the playhead, the filled
       part of the voice, the number and the plan together, frame by frame. */
    .calc { padding-top: clamp(3.5rem, 7vw, 5.5rem); }
    .calc .section-title label { cursor: pointer; }
    .studio {
      display: grid;
      grid-template-columns: minmax(0, 1.65fr) minmax(290px, .85fr);
      max-width: 1200px;
      margin: 2rem auto 0;
      overflow: clip;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 28px;
      box-shadow: var(--card-shadow);
    }
    .studio-mixer { display: flex; flex-direction: column; min-width: 0; padding: clamp(1.4rem, 3vw, 2.4rem); }
    /* The number large and plain, on a thin line that marks it as one to type in (violet when
       pointed at or typed in), the unit after it. */
    .studio-amount { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0 .6rem; }
    .studio-amount input {
      field-sizing: content;
      width: auto;
      min-width: 2ch;
      padding: 0 .04em .02em;
      border: 0;
      border-bottom: 2px solid var(--line-strong);
      border-radius: 0;
      background: none;
      color: var(--text);
      font: inherit;
      font-size: clamp(3rem, 5.4vw, 4.4rem);
      font-weight: 700;
      line-height: 1.08;
      letter-spacing: -.02em;
      font-variant-numeric: tabular-nums;
      cursor: text;
      transition: border-color .2s ease;
      -moz-appearance: textfield;
    }
    .studio-amount input:hover { border-bottom-color: color-mix(in srgb, var(--brand-accent) 60%, var(--line-strong)); }
    .studio-amount input:focus-visible { outline: none; border-bottom-color: var(--brand-accent); }
    .studio-unit { font-size: 1.25rem; font-weight: 600; }
    /* How long that is in hours, on its own line (kept when empty, under an hour), so the
       track never moves up or down. */
    .studio-about { margin: .55rem 0 0; min-height: 1.6em; color: var(--muted); font-size: .98rem; line-height: 1.6; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* The track: a voice note (words and the pauses between them), a round playhead, and the
       plans as stops on a thin line under it. */
    .studio-track { position: relative; margin-top: 1.4rem; }
    .studio-wave { position: relative; height: 112px; cursor: ew-resize; touch-action: pan-y; -webkit-user-select: none; user-select: none; -webkit-tap-highlight-color: transparent; }
    .studio-wave svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
    html[dir="rtl"] .studio-wave svg { transform: scaleX(-1); }
    .studio-bars-base { stroke: color-mix(in srgb, var(--muted) 34%, transparent); }
    .studio-bars-fill { stroke: var(--brand-accent); }
    .studio-wave input {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      margin: 0;
      opacity: 0;
      pointer-events: none;
    }
    .studio-head {
      position: absolute;
      top: 50%;
      left: 0;
      width: 28px;
      height: 28px;
      margin: -14px 0 0 -14px;
      border-radius: 50%;
      background: var(--brand-accent);
      box-shadow: 0 0 0 4px var(--surface), 0 3px 10px rgba(16, 24, 40, .22);
      pointer-events: none;
      will-change: translate;
      transition: scale .2s var(--ease-out), box-shadow .2s ease;
    }
    .studio-head::after { content: ""; position: absolute; inset: 9px; border-radius: 50%; background: #fff; }
    .studio.is-dragging .studio-head { scale: 1.14; }
    .studio-wave input:focus-visible ~ .studio-head { box-shadow: 0 0 0 4px var(--surface), 0 0 0 7px var(--brand-accent); }
    .studio-stations { position: relative; height: 70px; margin-top: 8px; }
    /* The line runs as far as the playhead can go, like the voice above it. */
    .studio-stations::before {
      content: "";
      position: absolute;
      top: 21px;
      inset-inline-start: var(--track-from, 0);
      inset-inline-end: var(--track-to, 0);
      height: 2px;
      border-radius: 2px;
      background: var(--line);
    }
    /* Each stop is a 44px target; its orb is drawn at full size and shrunk when not chosen. */
    .studio-station {
      position: absolute;
      top: 0;
      display: grid;
      justify-items: center;
      gap: 2px;
      min-width: 44px;
      padding: 4px 6px 0;
      border: 0;
      background: none;
      color: var(--muted);
      font: inherit;
      cursor: pointer;
      translate: -50% 0;
      -webkit-tap-highlight-color: transparent;
      transition: color .25s ease;
    }
    .studio-station .orb { --orb-size: 36px; box-shadow: 0 0 0 4px var(--surface); transform: scale(.72); transition: transform .35s cubic-bezier(.34, 1.4, .64, 1); }
    .studio-station-name { font-size: .8rem; line-height: 1.25; white-space: nowrap; text-align: center; }
    .studio-station:hover { color: var(--text); }
    .studio-station.is-on { color: var(--text); font-weight: 600; }
    .studio-station.is-on .orb { transform: none; }
    .studio-station-crown { position: absolute; top: -12px; left: 50%; width: 14px; height: 14px; translate: -50% 0; transform: translateY(5px); transition: transform .35s cubic-bezier(.34, 1.4, .64, 1); }
    .studio-station.is-on .studio-station-crown { transform: none; }

    /* At the foot of the track: the voices every plan has. */
    .studio-proof { display: flex; flex-wrap: nowrap; align-items: center; gap: .8rem; margin: 1.5rem 0 0; color: var(--muted); font-size: .9rem; }
    .studio-faces { display: flex; flex: none; }
    .studio-faces img { width: 32px; height: 32px; border-radius: 50%; border: 2px solid var(--surface); margin-inline-start: -9px; background: var(--surface2); box-shadow: 0 2px 8px rgba(15, 23, 42, .12); }
    .studio-faces img:first-child { margin-inline-start: 0; }
    .studio-faces img:nth-child(n+11) { display: none; }
    .studio-proof > :last-child { white-space: nowrap; }

    /* The plan that fits: one at a time. The old one goes quickly and the new one comes in just
       after it, so two never show at once. */
    .studio-fit {
      display: grid;
      align-items: center;
      min-width: 0;
      padding: clamp(1.4rem, 3vw, 2.2rem);
      background: color-mix(in srgb, var(--brand-accent) 5%, var(--surface));
      border-inline-start: 1px solid var(--line);
    }
    .fit-stage { display: grid; grid-template-columns: minmax(0, 1fr); }
    .fit-plan {
      grid-area: 1 / 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      min-width: 0;
      text-align: center;
      opacity: 0;
      visibility: hidden;
      transform: translateY(8px);
      transition: opacity .14s ease-in, transform .14s ease-in, visibility 0s linear .14s;
    }
    .fit-plan.is-active { opacity: 1; visibility: visible; transform: none; transition: opacity .3s var(--ease-out) .12s, transform .42s var(--ease-out) .12s, visibility 0s; }
    .fit-orb { --orb-size: 64px; margin-bottom: .75rem; box-shadow: 0 16px 30px -18px color-mix(in srgb, var(--o0) 75%, transparent); }
    .fit-name { margin: 0; font-size: 1.55rem; font-weight: 700; line-height: 1.25; }
    .fit-minutes { margin: .25rem 0 0; color: var(--muted); font-size: .92rem; }
    .fit-minutes strong { color: var(--text); font-weight: 700; font-variant-numeric: tabular-nums; }
    .fit-plan .price-line { margin: .9rem 0 1.2rem; }

    /* Phones: the plan that fits and its button stay in reach at the bottom once the cards and
       the calculator are scrolled past. */
    .plan-bar { display: none; }

    /* The track's one entrance: the voice rises from its centre line when it comes into view. */
    @keyframes wave-in { from { opacity: 0; scale: 1 .15; } to { opacity: 1; scale: 1 1; } }
    .studio.is-waiting .studio-wave svg { opacity: 0; }
    .studio.is-ready .studio-wave svg { animation: wave-in .7s var(--ease-out) both; }
    @media (prefers-reduced-motion: reduce) {
      .studio *, .plan-card, .fit-plan, .plan-tab, .plan-bar, .price-amount { transition: none !important; animation: none !important; }
    }

    /* Under the cards, as wide as their row: more minutes or a whole team, by talking to us. */
    .team-band {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr) auto;
      align-items: center;
      gap: 1.25rem 1.5rem;
      margin: clamp(1.5rem, 3vw, 2.25rem) auto 0;
      padding: 1.35rem 1.6rem;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--card-radius);
      box-shadow: var(--card-shadow);
    }
    /* As wide as the cards above it. */
    .team-band { max-width: 1200px; }
    .team-band[data-count="1"] { max-width: 420px; }
    .team-band[data-count="2"],
    .team-band[data-count="4"] { max-width: 820px; }
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
    /* A plan on offer: its full price struck through and how much is off, under the price. */
    .compare-plan-offer { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: .2rem .4rem; margin: -.45rem 0 .75rem; }
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
    /* Phones: two plans side by side, each picked in the row above them (it stays in view
       under the site's bar while reading down); the table then fits the screen. */
    .compare-pick-row { display: none; }
    @media (max-width: 640px) {
      .compare-scroll.is-picked { overflow: clip; }
      .compare-table.is-picked { min-width: 0; table-layout: fixed; font-size: .86rem; }
      .compare-table.is-picked .is-off { display: none; }
      .compare-table.is-picked .compare-pick-row { display: table-row; }
      .compare-table.is-picked thead tr:not(.compare-pick-row) th { position: static; padding: .9rem .45rem 1rem; }
      .compare-table.is-picked th[scope="row"],
      .compare-table.is-picked thead th:first-child { position: static; width: 30%; min-width: 0; }
      .compare-table.is-picked .compare-pick-label { width: 30%; }
      .compare-table.is-picked .compare-plan { min-width: 0; }
      .compare-table.is-picked .compare-plan-name { display: none; }
      .compare-table.is-picked .compare-plan-price { flex-wrap: wrap; margin: .1rem 0 .6rem; }
      .compare-table.is-picked .compare-plan-price b { font-size: 1.15rem; }
      .compare-table.is-picked td,
      .compare-table.is-picked th[scope="row"] { padding: .75rem .45rem; }
      .compare-table.is-picked th[scope="row"] { padding-inline-start: .9rem; }
      .compare-table.is-picked .compare-group span { inset-inline-start: .9rem; }
      .compare-pick-row td {
        position: sticky;
        top: 64px;
        z-index: 4;
        padding: .6rem .3rem;
        background: var(--surface);
        border-bottom: 1px solid var(--line);
        vertical-align: middle;
      }
      .compare-pick-row td:first-child { padding-inline-start: .8rem; color: var(--muted); font-size: .84rem; font-weight: 600; text-align: start; }
      .compare-pick-cell { position: relative; }
      .compare-pick-face {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 46px;
        padding-block: .3rem;
        padding-inline: .55rem 1.45rem;
        border: 1px solid var(--line-strong);
        border-radius: 21px;
        background: var(--surface);
        color: var(--text);
        font-size: .85rem;
        font-weight: 700;
        line-height: 1.25;
        text-align: center;
        overflow-wrap: anywhere;
        pointer-events: none;
      }
      .compare-pick-face::after {
        content: "";
        position: absolute;
        top: 50%;
        inset-inline-end: .8rem;
        width: 6px;
        height: 6px;
        border-right: 1.8px solid var(--muted);
        border-bottom: 1.8px solid var(--muted);
        transform: translateY(-70%) rotate(45deg);
      }
      /* On top of the face, so a tap opens the phone's own list. */
      .compare-pick {
        position: absolute;
        z-index: 1;
        inset: .6rem .3rem;
        width: calc(100% - .6rem);
        height: calc(100% - 1.2rem);
        margin: 0;
        opacity: 0;
        font-size: 16px;
        cursor: pointer;
      }
      .compare-pick:focus-visible + .compare-pick-face { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
    }
    .compare-tick { width: 1.15rem; height: 1.15rem; fill: none; stroke: var(--ok); stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; vertical-align: middle; }
    .compare-no { color: var(--muted); font-weight: 600; }
    /* Wide screens, once the whole table fits: the plan names, prices and buttons stay in
       view while you read down. Narrower, the table scrolls sideways in its own box. */
    @media (min-width: 1280px) {
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

    /* Why a link brought the visitor here instead: a trial or a pack that is not on offer. */
    .page-notice {
      display: block;
      width: fit-content;
      margin: 0 auto 1.25rem;
      padding: .6rem 1.1rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      background: var(--surface2);
      color: var(--text);
      font-size: .92rem;
      font-weight: 600;
    }

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


    /* ── Smaller screens ── */
    @media (max-width: 1000px) {
      /* The calculator in one column: the plan that fits under the track. */
      .studio { grid-template-columns: minmax(0, 1fr); }
      .studio-fit { border-inline-start: 0; border-top: 1px solid var(--line); }
      .fit-plan .price-line { margin-bottom: 1rem; }
      .fit-plan .plan-btn { max-width: 420px; }
      /* Below the cards and the calculator, the plan that fits stays in reach at the bottom. */
      .plan-bar {
        display: flex;
        position: fixed;
        inset: auto 0 0;
        z-index: 80;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: .7rem 1rem calc(.7rem + env(safe-area-inset-bottom));
        background: color-mix(in srgb, var(--surface) 92%, transparent);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border-top: 1px solid var(--line);
        transform: translateY(110%);
        visibility: hidden;
        transition: transform .35s cubic-bezier(.22, 1, .36, 1), visibility 0s linear .35s;
      }
      .plan-bar.is-shown { transform: none; visibility: visible; transition: transform .35s cubic-bezier(.22, 1, .36, 1), visibility 0s; }
      .plan-bar-name { display: block; font-weight: 700; }
      .plan-bar-price { color: var(--muted); font-size: .86rem; }
      .plan-bar-price.has-offer { color: var(--text); font-weight: 600; }
      .plan-bar-now { white-space: nowrap; }
      /* Spaced on both sides: the struck price runs left to right inside an Arabic line. */
      .plan-bar-price s { margin: 0 .45rem; color: var(--muted); font-size: .86rem; font-weight: 600; text-decoration-thickness: 1.5px; text-decoration-color: color-mix(in oklab, var(--danger) 85%, transparent); }
      .plan-bar .plan-btn { width: auto; min-height: 44px; padding: .5rem 1.3rem; white-space: nowrap; background: var(--brand-accent); color: #fff; border-color: transparent; }
      .plan-bar .plan-btn.btn-disabled { background: var(--surface2); color: var(--muted); }
      body { padding-bottom: calc(72px + env(safe-area-inset-bottom)); }
      body.has-plan-bar .wa-widget { bottom: calc(84px + env(safe-area-inset-bottom)) !important; }
    }
    /* The chat button steps aside while the plans and the calculator are on screen, wherever the
       cards reach the screen's edge: it would cover a card's price or button. */
    @media (max-width: 1360px) {
      .wa-widget { transition: opacity .25s ease, transform .25s ease, bottom .35s ease; }
      body.plans-in-view .wa-widget:not(.open) { opacity: 0; transform: translateY(14px) scale(.9); pointer-events: none; }
    }
    @media (max-width: 640px) {
      .page-hero .billing-toggle { width: min(100%, 360px); }
      .plan-grid { margin-top: 1.5rem; }
      .plan-card { padding: 1.6rem 1.2rem 1.25rem; border-radius: 22px; margin-bottom: 16px; }
      .plan-card + .plan-card.popular { margin-top: 8px; }
      .plan-orb { --orb-size: 52px; }
      .plan-badges { margin-bottom: .6rem; }
      .plan-name { font-size: 1.4rem; }
      .plan-desc { font-size: .9rem; }
      .plan-offer { padding-top: .9rem; }
      .plan-pricing { padding: .65rem 0 1rem; }
      .plan-action { padding-bottom: 1.1rem; }
      .studio { border-radius: 22px; }
      .studio-amount input { font-size: 3rem; }
      .studio-wave { height: 96px; }
      /* Every plan's name on the track, on two lines when it needs them. */
      .studio-station-name { font-size: .7rem; white-space: normal; width: max-content; max-width: 4.7rem; }
      .studio-proof { gap: .6rem; font-size: .84rem; }
      .studio-faces { flex: 0 1 auto; min-width: 0; flex-wrap: wrap; height: 28px; overflow: hidden; }
      .studio-faces img { width: 28px; height: 28px; margin-inline-start: -8px; box-shadow: none; }
      .studio-faces img:nth-child(n+7) { display: none; }
    }
    @media (max-width: 1000px) {
      .team-band[data-count] { max-width: 820px; }
    }
    @media (max-width: 860px) {
      .features-grid { grid-template-columns: 1fr; }
      .faq-section { grid-template-columns: 1fr; gap: 1.25rem; }
      .faq-intro { position: static; }
      .faq-intro .section-title, .faq-intro { text-align: center; }
    }
    @media (max-width: 600px) {
      .pricing-page { width: min(100% - 2rem, 1320px); }
      .page-hero { padding: 1.75rem 0 1.4rem; }
      .page-hero .lead { font-size: .95rem; line-height: 1.6; }
      /* The three things every plan has, as compact rows: the icon beside its title. */
      .features-grid { gap: .75rem; }
      .feature-card { display: grid; grid-template-columns: auto minmax(0, 1fr); column-gap: .9rem; padding: 1.1rem; }
      .feature-icon { grid-row: span 2; width: 2.3rem; height: 2.3rem; margin: 0; }
      .feature-card h3 { margin: .15rem 0 .3rem; font-size: 1rem; }
      .feature-card p { font-size: .9rem; line-height: 1.6; }
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
          // The featured plan keeps its crown and strap for the member already on it too.
          'is_popular' => !empty($plan['is_popular']),
          'trial_monthly' => in_array('monthly', $trialCycles, true),
          'trial_yearly' => in_array('yearly', $trialCycles, true),
        ];
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
    <section class="page-hero">
      @if(session('notice'))
        <p class="page-notice" role="status">{{ session('notice') }}</p>
      @endif
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
      <h1>{{ __('ui.pricing.hero_title') }} {{ __('ui.pricing.hero_title_accent') }}</h1>
      <p class="lead">{{ __('ui.pricing.hero_subtitle') }}</p>
      {{-- Monthly or yearly: every price on the page follows it. --}}
      <div class="billing-toggle" id="billingToggle" role="group" aria-label="{{ __('ui.pricing.monthly') }} / {{ __('ui.pricing.yearly') }}">
        <span class="billing-indicator" aria-hidden="true"></span>
        <button type="button" id="monthlyBtn" class="active" aria-pressed="true">{{ __('ui.pricing.monthly') }}</button>
        {{-- The yearly saving shows on the cards (the usual price struck through, and the button). --}}
        <button type="button" id="yearlyBtn" aria-pressed="false">{{ __('ui.pricing.yearly') }}</button>
      </div>
    </section>

    @php
      $planCount = min(6, max(1, count($mainPlans)));
      // The plans on the minutes track, fewest minutes first.
      $trackPlans = collect($mainPlans)
        ->map(fn ($p, $i) => ['slug' => (string) $p['slug'], 'name' => $planDisplayName($p), 'minutes' => \App\Support\Credits::minutesFor((int) ($p['monthly_chars'] ?? 0)), 'popular' => $planState($p, $i)['is_popular']])
        ->filter(fn ($p) => $p['minutes'] > 0)
        ->sortBy('minutes')->values();
      // The plan the calculator starts on: the recommended one, else the member's own, else the first.
      $mainSlugList = array_column($mainPlans, 'slug');
      $shownSlug = (collect($mainPlans)->first(fn ($p, $i) => $planState($p, $i)['is_popular']) ?? [])['slug']
        ?? (in_array($activePlanSlug, $mainSlugList, true) ? $activePlanSlug : ($mainSlugList[0] ?? null));
      if (!$trackPlans->contains('slug', $shownSlug)) {
        $shownSlug = $trackPlans->first()['slug'] ?? $shownSlug;
      }
      $crownSvg = '<svg class="crown" viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 8.5l4.2 3.6L12 4.5l4.3 7.6 4.2-3.6-1.8 9H5.3z"/><rect x="5.3" y="18.6" width="13.4" height="2" rx="1"/></svg>';
      $shownMinutes = (int) ($trackPlans->firstWhere('slug', $shownSlug)['minutes'] ?? ($trackPlans->first()['minutes'] ?? 0));
      $studioI18n = [
        'about' => __('ui.pricing.minutes_about'),
        'hours' => explode('|', __('ui.pricing.hours_count')),
        'minutes' => explode('|', __('ui.pricing.minutes_count')),
        'hours_and_minutes' => __('ui.pricing.hours_and_minutes'),
        'live' => __('ui.pricing.minutes_live'),
      ];
      $proofFaces = ['ar-ahmed', 'ar-amal', 'en-amelia', 'ar-ali', 'ar-aisha', 'en-adrian', 'ar-layla', 'ar-omar', 'en-alice', 'ar-nour'];
    @endphp
    <section class="plans" aria-labelledby="plansTitle">
      {{-- Named for screen readers only: the cards follow the title above. --}}
      <h2 class="plans-title" id="plansTitle">{{ __('ui.pricing.all_plans') }}</h2>

      @if(count($mainPlans) > 1)
        {{-- Phones: every plan's name in one switch that stays under the site's bar while the cards
             scroll by; the plan being read is the chosen one, and a name goes to its card. --}}
        <div class="plan-tabs-bar" id="planTabsBar">
          <nav class="plan-tabs" id="planTabs" aria-labelledby="plansTitle">
            @foreach($mainPlans as $index => $plan)
              <a @class(['plan-tab', 'is-popular' => $planState($plan, $index)['is_popular']]) href="#plan-{{ $plan['slug'] }}" data-plan="{{ $plan['slug'] }}">@if($planState($plan, $index)['is_popular']){!! $crownSvg !!}@endif{{ $planDisplayName($plan) }}</a>
            @endforeach
          </nav>
        </div>
      @endif

      <div class="plan-grid" id="planGrid" data-count="{{ count($mainPlans) }}">
        @foreach($mainPlans as $index => $plan)
          @include('components._plan-card', ['plan' => $plan, 'index' => $index])
        @endforeach
      </div>
    </section>

    @if($trackPlans->count() >= 2)
      {{-- The calculator: the minutes a month the visitor needs, on a voice track, and the plan that fits. --}}
      <section class="calc" aria-labelledby="studioTitle">
        <h2 class="section-title" id="studioTitle"><label for="studioMinutes">{{ __('ui.pricing.minutes_label') }}</label></h2>
        <div class="studio" id="studio" data-plans="{{ json_encode($trackPlans) }}" data-i18n="{{ json_encode($studioI18n) }}">
          <div class="studio-mixer">
            <div class="studio-amount">
              <input id="studioMinutes" inputmode="numeric" autocomplete="off" value="{{ number_format($shownMinutes) }}" aria-describedby="studioAbout">
              <span class="studio-unit">{{ __('ui.pricing.minutes_unit') }}</span>
            </div>
            <p class="studio-about" id="studioAbout"></p>
            <div class="studio-track">
              <div class="studio-wave" id="studioWave">
                <svg id="studioBars" aria-hidden="true" focusable="false">
                  <defs><clipPath id="studioFillClip"><rect id="studioFillEdge" x="0" y="-20" width="0" height="200"/></clipPath></defs>
                  <path class="studio-bars-base" fill="none" stroke-linecap="round"/>
                  <path class="studio-bars-fill" fill="none" stroke-linecap="round" clip-path="url(#studioFillClip)"/>
                </svg>
                <input id="studioSlider" type="range" min="0" max="0" step="1" value="0" aria-label="{{ __('ui.pricing.minutes_track') }}">
                <span class="studio-head" id="studioHead"></span>
              </div>
              <div class="studio-stations" aria-hidden="true">
                @foreach($trackPlans as $trackPlan)
                  <button type="button" class="studio-station" data-pick="{{ $trackPlan['slug'] }}" tabindex="-1">
                    @if($trackPlan['popular']){!! str_replace('class="crown"', 'class="crown studio-station-crown"', $crownSvg) !!}@endif
                    <x-orb :name="\App\Support\Orbs::forPlan($trackPlan['slug'])" />
                    <span class="studio-station-name">{{ $trackPlan['name'] }}</span>
                  </button>
                @endforeach
              </div>
            </div>
            <p class="studio-proof">
              <span class="studio-faces" aria-hidden="true">@foreach($proofFaces as $face)<img src="{{ asset('images/speakers/'.$face.'.webp') }}" alt="" width="32" height="32" loading="lazy">@endforeach</span>
              <span>{{ __('ui.pricing.voices_proof', ['count' => \App\Support\DialectCounts::arabic() + \App\Support\DialectCounts::english()]) }}</span>
            </p>
          </div>
          {{-- The plan that fits the minutes, with its price and its own button. --}}
          <div class="studio-fit">
            <div class="fit-stage">
              @foreach($mainPlans as $index => $plan)
                @php
                  $fitMinutes = \App\Support\Credits::minutesFor((int) ($plan['monthly_chars'] ?? 0));
                  $fitShown = $plan['slug'] === $shownSlug;
                  $fitMonthly = $wholePrice((string) ($plan['display_price_monthly_formatted'] ?? ''));
                  $fitYearly = $wholePrice((string) ($plan['display_price_yearly_monthly_formatted'] ?? $fitMonthly));
                  $fitOriginal = !empty($plan['has_discount']) && !empty($plan['original_price_monthly_formatted']) ? $wholePrice((string) $plan['original_price_monthly_formatted']) : null;
                  $fitFullMonthly = $fitOriginal ?? $fitMonthly;
                @endphp
                @if($fitMinutes > 0)
                  <div @class(['fit-plan', 'is-active' => $fitShown]) data-fit-slug="{{ $plan['slug'] }}" data-orb-host @unless($fitShown) inert @endunless>
                    <x-orb :name="\App\Support\Orbs::forPlan((string) $plan['slug'])" @class(['fit-orb', 'is-live' => $fitShown]) />
                    <p class="fit-name">{{ $planDisplayName($plan) }}</p>
                    <p class="fit-minutes"><strong>{{ number_format($fitMinutes) }}</strong> {{ __('ui.pricing.minutes_per_month') }}</p>
                    <div class="price-line">
                      @if($fitOriginal)
                        <div class="price-original-row is-offer"><s class="price-original" dir="ltr">{{ $fitOriginal }}</s></div>
                      @endif
                      @if($fitYearly !== $fitFullMonthly)
                        <div class="price-original-row is-yearly"><s class="price-original" dir="ltr">{{ $fitFullMonthly }}</s></div>
                      @endif
                      <div class="price-row">
                        <span class="price-main" dir="ltr"><span class="price-amount" data-compare-price data-monthly="{{ $fitMonthly }}" data-yearly="{{ $fitYearly }}">{{ $fitMonthly }}</span></span>
                        <span class="price-period">{{ __('ui.pricing.per_month_short') }}</span>
                      </div>
                    </div>
                    @include('components._plan-button', ['plan' => $plan, 'index' => $index])
                  </div>
                @endif
              @endforeach
            </div>
          </div>
        </div>
      </section>
    @endif

    {{-- More than the biggest plan, or a whole team: by talking to us. --}}
    <section class="team-band" data-count="{{ $planCount }}" aria-labelledby="teamTitle" data-orb-host>
      <x-orb name="teams" class="team-orb" />
      <div>
        <h2 id="teamTitle">{{ __('ui.pricing.team_title') }}</h2>
        <p>{{ __('ui.pricing.team_body') }}</p>
      </div>
      <a class="team-btn" href="{{ route('contact', ['topic' => 'billing']) }}">{{ __('ui.pricing.team_cta') }}</a>
    </section>

    {{-- What every plan has, right after the plans; the full comparison follows for those who want it. --}}
    <section class="features-section">
      <h2 class="section-title">{{ __('ui.pricing.included_title') }}</h2>
      <p class="section-subtitle">{{ __('ui.pricing.included_subtitle') }}</p>
      <div class="features-grid">
        <article class="feature-card">
          <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3M8 22h8"/>
          </svg>
          <h3>{{ __('ui.pricing.feature1_title') }}</h3>
          <p>{{ __('ui.pricing.feature1_body') }}</p>
        </article>
        <article class="feature-card">
          <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 5h8M8 3v2M10.5 5c-.8 3.6-3 6.4-6.5 8M6.5 9c1.2 2 3 3.4 5.5 4.2"/><path d="M12.5 21l4-9 4 9M14 18h5"/>
          </svg>
          <h3>{{ __('ui.pricing.feature2_title') }}</h3>
          <p>{{ __('ui.pricing.feature2_body') }}</p>
        </article>
        <article class="feature-card">
          <svg class="feature-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M10 3.5a2 2 0 0 1 4 0V5h3.5A1.5 1.5 0 0 1 19 6.5V10h-1.5a2 2 0 0 0 0 4H19v3.5a1.5 1.5 0 0 1-1.5 1.5H14v-1.5a2 2 0 0 0-4 0V19H6.5A1.5 1.5 0 0 1 5 17.5V14h1.5a2 2 0 0 0 0-4H5V6.5A1.5 1.5 0 0 1 6.5 5H10z"/>
          </svg>
          <h3>{{ __('ui.pricing.feature3_title') }}</h3>
          <p>{{ __('ui.pricing.feature3_body') }}</p>
        </article>
      </div>
    </section>

    @if(count($comparePlans) > 1 && $compareRows !== [])
      <section class="compare" id="compare" aria-labelledby="compareTitle">
        <h2 class="section-title" id="compareTitle">{{ __('ui.pricing.compare_title') }}</h2>
        <p class="section-subtitle">{{ __('ui.pricing.compare_subtitle') }}</p>
        <div class="compare-scroll" role="region" aria-labelledby="compareTitle" tabindex="0">
          @php
            // Phones show two plans side by side, picked above the table: first the plan the
            // calculator starts on, then the next one up (the one before it when it is the last).
            $compareFirst = max(0, (int) array_search($shownSlug, array_column($comparePlans, 'slug'), true));
            $compareSecond = $compareFirst + 1 < count($comparePlans) ? $compareFirst + 1 : max(0, $compareFirst - 1);
          @endphp
          <table class="compare-table" data-pick="{{ $compareFirst }},{{ $compareSecond }}">
            <thead>
              @if(count($comparePlans) > 2)
                <tr class="compare-pick-row">
                  <td class="compare-pick-label">{{ __('ui.pricing.compare_pick') }}</td>
                  @foreach(['compare_pick_first', 'compare_pick_second'] as $pickLabel)
                    <td class="compare-pick-cell">
                      {{-- The phone's own list opens from the select; the name shows on the face
                           under it, where a long name can take two lines. --}}
                      <select class="compare-pick" aria-label="{{ __('ui.pricing.'.$pickLabel) }}">
                        @foreach($comparePlans as $pickIndex => $pickPlan)
                          <option value="{{ $pickIndex }}">{{ $planDisplayName($pickPlan) }}</option>
                        @endforeach
                      </select>
                      <span class="compare-pick-face" aria-hidden="true"></span>
                    </td>
                  @endforeach
                </tr>
              @endif
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
                  <th scope="col" class="compare-plan {{ $comparePopular ? 'is-popular' : '' }}" data-col="{{ $compareIndex }}" data-slug="{{ $comparePlan['slug'] }}">
                    <span class="compare-plan-badge">{{ $comparePopular ? __('ui.pricing.most_popular') : '' }}</span>
                    <span class="compare-plan-name">{{ $planDisplayName($comparePlan) }}</span>
                    <span class="compare-plan-price">
                      <b dir="ltr" data-compare-price data-monthly="{{ $compareMonthly }}" data-yearly="{{ $compareYearly }}">{{ $compareMonthly }}</b><small>{{ __('ui.pricing.per_month_short') }}</small>
                    </span>
                    @if(!empty($comparePlan['has_discount']) && !empty($comparePlan['original_price_monthly_formatted']))
                      <span class="compare-plan-offer">
                        <s dir="ltr">{{ $wholePrice((string) $comparePlan['original_price_monthly_formatted']) }}</s>
                        <span class="offer-chip">{{ __('ui.pricing.discount_chip', ['percent' => (int) ($comparePlan['discount_percentage'] ?? 0)]) }}</span>
                      </span>
                    @endif
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
                    @foreach($comparePlans as $compareIndex => $comparePlan)
                      @php $cell = $values[$comparePlan['slug']] ?? null; @endphp
                      <td class="{{ !empty($comparePlan['is_popular']) ? 'is-popular' : '' }}" data-col="{{ $compareIndex }}">
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

    <section class="faq-section" aria-labelledby="faqTitle">
      <div class="faq-intro">
        <h2 class="section-title" id="faqTitle">{{ __('ui.pricing.faq_title') }}</h2>
        <p>{{ __('ui.pricing.faq_subtitle') }}</p>
        <a class="faq-contact" href="{{ route('contact') }}">{{ __('ui.pricing.faq_contact') }}<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
      </div>
      <div class="faq-list">
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

  {{-- Phones: the plan that fits and its button, kept at the bottom once the plans are scrolled past. --}}
  <div class="plan-bar" id="planBar" aria-hidden="true">
    <div><span class="plan-bar-name" id="planBarName"></span><span class="plan-bar-price" id="planBarPrice"></span></div>
    <div id="planBarAction"></div>
  </div>

  @include('components.footer')
  @include('components.scripts')
  @include('components.motion')
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
      var page = document.querySelector('.pricing-page');
      var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var isYearly = false;
      var i18n = @json($pricingI18n);

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

          // A plan on offer (monthly billing only: the card hides it on yearly).
          var offer = card.querySelector('.offer-label');

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
            priceNote.textContent = !offer && displayYearlyMonthly !== displayMonthly
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
        placeChip();
        if (page) page.classList.toggle('is-yearly', yearly);
        updatePrices();
        updateUpgradeLinks();
        updateComparePrices();
        // The prices on the cards and in the calculator lift in.
        if (!reduced) {
          document.querySelectorAll('.plan-card .price-amount, .fit-plan .price-amount').forEach(function(el) {
            el.classList.remove('is-changing');
            void el.offsetWidth;
            el.classList.add('is-changing');
          });
        }
      }

      // The chip under the chosen half: its exact place and width, whatever the words' widths.
      function placeChip() {
        var active = isYearly ? yearlyBtn : monthlyBtn;
        if (!active.offsetWidth) return;
        billingToggle.style.setProperty('--chip-w', active.offsetWidth + 'px');
        billingToggle.style.setProperty('--chip-x', active.offsetLeft + 'px');
        billingToggle.classList.add('is-measured');
      }
      placeChip();
      if ('ResizeObserver' in window) new ResizeObserver(placeChip).observe(billingToggle);
      if (document.fonts && document.fonts.ready) document.fonts.ready.then(placeChip);

      monthlyBtn.addEventListener('click', function() { setBillingMode(false); });
      yearlyBtn.addEventListener('click', function() { setBillingMode(true); });

      updatePrices();
      updateUpgradeLinks();
    })();
  </script>
  <script>
    /* ── The plans and the minutes calculator ──
       Phones: the switch above the cards follows the card being read and a name goes to its
       card; each card shows four lines until asked for the rest. In the calculator the plans sit on
       the track evenly spaced, the minutes between two plans on a log scale, so 15 and
       2,500 minutes both fit. One loop moves everything: the playhead, the filled part of
       the voice, the number and the plan follow the same position in the same frame, so
       nothing lags behind anything else. A drag follows the hand; a click, a key, a plan
       or a typed number glides there; close to a plan the playhead settles on it. The plan
       that fits is the smallest one that covers the minutes chosen; once the visitor has
       used the calculator, its card above gets a ring too. */
    (function () {
      var studio = document.getElementById('studio');
      var grid = document.getElementById('planGrid');
      var plansSection = grid && grid.closest('.plans');
      var bar = document.getElementById('planBar');
      var rtl = document.documentElement.dir === 'rtl';
      var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var narrow = window.matchMedia('(max-width: 1000px)');
      var cardsReachEdge = window.matchMedia('(max-width: 1360px)');
      var all = function (root, selector) { return root ? Array.prototype.slice.call(root.querySelectorAll(selector)) : []; };
      var cards = all(grid, '.plan-card');
      var tabBox = document.getElementById('planTabs');
      var tabs = all(tabBox, '.plan-tab[data-plan]');
      var fits = all(studio, '.fit-plan');
      var stations = all(studio, '.studio-station[data-pick]');
      var shownSlug = null, used = false;

      // The switch on phones: one name chosen at a time, kept in view inside the switch.
      var readingSlug = null, followFrom = 0;
      function chooseTab(slug) {
        if (!tabBox || slug === readingSlug) return;
        readingSlug = slug;
        tabs.forEach(function (tab) {
          var on = tab.getAttribute('data-plan') === slug;
          tab.classList.toggle('is-on', on);
          if (on) {
            tab.setAttribute('aria-current', 'true');
            var box = tabBox.getBoundingClientRect(), it = tab.getBoundingClientRect();
            var by = it.left < box.left + 8 ? it.left - box.left - 8 : (it.right > box.right - 8 ? it.right - box.right + 8 : 0);
            if (by) tabBox.scrollBy({ left: by, behavior: reduced ? 'auto' : 'smooth' });
          } else {
            tab.removeAttribute('aria-current');
          }
        });
      }
      if (tabs.length) {
        chooseTab(tabs[0].getAttribute('data-plan'));
        // A name goes to its card (the switch keeps that name while the page glides there).
        tabs.forEach(function (tab) {
          tab.addEventListener('click', function (e) {
            var card = document.getElementById('plan-' + tab.getAttribute('data-plan'));
            if (!card) return;
            e.preventDefault();
            chooseTab(tab.getAttribute('data-plan'));
            followFrom = Date.now() + (reduced ? 0 : 900);
            card.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
            card.focus({ preventScroll: true });
          });
        });
        // The band under the site's bar gets its hairline once it is held there.
        var tabBar = document.getElementById('planTabsBar'), barFrame = 0;
        if (tabBar) {
          var held = function () {
            barFrame = 0;
            var header = document.querySelector('.app-header');
            var top = header ? header.getBoundingClientRect().bottom : 64;
            tabBar.classList.toggle('is-stuck', tabBar.getBoundingClientRect().top <= top + 0.5 && window.scrollY > 0);
          };
          window.addEventListener('scroll', function () { if (!barFrame) barFrame = requestAnimationFrame(held); }, { passive: true });
          held();
        }
        // The card crossing the upper middle of the screen is the one being read.
        if ('IntersectionObserver' in window) {
          var reading = new IntersectionObserver(function (entries) {
            if (Date.now() < followFrom) return;
            entries.forEach(function (entry) { if (entry.isIntersecting) chooseTab(entry.target.getAttribute('data-plan-slug')); });
          }, { rootMargin: '-40% 0px -55% 0px' });
          cards.forEach(function (card) { reading.observe(card); });
        }
      }

      // Phones: the rest of a card's lines on request; the keyboard moves on to the first of them.
      if (grid) {
        grid.classList.add('can-fold');
        all(grid, '.plan-more').forEach(function (button) {
          button.addEventListener('click', function () {
            var card = button.closest('.plan-card');
            card.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');
            var next = card.querySelector('.plan-features li:nth-child(5)');
            if (next) { next.setAttribute('tabindex', '-1'); next.focus({ preventScroll: true }); }
          });
        });
      }

      function fitCard() {
        return fits.filter(function (f) { return f.classList.contains('is-active'); })[0] || fits[0];
      }
      // The plan that fits: its card gets a ring, once the visitor has used the calculator.
      function mark() {
        cards.forEach(function (c) { c.classList.toggle('is-fit', used && c.getAttribute('data-plan-slug') === shownSlug); });
      }
      function use() { if (!used) { used = true; mark(); } }
      // One plan in the calculator at a time; its orb comes alive.
      function showPlan(slug) {
        if (slug === shownSlug) return;
        shownSlug = slug;
        stations.forEach(function (s) { s.classList.toggle('is-on', s.getAttribute('data-pick') === slug); });
        fits.forEach(function (fit) {
          var on = fit.getAttribute('data-fit-slug') === slug;
          fit.classList.toggle('is-active', on);
          if (on) { fit.removeAttribute('inert'); } else { fit.setAttribute('inert', ''); }
          var orb = fit.querySelector('.fit-orb');
          if (orb) orb.classList.toggle('is-live', on);
        });
        mark();
        refreshBar();
        document.dispatchEvent(new CustomEvent('listen:pricing-plan', { detail: { slug: slug } }));
      }

      // The bar at the bottom on phones: the plan that fits, its price and its own button.
      function refreshBar() {
        if (!bar) return;
        var fit = fitCard();
        if (!fit) return;
        var name = fit.querySelector('.fit-name');
        var price = fit.querySelector('.price-amount');
        var period = fit.querySelector('.price-period');
        var was = Array.prototype.filter.call(fit.querySelectorAll('.price-original'), function (el) {
          return getComputedStyle(el.parentNode).display !== 'none';
        })[0];
        var barPrice = document.getElementById('planBarPrice');
        document.getElementById('planBarName').textContent = name ? name.textContent.trim() : '';
        barPrice.textContent = '';
        if (price) {
          // The price in its own direction ("15.40 US$", never "$US 15.40"), "/month" after it.
          var now = document.createElement('span');
          var amount = document.createElement('span');
          now.className = 'plan-bar-now';
          amount.dir = 'ltr';
          amount.textContent = price.textContent.trim();
          now.appendChild(amount);
          if (period) now.appendChild(document.createTextNode(' ' + period.textContent.trim()));
          barPrice.appendChild(now);
        }
        barPrice.classList.toggle('has-offer', !!was);
        if (was && price) {
          var struck = document.createElement('s');
          struck.dir = 'ltr';
          struck.textContent = was.textContent.trim();
          barPrice.appendChild(struck);
        }
        var action = document.getElementById('planBarAction');
        action.innerHTML = '';
        var button = fit.querySelector('.plan-btn');
        if (button) {
          var copy = button.cloneNode(true);
          copy.removeAttribute('data-upgrade-link');
          copy.setAttribute('tabindex', '-1');
          // The bar is narrow: on yearly, the shorter "Get 20% off".
          var barYearly = copy.getAttribute('data-bar-yearly-label');
          if (barYearly && fit.closest('.pricing-page.is-yearly')) copy.textContent = barYearly;
          action.appendChild(copy);
        }
      }
      // Phones and tablets: the bar shows once the plans and the calculator are scrolled past, and
      // the chat button steps aside while the plans' rows and buttons are on screen.
      var seen = { plans: false, studio: false }, pending = 0;
      function syncBar() {
        pending = 0;
        var near = seen.plans || seen.studio;
        document.body.classList.toggle('plans-in-view', near && cardsReachEdge.matches);
        if (!bar || !studio) return;
        var show = !near && studio.getBoundingClientRect().bottom < 0;
        bar.classList.toggle('is-shown', show);
        document.body.classList.toggle('has-plan-bar', show && narrow.matches);
      }
      if ('IntersectionObserver' in window) {
        var watch = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) { seen[entry.target === studio ? 'studio' : 'plans'] = entry.isIntersecting; });
          syncBar();
        }, { rootMargin: '0px 0px -90px 0px' });
        if (plansSection) watch.observe(plansSection);
        if (studio) watch.observe(studio);
        // A jump back to the top passes nothing on the way, so the scroll itself is checked too
        // (once a frame at most).
        window.addEventListener('scroll', function () { if (!pending) pending = requestAnimationFrame(syncBar); }, { passive: true });
      }
      // A new billing cycle: the bar follows.
      ['monthlyBtn', 'yearlyBtn'].forEach(function (id) {
        var button = document.getElementById(id);
        if (button) button.addEventListener('click', refreshBar);
      });

      if (!studio) return;
      var plans = JSON.parse(studio.getAttribute('data-plans') || '[]');
      var t = JSON.parse(studio.getAttribute('data-i18n') || '{}');
      var fmt = function (n) { return Math.round(n).toLocaleString('en'); };
      var first = fitCard();
      shownSlug = first ? first.getAttribute('data-fit-slug') : null;
      stations.forEach(function (s) { s.classList.toggle('is-on', s.getAttribute('data-pick') === shownSlug); });
      refreshBar();

      var slider = document.getElementById('studioSlider');
      var input = document.getElementById('studioMinutes');
      var about = document.getElementById('studioAbout');
      var head = document.getElementById('studioHead');
      var wave = document.getElementById('studioWave');
      var svg = document.getElementById('studioBars');
      var edge = document.getElementById('studioFillEdge');
      var voicePaths = [svg.querySelector('.studio-bars-base'), svg.querySelector('.studio-bars-fill')];

      // Where minutes sit on the track (0 = its start, 1 = its end), and back. The first
      // plan sits right at the start (fewer minutes take a short lead-in before it), the
      // last near the end, and the plans in between are evenly spaced.
      var minM = Math.max(1, Math.floor(plans[0].minutes / 3));
      var maxM = plans[plans.length - 1].minutes;
      var anchors = [minM].concat(plans.map(function (p) { return p.minutes; }));
      var START = 0.06, END = 0.94, LEAD = 0.015, GAPS = anchors.length - 2;
      function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
      function between(m, lo, hi) { return clamp(Math.log(m / lo) / (Math.log(hi / lo) || 1), 0, 1); }
      function pos(m) {
        if (m <= anchors[1]) return LEAD + (START - LEAD) * between(Math.max(m, minM), minM, anchors[1]);
        for (var i = 2; i < anchors.length; i++) {
          if (m <= anchors[i]) return START + (END - START) * (i - 2 + between(m, anchors[i - 1], anchors[i])) / GAPS;
        }
        return END;
      }
      function minutesAt(f) {
        if (f <= START) return minM * Math.pow(anchors[1] / minM, clamp((f - LEAD) / (START - LEAD), 0, 1));
        var u = clamp((f - START) / (END - START), 0, 1) * GAPS;
        var i = Math.min(GAPS - 1, Math.floor(u));
        return anchors[i + 1] * Math.pow(anchors[i + 2] / anchors[i + 1], u - i);
      }
      // What the number shows at a point: a plan's own minutes on its stop, else a round
      // figure (1, 5, 10, 25 or 50 at a time as the minutes grow).
      function shownAt(f) {
        for (var i = 0; i < plans.length; i++) if (Math.abs(pos(plans[i].minutes) - f) < 0.0015) return plans[i].minutes;
        var m = minutesAt(f);
        var step = m < 30 ? 1 : m < 100 ? 5 : m < 500 ? 10 : m < 2000 ? 25 : 50;
        return clamp(Math.round(m / step) * step, minM, maxM);
      }
      function planFor(m) {
        return plans.filter(function (p) { return m <= p.minutes; })[0] || plans[plans.length - 1];
      }
      // The keys move one round figure at a time, stopping at every plan.
      var NICE = [1, 2, 3, 5, 10, 15, 20, 30, 45, 60, 90, 100, 120, 150, 200, 250, 300, 400, 500, 600, 750, 1000, 1250, 1500, 1750, 2000, 2500, 3000, 4000, 5000, 7500, 10000, 15000, 20000, 25000, 50000];
      var stops = NICE.filter(function (n) { return n >= minM && n <= maxM; }).concat(anchors)
        .filter(function (n, i, list) { return list.indexOf(n) === i; })
        .sort(function (a, b) { return a - b; });
      slider.max = String(stops.length - 1);
      studio.style.setProperty('--track-from', (LEAD * 100) + '%');
      studio.style.setProperty('--track-to', ((1 - END) * 100) + '%');

      // The plans as orbs along the track.
      stations.forEach(function (station) {
        var plan = plans.filter(function (p) { return p.slug === station.getAttribute('data-pick'); })[0];
        if (plan) station.style.left = ((rtl ? 1 - pos(plan.minutes) : pos(plan.minutes)) * 100) + '%';
      });

      // The voice: words of a few syllables each and the short pauses between them, the
      // same every time. It is drawn in whole pixels from the lowest point the playhead
      // can reach to the highest, so it is empty at the start and full at the last plan.
      var voice = (function () {
        var seed = 11, out = [];
        function rnd() { seed = (seed * 16807) % 2147483647; return (seed - 1) / 2147483646; }
        while (out.length < 400) {
          var len = 4 + Math.floor(rnd() * 9), loud = 0.55 + rnd() * 0.45, syllables = 1 + Math.floor(rnd() * 3);
          for (var j = 0; j < len; j++) {
            var k = (j + 0.5) / len;
            var shape = Math.pow(Math.sin(Math.PI * k), 0.7) * (0.6 + 0.4 * Math.abs(Math.sin(Math.PI * syllables * k)));
            out.push(Math.max(0.07, loud * shape * (0.82 + rnd() * 0.36)));
          }
          var pause = rnd() < 0.2 ? 3 + Math.floor(rnd() * 2) : 1 + Math.floor(rnd() * 2);
          for (var g = 0; g < pause; g++) out.push(0.04);
        }
        return out;
      })();
      var W = 0, H = 0;
      function drawVoice() {
        var pitch = W < 520 ? 5 : 6, bar = pitch / 2, from = LEAD * W, span = (END - LEAD) * W;
        var n = Math.max(1, Math.floor((span - bar) / pitch) + 1);
        var x0 = from + (span - ((n - 1) * pitch + bar)) / 2 + bar / 2, mid = H / 2, d = '';
        for (var i = 0; i < n; i++) {
          var h = Math.max(0.5, voice[i % voice.length] * (H * 0.84 - bar)) / 2;
          var x = (x0 + i * pitch).toFixed(1);
          d += 'M' + x + ' ' + (mid - h).toFixed(1) + 'V' + (mid + h).toFixed(1);
        }
        svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
        edge.setAttribute('height', String(H + 40));
        voicePaths.forEach(function (path) { path.setAttribute('d', d); path.setAttribute('stroke-width', String(bar)); });
      }
      function measure() {
        var w = wave.clientWidth, h = wave.clientHeight;
        if (!w || (w === W && h === H)) return;
        W = w; H = h;
        drawVoice();
        if (cur !== null) render();
      }

      function plural(list, n) {
        var i;
        if (rtl) {
          var m = n % 100;
          i = n === 0 ? 0 : n === 1 ? 1 : n === 2 ? 2 : (m >= 3 && m <= 10) ? 3 : (m >= 11 && m <= 99) ? 4 : 5;
        } else {
          i = n === 1 ? 0 : 1;
        }
        return String(list[Math.min(i, list.length - 1)] || '').replace(':count', fmt(n));
      }
      function duration(m) {
        var hours = Math.floor(m / 60), rest = m % 60;
        if (!hours) return plural(t.minutes, m);
        if (!rest) return plural(t.hours, hours);
        return t.hours_and_minutes.replace(':hours', plural(t.hours, hours)).replace(':minutes', plural(t.minutes, rest));
      }

      // The number box is as wide as its digits (where the browser cannot size it itself).
      var sizes = window.CSS && CSS.supports && CSS.supports('field-sizing', 'content');
      function fit() { if (!sizes) input.style.width = (String(input.value).length + 0.3) + 'ch'; }
      input.addEventListener('input', fit);

      var live = document.createElement('p');
      live.className = 'sr-only';
      live.setAttribute('aria-live', 'polite');
      live.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap';
      studio.appendChild(live);

      // The motion. cur is where the playhead is drawn, goal where it is going; each
      // frame closes part of the gap, which reads as a glide that slows into place.
      // A drag closes it almost at once, so the playhead stays under the hand.
      var cur = null, goal = 0, exact = null, lockedPlan = null, ease = 0.1;
      var frame = 0, last = 0, shownMinutes = null, announced = null, announce = 0;
      // The page's orbs keep still while the track moves, so every frame goes to the track.
      function still(on) { if (window.ListenOrbs && window.ListenOrbs.hold) window.ListenOrbs.hold(on); }
      function go(f, opts) {
        opts = opts || {};
        goal = clamp(f, LEAD, END);
        exact = opts.exact == null ? null : opts.exact;
        ease = reduced ? 0 : (opts.drag ? 0.018 : 0.1);
        // A glide shows where it is going straight away, not every plan it passes.
        lockedPlan = opts.drag ? null : planFor(exact != null ? exact : shownAt(goal)).slug;
        if (lockedPlan) showPlan(lockedPlan);
        if (cur === null || !ease) { cur = goal; cancelAnimationFrame(frame); frame = 0; render(); return; }
        if (!frame) { last = 0; still(true); frame = requestAnimationFrame(tick); }
      }
      function tick(now) {
        var dt = last ? Math.min(0.05, (now - last) / 1000) : 1 / 60;
        last = now;
        cur += (goal - cur) * (1 - Math.exp(-dt / ease));
        if (Math.abs(goal - cur) * W < 0.25) cur = goal;
        render();
        frame = cur === goal ? 0 : requestAnimationFrame(tick);
        if (!frame && !(press && press.live)) still(false);
      }
      function render() {
        var x = cur * W;
        // The position on its own property, so growing the playhead while dragged never moves it.
        head.style.translate = (rtl ? W - x : x).toFixed(2) + 'px 0';
        edge.setAttribute('width', x.toFixed(2));
        var settled = cur === goal;
        var m = settled && exact != null ? exact : shownAt(cur);
        if (m !== shownMinutes) {
          shownMinutes = m;
          if (document.activeElement !== input) { input.value = fmt(m); fit(); }
          // Only once it adds something: in hours (under an hour it would repeat the number).
          var text = m >= 60 ? t.about.replace(':time', duration(m)) : '';
          if (about.textContent !== text) about.textContent = text;
        }
        if (!lockedPlan) showPlan(planFor(m).slug);
        if (settled) settle(m);
      }
      // At rest: the slider's own value for keys and screen readers, said once.
      function settle(m) {
        lockedPlan = null;
        var k = 0;
        stops.forEach(function (s, i) { if (Math.abs(s - m) < Math.abs(stops[k] - m)) k = i; });
        slider.value = String(k);
        var sentence = t.live.replace(':minutes', fmt(m)).replace(':plan', planFor(m).name);
        slider.setAttribute('aria-valuetext', sentence);
        if (announced === sentence) return;
        announced = sentence;
        clearTimeout(announce);
        announce = setTimeout(function () { live.textContent = sentence; }, 500);
      }
      function heading() { return exact != null ? exact : shownAt(goal); }

      // Keys: one round figure per arrow (left is more in Arabic, where the track runs
      // right to left), a whole plan per Page Up / Page Down, the ends with Home / End.
      slider.addEventListener('keydown', function (e) {
        var now = heading(), next;
        var more = e.key === 'ArrowUp' || e.key === (rtl ? 'ArrowLeft' : 'ArrowRight');
        var less = e.key === 'ArrowDown' || e.key === (rtl ? 'ArrowRight' : 'ArrowLeft');
        var marks = plans.map(function (p) { return p.minutes; });
        if (more) next = stops.filter(function (s) { return s > now; })[0];
        else if (less) next = stops.filter(function (s) { return s < now; }).pop();
        else if (e.key === 'PageUp') next = marks.filter(function (s) { return s > now; })[0];
        else if (e.key === 'PageDown') next = marks.filter(function (s) { return s < now; }).pop();
        else if (e.key === 'Home') next = minM;
        else if (e.key === 'End') next = maxM;
        else return;
        e.preventDefault();
        use();
        if (next != null) go(pos(next), { exact: next });
      });
      // Assistive technology may set the value itself.
      slider.addEventListener('input', function () {
        var m = stops[+slider.value];
        use();
        if (m != null) go(pos(m), { exact: m });
      });

      // Pointer. A mouse acts at once; a finger only once it clearly moves sideways (or
      // taps), so scrolling the page over the track never changes the plan.
      var DETENT = 0.022, press = null, box = null;
      function toward(clientX, drag) {
        var f = (clientX - box.left) / box.width;
        if (rtl) f = 1 - f;
        f = clamp(f, LEAD, END);
        var near = null;
        plans.forEach(function (p) {
          var d = Math.abs(pos(p.minutes) - f);
          if (d < DETENT && (!near || d < near.d)) near = { d: d, minutes: p.minutes };
        });
        if (near) go(pos(near.minutes), { exact: near.minutes, drag: drag });
        else go(f, { drag: drag });
      }
      function begin(e) {
        press.live = true;
        try { wave.setPointerCapture(e.pointerId); } catch (err) {}
        studio.classList.add('is-dragging');
        still(true);
        slider.focus({ preventScroll: true });
        use();
        toward(e.clientX, false);
      }
      function end(e) {
        if (!press || e.pointerId !== press.id) return;
        var tap = !press.live && e.type === 'pointerup';
        if (press.live) {
          studio.classList.remove('is-dragging');
          if (!frame) still(false);
        }
        press = null;
        if (tap) { slider.focus({ preventScroll: true }); use(); toward(e.clientX, false); }
      }
      wave.addEventListener('pointerdown', function (e) {
        if (e.button > 0 || press) return;
        box = wave.getBoundingClientRect();
        press = { id: e.pointerId, x: e.clientX, y: e.clientY, live: false };
        if (e.pointerType === 'mouse') { e.preventDefault(); begin(e); }
      });
      wave.addEventListener('pointermove', function (e) {
        if (!press || e.pointerId !== press.id) return;
        if (!press.live) {
          var dx = Math.abs(e.clientX - press.x), dy = Math.abs(e.clientY - press.y);
          if (dx > 6 && dx > dy) { begin(e); } else if (dy > 10) { press = null; }
          return;
        }
        toward(e.clientX, true);
      });
      wave.addEventListener('pointerup', end);
      wave.addEventListener('pointercancel', end);
      // Only the track's own capture ending counts (a finger's first capture is on the
      // shape it touched, and moving it to the track ends that one).
      wave.addEventListener('lostpointercapture', function (e) { if (e.target === wave) end(e); });

      // Typing a number (Arabic or Western digits).
      function typed() {
        var digits = String(input.value).replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).replace(/[^\d]/g, '');
        if (digits === '') { input.value = fmt(shownMinutes); fit(); return; }
        var m = clamp(+digits, minM, maxM);
        input.value = fmt(m);
        fit();
        use();
        go(pos(m), { exact: m });
      }
      input.addEventListener('change', typed);
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); typed(); } });
      input.addEventListener('blur', function () { input.value = fmt(shownMinutes); fit(); });

      // A plan's orb on the track moves the track to it.
      stations.forEach(function (station) {
        station.addEventListener('click', function () {
          var plan = plans.filter(function (q) { return q.slug === station.getAttribute('data-pick'); })[0];
          use();
          if (plan) go(pos(plan.minutes), { exact: plan.minutes });
        });
      });

      measure();
      if ('ResizeObserver' in window) new ResizeObserver(measure).observe(wave);
      else window.addEventListener('resize', measure);
      var start = +String(input.value).replace(/[^\d]/g, '') || plans[0].minutes;
      go(pos(start), { exact: start });
      clearTimeout(announce); // said only once the visitor moves it
      // The voice rises once, when the track comes into view.
      if ('IntersectionObserver' in window && !reduced) {
        studio.classList.add('is-waiting');
        var rise = new IntersectionObserver(function (entries) {
          if (!entries.some(function (entry) { return entry.isIntersecting; })) return;
          rise.disconnect();
          studio.classList.remove('is-waiting');
          studio.classList.add('is-ready');
        }, { threshold: 0.35 });
        rise.observe(wave);
      }
    })();
  </script>
  <script>
    /* ── Phones: two plans side by side in the comparison ──
       The plan the calculator shows and the next one up, until the visitor picks their own two
       above the table. The other plans' columns are left out, so the table fits the screen. */
    (function () {
      var table = document.querySelector('.compare-table[data-pick]');
      var picks = table ? table.querySelectorAll('.compare-pick') : [];
      if (picks.length < 2) return;
      var wrap = table.closest('.compare-scroll');
      var heads = Array.prototype.slice.call(table.querySelectorAll('thead th[data-col]'));
      var slugs = heads.map(function (th) { return th.getAttribute('data-slug'); });
      var rows = Array.prototype.slice.call(table.querySelectorAll('tr')).filter(function (row) { return row.querySelector('[data-col]'); });
      var groups = table.querySelectorAll('th.compare-group');
      var phone = window.matchMedia('(max-width: 640px)');
      var chosen = table.getAttribute('data-pick').split(',').map(Number), touched = false, moved = false, wait = 0;
      function rank(cell) {
        var c = +cell.getAttribute('data-col'), k = chosen.indexOf(c);
        return !phone.matches ? c : (k < 0 ? 2 + c : k);
      }
      function apply() {
        var on = phone.matches;
        Array.prototype.forEach.call(picks, function (select, side) {
          select.value = String(chosen[side]);
          var face = select.nextElementSibling;
          if (face) face.textContent = select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : '';
        });
        table.classList.toggle('is-picked', on);
        if (wrap) wrap.classList.toggle('is-picked', on);
        if (!on && !moved) return;
        moved = on;
        rows.forEach(function (row) {
          Array.prototype.slice.call(row.querySelectorAll('[data-col]'))
            .sort(function (a, b) { return rank(a) - rank(b); })
            .forEach(function (cell) {
              row.appendChild(cell);
              cell.classList.toggle('is-off', on && chosen.indexOf(+cell.getAttribute('data-col')) < 0);
            });
        });
        Array.prototype.forEach.call(groups, function (g) { g.colSpan = on ? 3 : heads.length + 1; });
      }
      Array.prototype.forEach.call(picks, function (select, side) {
        select.addEventListener('change', function () {
          var value = +select.value;
          // Picking the plan the other side shows swaps the two.
          if (value === chosen[1 - side]) chosen[1 - side] = chosen[side];
          chosen[side] = value;
          touched = true;
          apply();
        });
      });
      // Until then it follows the track (once the track rests, not on every plan it passes).
      document.addEventListener('listen:pricing-plan', function (e) {
        var i = slugs.indexOf(e.detail && e.detail.slug);
        if (touched || i < 0) return;
        chosen = [i, i + 1 < slugs.length ? i + 1 : Math.max(0, i - 1)];
        clearTimeout(wait);
        wait = setTimeout(apply, 400);
      });
      if (phone.addEventListener) phone.addEventListener('change', apply); else if (phone.addListener) phone.addListener(apply);
      apply();
    })();
  </script>
</body>
</html>
