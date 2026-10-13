@include('components.page-head', ['pageTitle' => __('ui.billing.manual_page_title'), 'page' => 'billing'])
  <style>
    .manual-main {
      padding: 1.5rem 0 3rem;
    }
    .manual-shell {
      display: grid;
      grid-template-columns: 1.15fr .85fr;
      gap: 1.25rem;
    }
    .manual-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
    }
    .manual-card h1,
    .manual-card h2 {
      margin: 0 0 .35rem;
    }
    .manual-card p {
      color: var(--muted);
      margin: 0;
    }
    .manual-stack {
      display: grid;
      gap: 1rem;
    }
    .manual-hero {
      display: grid;
      gap: .8rem;
      margin-bottom: 1.25rem;
    }
    .manual-kicker {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      width: fit-content;
      padding: .35rem .75rem;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--brand-accent) 12%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--brand-accent) 28%, var(--line));
      color: var(--brand-subtle);
      font-size: .8rem;
      font-weight: 700;
      letter-spacing: .04em;
      text-transform: uppercase;
    }
    .manual-amount {
      padding: 1.15rem 1.25rem;
      border-radius: var(--radius-lg);
      border: 1px solid color-mix(in oklab, var(--brand-accent) 36%, var(--line));
      background: linear-gradient(135deg, color-mix(in oklab, var(--brand-accent) 10%, var(--surface)), var(--surface));
    }
    .manual-amount small {
      display: block;
      color: var(--muted);
      margin-bottom: .35rem;
      text-transform: uppercase;
      letter-spacing: .05em;
      font-size: .72rem;
    }
    .manual-amount strong {
      display: inline-block;
      font-size: clamp(1.8rem, 3vw, 2.4rem);
      line-height: 1;
      color: var(--text);
      direction: ltr;
      unicode-bidi: isolate;
    }
    .manual-grid {
      display: grid;
      gap: .9rem;
    }
    .manual-detail,
    .manual-step,
    .manual-status {
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--surface) 82%, transparent);
      padding: 1rem;
    }
    .manual-detail dt,
    .manual-status dt {
      color: var(--muted);
      font-size: .78rem;
      text-transform: uppercase;
      letter-spacing: .04em;
      margin-bottom: .25rem;
    }
    .manual-detail dd,
    .manual-status dd {
      margin: 0;
      font-weight: 600;
    }
    .manual-detail-list {
      display: grid;
      gap: .75rem;
    }
    .manual-destination {
      display: grid;
      gap: .55rem;
    }
    .destination-line {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .75rem;
      padding: .75rem .85rem;
      border-radius: var(--radius);
      background: var(--surface2);
      border: 1px solid color-mix(in oklab, var(--line) 75%, transparent);
    }
    .destination-value {
      font-family: var(--font-mono);
      direction: ltr;
      unicode-bidi: isolate;
      color: var(--text);
      word-break: break-all;
    }
    .copy-btn {
      padding: .45rem .7rem;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--text);
      cursor: pointer;
      font-size: .85rem;
      font-weight: 600;
    }
    .copy-btn:hover {
      border-color: color-mix(in oklab, var(--brand-accent) 35%, var(--line));
    }
    .manual-steps {
      display: grid;
      gap: .8rem;
    }
    .manual-step strong {
      display: block;
      margin-bottom: .35rem;
      font-size: .95rem;
    }
    .manual-step p {
      line-height: 1.6;
    }
    .manual-form {
      display: grid;
      gap: .9rem;
    }
    .manual-form label {
      display: block;
      margin-bottom: .4rem;
      font-size: .85rem;
      font-weight: 600;
      color: color-mix(in oklab, var(--text) 88%, var(--muted));
    }
    .manual-form input[type="file"] {
      width: 100%;
      padding: .85rem;
      border-radius: var(--radius);
      border: 1px dashed var(--line);
      background: var(--surface2);
      color: var(--muted);
    }
    .manual-note {
      padding: .9rem 1rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--brand-accent) 30%, var(--line));
      background: color-mix(in oklab, var(--brand-accent) 9%, var(--surface));
      color: var(--text);
      line-height: 1.6;
    }
    .manual-alert {
      padding: .9rem 1rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--ok) 40%, var(--line));
      background: color-mix(in oklab, var(--ok) 12%, var(--surface));
      color: color-mix(in oklab, var(--ok) 86%, var(--text));
      margin-bottom: 1rem;
      line-height: 1.6;
    }
    .manual-error {
      padding: .9rem 1rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--danger) 40%, var(--line));
      background: color-mix(in oklab, var(--danger) 12%, var(--surface));
      color: color-mix(in oklab, var(--danger) 88%, var(--text));
      margin-bottom: 1rem;
    }
    .manual-actions {
      display: flex;
      gap: .75rem;
      flex-wrap: wrap;
      margin-top: .35rem;
    }
    .manual-meta {
      display: grid;
      gap: .75rem;
    }
    @media (max-width: 980px) {
      .manual-shell {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  @php
    $planName = $plan->getLocalizedName(app()->getLocale());
  @endphp
  @include('components.header')

  <main class="container manual-main">
    @if(session('manual_payment_success'))
      <div class="manual-alert">{{ session('manual_payment_success') }}</div>
    @endif

    @if($errors->any())
      <div class="manual-error">{{ $errors->first() }}</div>
    @endif

    <div class="manual-shell">
      <section class="manual-card">
        <div class="manual-hero">
          <span class="manual-kicker">{{ $paymentMethodMeta['label'] }}</span>
          <div>
            <h1>{{ __('ui.billing.manual_page_title') }}</h1>
            <p>{{ __('ui.billing.manual_page_subtitle') }}</p>
          </div>
        </div>

        <div class="manual-stack">
          <div class="manual-amount">
            <small>{{ __('ui.billing.manual_exact_amount') }}</small>
            <strong>{{ $quote['amount_formatted'] }}</strong>
          </div>

          <div class="manual-grid">
            <dl class="manual-detail">
              <dt>{{ __('ui.billing.plan') }}</dt>
              <dd>{{ $planName }}</dd>
            </dl>
            <dl class="manual-detail">
              <dt>{{ __('ui.billing.billing_cycle') }}</dt>
              <dd>{{ $billingCycle === 'one_time' ? __('ui.pack.one_time_payment') : ($billingCycle === 'yearly' ? __('ui.billing.yearly_save_20') : __('ui.billing.monthly')) }}</dd>
            </dl>
            <dl class="manual-detail">
              <dt>{{ __('ui.billing.manual_payment_method') }}</dt>
              <dd>{{ $paymentMethodMeta['label'] }}</dd>
            </dl>
            <dl class="manual-detail">
              <dt>{{ __('ui.billing.manual_market') }}</dt>
              <dd>{{ $countryContext['country_code'] }} / {{ $quote['currency'] }}</dd>
            </dl>
          </div>

          <div class="manual-detail">
            <dt>{{ __('ui.billing.manual_send_to') }}</dt>
            <dd class="manual-destination">
              @foreach($destination['lines'] as $line)
                <div class="destination-line">
                  <span class="destination-value">{{ $line }}</span>
                  <button type="button" class="copy-btn" data-copy="{{ $line }}">{{ __('ui.billing.copy') }}</button>
                </div>
              @endforeach
            </dd>
          </div>

          <div class="manual-note">
            {{ $destination['instructions'] }}
          </div>

          <div class="manual-steps">
            <div class="manual-step">
              <strong>{{ __('ui.billing.manual_step_amount_title') }}</strong>
              <p>{{ __('ui.billing.manual_step_amount_body', ['amount' => $quote['amount_formatted']]) }}</p>
            </div>
            <div class="manual-step">
              <strong>{{ __('ui.billing.manual_step_destination_title') }}</strong>
              <p>{{ __('ui.billing.manual_step_destination_body') }}</p>
            </div>
            <div class="manual-step">
              <strong>{{ __('ui.billing.manual_step_confirm_title') }}</strong>
              <p>{{ __('ui.billing.manual_step_confirm_body') }}</p>
            </div>
          </div>
        </div>
      </section>

      <aside class="manual-card">
        <div class="manual-stack">
          <div>
            <h2>{{ __('ui.billing.manual_confirm_title') }}</h2>
            <p>{{ __('ui.billing.manual_confirm_subtitle') }}</p>
          </div>

          @if($pendingRequest)
            <dl class="manual-status manual-meta">
              <div>
                <dt>{{ __('ui.billing.manual_status_label') }}</dt>
                <dd>{{ __('ui.billing.manual_pending_status') }}</dd>
              </div>
              <div>
                <dt>{{ __('ui.billing.manual_submitted_at') }}</dt>
                <dd>{{ optional($pendingRequest->submitted_at)->translatedFormat('j M Y, g:i A') }}</dd>
              </div>
              <div>
                <dt>{{ __('ui.billing.manual_review_notice_title') }}</dt>
                <dd>{{ __('ui.billing.manual_review_notice_body') }}</dd>
              </div>
            </dl>
          @else
            <form class="manual-form" method="POST" action="{{ route('billing.manual.confirm') }}" enctype="multipart/form-data">
              @csrf
              <input type="hidden" name="plan" value="{{ $plan->slug }}">
              <input type="hidden" name="billing_cycle" value="{{ $billingCycle }}">
              <input type="hidden" name="payment_method" value="{{ $paymentMethod }}">

              <div>
                <label for="proof_image">{{ __('ui.billing.manual_proof_label') }}</label>
                <input id="proof_image" type="file" name="proof_image" accept="image/*">
              </div>

              <div class="manual-note">
                {{ __('ui.billing.manual_review_notice_body') }}
              </div>

              <div class="manual-actions">
                <button class="btn btn-primary" type="submit">{{ __('ui.billing.i_completed_manual_payment') }}</button>
                <a class="btn" href="{{ $plan->isPack() ? route('billing.pack') : route('billing.page', ['plan' => $plan->slug]) }}">{{ __('ui.billing.back_to_checkout') }}</a>
              </div>
            </form>
          @endif
        </div>
      </aside>
    </div>
  </main>

  @include('components.footer')
  @include('components.scripts')
  <script>
    document.querySelectorAll('[data-copy]').forEach(function(button) {
      button.addEventListener('click', async function() {
        const value = button.getAttribute('data-copy') || '';
        if (!value) return;

        try {
          await navigator.clipboard.writeText(value);
          button.textContent = @json(__('ui.billing.copied'));
          window.setTimeout(function() {
            button.textContent = @json(__('ui.billing.copy'));
          }, 1400);
        } catch (error) {
          button.textContent = @json(__('ui.billing.copy_failed'));
        }
      });
    });
  </script>
</body>
</html>
