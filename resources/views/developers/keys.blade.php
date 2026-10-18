@include('components.page-head', ['pageTitle' => __('developers.keys.page_title'), 'page' => 'developers_keys'])
@include('developers._styles')
  <style>
    .keys-grid { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 1.25rem; align-items: start; }
    .keys-grid .dev-card + .dev-card { margin-top: 0; }
    .keys-stat { font-size: 1.9rem; font-weight: 600; color: var(--text); line-height: 1.2; }
    .keys-meter { height: 8px; border-radius: 99px; background: color-mix(in oklab, var(--text) 10%, transparent); overflow: hidden; margin: .6rem 0 .5rem; }
    .keys-meter span { display: block; height: 100%; background: var(--accent-text); border-radius: inherit; }
    .keys-row { display: flex; flex-wrap: wrap; gap: 1.5rem; margin-top: 1rem; }
    .keys-row div { min-width: 120px; }
    .keys-row strong { display: block; color: var(--text); font-size: 1.1rem; }
    .keys-form label { display: block; font-weight: 600; font-size: .9rem; margin: 0 0 .4rem; color: var(--text); }
    .keys-form .field { margin-bottom: 1rem; }
    .keys-form input[type="text"], .keys-form input[type="number"] {
      width: 100%; padding: .7rem .85rem; border-radius: 12px; border: 1px solid var(--line);
      background: var(--bg, transparent); color: var(--text); font: inherit;
    }
    .keys-scopes { display: grid; gap: .45rem; }
    .keys-scopes label { display: flex; gap: .55rem; align-items: center; font-weight: 500; color: var(--muted); margin: 0; }
    .keys-help { font-size: .82rem; color: var(--muted); margin-top: .35rem; }
    .keys-new { border-color: color-mix(in oklab, var(--ok) 45%, var(--line)); }
    .keys-new-value { display: flex; gap: .5rem; align-items: center; margin-top: .75rem; direction: ltr; }
    .keys-new-value input { flex: 1; min-width: 0; padding: .7rem .85rem; border-radius: 12px; border: 1px solid var(--line); background: transparent; color: var(--text); font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .88rem; }
    .keys-actions { display: flex; gap: .4rem; flex-wrap: wrap; }
    .keys-actions form { margin: 0; }
    .btn-small { padding: .4rem .75rem; font-size: .82rem; }
    .btn-danger-text { color: var(--danger); }
    .keys-edit { margin-top: .6rem; }
    .keys-edit summary { cursor: pointer; color: var(--muted); font-size: .85rem; }
    .keys-edit form { display: grid; gap: .5rem; margin-top: .5rem; max-width: 360px; }
    .keys-revoked td { opacity: .65; }
    .keys-mask { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .85rem; direction: ltr; unicode-bidi: isolate; color: var(--text); }
    @media (max-width: 900px) { .keys-grid { grid-template-columns: minmax(0, 1fr); } }
  </style>
</head>
<body>
  @include('components.header')

  @php
    $k = fn (string $key, array $replace = []) => __('developers.keys.'.$key, $replace);
    $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->locale(app()->getLocale())->translatedFormat('j M Y') : null;
    $isPack = ($usage['mode'] ?? 'monthly') === 'pack';
    $percent = (int) ($usage['chars_percent'] ?? 0);
  @endphp

  <main class="container dev-page">
    <section class="dev-hero">
      <span class="dev-kicker">{{ __('developers.docs.kicker') }}</span>
      <h1>{{ $k('title') }}</h1>
      <p>{{ $k('subtitle') }}</p>
      <div class="dev-actions">
        <a class="btn" href="{{ route('developers') }}">{{ $k('docs_link') }}</a>
      </div>
    </section>

    @if(session('ok'))
      <div class="alert alert-success" role="status">{{ session('ok') }}</div>
    @endif
    @if(session('error'))
      <div class="alert alert-error" role="alert">{{ session('error') }}</div>
    @endif
    @if($errors->any())
      <div class="alert alert-error" role="alert">{{ $errors->first() }}</div>
    @endif

    @if($newKey)
      <section class="dev-card keys-new" aria-live="polite">
        <h2>{{ $k('new_key_title') }}</h2>
        <p>{{ $k('new_key_body') }}</p>
        <div class="keys-new-value">
          <input type="text" id="newApiKey" value="{{ $newKey }}" readonly onclick="this.select()" aria-label="{{ $k('new_key_title') }}">
          <button type="button" class="btn btn-primary" data-copy data-copy-target="newApiKey" data-copied-label="{{ $k('copied') }}">{{ $k('copy') }}</button>
        </div>
      </section>
    @endif

    @unless($apiEnabled)
      <section class="dev-card">
        <h2>{{ $k('not_in_plan_title') }}</h2>
        <p>{{ $k('not_in_plan_body', ['plan' => $planName]) }}</p>
        <a class="btn btn-primary" href="{{ route('pricing') }}">{{ $k('upgrade_cta') }}</a>
      </section>
    @endunless

    <div class="keys-grid" style="margin-top: 1.25rem;">
      <section class="dev-card">
        <h2>{{ $k('usage_title') }}</h2>
        <div class="dev-muted">{{ $k('usage_credits') }}</div>
        <div class="keys-stat">{{ number_format((int) $usage['total_chars']) }} <span class="dev-muted">{{ $k('usage_of', ['limit' => number_format((int) $usage['plan_chars'])]) }}</span></div>
        <div class="keys-meter" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ max(0, min(100, $percent)) }}%"></span></div>
        <div class="dev-muted">
          {{ $k('usage_remaining', ['count' => number_format((int) $usage['remaining_chars'])]) }} ·
          @if($isPack)
            {{ $usage['pack_expires_at'] ? $k('usage_pack_expires', ['date' => $date($usage['pack_expires_at'])]) : '' }}
          @else
            {{ $k('usage_resets', ['date' => $date($resetsAt)]) }}
          @endif
        </div>
        <div class="keys-row">
          <div><span class="dev-muted">{{ $k('usage_api_requests') }}</span><strong>{{ number_format($apiRequests) }}</strong></div>
          <div><span class="dev-muted">{{ $k('usage_api_credits') }}</span><strong>{{ number_format($apiCredits) }}</strong></div>
        </div>
        <p class="dev-muted" style="margin-top: 1rem;">{{ $k('usage_hard_cap') }}</p>
        @if($apiEnabled)
          <h3>{{ $k('limits_title') }}</h3>
          <ul>
            <li>{{ $k('limit_rpm', ['count' => number_format($limits['rpm'])]) }}</li>
            <li>{{ $k('limit_concurrency', ['count' => number_format($limits['concurrency'])]) }}</li>
            <li>{{ $k('limit_chars', ['count' => number_format($limits['max_chars'])]) }}</li>
          </ul>
        @endif
      </section>

      <section class="dev-card">
        <h2>{{ $k('create_title') }}</h2>
        @if($apiEnabled && $activeKeys->count() < $maxKeys)
          <form method="POST" action="{{ route('developers.keys.store') }}" class="keys-form">
            @csrf
            <div class="field">
              <label for="keyName">{{ $k('name_label') }}</label>
              <input type="text" id="keyName" name="name" maxlength="80" required value="{{ old('name') }}" placeholder="{{ $k('name_placeholder') }}">
            </div>
            <div class="field">
              <label>{{ $k('permissions_label') }}</label>
              <div class="keys-scopes">
                @foreach($scopes as $scope)
                  <label><input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(old('scopes') === null || in_array($scope, (array) old('scopes'), true))> {{ __('developers.scopes.'.$scope) }} <code>{{ $scope }}</code></label>
                @endforeach
              </div>
            </div>
            <div class="field">
              <label for="keyLimit">{{ $k('limit_label') }}</label>
              <input type="number" id="keyLimit" name="monthly_credit_limit" min="1" step="1" value="{{ old('monthly_credit_limit') }}" placeholder="{{ $k('limit_placeholder') }}" dir="ltr">
              <div class="keys-help">{{ $k('limit_help') }}</div>
            </div>
            <button type="submit" class="btn btn-primary">{{ $k('create_button') }}</button>
          </form>
        @elseif($apiEnabled)
          <p>{{ $k('max_keys', ['count' => $maxKeys]) }}</p>
        @else
          <p>{{ $k('not_in_plan_body', ['plan' => $planName]) }}</p>
        @endif
        <div class="dev-note warn" style="margin-top: 1.25rem;"><strong>{{ $k('security_title') }}.</strong> {{ $k('security_body') }}</div>
      </section>
    </div>

    <section class="dev-card" style="margin-top: 1.25rem;">
      <h2>{{ $k('list_title') }}</h2>
      @if($activeKeys->isEmpty())
        <p>{{ $k('empty') }}</p>
      @else
        <div class="dev-table-wrap">
          <table class="dev-table">
            <thead><tr>
              <th>{{ $k('col_name') }}</th><th>{{ $k('col_key') }}</th><th>{{ $k('col_permissions') }}</th>
              <th>{{ $k('col_month') }}</th><th>{{ $k('col_last_used') }}</th><th>{{ $k('col_created') }}</th><th></th>
            </tr></thead>
            <tbody>
              @foreach($activeKeys as $apiKey)
                @php($used = (int) ($monthUsage->get($apiKey->id)?->credits ?? 0))
                @php($requests = (int) ($monthUsage->get($apiKey->id)?->requests ?? 0))
                <tr>
                  <td>
                    {{ $apiKey->name }}
                    <details class="keys-edit">
                      <summary>{{ $k('edit') }}</summary>
                      <form method="POST" action="{{ route('developers.keys.update', $apiKey) }}" class="keys-form">
                        @csrf
                        @method('PUT')
                        <label for="name-{{ $apiKey->id }}">{{ $k('name_label') }}</label>
                        <input type="text" id="name-{{ $apiKey->id }}" name="name" maxlength="80" required value="{{ $apiKey->name }}">
                        <label for="limit-{{ $apiKey->id }}">{{ $k('limit_label') }}</label>
                        <input type="number" id="limit-{{ $apiKey->id }}" name="monthly_credit_limit" min="1" step="1" value="{{ $apiKey->monthly_credit_limit }}" placeholder="{{ $k('limit_placeholder') }}" dir="ltr">
                        <button type="submit" class="btn btn-small">{{ $k('save') }}</button>
                      </form>
                    </details>
                  </td>
                  <td><span class="keys-mask">{{ $apiKey->maskedKey() }}</span></td>
                  <td>
                    @if($apiKey->scopes === null)
                      {{ $k('all_permissions') }}
                    @else
                      @foreach($apiKey->scopes as $scope)<code>{{ $scope }}</code> @endforeach
                    @endif
                  </td>
                  <td>
                    {{ $apiKey->monthly_credit_limit ? $k('credits_of_limit', ['used' => number_format($used), 'limit' => number_format($apiKey->monthly_credit_limit)]) : $k('credits_used', ['used' => number_format($used)]) }}
                    <div class="dev-muted">{{ $k('requests_count', ['count' => number_format($requests)]) }}{{ $apiKey->monthly_credit_limit ? '' : ' · '.$k('no_limit') }}</div>
                  </td>
                  <td>{{ $apiKey->last_used_at ? $apiKey->last_used_at->locale(app()->getLocale())->diffForHumans() : $k('never') }}</td>
                  <td>{{ $date($apiKey->created_at) }}</td>
                  <td>
                    <div class="keys-actions">
                      <form method="POST" action="{{ route('developers.keys.revoke', $apiKey) }}" onsubmit="return confirm(@js($k('revoke_confirm')));">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-small btn-danger-text">{{ $k('revoke') }}</button>
                      </form>
                    </div>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif

      @if($revokedKeys->isNotEmpty())
        <h3>{{ $k('revoked_title') }}</h3>
        <div class="dev-table-wrap">
          <table class="dev-table">
            <tbody>
              @foreach($revokedKeys as $apiKey)
                <tr class="keys-revoked">
                  <td>{{ $apiKey->name }}</td>
                  <td><span class="keys-mask">{{ $apiKey->maskedKey() }}</span></td>
                  <td>{{ $k('revoked_on', ['date' => $date($apiKey->revoked_at)]) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </section>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @include('developers._script')
</body>
</html>
