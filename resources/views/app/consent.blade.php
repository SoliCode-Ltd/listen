@include('components.page-head', ['pageTitle' => __('app.consent.title')])
  <meta name="robots" content="noindex, nofollow">
  <style>
    /* The iPhone app's question before a first voice, in the account page's type and spacing (views/app/plan). */
    .ai-ask { max-width: 620px; margin: 0 auto; padding: clamp(1.5rem, 5vw, 2.5rem) clamp(1.1rem, 4.5vw, 1.75rem) 4rem; display: grid; gap: 1.25rem; }
    .ai-ask h1 { margin: 0; font-family: var(--font-display); font-size: clamp(1.55rem, 6vw, 1.9rem); font-weight: 700; line-height: 1.3; }
    html[dir="rtl"] .ai-ask h1 { line-height: 1.5; }
    .ai-ask-lead { margin: 0; font-size: 1.05rem; line-height: 1.7; }
    .ai-ask-card { background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: clamp(1.1rem, 4.5vw, 1.5rem); display: grid; gap: 1.1rem; }
    .ai-ask-card h2 { margin: 0 0 .45rem; font-family: var(--font-display); font-size: 1.05rem; font-weight: 700; line-height: 1.5; }
    .ai-ask-card ul { margin: 0; padding-inline-start: 1.2rem; display: grid; gap: .4rem; }
    .ai-ask-card li, .ai-ask-card p { margin: 0; font-size: 1rem; line-height: 1.7; }
    .ai-ask-more { color: var(--brand-accent); font-weight: 600; text-decoration: none; }
    html:not([data-theme="light"]) .ai-ask-more { color: var(--brand-subtle, #A99CFF); }
    .ai-ask-note { margin: 0; color: var(--muted); font-size: .98rem; line-height: 1.7; }
    .ai-ask-actions { display: grid; gap: .7rem; }
    .ai-ask-actions form { margin: 0; }
    .ai-ask-actions .btn { width: 100%; justify-content: center; min-height: 54px; font-size: 1.05rem; border-radius: 14px; }
    /* The question stays in view while the page is read, above the app's tabs, with both answers
       side by side (one above the other where the screen is too narrow for that). */
    .ai-ask-actions.is-question { position: sticky; bottom: 0; z-index: 5; display: flex; flex-wrap: wrap-reverse; gap: .6rem; margin: 0 calc(-1 * clamp(1.1rem, 4.5vw, 1.75rem)); padding: .8rem clamp(1.1rem, 4.5vw, 1.75rem); background: var(--bg); border-top: 1px solid var(--line); }
    html:has(.app-tabs) .ai-ask-actions.is-question { bottom: calc(62px + env(safe-area-inset-bottom, 0px)); }
    .ai-ask-actions.is-question form { flex: 1 1 9.5rem; }
    .ai-ask-actions.is-question > .btn { order: -1; flex: 0 1 auto; width: auto; padding-inline: 1.3rem; }
    @media (max-width: 359px) {
      .ai-ask-actions.is-question .btn { font-size: .95rem; padding-inline: .7rem; min-height: 50px; }
    }
  </style>
</head>
<body>
  @include('components.header')

  <main class="main-content ai-ask">
    <h1>{{ __('app.consent.title') }}</h1>
    <p class="ai-ask-lead">{{ __('app.consent.lead') }}</p>

    <section class="ai-ask-card">
      <div>
        <h2>{{ __('app.consent.what_heading') }}</h2>
        <ul>
          @foreach((array) __('app.consent.what') as $line)
            <li>{{ $line }}</li>
          @endforeach
        </ul>
      </div>
      <div>
        <h2>{{ __('app.consent.who_heading') }}</h2>
        <p>{{ __('app.consent.who') }}</p>
      </div>
      <div>
        <h2>{{ __('app.consent.why_heading') }}</h2>
        <p>{{ __('app.consent.why') }}</p>
      </div>
      <a class="ai-ask-more" href="{{ route('legal.privacy') }}">{{ __('app.consent.more') }}</a>
    </section>

    @if($agreedOn)
      <p class="ai-ask-note">{{ __('app.consent.agreed_on', ['date' => $agreedOn]) }} {{ __('app.consent.withdraw_note') }}</p>
      <div class="ai-ask-actions">
        <a class="btn btn-primary" href="{{ route('app.plan') }}" data-in-app-plan>{{ __('app.consent.back') }}</a>
        <form method="post" action="{{ route('app.consent.withdraw') }}">
          @csrf
          <button type="submit" class="btn">{{ __('app.consent.withdraw') }}</button>
        </form>
      </div>
    @else
      <div class="ai-ask-actions is-question">
        <form method="post" action="{{ route('app.consent.agree') }}">
          @csrf
          <button type="submit" class="btn btn-primary">{{ __('app.consent.agree') }}</button>
        </form>
        <a class="btn" href="{{ route('app.plan') }}" data-in-app-plan>{{ __('app.consent.not_now') }}</a>
      </div>
    @endif
  </main>

  @include('components.scripts', ['noSupportChat' => true])
</body>
</html>
