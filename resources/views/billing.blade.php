@include('components.page-head', ['pageTitle' => __('ui.seo.billing_title'), 'page' => 'billing'])
  <script src="https://js.stripe.com/v3/"></script>
  <style>
    .main-content {
      padding: 1.5rem 0 3rem;
    }
    .page-header {
      margin-bottom: 1.5rem;
    }
    .page-header h1 {
      margin: 0 0 .35rem;
      font-size: 1.75rem;
    }
    .page-header p {
      margin: 0;
      color: var(--muted);
    }
    .grid-layout {
      display: grid;
      grid-template-columns: 1.15fr .85fr;
      gap: 1.25rem;
    }
    .card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
      animation: fadeUp .22s ease both;
    }
    .grid-layout .card:nth-child(2) { animation-delay: .07s; }
    .status-item {
      animation: fadeUp .2s ease both;
    }
    .status-grid .status-item:nth-child(2) { animation-delay: .03s; }
    .status-grid .status-item:nth-child(3) { animation-delay: .06s; }
    .card-header {
      margin-bottom: 1rem;
      padding-bottom: .85rem;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
    }
    .card-header h2 {
      margin: 0 0 .25rem;
      font-size: 1.1rem;
    }
    .card-header p {
      margin: 0;
      color: var(--muted);
      font-size: .88rem;
    }
    .status-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: .75rem;
    }
    .status-item {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: .85rem;
    }
    .status-item small {
      display: block;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .04em;
      font-size: .7rem;
      margin-bottom: .3rem;
    }
    .status-item b {
      display: block;
      font-size: .95rem;
    }
    .form-grid {
      display: grid;
      gap: .85rem;
    }
    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .75rem;
    }
    .form-group label {
      display: block;
      margin-bottom: .4rem;
      font-size: .85rem;
      font-weight: 600;
      color: color-mix(in oklab, var(--text) 85%, var(--muted));
    }
    select {
      width: 100%;
      padding: .75rem;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--text);
      font-size: .9rem;
      cursor: pointer;
      transition: border-color .2s ease;
    }
    select:focus {
      outline: none;
      border-color: var(--a);
    }
    .stripe-container {
      margin-top: .5rem;
      padding: 1rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--surface) 85%, transparent);
    }
    .stripe-label {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      font-size: .78rem;
      font-weight: 600;
      color: var(--muted);
      margin-bottom: .65rem;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .stripe-label svg {
      width: 1rem;
      height: 1rem;
      fill: currentColor;
    }
    .stripe-label .card-icon {
      width: 1.08rem;
      height: 1.08rem;
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
      padding: .75rem 1rem;
      margin-bottom: .75rem;
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--success) 12%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--success) 30%, transparent);
      font-size: .88rem;
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
      font-size: .8rem;
      color: var(--muted);
      margin-top: .2rem;
    }
    #card-element {
      padding: .85rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: var(--surface);
    }
    .card-error {

      margin-top: .5rem;
      font-size: .85rem;
      color: var(--danger);
      min-height: 1.2em;
    }
    .card-hint {
        text-align: center !important;

      margin-top: .6rem;
      font-size: .82rem;
      color: var(--muted);
    }
    .save-card-checkbox {
      display: flex;
      align-items: center;
      gap: .5rem;
      padding: .6rem .75rem;
      border-radius: var(--radius);
      cursor: pointer;
      font-size: .88rem;
      color: var(--fg);
      transition: border-color 0.15s ease;
    }
    .save-card-checkbox:hover {
      border-color: var(--a);
    }
    .save-card-checkbox input[type="checkbox"] {
      appearance: none;
      -webkit-appearance: none;
      width: 1.25rem;
      height: 1.25rem;
      border: 1px solid var(--line);
      border-radius: 4px;
      background: var(--surface);
      cursor: pointer;
      position: relative;
      transition: all 0.2s ease;
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
      height: 9px;
      border-right: 2px solid white;
      border-bottom: 2px solid white;
    }
    .consent-box {
      margin-top: .65rem;
      padding: .72rem .78rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--surface2) 70%, var(--surface));
      font-size: .82rem;
      color: var(--muted);
      line-height: 1.5;
    }
    .consent-row {
      margin-top: .45rem;
      color: var(--text);
      font-size: .84rem;
    }
    .actions {
      margin-top: 1rem;
      display: flex;
      gap: .6rem;
      flex-wrap: wrap;
    }
    .actions .btn {
      flex: 1;
      justify-content: center;
    }
    .btn.is-loading {
      opacity: .8;
      pointer-events: none;
    }
    .message {
      margin-top: .85rem;
      padding: .85rem 1rem;
      border-radius: var(--radius);
      display: none;
      font-size: .9rem;
    }
    .message.success {
      display: block;
      border: 1px solid color-mix(in oklab, var(--ok) 45%, var(--line));
      background: color-mix(in oklab, var(--ok) 12%, var(--surface));
      color: color-mix(in oklab, var(--ok) 85%, var(--text));
    }
    .message.error {
      display: block;
      border: 1px solid color-mix(in oklab, var(--danger) 45%, var(--line));
      background: color-mix(in oklab, var(--danger) 12%, var(--surface));
      color: color-mix(in oklab, var(--danger) 85%, var(--text));
    }
    .quote-section {
      display: flex;
      flex-direction: column;
      gap: .85rem;
    }
    .quote-highlight {
      text-align: center;
      padding: 1.25rem;
      border: 1px solid color-mix(in oklab, var(--a) 45%, var(--line));
      border-radius: var(--radius);
      background: linear-gradient(145deg, color-mix(in oklab, var(--a) 8%, var(--surface)), var(--surface));
    }
    .quote-highlight small {
      display: block;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .06em;
      font-size: .72rem;
      margin-bottom: .35rem;
    }
    .quote-highlight .amount {
      font-size: 2rem;
      font-weight: 800;
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
      margin: .4rem 0 0;
      color: var(--muted);
      font-size: .85rem;
    }
    .quote-details {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      overflow: hidden;
    }
    .quote-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .7rem .9rem;
      border-bottom: 1px dashed color-mix(in oklab, var(--line) 65%, transparent);
      font-size: .88rem;
    }
    .quote-row:last-child {
      border-bottom: none;
    }
    .quote-row dt {
      color: var(--muted);
    }
    .quote-row dd {
      font-weight: 600;
    }
    .quick-links {
      margin-top: .5rem;
      display: flex;
      flex-direction: column;
      gap: .5rem;
    }
    .quick-links .btn {
      width: 100%;
      justify-content: center;
    }
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 980px) {
      .grid-layout {
        grid-template-columns: 1fr;
      }
      .form-row {
        grid-template-columns: 1fr;
      }
      .status-grid {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 640px) {
      .main-content {
        padding: 1rem 0 2rem;
      }
      .page-header h1 {
        font-size: 1.45rem;
      }
      .card {
        padding: 1rem;
      }
      .stripe-container {
        padding: .85rem;
      }
      .actions .btn {
        flex: 1 1 100%;
      }
      .quote-highlight {
        padding: 1rem;
      }
      .quote-highlight .amount {
        font-size: 1.65rem;
      }
      .quote-row {
        align-items: flex-start;
        flex-direction: column;
        gap: .2rem;
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
  @endphp
  @include('components.header')

  <main class="container main-content">
    <div class="page-header">
      <h1>{{ __('ui.billing.title') }}</h1>
      <p>{{ __('ui.billing.subtitle') }}</p>
    </div>

    <div class="status-grid" style="margin-bottom: 1rem;">
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

        <form id="billingForm" class="form-grid">
          <div class="form-row">
            <div class="form-group">
              <label for="plan">{{ __('ui.billing.plan') }}</label>
              <select id="plan">
                @foreach($plansForUi as $plan)
                  <option value="{{ $plan['slug'] }}" {{ $selectedPlan === $plan['slug'] ? 'selected' : '' }}>
                    {{ $plan['localized_name'] }} - {{ $plan['localized_voice_tier'] }}
                  </option>
                @endforeach
              </select>
            </div>
            <div class="form-group">
              <label for="cycle">{{ __('ui.billing.billing_cycle') }}</label>
              <select id="cycle">
                <option value="monthly">{{ __('ui.billing.monthly') }}</option>
                <option value="yearly">{{ __('ui.billing.yearly_save_20') }}</option>
              </select>
            </div>
          </div>

          <div class="stripe-container">
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
            @if($stripeReady)
              <p class="card-hint">{{ __('ui.billing.card_processed_by_stripe') }}</p>
            @else
              <p class="card-hint" style="color: var(--danger);">{{ __('ui.billing.stripe_not_configured') }}</p>
            @endif
            <div class="consent-box">
              {{ __('ui.billing.auto_renew_notice') }}
            </div>
          </div>

          <div class="actions">
            <button class="btn btn-primary" id="subscribeBtn" type="button" {{ $stripeReady ? '' : 'disabled' }}>
              {{ __('ui.billing.confirm_and_pay') }}
            </button>
            <a class="btn" href="{{ route('manage-subscription.page') }}">{{ __('ui.billing.manage_subscription') }}</a>
          </div>
          <div id="message" class="message"></div>
        </form>
      </div>

      <div class="card quote-section">
        <div class="card-header">
          <h2>{{ __('ui.billing.charge_preview') }}</h2>
          <p>{{ __('ui.billing.charge_preview_hint') }}</p>
        </div>

        <div class="quote-highlight">
          <small>{{ __('ui.billing.amount_to_be_charged') }}</small>
          <p class="amount" id="quoteAmount" dir="ltr">-</p>
          <p class="hint" id="quoteHint">{{ __('ui.billing.select_plan_for_preview') }}</p>
        </div>

        <div class="quote-details">
          <div class="quote-row">
            <dt>{{ __('ui.billing.plan') }}</dt>
            <dd id="quotePlan">-</dd>
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
      'every' => __('ui.billing.every'),
      'month' => __('ui.billing.month'),
      'months' => __('ui.billing.months'),
      'year' => __('ui.billing.year'),
      'years' => __('ui.billing.years'),
      'select_plan_for_preview' => __('ui.billing.select_plan_for_preview'),
      'you_will_be_charged' => __('ui.billing.you_will_be_charged'),
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
    ];
  @endphp
  <script>
    const stripeKey = @json($stripeKey);
    const csrfToken = '{{ csrf_token() }}';
    const userName = @json((string) auth()->user()->name);
    const userEmail = @json((string) auth()->user()->email);
    const hasDefaultPaymentMethod = @json((bool) $defaultPaymentMethod);
    const planCatalog = @json(collect($plansForUi)->keyBy('slug')->all());

    const messageBox = document.getElementById('message');
    const subscribeBtn = document.getElementById('subscribeBtn');
    const planSelect = document.getElementById('plan');
    const cycleSelect = document.getElementById('cycle');
    const cardError = document.getElementById('card-error');
    const saveCardCheckbox = document.getElementById('saveCard');

    const quoteAmount = document.getElementById('quoteAmount');
    const quoteHint = document.getElementById('quoteHint');
    const quotePlan = document.getElementById('quotePlan');
    const quoteCycle = document.getElementById('quoteCycle');
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
      if (!raw) {
        return fallback || '';
      }

      if (hasArabicChars(raw) || !isArabicLocale) {
        return raw;
      }

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

      if (invalidCard) {
        return i18n.invalid_card_details;
      }

      if (
        lowered.includes('authentication') ||
        lowered.includes('3d secure') ||
        lowered.includes('requires_action')
      ) {
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

    function setSubscribeButtonBusy(isBusy, text) {
      if (!subscribeBtn) return;
      subscribeBtn.disabled = isBusy || !stripe;
      subscribeBtn.classList.toggle('is-loading', Boolean(isBusy));
      subscribeBtn.textContent = text || subscribeBtnDefaultText;
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

    function selectedMeta() {
      const selectedPlan = String(planSelect?.value || '');
      const selectedCycle = String(cycleSelect?.value || 'monthly');
      const plan = planCatalog[selectedPlan];
      if (!plan) return null;
      const meta = selectedCycle === 'yearly' ? plan.yearly : plan.monthly;
      if (!meta) return null;
      return {
        plan,
        cycle: selectedCycle,
        meta,
      };
    }

    function cycleLabel(meta) {
      const intervalCount = Number(meta?.interval_count || 1);
      const interval = String(meta?.interval || 'month');
      const unit = interval === 'year'
        ? (intervalCount > 1 ? i18n.years : i18n.year)
        : (intervalCount > 1 ? i18n.months : i18n.month);
      return intervalCount > 1 ? `${i18n.every} ${intervalCount} ${unit}` : `${i18n.every} ${unit}`;
    }

    function renderChargePreview() {
      const selected = selectedMeta();
      if (!selected) {
        quoteAmount.textContent = '-';
        quotePlan.textContent = '-';
        quoteCycle.textContent = '-';
        quoteHint.textContent = i18n.select_plan_for_preview;
        return;
      }

      const amountText = String(selected.meta.amount_formatted || '-');
      const amountBidi = `\u2066${amountText}\u2069`;
      quoteAmount.textContent = amountText;
      quotePlan.textContent = String(selected.plan.localized_name || selected.plan.name || selected.plan.slug || '-');
      quoteCycle.textContent = cycleLabel(selected.meta);
      quoteHint.textContent = `${i18n.you_will_be_charged} ${amountBidi} (${cycleLabel(selected.meta)}).`;
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

    subscribeBtn?.addEventListener('click', async () => {
      setSubscribeButtonBusy(true, i18n.processing_payment);
      setMessage('', '');

      try {
        const selected = selectedMeta();
        if (!selected) {
          throw new Error(i18n.choose_plan_and_cycle);
        }

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

          setMessage('ok', i18n.payment_confirmed_redirecting);
          window.location.href = '{{ route('manage-subscription.page') }}';
          return;
        }

        if (!response.ok || response.data?.ok === false) {
          throw new Error(response.data?.message || i18n.upgrade_failed);
        }

        setMessage('ok', response.data?.message || i18n.subscription_updated_redirecting);
        window.location.href = '{{ route('manage-subscription.page') }}';
      } catch (error) {
        setMessage('error', normalizeStripeMessage(error?.message, i18n.upgrade_failed));
      } finally {
        setSubscribeButtonBusy(false, subscribeBtnDefaultText);
      }
    });

    planSelect?.addEventListener('change', renderChargePreview);
    cycleSelect?.addEventListener('change', renderChargePreview);

    setSubscribeButtonBusy(false, subscribeBtnDefaultText);
    renderChargePreview();
  </script>
</body>
</html>

