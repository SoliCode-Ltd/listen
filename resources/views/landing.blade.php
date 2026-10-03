@include('components.page-head', ['pageTitle' => __('ui.seo.home_title'), 'page' => 'home'])
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
      --landing-section-space: 3rem;
      --landing-section-space-mobile: 2rem;
    }

    .hero {
      padding: var(--landing-section-space) 0;
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
      line-height: 1.05;
      letter-spacing: -.03em;
    }
    html[dir="rtl"] .hero h1 {
      font-size: clamp(2.4rem, 6vw, 4.5rem);
      line-height: 1.22;
      letter-spacing: 0;
      text-wrap: balance;
    }
    .lead {
      margin: 0 0 1.5rem;
      color: var(--muted);
      font-size: 1.1rem;
      line-height: 1.6;
      max-width: 52ch;
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
    .hero-art img {
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
      margin: 0 0 .5rem;
      font-size: clamp(1.6rem, 3.5vw, 2.4rem);
      text-align: center;
    }
    .section .section-lead {
      margin: 0 0 2rem;
      color: var(--muted);
      font-size: 1.05rem;
      text-align: center;
      margin-left: auto;
      margin-right: auto;
    }

    /* ─── Listen Anywhere Section ─── */
    .apps-shell {
      position: relative;
      overflow: hidden;
      padding: clamp(2rem, 3vw, 3rem);

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
      background: var(--surface2);
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
      transform: translateY(-5px) scale(1.005);
      border-color: var(--line);
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
    .app-icon svg {
      width: 1.15rem;
      height: 1.15rem;
      stroke: var(--text);
      stroke-width: 1.8;
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
      gap: .45rem;
      padding: .42rem .95rem;
      border-radius: var(--radius-full);
      background: var(--brand-accent);
      color: #fff;
      font-size: .85rem;
      font-weight: 700;
      text-decoration: none;
      transition: opacity .2s ease, transform .2s ease;
      white-space: nowrap;
    }
    .app-link:hover {
      opacity: .88;
      transform: scale(1.03);
    }

    .premium-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 1rem;
    }
    .premium-card {
      background: var(--surface2);
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
      transition: transform .25s ease, border-color .25s ease;
    }
    .premium-card:hover {
      transform: translateY(-4px);
      border-color: var(--brand-accent);
    }
    .premium-card h3 {
      margin: 0 0 .55rem;
      font-size: 1.05rem;
    }
    .premium-card p {
      margin: 0 0 .75rem;
      color: var(--muted);
      font-size: .95rem;
      line-height: 1.55;
    }
    .premium-metric {
      display: inline-block;
      padding: .35rem .7rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .9rem;
      color: var(--muted);
    }

    .cta-section {
      text-align: center;
      padding: 3rem;
      background: var(--surface2);
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      margin: 0;
    }
    .cta-section h2 {
      margin: 0 0 .65rem;
    }
    .cta-section p {
      color: var(--muted);
      margin: 0 0 1.25rem;
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

    /* ─── Voice Sample Showcase — Premium Redesign ─── */
    .voice-gallery-shell {
      position: relative;
      overflow: visible;
      border: none;
      border-radius: 0;
      padding: 0;
      background: transparent;
      box-shadow: none;
    }
    .voice-gallery-inner {
      position: relative;
      z-index: 1;
    }
    .voice-gallery-head {
      margin-bottom: 2rem;
    }
    .voice-gallery-copy {
      width: min(100%, 60rem);
      margin: 0 auto;
      text-align: center;
    }
    .voice-gallery-kicker {
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      padding: .32rem .75rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      background: var(--surface2);
      font-size: .72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .1em;
      color: var(--text);
      margin-bottom: .75rem;
    }
    .voice-gallery-kicker::before {
      content: "";
      width: .42rem;
      height: .42rem;
      border-radius: 50%;
      background: var(--brand-accent);
      box-shadow: none;
    }
    .voice-gallery-title {
      margin: 0 0 .45rem;
      font-size: clamp(1.5rem, 3vw, 2.2rem);
    }
    .voice-gallery-subtitle {
      margin: 0;
      color: var(--muted);
      font-size: 1rem;
    }
    /* Scrollable track with snap */
    .sample-showcase-track {
      display: grid;
      grid-template-columns: repeat(4, minmax(240px, 1fr));
      column-gap: 1.1rem;
      row-gap: 1.4rem;
    }
    @media (max-width: 1100px) {
      .sample-showcase-track { grid-template-columns: repeat(3, minmax(220px, 1fr)); }
    }
    @media (max-width: 780px) {
      .sample-showcase-track {
        grid-template-columns: repeat(2, minmax(200px, 1fr));
      }
    }
    /* Phones: one swipeable row instead of a column of a dozen full-width cards. */
    @media (max-width: 560px) {
      .sample-showcase-track {
        grid-template-columns: none;
        grid-auto-flow: column;
        grid-auto-columns: 82%;
        column-gap: .85rem;
        overflow-x: auto;
        overscroll-behavior-x: contain;
        scroll-snap-type: x mandatory;
        scroll-padding-inline: .25rem;
        padding: .5rem .25rem 1rem;
        scrollbar-width: thin;
      }
      .sample-showcase-track .sample-card {
        scroll-snap-align: start;
      }
      .sample-showcase-track .sample-card:hover {
        transform: none;
      }
      .sample-card-hero {
        min-height: 6.5rem;
      }
    }
    /* Individual voice card */
    .sample-card {
      position: relative;
      overflow: hidden;
      border-radius: calc(var(--radius-lg) + .2rem);
      border: 1px solid var(--line);
      background: var(--surface);
      padding: 0;
      box-shadow: 0 8px 28px rgba(0,0,0,.15);
      transition: transform .4s cubic-bezier(.22,1,.36,1), border-color .4s ease, box-shadow .4s ease;
      will-change: transform, box-shadow;
      backface-visibility: hidden;
      display: flex;
      flex-direction: column;
      cursor: default;
    }
    .sample-card::before {
      content: "";
      position: absolute;
      inset: 0 0 auto 0;
      height: 1px;
      display: none;
    }
    .sample-card:hover {
      transform: translateY(-8px) scale(1.02);
      border-color: var(--brand-hover);
      box-shadow: var(--shadow);
    }
    .sample-card.is-active-card {
      border-color: var(--brand-active);
      box-shadow: var(--shadow);
    }
    /* Card hero section with gradient bg */
    .sample-card-hero {
      position: relative;
      min-height: 9rem;
      overflow: hidden;
      isolation: isolate;
      border-radius: calc(var(--radius-lg) + .2rem) calc(var(--radius-lg) + .2rem) 0 0;
      backface-visibility: hidden;
      transform: translate3d(0,0,0);
    }
    .sample-card-hero::after {
      content: "";
      position: absolute;
      inset: 0;
      background:
        linear-gradient(145deg, rgba(3, 7, 18, .12), rgba(3, 7, 18, .34)),
        var(--sample-flag-image, linear-gradient(135deg, color-mix(in oklab, var(--a) 12%, transparent), transparent 65%)),
        var(--surface2);
      background-position: center;
      background-repeat: no-repeat;
      background-size: cover;
      filter: saturate(1.15) brightness(0.9) contrast(1.03);
      pointer-events: none;
      z-index: 1;
    }
    .sample-card-hero.has-inline-art::after {
      background: linear-gradient(145deg, rgba(3, 7, 18, .08), rgba(3, 7, 18, .26));
    }
    .sample-card-hero-svg {
      position: absolute;
      inset: 0;
      z-index: 0;
      overflow: hidden;
      backface-visibility: hidden;
      transform: translate3d(0,0,0);
    }
    .sample-card-hero-svg svg {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .sample-card-hero-svg .sample-card-hero-msa-label {
      font-family: var(--font-display-ar), 'Cairo', sans-serif;
      font-weight: 800;
    }
    /* no white line divider */
    /* Card body */
    .sample-card-body {
      padding: 1.05rem 1.4rem 1rem;
      background: var(--surface);
      position: relative;
      display: flex;
      flex-direction: column;
      gap: .55rem;
      flex: 1;
    }
    .sample-card-body::before {
      display: none;
    }
    .sample-card-title {
      margin: 0;
      font-size: 1.05rem;
      font-weight: 700;
      line-height: 1.3;
      color: var(--text);
    }
    .sample-card-desc {
      margin: 0;
      font-size: .83rem;
      color: var(--muted);
      line-height: 1.5;
    }
    /* ─── Equalizer wave visualizer ─── */
    .sample-eq {
      display: flex;
      align-items: flex-end;
      justify-content: center;
      gap: 4px;
      height: 0;
      margin: 0;
      pointer-events: none;
      opacity: 0;
      overflow: hidden;
      transform: translateY(6px) scaleY(.82);
      transform-origin: center bottom;
      transition: height .4s cubic-bezier(.22, 1, .36, 1), margin .4s cubic-bezier(.22, 1, .36, 1), opacity .3s ease, transform .4s cubic-bezier(.22, 1, .36, 1);
      will-change: height, margin, opacity, transform;
    }
    .sample-eq-bar {
      width: 3px;
      border-radius: 6px;
      background: var(--brand-accent);
      opacity: 0;
      transition: opacity .3s ease, height .4s cubic-bezier(.22, 1, .36, 1);
      will-change: height, opacity;
    }
    /* Idle natural heights */
    .sample-eq-bar:nth-child(1) { height: 8px; }
    .sample-eq-bar:nth-child(2) { height: 16px; }
    .sample-eq-bar:nth-child(3) { height: 10px; }
    .sample-eq-bar:nth-child(4) { height: 22px; }
    .sample-eq-bar:nth-child(5) { height: 14px; }
    .sample-eq-bar:nth-child(6) { height: 20px; }
    .sample-eq-bar:nth-child(7) { height: 8px; }
    .sample-eq-bar:nth-child(8) { height: 18px; }
    .sample-eq-bar:nth-child(9) { height: 12px; }
    .sample-eq-bar:nth-child(10) { height: 24px; }
    .sample-eq-bar:nth-child(11) { height: 6px; }
    .sample-eq-bar:nth-child(12) { height: 16px; }
    /* Playing state */
    .sample-card.is-playing-card .sample-eq {
      height: 32px;
      margin: .25rem 0 .1rem;
      opacity: 1;
      transform: translateY(0) scaleY(1);
    }
    .sample-card.is-playing-card .sample-eq-bar {
      opacity: 1;
      animation: sampleEqPulse .7s ease-in-out infinite;
    }
    .sample-card.is-playing-card .sample-eq-bar:nth-child(1)  { animation-delay: 0s; }
    .sample-card.is-playing-card .sample-eq-bar:nth-child(2)  { animation-delay: .07s; }
    .sample-card.is-playing-card .sample-eq-bar:nth-child(3)  { animation-delay: .14s; }
    .sample-card.is-playing-card .sample-eq-bar:nth-child(4)  { animation-delay: .21s; }
    .sample-card.is-playing-card .sample-eq-bar:nth-child(5)  { animation-delay: .28s; }
    .sample-card.is-playing-card .sample-eq-bar:nth-child(6)  { animation-delay: .35s; }
    .sample-card.is-playing-card .sample-eq-bar:nth-child(7)  { animation-delay: .42s; }
    .sample-card.is-playing-card .sample-eq-bar:nth-child(8)  { animation-delay: .28s; }
    .sample-card.is-playing-card .sample-eq-bar:nth-child(9)  { animation-delay: .14s; }
    .sample-card.is-playing-card .sample-eq-bar:nth-child(10) { animation-delay: .07s; }
    .sample-card.is-playing-card .sample-eq-bar:nth-child(11) { animation-delay: .35s; }
    .sample-card.is-playing-card .sample-eq-bar:nth-child(12) { animation-delay: .21s; }
    @keyframes sampleEqPulse {
      0%, 100% { height: 5px; opacity: .85; }
      50% { height: 28px; opacity: 1; }
    }

    /* ─── Play button ─── circular, icon-only, glowing ─── */
    .sample-play-btn {
      position: relative;
      margin: .4rem auto 1.3rem;
      width: 3.2rem;
      height: 3.2rem;
      border-radius: 999px;
      border: none;
      background: var(--brand-accent);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #ffffff;
      /* box-shadow:
        0 8px 20px color-mix(in oklab, var(--a) 30%, transparent),
        inset 0 1px 1.5px rgba(255,255,255,.4),
        inset 0 -2px 5px color-mix(in oklab, var(--b) 90%, black); */
      transition: all .4s cubic-bezier(0.22, 1, 0.36, 1);
      will-change: transform, box-shadow, filter;
      backface-visibility: hidden;
    }
    /* Outer pulse ring — static */
    .sample-play-btn::before {
      display: none;
    }
    /* Inner gloss sheen */
    .sample-play-btn::after {
      display: none;
    }
    .sample-play-btn:hover {
      transform: translateY(-4px) scale(1.08);
      background: var(--brand-hover);
      box-shadow: var(--shadow);
    }
    .sample-play-btn:active {
      transform: translateY(1px) scale(0.96);
      background: var(--brand-active);
    }
    .sample-play-btn.is-playing {
      background: var(--brand-active);
      transform: scale(0.96);
    }
    .sample-play-btn > * { position: relative; z-index: 1; }
    .sample-play-icon {
      width: 1.4rem;
      height: 1.4rem;
      overflow: visible;
      transition: all .4s cubic-bezier(0.22, 1, 0.36, 1);
    }
    .sample-play-icon path {
      fill: currentColor;
      transform-origin: center;
      transition: opacity .3s ease, transform .4s cubic-bezier(0.22, 1, 0.36, 1);
      will-change: opacity, transform;
    }
    .sample-icon-play {
      opacity: 1;
      transform: scale(1) translate3d(0,0,0);
    }
    .sample-icon-pause {
      opacity: 0;
      transform: scale(.68) translate3d(0,0,0);
    }
    .sample-play-btn.is-playing .sample-icon-play {
      opacity: 0;
      transform: scale(.68) translate3d(-1px,0,0);
    }
    .sample-play-btn.is-playing .sample-icon-pause {
      opacity: 1;
      transform: scale(1) translate3d(0,0,0);
    }
    .sample-play-btn:hover .sample-play-icon {
      transform: scale(1.06);
    }
    /* Light-mode overrides */
    html[data-theme="light"] .sample-card {
      background: var(--surface);
    }
    html[data-theme="light"] .sample-card-body {
      background: var(--surface);
      border-top-color: var(--line);
    }

    html[data-theme="light"] .sample-card-hero::after {
      background:
        linear-gradient(135deg, rgba(15, 23, 42, 0), rgba(15, 23, 42, .1)),
        var(--sample-flag-image, linear-gradient(135deg, color-mix(in oklab, var(--a) 12%, transparent), transparent 65%));
      background-position: center;
      background-repeat: no-repeat;
      background-size: cover;
      filter: saturate(1.15) brightness(1.02) contrast(1.02);
    }

    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-14px); }
    }
    @keyframes pulseGlow {
      0%, 100% { transform: scale(.95); opacity: .35; }
      50% { transform: scale(1.05); opacity: .58; }
    }

    @media (max-width: 960px) {
      .hero-grid,
      .voice-gallery-head,
      .apps-intro,
      .apps-grid,
      .premium-grid {
        grid-template-columns: 1fr;
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
      .voice-gallery-note {
        min-width: 100%;
        min-height: auto;
      }
      .hero-art {
        margin-top: .35rem;
      }
      .hero-art::before {
        width: min(360px, 88%);
        opacity: .38;
      }
      .hero-art img {
        max-width: min(370px, 92vw);
        animation: none;
      }
    }

    @media (max-width: 640px) {
      .hero {
        padding: var(--landing-section-space-mobile) 0;
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

    .hero:has(+ .clone-announce) {
      padding-bottom: 1.75rem;
    }
    .clone-announce {
      padding: 0;
    }
    .clone-announce + .section {
      padding-top: 4.25rem;
    }
    .clone-announce-card {
      display: flex;
      align-items: center;
      gap: 1.25rem;
      padding: 1.15rem 1.35rem;
      border-radius: 1.25rem;
      border: 1px solid color-mix(in oklab, var(--a) 34%, var(--line));
      background:
        radial-gradient(90% 140% at 0% 0%, color-mix(in oklab, var(--a) 16%, transparent), transparent 58%),
        var(--surface);
      box-shadow: 0 18px 40px color-mix(in oklab, var(--a) 12%, transparent);
    }
    html[dir="rtl"] .clone-announce-card {
      background:
        radial-gradient(90% 140% at 100% 0%, color-mix(in oklab, var(--a) 16%, transparent), transparent 58%),
        var(--surface);
    }
    .clone-announce-mark {
      width: 3.5rem;
      height: 3.5rem;
      flex: none;
      border-radius: 1rem;
      display: grid;
      place-items: center;
      color: var(--a);
      background: color-mix(in oklab, var(--a) 14%, var(--surface2));
      border: 1px solid color-mix(in oklab, var(--a) 28%, transparent);
    }
    .clone-announce-wave {
      width: 2.05rem;
      height: 2.05rem;
      display: block;
    }
    .clone-announce-copy {
      flex: 1;
      min-width: 0;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: .2rem;
    }
    .clone-announce-copy h2 {
      margin: 0;
      text-align: start;
      font-size: clamp(1.2rem, 2vw, 1.55rem);
      line-height: 1.35;
      letter-spacing: -.02em;
    }
    html[dir="rtl"] .clone-announce-copy h2 {
      letter-spacing: 0;
      line-height: 1.5;
      font-size: clamp(1.25rem, 2vw, 1.6rem);
    }
    .clone-announce-copy p {
      margin: 0;
      color: var(--muted);
      font-size: 1rem;
      line-height: 1.6;
      max-width: 46ch;
    }
    html[dir="rtl"] .clone-announce-copy p {
      font-size: 1.02rem;
      line-height: 1.75;
    }
    .clone-announce-card .btn {
      flex: none;
      min-height: 48px;
      margin-inline-start: auto;
      white-space: nowrap;
      padding-inline: 1.45rem;
    }
    .clone-announce-card .btn:focus-visible {
      outline: 2px solid var(--text);
      outline-offset: 3px;
    }
    @media (max-width: 760px) {
      .hero:has(+ .clone-announce) {
        padding-bottom: 1.25rem;
      }
      .clone-announce + .section {
        padding-top: 2.75rem;
      }
      .clone-announce-card {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr);
        align-items: center;
        gap: .85rem 1rem;
        padding: 1.05rem 1rem 1rem;
      }
      .clone-announce-card .btn {
        grid-column: 1 / -1;
        width: 100%;
        margin-inline-start: 0;
        margin-top: .15rem;
      }
    }

    /* TTS Illustration CSS */
    .tts-illustration {
      position: relative;
      width: 100%;
      max-width: 580px;
      aspect-ratio: 5 / 4;
      margin: 0 auto;
      perspective: 1200px;
      display: flex;
      align-items: center;
      justify-content: center;
      animation: float 8s ease-in-out infinite;
    }

    .tts-card {
      position: relative;
      width: 90%;
      height: auto;
      min-height: 75%;
      border-radius: 1.5rem;
      background: var(--surface);
      border: 1px solid var(--line);
      box-shadow: var(--shadow);
      transform: rotateY(-15deg) rotateX(10deg) translateZ(0);
      will-change: transform;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    html[dir="rtl"] .tts-card {
      transform: rotateY(15deg) rotateX(10deg) translateZ(0);
    }

    .tts-header {
      padding: 1rem 1.5rem;
      border-bottom: 1px solid var(--line);
      display: flex;
      gap: 8px;
    }

    .tts-dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: var(--line);
    }
    .tts-dot:nth-child(1) { background: var(--danger); }
    .tts-dot:nth-child(2) { background: var(--ok); }

    .tts-body {
      flex: 1;
      padding: 1.5rem 1.5rem 2rem;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 1rem;
    }

    .hero-audio-player {
      width: 100%;
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 0.75rem;
      min-height: 2.5em;
    }

    .hero-audio-btn {
      width: 64px;
      height: 64px;
      border: 1px solid color-mix(in oklab, rgba(255,255,255,.2) 50%, transparent);
      border-radius: 50%;
      background: linear-gradient(135deg, var(--a), var(--b));
      color: white;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all .4s cubic-bezier(0.22, 1, 0.36, 1);
      box-shadow:
        0 8px 24px color-mix(in oklab, var(--a) 30%, transparent),
        inset 0 1px 1.5px rgba(255,255,255,.4),
        inset 0 -2px 5px color-mix(in oklab, var(--b) 90%, black);
      padding: 0;
      position: relative;
      z-index: 2;
      will-change: transform, box-shadow, filter;
      backface-visibility: hidden;
    }

    .hero-audio-btn:hover {
      transform: translateY(-2px) scale(1.05);
      box-shadow:
        0 14px 30px color-mix(in oklab, var(--a) 45%, rgba(0,0,0,.2)),
        inset 0 1px 1.5px rgba(255,255,255,.5),
        inset 0 -2px 5px color-mix(in oklab, var(--b) 90%, black);
      filter: brightness(1.05);
    }

    .hero-audio-btn:active {
      transform: translateY(1px) scale(0.98);
      box-shadow:
        0 4px 10px color-mix(in oklab, var(--a) 25%, transparent),
        inset 0 2px 4px rgba(0,0,0,.3);
    }

    .hero-audio-btn.is-playing {
      background: var(--brand-active);
      box-shadow: var(--shadow);
      border-color: var(--brand-active);
    }

    .hero-audio-btn:not(.is-playing) {
      animation: heroHeartbeat 1.8s cubic-bezier(0.22, 1, 0.36, 1) infinite;
    }

    .hero-audio-btn::before {
      display: none;
    }

    .hero-audio-btn::after {
      display: none;
    }

    .hero-audio-rings {
      position: absolute;
      top: 0;
      left: 50%;
      width: 64px;
      height: 64px;
      transform: translateX(-50%);
      pointer-events: none;
      z-index: 1;
    }

    .hero-audio-rings::before,
    .hero-audio-rings::after,
    .hero-audio-rings span {
      content: "";
      position: absolute;
      inset: -2px;
      border-radius: 50%;
      border: 1px solid color-mix(in oklab, white 70%, var(--a) 30%);
      opacity: 0;
      transform: scale(0.78);
      box-shadow: 0 0 0 1px color-mix(in oklab, var(--a) 16%, transparent), 0 0 28px color-mix(in oklab, var(--a) 22%, transparent);
      will-change: transform, opacity;
    }

    .hero-audio-btn:not(.is-playing) + .hero-audio-rings::before {
      animation: heroRingFade 1.8s linear infinite;
    }

    .hero-audio-btn:not(.is-playing) + .hero-audio-rings::after {
      animation: heroRingFade 1.8s linear infinite .6s;
    }

    .hero-audio-btn:not(.is-playing) + .hero-audio-rings span {
      animation: heroRingFade 1.8s linear infinite 1.2s;
    }

    .hero-audio-btn.is-playing + .hero-audio-rings::before,
    .hero-audio-btn.is-playing + .hero-audio-rings::after,
    .hero-audio-btn.is-playing + .hero-audio-rings span {
      animation: none;
      opacity: 0;
      transform: scale(0.9);
    }

    .hero-audio-btn:hover {
      transform: scale(1.06) translateY(-2px);
      box-shadow: 0 14px 34px color-mix(in oklab, var(--a) 52%, transparent), inset 0 2px 4px rgba(255,255,255,0.38);
      filter: saturate(1.08);
    }

    .hero-audio-btn:hover::before {
      opacity: 0.6;
      transform: scale(1.1);
    }

    .hero-audio-btn:hover::after {
      opacity: 0.5;
      transform: scale(1.03);
    }

    .hero-audio-btn:hover + .hero-audio-rings::before,
    .hero-audio-btn:hover + .hero-audio-rings::after,
    .hero-audio-btn:hover + .hero-audio-rings span {
      border-color: color-mix(in oklab, white 76%, var(--a) 24%);
      animation-duration: 1.55s;
    }

    html[data-theme="light"] .hero-audio-rings::before,
    html[data-theme="light"] .hero-audio-rings::after,
    html[data-theme="light"] .hero-audio-rings span {
      border-color: color-mix(in oklab, var(--a) 58%, var(--b) 42%);
      box-shadow:
        0 0 0 1px color-mix(in oklab, var(--a) 34%, transparent),
        0 0 24px color-mix(in oklab, var(--a) 42%, transparent);
    }

    html[data-theme="light"] .hero-audio-btn:not(.is-playing) + .hero-audio-rings::before,
    html[data-theme="light"] .hero-audio-btn:not(.is-playing) + .hero-audio-rings::after,
    html[data-theme="light"] .hero-audio-btn:not(.is-playing) + .hero-audio-rings span {
      animation-name: heroRingFadeLight;
    }

    .hero-audio-btn:active {
      transform: scale(0.95);
    }

    .hero-audio-btn:focus-visible {
      outline: 2px solid color-mix(in oklab, var(--a) 72%, white);
      outline-offset: 4px;
    }

    .hero-audio-btn svg {
      width: 28px;
      height: 28px;
      fill: currentColor;
      margin-left: 4px;
      transition: margin .4s cubic-bezier(0.22, 1, 0.36, 1), transform .4s cubic-bezier(0.22, 1, 0.36, 1);
      flex-shrink: 0;
      will-change: margin, transform;
    }

    .hero-audio-btn.is-playing {
      box-shadow: 0 14px 32px color-mix(in oklab, var(--a) 50%, transparent), inset 0 2px 4px rgba(255,255,255,0.4);
      animation: btnFloat 3s ease-in-out infinite;
      filter: saturate(1.05);
    }

    .hero-audio-btn.is-playing::before {
      opacity: 0.6;
      transform: scale(1.15);
      animation: glowPulse 2s infinite alternate;
    }

    .hero-audio-btn.is-playing::after {
      opacity: 0.22;
      transform: scale(1.02);
      transition: opacity .3s ease, transform .3s ease;
    }

    .hero-audio-btn.is-playing svg {
      margin-left: 0;
    }

    @keyframes heroHeartbeat {
      0%, 100% { transform: scale(1); }
      12% { transform: scale(1.045); }
      24% { transform: scale(0.992); }
      36% { transform: scale(1.03); }
      48% { transform: scale(1); }
      62% { transform: scale(1.022); }
      74% { transform: scale(0.996); }
      86% { transform: scale(1.012); }
    }

    @keyframes heroRingFade {
      0% { opacity: 0; transform: scale(0.78); }
      14% { opacity: 0.48; }
      45% { opacity: 0.18; }
      100% { opacity: 0; transform: scale(1.82); }
    }

    @keyframes heroRingFadeLight {
      0% { opacity: 0; transform: scale(0.78); }
      14% { opacity: 0.62; }
      45% { opacity: 0.24; }
      100% { opacity: 0; transform: scale(1.78); }
    }

    @keyframes btnFloat {
      0%, 100% { transform: scale(1.08) translateY(-2px); }
      50% { transform: scale(1.08) translateY(-6px); }
    }

    @keyframes glowPulse {
      0% { opacity: 0.4; transform: scale(1.1); }
      100% { opacity: 0.7; transform: scale(1.25); filter: blur(12px); }
    }

    @keyframes idleGlow {
      0% { opacity: 0.1; transform: scale(1); }
      100% { opacity: 0.4; transform: scale(1.05); }
    }

    .hero-audio-content {
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .hero-audio-captions-wrapper {
      position: absolute;
      top: 100%;
      margin-top: 1.25rem;
      left: 0;
      right: 0;
      display: flex;
      justify-content: center;
      pointer-events: none;
    }

    .hero-audio-caption {
      position: absolute;
      font-size: 0.85rem;
      font-weight: 500;
      letter-spacing: 0.15em;
      text-transform: uppercase;
      color: color-mix(in oklab, var(--text) 90%, transparent);
      text-shadow: 0 0 16px color-mix(in oklab, var(--text) 50%, transparent), 0 0 8px color-mix(in oklab, var(--a) 30%, transparent);
      white-space: nowrap;
      transition: opacity 0.3s ease, transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
      will-change: opacity, transform;
    }

    html[dir="rtl"] .hero-audio-caption {
      letter-spacing: 0;
      font-size: 0.95rem;
    }

    .active-caption {
      opacity: 0;
      transform: translateY(-8px);
    }

    .tts-body.is-playing .active-caption {
      opacity: 1;
      transform: translateY(0);
    }

    .tts-body.is-playing .active-caption.is-switching {
      opacity: 0;
      transform: translateY(4px);
    }

    .idle-caption {
      opacity: 1;
      transform: translateY(0);
    }

    .tts-body.is-playing .idle-caption {
      opacity: 0;
      transform: translateY(8px);
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
      box-shadow: 0 0 8px color-mix(in oklab, var(--a) 60%, var(--b) 40%);
      border-radius: 1px;
      opacity: 1;
      transition: opacity 0.18s ease;
    }

    .tts-body.is-playing .idle-caption::after {
      opacity: 0;
    }

    @keyframes blinkCaret {
      0%, 100% { opacity: 1; }
      50% { opacity: 0; }
    }

    /* Dynamic 12-bar Sound Wave Animation */
    .tts-audio-wave {
      display: flex;
      align-items: flex-end;
      justify-content: center;
      gap: 6px;
      height: 0;
      opacity: 0;
      flex-shrink: 0;
      margin-top: 0;
      overflow: hidden;
      transition: height 0.6s cubic-bezier(0.22, 1, 0.36, 1), margin-top 0.6s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.5s ease;
    }

    .tts-body.is-playing .tts-audio-wave {
      height: 48px;
      opacity: 1;
      margin-top: 2.5rem;
    }

    .tts-bar {
      width: 8px;
      height: 44px;
      border-radius: var(--radius-full);
      background: var(--brand-accent);
      opacity: 0.5;
      transform: scaleY(0.27);
      transform-origin: bottom;
      transition: transform 0.3s ease, opacity 0.3s ease;
      will-change: transform;
    }

    .tts-body.is-playing .tts-bar {
      opacity: 1;
      animation: heroWavePulse 0.85s ease-in-out infinite;
    }
    .tts-body.is-playing .tts-bar:nth-child(2) { animation-delay: .1s; }
    .tts-body.is-playing .tts-bar:nth-child(3) { animation-delay: .2s; }
    .tts-body.is-playing .tts-bar:nth-child(4) { animation-delay: .3s; }
    .tts-body.is-playing .tts-bar:nth-child(5) { animation-delay: .4s; }
    .tts-body.is-playing .tts-bar:nth-child(6) { animation-delay: .5s; }
    .tts-body.is-playing .tts-bar:nth-child(7) { animation-delay: .6s; }
    .tts-body.is-playing .tts-bar:nth-child(8) { animation-delay: .7s; }
    .tts-body.is-playing .tts-bar:nth-child(9) { animation-delay: .8s; }
    .tts-body.is-playing .tts-bar:nth-child(10) { animation-delay: .9s; }
    .tts-body.is-playing .tts-bar:nth-child(11) { animation-delay: 1.0s; }
    .tts-body.is-playing .tts-bar:nth-child(12) { animation-delay: 1.1s; }
    .tts-body.is-playing .tts-bar:nth-child(13) { animation-delay: 1.2s; }
    .tts-body.is-playing .tts-bar:nth-child(14) { animation-delay: 1.3s; }
    .tts-body.is-playing .tts-bar:nth-child(15) { animation-delay: 1.4s; }
    .tts-body.is-playing .tts-bar:nth-child(16) { animation-delay: 1.5s; }
    .tts-body.is-playing .tts-bar:nth-child(17) { animation-delay: 1.6s; }
    .tts-body.is-playing .tts-bar:nth-child(18) { animation-delay: 1.7s; }

    @keyframes heroWavePulse {
      0%, 100% { transform: scaleY(0.27); }
      50% { transform: scaleY(1); }
    }

    .tts-glowing-orb {
      display: none;
    }

    @keyframes pulse-glow {
      0% { opacity: 0.3; transform: translate(-50%, -50%) translateZ(-50px) scale(0.8); }
      100% { opacity: 0.6; transform: translate(-50%, -50%) translateZ(-50px) scale(1.2); }
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
      .apps-grid,
      .premium-grid {
        grid-template-columns: 1fr;
      }
      .apps-intro {
        grid-template-columns: 1fr;
        text-align: center;
      }
      .apps-points {
        justify-content: center;
      }
    }

    @media (prefers-reduced-motion: reduce) {
      .hero-audio-btn,
      .hero-audio-btn::before,
      .hero-audio-btn::after,
      .hero-audio-rings::before,
      .hero-audio-rings::after,
      .hero-audio-rings span {
        animation: none !important;
      }
      .hero-audio-rings {
        display: none;
      }
      .hero-audio-btn {
        transition: box-shadow .2s ease, background .2s ease;
      }
      .hero-audio-btn:hover {
        transform: none;
      }
    }

    @media (max-width: 640px) {
      .hero-art {
        padding-inline: .5rem;
      }
      .tts-illustration {
        aspect-ratio: 1 / 1.18;
        width: 100%;
        max-width: min(380px, 92vw);
        animation: none;
      }
      .tts-card {
        width: 100%;
        min-height: 100%;
        margin-inline: auto;
        transform: rotateY(-15deg) rotateX(10deg) translateZ(0);
        transform-origin: center center;
        backface-visibility: hidden;
      }
      .tts-glowing-orb {
        animation: none;
      }
      .bg-orb {
        animation: none;
      }
      .tts-body {
        padding: 1.35rem 1rem 1.85rem;
        gap: 1.2rem;
      }
      .hero-audio-player {
        min-height: 9.5rem;
        justify-content: flex-start;
        align-items: center;

      }
      .hero-audio-content {
        width: min(260px, 88vw);
        margin-inline: auto;
        align-items: center;
        margin-top: 25%;
      }
      .hero-audio-captions-wrapper {
        position: relative;
        top: auto;
        left: auto;
        right: auto;
        margin-top: 2rem;
        min-height: 2.9rem;
        width: 100%;
        justify-content: center;
      }
      .hero-audio-caption {
        font-size: 0.72rem;
        letter-spacing: 0.1em;
        margin-top: 0;
        white-space: normal;
        width: 100%;
        max-width: none;
        text-align: center;
        line-height: 1.4;
        left: 50%;
        right: auto;
        transform: translate(-50%, 0);
      }
      html[dir="rtl"] .hero-audio-caption {
        letter-spacing: 0;
        font-size: 0.82rem;
      }
      .active-caption {
        transform: translate(-50%, -8px);
      }
      .tts-body.is-playing .active-caption {
        transform: translate(-50%, 0);
      }
      .tts-body.is-playing .active-caption.is-switching {
        transform: translate(-50%, 4px);
      }
      .idle-caption {
        transform: translate(-50%, 0);
      }
      .tts-body.is-playing .idle-caption {
        transform: translate(-50%, 8px);
      }
      .tts-audio-wave {
        gap: 4px;
      }
      .tts-body.is-playing .tts-audio-wave {
        height: 48px;
        margin-top: 1.4rem;
      }
      .tts-bar {
        width: 6px;
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
          <!-- <span class="eyebrow">Enterprise Voice Platform</span> -->
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
        </div>

        <aside class="hero-art reveal">
          <div class="tts-illustration">
            <div class="tts-glowing-orb"></div>
            <div class="tts-card">
              <div class="tts-header">
                <div class="tts-dot"></div>
                <div class="tts-dot"></div>
                <div class="tts-dot"></div>
              </div>
              <div class="tts-body">
                <div class="hero-audio-player">
                  <div class="hero-audio-content">
                    @php
                      $isArabicLocale = str_starts_with(strtolower((string) app()->getLocale()), 'ar');
                      $heroSampleSource = ($heroVoiceSample['audio_url'] ?? null)
                        ?? ($isArabicLocale ? '/audio/ar.mp3' : '/audio/en.mp3');
                    @endphp
                    <button class="hero-audio-btn" id="heroAudioToggle" type="button" aria-label="{{ __('ui.landing.play_voice_sample') }}" aria-controls="heroSampleAudio">
                      <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path id="heroAudioIconPath" d="M8 5v14l11-7z"></path>
                      </svg>
                    </button>
                    <div class="hero-audio-rings" aria-hidden="true"><span></span></div>
                    <div class="hero-audio-captions-wrapper">
                      <p class="hero-audio-caption idle-caption" id="heroAudioIdleCaption"></p>
                      <p class="hero-audio-caption active-caption" id="heroAudioActiveCaption">{{ str_starts_with(strtolower((string) app()->getLocale()), 'ar') ? __('ui.landing.arabic_sample_label') : __('ui.landing.english_sample_label') }}</p>
                    </div>
                  </div>
                  <audio id="heroSampleAudio" class="initialized" preload="auto">
                    <source src="{{ $heroSampleSource }}">
                  </audio>
                </div>
                <div class="tts-audio-wave">
                  <div class="tts-bar"></div><div class="tts-bar"></div><div class="tts-bar"></div><div class="tts-bar"></div><div class="tts-bar"></div><div class="tts-bar"></div>
                  <div class="tts-bar"></div><div class="tts-bar"></div><div class="tts-bar"></div><div class="tts-bar"></div><div class="tts-bar"></div><div class="tts-bar"></div>
                  <div class="tts-bar"></div><div class="tts-bar"></div><div class="tts-bar"></div><div class="tts-bar"></div><div class="tts-bar"></div><div class="tts-bar"></div>
                </div>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </section>

    <section class="clone-announce" aria-labelledby="cloneAnnounceTitle">
      <div class="clone-announce-card">
        <div class="clone-announce-mark" aria-hidden="true">
          <svg class="clone-announce-wave" viewBox="0 0 128 128" fill="currentColor">
            <rect x="8" y="59" width="10" height="10" rx="5"/>
            <rect x="26" y="42" width="10" height="44" rx="5"/>
            <rect x="44" y="28" width="10" height="72" rx="5"/>
            <rect x="62" y="14" width="10" height="100" rx="5"/>
            <rect x="80" y="28" width="10" height="72" rx="5"/>
            <rect x="98" y="42" width="10" height="44" rx="5"/>
            <rect x="116" y="59" width="10" height="10" rx="5"/>
          </svg>
        </div>
        <div class="clone-announce-copy">
          <h2 id="cloneAnnounceTitle">{{ __('ui.landing.clone_announce_title') }}</h2>
          <p>{{ __('ui.landing.clone_announce_body') }}</p>
        </div>
        <a class="btn btn-primary" href="{{ route('pricing') }}">{{ __('ui.landing.clone_announce_cta') }}</a>
      </div>
    </section>

    {{-- Voice Samples Showcase --}}
    @if(!empty($voiceSamples))
    <section class="section reveal" id="voice-samples">
      <div class="voice-gallery-shell">
        <div class="voice-gallery-inner">
          <div class="voice-gallery-head">
            <div class="voice-gallery-copy">
              @if(str_starts_with(strtolower((string) app()->getLocale()), 'ar'))
                <h2 class="voice-gallery-title">لهجات من جميع أنحاء العالم العربي</h2>
                <p class="voice-gallery-subtitle">نماذج صوتية من بلدان متعددة بلهجات طبيعية وأداء احترافي يناسب مختلف أنواع المحتوى.</p>
              @else
                <h2 class="voice-gallery-title">Voices from Across the English-Speaking World</h2>
                <p class="voice-gallery-subtitle">Explore voice samples from different countries with natural accents and professional delivery for all types of content.</p>
              @endif
            </div>
          </div>
          <div class="sample-showcase-track">
          @foreach($voiceSamples as $idx => $sample)
          @php
            $countryCode = strtolower(trim((string) ($sample['country'] ?? '')));
            $sampleLanguage = strtolower(trim((string) ($sample['language'] ?? '')));
            $flagImage = preg_match('/^[a-z]{2}$/', $countryCode)
              ? "https://flagcdn.com/{$countryCode}.svg"
              : null;
            $usesInlineArabicFallback = ! $flagImage && $sampleLanguage === 'ar';
            $fallbackSvg = $sampleLanguage === 'ar'
              ? <<<'SVG'
<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 640'>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@800&amp;display=swap');
  </style>
  <defs>
    <radialGradient id="gradBg" cx="50%" cy="50%" r="70%">
      <stop offset="0%" stop-color="#141E30"/>
      <stop offset="100%" stop-color="#040914"/>
    </radialGradient>
    <linearGradient id="textGrad" x1="0" y1="0" x2="1" y2="1">
       <stop offset="0%" stop-color="#ffffff"/>
       <stop offset="100%" stop-color="#94a3b8"/>
    </linearGradient>
    <linearGradient id="gold" x1="0" y1="0" x2="1" y2="1">
       <stop offset="0%" stop-color="#fcd34d"/>
       <stop offset="100%" stop-color="#d97706"/>
    </linearGradient>
    <filter id="glow">
      <feGaussianBlur stdDeviation="20" result="coloredBlur"/>
      <feMerge>
        <feMergeNode in="coloredBlur"/>
        <feMergeNode in="SourceGraphic"/>
      </feMerge>
    </filter>
  </defs>

  <rect width="1200" height="640" fill="url(#gradBg)"/>

  <path d="M-100 320 C 250 100, 350 540, 600 320 C 850 100, 950 540, 1300 320" fill="none" stroke="url(#gold)" stroke-width="4" opacity="0.3" filter="url(#glow)"/>
  <path d="M-100 360 C 250 140, 350 580, 600 360 C 850 140, 950 580, 1300 360" fill="none" stroke="url(#gold)" stroke-width="1.5" opacity="0.3"/>
  <path d="M-100 280 C 250 60, 350 500, 600 280 C 850 60, 950 500, 1300 280" fill="none" stroke="url(#gold)" stroke-width="1.5" opacity="0.3"/>

  <g text-anchor="middle" font-family="'Cairo', sans-serif">
    <text x="600" y="350" font-size="130" font-weight="800" fill="url(#textGrad)">العربية الفصحى</text>
  </g>
</svg>
SVG
              : <<<'SVG'
<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 640'>
  <defs>
    <linearGradient id='bg' x1='0' y1='0' x2='1' y2='1'>
      <stop offset='0%' stop-color='#151d2f'/>
      <stop offset='100%' stop-color='#243553'/>
    </linearGradient>
    <linearGradient id='accent' x1='0' y1='0' x2='1' y2='1'>
      <stop offset='0%' stop-color='#78c6ff'/>
      <stop offset='100%' stop-color='#ccecff'/>
    </linearGradient>
  </defs>
  <rect width='1200' height='640' fill='url(#bg)'/>
  <g opacity='.14' fill='none' stroke='url(#accent)'>
    <circle cx='188' cy='132' r='90' stroke-width='10'/>
    <circle cx='1010' cy='498' r='120' stroke-width='8'/>
    <path d='M114 512c96-88 206-132 330-132 124 0 242 36 354 108 86 56 170 84 252 84' stroke-width='8'/>
  </g>
  <g transform='translate(600 270)'>
    <rect x='-220' y='-110' width='440' height='220' rx='36' fill='rgba(7,12,24,.24)' stroke='rgba(204,236,255,.28)' stroke-width='3'/>
    <circle cx='0' cy='0' r='70' fill='none' stroke='url(#accent)' stroke-width='10'/>
    <circle cx='0' cy='0' r='30' fill='url(#accent)' opacity='.94'/>
    <path d='M-138 0h44M94 0h44M0-138v44M0 94v44' stroke='url(#accent)' stroke-width='10' stroke-linecap='round'/>
    <path d='M-92-92l30 30M92-92l-30 30M-92 92l30-30M92 92l-30-30' stroke='url(#accent)' stroke-width='10' stroke-linecap='round'/>
  </g>
  <g transform='translate(354 458)'>
    <rect x='0' y='38' width='18' height='44' rx='9' fill='url(#accent)'/>
    <rect x='36' y='16' width='18' height='88' rx='9' fill='url(#accent)'/>
    <rect x='72' y='0' width='18' height='120' rx='9' fill='url(#accent)'/>
    <rect x='108' y='24' width='18' height='72' rx='9' fill='url(#accent)'/>
    <rect x='144' y='10' width='18' height='98' rx='9' fill='url(#accent)'/>
    <rect x='180' y='28' width='18' height='64' rx='9' fill='url(#accent)'/>
    <rect x='216' y='6' width='18' height='106' rx='9' fill='url(#accent)'/>
    <rect x='252' y='18' width='18' height='82' rx='9' fill='url(#accent)'/>
    <rect x='288' y='34' width='18' height='48' rx='9' fill='url(#accent)'/>
  </g>
  <g opacity='.22' fill='url(#accent)'>
    <circle cx='340' cy='530' r='8'/>
    <circle cx='860' cy='138' r='8'/>
    <circle cx='910' cy='214' r='5'/>
  </g>
</svg>
SVG;
            $heroArtwork = $flagImage
              ? "url('{$flagImage}')"
              : ($usesInlineArabicFallback ? null : "url(\"data:image/svg+xml," . rawurlencode($fallbackSvg) . "\")");
          @endphp
          <article class="sample-card reveal" data-sample-idx="{{ $idx }}" id="sample-card-{{ $idx }}">
            <div
              class="sample-card-hero{{ $usesInlineArabicFallback ? ' has-inline-art' : '' }}"
              @if($heroArtwork)
                style="--sample-flag-image: {{ $heroArtwork }};"
              @endif
            >
              @if($usesInlineArabicFallback)
              <div class="sample-card-hero-svg" aria-hidden="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 640" preserveAspectRatio="xMidYMid slice">
                  <defs>
                    <radialGradient id="sample-msa-grad-bg-{{ $idx }}" cx="50%" cy="50%" r="70%">
                      <stop offset="0%" stop-color="#141E30"/>
                      <stop offset="100%" stop-color="#040914"/>
                    </radialGradient>
                    <linearGradient id="sample-msa-text-grad-{{ $idx }}" x1="0" y1="0" x2="1" y2="1">
                      <stop offset="0%" stop-color="#ffffff"/>
                      <stop offset="100%" stop-color="#94a3b8"/>
                    </linearGradient>
                    <linearGradient id="sample-msa-gold-{{ $idx }}" x1="0" y1="0" x2="1" y2="1">
                      <stop offset="0%" stop-color="#fcd34d"/>
                      <stop offset="100%" stop-color="#d97706"/>
                    </linearGradient>
                    <filter id="sample-msa-glow-{{ $idx }}">
                      <feGaussianBlur stdDeviation="20" result="coloredBlur"/>
                      <feMerge>
                        <feMergeNode in="coloredBlur"/>
                        <feMergeNode in="SourceGraphic"/>
                      </feMerge>
                    </filter>
                  </defs>

                  <rect width="1200" height="640" fill="url(#sample-msa-grad-bg-{{ $idx }})"/>
                  <path d="M-100 320 C 250 100, 350 540, 600 320 C 850 100, 950 540, 1300 320" fill="none" stroke="url(#sample-msa-gold-{{ $idx }})" stroke-width="4" opacity="0.3" filter="url(#sample-msa-glow-{{ $idx }})"/>
                  <path d="M-100 360 C 250 140, 350 580, 600 360 C 850 140, 950 580, 1300 360" fill="none" stroke="url(#sample-msa-gold-{{ $idx }})" stroke-width="1.5" opacity="0.3"/>
                  <path d="M-100 280 C 250 60, 350 500, 600 280 C 850 60, 950 500, 1300 280" fill="none" stroke="url(#sample-msa-gold-{{ $idx }})" stroke-width="1.5" opacity="0.3"/>

                  <g text-anchor="middle" direction="rtl">
                    <text x="600" y="350" font-size="130" fill="url(#sample-msa-text-grad-{{ $idx }})" class="sample-card-hero-msa-label">العربية الفصحى</text>
                  </g>
                </svg>
              </div>
              @endif
            </div>
            <div class="sample-card-body">
              <h3 class="sample-card-title">{{ $sample['title'] }}</h3>
              <p class="sample-card-desc">{{ $sample['description'] }}</p>
              <div class="sample-eq" aria-hidden="true">
                <div class="sample-eq-bar"></div>
                <div class="sample-eq-bar"></div>
                <div class="sample-eq-bar"></div>
                <div class="sample-eq-bar"></div>
                <div class="sample-eq-bar"></div>
                <div class="sample-eq-bar"></div>
                <div class="sample-eq-bar"></div>
                <div class="sample-eq-bar"></div>
                <div class="sample-eq-bar"></div>
                <div class="sample-eq-bar"></div>
                <div class="sample-eq-bar"></div>
                <div class="sample-eq-bar"></div>
              </div>
            @if($sample['audio_url'])
            <button class="sample-play-btn" type="button"
                    data-audio-src="{{ $sample['audio_url'] }}"
                    data-card-id="sample-card-{{ $idx }}"
                    data-play-aria-label="{{ __('ui.landing.play_voice_sample') }}"
                    data-pause-aria-label="{{ __('ui.landing.pause_voice_sample') }}"
                    aria-label="{{ __('ui.landing.play_voice_sample') }}">
              <svg viewBox="0 0 24 24" aria-hidden="true" class="sample-play-icon" fill="currentColor">
                <path class="sample-icon-play" fill-rule="evenodd" clip-rule="evenodd" d="M9.315 5.28a.75.75 0 0 0-1.215-.56L4.35 7.5H2.25a.75.75 0 0 0-.75.75v7.5c0 .414.336.75.75.75h2.1l3.75 2.78a.75.75 0 0 0 1.215-.56V5.28Zm5.955 1.545a.75.75 0 0 1 1.06 0 6.75 6.75 0 0 1 0 9.546.75.75 0 1 1-1.06-1.061 5.25 5.25 0 0 0 0-7.424.75.75 0 0 1 0-1.061Zm2.652-2.652a.75.75 0 0 1 1.06 0 10.5 10.5 0 0 1 0 14.85.75.75 0 1 1-1.06-1.061 9 9 0 0 0 0-12.728.75.75 0 0 1 0-1.061Z"/>
                <path class="sample-icon-pause" fill-rule="evenodd" clip-rule="evenodd" d="M6.75 5.25a.75.75 0 0 1 .75-.75H9a.75.75 0 0 1 .75.75v13.5a.75.75 0 0 1-.75.75H7.5a.75.75 0 0 1-.75-.75V5.25Zm7.5 0a.75.75 0 0 1 .75-.75h1.5a.75.75 0 0 1 .75.75v13.5a.75.75 0 0 1-.75.75H15a.75.75 0 0 1-.75-.75V5.25Z"/>
              </svg>
            </button>
            @endif
            </div>
          </article>
          @endforeach
          </div>
        </div>
      </div>
    </section>
    @endif

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
              <div class="app-icon" aria-hidden="true" style="filter: grayscale(100%) contrast(1.2) brightness(1.2);">
                <svg viewBox="0 0 24 24">
                  <circle cx="12" cy="12" r="8.5"></circle>
                  <path d="M3.5 12h17"></path>
                  <path d="M12 3.5a14 14 0 0 1 0 17"></path>
                  <path d="M12 3.5a14 14 0 0 0 0 17"></path>
                </svg>
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
              <div class="app-icon" aria-hidden="true" style="filter: grayscale(100%) contrast(1.2) brightness(1.2);">
                <svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="12" r="10"></circle>
                  <circle cx="12" cy="12" r="4"></circle>
                  <line x1="21.17" y1="8" x2="12" y2="8"></line>
                  <line x1="3.95" y1="6.06" x2="8.54" y2="14"></line>
                  <line x1="10.88" y1="21.94" x2="15.46" y2="14"></line>
                </svg>
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
          <h3>{{ __('ui.landing.section2_card1_title') }}</h3>
          <p>{{ __('ui.landing.section2_card1_body') }}</p>
          <span class="premium-metric">{{ __('ui.landing.section2_card1_metric') }}</span>
        </article>
        <article class="premium-card reveal">
          <h3>{{ __('ui.landing.section2_card2_title') }}</h3>
          <p>{{ __('ui.landing.section2_card2_body') }}</p>
          <span class="premium-metric">{{ __('ui.landing.section2_card2_metric') }}</span>
        </article>
        <article class="premium-card reveal">
          <h3>{{ __('ui.landing.section2_card3_title') }}</h3>
          <p>{{ __('ui.landing.section2_card3_body') }}</p>
          <span class="premium-metric">{{ __('ui.landing.section2_card3_metric') }}</span>
        </article>
        <article class="premium-card reveal">
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
  @php
    $landingI18n = [
      'pause_voice_sample' => __('ui.landing.pause_voice_sample'),
      'play_voice_sample' => __('ui.landing.play_voice_sample'),
      'phrase1' => __('ui.landing.typing_phrase_1'),
      'phrase2' => __('ui.landing.typing_phrase_2'),
      'phrase3' => __('ui.landing.typing_phrase_3'),
      'phrase4' => __('ui.landing.typing_phrase_4'),
      'arabic_sample_label' => __('ui.landing.arabic_sample_label'),
      'english_sample_label' => __('ui.landing.english_sample_label'),
    ];
  @endphp
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const audio = document.getElementById('heroSampleAudio');
      const toggle = document.getElementById('heroAudioToggle');
      const iconPath = document.getElementById('heroAudioIconPath');
      const idleCaption = document.getElementById('heroAudioIdleCaption');
      const activeCaption = document.getElementById('heroAudioActiveCaption');
      const ttsBody = toggle ? toggle.closest('.tts-body') : null;

      if (!audio || !toggle || !iconPath || !idleCaption || !activeCaption || !ttsBody) return;

      const playPath = 'M8 5v14l11-7z';
      const pausePath = 'M6 5h4v14H6zM14 5h4v14h-4z';
      const i18n = @json($landingI18n);
      const isArabicLocale = String(document.documentElement.lang || '').toLowerCase().startsWith('ar');
      let isPlayingState = false;
      let typingTimer = null;
      let captionSwapTimer = null;
      let syncFrame = null;
      let activeCaptionTarget = activeCaption.textContent;
      let phraseIndex = 0;
      let charIndex = 0;
      let isDeleting = false;
      let heroPlayPromise = null;
      let heroPlayRequestId = 0;

      const localeStartCaption = () => (isArabicLocale ? i18n.arabic_sample_label : i18n.english_sample_label);

      const setState = (isPlaying) => {
        if (isPlayingState === isPlaying) return;
        isPlayingState = isPlaying;
        toggle.classList.toggle('is-playing', isPlaying);
        ttsBody.classList.toggle('is-playing', isPlaying);
        iconPath.setAttribute('d', isPlaying ? pausePath : playPath);
        toggle.setAttribute('aria-label', isPlaying ? i18n.pause_voice_sample : i18n.play_voice_sample);

        if (isPlaying) {
          stopTyping();
        } else {
          startTyping();
        }
      };

      const phrases = [i18n.phrase1, i18n.phrase2, i18n.phrase3, i18n.phrase4];

      const typeLoop = () => {
        if (isPlayingState) return;
        const currentPhrase = phrases[phraseIndex];

        if (isDeleting) {
          charIndex -= 1;
        } else {
          charIndex += 1;
        }

        if (charIndex < 0) charIndex = 0;
        if (charIndex > currentPhrase.length) charIndex = currentPhrase.length;
        idleCaption.textContent = currentPhrase.substring(0, charIndex);

        let typeSpeed = isDeleting ? 26 : 58;

        if (!isDeleting && charIndex === currentPhrase.length) {
          typeSpeed = 2100;
          isDeleting = true;
        } else if (isDeleting && charIndex === 0) {
          isDeleting = false;
          phraseIndex = (phraseIndex + 1) % phrases.length;
          typeSpeed = 420;
        }

        typingTimer = window.setTimeout(typeLoop, typeSpeed);
      };

      const startTyping = () => {
        if (isPlayingState || typingTimer) return;
        typingTimer = window.setTimeout(typeLoop, 200);
      };

      const stopTyping = () => {
        if (!typingTimer) return;
        window.clearTimeout(typingTimer);
        typingTimer = null;
      };

      startTyping();

      const updateActiveCaption = (text) => {
        if (activeCaptionTarget === text) return;
        activeCaptionTarget = text;
        if (captionSwapTimer) {
          window.clearTimeout(captionSwapTimer);
          captionSwapTimer = null;
        }

        activeCaption.classList.add('is-switching');
        activeCaption.textContent = activeCaptionTarget;
        captionSwapTimer = window.setTimeout(() => {
          activeCaption.classList.remove('is-switching');
          captionSwapTimer = null;
        }, 16);
      };

      const resetToLocaleStart = () => {
        audio.currentTime = 0;
        const caption = localeStartCaption();
        activeCaptionTarget = caption;
        activeCaption.textContent = caption;
      };

      const syncActiveCaptionWithTime = () => {
        updateActiveCaption(localeStartCaption());
      };

      audio.addEventListener('loadedmetadata', () => {
        if (!isPlayingState) {
          resetToLocaleStart();
        }
      });

      resetToLocaleStart();

      toggle.addEventListener('click', async () => {
        if (heroPlayPromise) {
          heroPlayRequestId += 1;
          audio.pause();
          setState(false);
          return;
        }

        if (audio.paused) {
          const nearStart = audio.currentTime <= 0.05;
          const nearEnd = Number.isFinite(audio.duration) && audio.currentTime >= (audio.duration - 0.05);

          if (nearStart || nearEnd) {
            resetToLocaleStart();
          }

          setState(true);
          syncActiveCaptionWithTime();
          try {
            const requestId = ++heroPlayRequestId;
            const playResult = audio.play();
            if (playResult && typeof playResult.then === 'function') {
              heroPlayPromise = playResult;
              await playResult;
              if (requestId === heroPlayRequestId) {
                setState(!audio.paused);
              }
            } else {
              setState(!audio.paused);
            }
          } catch (err) {
            if (!(err && err.name === 'AbortError')) {
              console.warn('Hero audio playback failed', err);
            }
            setState(false);
          } finally {
            heroPlayPromise = null;
          }
          return;
        }

        heroPlayRequestId += 1;
        setState(false);
        audio.pause();
      });

      const startCaptionSyncLoop = () => {
        if (syncFrame) return;
        const tick = () => {
          if (!isPlayingState) {
            syncFrame = null;
            return;
          }
          syncActiveCaptionWithTime();
          syncFrame = window.requestAnimationFrame(tick);
        };
        syncFrame = window.requestAnimationFrame(tick);
      };

      const stopCaptionSyncLoop = () => {
        if (!syncFrame) return;
        window.cancelAnimationFrame(syncFrame);
        syncFrame = null;
      };

      audio.addEventListener('play', () => {
        setState(true);
        startCaptionSyncLoop();
      });
      audio.addEventListener('pause', () => {
        setState(false);
        stopCaptionSyncLoop();
      });
      audio.addEventListener('ended', () => {
        setState(false);
        stopCaptionSyncLoop();
        if (captionSwapTimer) {
          window.clearTimeout(captionSwapTimer);
          captionSwapTimer = null;
        }
        activeCaption.classList.remove('is-switching');
        resetToLocaleStart();
      });

      audio.addEventListener('timeupdate', () => {
        if (audio.paused && audio.currentTime === 0) return;
        syncActiveCaptionWithTime();
      });

      document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
          stopTyping();
          return;
        }
        if (!isPlayingState) startTyping();
      });

      // ─── Voice Sample Card Players ───
      (() => {
        let sampleAudio = null;
        let activeBtn = null;
        let activeCard = null;

        const setCardPlaying = (card, playing) => {
          if (!card) return;
          card.classList.toggle('is-playing-card', playing);
        };

        const setAriaLabel = (btn, playing) => {
          btn.setAttribute(
            'aria-label',
            playing
              ? (btn.dataset.pauseAriaLabel || 'Pause Voice Sample')
              : (btn.dataset.playAriaLabel || 'Play Voice Sample')
          );
        };

        document.querySelectorAll('.sample-play-btn').forEach(btn => {
          const cardId = btn.dataset.cardId;
          const card = cardId ? document.getElementById(cardId) : btn.closest('.sample-card');

          btn.addEventListener('click', () => {
            const src = btn.dataset.audioSrc;
            if (!src) return;

            // If clicking the already-playing button → pause
            if (activeBtn === btn && sampleAudio && !sampleAudio.paused) {
              sampleAudio.pause();
              btn.classList.remove('is-playing');
              setCardPlaying(card, false);
              setAriaLabel(btn, false);
              activeBtn = null;
              activeCard = null;
              return;
            }

            // Stop any previously playing sample
            if (sampleAudio) {
              sampleAudio.pause();
              sampleAudio.removeAttribute('src');
              sampleAudio.load();
            }
            if (activeBtn) {
              activeBtn.classList.remove('is-playing');
              setAriaLabel(activeBtn, false);
            }
            if (activeCard) {
              setCardPlaying(activeCard, false);
            }

            // Also pause the hero audio if playing
            if (audio && !audio.paused) {
              audio.pause();
            }

            sampleAudio = new Audio(src);
            activeBtn = btn;
            activeCard = card;
            btn.classList.add('is-playing');
            setCardPlaying(card, true);
            setAriaLabel(btn, true);

            sampleAudio.play().catch(() => {
              btn.classList.remove('is-playing');
              setCardPlaying(card, false);
              setAriaLabel(btn, false);
              activeBtn = null;
              activeCard = null;
            });

            sampleAudio.addEventListener('ended', () => {
              btn.classList.remove('is-playing');
              setCardPlaying(card, false);
              setAriaLabel(btn, false);
              activeBtn = null;
              activeCard = null;
            });
          });
        });
      })();
    });
  </script>
</body>
</html>
