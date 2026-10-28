{{-- Apple Pay and Google Pay, above the card boxes (components/card-fields). It shows only
     on a phone or browser with one of them ready; elsewhere nothing changes. The wallet
     gives the page a card payment method, which is paid exactly like one typed into the
     boxes: the same request to our server, the same bank check, the same message after.
     A page makes it with ListenWalletPay(stripe, elements, options); see below. --}}
@props(['id' => 'wallet'])
<div class="wallet-pay" id="{{ $id }}" hidden>
  <div class="wallet-pay-button" id="{{ $id }}-button"></div>
  <p class="wallet-pay-or"><span>{{ __('ui.billing.wallet_or_card') }}</span></p>
</div>
@once
  <style>
    .wallet-pay { margin-bottom: 1rem; }
    .wallet-pay[hidden] { display: none; }
    .wallet-pay-button { min-height: 48px; }
    .wallet-pay-or { display: flex; align-items: center; gap: .75rem; margin: 1rem 0 0; color: var(--muted); font-size: .88rem; }
    .wallet-pay-or::before, .wallet-pay-or::after { content: ''; flex: 1; border-top: 1px solid var(--line); }
  </style>
  <script>
    /**
     * options: {
     *   id:       the component's id,
     *   country:  the payment account's country (config services.stripe.country),
     *   charge:   { currency, amount, label } or null: what the wallet sheet shows,
     *   theme:    'dark' | 'light',
     *   onPay:    function (paymentMethodId, done): pay with it, then done('success' | 'fail')
     *             closes the wallet sheet.
     * }
     * Returns { set(charge, shown) }: the amount to show (in the smallest unit of the
     * currency) and whether it is offered now; a null charge hides it.
     */
    window.ListenWalletPay = function (stripe, elements, options) {
      var box = document.getElementById(options.id);
      if (!box || !stripe || !elements) return { set: function () {} };
      var charge = options.charge;
      var request = stripe.paymentRequest({
        country: options.country,
        currency: (charge ? charge.currency : 'usd').toLowerCase(),
        total: { label: charge ? charge.label : 'Listen', amount: charge ? charge.amount : 0 },
        // Only Apple Pay and Google Pay: a card saved in the browser is typed into the boxes.
        disableWallets: ['link', 'browserCard'],
      });
      var ready = false;
      var wanted = Boolean(charge);
      function show() { box.hidden = !(ready && wanted && charge); }
      request.canMakePayment().then(function (result) {
        if (!result || !(result.applePay || result.googlePay)) return;
        elements.create('paymentRequestButton', {
          paymentRequest: request,
          style: { paymentRequestButton: { type: 'buy', theme: options.theme === 'light' ? 'dark' : 'light', height: '48px' } },
        }).mount('#' + options.id + '-button');
        ready = true;
        show();
      }).catch(function () { /* no wallet: the card boxes are there */ });
      request.on('paymentmethod', function (event) {
        options.onPay(event.paymentMethod.id, function (status) {
          try { event.complete(status); } catch (_e) { /* the sheet is already closed */ }
        });
      });
      return {
        set: function (next, shown) {
          charge = next && next.amount > 0 ? next : null;
          wanted = Boolean(shown);
          if (charge) {
            request.update({ currency: charge.currency.toLowerCase(), total: { label: charge.label, amount: charge.amount } });
          }
          show();
        },
      };
    };
  </script>
@endonce
