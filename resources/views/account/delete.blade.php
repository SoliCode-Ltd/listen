@include('components.page-head', ['pageTitle' => __('account.delete.title')])
  <meta name="robots" content="noindex, nofollow">
  <style>
    /* "Delete my account", in the account page's own type and spacing (views/app/plan). */
    .acct-del { max-width: 620px; margin: 0 auto; padding: clamp(1.5rem, 5vw, 2.5rem) clamp(1.1rem, 4.5vw, 1.75rem) 4rem; display: grid; gap: 1.25rem; }
    .acct-del h1 { margin: 0; font-family: var(--font-display); font-size: clamp(1.55rem, 6vw, 1.9rem); font-weight: 700; line-height: 1.3; }
    html[dir="rtl"] .acct-del h1 { line-height: 1.5; }
    .acct-del-lead { margin: 0; color: var(--text); font-size: 1.05rem; line-height: 1.7; }
    .acct-del-who { margin: 0; color: var(--muted); font-size: .98rem; line-height: 1.6; overflow-wrap: anywhere; }
    .acct-del-card { background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: clamp(1.1rem, 4.5vw, 1.5rem); }
    .acct-del-card h2 { margin: 0 0 .7rem; font-family: var(--font-display); font-size: 1.1rem; font-weight: 700; line-height: 1.5; }
    .acct-del-card ul { margin: 0; padding-inline-start: 1.2rem; display: grid; gap: .45rem; }
    .acct-del-card li, .acct-del-card p { margin: 0; color: var(--text); font-size: 1rem; line-height: 1.7; }
    .acct-del-card p.is-muted { color: var(--muted); }
    .acct-del-note { border-color: color-mix(in oklab, #E5484D 45%, var(--line)); }
    .acct-del-note p { font-weight: 600; }
    .acct-del-error { margin: 0; padding: .9rem 1.1rem; border-radius: 14px; border: 1px solid #E5484D; color: #E5484D; font-size: 1rem; line-height: 1.7; font-weight: 600; }
    .acct-del-check { display: flex; align-items: flex-start; gap: .8rem; font-size: 1rem; line-height: 1.7; cursor: pointer; }
    .acct-del-check input { flex: none; width: 22px; height: 22px; margin-top: .25rem; accent-color: #E5484D; }
    .acct-del-actions { display: grid; gap: .7rem; }
    .acct-del-actions .btn { width: 100%; justify-content: center; min-height: 54px; font-size: 1.05rem; border-radius: 14px; }
    .acct-del-go { background: #E5484D; border-color: #E5484D; color: #fff; }
    .acct-del-go[disabled] { opacity: .5; }
  </style>
</head>
<body>
  @include('components.header')

  <main class="main-content acct-del">
    <h1>{{ __('account.delete.title') }}</h1>
    <p class="acct-del-lead">{{ __('account.delete.lead') }}</p>
    <p class="acct-del-who">{{ __('account.delete.signed_in_as') }} <bdi dir="ltr">{{ $member->displayContact() }}</bdi></p>

    @error('delete')
      <p class="acct-del-error" role="alert">{{ $message }}</p>
    @enderror

    <section class="acct-del-card" aria-labelledby="acctDelGone">
      <h2 id="acctDelGone">{{ __('account.delete.gone_heading') }}</h2>
      <ul>
        @foreach((array) __('account.delete.gone') as $line)
          <li>{{ $line }}</li>
        @endforeach
      </ul>
    </section>

    <section class="acct-del-card" aria-labelledby="acctDelKept">
      <h2 id="acctDelKept">{{ __('account.delete.kept_heading') }}</h2>
      <p class="is-muted">{{ __('account.delete.kept') }}</p>
    </section>

    @if($billing)
      <section class="acct-del-card acct-del-note">
        <p>{{ __('account.delete.billing_'.$billing) }}</p>
      </section>
    @endif

    @if($refusal)
      <p class="acct-del-error" role="alert">{{ $refusal }}</p>
      <div class="acct-del-actions">
        <a class="btn" href="{{ $backUrl }}" data-in-app-plan>{{ __('account.delete.cancel') }}</a>
      </div>
    @else
      <form method="post" action="{{ route('account.delete.confirm') }}" class="acct-del-actions" id="acctDelForm">
        @csrf
        <label class="acct-del-check">
          <input type="checkbox" name="confirm" value="1" id="acctDelConfirm" required>
          <span>{{ __('account.delete.confirm') }}</span>
        </label>
        @error('confirm')
          <p class="acct-del-error" role="alert">{{ $message }}</p>
        @enderror
        <button type="submit" class="btn acct-del-go" id="acctDelGo" disabled>{{ __('account.delete.button') }}</button>
        <a class="btn" href="{{ $backUrl }}" data-in-app-plan>{{ __('account.delete.cancel') }}</a>
      </form>
    @endif
  </main>

  @include('components.scripts', ['noSupportChat' => true])
  <script>
    // The button wakes only once the box is ticked, and cannot be pressed twice.
    (function () {
      var box = document.getElementById('acctDelConfirm'), go = document.getElementById('acctDelGo'), form = document.getElementById('acctDelForm');
      if (!box || !go || !form) return;
      var sync = function () { go.disabled = !box.checked; };
      box.addEventListener('change', sync);
      sync();
      form.addEventListener('submit', function () { setTimeout(function () { go.disabled = true; }, 0); });
    })();
  </script>
</body>
</html>
