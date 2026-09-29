@include('components.page-head', ['pageTitle' => __('ui.seo.billing_title'), 'page' => 'billing'])
  <script src="https://js.stripe.com/v3/"></script>
  <style>
    .main-content {
      padding: 2rem 0 3.5rem;
    }
    .page-header {
      margin-bottom: 1.75rem;
    }
    .page-header h1 {
      margin: 0 0 .4rem;
      font-size: 1.75rem;
      font-weight: 700;
    }
    .page-header p {
      margin: 0;
      color: var(--muted);
      font-size: 1rem;
    }

    /* ── Status bar ── */
    .status-grid {
      display: grid;
      grid-template-columns: 1fr 1fr 340px;
      gap: .65rem 1.25rem;
    }
    .status-item {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: .75rem .9rem;
      animation: fadeUp .2s ease both;
    }
    .status-grid .status-item:nth-child(2) { animation-delay: .04s; }
    .status-grid .status-item:nth-child(3) { animation-delay: .08s; }
    .status-item small {
      display: block;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .05em;
      font-size: .75rem;
      margin-bottom: .25rem;
    }
    .status-item b {
      display: block;
      font-size: 1rem;
    }

    /* ── Grid layout ── */
    .grid-layout {
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 1.25rem;
      align-items: start;
    }

    /* ── Cards ── */
    .card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      animation: fadeUp .22s ease both;
    }
    .grid-layout .card:nth-child(2) { animation-delay: .07s; }
    .card-header {
      margin-bottom: 1.15rem;
      padding-bottom: .75rem;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 50%, transparent);
    }
    .card-header h2 {
      margin: 0 0 .2rem;
      font-size: 1.15rem;
      font-weight: 700;
    }
    .card-header p {
      margin: 0;
      color: var(--muted);
      font-size: .95rem;
    }

    /* ── Form ── */
    .form-grid {
      display: grid;
      gap: .9rem;
    }
    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .75rem;
    }
    .form-group label {
      display: block;
      margin-bottom: .4rem;
      font-size: .95rem;
      font-weight: 600;
      color: color-mix(in oklab, var(--text) 85%, var(--muted));
    }
    select {
      width: 100%;
      padding: .75rem 2.25rem .75rem .75rem;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--text);
      font-size: .95rem;
      cursor: pointer;
      transition: border-color .15s ease, box-shadow .15s ease;
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23A1A1AA' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 14 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right .75rem center;
      background-size: 1rem;
    }
    html[dir="rtl"] select {
      padding: .75rem .75rem .75rem 2.25rem;
      background-position: left .75rem center;
    }
    select:focus {
      outline: none;
      border-color: var(--a);
      box-shadow: 0 0 0 3px color-mix(in oklab, var(--a) 12%, transparent);
    }

    /* ── Payment method cards ── */
    .payment-methods {
      display: grid;
      gap: .6rem;
      margin-top: .15rem;
    }
    .payment-method-grid {
      display: flex;
      gap: .5rem;
    }
    .payment-method-card {
      position: relative;
      flex: 1;
      border: 2px solid var(--line);
      border-radius: var(--radius-lg);
      background: var(--surface);
      padding: .85rem .9rem;
      cursor: pointer;
      transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      gap: .55rem;
    }
    .payment-method-card:hover {
      border-color: color-mix(in oklab, var(--a) 45%, var(--line));
      box-shadow: 0 4px 16px color-mix(in oklab, var(--a) 8%, transparent);
    }
    .payment-method-card input {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }
    .payment-method-card.active {
      border-color: var(--a);
      background: color-mix(in oklab, var(--a) 4%, var(--surface));
      box-shadow: 0 0 0 3px color-mix(in oklab, var(--a) 12%, transparent);
    }
    .payment-method-card.unavailable {
      opacity: .35;
      cursor: not-allowed;
      box-shadow: none;
    }

    .payment-method-card .method-title {
      display: flex;
      align-items: center;
      gap: .5rem;
      font-weight: 700;
    }
    .method-brand {
      display: flex;
      align-items: center;
      gap: .6rem;
      width: 100%;
    }
    .method-logo {
      width: 2.5rem;
      height: 2.5rem;
      border-radius: .5rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      padding: .3rem;
    }
    .method-logo img {
      max-width: 100%;
      max-height: 100%;
      display: block;
      object-fit: contain;
      object-position: center;
    }
    .method-logo-fallback {
      font-size: .65rem;
      font-weight: 800;
      letter-spacing: .04em;
      text-transform: uppercase;
      color: #6366f1;
    }
    .method-copy {
      display: flex;
      flex-direction: column;
      min-width: 0;
    }
    .method-name {
      font-size: 1rem;
      font-weight: 700;
      line-height: 1.2;
    }

    /* ── Checkout panels ── */
    .checkout-panel {
      margin-top: .5rem;
      overflow: hidden;
    }
    .manual-panel {
      display: none;
      padding: 1rem;
      gap: 1rem;
    }
    .manual-panel.show {
      display: grid;
    }
    .manual-main-header h3,
    .manual-proof-card h3,
    .manual-review-card h3 {
      margin: 0 0 .4rem;
      font-size: 1.05rem;
      font-weight: 700;
    }
    .manual-main-header p {
      margin-bottom: 2rem !important;
      color: var(--muted);
      line-height: 1.6;
      font-size: .95rem;
    }
    .manual-main-grid {
      display: none;
    }
    .manual-proof-card,
    .manual-review-card {
      padding: 1rem;
    }
    .manual-proof-card h3,
    .manual-review-card h3 {
      margin: 0 0 .6rem;
      font-size: 1rem;
    }
    .manual-proof-card .proof-upload-area {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: .6rem;
      width: 100%;
      padding: 1.5rem 1rem;
      border-radius: var(--radius);
      border: 2px dashed color-mix(in oklab, var(--a) 30%, var(--line));
      background: color-mix(in oklab, var(--surface2) 50%, transparent);
      color: var(--text);
      cursor: pointer;
      transition: border-color .15s ease, background .15s ease;
      text-align: center;
    }
    .manual-proof-card .proof-upload-area:hover {
      border-color: var(--a);
      background: color-mix(in oklab, var(--a) 4%, var(--surface));
    }
    .manual-proof-card .proof-upload-area.has-file {
      border-color: var(--a);
      border-style: solid;
    }
    .proof-upload-icon {
      width: 2.5rem;
      height: 2.5rem;
      color: var(--muted);
    }
    .proof-upload-area.has-file .proof-upload-icon {
      color: var(--a);
    }
    .proof-upload-text {
      font-size: .95rem;
      color: var(--muted);
    }
    .proof-upload-text strong {
      color: var(--a);
      font-weight: 600;
    }
    .proof-upload-hint {
      font-size: .95rem;
      color: var(--muted);
      opacity: .7;
    }
    .proof-file-name {
      font-size: .92rem;
      color: var(--text);
      font-weight: 600;
    }
    .manual-proof-card input[type="file"] {
      display: none;
    }
    .manual-proof-card p,
    .manual-review-card p {
      margin: 0;
      color: var(--muted);
      line-height: 1.6;
      font-size: .95rem;
    }
    .manual-meta-footer {
      margin-top: .75rem;
    }
    .manual-pay-actions {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: .75rem;
    }
    .manual-qr-wrap {
      border-radius: var(--radius);
      padding: 0;
      display: none;
      flex-direction: column;
      align-items: center;
      gap: .75rem;
      background: transparent;
      width: 100%;
      max-width: 320px;
      box-sizing: border-box;
    }
    .manual-qr-wrap.show {
      display: flex;
    }
    .manual-qr-wrap img {
      width: 100%;
      aspect-ratio: 1;
      object-fit: contain;
      border-radius: .6rem;
    }
    .manual-pay-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      max-width: 320px;
      box-sizing: border-box;
      padding: .7rem 1rem;
      border-radius: var(--radius);
      border: none;
      background: linear-gradient(135deg, var(--a), var(--b));
      color: white;
      text-decoration: none;
      font-weight: 600;
      font-size: .92rem;
      transition: opacity .15s ease, transform .1s ease;
    }
    .manual-pay-link:hover {
      opacity: .9;
    }

    /* ── Stripe container ── */
    .stripe-container {
      padding: 1rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--surface2) 40%, var(--surface));
    }
    .stripe-container.hidden {
      display: none;
    }
    .stripe-label {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      font-size: .95rem;
      font-weight: 700;
      color: var(--muted);
      margin-bottom: .6rem;
    }
    .stripe-label svg {
      width: 1rem;
      height: 1rem;
      fill: currentColor;
    }
    .stripe-label .card-icon {
      width: 1.05rem;
      height: 1.05rem;
      display: inline-block;
      flex-shrink: 0;
    }
    .stripe-label .card-icon svg {
      width: 100%;
      height: 100%;
      display: block;
      fill: none;
      stroke: none;
    }
    .saved-card-notice {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: .4rem;
      padding: .7rem .9rem;
      margin-bottom: .65rem;
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--success) 8%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--success) 25%, transparent);
      font-size: .95rem;
      color: var(--fg);
    }
    .saved-card-notice .saved-card-icon {
      color: var(--success);
      font-weight: 700;
    }
    .saved-card-notice strong {
      color: var(--fg);
      font-weight: 600;
    }
    .saved-card-notice .saved-card-hint {
      flex-basis: 100%;
      font-size: .82rem;
      color: var(--muted);
      margin-top: .15rem;
    }
    #card-element {
      padding: .8rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: var(--surface);
      transition: border-color .15s ease, box-shadow .15s ease;
    }
    #card-element:focus-within {
      border-color: var(--a);
      box-shadow: 0 0 0 3px color-mix(in oklab, var(--a) 12%, transparent);
    }
    .card-error {
      margin-top: .4rem;
      font-size: .95rem;
      color: var(--danger);
      min-height: 1.1em;
    }
    .save-card-checkbox {
      display: flex;
      align-items: center;
      gap: .5rem;
      padding: .55rem .7rem;
      border-radius: var(--radius);
      cursor: pointer;
      font-size: .95rem;
      color: var(--fg);
      transition: border-color .15s ease;
    }
    .save-card-checkbox:hover {
      border-color: var(--a);
    }
    .save-card-checkbox input[type="checkbox"] {
      appearance: none;
      -webkit-appearance: none;
      width: 1.15rem;
      height: 1.15rem;
      border: 1.5px solid var(--line);
      border-radius: 4px;
      background: var(--surface);
      cursor: pointer;
      position: relative;
      transition: all .15s ease;
      flex-shrink: 0;
      margin: 0;
    }
    .save-card-checkbox input[type="checkbox"]:hover {
      border-color: var(--a);
    }
    .save-card-checkbox input[type="checkbox"]:checked {
      background: linear-gradient(135deg, var(--a), var(--b));
      border-color: transparent;
    }
    .save-card-checkbox input[type="checkbox"]:checked::after {
      content: "";
      position: absolute;
      top: 45%;
      left: 50%;
      transform: translate(-50%, -50%) rotate(45deg);
      width: 4px;
      height: 8px;
      border-right: 2px solid white;
      border-bottom: 2px solid white;
    }
    .consent-box {
      margin-top: .6rem;
      padding: .7rem .85rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--surface2) 60%, var(--surface));
      font-size: .95rem;
      color: var(--muted);
      line-height: 1.6;
    }
    .consent-row {
      margin-top: .4rem;
      color: var(--text);
      font-size: .95rem;
    }

    /* ── Action buttons ── */
    .actions {
      margin-top: 1rem;
      display: flex;
      gap: .5rem;
      flex-wrap: wrap;
    }
    .actions .btn {
      flex: 1;
      justify-content: center;
    }
    .btn.is-loading {
      opacity: .75;
      pointer-events: none;
    }
    .btn[hidden],
    .message[hidden],
    #trialBtn[hidden],
    #trialNote[hidden] {
      display: none !important;
    }

    /* ── Messages ── */
    .message {
      margin-top: .75rem;
      padding: .75rem .9rem;
      border-radius: var(--radius);
      display: none;
      font-size: .92rem;
    }
    .message.success {
      display: block;
      border: 1px solid color-mix(in oklab, var(--ok) 40%, var(--line));
      background: color-mix(in oklab, var(--ok) 8%, var(--surface));
      color: color-mix(in oklab, var(--ok) 85%, var(--text));
    }
    .message.error {
      display: block;
      border: 1px solid color-mix(in oklab, var(--danger) 40%, var(--line));
      background: color-mix(in oklab, var(--danger) 8%, var(--surface));
      color: color-mix(in oklab, var(--danger) 85%, var(--text));
    }

    /* ── Quote sidebar ── */
    .quote-section {
      display: flex;
      flex-direction: column;
      gap: .75rem;
      position: sticky;
      top: 1.5rem;
    }
    .quote-highlight {
      text-align: center;
      padding: 1.25rem 1rem;
      border: 1px solid color-mix(in oklab, var(--a) 30%, var(--line));
      border-radius: var(--radius);
      background: linear-gradient(145deg, color-mix(in oklab, var(--a) 6%, var(--surface)), var(--surface));
    }
    .quote-highlight small {
      display: block;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .06em;
      font-size: .78rem;
      margin-bottom: .3rem;
    }
    .quote-highlight .amount {
      font-size: 2rem;
      font-weight: 800;
      font-family: 'IBM Plex Sans', sans-serif;
      margin: 0;
      background: linear-gradient(135deg, var(--a), var(--b));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      direction: ltr;
      unicode-bidi: isolate;
      display: inline-block;
    }
    .quote-highlight .hint {
      margin: .35rem 0 0;
      color: var(--muted);
      font-size: .95rem;
    }
    .quote-details {

      overflow: hidden;
    }
    .quote-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .65rem .85rem;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 40%, transparent);
      font-size: .95rem;
    }
    .quote-row:last-child {
      border-bottom: none;
    }
    .quote-row dt {
      color: var(--muted);
      font-size: .9rem;
    }
    .quote-row dd {
      font-weight: 600;
      font-size: .92rem;
      font-family: 'IBM Plex Sans', sans-serif;
    }
    .quote-row.is-hidden {
      display: none;
    }
    .quick-links {
      margin-top: .35rem;
      display: flex;
      flex-direction: column;
      gap: .4rem;
    }
    .quick-links .btn {
      width: 100%;
      justify-content: center;
    }

    /* ── Animations ── */
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* ── Responsive ── */
    @media (max-width: 980px) {
      .grid-layout {
        grid-template-columns: 1fr;
      }
      .quote-section {
        position: static;
        order: -1;
      }
      .form-row {
        grid-template-columns: 1fr;
      }
      .status-grid {
        grid-template-columns: 1fr 1fr 1fr;
        gap: .65rem;
      }
      .payment-method-grid {
        flex-direction: column;
      }
      .manual-main-grid {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 640px) {
      .main-content {
        padding: 1.25rem 0 2rem;
        overflow-x: hidden;
      }
      .page-header h1 {
        font-size: 1.35rem;
      }
      .card {
        padding: 1rem;
      }
      .grid-layout,
      .checkout-panel,
      .manual-panel,
      .manual-proof-card,
      .manual-pay-actions {
        min-width: 0;
      }
      .manual-qr-wrap,
      .manual-pay-link {
        width: 100%;
        max-width: 100%;
      }
      .status-grid {
        grid-template-columns: 1fr;
      }
      .stripe-container {
        padding: .75rem;
      }
      .actions .btn {
        flex: 1 1 100%;
      }
      .quote-highlight {
        padding: .85rem;
      }
      .quote-highlight .amount {
        font-size: 1.5rem;
      }
      .quote-row {
        align-items: flex-start;
        flex-direction: column;
        gap: .15rem;
      }
    }
  </style>
</head>
<body>
  @php
    $planNameBySlug = static function (?string $slug, ?string $fallback = null): string {
      // Dynamic plan name lookup — supports all plans
      $knownSlugs = [
        'free' => __('ui.pricing.plan_name_free'),
        'starter' => __('ui.pricing.plan_name_starter', ['default' => 'Starter']),
        'creator' => __('ui.pricing.plan_name_creator'),
        'pro' => __('ui.pricing.plan_name_pro'),
        'studio_max' => __('ui.pricing.plan_name_studio_max', ['default' => 'Studio Max']),
      ];
      return $knownSlugs[(string) $slug] ?? (string) ($fallback ?: ucfirst((string) $slug) ?: '-');
    };
    $normalizeTierLabel = static function (?string $label): string {
      $raw = trim((string) $label);
      $value = strtolower($raw);
      $display = $value === 'standard' ? __('ui.dashboard.standard') : $raw;
      if ($display !== '' && preg_match('/[A-Za-z0-9+]/', $display) && !preg_match('/[\x{0600}-\x{06FF}]/u', $display)) {
        return "\u{2066}{$display}\u{2069}";
      }
      return $display;
    };
    $activePlanName = $planNameBySlug($activePlan?->slug ?? 'free', $activePlan?->name ?? __('ui.dashboard.free_plan'));
    $plansForUi = collect($plans)->map(function (array $plan) use ($planNameBySlug, $normalizeTierLabel): array {
      $plan['localized_name'] = $planNameBySlug((string) ($plan['slug'] ?? ''), (string) ($plan['name'] ?? ''));
      $plan['localized_voice_tier'] = $normalizeTierLabel((string) ($plan['voice_tier'] ?? ''));
      return $plan;
    })->values()->all();
    $upgradePlansForUi = collect($plansForUi)
      ->reject(fn (array $plan): bool => (string) ($plan['slug'] ?? '') === (string) ($currentPlanSlug ?? ''))
      ->values()
      ->all();
    $upgradePlanSlugs = collect($upgradePlansForUi)->pluck('slug')->all();
    $showEgyptManualMethods = (bool) ($countryContext['is_egypt'] ?? false);
    $selectedPlanValue = old('plan', $selectedPlan);
    if (!in_array((string) $selectedPlanValue, $upgradePlanSlugs, true)) {
      $selectedPlanValue = (string) ($upgradePlanSlugs[0] ?? '');
    }
    $hasUpgradePlans = !empty($upgradePlansForUi);
    $selectedBillingCycle = old('billing_cycle', (string) request('billing_cycle', 'monthly'));
    $selectedPaymentMethod = old('payment_method', (string) request('payment_method', ''));
    $initialMessageText = session('manual_payment_success')
      ?: $errors->first('manual_payment')
      ?: $errors->first('payment_method');
    $initialMessageType = session('manual_payment_success')
      ? 'success'
      : ($initialMessageText !== '' ? 'error' : '');
    $paymentBrandAssets = is_array($paymentBrandAssets ?? null) ? $paymentBrandAssets : [];
  @endphp
  @include('components.header')

  <main class="container main-content">
    <div class="page-header">
      <h1>{{ __('ui.billing.title') }}</h1>
      <p>{{ __('ui.billing.subtitle') }}</p>
    </div>

    <div class="status-grid" style="margin-bottom: 1.25rem;">
      <div class="status-item">
        <small>{{ __('ui.billing.current_plan') }}</small>
        <b>{{ $activePlanName }}</b>
      </div>
      <div class="status-item">
        <small>{{ __('ui.billing.voice_quality') }}</small>
        <b>{{ $normalizeTierLabel($activeTierLabel) }}</b>
      </div>
      <div class="status-item">
        <small>{{ __('ui.billing.saved_card') }}</small>
        <b>
          @if($defaultPaymentMethod)
            {{ strtoupper((string) ($defaultPaymentMethod->card->brand ?? 'card')) }} **** {{ $defaultPaymentMethod->card->last4 ?? '' }}
          @else
            {{ __('ui.billing.not_saved') }}
          @endif
        </b>
      </div>
    </div>

    <div class="grid-layout">
      <div class="card">
        <div class="card-header">
          <h2>{{ __('ui.billing.upgrade_plan') }}</h2>
          <p>{{ __('ui.billing.upgrade_plan_hint') }}</p>
        </div>

        <form id="billingForm" class="form-grid" method="POST" action="{{ url()->current() }}">
          @csrf
          <input type="hidden" name="plan" id="manualPlanField" value="{{ $selectedPlanValue }}">
          <input type="hidden" name="billing_cycle" id="manualCycleField" value="{{ $selectedBillingCycle }}">
          <input type="hidden" name="payment_method" id="manualMethodField" value="{{ $selectedPaymentMethod }}">
          <div class="form-row">
            <div class="form-group">
              <label for="plan">{{ __('ui.billing.plan') }}</label>
              <select id="plan" {{ $hasUpgradePlans ? '' : 'disabled' }}>
                @if($hasUpgradePlans)
                  @foreach($upgradePlansForUi as $plan)
                    <option value="{{ $plan['slug'] }}" {{ $selectedPlanValue === $plan['slug'] ? 'selected' : '' }}>
                      {{ $plan['localized_name'] }} - {{ $plan['localized_voice_tier'] }}
                    </option>
                  @endforeach
                @else
                  <option value="">{{ __('ui.billing.no_upgrade_plans_available') }}</option>
                @endif
              </select>
            </div>
            <div class="form-group">
              <label for="cycle">{{ __('ui.billing.billing_cycle') }}</label>
              <select id="cycle" {{ $hasUpgradePlans ? '' : 'disabled' }}>
                <option value="monthly" {{ $selectedBillingCycle === 'monthly' ? 'selected' : '' }}>{{ __('ui.billing.monthly') }}</option>
                <option value="yearly" {{ $selectedBillingCycle === 'yearly' ? 'selected' : '' }}>{{ __('ui.billing.yearly') }}</option>
              </select>
            </div>
          </div>

          <div class="payment-methods">
            <div class="form-group">
              <label>{{ __('ui.billing.payment_method_label') }}</label>
              <div class="payment-method-grid" id="paymentMethodGrid">
                <label class="payment-method-card" data-method-card="stripe">
                  <input type="radio" name="payment_method_choice" value="stripe" @checked($selectedPaymentMethod === '' || $selectedPaymentMethod === 'stripe')>
                  <div class="method-title">
                    <div class="method-brand">
                      <span class="method-logo" aria-hidden="true">
                        @if(!empty($paymentBrandAssets['stripe']))
                          <img src="{{ $paymentBrandAssets['stripe'] }}" alt="{{ __('ui.billing.payment_method_stripe') }}">
                        @else
                          <span class="method-logo-fallback">Stripe</span>
                        @endif
                      </span>
                      <span class="method-copy">
                        <span class="method-name">{{ __('ui.billing.payment_method_stripe') }}</span>
                      </span>
                    </div>
                  </div>
                </label>

                @if($showEgyptManualMethods)
                  <label class="payment-method-card" data-method-card="instapay">
                    <input type="radio" name="payment_method_choice" value="instapay">
                    <div class="method-title">
                      <div class="method-brand">
                        <span class="method-logo" aria-hidden="true">
                          @if(!empty($paymentBrandAssets['instapay']))
                            <img src="{{ $paymentBrandAssets['instapay'] }}" alt="{{ __('ui.billing.payment_method_instapay') }}">
                          @else
                            <span class="method-logo-fallback">InstaPay</span>
                          @endif
                        </span>
                        <span class="method-copy">
                          <span class="method-name">{{ __('ui.billing.payment_method_instapay') }}</span>
                        </span>
                      </div>
                    </div>
                  </label>

                  <label class="payment-method-card" data-method-card="vodafone_cash">
                    <input type="radio" name="payment_method_choice" value="vodafone_cash">
                    <div class="method-title">
                      <div class="method-brand">
                        <span class="method-logo" aria-hidden="true">
                          @if(!empty($paymentBrandAssets['vodafone_cash']))
                            <img src="{{ $paymentBrandAssets['vodafone_cash'] }}" alt="{{ __('ui.billing.payment_method_vodafone_cash') }}">
                          @else
                            <span class="method-logo-fallback">Vodafone</span>
                          @endif
                        </span>
                        <span class="method-copy">
                          <span class="method-name">{{ __('ui.billing.payment_method_vodafone_cash') }}</span>
                        </span>
                      </div>
                    </div>
                  </label>
                @endif
              </div>
            </div>
          </div>

          <div class="checkout-panel">
            <div class="manual-panel" id="manualPanel">
              <div class="manual-proof-card manual-main-card">
                <div class="manual-main-header">
                  <h3>{{ __('ui.billing.manual_page_title') }}</h3>
                  <p id="manualIntroText">{{ __('ui.billing.manual_callout') }}</p>
                </div>
                <div class="manual-main-grid">
                  <div class="manual-main-amount">
                    <small>{{ __('ui.billing.manual_exact_amount') }}</small>
                    <span class="manual-amount" id="manualAmount">-</span>
                    <p id="manualCycleHint">{{ __('ui.billing.select_plan_for_preview') }}</p>
                  </div>
                </div>
                <div class="manual-meta-footer">
                  <div class="manual-pay-actions">
                    <div class="manual-qr-wrap" id="manualQrWrap">
                      <img id="manualQrImage" src="" alt="{{ __('ui.billing.manual_qr_alt') }}">
                    </div>
                    <a class="manual-pay-link" id="manualPaymentLink" href="#" target="_blank" rel="noopener noreferrer" style="display:none;">
                      {{ __('ui.billing.manual_open_payment_link') }}
                    </a>
                  </div>
                </div>
                <h3 style="margin-top: 1.25rem;">{{ __('ui.billing.manual_confirm_title') }}</h3>
                <p style="margin-bottom: .75rem;">{{ __('ui.billing.manual_confirm_subtitle') }}</p>
                <div class="proof-upload-area" id="proofUploadArea" role="button" tabindex="0">
                  <svg class="proof-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                  </svg>
                  <span class="proof-upload-text" id="proofUploadText">{{ __('ui.billing.upload_proof_prompt') }}</span>
                  <span class="proof-upload-hint" id="proofUploadHint">{{ __('ui.billing.upload_proof_hint') }}</span>
                </div>
                <input type="file" id="proofImage" name="proof_image" accept="image/*">
              </div>
            </div>

            <div class="stripe-container" id="stripeContainer">
              <div class="stripe-label">
                <span class="card-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                    <defs>
                      <linearGradient id="billingCardGradient" x1="2" y1="2" x2="22" y2="22" gradientUnits="userSpaceOnUse">
                        <stop offset="0%" stop-color="#3B82F6"></stop>
                        <stop offset="50%" stop-color="#8B5CF6"></stop>
                        <stop offset="100%" stop-color="#F97316"></stop>
                      </linearGradient>
                    </defs>
                    <rect x="2" y="4" width="20" height="16" rx="4" fill="url(#billingCardGradient)"></rect>
                    <rect x="2" y="8" width="20" height="2" fill="rgba(255,255,255,.75)"></rect>
                    <rect x="5" y="13" width="4.8" height="3.4" rx="1" fill="rgba(255,255,255,.92)"></rect>
                    <rect x="11.5" y="14.2" width="7.3" height="1.1" rx=".55" fill="rgba(255,255,255,.88)"></rect>
                  </svg>
                </span>
                {{ __('ui.billing.credit_or_debit_card') }}
              </div>
              @if($defaultPaymentMethod)
                <div class="saved-card-notice">
                  <span class="saved-card-icon" style="display: flex; align-items: center; justify-content: center;">
                    <svg viewBox="0 0 24 24" width="20" height="20">
                      <defs>
                        <linearGradient id="savedCardGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                          <stop offset="0%" stop-color="var(--ok)"/>
                          <stop offset="100%" stop-color="#10B981"/>
                        </linearGradient>
                      </defs>
                      <circle cx="12" cy="12" r="12" fill="url(#savedCardGrad)" opacity="0.2"/>
                      <circle cx="12" cy="12" r="9" fill="url(#savedCardGrad)"/>
                      <path d="M8.5 12L10.5 14.5L16 9" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                    </svg>
                  </span>
                  <span>{{ __('ui.billing.using_saved_card') }} <strong>{{ strtoupper($defaultPaymentMethod->card->brand) }} **** {{ $defaultPaymentMethod->card->last4 }}</strong></span>
                  <span class="saved-card-hint">{{ __('ui.billing.enter_new_card_optional') }}</span>
                </div>
              @endif
              <div id="card-element"></div>
              <div id="card-error" class="card-error"></div>
              <label class="save-card-checkbox">
                <input type="checkbox" id="saveCard" checked>
                <span>{{ __('ui.billing.save_card_for_future') }}</span>
              </label>
              @if(!empty($trialOffer['enabled']) && !empty($trialOffer['show_admin_test_cards']))
                <div class="consent-box" id="adminTrialCards">
                  <strong>{{ __('ui.billing.trial_admin_test_title') }}</strong>
                  <p>{{ __('ui.billing.trial_admin_test_body') }}</p>
                  <ul>
                    @foreach(($trialOffer['test_cards'] ?? []) as $testCard)
                      <li><code>{{ $testCard['number'] }}</code> — {{ $testCard['label'] }}</li>
                    @endforeach
                  </ul>
                  @if(empty($trialOffer['stripe_test_mode']))
                    <p>{{ __('ui.billing.trial_admin_live_mode_warning') }}</p>
                  @endif
                </div>
              @endif
              <div id="trialNote" class="message info" hidden></div>
              <div class="consent-box">
                @if($stripeReady)
                  <span>{{ __('ui.billing.card_processed_by_stripe') }}</span> · {{ __('ui.billing.auto_renew_notice') }}
                @else
                  <span style="color: var(--danger);">{{ __('ui.billing.stripe_not_configured') }}</span> · {{ __('ui.billing.auto_renew_notice') }}
                @endif
              </div>
            </div>
          </div>

          <div class="actions">
            <button class="btn btn-primary" id="subscribeBtn" type="submit" {{ $stripeReady ? '' : 'disabled' }}>
              {{ __('ui.billing.confirm_and_pay') }}
            </button>
            @if(!empty($trialOffer['enabled']) && !empty($trialOffer['available']))
              <button class="btn" id="trialBtn" type="button" hidden {{ $stripeReady ? '' : 'disabled' }}>
                {{ __('ui.billing.start_creator_trial', ['days' => (int) ($trialOffer['days'] ?? 7)]) }}
              </button>
            @endif
            <a class="btn" href="{{ route('manage-subscription.page') }}">{{ __('ui.billing.manage_subscription') }}</a>
          </div>
          <div id="message" class="message {{ $initialMessageType }}">{{ $initialMessageText }}</div>
        </form>
      </div>

      <div class="card quote-section">
        <div class="card-header">
          <h2>{{ __('ui.billing.charge_preview') }}</h2>
          <p>{{ __('ui.billing.charge_preview_hint') }}</p>
        </div>

        <div class="quote-highlight">
          <small id="quoteAmountLabel">{{ __('ui.billing.amount_to_be_charged') }}</small>
          <p class="amount" id="quoteAmount" dir="ltr">-</p>
          <p class="hint" id="quoteHint">{{ __('ui.billing.select_plan_for_preview') }}</p>
        </div>

        <div class="quote-details">
          <div class="quote-row">
            <dt>{{ __('ui.billing.plan') }}</dt>
            <dd id="quotePlan">-</dd>
          </div>
          <div class="quote-row">
            <dt>{{ __('ui.billing.payment_method_label') }}</dt>
            <dd id="quoteMethod">-</dd>
          </div>
          <div class="quote-row">
            <dt>{{ __('ui.billing.billing_cycle') }}</dt>
            <dd id="quoteCycle">-</dd>
          </div>
        </div>
      </div>
    </div>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @php
    $billingI18n = [
      'confirm_and_pay' => __('ui.billing.confirm_and_pay'),
      'continue_manual' => __('ui.billing.i_completed_manual_payment'),
      'every' => __('ui.billing.every'),
      'month' => __('ui.billing.month'),
      'months' => __('ui.billing.months'),
      'year' => __('ui.billing.year'),
      'years' => __('ui.billing.years'),
      'select_plan_for_preview' => __('ui.billing.select_plan_for_preview'),
      'you_will_be_charged' => __('ui.billing.you_will_be_charged'),
      'you_will_pay_manually' => __('ui.billing.you_will_pay_manually'),
      'stripe_not_configured' => __('ui.billing.stripe_not_configured'),
      'invalid_card_details' => __('ui.billing.invalid_card_details'),
      'processing_payment' => __('ui.billing.processing_payment'),
      'choose_plan_and_cycle' => __('ui.billing.choose_plan_and_cycle'),
      'enter_card_details' => __('ui.billing.enter_card_details'),
      'securing_card' => __('ui.billing.securing_card'),
      'finalizing_subscription' => __('ui.billing.finalizing_subscription'),
      'waiting_bank_confirmation' => __('ui.billing.waiting_bank_confirmation'),
      'payment_auth_failed' => __('ui.billing.payment_auth_failed'),
      'payment_confirmed_redirecting' => __('ui.billing.payment_confirmed_redirecting'),
      'upgrade_failed' => __('ui.billing.upgrade_failed'),
      'subscription_updated_redirecting' => __('ui.billing.subscription_updated_redirecting'),
      'payment_method_unavailable' => __('ui.billing.payment_method_unavailable'),
      'payment_method_label' => __('ui.billing.payment_method_label'),
      'manual_callout' => __('ui.billing.manual_callout'),
      'manual_locked_to_egypt' => __('ui.billing.manual_locked_to_egypt'),
      'manual_exact_amount' => __('ui.billing.manual_exact_amount'),
      'amount_to_be_charged' => __('ui.billing.amount_to_be_charged'),
      'manual_submit' => __('ui.billing.i_completed_manual_payment'),
      'manual_request_received' => __('ui.billing.manual_request_received'),
      'no_upgrade_plans_available' => __('ui.billing.no_upgrade_plans_available'),
      'proof_image_required' => __('ui.billing.proof_image_required'),
      'monthly_label' => __('ui.billing.monthly'),
      'yearly_label' => __('ui.billing.yearly'),
      'cycle_with_discount' => __('ui.billing.cycle_with_discount'),
      'start_creator_trial' => __('ui.billing.start_creator_trial'),
      'trial_starting' => __('ui.billing.trial_starting'),
      'trial_started_redirecting' => __('ui.billing.trial_started_redirecting'),
      'trial_note' => __('ui.billing.trial_note'),
    ];
  @endphp
  <script>
    const stripeKey = @json($stripeKey);
    const csrfToken = '{{ csrf_token() }}';
    const userName = @json((string) auth()->user()->name);
    const userEmail = @json((string) auth()->user()->email);
    const hasDefaultPaymentMethod = @json((bool) $defaultPaymentMethod);
    const yearlyDiscountPercent = Number(@json((int) ($yearlyDiscountPercent ?? 0)));
    const planCatalog = @json(collect($upgradePlansForUi)->keyBy('slug')->all());
    const initialPaymentMethod = @json($selectedPaymentMethod);
    const gadsConversionSendTo = @json(\App\Support\SeoHelper::googleAdsConversionSendTo());
    const gadsTrialConversionSendTo = @json(\App\Support\SeoHelper::googleAdsTrialConversionSendTo());
    const trialOffer = @json($trialOffer ?? ['available' => false, 'quota' => []]);
    const trialBtn = document.getElementById('trialBtn');
    const trialNote = document.getElementById('trialNote');

    const messageBox = document.getElementById('message');
    const subscribeBtn = document.getElementById('subscribeBtn');
    const planSelect = document.getElementById('plan');
    const cycleSelect = document.getElementById('cycle');
    const cycleMonthlyOption = cycleSelect?.querySelector('option[value="monthly"]') || null;
    const cycleYearlyOption = cycleSelect?.querySelector('option[value="yearly"]') || null;
    const cardError = document.getElementById('card-error');
    const saveCardCheckbox = document.getElementById('saveCard');
    const stripeContainer = document.getElementById('stripeContainer');
    const manualPanel = document.getElementById('manualPanel');
    const manualPlanField = document.getElementById('manualPlanField');
    const manualCycleField = document.getElementById('manualCycleField');
    const manualMethodField = document.getElementById('manualMethodField');
    const manualAmount = document.getElementById('manualAmount');
    const manualCycleHint = document.getElementById('manualCycleHint');
    const manualIntroText = document.getElementById('manualIntroText');
    const manualPaymentLink = document.getElementById('manualPaymentLink');
    const manualQrWrap = document.getElementById('manualQrWrap');
    const manualQrImage = document.getElementById('manualQrImage');
    const paymentMethodInputs = Array.from(document.querySelectorAll('input[name="payment_method_choice"]'));

    const quoteAmount = document.getElementById('quoteAmount');
    const quoteAmountLabel = document.getElementById('quoteAmountLabel');
    const quoteHint = document.getElementById('quoteHint');
    const quotePlan = document.getElementById('quotePlan');
    const quoteCycle = document.getElementById('quoteCycle');
    const quoteMethod = document.getElementById('quoteMethod');
    const i18n = @json($billingI18n);
    const localeCode = String(document.documentElement.lang || '').toLowerCase();
    const isArabicLocale = localeCode.startsWith('ar');
    const subscribeBtnDefaultText = subscribeBtn ? subscribeBtn.textContent.trim() : i18n.confirm_and_pay;
    const LIGHT_CARD_STYLE = {
      base: {
        color: '#13213d',
        iconColor: '#2e4f8f',
        fontSize: '15px',
        '::placeholder': { color: '#7a8da8' },
      },
      invalid: { color: '#c1244f', iconColor: '#c1244f' },
    };
    const DARK_CARD_STYLE = {
      base: {
        color: '#f5f7ff',
        iconColor: '#9fb0d4',
        fontSize: '15px',
        '::placeholder': { color: '#8f9bbb' },
      },
      invalid: { color: '#ff7a9a', iconColor: '#ff7a9a' },
    };

    function resolveCardStyle() {
      return document.documentElement.getAttribute('data-theme') === 'light'
        ? LIGHT_CARD_STYLE
        : DARK_CARD_STYLE;
    }

    function hasArabicChars(value) {
      return /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF]/.test(String(value || ''));
    }

    function normalizeStripeMessage(message, fallback) {
      const raw = String(message || '').trim();
      if (!raw) return fallback || '';
      if (hasArabicChars(raw) || !isArabicLocale) return raw;

      const lowered = raw.toLowerCase();
      const invalidCard =
        lowered.includes('card number') ||
        lowered.includes('cvc') ||
        lowered.includes('expiry') ||
        lowered.includes('expired card') ||
        lowered.includes('insufficient funds') ||
        lowered.includes('declined') ||
        lowered.includes('invalid card') ||
        lowered.includes('incomplete');

      if (invalidCard) return i18n.invalid_card_details;
      if (lowered.includes('authentication') || lowered.includes('3d secure') || lowered.includes('requires_action')) {
        return i18n.payment_auth_failed;
      }

      return fallback || i18n.invalid_card_details;
    }

    function setMessage(type, text) {
      messageBox.className = 'message';
      messageBox.textContent = '';
      if (!text) return;
      messageBox.classList.add(type === 'error' ? 'error' : 'success');
      messageBox.textContent = text;
    }

    function currentPlan() {
      return planCatalog[String(planSelect?.value || '')] || null;
    }

    function cycleOptionLabel(baseLabel, discountPercent) {
      const pct = Math.max(0, Math.min(99, Math.round(Number(discountPercent || 0))));
      if (pct <= 0) return String(baseLabel || '');
      return String(i18n.cycle_with_discount || ':cycle (:percent%)')
        .replace(':cycle', String(baseLabel || ''))
        .replace(':percent', String(pct));
    }

    function syncCycleOptionLabels() {
      const plan = currentPlan();
      const promoPercent = plan && Boolean(plan.has_discount)
        ? Math.max(0, Math.min(99, Math.round(Number(plan.discount_percentage || 0))))
        : 0;
      const yearlyPercent = Math.max(0, Math.min(99, promoPercent + Math.max(0, Math.min(99, yearlyDiscountPercent))));

      if (cycleMonthlyOption) {
        cycleMonthlyOption.textContent = cycleOptionLabel(i18n.monthly_label, promoPercent);
      }
      if (cycleYearlyOption) {
        cycleYearlyOption.textContent = cycleOptionLabel(i18n.yearly_label, yearlyPercent);
      }
    }

    function preferredMethodForPlan(plan) {
      if (!plan) return 'stripe';
      const preferred = String(plan.preferred_payment_method || 'stripe');
      const preferredMeta = plan.payment_methods?.[preferred];
      return preferredMeta && preferredMeta.available ? preferred : 'stripe';
    }

    function selectedMethod() {
      const checked = paymentMethodInputs.find(function(input) { return input.checked; });
      return checked ? String(checked.value || 'stripe') : preferredMethodForPlan(currentPlan());
    }

    function selectedMeta() {
      const plan = currentPlan();
      if (!plan) return null;

      const cycle = String(cycleSelect?.value || 'monthly');
      const method = selectedMethod();
      const methodMeta = plan.payment_methods?.[method] || null;
      const meta = methodMeta ? methodMeta[cycle] : null;

      if (!methodMeta || methodMeta.available !== true || !meta) {
        return null;
      }

      return { plan, cycle, method, methodMeta, meta };
    }

    function syncManualFields() {
      if (manualPlanField) manualPlanField.value = String(planSelect?.value || '');
      if (manualCycleField) manualCycleField.value = String(cycleSelect?.value || 'monthly');
      if (manualMethodField) manualMethodField.value = String(selectedMethod() || 'stripe');
    }

    function cycleLabel(meta) {
      const intervalCount = Number(meta?.interval_count || 1);
      const interval = String(meta?.interval || 'month');
      const unit = interval === 'year'
        ? (intervalCount > 1 ? i18n.years : i18n.year)
        : (intervalCount > 1 ? i18n.months : i18n.month);
      return intervalCount > 1 ? `${i18n.every} ${intervalCount} ${unit}` : `${i18n.every} ${unit}`;
    }

    function syncPaymentMethods() {
      const plan = currentPlan();
      const preferred = preferredMethodForPlan(plan);

      paymentMethodInputs.forEach(function(input) {
        const card = document.querySelector(`[data-method-card="${input.value}"]`);
        const methodConfig = plan?.payment_methods?.[input.value];
        const available = Boolean(methodConfig && methodConfig.available);

        input.disabled = !available;
        if (card) {
          card.classList.toggle('unavailable', !available);
          card.classList.toggle('active', input.checked && available);
        }
      });

      const checked = paymentMethodInputs.find(function(input) {
        return input.checked && !input.disabled;
      });

      if (!checked) {
        const fallbackInput = paymentMethodInputs.find(function(input) {
          return String(input.value || '') === preferred && !input.disabled;
        }) || paymentMethodInputs.find(function(input) {
          return !input.disabled;
        });

        if (fallbackInput) {
          fallbackInput.checked = true;
        }
      }

      paymentMethodInputs.forEach(function(input) {
        const card = document.querySelector(`[data-method-card="${input.value}"]`);
        if (card) {
          card.classList.toggle('active', input.checked && !input.disabled);
        }
      });

      syncManualFields();
    }

    function renderMode() {
      const method = selectedMethod();
      const usingStripe = method === 'stripe';

      stripeContainer?.classList.toggle('hidden', !usingStripe);
      manualPanel?.classList.toggle('show', !usingStripe);

      if (subscribeBtn) {
        subscribeBtn.textContent = usingStripe ? subscribeBtnDefaultText : i18n.manual_submit;
        const hasPlan = Boolean(currentPlan());
        subscribeBtn.disabled = !hasPlan || (usingStripe ? !stripe : false);
      }

      const plan = currentPlan();
      const cycle = String(cycleSelect?.value || 'monthly');
      const planCycles = (trialOffer.plan_cycles || {})[String(plan?.slug || '')] || [];
      const trialReady = Boolean(trialOffer.enabled && trialOffer.available)
        && usingStripe
        && planCycles.indexOf(cycle) !== -1;
      if (trialBtn) {
        trialBtn.hidden = !trialReady;
        trialBtn.style.display = trialReady ? '' : 'none';
      }
      if (trialNote) {
        if (!trialReady) {
          trialNote.hidden = true;
          trialNote.textContent = '';
        } else {
          const quota = (trialOffer.plan_quotas || {})[String(plan?.slug || '')] || trialOffer.quota || {};
          const amount = (cycle === 'yearly' ? plan?.yearly?.amount_formatted : plan?.monthly?.amount_formatted) || '';
          const end = new Date();
          end.setDate(end.getDate() + Number(trialOffer.days || 7));
          trialNote.hidden = false;
          trialNote.textContent = String(i18n.trial_note || '')
            .replace(':plan', String(plan?.localized_name || plan?.name || ''))
            .replace(':quota', String(quota.name || trialOffer.quota_plan_name || ''))
            .replace(':days', String(trialOffer.days || 7))
            .replace(':amount', amount)
            .replace(':date', end.toLocaleDateString())
            .replace(':chars', Number(quota.chars || 0).toLocaleString())
            .replace(':per_request', Number(quota.per_request || 0).toLocaleString())
            .replace(':minutes', Number(quota.minutes || 0).toLocaleString());
        }
      }
    }

    function renderManualPanel(selected) {
      if (!manualPanel) return;

      if (!selected || selected.method === 'stripe') {
        manualAmount.textContent = '-';
        manualCycleHint.textContent = i18n.select_plan_for_preview;
        manualIntroText.textContent = i18n.manual_callout;
        if (manualPaymentLink) {
          manualPaymentLink.style.display = 'none';
          manualPaymentLink.removeAttribute('href');
        }
        if (manualQrWrap) {
          manualQrWrap.classList.remove('show');
        }
        if (manualQrImage) {
          manualQrImage.removeAttribute('src');
        }
        return;
      }

      const destination = selected.methodMeta?.destination || {};
      manualAmount.textContent = String(selected.meta.amount_formatted || '-');
      manualCycleHint.textContent = `${i18n.you_will_pay_manually} ${String(selected.meta.amount_formatted || '-')}.`;
      manualIntroText.textContent = `${String(selected.methodMeta.label || '')} - ${String(selected.methodMeta.description || '')}`;
      const paymentLink = String(destination.payment_link || '').trim();
      const qrImageUrl = String(destination.qr_image_url || '').trim();
      if (manualPaymentLink) {
        if (paymentLink) {
          manualPaymentLink.style.display = 'inline-flex';
          manualPaymentLink.setAttribute('href', paymentLink);
        } else {
          manualPaymentLink.style.display = 'none';
          manualPaymentLink.removeAttribute('href');
        }
      }
      if (manualQrWrap) {
        manualQrWrap.classList.toggle('show', Boolean(qrImageUrl));
      }
      if (manualQrImage) {
        if (qrImageUrl) {
          manualQrImage.setAttribute('src', qrImageUrl);
        } else {
          manualQrImage.removeAttribute('src');
        }
      }
    }

    function renderChargePreview() {
      syncCycleOptionLabels();
      const selected = selectedMeta();
      if (!selected) {
        quoteAmount.textContent = '-';
        quoteAmountLabel.textContent = i18n.amount_to_be_charged;
        quotePlan.textContent = '-';
        quoteMethod.textContent = '-';
        quoteCycle.textContent = '-';
        quoteHint.textContent = currentPlan() ? i18n.payment_method_unavailable : i18n.no_upgrade_plans_available;
        renderManualPanel(null);
        return;
      }

      let amountText = String(selected.meta.amount_formatted || '-');
      const amountBidi = `\u2066${amountText}\u2069`;
      quoteAmount.textContent = amountText;
      quoteAmountLabel.textContent = selected.method === 'stripe'
        ? i18n.amount_to_be_charged
        : i18n.manual_exact_amount;
      quotePlan.textContent = String(selected.plan.localized_name || selected.plan.name || selected.plan.slug || '-');
      quoteMethod.textContent = String(selected.methodMeta.label || i18n.payment_method_label);
      quoteCycle.textContent = cycleLabel(selected.meta);
      quoteHint.textContent = selected.method === 'stripe'
        ? `${i18n.you_will_be_charged} ${amountBidi} (${cycleLabel(selected.meta)}).`
        : `${i18n.you_will_pay_manually} ${amountBidi}.`;
      renderManualPanel(selected);
      syncManualFields();
    }

    function setSubscribeButtonBusy(isBusy, text) {
      if (!subscribeBtn) return;
      const usingStripe = selectedMethod() === 'stripe';
      subscribeBtn.disabled = Boolean(isBusy) || (usingStripe && !stripe);
      subscribeBtn.classList.toggle('is-loading', Boolean(isBusy));
      subscribeBtn.textContent = text || (usingStripe ? subscribeBtnDefaultText : i18n.manual_submit);
    }

    async function post(url, payload = {}) {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
      });

      const data = await res.json().catch(() => ({}));
      return { ok: res.ok, status: res.status, data };
    }

    async function postForm(url, formData) {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: formData,
      });

      const data = await res.json().catch(() => ({}));
      return { ok: res.ok, status: res.status, data };
    }

    function responseMessage(response, fallback) {
      const data = response?.data || {};
      const direct = String(data.message || '').trim();
      if (direct !== '') return direct;

      const errors = data.errors && typeof data.errors === 'object' ? data.errors : null;
      if (errors) {
        for (const key of Object.keys(errors)) {
          const first = Array.isArray(errors[key]) ? String(errors[key][0] || '').trim() : '';
          if (first !== '') return first;
        }
      }

      return fallback;
    }

    const stripe = stripeKey ? Stripe(stripeKey) : null;
    const elements = stripe ? stripe.elements() : null;
    const card = elements
      ? elements.create('card', {
          hidePostalCode: true,
          style: resolveCardStyle(),
        })
      : null;

    let cardComplete = false;
    card?.mount('#card-element');
    card?.on('change', (event) => {
      cardComplete = Boolean(event.complete);
      cardError.textContent = event.error
        ? normalizeStripeMessage(event.error.message, i18n.invalid_card_details)
        : '';
    });

    if (card && 'MutationObserver' in window) {
      let currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
      const observer = new MutationObserver(() => {
        const nextTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        if (nextTheme === currentTheme) return;
        currentTheme = nextTheme;
        card.update({ style: resolveCardStyle() });
      });
      observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme'],
      });
    }

    async function collectCardPaymentMethod() {
      if (!stripe || !card) throw new Error(i18n.stripe_not_configured);

      const result = await stripe.createPaymentMethod({
        type: 'card',
        card,
        billing_details: {
          name: userName || undefined,
          email: userEmail || undefined,
        },
      });

      if (result.error) {
        throw new Error(normalizeStripeMessage(result.error.message, i18n.invalid_card_details));
      }

      return String(result.paymentMethod?.id || '');
    }

    paymentMethodInputs.forEach(function(input) {
      input.addEventListener('change', function() {
        syncPaymentMethods();
        renderMode();
        renderChargePreview();
      });
    });

    function sendAdsConversion(sendTo, meta, transactionId) {
      return new Promise(function(resolve) {
        if (typeof gtag !== 'function' || !sendTo) {
          resolve();
          return;
        }
        const payload = { send_to: sendTo, event_callback: resolve };
        const chargeCurrency = String(meta?.charge_currency || meta?.currency || '').toUpperCase();
        const minor = chargeCurrency === 'USD' && meta?.base_usd_cents ? meta.base_usd_cents : meta?.amount_cents;
        if (chargeCurrency && Number(minor) > 0) {
          payload.value = Math.round(Number(minor)) / 100;
          payload.currency = chargeCurrency;
        }
        if (transactionId) {
          payload.transaction_id = String(transactionId);
        }
        gtag('event', 'conversion', payload);
        // Navigating away before the hit is sent drops the conversion.
        setTimeout(resolve, 1500);
      });
    }

    function trackPurchaseConversion(meta) {
      return sendAdsConversion(gadsConversionSendTo, meta, '');
    }

    trialBtn?.addEventListener('click', async () => {
      setMessage('', '');
      try {
        if (!stripe || !card) throw new Error(i18n.stripe_not_configured);
        if (!cardComplete) throw new Error(i18n.enter_card_details);

        setSubscribeButtonBusy(true, i18n.trial_starting);
        if (trialBtn) trialBtn.disabled = true;

        const setupResponse = await post('{{ route('billing.trial.setup') }}', {
          plan: String(currentPlan()?.slug || ''),
          billing_cycle: String(cycleSelect?.value || 'monthly'),
        });
        if (!setupResponse.ok || setupResponse.data?.ok === false) {
          throw new Error(responseMessage(setupResponse, i18n.upgrade_failed));
        }

        const confirmed = await stripe.confirmCardSetup(String(setupResponse.data.client_secret || ''), {
          payment_method: {
            card,
            billing_details: {
              name: userName || undefined,
              email: userEmail || undefined,
            },
          },
        });
        if (confirmed.error) {
          throw new Error(normalizeStripeMessage(confirmed.error.message, i18n.payment_auth_failed));
        }

        const confirmResponse = await post('{{ route('billing.trial.confirm') }}', {
          setup_intent_id: String(confirmed.setupIntent?.id || ''),
        });
        if (!confirmResponse.ok || confirmResponse.data?.ok === false) {
          throw new Error(responseMessage(confirmResponse, i18n.upgrade_failed));
        }

        setMessage('ok', confirmResponse.data?.message || i18n.trial_started_redirecting);
        const trialPlan = currentPlan();
        await sendAdsConversion(
          gadsTrialConversionSendTo,
          trialPlan ? trialPlan[String(cycleSelect?.value || 'monthly')] : null,
          String(confirmed.setupIntent?.id || '')
        );
        window.location.href = '{{ route('manage-subscription.page') }}';
      } catch (error) {
        setMessage('error', normalizeStripeMessage(error?.message, i18n.upgrade_failed));
      } finally {
        setSubscribeButtonBusy(false);
        if (trialBtn) trialBtn.disabled = false;
      }
    });

    document.getElementById('billingForm')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      setMessage('', '');

      try {
        const selected = selectedMeta();
        if (!selected) {
          throw new Error(i18n.payment_method_unavailable);
        }

        if (selected.method !== 'stripe') {
          const proofInput = document.getElementById('proofImage');
          if (!proofInput || !proofInput.files || proofInput.files.length === 0) {
            throw new Error(i18n.proof_image_required);
          }
          setSubscribeButtonBusy(true, i18n.manual_submit);
          const formData = new FormData();
          formData.set('plan', String(selected.plan.slug || ''));
          formData.set('billing_cycle', String(selected.cycle || 'monthly'));
          formData.set('payment_method', String(selected.method || 'instapay'));
          formData.set('proof_image', proofInput.files[0]);

          const response = await postForm('{{ route('billing.manual.confirm') }}', formData);
          if (!response.ok || response.data?.ok === false) {
            throw new Error(responseMessage(response, i18n.upgrade_failed));
          }

          await trackPurchaseConversion(selected.meta);
          setMessage('ok', response.data?.message || i18n.manual_request_received);
          proofInput.value = '';
          proofUploadArea?.classList.remove('has-file');
          if (proofUploadText) {
            proofUploadText.textContent = proofUploadArea?.dataset.defaultText || proofUploadText.textContent;
          }
          if (proofUploadHint) {
            proofUploadHint.style.display = '';
          }
          return;
        }

        setSubscribeButtonBusy(true, i18n.processing_payment);

        let paymentMethod = '';
        if (cardComplete) {
          setSubscribeButtonBusy(true, i18n.securing_card);
          paymentMethod = await collectCardPaymentMethod();
        } else if (!hasDefaultPaymentMethod) {
          throw new Error(i18n.enter_card_details);
        }

        setSubscribeButtonBusy(true, i18n.finalizing_subscription);
        const response = await post('{{ route('billing.subscribe') }}', {
          plan: String(selected.plan.slug || ''),
          billing_cycle: String(selected.cycle || 'monthly'),
          payment_method: paymentMethod || null,
          save_card: saveCardCheckbox ? saveCardCheckbox.checked : true,
        });

        if (response.status === 402 && response.data?.requires_action && response.data?.client_secret) {
          setSubscribeButtonBusy(true, i18n.waiting_bank_confirmation);
          const confirmation = await stripe.confirmCardPayment(String(response.data.client_secret));
          if (confirmation.error) {
            throw new Error(normalizeStripeMessage(confirmation.error.message, i18n.payment_auth_failed));
          }

          await trackPurchaseConversion(selected.meta);
          setMessage('ok', i18n.payment_confirmed_redirecting);
          window.location.href = '{{ route('manage-subscription.page') }}';
          return;
        }

        if (!response.ok || response.data?.ok === false) {
          throw new Error(response.data?.message || i18n.upgrade_failed);
        }

        await trackPurchaseConversion(selected.meta);
        setMessage('ok', response.data?.message || i18n.subscription_updated_redirecting);
        window.location.href = '{{ route('manage-subscription.page') }}';
      } catch (error) {
        setMessage('error', normalizeStripeMessage(error?.message, i18n.upgrade_failed));
      } finally {
        setSubscribeButtonBusy(false);
      }
    });

    planSelect?.addEventListener('change', function() {
      syncPaymentMethods();
      renderMode();
      renderChargePreview();
    });
    cycleSelect?.addEventListener('change', function() {
      syncManualFields();
      renderMode();
      renderChargePreview();
    });

    if (initialPaymentMethod) {
      const initialInput = paymentMethodInputs.find(function(input) {
        return String(input.value || '') === initialPaymentMethod;
      });
      if (initialInput && !initialInput.disabled) {
        initialInput.checked = true;
      }
    }

    syncPaymentMethods();
    renderMode();
    renderChargePreview();

    // Custom file upload area
    const proofUploadArea = document.getElementById('proofUploadArea');
    const proofImage = document.getElementById('proofImage');
    const proofUploadText = document.getElementById('proofUploadText');
    const proofUploadHint = document.getElementById('proofUploadHint');
    if (proofUploadArea && proofImage) {
      if (!proofUploadArea.dataset.defaultText) {
        proofUploadArea.dataset.defaultText = proofUploadText ? proofUploadText.textContent : '';
      }
      proofUploadArea.addEventListener('click', function() {
        proofImage.click();
      });
      proofUploadArea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          proofImage.click();
        }
      });
      proofImage.addEventListener('change', function() {
        if (proofImage.files && proofImage.files.length > 0) {
          const fileName = proofImage.files[0].name;
          proofUploadArea.classList.add('has-file');
          proofUploadText.innerHTML = '';
          const fileNameSpan = document.createElement('span');
          fileNameSpan.className = 'proof-file-name';
          fileNameSpan.textContent = fileName;
          proofUploadText.appendChild(fileNameSpan);
          if (proofUploadHint) proofUploadHint.style.display = 'none';
        } else {
          proofUploadArea.classList.remove('has-file');
          proofUploadText.textContent = proofUploadArea.dataset.defaultText || proofUploadText.textContent;
          if (proofUploadHint) proofUploadHint.style.display = '';
        }
      });
    }
  </script>
</body>
</html>
