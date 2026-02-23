<!doctype html>
<html lang="en" data-theme="{{ \App\Support\SeoHelper::defaultTheme() }}">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  @include('components.seo', ['pageTitle' => 'Cookie Policy', 'page' => 'cookies'])
  <meta name="description" content="Cookie Policy for Listen by SoliCode Ltd." />
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
      opacity: .2;
      animation: float 10s ease-in-out infinite;
    }
    .bg-orb.a {
      width: 14rem;
      height: 14rem;
      background: color-mix(in oklab, var(--a) 72%, white 28%);
      left: -5rem;
      top: 12vh;
    }
    .bg-orb.b {
      width: 18rem;
      height: 18rem;
      background: color-mix(in oklab, var(--b) 72%, white 28%);
      right: -5rem;
      bottom: 6vh;
      animation-delay: -3s;
    }
    .legal-page {
      padding: 2.5rem 0 3rem;
    }
    .legal-shell {
      width: min(860px, 100%);
      margin: 0 auto;
      padding: 1.5rem;
      border-radius: var(--radius-lg);
      border: 1px solid var(--line);
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      box-shadow: var(--shadow);
    }
    .legal-kicker {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      margin-bottom: 1rem;
      padding: .38rem .74rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .78rem;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: var(--muted);
    }
    .legal-shell h1 {
      margin: 0 0 .5rem;
      font-size: clamp(1.8rem, 4vw, 2.6rem);
      letter-spacing: -.02em;
    }
    .legal-updated {
      margin: 0 0 1.2rem;
      color: var(--muted);
      font-size: .92rem;
    }
    .legal-intro {
      margin: 0 0 1.5rem;
      color: var(--muted);
      line-height: 1.65;
    }
    .legal-section + .legal-section {
      margin-top: 1.3rem;
    }
    .legal-section h2 {
      margin: 0 0 .5rem;
      font-size: 1.15rem;
    }
    .legal-section p {
      margin: 0;
      color: var(--muted);
      line-height: 1.65;
    }
    .legal-section ul {
      margin: .55rem 0 0 0;
      padding-left: 1.15rem;
      color: var(--muted);
      list-style: disc;
    }
    .legal-section li {
      margin: .35rem 0;
      line-height: 1.6;
    }
    .cookie-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: .85rem;
      margin-top: .75rem;
    }
    .cookie-card {
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--surface2) 65%, var(--surface));
      padding: .85rem;
    }
    .cookie-card h3 {
      margin: 0 0 .35rem;
      font-size: .95rem;
    }
    .cookie-card p {
      margin: 0;
      font-size: .88rem;
    }
    .legal-section a {
      color: var(--text);
      text-underline-offset: 3px;
    }
    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-12px); }
    }
    @media (max-width: 700px) {
      .cookie-grid {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 640px) {
      .legal-page {
        padding: 1.5rem 0 2rem;
      }
      .legal-shell {
        padding: 1.1rem;
      }
    }
  </style>
</head>
<body>
  <div class="bg-orb a"></div>
  <div class="bg-orb b"></div>

  @include('components.header')

  <main class="container legal-page">
    <article class="legal-shell">
      <span class="legal-kicker">Legal</span>
      <h1>Cookie Policy</h1>
      <p class="legal-updated">Effective date: February 22, 2026</p>
      <p class="legal-intro">This Cookie Policy describes how Listen uses cookies and similar technologies to provide secure sign-in, maintain session state, remember preferences, and protect service integrity.</p>

      <section class="legal-section">
        <h2>1. What Cookies Are</h2>
        <p>Cookies are small text files stored on your device by your browser. They help websites remember your session and preferences and can also support security and reliability functions.</p>
      </section>

      <section class="legal-section">
        <h2>2. Cookie Categories We Use</h2>
        <div class="cookie-grid">
          <article class="cookie-card">
            <h3>Essential</h3>
            <p>Required for login state, secure session handling, CSRF protection, and core navigation.</p>
          </article>
          <article class="cookie-card">
            <h3>Preference</h3>
            <p>Used to remember product settings such as your selected theme (dark/light).</p>
          </article>
          <article class="cookie-card">
            <h3>Security and Abuse Prevention</h3>
            <p>Supports rate limiting and anti-abuse controls for public demo and API protection.</p>
          </article>
          <article class="cookie-card">
            <h3>Billing and Account Continuity</h3>
            <p>Helps maintain secure account and subscription flows during checkout and account updates.</p>
          </article>
        </div>
      </section>

      <section class="legal-section">
        <h2>3. Similar Technologies</h2>
        <p>In addition to cookies, Listen may use local storage for non-sensitive UI preferences (for example, theme selection) and short-lived identifiers required for security and product operation.</p>
      </section>

      <section class="legal-section">
        <h2>4. Managing Cookies</h2>
        <ul>
          <li>You can control or delete cookies through your browser settings.</li>
          <li>Blocking essential cookies may prevent sign-in, billing actions, or core features from working correctly.</li>
          <li>Clearing preference cookies or local storage resets UI settings such as theme choice.</li>
        </ul>
      </section>

      <section class="legal-section">
        <h2>5. Updates to This Policy</h2>
        <p>We may update this Cookie Policy as product behavior, infrastructure, or legal requirements evolve. The effective date at the top indicates the latest revision.</p>
      </section>

      <section class="legal-section">
        <h2>6. Contact</h2>
        <p>For cookie-related questions, contact <a href="mailto:support@solicode.me">support@solicode.me</a>.</p>
      </section>
    </article>
  </main>

  @include('components.footer')
  @include('components.scripts')
</body>
</html>
