<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen - Enterprise Voice Synthesis</title>
  <meta name="description" content="Launch your voice SaaS with premium Arabic and English playback, polished UX, and production-ready architecture." />
  <link rel="icon" type="image/svg+xml" href="{{ asset('icons/headphones.svg') }}" />
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
      padding: 4.5rem 0 3rem;
    }
    .hero-grid {
      display: grid;
      grid-template-columns: 1.1fr .9fr;
      gap: 2rem;
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
        padding: 2.5rem 0 2rem;
      }
      .section {
        padding: 2.5rem 0;
      }
      .hero-art {
        order: -1;
      }
      .hero-art img {
        max-width: 320px;
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
          <span class="eyebrow">Enterprise Voice Platform</span>
          <h1>Build voice experiences users love.</h1>
          <p class="lead">Listen helps you launch and scale a modern voice product with polished UX, premium voice presets, bilingual support, and production-ready architecture.</p>
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
          <img src="{{ asset('icons/listen-galaxy2.png') }}" alt="Listen voice platform illustration" />
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
</body>
</html>
