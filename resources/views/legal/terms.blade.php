<!doctype html>
<html lang="en" data-theme="{{ \App\Support\SeoHelper::defaultTheme() }}">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  @include('components.seo', ['pageTitle' => 'Terms of Service', 'page' => 'terms'])
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
      <h1>Terms of Service</h1>
      <p class="legal-updated">Effective date: February 22, 2026</p>
      <p class="legal-intro">These Terms govern your access to and use of Listen, including our website, APIs, dashboard, demo tools, and subscription features. By using the service, you agree to these Terms.</p>

      <section class="legal-section">
        <h2>1. Eligibility and Accounts</h2>
        <p>You are responsible for maintaining accurate account information, safeguarding your credentials, and all activity under your account. You must use the service in compliance with applicable laws and these Terms.</p>
      </section>

      <section class="legal-section">
        <h2>2. Acceptable Use</h2>
        <ul>
          <li>Do not use the service for unlawful, abusive, infringing, deceptive, or harmful purposes.</li>
          <li>Do not attempt to bypass quotas, pricing controls, security controls, or access restrictions.</li>
          <li>Do not interfere with platform stability, reverse engineer protected systems, or abuse automated access.</li>
        </ul>
      </section>

      <section class="legal-section">
        <h2>3. Plans, Billing, and Renewals</h2>
        <p>Paid plans renew automatically until canceled. By subscribing, you authorize recurring charges under your selected billing cycle. Pricing, included usage, and overage terms are shown in the product and may be updated with notice as required by law.</p>
      </section>

      <section class="legal-section">
        <h2>4. Service Content and License</h2>
        <p>You retain rights to content you submit. You grant us a limited license to process, store, and transmit that content only as needed to operate and secure the service. Listen and its software, branding, and platform materials remain the property of SoliCode Ltd and its licensors.</p>
      </section>

      <section class="legal-section">
        <h2>5. Availability and Changes</h2>
        <p>We may modify features, limits, or components to improve quality, reliability, or security. While we aim for high uptime, uninterrupted or error-free operation is not guaranteed.</p>
      </section>

      <section class="legal-section">
        <h2>6. Disclaimer and Liability Limits</h2>
        <p>The service is provided on an "as available" basis. To the maximum extent allowed by law, SoliCode Ltd disclaims implied warranties and will not be liable for indirect, incidental, special, consequential, or punitive damages arising from use of the service.</p>
      </section>

      <section class="legal-section">
        <h2>7. Suspension and Termination</h2>
        <p>We may suspend or terminate access for violations of these Terms, security risks, fraud indicators, or legal requirements. You may stop using the service and cancel subscriptions in accordance with your billing cycle.</p>
      </section>

      <section class="legal-section">
        <h2>8. Contact</h2>
        <p>Questions about these Terms can be sent to <a href="mailto:support@listenai.studio">support@listenai.studio</a>.</p>
      </section>
    </article>
  </main>

  @include('components.footer')
  @include('components.scripts')
</body>
</html>
