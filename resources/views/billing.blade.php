<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen | Billing</title>
  <link rel="icon" type="image/svg+xml" href="{{ asset('icons/headphones.svg') }}" />
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
    }
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
    .actions .btn {
      flex: 1;
      justify-content: center;
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
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                <line x1="1" y1="10" x2="23" y2="10"></line>
              </svg>
              Credit or Debit Card
            </div>
            <div id="card-element"></div>
            <div id="card-error" class="card-error"></div>
            @if($stripeReady)
              <p class="card-hint">Card data is encrypted and securely processed by Stripe.</p>
            @else
              <p class="card-hint" style="color: var(--danger);">Billing is not configured. Add Stripe keys to enable payments.</p>
            @endif
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
          <div class="quote-row">
            <dt>Price ID</dt>
            <dd id="quotePriceId" style="font-size:.78rem;color:var(--muted);">-</dd>
          </div>
        </div>

        <div class="quick-links">
          <a class="btn" href="{{ route('pricing') }}">View All Plans</a>
          <a class="btn" href="{{ route('manage-subscription.page') }}">Subscription Details</a>
        </div>
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
    const quotePriceId = document.getElementById('quotePriceId');

    function setMessage(type, text) {
      messageBox.className = 'message';
      messageBox.textContent = '';
      if (!text) return;
      messageBox.classList.add(type === 'error' ? 'error' : 'success');
      messageBox.textContent = text;
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
          style: {
            base: {
              color: '#f5f7ff',
              fontSize: '15px',
              '::placeholder': { color: '#8f9bbb' },
            },
            invalid: { color: '#ff7a9a' },
          },
        })
      : null;

    let cardComplete = false;
    card?.mount('#card-element');
    card?.on('change', (event) => {
      cardComplete = Boolean(event.complete);
      cardError.textContent = event.error?.message || '';
    });

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
        quotePriceId.textContent = '-';
        quoteHint.textContent = 'Select a plan to preview your charge.';
        return;
      }

      quoteAmount.textContent = String(selected.meta.amount_formatted || '-');
      quotePlan.textContent = String(selected.plan.name || selected.plan.slug || '-');
      quoteCycle.textContent = cycleLabel(selected.meta);
      quotePriceId.textContent = String(selected.meta.price_id || '-');
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

    async function syncPlanAfterAction() {
      await post('{{ route('billing.sync') }}');
      window.location.href = '{{ route('manage-subscription.page') }}';
    }

    subscribeBtn?.addEventListener('click', async () => {
      subscribeBtn.disabled = true;
      setMessage('', '');

      try {
        const selected = selectedMeta();
        if (!selected) {
          throw new Error('Please choose a plan and billing cycle.');
        }

        let paymentMethod = '';
        if (cardComplete) {
          paymentMethod = await collectCardPaymentMethod();
        } else if (!hasDefaultPaymentMethod) {
          throw new Error('Please enter your credit card details to continue.');
        }

        const response = await post('{{ route('billing.subscribe') }}', {
          plan: String(selected.plan.slug || ''),
          billing_cycle: String(selected.cycle || 'monthly'),
          payment_method: paymentMethod || null,
        });

        if (response.status === 402 && response.data?.requires_action && response.data?.client_secret) {
          const confirmation = await stripe.confirmCardPayment(String(response.data.client_secret));
          if (confirmation.error) {
            throw new Error(confirmation.error.message || 'Payment authentication failed.');
          }

          await syncPlanAfterAction();
          return;
        }

        if (!response.ok || response.data?.ok === false) {
          throw new Error(response.data?.message || 'Upgrade failed.');
        }

        setMessage('ok', response.data?.message || 'Subscription updated successfully.');
        await syncPlanAfterAction();
      } catch (error) {
        setMessage('error', error.message || 'Upgrade failed.');
      } finally {
        subscribeBtn.disabled = !stripe;
      }
    });

    planSelect?.addEventListener('change', renderChargePreview);
    cycleSelect?.addEventListener('change', renderChargePreview);

    subscribeBtn.disabled = !stripe;
    renderChargePreview();
  </script>
</body>
</html>
