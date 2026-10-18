@include('components.page-head', ['pageTitle' => $packName, 'page' => 'billing'])
  @if($canBuy && $stripeReady)
    <script src="https://js.stripe.com/v3/"></script>
  @endif
  <style>
    .pack-main { padding: 1.5rem 0 3rem; }
    .pack-shell { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; align-items: start; }
    .pack-card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
    }
    .pack-card h1, .pack-card h2 { margin: 0 0 .35rem; }
    .pack-card h1 { font-size: clamp(1.5rem, 3vw, 2rem); line-height: 1.25; }
    .pack-card h2 { font-size: 1.2rem; }
    .pack-card p { color: var(--muted); margin: 0; }
    .pack-kicker { display: inline-block; font-size: .78rem; font-weight: 700; color: var(--brand-accent); margin-bottom: .5rem; }
    .pack-headline { margin: 1rem 0; padding: .9rem 1rem; border: 1px solid var(--line); border-radius: var(--radius); font-weight: 700; }
    .pack-lines { list-style: none; margin: 0; padding: 0; }
    .pack-lines li { display: flex; justify-content: space-between; gap: 1rem; padding: .55rem 0; border-bottom: 1px solid var(--line); }
    .pack-lines li:last-child { border-bottom: 0; }
    .pack-lines span { color: var(--muted); }
    .pack-notice { margin-bottom: 1rem; padding: .85rem 1rem; border-radius: var(--radius); border: 1px solid var(--line); background: var(--surface2); }
    .pack-notice.is-ok { border-color: rgba(34, 197, 94, .45); }
    .pack-pay { display: grid; gap: 1rem; }
    .pack-option { border: 1px solid var(--line); border-radius: var(--radius); padding: 1rem; display: grid; gap: .75rem; }
    .pack-option-head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
    .pack-option-head strong { font-size: 1.05rem; }
    .pack-amount { font-weight: 800; font-size: 1.15rem; }
    #pack-card-element { padding: .8rem .9rem; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface); }
    .pack-error { color: #ff7a9a; min-height: 1.2em; font-size: .9rem; }
    .pack-small { font-size: .85rem; color: var(--muted); }
    .pack-manual-links { display: grid; gap: .6rem; }
    @media (max-width: 900px) { .pack-shell { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  @include('components.header')

  @php
    $locale = app()->getLocale();
    $lines = \App\Support\PlanPricingLines::forPlan($pack, $locale);
    $expiresOn = $wallet['expires_at']?->locale($locale)->translatedFormat('j F Y');
  @endphp

  <main class="container pack-main">
    <div class="pack-shell">
      <section class="pack-card">
        <span class="pack-kicker">{{ __('ui.pack.kicker') }}</span>
        <h1>{{ $packName }}</h1>
        <p>{{ __('ui.pack.subtitle', ['days' => $pack->packDays()]) }}</p>

        <div class="pack-headline">{{ __('ui.pack.headline', ['count' => number_format((int) $pack->monthly_chars), 'days' => $pack->packDays()]) }}</div>

        <ul class="pack-lines">
          @foreach($lines as $line)
            <li><span>{{ $line['label'] }}</span><strong>{{ $line['value'] }}</strong></li>
          @endforeach
        </ul>
      </section>

      <aside class="pack-card">
        @if($wallet['active'])
          <div class="pack-notice is-ok">
            {{ __('ui.pack.you_have_left', ['count' => number_format($wallet['remaining']), 'date' => $expiresOn]) }}
            @if($canBuy)
              <div class="pack-small">{{ __('ui.pack.buy_again_note', ['count' => number_format((int) $pack->monthly_chars), 'days' => $pack->packDays()]) }}</div>
            @endif
          </div>
        @endif

        @if($pendingManual)
          <div class="pack-notice">{{ __('ui.pack.manual_pending') }}</div>
        @endif

        @if(! $canBuy)
          <div class="pack-notice">
            {{ (int) ($currentPlan?->price_cents ?? 0) > 0 ? __('ui.pack.plan_includes_premium') : __('ui.pack.not_available') }}
          </div>
          <a class="btn btn-primary" href="{{ route('dashboard') }}">{{ __('ui.pack.go_to_dashboard') }}</a>
        @else
          <h2>{{ __('ui.pack.pay_title') }}</h2>
          <p>{{ __('ui.pack.pay_subtitle') }}</p>

          <div class="pack-pay" style="margin-top: 1rem;">
            @if($stripeReady)
              <form class="pack-option" id="pack-card-form">
                <div class="pack-option-head">
                  <strong>{{ __('ui.pack.pay_by_card') }}</strong>
                  <span class="pack-amount" dir="ltr">{{ $cardPrice }}</span>
                </div>
                <div id="pack-card-element"></div>
                <div class="pack-error" id="pack-card-error" role="alert"></div>
                <button class="btn btn-primary" type="submit" id="pack-card-button">{{ __('ui.pack.pay_amount', ['amount' => "\u{2066}".$cardPrice."\u{2069}"]) }}</button>
                <div class="pack-small">{{ __('ui.pack.card_note') }}</div>
              </form>
            @endif

            @if(count($manualOptions) > 0)
              <div class="pack-option">
                <div class="pack-option-head"><strong>{{ __('ui.pack.pay_locally') }}</strong></div>
                <div class="pack-manual-links">
                  @foreach($manualOptions as $option)
                    <a class="btn" href="{{ $option['url'] }}">{{ __('ui.pack.pay_with', ['method' => $option['meta']['label'] ?? $option['method'], 'amount' => $option['amount']]) }}</a>
                  @endforeach
                </div>
                <div class="pack-small">{{ __('ui.pack.manual_note') }}</div>
              </div>
            @endif

            @if(! $stripeReady && count($manualOptions) === 0)
              <div class="pack-notice">{{ __('ui.pack.not_available') }}</div>
            @endif
          </div>
        @endif
      </aside>
    </div>
  </main>

  @include('components.footer')
  @include('components.scripts')

  @if($canBuy && $stripeReady)
  <script>
    (function () {
      const form = document.getElementById('pack-card-form');
      const button = document.getElementById('pack-card-button');
      const errorBox = document.getElementById('pack-card-error');
      const buttonText = button.textContent;
      const csrfToken = '{{ csrf_token() }}';
      const i18n = {
        working: @json(__('ui.pack.card_working')),
        failed: @json(__('ui.pack.card_failed')),
        incomplete: @json(__('ui.pack.card_incomplete')),
      };
      const conversionSendTo = @json(\App\Support\SeoHelper::googleAdsConversionSendTo());
      const conversionValue = {{ round(((int) $pack->getEffectivePriceCents()) / 100, 2) }};
      const isLight = () => document.documentElement.getAttribute('data-theme') === 'light';
      const cardStyle = () => isLight()
        ? { base: { color: '#13213d', iconColor: '#2e4f8f', fontSize: '15px', '::placeholder': { color: '#7a8da8' } }, invalid: { color: '#c1244f', iconColor: '#c1244f' } }
        : { base: { color: '#f5f7ff', iconColor: '#9fb0d4', fontSize: '15px', '::placeholder': { color: '#8f9bbb' } }, invalid: { color: '#ff7a9a', iconColor: '#ff7a9a' } };

      const stripe = Stripe(@json($stripeKey));
      const card = stripe.elements().create('card', { hidePostalCode: true, style: cardStyle() });
      let cardComplete = false;
      card.mount('#pack-card-element');
      card.on('change', (event) => {
        cardComplete = Boolean(event.complete);
        errorBox.textContent = event.error ? event.error.message : '';
      });
      new MutationObserver(() => card.update({ style: cardStyle() }))
        .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

      async function post(url, payload) {
        const res = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify(payload),
        });
        return { status: res.status, data: await res.json().catch(() => ({})) };
      }

      function firstError(data) {
        if (data && data.message) return data.message;
        const errors = data && data.errors ? Object.values(data.errors) : [];
        return errors.length && errors[0][0] ? errors[0][0] : i18n.failed;
      }

      function conversion(purchaseId) {
        return new Promise((resolve) => {
          if (typeof gtag !== 'function' || !conversionSendTo) { resolve(); return; }
          gtag('event', 'conversion', { send_to: conversionSendTo, value: conversionValue, currency: 'USD', transaction_id: 'pack-' + purchaseId, event_callback: resolve });
          setTimeout(resolve, 1500);
        });
      }

      async function finish(response) {
        if (response.data && response.data.ok) {
          await conversion(response.data.purchase || '');
          window.location.href = response.data.redirect;
          return true;
        }
        return false;
      }

      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (button.disabled) return;
        if (!cardComplete) { errorBox.textContent = i18n.incomplete; return; }

        button.disabled = true;
        button.textContent = i18n.working;
        errorBox.textContent = '';

        try {
          const method = await stripe.createPaymentMethod({ type: 'card', card });
          if (method.error) throw new Error(method.error.message);

          let response = await post(@json(route('billing.pack.card')), { payment_method: method.paymentMethod.id });
          if (await finish(response)) return;

          if (response.status === 402 && response.data.requires_action) {
            const check = await stripe.confirmCardPayment(response.data.client_secret);
            if (check.error) throw new Error(check.error.message);
            response = await post(@json(route('billing.pack.card.confirm')), { purchase: response.data.purchase });
            if (await finish(response)) return;
          }

          throw new Error(firstError(response.data));
        } catch (error) {
          errorBox.textContent = error.message || i18n.failed;
          button.disabled = false;
          button.textContent = buttonText;
        }
      });
    })();
  </script>
  @endif
</body>
</html>
