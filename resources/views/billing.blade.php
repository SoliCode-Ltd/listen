<!doctype html>
<html lang="en" data-theme="{{ \App\Support\SeoHelper::defaultTheme() }}">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  @include('components.seo', ['pageTitle' => 'Billing', 'page' => 'billing'])
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script src="https://js.stripe.com/v3/"></script>
  @include('components.styles')
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
  @include('components.header')

  <main class="container main-content">
    <div class="page-header">
      <h1>Billing</h1>
      <p>Upgrade your plan, manage payment methods, and view charge previews.</p>
    </div>

    <div class="status-grid" style="margin-bottom: 1rem;">
      <div class="status-item">
        <small>Current Plan</small>
        <b>{{ $activePlan?->name ?? 'FREE' }}</b>
      </div>
      <div class="status-item">
        <small>Voice Quality</small>
        <b>{{ $activeTierLabel }}</b>
      </div>
      <div class="status-item">
        <small>Saved Card</small>
        <b>
          @if($defaultPaymentMethod)
            {{ strtoupper((string) ($defaultPaymentMethod->card->brand ?? 'card')) }} **** {{ $defaultPaymentMethod->card->last4 ?? '' }}
          @else
            Not saved
          @endif
        </b>
      </div>
    </div>

    <div class="grid-layout">
      <div class="card">
        <div class="card-header">
          <h2>Upgrade Plan</h2>
          <p>Select your preferred plan and billing cycle, then complete payment.</p>
        </div>

        <form id="billingForm" class="form-grid">
          <div class="form-row">
            <div class="form-group">
              <label for="plan">Plan</label>
              <select id="plan">
                @foreach($plans as $plan)
                  <option value="{{ $plan['slug'] }}" {{ $selectedPlan === $plan['slug'] ? 'selected' : '' }}>
                    {{ $plan['name'] }} - {{ $plan['voice_tier'] }}
                  </option>
                @endforeach
              </select>
            </div>
            <div class="form-group">
              <label for="cycle">Billing Cycle</label>
              <select id="cycle">
                <option value="monthly">Monthly</option>
                <option value="yearly">Yearly (Save 20%)</option>
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
              Credit or Debit Card
            </div>
            <div id="card-element"></div>
            <div id="card-error" class="card-error"></div>
            @if($stripeReady)
              <p class="card-hint">Card data is encrypted and securely processed by Stripe.</p>
            @else
              <p class="card-hint" style="color: var(--danger);">Billing is not configured. Add Stripe keys to enable payments.</p>
            @endif
            <div class="consent-box">
              Subscriptions renew automatically using your payment card until you cancel.
              <!-- <div class="consent-row">Your payment card will be used for the next billing cycles.</div> -->
            </div>
          </div>

          <div class="actions">
            <button class="btn btn-primary" id="subscribeBtn" type="button" {{ $stripeReady ? '' : 'disabled' }}>
              Confirm & Pay
            </button>
            <a class="btn" href="{{ route('manage-subscription.page') }}">Manage Subscription</a>
          </div>
          <div id="message" class="message"></div>
        </form>
      </div>

      <div class="card quote-section">
        <div class="card-header">
          <h2>Charge Preview</h2>
          <p>Exact amount based on your selected plan and billing cycle.</p>
        </div>

        <div class="quote-highlight">
          <small>Amount to be charged</small>
          <p class="amount" id="quoteAmount">-</p>
          <p class="hint" id="quoteHint">Select a plan to preview your charge.</p>
        </div>

        <div class="quote-details">
          <div class="quote-row">
            <dt>Plan</dt>
            <dd id="quotePlan">-</dd>
          </div>
          <div class="quote-row">
            <dt>Billing Cycle</dt>
            <dd id="quoteCycle">-</dd>
          </div>
        </div>
<!--
        <div class="quick-links">
          <a class="btn" href="{{ route('pricing') }}">View All Plans</a>
          <a class="btn" href="{{ route('manage-subscription.page') }}">Subscription Details</a>
        </div> -->
      </div>
    </div>
  </main>

  @include('components.footer')
  @include('components.scripts')
  <script>
    const stripeKey = @json($stripeKey);
    const csrfToken = '{{ csrf_token() }}';
    const userName = @json((string) auth()->user()->name);
    const userEmail = @json((string) auth()->user()->email);
    const hasDefaultPaymentMethod = @json((bool) $defaultPaymentMethod);
    const planCatalog = @json(collect($plans)->keyBy('slug')->all());

    const messageBox = document.getElementById('message');
    const subscribeBtn = document.getElementById('subscribeBtn');
    const planSelect = document.getElementById('plan');
    const cycleSelect = document.getElementById('cycle');
    const cardError = document.getElementById('card-error');

    const quoteAmount = document.getElementById('quoteAmount');
    const quoteHint = document.getElementById('quoteHint');
    const quotePlan = document.getElementById('quotePlan');
    const quoteCycle = document.getElementById('quoteCycle');
    const subscribeBtnDefaultText = subscribeBtn ? subscribeBtn.textContent.trim() : 'Confirm & Pay';
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
      cardError.textContent = event.error?.message || '';
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
      const unit = intervalCount > 1 ? `${interval}s` : interval;
      return intervalCount > 1 ? `Every ${intervalCount} ${unit}` : `Every ${unit}`;
    }

    function renderChargePreview() {
      const selected = selectedMeta();
      if (!selected) {
        quoteAmount.textContent = '-';
        quotePlan.textContent = '-';
        quoteCycle.textContent = '-';
        quoteHint.textContent = 'Select a plan to preview your charge.';
        return;
      }

      quoteAmount.textContent = String(selected.meta.amount_formatted || '-');
      quotePlan.textContent = String(selected.plan.name || selected.plan.slug || '-');
      quoteCycle.textContent = cycleLabel(selected.meta);
      quoteHint.textContent = `You will be charged ${selected.meta.amount_formatted} (${cycleLabel(selected.meta)}).`;
    }

    async function collectCardPaymentMethod() {
      if (!stripe || !card) throw new Error('Stripe is not configured.');
      const result = await stripe.createPaymentMethod({
        type: 'card',
        card,
        billing_details: {
          name: userName || undefined,
          email: userEmail || undefined,
        },
      });

      if (result.error) {
        throw new Error(result.error.message || 'Card details are invalid.');
      }

      return String(result.paymentMethod?.id || '');
    }

    subscribeBtn?.addEventListener('click', async () => {
      setSubscribeButtonBusy(true, 'Processing payment...');
      setMessage('', '');

      try {
        const selected = selectedMeta();
        if (!selected) {
          throw new Error('Please choose a plan and billing cycle.');
        }

        let paymentMethod = '';
        if (cardComplete) {
          setSubscribeButtonBusy(true, 'Securing card...');
          paymentMethod = await collectCardPaymentMethod();
        } else if (!hasDefaultPaymentMethod) {
          throw new Error('Please enter your credit card details to continue.');
        }

        setSubscribeButtonBusy(true, 'Finalizing subscription...');
        const response = await post('{{ route('billing.subscribe') }}', {
          plan: String(selected.plan.slug || ''),
          billing_cycle: String(selected.cycle || 'monthly'),
          payment_method: paymentMethod || null,
        });

        if (response.status === 402 && response.data?.requires_action && response.data?.client_secret) {
          setSubscribeButtonBusy(true, 'Waiting for bank confirmation...');
          const confirmation = await stripe.confirmCardPayment(String(response.data.client_secret));
          if (confirmation.error) {
            throw new Error(confirmation.error.message || 'Payment authentication failed.');
          }

          setMessage('ok', 'Payment confirmed. Redirecting to your subscription details...');
          window.location.href = '{{ route('manage-subscription.page') }}';
          return;
        }

        if (!response.ok || response.data?.ok === false) {
          throw new Error(response.data?.message || 'Upgrade failed.');
        }

        setMessage('ok', response.data?.message || 'Subscription updated successfully. Redirecting...');
        window.location.href = '{{ route('manage-subscription.page') }}';
      } catch (error) {
        setMessage('error', error.message || 'Upgrade failed.');
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
