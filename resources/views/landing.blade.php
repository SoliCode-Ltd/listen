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

    .hero {
      padding: 4rem 0 3rem;
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

    .hero-art {
      position: relative;
      display: grid;
      place-items: center;
    }
    .hero-art::before {
      content: "";
      position: absolute;
      width: min(420px, 82%);
      aspect-ratio: 1/1;
      border-radius: 999px;
      background: radial-gradient(circle, color-mix(in oklab, var(--a) 30%, transparent), transparent 68%);
      filter: blur(18px);
      opacity: .45;
      animation: pulseGlow 6s ease-in-out infinite;
      z-index: 0;
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
      padding: 4rem 0;
    }
    .section h2 {
      margin: 0 0 .5rem;
      font-size: clamp(1.6rem, 3.5vw, 2.4rem);
    }
    .section .section-lead {
      margin: 0 0 2rem;
      color: var(--muted);
      font-size: 1.05rem;
    }

    .cards {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1rem;
    }
    .card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
      transition: transform .25s ease, border-color .25s ease;
    }
    .card:hover {
      transform: translateY(-4px);
      border-color: color-mix(in oklab, var(--a) 50%, var(--line));
    }
    .card h3 {
      margin: 0 0 .65rem;
      font-size: 1.1rem;
    }
    .card p {
      margin: 0;
      color: var(--muted);
      font-size: .92rem;
      line-height: 1.55;
    }

    .premium-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 1rem;
    }
    .premium-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
      transition: transform .25s ease, border-color .25s ease;
    }
    .premium-card:hover {
      transform: translateY(-4px);
      border-color: color-mix(in oklab, var(--a) 45%, var(--line));
    }
    .premium-card h3 {
      margin: 0 0 .55rem;
      font-size: 1.05rem;
    }
    .premium-card p {
      margin: 0 0 .75rem;
      color: var(--muted);
      font-size: .92rem;
      line-height: 1.55;
    }
    .premium-metric {
      display: inline-block;
      padding: .35rem .7rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .8rem;
      color: var(--muted);
    }

    .cta-section {
      text-align: center;
      padding: 3rem;
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      margin: 2rem 0 3rem;
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
      .cards,
      .premium-grid {
        grid-template-columns: 1fr;
      }
      .hero {
        padding: 1.6rem 0 2rem;
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
        padding: 2.5rem 0;
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
        padding: 1.1rem 0 1.6rem;
      }
      .hero-cta {
        flex-direction: column;
        align-items: stretch;
      }
      .hero-cta .btn {
        width: 100%;
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
      background: linear-gradient(145deg, color-mix(in oklab, var(--a) 15%, transparent), color-mix(in oklab, var(--surface) 50%, transparent));
      border: 1px solid color-mix(in oklab, var(--text) 5%, transparent);
      box-shadow: 0 32px 64px rgba(0,0,0,0.5), inset 0 1px 1px color-mix(in oklab, var(--text) 15%, transparent);
      backdrop-filter: blur(24px);
      -webkit-backdrop-filter: blur(24px);
      transform: rotateY(-15deg) rotateX(10deg);
      transform-style: preserve-3d;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .tts-header {
      padding: 1rem 1.5rem;
      border-bottom: 1px solid color-mix(in oklab, var(--text) 10%, transparent);
      display: flex;
      gap: 8px;
    }

    .tts-dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: color-mix(in oklab, var(--text) 20%, transparent);
    }
    .tts-dot:nth-child(1) { background: color-mix(in oklab, var(--a) 80%, transparent); }
    .tts-dot:nth-child(2) { background: color-mix(in oklab, var(--b) 80%, transparent); }

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
      border: none;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--a), var(--b));
      color: white;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: transform .45s cubic-bezier(0.22, 1, 0.36, 1), box-shadow .45s cubic-bezier(0.22, 1, 0.36, 1), filter .35s ease;
      box-shadow: 0 10px 24px color-mix(in oklab, var(--a) 40%, transparent), inset 0 2px 4px rgba(255,255,255,0.3);
      padding: 0;
      position: relative;
      z-index: 2;
    }

    .hero-audio-btn:not(.is-playing) {
      animation: heroHeartbeat 1.8s cubic-bezier(0.22, 1, 0.36, 1) infinite;
    }

    .hero-audio-btn::before {
      content: "";
      position: absolute;
      inset: -6px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--a), var(--b));
      opacity: 0.3;
      z-index: -1;
      transition: opacity .3s ease, transform .4s ease;
      filter: blur(8px);
      animation: idleGlow 3s ease-in-out infinite alternate;
    }

    .hero-audio-btn::after {
      content: "";
      position: absolute;
      inset: 1px;
      border-radius: 50%;
      border: 1px solid color-mix(in oklab, white 75%, var(--a) 25%);
      opacity: 0.3;
      transition: opacity .35s ease, transform .45s cubic-bezier(0.22, 1, 0.36, 1);
      pointer-events: none;
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
      margin-left: 4px; /* offset play icon slightly */
      transition: margin .3s ease, transform .2s ease;
      flex-shrink: 0;
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
      background: linear-gradient(180deg, var(--a), var(--b));
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
      height: 12px;
      border-radius: var(--radius-full);
      background: linear-gradient(180deg, var(--a), var(--b));
      opacity: 0.5;
      transition: height 0.3s ease, opacity 0.3s ease;
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
      0%, 100% { height: 12px; }
      50% { height: 44px; }
    }

    .tts-glowing-orb {
      position: absolute;
      top: 50%;
      left: 50%;
      width: 150px;
      height: 150px;
      background: var(--a);
      border-radius: 50%;
      filter: blur(60px);
      transform: translate(-50%, -50%) translateZ(-50px);
      opacity: 0.4;
      animation: pulse-glow 4s ease-in-out infinite alternate;
    }

    @keyframes pulse-glow {
      0% { opacity: 0.3; transform: translate(-50%, -50%) translateZ(-50px) scale(0.8); }
      100% { opacity: 0.6; transform: translate(-50%, -50%) translateZ(-50px) scale(1.2); }
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
        aspect-ratio: 1 / 1.05;
        width: 100%;
        max-width: 100%;
      }
      .hero-audio-captions-wrapper {
        margin-top: 1rem;
      }
      .tts-card {
        width: 100%;
        margin-inline: auto;
        transform: rotateX(8deg);
        transform-origin: center center;
      }
      .hero-audio-caption {
        font-size: 0.75rem;
        letter-spacing: 0.1em;
        margin-top: 0.85rem;
      }
      html[dir="rtl"] .hero-audio-caption {
        letter-spacing: 0;
        font-size: 0.85rem;
      }
      .tts-body {
        padding: 1.25rem 1rem 1.75rem;
      }
      .tts-audio-wave {
        gap: 4px;
      }
      .tts-body.is-playing .tts-audio-wave {
        height: 48px;
        margin-top: 2rem;
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
              <a class="btn" href="{{ route('register') }}">{{ __('ui.landing.create_free_account') }}</a>
            @else
              <a class="btn btn-primary" href="{{ route('dashboard') }}">{{ __('ui.landing.go_to_dashboard') }}</a>
              <a class="btn" href="{{ route('browsers-extension.page') }}">{{ __('ui.landing.browser_extension') }}</a>
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
                    <source src="{{ str_starts_with(strtolower((string) app()->getLocale()), 'ar') ? asset('audio/ar.mp3') : asset('audio/en.mp3') }}" type="audio/mpeg">
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

    <section class="section reveal">
      <h2>{{ __('ui.landing.section1_title') }}</h2>
      <p class="section-lead">{{ __('ui.landing.section1_subtitle') }}</p>
      <div class="cards">
        <article class="card reveal">
          <h3>{{ __('ui.landing.section1_card1_title') }}</h3>
          <p>{{ __('ui.landing.section1_card1_body') }}</p>
        </article>
        <article class="card reveal">
          <h3>{{ __('ui.landing.section1_card2_title') }}</h3>
          <p>{{ __('ui.landing.section1_card2_body') }}</p>
        </article>
        <article class="card reveal">
          <h3>{{ __('ui.landing.section1_card3_title') }}</h3>
          <p>{{ __('ui.landing.section1_card3_body') }}</p>
        </article>
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

    <section class="cta-section reveal">
      <h2>{{ __('ui.landing.cta_title') }}</h2>
      <p>{{ __('ui.landing.cta_subtitle') }}</p>
      @auth
        <a class="btn btn-primary" href="{{ route('dashboard') }}">{{ __('ui.landing.go_to_dashboard') }}</a>
      @else
        <a class="btn btn-primary" href="{{ route('register') }}">{{ __('ui.landing.get_started_free') }}</a>
      @endauth
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
        if (audio.paused) {
          const nearStart = audio.currentTime <= 0.05;
          const nearEnd = Number.isFinite(audio.duration) && audio.currentTime >= (audio.duration - 0.05);

          if (nearStart || nearEnd) {
            resetToLocaleStart();
          }

          setState(true);
          syncActiveCaptionWithTime();
          try {
            await audio.play();
            setState(true);
          } catch (_err) {
            setState(false);
          }
          return;
        }

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
    });
  </script>
</body>
</html>
