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
  {{-- Where the wallet showed before on this device, its place is kept while Stripe gets it
       ready, so it never pushes the card boxes down when it arrives. --}}
  <div class="wallet-skeleton" aria-hidden="true"></div>
  <div class="wallet-button" id="{{ $id }}-button"></div>
  <div class="wallet-or"><span>{{ __('ui.billing.wallet_or_card') }}</span></div>
</div>
@once
  <style>
    .wallet-pay { position: relative; display: grid; gap: 1rem; margin-bottom: 1rem; }
    .wallet-pay[hidden] { display: none; }
    .wallet-skeleton { display: none; grid-template-columns: repeat(var(--wallets, 1), minmax(0, 1fr)); gap: 12px; }
    .wallet-skeleton span {
      height: 48px;
      border-radius: 8px;
      background: color-mix(in oklab, var(--text) 7%, var(--surface));
      animation: wallet-wait 1.4s ease-in-out infinite;
    }
    .wallet-pay.is-loading .wallet-skeleton { display: grid; }
    /* Laid out (Stripe measures it) but out of sight until it is ready. */
    .wallet-pay.is-loading .wallet-button { position: absolute; inset-inline: 0; top: 0; visibility: hidden; pointer-events: none; }
    .wallet-pay.is-ready .wallet-button { animation: wallet-in .25s ease-out; }
    @keyframes wallet-wait { 50% { opacity: .55; } }
    @keyframes wallet-in { from { opacity: 0; } }
    @media (prefers-reduced-motion: reduce) {
      .wallet-skeleton span, .wallet-pay.is-ready .wallet-button { animation: none; }
    }
    .wallet-or { display: flex; align-items: center; gap: .75rem; color: var(--muted); font-size: .85rem; }
    .wallet-or::before, .wallet-or::after { content: ''; flex: 1; height: 1px; background: var(--line); }
    /* The same lift on hover as the site's other buttons, for both wallets alike. */
    .wallet-button iframe { transition: transform .2s ease; }
    .wallet-button iframe:hover { transform: translateY(-1px); }
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
        // The buttons' words in the page's language, not the browser's: an Arabic page says
        // its call to action in Arabic.
        locale: String(document.documentElement.lang || '').toLowerCase().indexOf('ar') === 0 ? 'ar' : 'en',
        // The site's corners (--radius); the wallets draw their own marks in their own colours.
        appearance: { variables: { borderRadius: '8px' } },
      });
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
        // Logos only: each wallet would write its words in its own font, never the site's,
        // so the page's own words (in the site font) say what the buttons do.
        buttonType: { applePay: 'plain', googlePay: 'plain' },
        buttonHeight: 48,
        // Side by side on a wide screen, one under the other on a phone; never hidden in a menu.
        layout: { maxColumns: narrow ? 1 : 2, overflow: 'never' },
      });
      // How many wallets this device showed last time: their place is kept while Stripe
      // gets them ready (the first visit waits, as before).
      var memoryKey = 'listen_wallets';
      var remembered = 0;
      try { remembered = Math.max(0, Math.min(2, parseInt(window.localStorage.getItem(memoryKey) || '0', 10) || 0)); } catch (e) {}
      var skeleton = box.querySelector('.wallet-skeleton');
      if (skeleton && remembered > 0) {
        for (var i = 0; i < remembered; i++) skeleton.appendChild(document.createElement('span'));
        skeleton.style.setProperty('--wallets', String(narrow ? 1 : remembered));
      }
      var ready = false;
      var wanted = false;
      var waiting = remembered > 0;
      var paint = function () {
        box.hidden = !(wanted && (ready || waiting));
        box.classList.toggle('is-loading', waiting && !ready);
        box.classList.toggle('is-ready', ready);
      };
      var remember = function (count) {
        try {
          if (count > 0) window.localStorage.setItem(memoryKey, String(count));
          else window.localStorage.removeItem(memoryKey);
        } catch (e) {}
      };
      // Never a placeholder for good: a wallet that takes too long simply arrives when it can.
      window.setTimeout(function () { if (waiting && !ready) { waiting = false; paint(); } }, 8000);
      button.on('ready', function (event) {
        var found = event.availablePaymentMethods || {};
        var count = (found.applePay ? 1 : 0) + (found.googlePay ? 1 : 0);
        ready = count > 0;
        waiting = false;
        remember(count);
        paint();
      });
      // No wallet could load (a blocked frame, a domain Stripe does not know yet): the card boxes stay.
      button.on('loaderror', function () { ready = false; waiting = false; remember(0); paint(); });
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
