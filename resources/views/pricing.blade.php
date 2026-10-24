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

    /* The title on the page's own background, short, so the minutes track and the
       plan's button fit on the first screen. */
    .page-hero {
      text-align: center;
      padding: 2.4rem 1rem 1.8rem;
    }
    /* One line, its second half in the brand's violet, as on the home page. */
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
    .page-hero h1 .accent { color: var(--brand-accent); }
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

    /* ── The minutes track and the plan that fits ──
       One card: on one side the visitor picks how many minutes of voice they need on
       a voice track (the plans sit on it as orbs); on the other, the plan that fits,
       which is that plan's own card, as the admin set it up. Moving between plans is
       one smooth motion: the playhead glides, the bars fill in a sweep, the number
       counts, and the plan's card fades across. */
    .studio {
      --ease-out: cubic-bezier(.22, 1, .36, 1);
      display: grid;
      grid-template-columns: minmax(0, 1.4fr) minmax(0, .95fr);
      max-width: 1240px;
      margin: 0 auto;
      overflow: clip;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 28px;
      box-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 40px 80px -42px color-mix(in srgb, var(--brand-accent) 40%, transparent);
    }
    .studio-mixer { display: flex; flex-direction: column; padding: clamp(1.4rem, 3vw, 2.4rem); }
    /* Wide screens: the track stays beside the plan's details while they scroll by. */
    @media (min-width: 1001px) {
      .studio-mixer { position: sticky; top: 84px; align-self: start; }
    }
    .studio-top { display: flex; justify-content: space-between; align-items: center; gap: .75rem 1rem; flex-wrap: wrap; }
    .studio-label { color: var(--muted); font-size: .95rem; font-weight: 500; }
    .studio .billing-toggle button { min-height: 38px; padding: 0 1rem; font-size: .88rem; }
    .studio-amount { display: flex; align-items: baseline; flex-wrap: wrap; gap: .35rem .55rem; margin-top: 1.1rem; }
    /* The number box is as wide as its digits, so the unit sits right after them. */
    .studio-amount input {
      field-sizing: content;
      width: auto;
      min-width: 1ch;
      padding: 0;
      border: 0;
      background: none;
      color: var(--text);
      font: inherit;
      font-size: clamp(3.4rem, 6.6vw, 5.2rem);
      font-weight: 700;
      line-height: 1;
      letter-spacing: -.02em;
      font-variant-numeric: tabular-nums;
      -moz-appearance: textfield;
    }
    .studio-amount input:focus-visible { outline: none; text-decoration: underline 3px var(--brand-accent); text-underline-offset: 10px; }
    .studio-unit { font-size: 1.25rem; font-weight: 600; }
    .studio-about { color: var(--muted); font-size: .98rem; margin-inline-start: .45rem; }

    /* The track: a voice waveform on the card itself, with a round playhead, and the
       plans as stops on a thin timeline under it. */
    .studio-track { position: relative; margin-top: 1.9rem; }
    .studio-wave { position: relative; height: 104px; }
    .studio-wave svg { display: block; width: 100%; height: 100%; overflow: visible; }
    html[dir="rtl"] .studio-wave svg { transform: scaleX(-1); }
    .studio-wave rect {
      fill: color-mix(in srgb, var(--muted) 32%, transparent);
      transform-box: fill-box;
      transform-origin: center;
      transition: fill .4s var(--ease-out);
    }
    .studio-wave rect.on { fill: var(--brand-accent); }
    .studio-wave input {
      position: absolute;
      inset: -8px 0;
      width: 100%;
      height: calc(100% + 16px);
      margin: 0;
      opacity: 0;
      cursor: ew-resize;
    }
    .studio-head {
      position: absolute;
      top: 50%;
      left: 0;
      width: 30px;
      height: 30px;
      margin: -15px 0 0 -15px;
      border-radius: 50%;
      background: var(--brand-accent);
      pointer-events: none;
      box-shadow: 0 0 0 5px var(--surface), 0 0 0 6px color-mix(in srgb, var(--brand-accent) 30%, transparent), 0 10px 22px -6px var(--brand-accent);
      transition: left .5s var(--ease-out), transform .2s ease;
    }
    .studio-head::after { content: ""; position: absolute; inset: 10px; border-radius: 50%; background: #fff; }
    .studio.is-dragging .studio-head { transition: left .14s ease-out, transform .2s ease; transform: scale(1.12); }
    .studio-wave input:focus-visible ~ .studio-head { box-shadow: 0 0 0 5px var(--surface), 0 0 0 8px var(--brand-accent); }
    .studio-stations { position: relative; height: 54px; margin-top: 10px; }
    .studio-stations::before {
      content: "";
      position: absolute;
      top: 17px;
      left: 6%;
      right: 6%;
      height: 2px;
      border-radius: 2px;
      background: linear-gradient(90deg, transparent, var(--line) 8%, var(--line) 92%, transparent);
    }
    .studio-station {
      position: absolute;
      top: 6px;
      display: grid;
      justify-items: center;
      gap: 4px;
      padding: 0;
      border: 0;
      background: none;
      color: var(--muted);
      font: inherit;
      cursor: pointer;
      translate: -50% 0;
      transition: color .3s ease;
    }
    .studio-station .orb { --orb-size: 22px; box-shadow: 0 0 0 4px var(--surface); transition: transform .45s cubic-bezier(.34, 1.56, .64, 1); }
    .studio-station-name { font-size: .78rem; line-height: 1.25; white-space: nowrap; text-align: center; }
    .studio-station:hover { color: var(--text); }
    .studio-station.is-on { color: var(--text); font-weight: 600; }
    .studio-station.is-on .orb { transform: scale(1.4); }
    .studio-station-crown { position: absolute; top: -13px; left: 50%; width: 14px; height: 14px; translate: -50% 0; transition: top .45s cubic-bezier(.34, 1.56, .64, 1); }
    .studio-station.is-on .studio-station-crown { top: -18px; }

    /* The plan that fits: the plan's own card, one at a time, fading across. */
    .studio-plan {
      position: relative;
      overflow: clip;
      padding: clamp(1.3rem, 2.4vw, 1.9rem) clamp(1.3rem, 3vw, 2.4rem);
      background:
        radial-gradient(70% 50% at 50% 0%, color-mix(in srgb, var(--brand-accent) 13%, transparent), transparent 72%),
        color-mix(in srgb, var(--brand-accent) 5%, var(--surface));
      border-inline-start: 1px solid var(--line);
    }
    .plan-stage { display: grid; }
    .plan-card {
      grid-area: 1 / 1;
      position: relative;
      display: flex;
      flex-direction: column;
      opacity: 0;
      visibility: hidden;
      transform: translateY(12px) scale(.985);
      transition: opacity .45s var(--ease-out), transform .55s var(--ease-out), visibility 0s linear .45s;
    }
    .plan-card.is-active { opacity: 1; visibility: visible; transform: none; transition-delay: .08s, .08s, 0s; }
    .plan-top { display: flex; flex-direction: column; align-items: center; text-align: center; }
    .plan-badges { display: flex; flex-direction: column; align-items: center; gap: .55rem; margin-bottom: .55rem; }
    .plan-orb { --orb-size: 84px; box-shadow: 0 22px 40px -22px color-mix(in srgb, var(--o0) 75%, transparent); }
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
    .plan-fit { color: color-mix(in oklab, var(--ok) 60%, var(--text)); font-size: .88rem; font-weight: 600; }
    .plan-name { margin: .1rem 0 0; font-size: 1.85rem; font-weight: 700; letter-spacing: -.02em; }
    html[dir="rtl"] .plan-name { letter-spacing: 0; }
    .plan-desc { margin: .35rem auto 0; max-width: 34ch; color: var(--muted); font-size: .92rem; line-height: 1.6; }

    /* The price, the old price struck beside it, then the offer in one line. */
    .plan-pricing {
      display: grid;
      grid-template-columns: auto auto;
      justify-content: center;
      align-items: baseline;
      gap: .3rem .6rem;
      padding: .9rem 0 1rem;
      text-align: center;
    }
    .plan-pricing > * { grid-column: 1 / -1; justify-self: center; }
    .plan-pricing > .price-row { grid-column: 1; grid-row: 1; }
    .plan-pricing > .price-original-row { grid-column: 2; grid-row: 1; }
    .price-original-row { display: inline-flex; align-items: baseline; }
    .price-original { color: var(--muted); font-size: 1.05rem; font-weight: 500; text-decoration: line-through; text-decoration-thickness: 1.5px; }
    /* The line under the price already says how much is off. */
    .price-off { display: none; }
    .price-row { display: flex; align-items: baseline; justify-content: center; gap: .35rem; }
    .price-amount { font-size: clamp(2.5rem, 3.4vw, 3rem); font-weight: 700; line-height: 1; letter-spacing: -.03em; font-variant-numeric: tabular-nums; }
    .price-period { color: var(--muted); font-size: .95rem; font-weight: 500; }
    /* The price changes with a short lift when the billing cycle changes. */
    .price-amount.is-changing { animation: price-in .45s var(--ease-out); }
    @keyframes price-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    .price-note { color: var(--muted); font-size: .86rem; }
    .price-note:empty { display: none; }
    .price-note.savings { color: color-mix(in oklab, var(--ok) 50%, var(--text)); font-weight: 600; }

    /* The offer as one sentence ("10% off your first month. Then EGP 1,218/month"),
       and when it ends, small, under it. */
    .discount-terms { display: flex; flex-wrap: wrap; justify-content: center; max-width: 38ch; margin-top: .15rem; font-size: .88rem; line-height: 1.6; }
    .discount-terms-offer { color: var(--accent-text); font-weight: 650; }
    .discount-terms-then { color: var(--muted); }
    .discount-terms-then::before { content: ".\00a0"; color: var(--muted); }
    html[dir="rtl"] .discount-terms-then::before { content: "،\00a0"; }
    .discount-terms-then[hidden] { display: none; }
    /* When the offer ends: the day, then the time left to it, counting down. */
    .offer-ends { display: flex; flex-basis: 100%; flex-direction: column; align-items: center; gap: .45rem; margin-top: .65rem; }
    .offer-ends[hidden] { display: none; }
    .offer-ends-when { display: inline-flex; align-items: center; gap: .4rem; color: var(--text); font-size: .84rem; font-weight: 600; }
    .offer-ends-when svg { flex: none; width: 15px; height: 15px; fill: none; stroke: var(--accent-text); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .offer-ends-clock { display: inline-flex; gap: .35rem; direction: ltr; }
    html[dir="rtl"] .offer-ends-clock { direction: rtl; }
    .offer-ends-clock:empty { display: none; }
    .offer-ends-unit {
      display: grid;
      justify-items: center;
      min-width: 3.1rem;
      padding: .3rem .45rem .25rem;
      border-radius: 10px;
      background: color-mix(in srgb, var(--brand-accent) 7%, var(--surface));
      border: 1px solid color-mix(in srgb, var(--brand-accent) 22%, transparent);
    }
    .offer-ends-unit b { font-size: 1rem; font-weight: 700; line-height: 1.15; font-variant-numeric: tabular-nums; color: var(--accent-text); }
    .offer-ends-unit small { font-size: .64rem; font-weight: 600; color: color-mix(in srgb, var(--accent-text) 55%, var(--muted)); }
    .offer-ends-text { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

    /* The most popular plan: a ribbon across the panel's corner, with a crown. */
    .plan-ribbon {
      position: absolute;
      top: 26px;
      inset-inline-end: -46px;
      z-index: 2;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: .35rem;
      width: 196px;
      padding: .42rem 0;
      background: linear-gradient(90deg, #5B3CF0, #8B5CF6 55%, #B45CE6);
      color: #fff;
      font-size: .78rem;
      font-weight: 700;
      box-shadow: 0 8px 18px -8px rgba(76, 45, 235, .65);
      transform: rotate(45deg);
      transition: opacity .35s ease, transform .45s cubic-bezier(.22, 1, .36, 1);
    }
    html[dir="rtl"] .plan-ribbon { transform: rotate(-45deg); }
    .plan-ribbon[hidden] { display: flex; opacity: 0; transform: rotate(45deg) translateY(-14px); pointer-events: none; }
    html[dir="rtl"] .plan-ribbon[hidden] { transform: rotate(-45deg) translateY(-14px); }
    .crown { flex: none; width: 15px; height: 15px; fill: #FFD36B; stroke: #E8A41F; stroke-width: 1.2; stroke-linejoin: round; }
    /* Its own small badge is not needed in the panel: the ribbon says it. */
    .plan-card .popular-badge { display: none; }

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
    .studio.is-yearly [data-trial-monthly-only],
    .studio:not(.is-yearly) [data-trial-yearly-only] {
      display: none !important;
    }

    .plan-action { padding-bottom: 1.2rem; }
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
    /* In the panel the shown plan's button is the page's main action: always filled. */
    .plan-card .plan-btn { min-height: 52px; font-size: 1.02rem; }
    .plan-card .plan-btn.btn-outline { background: var(--brand-accent); color: #fff; border-color: transparent; box-shadow: var(--lift); }
    .plan-card .plan-btn:not(.btn-disabled):hover { background: var(--brand-hover); transform: translateY(-1px); }

    /* Minutes and the credits behind them, on one line. */
    .plan-quota {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      align-items: baseline;
      gap: .4rem 1rem;
      padding: 1rem 0;
      border-top: 1px solid color-mix(in srgb, var(--brand-accent) 14%, var(--line));
      font-size: .9rem;
      font-weight: 600;
    }
    .plan-minutes { display: flex; align-items: baseline; flex-wrap: wrap; gap: .1rem .4rem; }
    .plan-minutes-number { font-size: 1.25rem; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }
    .plan-minutes-unit { color: var(--muted); font-size: .84rem; font-weight: 500; }
    .plan-quota-credits { display: inline-flex; align-items: center; gap: .4rem; color: var(--muted); font-size: .82rem; font-weight: 500; cursor: help; }
    .plan-quota-coin { font-size: 1rem; flex: none; }
    .plan-quota-amount { display: inline-flex; flex-wrap: wrap; align-items: baseline; gap: .25rem; }
    .plan-quota-number { color: var(--text); font-weight: 700; font-variant-numeric: tabular-nums; }

    /* The plan's lines: the label on one side, its value on the other. */
    .plan-features {
      list-style: none;
      margin: 0;
      padding: 1rem 0 0;
      border-top: 1px solid color-mix(in srgb, var(--brand-accent) 14%, var(--line));
      display: flex;
      flex-direction: column;
      gap: .65rem;
    }
    .plan-features li { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; font-size: .88rem; }
    .feature-label { display: inline-flex; align-items: flex-start; flex: 1 1 auto; min-width: 0; gap: .5rem; color: var(--muted); line-height: 1.4; }
    .feature-label svg { flex: none; width: 1rem; height: 1rem; margin-top: .12rem; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .feature-value { flex: none; max-width: 55%; color: var(--text); font-weight: 650; line-height: 1.4; text-align: end; }
    .plan-features li.is-tick .feature-label { color: var(--text); font-weight: 600; }
    .plan-features li.is-tick .feature-label svg { color: var(--ok); stroke-width: 2.6; }

    /* At the foot of the track: the voices every plan has. Under the card: every plan to pick from. */
    .studio-proof { display: flex; align-items: center; gap: .8rem; margin: 1.4rem 0 0; color: var(--muted); font-size: .9rem; }
    .studio-faces { display: flex; flex: none; }
    .studio-faces img { width: 32px; height: 32px; border-radius: 50%; border: 2px solid var(--surface); margin-inline-start: -9px; background: var(--surface2); box-shadow: 0 2px 8px rgba(15, 23, 42, .12); }
    .studio-faces img:first-child { margin-inline-start: 0; }
    .studio-proof { flex-wrap: nowrap; }
    .studio-faces img:nth-child(n+16) { display: none; }
    .studio-proof > :last-child { white-space: nowrap; }
    @media (max-width: 1360px) { .studio-faces img:nth-child(n+14) { display: none; } }
    @media (max-width: 640px) { .studio-proof > :last-child { white-space: normal; } }
    @media (max-width: 1200px) { .studio-faces img:nth-child(n+11) { display: none; } }
    @media (max-width: 640px) { .studio-faces img:nth-child(n+7) { display: none; } }
    .plan-picker { max-width: 1200px; margin: 0 auto; padding-top: clamp(3rem, 6vw, 4.5rem); }
    .plan-picker-list { display: grid; grid-template-columns: repeat(var(--count, 5), minmax(0, 1fr)); gap: 14px; }
    .plan-pick {
      display: grid;
      justify-items: center;
      gap: .2rem;
      padding: 1.4rem 1rem 1.25rem;
      border-radius: 22px;
      border: 1px solid var(--line);
      background: var(--surface);
      color: inherit;
      font: inherit;
      text-align: center;
      cursor: pointer;
      transition: border-color .3s ease, box-shadow .3s ease, transform .3s var(--ease-out, ease);
    }
    .plan-pick:hover { transform: translateY(-3px); box-shadow: 0 18px 36px -24px color-mix(in srgb, var(--brand-accent) 55%, transparent); }
    .plan-pick:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 3px; }
    .plan-pick.is-on { border-color: var(--brand-accent); box-shadow: 0 0 0 1px var(--brand-accent), 0 18px 36px -22px color-mix(in srgb, var(--brand-accent) 70%, transparent); }
    .plan-pick { position: relative; }
    .plan-pick-crown {
      position: absolute;
      top: -12px;
      left: 50%;
      translate: -50% 0;
      display: inline-flex;
      align-items: center;
      gap: .3rem;
      padding: .22rem .7rem;
      border-radius: var(--radius-full);
      background: linear-gradient(90deg, #5B3CF0, #8B5CF6 55%, #B45CE6);
      color: #fff;
      font-size: .72rem;
      font-weight: 700;
      white-space: nowrap;
      box-shadow: 0 6px 14px -6px rgba(76, 45, 235, .6);
    }
    .plan-pick-crown .crown { width: 13px; height: 13px; }
    .plan-pick .orb { --orb-size: 60px; margin-bottom: .6rem; }
    .plan-pick-name { font-size: 1.08rem; font-weight: 700; }
    .plan-pick-minutes { color: var(--muted); font-size: .88rem; }
    .plan-pick-price { margin-top: .55rem; font-size: 1.2rem; font-weight: 700; font-variant-numeric: tabular-nums; }
    .plan-pick-price small { color: var(--muted); font-size: .8rem; font-weight: 500; margin-inline-start: .2rem; }

    /* Phones: the shown plan and its button stay in reach at the bottom. */
    .plan-bar { display: none; }

    /* One entrance: the waveform rises bar by bar, once. */
    @keyframes bar-rise { from { transform: scaleY(.08); opacity: .3; } to { transform: none; opacity: 1; } }
    .studio.is-ready .studio-wave rect { animation: bar-rise .7s var(--ease-out) both; animation-delay: calc(var(--i, 0) * 6ms); }
    @media (prefers-reduced-motion: reduce) {
      .studio *, .plan-card, .plan-pick, .plan-bar { transition: none !important; animation: none !important; }
    }

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

    .reveal { opacity: 0; transform: translateY(14px); transition: opacity .5s ease, transform .5s ease; }
    .reveal.show { opacity: 1; transform: translateY(0); }
    @media (prefers-reduced-motion: reduce) {
      .reveal { transition: none; transform: none; }
    }

    /* ── Smaller screens ── */
    @media (max-width: 1000px) {
      .studio { grid-template-columns: 1fr; }
      .studio-plan { border-inline-start: 0; border-top: 1px solid var(--line); }
      .plan-picker-list { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
    }
    @media (max-width: 640px) {
      .studio { border-radius: 22px; }
      .studio-station-name { font-size: .7rem; }
      .studio-station:not(.is-on) .studio-station-name { visibility: hidden; }
      .plan-picker-list { grid-template-columns: 1fr; gap: 10px; }
      .plan-pick { grid-template-columns: auto 1fr auto; justify-items: start; column-gap: 1rem; padding: 1rem 1.1rem; text-align: start; }
      .plan-pick .orb { --orb-size: 44px; grid-row: span 2; margin: 0; }
      .plan-pick-price { grid-column: 3; grid-row: 1 / span 2; align-self: center; margin: 0; }
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
        transition: transform .35s cubic-bezier(.22, 1, .36, 1);
      }
      .plan-bar.is-shown { transform: none; }
      .plan-bar-name { display: block; font-weight: 700; }
      .plan-bar-price { color: var(--muted); font-size: .86rem; }
      .plan-bar .plan-btn { width: auto; min-height: 44px; padding: .5rem 1.3rem; background: var(--brand-accent); color: #fff; border-color: transparent; }
      .plan-bar .plan-btn.btn-disabled { background: var(--surface2); color: var(--muted); }
      /* The chat button moves up while the bar is shown, so it never covers the button. */
      body.has-plan-bar .wa-widget { bottom: calc(84px + env(safe-area-inset-bottom)) !important; transition: bottom .35s ease; }
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
      <h1>{{ __('ui.pricing.hero_title') }} <span class="accent">{{ __('ui.pricing.hero_title_accent') }}</span></h1>
      <p class="lead">{{ __('ui.pricing.hero_subtitle') }}</p>
    </section>

    @php
      $planCount = min(6, max(1, count($mainPlans)));
      // The plans on the minutes track, fewest minutes first.
      $trackPlans = collect($mainPlans)
        ->map(fn ($p, $i) => ['slug' => (string) $p['slug'], 'name' => $planDisplayName($p), 'minutes' => \App\Support\Credits::minutesFor((int) ($p['monthly_chars'] ?? 0)), 'popular' => $planState($p, $i)['is_popular']])
        ->filter(fn ($p) => $p['minutes'] > 0)
        ->sortBy('minutes')->values();
      // The plan shown first: the recommended one, else the member's own, else the first.
      $mainSlugList = array_column($mainPlans, 'slug');
      $shownSlug = (collect($mainPlans)->first(fn ($p, $i) => $planState($p, $i)['is_popular']) ?? [])['slug']
        ?? (in_array($activePlanSlug, $mainSlugList, true) ? $activePlanSlug : ($mainSlugList[0] ?? null));
      $shownPopular = (bool) ($trackPlans->firstWhere('slug', $shownSlug)['popular'] ?? false);
      $crownSvg = '<svg class="crown" viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 8.5l4.2 3.6L12 4.5l4.3 7.6 4.2-3.6-1.8 9H5.3z"/><rect x="5.3" y="18.6" width="13.4" height="2" rx="1"/></svg>';
      $shownMinutes = (int) ($trackPlans->firstWhere('slug', $shownSlug)['minutes'] ?? ($trackPlans->first()['minutes'] ?? 0));
      $studioI18n = [
        'about' => __('ui.pricing.minutes_about'),
        'hours' => explode('|', __('ui.pricing.hours_count')),
        'minutes' => explode('|', __('ui.pricing.minutes_count')),
        'hours_and_minutes' => __('ui.pricing.hours_and_minutes'),
        'live' => __('ui.pricing.minutes_live'),
      ];
      $proofFaces = ['ar-ahmed', 'ar-amal', 'en-amelia', 'ar-ali', 'ar-aisha', 'en-adrian', 'ar-layla', 'ar-omar', 'en-alice', 'ar-nour',
        'ar-khaled', 'ar-mariam', 'en-alexander', 'ar-salma', 'ar-youssef', 'en-anastasia', 'ar-hassan', 'ar-rania'];
    @endphp
    <section class="studio {{ $trackPlans->count() < 2 ? 'is-single' : '' }}" id="studio" aria-label="{{ __('ui.pricing.minutes_label') }}"
             data-plans="{{ json_encode($trackPlans) }}" data-i18n="{{ json_encode($studioI18n) }}">
      <div class="studio-mixer">
        <div class="studio-top">
          <label class="studio-label" for="studioMinutes">{{ __('ui.pricing.minutes_label') }}</label>
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
        @if($trackPlans->count() >= 2)
          <div class="studio-amount">
            <input id="studioMinutes" inputmode="numeric" autocomplete="off" value="{{ number_format($shownMinutes) }}" aria-describedby="studioAbout">
            <span class="studio-unit">{{ __('ui.pricing.minutes_unit') }}</span>
            <span class="studio-about" id="studioAbout"></span>
          </div>
          <div class="studio-track">
            <div class="studio-wave" id="studioWave">
              <svg id="studioBars" viewBox="0 0 1000 86" preserveAspectRatio="none" aria-hidden="true"></svg>
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
        @endif
        <p class="studio-proof">
          <span class="studio-faces" aria-hidden="true">@foreach($proofFaces as $face)<img src="{{ asset('images/speakers/'.$face.'.webp') }}" alt="" width="32" height="32" loading="lazy">@endforeach</span>
          <span>{{ __('ui.pricing.voices_proof', ['count' => \App\Support\DialectCounts::arabic() + \App\Support\DialectCounts::english()]) }}</span>
        </p>
      </div>
      <div class="studio-plan">
        <div class="plan-ribbon" id="planRibbon" @unless($shownPopular) hidden @endunless>{!! $crownSvg !!}{{ __('ui.pricing.most_popular') }}</div>
        <div class="plan-stage">
          @foreach($mainPlans as $index => $plan)
            @include('components._plan-card', ['plan' => $plan, 'index' => $index, 'isShown' => $plan['slug'] === $shownSlug])
          @endforeach
        </div>
      </div>
    </section>

    @if(count($mainPlans) > 1)
      <section class="plan-picker" aria-labelledby="planPickerTitle">
        <h2 class="section-title reveal" id="planPickerTitle">{{ __('ui.pricing.all_plans') }}</h2>
        <div class="plan-picker-list" style="--count: {{ min(5, count($mainPlans)) }}">
          @foreach($mainPlans as $index => $plan)
            @php
              $pickPopular = $planState($plan, $index)['is_popular'];
              $pickMonthly = $wholePrice((string) ($plan['display_price_monthly_formatted'] ?? ''));
              $pickYearly = $wholePrice((string) ($plan['display_price_yearly_monthly_formatted'] ?? $pickMonthly));
              $pickMinutes = \App\Support\Credits::minutesFor((int) ($plan['monthly_chars'] ?? 0));
            @endphp
            <button type="button" class="plan-pick" data-pick="{{ $plan['slug'] }}" aria-controls="studio">
              @if($pickPopular)<span class="plan-pick-crown">{!! $crownSvg !!}{{ __('ui.pricing.most_popular') }}</span>@endif
              <x-orb :name="\App\Support\Orbs::forPlan((string) $plan['slug'])" />
              <span class="plan-pick-name">{{ $planDisplayName($plan) }}</span>
              <span class="plan-pick-minutes">{{ trans_choice('ui.pricing.minutes_count', $pickMinutes, ['count' => number_format($pickMinutes)]) }}</span>
              <span class="plan-pick-price"><span dir="ltr" data-monthly="{{ $pickMonthly }}" data-yearly="{{ $pickYearly }}" data-compare-price>{{ $pickMonthly }}</span><small>{{ __('ui.pricing.per_month_short') }}</small></span>
            </button>
          @endforeach
        </div>
      </section>
    @endif

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

  {{-- Phones: the plan shown above and its button, kept at the bottom while the panel is out of view. --}}
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
        document.querySelectorAll('.studio').forEach(function(el) {
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
      var units = { on: @json(__('ui.pricing.offer_ends_on')), list: @json(explode('|', __('ui.pricing.countdown_units'))) };
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
      // The day it ends, in the visitor's own time zone and language.
      var dateFormat = new Intl.DateTimeFormat(arabic ? 'ar-EG-u-nu-latn' : 'en-GB', { weekday: 'long', day: 'numeric', month: 'long' });
      lines.forEach(function(el) {
        var date = el.querySelector('.offer-ends-date');
        var at = new Date(el.getAttribute('data-ends'));
        if (date && !isNaN(at)) date.textContent = units.on.replace(':date', dateFormat.format(at));
      });
      // The time left, to that same moment: days, hours, minutes (hours, minutes, seconds on the last day).
      function pad(n) { return (n < 10 ? '0' : '') + n; }
      function clock(el, seconds) {
        var box = el.querySelector('.offer-ends-clock');
        if (!box) return;
        var d = Math.floor(seconds / 86400), h = Math.floor(seconds % 86400 / 3600), m = Math.floor(seconds % 3600 / 60), s = seconds % 60;
        var parts = d > 0 ? [[d, units.list[0]], [h, units.list[1]], [m, units.list[2]]] : [[h, units.list[1]], [m, units.list[2]], [s, units.list[3]]];
        var html = parts.map(function(p) { return '<span class="offer-ends-unit"><b>' + pad(p[0]) + '</b><small>' + p[1] + '</small></span>'; }).join('');
        if (box.innerHTML !== html) box.innerHTML = html;
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
          clock(el, seconds);
        });
        if (any) setTimeout(refresh, 1000);
      }
      refresh();
    })();
  </script>
  <script>
    /* ── The minutes track: pick minutes, see the plan that fits ──
       The plans sit on the track evenly spaced, the minutes between two plans on a
       log scale, so 15 and 2,500 minutes both fit. The playhead moves between round
       stops; the plan shown is the smallest one that covers the minutes chosen. */
    (function () {
      var studio = document.getElementById('studio');
      if (!studio) return;
      var plans = JSON.parse(studio.getAttribute('data-plans') || '[]');
      var t = JSON.parse(studio.getAttribute('data-i18n') || '{}');
      var cards = Array.prototype.slice.call(studio.querySelectorAll('.plan-card'));
      var picks = Array.prototype.slice.call(document.querySelectorAll('[data-pick]'));
      var rtl = document.documentElement.dir === 'rtl';
      var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      var shownSlug = null;
      var ribbon = document.getElementById('planRibbon');
      var fmt = function (n) { return Math.round(n).toLocaleString('en'); };
      function shownCard() {
        return cards.filter(function (c) { return c.classList.contains('is-active'); })[0] || cards[0];
      }

      // One plan's card at a time; its orb comes alive.
      function showPlan(slug) {
        picks.forEach(function (p) { p.classList.toggle('is-on', p.getAttribute('data-pick') === slug); });
        if (slug === shownSlug) return;
        shownSlug = slug;
        cards.forEach(function (card) {
          var on = card.getAttribute('data-plan-slug') === slug;
          card.classList.toggle('is-active', on);
          if (on) { card.removeAttribute('inert'); } else { card.setAttribute('inert', ''); }
          var orb = card.querySelector('.plan-orb');
          if (orb) orb.classList.toggle('is-live', on);
          if (on && ribbon) ribbon.hidden = !card.classList.contains('popular');
        });
        refreshBar();
      }

      // The bar at the bottom on phones: the shown plan, its price and its own button.
      var bar = document.getElementById('planBar');
      function refreshBar() {
        if (!bar) return;
        var card = shownCard();
        if (!card) return;
        var name = card.querySelector('.plan-name');
        var price = card.querySelector('.price-value');
        var period = card.querySelector('.price-period');
        document.getElementById('planBarName').textContent = name ? name.textContent.trim() : '';
        document.getElementById('planBarPrice').textContent = price ? (price.textContent.trim() + ' ' + (period ? period.textContent.trim() : '')) : '';
        var action = document.getElementById('planBarAction');
        action.innerHTML = '';
        var button = card.querySelector('.plan-btn');
        if (button) {
          var copy = button.cloneNode(true);
          copy.removeAttribute('data-upgrade-link');
          copy.setAttribute('tabindex', '-1');
          action.appendChild(copy);
        }
      }
      var panel = studio.querySelector('.studio-plan');
      if (bar && panel && 'IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
          var show = !entries[0].isIntersecting;
          bar.classList.toggle('is-shown', show);
          document.body.classList.toggle('has-plan-bar', show && window.matchMedia('(max-width: 640px)').matches);
        }, { threshold: 0.15 }).observe(panel);
      }

      // A new billing cycle: the price lifts in, and the bar follows.
      ['monthlyBtn', 'yearlyBtn'].forEach(function (id) {
        var button = document.getElementById(id);
        if (!button) return;
        button.addEventListener('click', function () {
          var price = shownCard() && shownCard().querySelector('.price-amount');
          if (price && !reduced) { price.classList.remove('is-changing'); void price.offsetWidth; price.classList.add('is-changing'); }
          refreshBar();
        });
      });

      var first = shownCard();
      shownSlug = first ? first.getAttribute('data-plan-slug') : null;
      picks.forEach(function (p) { p.classList.toggle('is-on', p.getAttribute('data-pick') === shownSlug); });
      refreshBar();

      var slider = document.getElementById('studioSlider');
      if (plans.length < 2 || !slider) {
        picks.forEach(function (p) { p.addEventListener('click', function () { showPlan(p.getAttribute('data-pick')); }); });
        return;
      }

      var input = document.getElementById('studioMinutes');
      var about = document.getElementById('studioAbout');
      var head = document.getElementById('studioHead');
      var wave = document.getElementById('studioWave');
      var svg = document.getElementById('studioBars');
      var minM = Math.max(1, Math.floor(plans[0].minutes / 3));
      var maxM = plans[plans.length - 1].minutes;
      var anchors = [minM].concat(plans.map(function (p) { return p.minutes; }));
      // The first plan sits right at the start (fewer minutes take a short lead-in before
      // it), the last at the end, and the plans in between are evenly spaced.
      var START = 0.06, END = 0.94, LEAD = 0.015;
      function between(m, lo, hi) { return Math.max(0, Math.min(1, Math.log(m / lo) / (Math.log(hi / lo) || 1))); }
      function pos(m) {
        if (m <= anchors[1]) return LEAD + (START - LEAD) * between(Math.max(m, minM), minM, anchors[1]);
        var gaps = anchors.length - 2;
        for (var i = 2; i < anchors.length; i++) {
          if (m <= anchors[i]) return START + (END - START) * (i - 2 + between(m, anchors[i - 1], anchors[i])) / gaps;
        }
        return END;
      }
      var NICE = [1, 2, 3, 5, 10, 15, 20, 30, 45, 60, 90, 100, 120, 150, 200, 250, 300, 400, 500, 600, 750, 1000, 1250, 1500, 1750, 2000, 2500, 3000, 4000, 5000, 7500, 10000, 15000, 20000, 25000, 50000];
      var stops = NICE.filter(function (n) { return n >= minM && n <= maxM; }).concat(anchors)
        .filter(function (n, i, all) { return all.indexOf(n) === i; })
        .sort(function (a, b) { return a - b; });
      slider.max = String(stops.length - 1);

      // The plans as orbs along the track.
      studio.querySelectorAll('.studio-station').forEach(function (station) {
        var plan = plans.filter(function (p) { return p.slug === station.getAttribute('data-pick'); })[0];
        if (plan) station.style.left = ((rtl ? 1 - pos(plan.minutes) : pos(plan.minutes)) * 100) + '%';
      });

      // The waveform, drawn once.
      var N = 110, rects = [];
      for (var i = 0; i < N; i++) {
        var env = 0.32 + 0.68 * Math.abs(Math.sin(i * 0.075 + 0.6));
        var h = 6 + 70 * env * Math.abs(Math.sin(i * 0.63) * Math.cos(i * 0.21 + 1.1));
        var r = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
        r.setAttribute('x', (i * 1000 / N + 2.2).toFixed(2));
        r.setAttribute('width', (1000 / N - 4.4).toFixed(2));
        r.setAttribute('y', (43 - h / 2).toFixed(2));
        r.setAttribute('height', h.toFixed(2));
        r.setAttribute('rx', '3');
        r.style.setProperty('--i', String(i));
        svg.appendChild(r);
        rects.push(r);
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
      function fit() { if (!sizes) input.style.width = (String(input.value).length + 0.15) + 'ch'; }
      input.addEventListener('input', fit);

      // The number counts to its new value.
      var shownMinutes = null, raf = 0;
      function countTo(to) {
        var from = shownMinutes === null ? to : shownMinutes;
        shownMinutes = to;
        if (document.activeElement === input) return;
        cancelAnimationFrame(raf);
        if (reduced || from === to) { input.value = fmt(to); fit(); return; }
        var start = performance.now();
        (function step(now) {
          var k = Math.min(1, (now - start) / 450), e = 1 - Math.pow(1 - k, 3);
          input.value = fmt(from + (to - from) * e);
          fit();
          if (k < 1) raf = requestAnimationFrame(step);
        })(start);
      }

      var x = null, announce = 0;
      var live = document.createElement('p');
      live.className = 'sr-only';
      live.setAttribute('aria-live', 'polite');
      live.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap';
      studio.appendChild(live);

      function set(m) {
        m = Math.max(minM, Math.min(maxM, Math.round(m) || minM));
        var k = 0;
        stops.forEach(function (s, i) { if (Math.abs(pos(s) - pos(m)) < Math.abs(pos(stops[k]) - pos(m))) k = i; });
        slider.value = String(k);
        var next = pos(m), from = x === null ? next : x;
        // The bars fill in a sweep, from where the playhead was to where it goes.
        rects.forEach(function (rect, i) {
          var c = (i + 0.5) / N, on = c <= next;
          if (rect.classList.contains('on') !== on) {
            rect.style.transitionDelay = reduced || x === null ? '0ms'
              : Math.round(Math.abs(c - from) / Math.max(0.001, Math.abs(next - from)) * 320) + 'ms';
            rect.classList.toggle('on', on);
          }
        });
        x = next;
        head.style.left = ((rtl ? 1 - next : next) * 100) + '%';
        countTo(m);
        about.textContent = t.about.replace(':time', duration(m));
        var plan = plans.filter(function (p) { return m <= p.minutes; })[0] || plans[plans.length - 1];
        showPlan(plan.slug);
        var sentence = t.live.replace(':minutes', fmt(m)).replace(':plan', plan.name);
        slider.setAttribute('aria-valuetext', sentence);
        clearTimeout(announce);
        announce = setTimeout(function () { live.textContent = sentence; }, 500);
      }

      // Keyboard: one stop per arrow (the slider is the track for screen readers and keys).
      slider.addEventListener('input', function () { set(stops[+slider.value]); });

      // Pointer: the stop nearest to where the track is touched, also while dragging.
      function fromPointer(e) {
        var box = wave.getBoundingClientRect();
        var f = (e.clientX - box.left) / box.width;
        if (rtl) f = 1 - f;
        var best = stops[0];
        stops.forEach(function (s) { if (Math.abs(pos(s) - f) < Math.abs(pos(best) - f)) best = s; });
        set(best);
      }
      slider.style.pointerEvents = 'none';
      wave.style.cursor = 'ew-resize';
      wave.style.touchAction = 'pan-y';
      wave.addEventListener('pointerdown', function (e) {
        studio.classList.add('is-dragging');
        wave.setPointerCapture(e.pointerId);
        slider.focus({ preventScroll: true });
        fromPointer(e);
      });
      wave.addEventListener('pointermove', function (e) { if (wave.hasPointerCapture(e.pointerId)) fromPointer(e); });
      ['pointerup', 'pointercancel'].forEach(function (type) {
        wave.addEventListener(type, function () { studio.classList.remove('is-dragging'); });
      });

      // Typing a number (Arabic or Western digits).
      function typed() {
        var digits = String(input.value).replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).replace(/[^\d]/g, '');
        if (digits !== '') { shownMinutes = null; set(+digits); input.value = fmt(Math.max(minM, Math.min(maxM, +digits))); }
      }
      input.addEventListener('change', typed);
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); typed(); } });

      // A plan's orb on the track, or its card below, moves the track to it.
      picks.forEach(function (p) {
        p.addEventListener('click', function () {
          var plan = plans.filter(function (q) { return q.slug === p.getAttribute('data-pick'); })[0];
          if (plan) set(plan.minutes); else showPlan(p.getAttribute('data-pick'));
          if (p.classList.contains('plan-pick')) {
            var box = studio.getBoundingClientRect();
            if (box.top < 0 || box.top > window.innerHeight * 0.4) studio.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
          }
        });
      });

      set(+String(input.value).replace(/[^\d]/g, '') || plans[0].minutes);
      requestAnimationFrame(function () { studio.classList.add('is-ready'); });
    })();
  </script>
</body>
</html>
