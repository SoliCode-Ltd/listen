@include('components.page-head', ['pageTitle' => __('ui.seo.manage_subscription_title'), 'page' => 'manage_subscription'])
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
    .status-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: .75rem;
      margin-bottom: 1rem;
    }
    .status-item {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1rem;
      box-shadow: var(--shadow-sm);
    }
    .status-item small {
      display: block;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .04em;
      font-size: .7rem;
      margin-bottom: .35rem;
    }
    .status-item b {
      display: block;
      font-size: 1rem;
    }
    .status-item .status-badge {
      display: inline-flex;
      align-items: center;
      gap: .3rem;
      padding: .25rem .6rem;
      border-radius: var(--radius-full);
      font-size: .8rem;
      font-weight: 600;
    }
    .status-item .status-badge.active {
      background: color-mix(in oklab, var(--ok) 15%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--ok) 40%, var(--line));
      color: color-mix(in oklab, var(--ok) 85%, var(--text));
    }
    .status-item .status-badge.canceled {
      background: color-mix(in oklab, var(--danger) 15%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--danger) 40%, var(--line));
      color: color-mix(in oklab, var(--danger) 85%, var(--text));
    }
    .status-item .status-badge.pending {
      background: color-mix(in oklab, var(--a) 15%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--a) 40%, var(--line));
      color: color-mix(in oklab, var(--a) 85%, var(--text));
    }
    .grid-layout {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.25rem;
      margin-bottom: 1rem;
    }
    .card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
    }
    .status-item,
    .card {
      animation: fadeUp .24s ease both;
    }
    .status-grid .status-item:nth-child(2) { animation-delay: .03s; }
    .status-grid .status-item:nth-child(3) { animation-delay: .06s; }
    .status-grid .status-item:nth-child(4) { animation-delay: .09s; }
    .grid-layout .card:nth-child(1) { animation-delay: .08s; }
    .grid-layout .card:nth-child(2) { animation-delay: .12s; }
    .invoices-card { animation-delay: .16s; }
    .card-header {
      margin-bottom: 1rem;
      padding-bottom: .85rem;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
    }
    .card-header h2 {
      margin: 0 0 .2rem;
      font-size: 1.1rem;
    }
    .card-header p {
      margin: 0;
      color: var(--muted);
      font-size: .85rem;
    }
    .detail-list {
      display: flex;
      flex-direction: column;
      gap: .5rem;
    }
    .detail-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .65rem .75rem;
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      font-size: .88rem;
    }
    .detail-row dt {
      color: var(--muted);
    }
    .detail-row dd {
      font-weight: 600;
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
      margin-top: .6rem;
      font-size: .82rem;
      color: var(--muted);
    }
    .actions {
      margin-top: 1rem;
      display: flex;
      gap: .6rem;
      flex-wrap: wrap;
    }
    .btn-danger {
      background: color-mix(in oklab, var(--danger) 15%, var(--surface));
      border-color: color-mix(in oklab, var(--danger) 40%, var(--line));
      color: color-mix(in oklab, var(--danger) 85%, var(--text));
    }
    .btn-danger:hover {
      background: color-mix(in oklab, var(--danger) 25%, var(--surface));
    }
    .btn.is-loading {
      opacity: .82;
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
    .message.info {
      display: block;
      border: 1px solid color-mix(in oklab, var(--a) 45%, var(--line));
      background: color-mix(in oklab, var(--a) 12%, var(--surface));
      color: color-mix(in oklab, var(--a) 85%, var(--text));
    }
    .invoices-card {
      margin-top: 1rem;
    }
    .invoice-table {
      width: 100%;
      border-collapse: collapse;
    }
    .invoice-table th,
    .invoice-table td {
      padding: .7rem .5rem;
      text-align: left;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
      font-size: .88rem;
    }
    .invoice-table th {
      color: var(--muted);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .04em;
      font-size: .75rem;
    }
    .invoice-table tr:last-child td {
      border-bottom: none;
    }
    .invoice-status {
      display: inline-flex;
      padding: .2rem .5rem;
      border-radius: var(--radius-full);
      font-size: .75rem;
      font-weight: 600;
    }
    .invoice-status.paid {
      background: color-mix(in oklab, var(--ok) 12%, var(--surface));
      color: color-mix(in oklab, var(--ok) 80%, var(--text));
    }
    .invoice-status.open {
      background: color-mix(in oklab, var(--a) 12%, var(--surface));
      color: color-mix(in oklab, var(--a) 80%, var(--text));
    }
    .invoice-status.void {
      background: color-mix(in oklab, var(--muted) 12%, var(--surface));
      color: var(--muted);
    }
    .invoice-actions {
      display: flex;
      gap: .4rem;
    }
    .invoice-actions .btn {
      padding: .35rem .55rem;
      font-size: .8rem;
    }
    .empty-state {
      text-align: center;
      padding: 2rem;
      color: var(--muted);
    }
    .empty-state p {
      margin: 0;
    }
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(8px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @media (max-width: 980px) {
      .grid-layout {
        grid-template-columns: 1fr;
      }
      .status-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
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
      .status-grid {
        grid-template-columns: 1fr;
      }
      .detail-row {
        align-items: flex-start;
        flex-direction: column;
        gap: .2rem;
      }
      .actions .btn {
        flex: 1 1 100%;
        justify-content: center;
      }
      .invoice-table th,
      .invoice-table td {
        white-space: nowrap;
      }
    }
  </style>
</head>
<body>
  @php
    $planNameBySlug = static function (?string $slug, ?string $fallback = null): string {
      return match ((string) $slug) {
        'free' => __('ui.pricing.plan_name_free'),
        'creator' => __('ui.pricing.plan_name_creator'),
        'pro' => __('ui.pricing.plan_name_pro'),
        default => (string) ($fallback ?: '-'),
      };
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
    $subscriptionStatusLower = strtolower((string) $subscriptionStatus);
    $subscriptionStatusLabel = match ($subscriptionStatusLower) {
      'no subscription' => __('ui.manage_subscription.status_no_subscription'),
      'active' => __('ui.manage_subscription.status_active'),
      'trialing' => __('ui.manage_subscription.status_trialing'),
      'past due', 'past_due' => __('ui.manage_subscription.status_past_due'),
      'incomplete payment', 'incomplete' => __('ui.manage_subscription.status_incomplete'),
      'incomplete expired', 'incomplete_expired' => __('ui.manage_subscription.status_incomplete_expired'),
      'canceled', 'cancelled' => __('ui.manage_subscription.status_canceled'),
      'unpaid' => __('ui.manage_subscription.status_unpaid'),
      'cancellation scheduled' => __('ui.manage_subscription.cancellation_scheduled'),
      default => __('ui.manage_subscription.status_unknown'),
    };
  @endphp
  @include('components.header')

  <main class="container main-content">
    <div class="page-header">
      <h1>{{ __('ui.manage_subscription.title') }}</h1>
      <p>{{ __('ui.manage_subscription.subtitle') }}</p>
    </div>
    @if($billingProfileRemoved)
      <div class="message info" style="margin-top:-.4rem;margin-bottom:1rem;">
        {{ __('ui.manage_subscription.billing_profile_removed') }}
      </div>
    @endif

    <div class="status-grid">
      <div class="status-item">
        <small>{{ __('ui.manage_subscription.current_plan') }}</small>
        <b>{{ $planNameBySlug($activePlan?->slug ?? 'free', $activePlan?->name ?? __('ui.dashboard.free_plan')) }}</b>
      </div>
      <div class="status-item">
        <small>{{ __('ui.manage_subscription.status') }}</small>
        @php
          $statusClass = 'active';
          if (str_contains($subscriptionStatusLower, 'cancel')) $statusClass = 'canceled';
          elseif (str_contains($subscriptionStatusLower, 'pending') || str_contains($subscriptionStatusLower, 'past due') || str_contains($subscriptionStatusLower, 'past_due') || str_contains($subscriptionStatusLower, 'no subscription') || str_contains($subscriptionStatusLower, 'unpaid') || str_contains($subscriptionStatusLower, 'incomplete')) $statusClass = 'pending';
        @endphp
        <span class="status-badge {{ $statusClass }}" id="subscriptionStatusBadge">{{ $subscriptionStatusLabel }}</span>
      </div>
      <div class="status-item">
        <small>{{ __('ui.manage_subscription.next_billing') }}</small>
        <b>
          @if(!empty($upcomingCharge['total']))
            {{ $upcomingCharge['total'] }}{{ !empty($upcomingCharge['date']) ? ' ' . __('ui.manage_subscription.on_date', ['date' => $upcomingCharge['date']]) : '' }}
          @else
            {{ __('ui.manage_subscription.not_scheduled') }}
          @endif
        </b>
      </div>
      <div class="status-item">
        <small>{{ __('ui.manage_subscription.saved_card') }}</small>
        <b>
          @if($defaultPaymentMethod)
            {{ strtoupper((string) ($defaultPaymentMethod->card->brand ?? 'card')) }} **** {{ $defaultPaymentMethod->card->last4 ?? '' }}
          @else
            <span style="color: var(--muted);">{{ __('ui.manage_subscription.not_saved') }}</span>
          @endif
        </b>
      </div>
    </div>

    <div class="grid-layout">
      <div class="card">
        <div class="card-header">
          <h2>{{ __('ui.manage_subscription.subscription_details') }}</h2>
          <p>{{ __('ui.manage_subscription.subscription_details_hint') }}</p>
        </div>

        <div class="detail-list">
          <div class="detail-row">
            <dt>{{ __('ui.manage_subscription.voice_tier') }}</dt>
            <dd>{{ $normalizeTierLabel($activeTierLabel) }}</dd>
          </div>
          @if($subscriptionRenewsAt && ! $isCancellationScheduled)
            <div class="detail-row" id="renewsOnRow">
              <dt>{{ __('ui.manage_subscription.renews_on') }}</dt>
              <dd>{{ $subscriptionRenewsAt }}</dd>
            </div>
          @endif
          @if($subscriptionEndsAt)
            <div class="detail-row">
              <dt>{{ __('ui.manage_subscription.access_ends_on') }}</dt>
              <dd>{{ $subscriptionEndsAt }}</dd>
            </div>
          @endif
          @if(!empty($upcomingCharge['total']))
            <div class="detail-row">
              <dt>{{ __('ui.manage_subscription.upcoming_invoice') }}</dt>
              <dd>{{ $upcomingCharge['total'] }}</dd>
            </div>
          @endif
        </div>

        <div class="actions">
          <a class="btn btn-primary" href="{{ route('billing.page') }}">{{ __('ui.manage_subscription.change_plan') }}</a>
          @if($hasCancelableSubscription)
            <button class="btn btn-danger" id="cancelBtn" type="button">{{ __('ui.manage_subscription.cancel_subscription') }}</button>
          @endif
        </div>
        <div id="subscriptionMessage" class="message"></div>
      </div>

      <div class="card">
        <div class="card-header">
          <h2>{{ __('ui.manage_subscription.payment_method') }}</h2>
          <p>{{ __('ui.manage_subscription.payment_method_hint') }}</p>
        </div>

        <div class="stripe-container">
          <div class="stripe-label">
            <span class="card-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24">
                <defs>
                  <linearGradient id="manageCardGradient" x1="2" y1="2" x2="22" y2="22" gradientUnits="userSpaceOnUse">
                    <stop offset="0%" stop-color="#3B82F6"></stop>
                    <stop offset="50%" stop-color="#8B5CF6"></stop>
                    <stop offset="100%" stop-color="#F97316"></stop>
                  </linearGradient>
                </defs>
                <rect x="2" y="4" width="20" height="16" rx="4" fill="url(#manageCardGradient)"></rect>
                <rect x="2" y="8" width="20" height="2" fill="rgba(255,255,255,.75)"></rect>
                <rect x="5" y="13" width="4.8" height="3.4" rx="1" fill="rgba(255,255,255,.92)"></rect>
                <rect x="11.5" y="14.2" width="7.3" height="1.1" rx=".55" fill="rgba(255,255,255,.88)"></rect>
              </svg>
            </span>
            {{ __('ui.billing.credit_or_debit_card') }}
          </div>
          <div id="card-element"></div>
          <div id="card-error" class="card-error"></div>
          @if($stripeReady)
            <p class="card-hint">{{ __('ui.manage_subscription.card_used_for_future') }}</p>
          @else
            <p class="card-hint" style="color: var(--danger);">{{ __('ui.billing.stripe_not_configured') }}</p>
          @endif
        </div>

        <div class="actions">
          <button class="btn btn-primary" id="updateCardBtn" type="button" {{ $stripeReady ? '' : 'disabled' }}>
            {{ __('ui.manage_subscription.update_saved_card') }}
          </button>
        </div>
        <div id="paymentMessage" class="message"></div>
      </div>
    </div>

    <div class="card invoices-card">
      <div class="card-header">
        <h2>{{ __('ui.manage_subscription.invoice_history') }}</h2>
        <p>{{ __('ui.manage_subscription.invoice_history_hint') }}</p>
      </div>

      @if(count($invoices) > 0)
        <div style="overflow-x: auto;">
          <table class="invoice-table">
            <thead>
              <tr>
                <th>{{ __('ui.manage_subscription.invoice_date') }}</th>
                <th>{{ __('ui.manage_subscription.invoice_status') }}</th>
                <th>{{ __('ui.manage_subscription.invoice_paid_for') }}</th>
                <th>{{ __('ui.manage_subscription.invoice_amount') }}</th>
                <th>{{ __('ui.manage_subscription.invoice_payment_details') }}</th>
              </tr>
            </thead>
            <tbody>
              @foreach($invoices as $invoice)
                @php
                  $invStatus = strtolower((string) $invoice['status']);
                  $statusBadgeClass = 'paid';
                  if ($invStatus === 'open') $statusBadgeClass = 'open';
                  elseif (in_array($invStatus, ['void', 'uncollectible'])) $statusBadgeClass = 'void';
                  $invStatusLabel = match ($invStatus) {
                    'paid' => __('ui.manage_subscription.invoice_status_paid'),
                    'open' => __('ui.manage_subscription.invoice_status_open'),
                    'void' => __('ui.manage_subscription.invoice_status_void'),
                    'uncollectible' => __('ui.manage_subscription.invoice_status_uncollectible'),
                    default => __('ui.manage_subscription.status_unknown'),
                  };
                @endphp
                <tr>
                  <td>{{ $invoice['date'] }}</td>
                  <td><span class="invoice-status {{ $statusBadgeClass }}">{{ $invStatusLabel }}</span></td>
                  <td>{{ $invoice['purpose'] ?? __('ui.manage_subscription.subscription') }}</td>
                  <td>{{ $invoice['total'] }}</td>
                  <td>
                    <div class="invoice-actions">
                      <a class="btn" target="_blank" href="{{ route('billing.invoice.view', ['invoiceId' => $invoice['id']]) }}">{{ __('ui.manage_subscription.view') }}</a>
                    </div>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @else
        <div class="empty-state">
          <p>{{ __('ui.manage_subscription.no_invoices_yet') }}</p>
        </div>
      @endif
    </div>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @php
    $manageSubscriptionI18n = [
      'update_saved_card' => __('ui.manage_subscription.update_saved_card'),
      'cancel_subscription' => __('ui.manage_subscription.cancel_subscription'),
      'stripe_not_configured' => __('ui.billing.stripe_not_configured'),
      'invalid_card_details' => __('ui.billing.invalid_card_details'),
      'updating_card' => __('ui.manage_subscription.updating_card'),
      'enter_new_card_first' => __('ui.manage_subscription.enter_new_card_first'),
      'card_update_failed' => __('ui.manage_subscription.card_update_failed'),
      'card_updated_successfully' => __('ui.manage_subscription.card_updated_successfully'),
      'canceling' => __('ui.manage_subscription.canceling'),
      'confirm_cancel_prompt' => __('ui.manage_subscription.confirm_cancel_prompt'),
      'cancellation_failed' => __('ui.manage_subscription.cancellation_failed'),
      'subscription_canceled_success' => __('ui.manage_subscription.subscription_canceled_success'),
      'cancellation_scheduled' => __('ui.manage_subscription.cancellation_scheduled'),
    ];
  @endphp
  <script>
    const stripeKey = @json($stripeKey);
    const csrfToken = '{{ csrf_token() }}';
    const userName = @json((string) auth()->user()->name);
    const userEmail = @json((string) auth()->user()->email);

    const paymentMessageBox = document.getElementById('paymentMessage');
    const subscriptionMessageBox = document.getElementById('subscriptionMessage');
    const updateCardBtn = document.getElementById('updateCardBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const subscriptionStatusBadge = document.getElementById('subscriptionStatusBadge');
    const renewsOnRow = document.getElementById('renewsOnRow');
    const cardError = document.getElementById('card-error');
    const i18n = @json($manageSubscriptionI18n);
    const localeCode = String(document.documentElement.lang || '').toLowerCase();
    const isArabicLocale = localeCode.startsWith('ar');
    const updateCardBtnDefaultText = updateCardBtn ? updateCardBtn.textContent.trim() : i18n.update_saved_card;
    const cancelBtnDefaultText = cancelBtn ? cancelBtn.textContent.trim() : i18n.cancel_subscription;
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

      return fallback || i18n.invalid_card_details;
    }

    function setMessage(messageBox, type, text) {
      if (!messageBox) return;
      messageBox.className = 'message';
      messageBox.textContent = '';
      if (!text) return;
      messageBox.classList.add(type === 'error' ? 'error' : 'success');
      messageBox.textContent = text;
    }

    function setButtonBusy(button, isBusy, text, defaultText) {
      if (!button) return;
      button.disabled = Boolean(isBusy);
      button.classList.toggle('is-loading', Boolean(isBusy));
      button.textContent = isBusy ? text : defaultText;
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

    updateCardBtn?.addEventListener('click', async () => {
      setButtonBusy(updateCardBtn, true, i18n.updating_card, updateCardBtnDefaultText);
      setMessage(paymentMessageBox, '', '');

      try {
        if (!cardComplete) {
          throw new Error(i18n.enter_new_card_first);
        }

        const paymentMethod = await collectCardPaymentMethod();
        const response = await post('{{ route('billing.payment-method') }}', { payment_method: paymentMethod });

        if (!response.ok || response.data?.ok === false) {
          throw new Error(response.data?.message || i18n.card_update_failed);
        }

        setMessage(paymentMessageBox, 'ok', response.data?.message || i18n.card_updated_successfully);
        setTimeout(() => window.location.reload(), 1500);
      } catch (error) {
        setMessage(paymentMessageBox, 'error', normalizeStripeMessage(error?.message, i18n.card_update_failed));
      } finally {
        setButtonBusy(updateCardBtn, false, '', updateCardBtnDefaultText);
      }
    });

    cancelBtn?.addEventListener('click', async () => {
      setButtonBusy(cancelBtn, true, i18n.canceling, cancelBtnDefaultText);
      setMessage(subscriptionMessageBox, '', '');

      try {
        const proceed = window.confirm(i18n.confirm_cancel_prompt);
        if (!proceed) return;

        const response = await post('{{ route('billing.cancel') }}');
        if (!response.ok || response.data?.ok === false) {
          throw new Error(response.data?.message || i18n.cancellation_failed);
        }

        setMessage(subscriptionMessageBox, 'ok', response.data?.message || i18n.subscription_canceled_success);

        subscriptionStatusBadge?.classList.remove('active', 'canceled');
        subscriptionStatusBadge?.classList.add('pending');
        if (subscriptionStatusBadge) {
          subscriptionStatusBadge.textContent = i18n.cancellation_scheduled;
        }

        renewsOnRow?.remove();
        cancelBtn?.remove();
      } catch (error) {
        setMessage(subscriptionMessageBox, 'error', normalizeStripeMessage(error?.message, i18n.cancellation_failed));
      } finally {
        setButtonBusy(cancelBtn, false, '', cancelBtnDefaultText);
      }
    });
  </script>
</body>
</html>

