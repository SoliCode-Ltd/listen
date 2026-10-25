{{-- A card in three boxes, each with its own label: the number, the expiry date and the
     security code. Stripe's own secure fields go in the boxes, so card details never reach
     our server. A page makes them with ListenCardFields(elements, id, style, onChange) and
     pays with the number field (`.card`): Stripe reads the other two with it. --}}
@props(['id' => 'card'])
<div class="card-fields" id="{{ $id }}-fields">
  <div class="card-field card-field-number">
    <span class="card-field-label">{{ __('ui.billing.card_number') }}</span>
    <div class="card-input" id="{{ $id }}-number"></div>
  </div>
  <div class="card-field">
    <span class="card-field-label">{{ __('ui.billing.card_expiry') }}</span>
    <div class="card-input" id="{{ $id }}-expiry"></div>
  </div>
  <div class="card-field">
    <span class="card-field-label">{{ __('ui.billing.card_cvc') }}</span>
    <div class="card-input" id="{{ $id }}-cvc"></div>
  </div>
</div>
@once
  <style>
    .card-fields { display: grid; grid-template-columns: 1fr 1fr; gap: .85rem; }
    .card-field-number { grid-column: 1 / -1; }
    .card-field-label { display: block; margin-bottom: .4rem; color: var(--fg, var(--text)); font-size: .9rem; font-weight: 600; cursor: default; }
    .card-input {
      min-height: 48px;
      padding: .85rem .9rem;
      border: 1px solid var(--line);
      border-radius: var(--radius, 12px);
      background: var(--surface);
      transition: border-color .15s ease, box-shadow .15s ease;
    }
    .card-input.StripeElement--focus {
      border-color: var(--a, var(--brand-accent));
      box-shadow: 0 0 0 3px color-mix(in oklab, var(--a, var(--brand-accent)) 12%, transparent);
    }
    .card-input.StripeElement--invalid { border-color: var(--danger); }
  </style>
  <script>
    window.ListenCardFields = function (elements, id, style, onChange) {
      var order = ['number', 'expiry', 'cvc'];
      var types = { number: 'cardNumber', expiry: 'cardExpiry', cvc: 'cardCvc' };
      var extra = { number: { showIcon: true }, expiry: {}, cvc: { placeholder: '123' } };
      var fields = {}, done = {}, errors = {};
      order.forEach(function (key) {
        var box = document.getElementById(id + '-' + key);
        var field = elements.create(types[key], Object.assign({ style: style }, extra[key]));
        field.mount(box);
        // Its label puts the cursor in it (a label cannot point into Stripe's frame).
        var label = box.previousElementSibling;
        if (label) label.addEventListener('click', function () { field.focus(); });
        field.on('change', function (event) {
          done[key] = Boolean(event.complete);
          errors[key] = event.error || null;
          var error = null;
          order.forEach(function (k) { if (!error && errors[k]) error = errors[k]; });
          if (onChange) onChange({ complete: order.every(function (k) { return done[k]; }), error: error });
        });
        fields[key] = field;
      });
      // A finished number moves on to the expiry date, and a finished date to the code.
      fields.number.on('change', function (event) { if (event.complete) fields.expiry.focus(); });
      fields.expiry.on('change', function (event) { if (event.complete) fields.cvc.focus(); });
      return {
        card: fields.number,
        clear: function () { order.forEach(function (k) { fields[k].clear(); }); },
        focus: function () { fields.number.focus(); },
        update: function (options) { order.forEach(function (k) { fields[k].update(options); }); },
      };
    };
  </script>
@endonce
