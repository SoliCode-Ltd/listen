<!doctype html>
<html lang="en" data-theme="{{ \App\Support\SeoHelper::defaultTheme() }}">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  @include('components.seo', ['pageTitle' => 'Enterprise Voice Synthesis', 'page' => 'home'])
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  @include('components.styles')
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
      transition: transform .3s cubic-bezier(0.175, 0.885, 0.32, 1.275), box-shadow .3s ease, background .3s ease;
      box-shadow: 0 10px 24px color-mix(in oklab, var(--a) 40%, transparent), inset 0 2px 4px rgba(255,255,255,0.3);
      padding: 0;
      position: relative;
      z-index: 2;
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
      inset: 0;
      border-radius: 50%;
      border: 1px solid white;
      opacity: 0;
      transform: scale(1);
      transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .hero-audio-btn:hover {
      transform: scale(1.08) translateY(-2px);
      box-shadow: 0 14px 32px color-mix(in oklab, var(--a) 50%, transparent), inset 0 2px 4px rgba(255,255,255,0.4);
    }

    .hero-audio-btn:hover::before {
      opacity: 0.6;
      transform: scale(1.1);
    }

    .hero-audio-btn:hover::after {
      opacity: 0.4;
      transform: scale(1.15);
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
    }

    .hero-audio-btn.is-playing::before {
      opacity: 0.6;
      transform: scale(1.15);
      animation: glowPulse 2s infinite alternate;
    }

    .hero-audio-btn.is-playing::after {
      opacity: 0;
      transform: scale(1.3);
      transition: all 0.4s ease;
    }

    .hero-audio-btn.is-playing svg {
      margin-left: 0;
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
      font-size: 0.75rem;
      font-weight: 500;
      letter-spacing: 0.25em;
      text-transform: uppercase;
      color: color-mix(in oklab, var(--text) 90%, transparent);
      text-shadow: 0 0 16px color-mix(in oklab, var(--text) 50%, transparent), 0 0 8px color-mix(in oklab, var(--a) 30%, transparent);
      white-space: nowrap;
      transition: opacity 0.3s ease, transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
      will-change: opacity, transform;
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
      background: var(--a);
      margin-left: 6px;
      vertical-align: text-bottom;
      animation: blinkCaret 0.9s step-end infinite;
      box-shadow: 0 0 8px var(--a);
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

    @media (max-width: 640px) {
      .tts-illustration {
        aspect-ratio: 1 / 1.05;
        max-width: 440px;
      }
      .hero-audio-captions-wrapper {
        margin-top: 1rem;
      }
      .tts-card {
        width: 95%;
      }
      .hero-audio-caption {
        font-size: 0.65rem;
        letter-spacing: 0.2em;
        margin-top: 0.85rem;
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
          <h1>Turn any text into <span class="brand-text">human voice</span> instantly.</h1>
          <p class="lead">Listen integrates with your browser. Turn Arabic and English content into natural, studio-quality voice in one click.</p>
          <div class="hero-cta">
            @guest
              <a class="btn btn-primary" href="{{ route('demo.page') }}">Try Demo</a>
              <a class="btn" href="{{ route('register') }}">Create Free Account</a>
            @else
              <a class="btn btn-primary" href="{{ route('dashboard') }}">Go to Dashboard</a>
              <a class="btn" href="{{ route('browsers-extension.page') }}">Browsers Extension</a>
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
                    <button class="hero-audio-btn" id="heroAudioToggle" type="button" aria-label="Play Voice Sample" aria-controls="heroSampleAudio">
                      <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path id="heroAudioIconPath" d="M8 5v14l11-7z"></path>
                      </svg>
                    </button>
                    <div class="hero-audio-captions-wrapper">
                      <p class="hero-audio-caption idle-caption" id="heroAudioIdleCaption"></p>
                      <p class="hero-audio-caption active-caption" id="heroAudioActiveCaption">Arabic (Nabil)</p>
                    </div>
                  </div>
                  <audio id="heroSampleAudio" class="initialized" preload="auto">
                    <source src="{{ asset('audio/hero-ar-en.mp3') }}" type="audio/mpeg">
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
      <h2>Enterprise-Grade Delivery</h2>
      <p class="section-lead">Purpose-built for teams that require reliability, auditability, and predictable voice operations at scale.</p>
      <div class="cards">
        <article class="card reveal">
          <h3>Operational Reliability</h3>
          <p>Deterministic processing flow, resilient job execution, and clear system feedback for production incidents.</p>
        </article>
        <article class="card reveal">
          <h3>Policy Enforcement</h3>
          <p>Usage, plan governance, and entitlement controls are enforced in backend logic, not only in UI.</p>
        </article>
        <article class="card reveal">
          <h3>Security By Default</h3>
          <p>API-key isolation, role-safe account actions, and clean data boundaries for multi-tenant growth.</p>
        </article>
      </div>
    </section>

    <section class="section reveal">
      <h2>Why This Feels Premium</h2>
      <p class="section-lead">A high-confidence platform experience requires more than visuals. It requires system rigor end-to-end.</p>
      <div class="premium-grid">
        <article class="premium-card reveal">
          <h3>Voice Fidelity Governance</h3>
          <p>Arabic and English voice mapping is validated against approved catalogs to prevent label-to-voice drift in production.</p>
          <span class="premium-metric">Catalog integrity</span>
        </article>
        <article class="premium-card reveal">
          <h3>Fast Path Processing</h3>
          <p>Long requests are routed for non-blocking processing and prioritized execution so user-facing flows remain responsive.</p>
          <span class="premium-metric">Queue-aware routing</span>
        </article>
        <article class="premium-card reveal">
          <h3>Commercial Plan Controls</h3>
          <p>Entitlement checks cover request limits, overage policy, commercial usage rights, and processing class per plan.</p>
          <span class="premium-metric">Policy enforcement</span>
        </article>
        <article class="premium-card reveal">
          <h3>Extension UX Discipline</h3>
          <p>Playback controls, selection handling, and status feedback are tuned for stable behavior in real browsing environments.</p>
          <span class="premium-metric">User trust</span>
        </article>
      </div>
    </section>

    <section class="cta-section reveal">
      <h2>Ready to scale your voice product?</h2>
      <p>Start free and upgrade as your usage grows. No credit card required.</p>
      @auth
        <a class="btn btn-primary" href="{{ route('dashboard') }}">Go to Dashboard</a>
      @else
        <a class="btn btn-primary" href="{{ route('register') }}">Get Started Free</a>
      @endauth
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
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
      const arabicDurationFallback = 7.056;
      let transitionTime = arabicDurationFallback;
      let isPlayingState = false;
      let typingTimer = null;
      let captionSwapTimer = null;
      let syncFrame = null;
      let activeCaptionTarget = activeCaption.textContent;
      let phraseIndex = 0;
      let charIndex = 0;
      let isDeleting = false;

      const arabicSegmentAudio = new Audio('{{ asset('audio/ar.mp3') }}');
      arabicSegmentAudio.preload = 'metadata';
      arabicSegmentAudio.addEventListener('loadedmetadata', () => {
        if (Number.isFinite(arabicSegmentAudio.duration) && arabicSegmentAudio.duration > 0) {
          transitionTime = arabicSegmentAudio.duration;
        }
      });

      const setState = (isPlaying) => {
        if (isPlayingState === isPlaying) return;
        isPlayingState = isPlaying;
        toggle.classList.toggle('is-playing', isPlaying);
        ttsBody.classList.toggle('is-playing', isPlaying);
        iconPath.setAttribute('d', isPlaying ? pausePath : playPath);
        toggle.setAttribute('aria-label', isPlaying ? 'Pause Voice Sample' : 'Play Voice Sample');

        if (isPlaying) {
          stopTyping();
        } else {
          startTyping();
        }
      };
      
      const phrases = [
        "Generate human voice.",
        "Scale with enterprise voice.",
        "Turn text into lifelike audio.",
        "Arabic and English in one click."
      ];

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

      const syncActiveCaptionWithTime = () => {
        if (audio.currentTime < transitionTime) {
          updateActiveCaption('Arabic (Nabil)');
        } else {
          updateActiveCaption('English (Hannah)');
        }
      };

      toggle.addEventListener('click', async () => {
        if (audio.paused) {
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
        audio.currentTime = 0;
        activeCaptionTarget = 'Arabic (Nabil)';
        activeCaption.textContent = activeCaptionTarget;
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
