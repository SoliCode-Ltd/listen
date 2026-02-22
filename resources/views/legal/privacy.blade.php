<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  @include('components.seo', ['pageTitle' => 'Privacy Policy', 'page' => 'privacy'])
  <meta name="description" content="Privacy Policy for Listen by SoliCode Ltd." />
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
    .legal-section a {
      color: var(--text);
      text-underline-offset: 3px;
    }
    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-12px); }
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
      <h1>Privacy Policy</h1>
      <p class="legal-updated">Effective date: February 22, 2026</p>
      <p class="legal-intro">This Privacy Policy explains how Listen, operated by SoliCode Ltd, collects, uses, and protects personal data when you use our website, demo tools, APIs, dashboard, and billing services.</p>

      <section class="legal-section">
        <h2>1. Information We Collect</h2>
        <ul>
          <li>Account details, such as name, email address, and login profile data (including Google sign-in data if you choose social login).</li>
          <li>Service usage data, including text submitted for voice generation, selected language/voice settings, and generation metadata.</li>
          <li>Billing records needed to manage subscriptions and invoices. Card processing is handled by Stripe; Listen does not store full card numbers.</li>
          <li>Technical and security data such as IP address, device/browser information, session identifiers, and anti-abuse signals.</li>
        </ul>
      </section>

      <section class="legal-section">
        <h2>2. How We Use Information</h2>
        <ul>
          <li>To provide core product functionality, including voice generation, account access, usage tracking, and support.</li>
          <li>To process subscriptions, payment updates, and invoice delivery.</li>
          <li>To detect abuse, enforce quotas and plan policies, and protect service reliability and security.</li>
          <li>To improve performance, quality, and product experience.</li>
        </ul>
      </section>

      <section class="legal-section">
        <h2>3. Processors and Sharing</h2>
        <p>We share data only as needed to operate the service, including payment processing (Stripe), authentication providers, infrastructure providers, and speech-processing providers. We may also disclose information when required by law, legal process, or to protect rights and security.</p>
      </section>

      <section class="legal-section">
        <h2>4. Data Retention</h2>
        <p>We retain account, billing, and operational records for as long as needed to provide the service, maintain security, comply with legal obligations, and resolve disputes. We may delete or anonymize data that is no longer required for these purposes.</p>
      </section>

      <section class="legal-section">
        <h2>5. Security</h2>
        <p>We apply technical and organizational safeguards designed to protect data against unauthorized access, loss, and misuse. No method of storage or transmission is fully risk-free, so we cannot guarantee absolute security.</p>
      </section>

      <section class="legal-section">
        <h2>6. Your Choices</h2>
        <p>You may request access, correction, or deletion of personal data tied to your account, subject to applicable law and legitimate business needs. You can also close your account at any time from the product experience or by contacting us.</p>
      </section>

      <section class="legal-section">
        <h2>7. Contact</h2>
        <p>For privacy questions or data requests, contact SoliCode Ltd at <a href="mailto:support@solicode.me">support@solicode.me</a>.</p>
      </section>
    </article>
  </main>

  @include('components.footer')
  @include('components.scripts')
</body>
</html>
