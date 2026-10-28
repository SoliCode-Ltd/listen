{{-- Apple Pay and Google Pay: Stripe's own wallet button. It shows wherever the wallet can
     be used, also in a desktop browser of another make (Apple Pay in Chrome on Windows is paid
     by scanning a code with an iPhone), once the wallet is switched on in Stripe and the
     site's domain is registered there; where neither can be used the box stays hidden and
     the card boxes work as before. The wallet hands over a card, so a page pays with it the same
     way as with the card boxes. A page makes it with
     ListenWalletButton(stripe, id, { mode, amount, billingDetails, onConfirm }) and
     shows it with .show(true) when paying by card is the choice. --}}
@props(['id' => 'wallet'])
<div class="wallet-pay" id="{{ $id }}" hidden>
  <div class="wallet-button" id="{{ $id }}-button"></div>
  <div class="wallet-or"><span>{{ __('ui.billing.wallet_or_card') }}</span></div>
</div>
@once
  <style>
    .wallet-pay { display: grid; gap: .85rem; margin-bottom: 1rem; }
    .wallet-pay[hidden] { display: none; }
    .wallet-or { display: flex; align-items: center; gap: .75rem; color: var(--muted); font-size: .85rem; }
    .wallet-or::before, .wallet-or::after { content: ''; flex: 1; height: 1px; background: var(--line); }
  </style>
  <script>
    window.ListenWalletButton = function (stripe, id, options) {
      var box = document.getElementById(id);
      if (!stripe || !box || !(Number(options.amount) > 0)) return null;
      var light = document.documentElement.getAttribute('data-theme') === 'light';
      // The wallet shows the amount charged, in the currency the card boxes charge in.
      var elements = stripe.elements({
        mode: options.mode,
        amount: Math.round(Number(options.amount)),
        currency: 'usd',
        paymentMethodCreation: 'manual',
        // The site's corners (--radius); the wallets draw their own marks in their own colours.
        appearance: { variables: { borderRadius: '8px' } },
      });
      var type = options.buttonType || 'plain';
      var narrow = Boolean(window.matchMedia && window.matchMedia('(max-width: 640px)').matches);
      // An iPhone or iPad (iPadOS reports itself as a Mac with a touch screen) pays with its own
      // wallet only: the other one is little used there and would ask to sign in first.
      var appleTouch = /iPhone|iPad|iPod/.test(navigator.userAgent || '')
        || (/Macintosh/.test(navigator.userAgent || '') && Number(navigator.maxTouchPoints || 0) > 1);
      var button = elements.create('expressCheckout', {
        // 'always': each wallet shows in other makers' browsers too (on a computer the phone
        // wallet is paid by scanning a code); on 'auto' each only showed in its own browser.
        paymentMethods: { applePay: 'always', googlePay: appleTouch ? 'never' : 'always', link: 'never', paypal: 'never', amazonPay: 'never', klarna: 'never' },
        buttonTheme: { applePay: light ? 'black' : 'white', googlePay: light ? 'black' : 'white' },
        buttonType: { applePay: type, googlePay: type },
        buttonHeight: 48,
        // Side by side on a wide screen, one under the other on a phone; never hidden in a menu.
        layout: { maxColumns: narrow ? 1 : 2, overflow: 'never' },
      });
      var ready = false;
      var wanted = false;
      var paint = function () { box.hidden = !(ready && wanted); };
      button.on('ready', function (event) {
        var found = event.availablePaymentMethods || {};
        ready = Boolean(found.applePay || found.googlePay);
        paint();
      });
      // No wallet could load (a blocked frame, a domain Stripe does not know yet): the card boxes stay.
      button.on('loaderror', function () { ready = false; paint(); });
      // The wallet's own sheet closes on a failure; the page then says what went wrong.
      button.on('confirm', function (event) {
        var fail = function (error) {
          event.paymentFailed({ reason: 'fail' });
          return options.onConfirm(null, error);
        };
        elements.submit().then(function (submitted) {
          if (submitted.error) return fail(submitted.error);
          return stripe.createPaymentMethod({
            elements: elements,
            params: { billing_details: options.billingDetails || {} },
          }).then(function (result) {
            if (result.error) return fail(result.error);
            return options.onConfirm(String(result.paymentMethod.id || ''), null);
          });
        }).catch(function (error) { return fail(error); });
      });
      button.mount('#' + id + '-button');
      return {
        show: function (on) { wanted = Boolean(on); paint(); },
        setAmount: function (amount) {
          if (Number(amount) > 0) elements.update({ amount: Math.round(Number(amount)) });
        },
      };
    };
  </script>
@endonce
