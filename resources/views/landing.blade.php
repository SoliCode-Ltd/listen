@include('components.page-head', ['pageTitle' => __('ui.seo.home_title'), 'page' => 'home'])
  @php
    $voiceShowcaseCss = public_path('css/voice-showcase.css');
  @endphp
  <link rel="stylesheet" href="{{ asset('css/voice-showcase.css') }}?v={{ is_file($voiceShowcaseCss) ? filemtime($voiceShowcaseCss) : '1' }}">
  <style>
    .bg-orb {
      position: fixed;
      z-index: -1;
      pointer-events: none;
      border-radius: 999px;
      filter: blur(36px);
      opacity: .25;
      animation: float 10s ease-in-out infinite;
      will-change: transform;
    }
    .bg-orb.a {
      display: none;
    }
    .bg-orb.b {
      display: none;
    }

    :root {
      --landing-section-space: 4.5rem;
      --landing-section-space-mobile: 2.5rem;
    }

    .hero {
      padding: 4rem 0 2.5rem;
    }
    .hero-grid {
      display: grid;
      grid-template-columns: 1.1fr .9fr;
      gap: 1.75rem;
      align-items: center;
    }
    .eyebrow {
      display: inline-block;
      padding: .4rem .85rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .78rem;
      color: var(--muted);
      letter-spacing: .1em;
      text-transform: uppercase;
      margin-bottom: 1rem;
    }
    .hero h1 {
      margin: 0 0 1rem;
      font-size: clamp(2.4rem, 6vw, 4.5rem);
      /* Medium, like every big title on the site: confident without shouting. */
      font-weight: 600;
      line-height: 1.05;
      letter-spacing: -.03em;
      text-wrap: balance;
    }
    /* Two sentences, two lines: "Type anything." / "Hear it in any accent." */
    .hero h1 .brand-text {
      display: block;
    }
    html[dir="rtl"] .hero h1 {
      font-size: clamp(2.4rem, 6vw, 4.5rem);
      line-height: 1.25;
      letter-spacing: 0;
      text-wrap: balance;
    }
    .lead {
      margin: 0 0 1.5rem;
      color: var(--muted);
      font-size: 1.1rem;
      line-height: 1.6;
      max-width: 52ch;
      text-wrap: pretty;
    }
    .hero-cta {
      display: flex;
      gap: .75rem;
      flex-wrap: wrap;
    }
    .btn-trial {
      border-color: var(--brand-accent);
      color: var(--brand-accent);
      font-weight: 700;
    }

    .hero-art {
      position: relative;
      display: grid;
      place-items: center;
    }
    .hero-art::before {
      display: none;
    }
    .hero-art > img {
      width: min(520px, 100%);
      height: auto;
      filter: drop-shadow(0 16px 28px rgba(0,0,0,.24));
      animation: float 7s ease-in-out infinite;
      position: relative;
      z-index: 1;
    }

    .section {
      padding: var(--landing-section-space) 0;
    }
    .section h2 {
      margin: 0 0 .65rem;
      font-size: clamp(1.6rem, 3.2vw, 2.3rem);
      letter-spacing: -.02em;
      text-align: center;
      text-wrap: balance;
    }
    html[dir="rtl"] .section h2 {
      letter-spacing: 0;
    }
    .section .section-lead {
      margin: 0 auto 2.25rem;
      max-width: 60ch;
      color: var(--muted);
      font-size: 1.05rem;
      line-height: 1.65;
      text-align: center;
      text-wrap: pretty;
    }

    /* ─── Listen Anywhere Section ─── */
    .apps-shell {
      position: relative;
      overflow: hidden;
      padding: 0;
    }
    .apps-shell::after {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60'%3E%3Ccircle cx='1' cy='1' r='.85' fill='white' fill-opacity='.025'/%3E%3C/svg%3E");
      pointer-events: none;
    }
    .apps-kicker {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      font-size: .72rem;
      text-transform: uppercase;
      letter-spacing: .1em;
      color: color-mix(in oklab, var(--a) 75%, var(--text));
      border: 1px solid var(--line);
      background: var(--surface2);
      border-radius: var(--radius-full);
      padding: .32rem .75rem;
      margin-bottom: .85rem;
      position: relative;
      z-index: 1;
    }
    .apps-kicker::before {
      content: "";
      width: .42rem;
      height: .42rem;
      border-radius: 50%;
      background: var(--brand-accent);
      box-shadow: none;
    }

    .apps-copy {
      margin: 0 auto;
      text-align: center;
    }
    .apps-copy h2 { margin-bottom: .6rem; }
    .apps-points {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem;
      justify-content: flex-end;
      align-self: start;
      padding-top: .5rem;
    }
    .apps-point {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      padding: .48rem .8rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      background: var(--surface2);
      color: var(--muted);
      font-size: .8rem;
      white-space: nowrap;
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
    }
    .apps-point::before {
      content: "";
      width: .42rem;
      height: .42rem;
      border-radius: 50%;
      background: var(--brand-accent);
      flex-shrink: 0;
    }
    /* Two app cards */
    .apps-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 1.1rem;
      position: relative;
      z-index: 1;
    }
    .app-card {
      position: relative;
      overflow: hidden;
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.6rem 1.5rem;
      background: var(--surface);
      display: flex;
      flex-direction: column;
      gap: 1rem;
      transition: transform .28s cubic-bezier(.22,1,.36,1), border-color .28s ease, box-shadow .28s ease;
    }
    .app-card::before {
      content: "";
      position: absolute;
      inset: 0 0 auto 0;
      height: 1px;
      display: none;
    }
    .app-card:hover {
      border-color: color-mix(in oklab, var(--a) 30%, var(--line));
      box-shadow: var(--shadow);
    }
    .app-card-head {
      display: flex;
      align-items: center;
      gap: .85rem;
    }
    .app-icon {
      width: 2.6rem;
      height: 2.6rem;
      border-radius: .9rem;
      border: 1px solid var(--line);
      background: var(--surface);
      display: grid;
      place-items: center;
      flex-shrink: 0;
      box-shadow: 0 4px 10px rgba(0,0,0,.08);
    }
    .app-icon {
      color: var(--accent-text);
      background: color-mix(in oklab, var(--a) 10%, var(--surface));
      border-color: color-mix(in oklab, var(--a) 22%, var(--line));
      box-shadow: none;
    }
    .app-icon svg {
      width: 1.25rem;
      height: 1.25rem;
      stroke: currentColor;
      stroke-width: 1.8;
      stroke-linecap: round;
      stroke-linejoin: round;
      fill: none;
    }
    .app-icon img {
      width: 1.45rem;
      height: 1.45rem;
      object-fit: contain;
      display: block;
    }
    .app-card h3 {
      margin: 0;
      font-size: 1.15rem;
      font-weight: 700;
    }
    .app-card p {
      margin: 0;
      color: var(--muted);
      font-size: .95rem;
      line-height: 1.6;
      flex: 1;
    }
    .app-card-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .8rem;
      padding-top: .85rem;
      border-top: 1px solid var(--line);
    }
    .app-tag {
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      padding: .38rem .65rem;
      border-radius: var(--radius-full);
      background: var(--surface2);
      border: 1px solid var(--line);
      color: var(--muted);
      font-size: .74rem;
      font-weight: 600;
      white-space: nowrap;
    }
    .app-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: .45rem;
      min-height: 44px;
      padding: 0 1.25rem;
      border-radius: var(--btn-radius);
      background: var(--brand-accent);
      color: #fff;
      font-size: .92rem;
      font-weight: 700;
      text-decoration: none;
      transition: background-color .2s ease;
      white-space: nowrap;
    }
    .app-link:hover {
      background: var(--brand-hover);
    }
    .app-link:focus-visible {
      outline: 2px solid var(--brand-accent);
      outline-offset: 3px;
    }

    .premium-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 1rem;
    }
    .premium-card {
      display: flex;
      flex-direction: column;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem 1.35rem;
      transition: border-color .25s ease, box-shadow .25s ease;
    }
    .premium-card:hover {
      border-color: color-mix(in oklab, var(--a) 30%, var(--line));
      box-shadow: var(--shadow);
    }
    .premium-icon {
      width: 2.6rem;
      height: 2.6rem;
      border-radius: .8rem;
      display: grid;
      place-items: center;
      margin-bottom: 1rem;
      color: var(--accent-text);
      background: color-mix(in oklab, var(--a) 10%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--a) 22%, var(--line));
    }
    .premium-icon svg {
      width: 1.25rem;
      height: 1.25rem;
      fill: none;
      stroke: currentColor;
      stroke-width: 1.8;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .premium-card h3 {
      margin: 0 0 .5rem;
      font-size: 1.05rem;
    }
    .premium-card p {
      flex: 1;
      margin: 0 0 1rem;
      color: var(--muted);
      font-size: .93rem;
      line-height: 1.6;
    }
    .premium-metric {
      align-self: flex-start;
      font-size: .82rem;
      font-weight: 700;
      color: var(--accent-text);
    }

    .cta-section {
      text-align: center;
      padding: clamp(2.25rem, 5vw, 3.5rem) clamp(1.25rem, 4vw, 3rem);
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: 1.25rem;
      margin: 0;
    }
    .cta-section h2 {
      text-wrap: balance;
    }
    .cta-section h2 {
      margin: 0 0 .65rem;
    }
    .cta-section p {
      color: var(--muted);
      margin: 0 0 1.25rem;
    }

    .hero-listen {
      display: inline-flex;
      align-items: center;
      gap: .6rem;
      margin-top: 1.35rem;
      min-height: 44px;
      padding: .35rem .9rem .35rem .4rem;
      border: 0;
      border-radius: var(--radius-full);
      background: transparent;
      color: var(--text);
      font: inherit;
      font-size: .95rem;
      font-weight: 600;
      cursor: pointer;
      transition: background-color .2s ease;
    }
    html[dir="rtl"] .hero-listen {
      padding: .35rem .4rem .35rem .9rem;
    }
    .hero-listen:hover {
      background: color-mix(in oklab, var(--a) 9%, transparent);
    }
    .hero-listen:focus-visible {
      outline: 2px solid var(--brand-accent);
      outline-offset: 2px;
    }
    .hero-listen-icon {
      width: 2.25rem;
      height: 2.25rem;
      border-radius: 50%;
      display: grid;
      place-items: center;
      color: #fff;
      background: var(--brand-accent);
      box-shadow: var(--lift);
    }
    .hero-listen-icon svg {
      width: .95rem;
      height: .95rem;
      fill: currentColor;
    }
    .hero-listen-stop,
    .hero-listen-bars {
      display: none;
    }
    .hero-listen[aria-pressed="true"] .hero-listen-play {
      display: none;
    }
    .hero-listen[aria-pressed="true"] .hero-listen-stop {
      display: block;
    }
    .hero-listen[aria-pressed="true"] .hero-listen-bars {
      display: inline-flex;
    }
    .hero-listen-bars {
      align-items: flex-end;
      gap: 2px;
      height: 14px;
      color: var(--accent-text);
    }
    .hero-listen-bars i {
      width: 3px;
      height: 100%;
      border-radius: 2px;
      background: currentColor;
      animation: heroBars .9s ease-in-out infinite;
    }
    .hero-listen-bars i:nth-child(2) { animation-delay: .15s; }
    .hero-listen-bars i:nth-child(3) { animation-delay: .3s; }
    .hero-listen-bars i:nth-child(4) { animation-delay: .45s; }
    @keyframes heroBars {
      0%, 100% { transform: scaleY(.35); }
      50% { transform: scaleY(1); }
    }
    @media (prefers-reduced-motion: reduce) {
      .hero-listen-bars i { animation: none; }
    }

    /* What Listen has, in real numbers, each drawn above its figure. */
    .facts {
      padding: .5rem 0 2rem;
    }
    .facts-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      margin: 0;
      padding: 0;
      list-style: none;
      border: 1px solid var(--line);
      border-radius: 1.25rem;
      background: var(--surface);
      overflow: hidden;
    }
    .fact {
      display: flex;
      flex-direction: column;
      gap: .2rem;
      padding: 1.35rem 1.5rem 1.4rem;
    }
    .fact + .fact {
      border-inline-start: 1px solid var(--line);
    }
    .fact-value {
      margin-top: 1rem;
      font-size: clamp(2.1rem, 3.6vw, 2.9rem);
      font-weight: 800;
      line-height: 1.1;
      letter-spacing: -.02em;
      font-variant-numeric: tabular-nums;
    }
    .fact-label {
      color: var(--muted);
      font-size: 1rem;
      font-weight: 500;
    }

    /* The drawings: one shared stage, quiet colours, one entrance when the strip appears. */
    .fact-art {
      position: relative;
      height: 88px;
      border-radius: .9rem;
      background: color-mix(in oklab, var(--a) 4%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--a) 12%, var(--line));
      overflow: hidden;
    }
    .fact-art-dialects {
      display: flex;
      flex-wrap: wrap;
      align-content: center;
      justify-content: center;
      gap: .45rem .5rem;
      padding: .5rem .75rem;
    }
    .fa-bubble {
      padding: .26rem .7rem .32rem;
      border-radius: 14px 14px 14px 5px;
      background: var(--surface);
      border: 1px solid color-mix(in oklab, var(--a) 22%, var(--line));
      color: var(--accent-text);
      font-size: .86rem;
      font-weight: 700;
      line-height: 1.3;
      white-space: nowrap;
      box-shadow: 0 6px 14px -10px color-mix(in oklab, var(--a) 60%, transparent);
    }
    /* A loose, staggered cluster, like messages arriving. */
    .fa-bubble:nth-child(odd) { transform: translateY(-4px); }
    .fa-bubble:nth-child(even) { transform: translateY(4px); border-radius: 14px 14px 5px 14px; }
    .fa-bubble:nth-child(2) { background: var(--brand-accent); border-color: transparent; color: #fff; }
    .fact-art-accents { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .55rem; }
    .fa-flags { display: flex; }
    .fa-flags img {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      object-fit: cover;
      box-shadow: 0 0 0 2.5px var(--surface), 0 4px 10px -4px rgba(20, 16, 50, .35);
    }
    .fa-flags img + img { margin-inline-start: -6px; }
    .fa-wave { width: 62%; height: 22px; fill: color-mix(in oklab, var(--a) 55%, var(--surface)); }
    .fact-art-places { display: flex; align-items: center; justify-content: center; gap: .5rem; }
    .fa-tile {
      display: grid;
      place-items: center;
      width: 42px;
      height: 42px;
      border-radius: 12px;
      background: var(--surface);
      border: 1px solid color-mix(in oklab, var(--a) 18%, var(--line));
      color: var(--accent-text);
    }
    .fa-tile:nth-child(odd) { transform: translateY(-7px); }
    .fa-tile:nth-child(even) { transform: translateY(7px); }
    .fa-tile.is-on { background: var(--brand-accent); border-color: transparent; color: #fff; box-shadow: 0 10px 20px -10px color-mix(in oklab, var(--a) 80%, transparent); }
    .fa-tile svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .fact-art-styles { display: grid; place-items: center; }
    .fa-menu {
      display: grid;
      gap: 2px;
      width: min(80%, 200px);
      padding: 4px;
      border-radius: 12px;
      background: var(--surface);
      border: 1px solid color-mix(in oklab, var(--a) 18%, var(--line));
      box-shadow: 0 10px 22px -14px color-mix(in oklab, var(--a) 70%, transparent);
    }
    .fa-option {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .5rem;
      padding: .14rem .65rem;
      border-radius: 8px;
      color: var(--muted);
      font-size: .78rem;
      font-weight: 600;
      line-height: 1.4;
      white-space: nowrap;
    }
    .fa-option.is-on { background: var(--brand-accent); color: #fff; }
    .fa-option svg { width: 14px; height: 14px; flex: none; fill: none; stroke: currentColor; stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; }
    @media (prefers-reduced-motion: no-preference) {
      .facts.reveal:not(.show) .fa-bubble,
      .facts.reveal:not(.show) .fa-flags img,
      .facts.reveal:not(.show) .fa-tile,
      .facts.reveal:not(.show) .fa-option { opacity: 0; }
      .facts.show .fa-bubble { animation: faPop .5s cubic-bezier(.2, .9, .3, 1.25) both; animation-delay: calc(.15s + var(--i) * 90ms); }
      .facts.show .fa-flags img { animation: faSlide .45s ease-out both; animation-delay: calc(.15s + var(--i) * 60ms); }
      .facts.show .fa-tile,
      .facts.show .fa-option { animation: faPop .45s cubic-bezier(.2, .9, .3, 1.25) both; animation-delay: calc(.15s + var(--i) * 80ms); }
      .facts.show .fa-wave rect { transform-box: fill-box; transform-origin: center; animation: faGrow .6s ease-out both; animation-delay: calc(.2s + var(--i) * 25ms); }
    }
    @keyframes faPop { from { opacity: 0; transform: scale(.7); } }
    @keyframes faSlide { from { opacity: 0; transform: translateX(-10px); } }
    @keyframes faGrow { from { transform: scaleY(.15); } }
    /* Two drawings a row once four would squeeze them. */
    @media (max-width: 1080px) {
      .facts-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .fact:nth-child(3) {
        border-inline-start: 0;
      }
      .fact:nth-child(n + 3) {
        border-top: 1px solid var(--line);
      }
    }
    @media (max-width: 760px) {
      .fact {
        padding: 1rem;
      }
      .fa-bubble { font-size: .78rem; padding: .22rem .55rem .28rem; }
      .fact-art-dialects { gap: .35rem .4rem; padding: .4rem .5rem; }
      .fact-art { height: 80px; }
      .fa-tile { width: 36px; height: 36px; border-radius: 10px; }
      .fa-tile svg { width: 21px; height: 21px; }
      .fact-art-places { gap: .35rem; }
      .fa-flags img { width: 28px; height: 28px; }
      .fa-menu { width: min(86%, 200px); }
      .fa-option { font-size: .74rem; padding: .1rem .55rem; }
    }
    /* Small phones: one flag and one place fewer and tighter greetings, so nothing spills over. */
    @media (max-width: 420px) {
      .fact { padding: .9rem .8rem; }
      .fact-art-dialects { gap: .3rem; padding: .35rem .3rem; }
      .fa-bubble { font-size: .7rem; padding: .18rem .45rem .24rem; }
      .fa-flags img { width: 26px; height: 26px; }
      .fa-flags img + img { margin-inline-start: -8px; }
      .fa-flags img:nth-child(6), .fa-tile:nth-child(4) { display: none; }
      .fa-tile { width: 33px; height: 33px; border-radius: 9px; }
      .fa-tile svg { width: 19px; height: 19px; }
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
      .hero-grid,
      .apps-intro,
      .apps-grid {
        grid-template-columns: 1fr;
      }
      .premium-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .hero {
        padding: var(--landing-section-space-mobile) 0;
        text-align: center;
      }
      .hero-grid {
        gap: 1.1rem;
      }
      .hero h1 {
        font-size: clamp(2rem, 10vw, 2.85rem);
        margin-bottom: .85rem;
      }
      .lead {
        margin-bottom: 1.2rem;
        font-size: 1rem;
        margin-left: auto;
        margin-right: auto;
      }
      .hero-cta {
        justify-content: center;
      }
      .section {
        padding: var(--landing-section-space-mobile) 0;
      }
      .apps-points {
        justify-content: flex-start;
      }
      .hero-art {
        margin-top: .35rem;
      }
      .hero-art::before {
        width: min(360px, 88%);
        opacity: .38;
      }
      .hero-art > img {
        max-width: min(370px, 92vw);
        animation: none;
      }
    }

    @media (max-width: 640px) {
      .premium-grid {
        grid-template-columns: 1fr;
      }
      .hero {
        padding: 2rem 0 1.5rem;
      }
      .hero-cta {
        flex-direction: column;
        align-items: center;
        gap: .85rem;
      }
      .hero-cta .btn {
        width: min(100%, 260px);
      }
    }

    /* Voice cloning: one slim line under the hero. */
    .clone-announce {
      padding: 0 0 .5rem;
    }
    .clone-announce-card {
      display: flex;
      align-items: center;
      gap: 1rem;
      padding: .85rem 1rem .85rem .85rem;
      border-radius: 1rem;
      border: 1px solid color-mix(in oklab, var(--a) 26%, var(--line));
      background: color-mix(in oklab, var(--a) 6%, var(--surface));
    }
    html[dir="rtl"] .clone-announce-card {
      padding: .85rem .85rem .85rem 1rem;
    }
    .clone-announce-mark {
      width: 2.5rem;
      height: 2.5rem;
      flex: none;
      border-radius: .75rem;
      display: grid;
      place-items: center;
      color: #fff;
      background: var(--brand-accent);
    }
    .clone-announce-wave {
      width: 1.3rem;
      height: 1.3rem;
      display: block;
    }
    .clone-announce-copy {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      column-gap: .55rem;
      row-gap: .1rem;
    }
    .clone-announce-copy h2 {
      margin: 0;
      text-align: start;
      font-size: 1rem;
      font-weight: 700;
      line-height: 1.5;
      letter-spacing: 0;
    }
    .clone-announce-copy p {
      margin: 0;
      color: var(--muted);
      font-size: .95rem;
      line-height: 1.5;
    }
    .clone-announce-link {
      flex: none;
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      min-height: 44px;
      padding: 0 1rem;
      border-radius: var(--btn-radius);
      color: var(--accent-text);
      font-weight: 700;
      font-size: .92rem;
      text-decoration: none;
      white-space: nowrap;
      transition: background-color .2s ease;
    }
    .clone-announce-link:hover {
      background: color-mix(in oklab, var(--a) 10%, transparent);
    }
    .clone-announce-link:focus-visible {
      outline: 2px solid var(--brand-accent);
      outline-offset: 2px;
    }
    .clone-announce-link svg {
      width: 1rem;
      height: 1rem;
      fill: none;
      stroke: currentColor;
      stroke-width: 2.2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    html[dir="rtl"] .clone-announce-link svg {
      transform: scaleX(-1);
    }
    /* Phones: the mark beside the title, the sentence across the full width, then a full-width button. */
    @media (max-width: 760px) {
      .clone-announce-card,
      html[dir="rtl"] .clone-announce-card {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        align-items: center;
        gap: .8rem .85rem;
        padding: 1.15rem 1.15rem 1.2rem;
      }
      .clone-announce-copy {
        display: contents;
      }
      .clone-announce-copy h2 {
        font-size: 1.05rem;
        line-height: 1.4;
      }
      .clone-announce-copy p {
        grid-column: 1 / -1;
        font-size: .95rem;
        line-height: 1.65;
      }
      .clone-announce-link {
        grid-column: 1 / -1;
        justify-content: center;
        min-height: 46px;
        border: 1.5px solid color-mix(in oklab, var(--a) 40%, var(--line));
        background: var(--surface);
      }
    }

    .active-caption {
      opacity: 0;
      transform: translateY(-8px);
    }

    .idle-caption {
      opacity: 1;
      transform: translateY(0);
    }

    .idle-caption::after {
      content: '';
      display: inline-block;
      width: 4px;
      height: 1.1em;
      background: var(--brand-accent);
      margin-left: 6px;
      vertical-align: text-bottom;
      animation: blinkCaret 0.9s step-end infinite;
      border-radius: 1px;
      opacity: 1;
      transition: opacity 0.18s ease;
    }

    @keyframes blinkCaret {
      0%, 100% { opacity: 1; }
      50% { opacity: 0; }
    }

    @media (max-width: 980px) {
      .hero-grid {
        grid-template-columns: 1fr;
        text-align: center;
      }
      .hero-cta {
        justify-content: center;
      }
      .hero-art {
        justify-content: center;
      }
      .apps-grid {
        grid-template-columns: 1fr;
      }
      .apps-intro {
        grid-template-columns: 1fr;
        text-align: center;
      }
      /* Phones: the app cards line up with every other section. */
      .apps-shell {
        padding-inline: 0;
      }
      .apps-points {
        justify-content: center;
      }
    }

    @media (max-width: 640px) {
      .hero-art {
        padding-inline: .5rem;
      }
      /* On phones the voice circle sits under the buttons: smaller, so the
         page reaches the voices sooner. */
      .hero-art .vs-art {
        max-width: min(330px, 86vw);
      }
      .bg-orb {
        animation: none;
      }
      .active-caption {
        transform: translate(-50%, -8px);
      }
      .idle-caption {
        transform: translate(-50%, 0);
      }
    }
  </style>
</head>
<body>
  <div class="bg-orb a"></div>
  <div class="bg-orb b"></div>

  @include('components.header')

  <main class="container">
    <section class="hero">
      <div class="hero-grid">
        <div class="reveal">
          <h1>{{ __('ui.landing.hero_title_before') }} <span class="brand-text">{{ __('ui.landing.hero_title_highlight') }}</span> {{ __('ui.landing.hero_title_after') }}</h1>
          <p class="lead">{{ __('ui.landing.hero_subtitle') }}</p>
          <div class="hero-cta">
            @guest
              <a class="btn btn-primary" href="{{ route('demo.page') }}">{{ __('ui.landing.try_demo') }}</a>
              @if(!empty($trialPromo['show']))
                <a class="btn btn-trial" href="{{ route('trial.start') }}">{{ __('ui.landing.trial_button', ['days' => (int) ($trialPromo['days'] ?? 7)]) }}</a>
              @else
                <a class="btn" href="{{ route('register') }}">{{ __('ui.landing.create_free_account') }}</a>
              @endif
            @else
              <a class="btn btn-primary" href="{{ route('dashboard') }}">{{ __('ui.landing.go_to_dashboard') }}</a>
              @if(!empty($trialPromo['show']))
                <a class="btn btn-trial" href="{{ route('trial.start') }}">{{ __('ui.landing.trial_button', ['days' => (int) ($trialPromo['days'] ?? 7)]) }}</a>
              @else
                <a class="btn" href="{{ route('browsers-extension.page') }}">{{ __('ui.landing.browser_extension') }}</a>
              @endif
            @endguest
          </div>
          {{-- The voices on the right play when pressed; this says so, and plays them all. --}}
          <button type="button" class="hero-listen" data-hero-listen aria-pressed="false">
            <span class="hero-listen-icon" aria-hidden="true">
              <svg class="hero-listen-play" viewBox="0 0 24 24"><path d="M6.4 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L7.92 4.29A1 1 0 0 0 6.4 5.14z"/></svg>
              <svg class="hero-listen-stop" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2.5"/></svg>
            </span>
            <span>{{ __('ui.landing.hero_listen') }}</span>
            <span class="hero-listen-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
          </button>
        </div>

        <aside class="hero-art reveal">
          @include('components.home.hero-art')
        </aside>
      </div>
    </section>

    @php
      // Real counts, rounded down to the ten so each claim stays true as more are added.
      $factPlaces = (int) (floor(count(array_filter(\App\Support\AudioEffects::ALL, fn ($row) => $row[1] !== 'clean')) / 10) * 10);
      $factDialects = ['value' => \App\Support\DialectCounts::arabic(), 'label' => __('ui.landing.fact_dialects'), 'art' => 'dialects'];
      $factAccents = ['value' => \App\Support\DialectCounts::english(), 'label' => __('ui.landing.fact_accents'), 'art' => 'accents'];
      $facts = [
        // The page's own language comes first.
        ...(app()->getLocale() === 'ar' ? [$factDialects, $factAccents] : [$factAccents, $factDialects]),
        ['value' => $factPlaces, 'label' => __('ui.landing.fact_places'), 'art' => 'places'],
        ['value' => (int) (floor(count(\App\Support\StudioChoices::STYLES) / 10) * 10), 'label' => __('ui.landing.fact_styles'), 'art' => 'styles'],
      ];
    @endphp
    <section class="facts reveal" aria-label="{{ __('ui.landing.facts_label') }}">
      <ul class="facts-grid">
        @foreach($facts as $fact)
          @if($fact['value'] > 0)
            <li class="fact">
              <x-home.fact-art :kind="$fact['art']" />
              {{-- "140+" in English, "+140" in Arabic, as the rest of the copy writes it. --}}
              <strong class="fact-value"><bdi dir="ltr">{{ app()->getLocale() === 'ar' ? '+'.$fact['value'] : $fact['value'].'+' }}</bdi></strong>
              <span class="fact-label">{{ $fact['label'] }}</span>
            </li>
          @endif
        @endforeach
      </ul>
    </section>

    {{-- Voice cloning, announced in one slim line under the hero. --}}
    <section class="clone-announce reveal" aria-labelledby="cloneAnnounceTitle">
      <div class="clone-announce-card">
        <span class="clone-announce-mark" aria-hidden="true">
          <svg class="clone-announce-wave" viewBox="0 0 128 128" fill="currentColor">
            <rect x="8" y="59" width="10" height="10" rx="5"/>
            <rect x="26" y="42" width="10" height="44" rx="5"/>
            <rect x="44" y="28" width="10" height="72" rx="5"/>
            <rect x="62" y="14" width="10" height="100" rx="5"/>
            <rect x="80" y="28" width="10" height="72" rx="5"/>
            <rect x="98" y="42" width="10" height="44" rx="5"/>
            <rect x="116" y="59" width="10" height="10" rx="5"/>
          </svg>
        </span>
        <div class="clone-announce-copy">
          <h2 id="cloneAnnounceTitle">{{ __('ui.landing.clone_announce_title') }}</h2>
          <p>{{ __('ui.landing.clone_announce_body') }}</p>
        </div>
        <a class="clone-announce-link" href="{{ route('pricing') }}">
          {{ __('ui.landing.clone_announce_cta') }}
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </a>
      </div>
    </section>

    {{-- Every kind of voice, one orb each --}}
    @include('components.home.voice-tour')

    {{-- Voice gallery and dialogues --}}
    @include('components.home.voice-gallery')
    @include('components.home.dialogues')
    @include('components.home.environments')

    <section class="section reveal">
      <div class="apps-shell">
        <div class="apps-intro">
          <div class="apps-copy">
            <!-- <span class="apps-kicker">{{ __('ui.landing.section1_eyebrow') }}</span> -->
            <h2>{{ __('ui.landing.section1_title') }}</h2>
            <p class="section-lead">{{ __('ui.landing.section1_subtitle') }}</p>
          </div>
          <!-- <div class="apps-points" aria-hidden="true">
            <span class="apps-point">{{ __('ui.landing.section1_point1') }}</span>
            <span class="apps-point">{{ __('ui.landing.section1_point2') }}</span>
            <span class="apps-point">{{ __('ui.landing.section1_point3') }}</span>
          </div> -->
        </div>
        <div class="apps-grid">
          <article class="app-card reveal">
            <div class="app-card-head">
              <div class="app-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4M7.5 12.5v-3M10.5 13.5v-5M13.5 13.5v-5M16.5 12.5v-3"/></svg>
              </div>
              <h3>{{ __('ui.landing.section1_card1_title') }}</h3>
            </div>
            <p>{{ __('ui.landing.section1_card1_body') }}</p>
            <div class="app-card-footer">
              @auth
                <a class="app-link" href="{{ route('dashboard') }}">{{ __('ui.landing.section1_card1_cta') }}</a>
              @else
                <a class="app-link" href="{{ route('register') }}">{{ __('ui.landing.section1_card1_cta') }}</a>
              @endauth
            </div>
          </article>
          <article class="app-card reveal">
            <div class="app-card-head">
              <div class="app-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M10 3.5a2 2 0 0 1 4 0V5h3.5A1.5 1.5 0 0 1 19 6.5V10h-1.5a2 2 0 0 0 0 4H19v3.5a1.5 1.5 0 0 1-1.5 1.5H14v-1.5a2 2 0 0 0-4 0V19H6.5A1.5 1.5 0 0 1 5 17.5V14h1.5a2 2 0 0 0 0-4H5V6.5A1.5 1.5 0 0 1 6.5 5H10z"/></svg>
              </div>
              <h3>{{ __('ui.landing.section1_card2_title') }}</h3>
            </div>
            <p>{{ __('ui.landing.section1_card2_body') }}</p>
            <div class="app-card-footer">
              <a class="app-link" href="{{ route('browsers-extension.page') }}">{{ __('ui.landing.section1_card2_cta') }}</a>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section class="section reveal">
      <h2>{{ __('ui.landing.section2_title') }}</h2>
      <p class="section-lead">{{ __('ui.landing.section2_subtitle') }}</p>
      <div class="premium-grid">
        <article class="premium-card reveal">
          <span class="premium-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></span>
          <h3>{{ __('ui.landing.section2_card1_title') }}</h3>
          <p>{{ __('ui.landing.section2_card1_body') }}</p>
          <span class="premium-metric">{{ __('ui.landing.section2_card1_metric') }}</span>
        </article>
        <article class="premium-card reveal">
          <span class="premium-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M13 2 4.5 13.5H12L11 22l8.5-11.5H12z"/></svg></span>
          <h3>{{ __('ui.landing.section2_card2_title') }}</h3>
          <p>{{ __('ui.landing.section2_card2_body') }}</p>
          <span class="premium-metric">{{ __('ui.landing.section2_card2_metric') }}</span>
        </article>
        <article class="premium-card reveal">
          <span class="premium-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg></span>
          <h3>{{ __('ui.landing.section2_card3_title') }}</h3>
          <p>{{ __('ui.landing.section2_card3_body') }}</p>
          <span class="premium-metric">{{ __('ui.landing.section2_card3_metric') }}</span>
        </article>
        <article class="premium-card reveal">
          <span class="premium-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 8h18M7 6h.01M10 6h.01"/></svg></span>
          <h3>{{ __('ui.landing.section2_card4_title') }}</h3>
          <p>{{ __('ui.landing.section2_card4_body') }}</p>
          <span class="premium-metric">{{ __('ui.landing.section2_card4_metric') }}</span>
        </article>
      </div>
    </section>

    <section class="section reveal">
      <div class="cta-section">
        <h2>{{ __('ui.landing.cta_title') }}</h2>
        <p>{{ __('ui.landing.cta_subtitle') }}</p>
        @auth
          <a class="btn btn-primary" href="{{ route('dashboard') }}">{{ __('ui.landing.go_to_dashboard') }}</a>
        @else
          <a class="btn btn-primary" href="{{ route('register') }}">{{ __('ui.landing.get_started_free') }}</a>
        @endauth
      </div>
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @include('components.voice-player-script')
  @include('components.orb-script')
  <script>
    // "Hear the voices" plays the hero conversation, and shows when it is playing.
    (function () {
      var button = document.querySelector('[data-hero-listen]');
      var mark = document.querySelector('[data-vs-play-all]');
      if (!button) return;
      if (!mark) { button.hidden = true; return; }
      var cast = mark.closest('.vs-cast');
      button.addEventListener('click', function () { mark.click(); });
      var sync = function () {
        button.setAttribute('aria-pressed', cast && cast.classList.contains('is-playing-all') ? 'true' : 'false');
      };
      if (cast && window.MutationObserver) {
        new MutationObserver(sync).observe(cast, { attributes: true, attributeFilter: ['class'] });
      }
    })();
  </script>
</body>
</html>
