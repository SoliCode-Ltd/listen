{{--
  Dashboard: the Developer API section, built like the command center: one
  gauge for this month's API credits, the plan's limits as tiles, the
  options the plan includes, then the keys. Members whose plan has no API
  see the plans that include it.
--}}
@php
  $d = fn (string $key, array $replace = []) => __('developers.dashboard.'.$key, $replace);
  $k = fn (string $key, array $replace = []) => __('developers.keys.'.$key, $replace);
  $api = $developerApi;
  $status = $api['status'];
  $isOk = $status === \App\Support\Api\ApiPlanAccess::OK;
  $locale = app()->getLocale();
  $date = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->locale($locale)->translatedFormat('j F Y') : '';
  $usageRow = $api['usage'];
  $planCredits = max(0, (int) $usageRow['plan_chars']);
  $usedCredits = (int) $usageRow['total_chars'];
  $apiCredits = (int) $api['api_credits'];
  $appCredits = $api['app_credits'] ?? max(0, $usedCredits - $apiCredits);
  $leftCredits = max(0, (int) $usageRow['remaining_chars']);
  $share = $planCredits > 0 ? fn (int $part) => max(0, min(100, $part / $planCredits * 100)) : fn (int $part) => 0;
  $ring = (int) $api['api_share_percent'];
  // Flashed encrypted by DeveloperController::store, for this page only.
  try {
    $newKey = session()->has('new_api_key_plain') ? decrypt(session('new_api_key_plain')) : null;
  } catch (\Throwable) {
    $newKey = null;
  }
  $keyErrors = $errors->getBag('apiKeys');
  $activeKeys = $api['active_keys'];
  $maxKeys = \App\Models\ApiKey::MAX_ACTIVE_PER_USER;
  $scopes = \App\Support\Api\ApiScopes::all();
  $openCreate = $isOk && ($activeKeys->isEmpty() || $keyErrors->any() || old('scopes') !== null);
@endphp
<style>
  #developerApiSection .dapi-ring .usage-ring-arc { stroke: url(#dapiRingGradient); }
  #developerApiSection .usage-hero { grid-template-columns: auto minmax(0, 1fr) auto; gap: 1.6rem; }
  .dapi-split { display: flex; height: 10px; margin: .2rem 0 .75rem; overflow: hidden; border-radius: 999px; background: color-mix(in oklab, var(--line) 45%, transparent); }
  .dapi-split span { height: 100%; }
  .dapi-split .is-api { background: linear-gradient(90deg, var(--a), var(--b)); }
  .dapi-split .is-app { background: color-mix(in oklab, var(--a) 28%, var(--surface2, var(--surface))); }
  html[dir="rtl"] .dapi-split .is-api { background: linear-gradient(270deg, var(--a), var(--b)); }
  .dapi-legend { display: flex; flex-wrap: wrap; gap: .4rem 1.1rem; font-size: .86rem; color: var(--muted); }
  .dapi-legend span { display: inline-flex; align-items: center; gap: .4rem; }
  .dapi-legend b { color: var(--text); font-variant-numeric: tabular-nums; }
  .dapi-legend i { width: 9px; height: 9px; border-radius: 3px; display: inline-block; }
  .dapi-legend .is-api { background: var(--a); }
  .dapi-legend .is-app { background: color-mix(in oklab, var(--a) 28%, var(--surface2, var(--surface))); }
  .dapi-legend .is-left { background: color-mix(in oklab, var(--line) 80%, transparent); }
  .dapi-formats code { margin-inline-end: .25rem; }
  .dapi-mono, .dapi code { font-family: ui-monospace, "SF Mono", SFMono-Regular, Menlo, Consolas, monospace; direction: ltr; unicode-bidi: isolate; }
  .dapi-features .usage-health-row { flex-wrap: wrap; gap: .55rem 1.25rem; }
  .dapi-features .usage-health-item { gap: .5rem; white-space: nowrap; }
  .dapi-features .dot.off { background: color-mix(in oklab, var(--muted) 45%, transparent); box-shadow: none; }
  .dapi-features .usage-health-item.is-off small { text-decoration: none; opacity: .75; }
  .dapi-features a { font-size: .88rem; font-weight: 600; color: var(--accent-text); text-decoration: none; }
  .dapi-features a:hover { text-decoration: underline; }

  /* Keys */
  .dapi-keys-head { display: flex; align-items: center; justify-content: space-between; gap: .75rem 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
  .dapi-keys-head h2 { display: flex; align-items: baseline; gap: .6rem; margin: 0; }
  .dapi-keys-head h2 small { font-size: .9rem; font-weight: 600; color: var(--muted); font-variant-numeric: tabular-nums; }
  .dapi-btn { display: inline-flex; align-items: center; justify-content: center; gap: .45rem; min-height: 42px; padding: 0 1.05rem; border-radius: var(--btn-radius, 12px); border: 1px solid var(--line); background: var(--surface); color: var(--text); font: inherit; font-weight: 650; font-size: .92rem; text-decoration: none; cursor: pointer; }
  .dapi-btn:hover { border-color: color-mix(in oklab, var(--a) 35%, var(--line)); }
  .dapi-btn.primary { background: var(--brand-accent); border-color: transparent; color: #fff; }
  .dapi-btn.primary:hover { background: var(--brand-hover); }
  .dapi-btn.small { min-height: 34px; padding: 0 .75rem; font-size: .85rem; }
  .dapi-btn.danger { color: var(--danger); }
  .dapi-btn:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
  .dapi-btn svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

  .dapi-panel { padding: 1.15rem 1.2rem; border-radius: 16px; border: 1px solid color-mix(in oklab, var(--line) 85%, transparent); background: var(--surface); }
  .dapi-panel + .dapi-panel, .dapi-panel + .dapi-list, .dapi-list + .dapi-panel { margin-top: .9rem; }
  .dapi-new { border-color: color-mix(in oklab, var(--ok) 50%, var(--line)); background: color-mix(in oklab, var(--ok) 6%, var(--surface)); }
  .dapi-new h3, .dapi-form h3 { margin: 0 0 .35rem; font-size: 1.02rem; }
  .dapi-new p { margin: 0 0 .8rem; color: var(--muted); font-size: .92rem; }
  .dapi-secret { display: flex; gap: .5rem; direction: ltr; }
  .dapi-secret input { flex: 1; min-width: 0; height: 42px; padding: 0 .8rem; border-radius: 10px; border: 1px solid var(--line); background: var(--surface); color: var(--text); font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; font-size: .86rem; }

  .dapi-form[hidden] { display: none; }
  .dapi-form-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1rem 1.25rem; margin-top: .8rem; }
  #developerApiSection .dapi-field label, #developerApiSection .dapi-field legend { display: block; width: 100%; margin: 0 0 .45rem; padding: 0; font-size: .88rem; font-weight: 650; color: var(--text); }
  .dapi-field fieldset { margin: 0; padding: 0; border: 0; min-width: 0; }
  .dapi-field input[type="text"], .dapi-field input[type="number"] { width: 100%; height: 42px; margin: 0; padding: 0 .8rem; border-radius: 10px; border: 1px solid color-mix(in oklab, var(--line) 90%, transparent); background: var(--surface); color: var(--text); font: inherit; font-size: .92rem; box-shadow: none; }
  .dapi-field input:focus { outline: none; border-color: var(--a); box-shadow: 0 0 0 3px color-mix(in oklab, var(--a) 18%, transparent); }
  .dapi-field small { display: block; margin-top: .35rem; color: var(--muted); font-size: .82rem; line-height: 1.5; }
  .dapi-scopes { grid-column: 1 / -1; }
  .dapi-scope-list { display: flex; flex-wrap: wrap; gap: .45rem; }
  /* The dashboard styles every label and input for its forms; the chips set their own. */
  #developerApiSection .dapi-scope-list label.dapi-scope { position: relative; display: inline-flex; align-items: center; gap: .5rem; width: auto; min-height: 38px; margin: 0; padding: 0 .85rem; border-radius: 999px; border: 1px solid var(--line); background: var(--surface); font-size: .87rem; font-weight: 500; line-height: 1.2; color: var(--muted); cursor: pointer; user-select: none; white-space: nowrap; }
  #developerApiSection .dapi-scope input[type="checkbox"] { flex: none; width: 16px; height: 16px; min-width: 16px; margin: 0; padding: 0; border: 0; border-radius: 4px; background: none; box-shadow: none; transition: none; accent-color: var(--brand-accent); cursor: pointer; }
  #developerApiSection .dapi-scope-list label.dapi-scope:has(input:checked) { color: var(--text); border-color: color-mix(in oklab, var(--a) 45%, var(--line)); background: color-mix(in oklab, var(--a) 7%, var(--surface)); }
  #developerApiSection .dapi-scope-list label.dapi-scope:has(input:focus-visible) { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
  .dapi-form-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1.1rem; }

  .dapi-list { display: grid; gap: 0; border-radius: 16px; border: 1px solid color-mix(in oklab, var(--line) 85%, transparent); background: var(--surface); overflow: hidden; }
  .dapi-key { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr) auto; gap: .8rem 1.25rem; align-items: center; padding: 1rem 1.15rem; border-bottom: 1px solid color-mix(in oklab, var(--line) 70%, transparent); }
  .dapi-key:last-child { border-bottom: 0; }
  .dapi-key-name { display: block; font-weight: 700; color: var(--text); overflow-wrap: anywhere; }
  .dapi-key-mask { display: inline-block; margin-top: .2rem; font-size: .84rem; color: var(--muted); }
  .dapi-key-scopes { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .45rem; }
  .dapi-key-scopes span { padding: .1rem .5rem; border-radius: 999px; background: color-mix(in oklab, var(--surface2, var(--surface)) 80%, var(--surface)); border: 1px solid color-mix(in oklab, var(--line) 80%, transparent); font-size: .76rem; color: var(--muted); }
  .dapi-key-usage { display: grid; gap: .2rem; font-size: .88rem; line-height: 1.45; color: var(--muted); font-variant-numeric: tabular-nums; }
  .dapi-key-usage b { color: var(--text); }
  .dapi-key-usage .meter-track { height: 6px; margin: .15rem 0; }
  .dapi-key-actions { display: flex; gap: .4rem; justify-content: flex-end; }
  .dapi-key-actions form { margin: 0; }
  .dapi-key-edit { grid-column: 1 / -1; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; gap: .75rem; align-items: end; padding-top: .3rem; }
  .dapi-key-edit[hidden] { display: none; }
  .dapi-empty { padding: 1.4rem 1.2rem; color: var(--muted); font-size: .94rem; text-align: center; }
  .dapi-revoked { margin-top: .9rem; }
  .dapi-revoked summary { cursor: pointer; color: var(--muted); font-size: .9rem; font-weight: 600; }
  .dapi-revoked ul { list-style: none; margin: .5rem 0 0; padding: 0; }
  .dapi-revoked li { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; padding: .5rem 0; border-bottom: 1px dashed color-mix(in oklab, var(--line) 70%, transparent); color: var(--muted); font-size: .88rem; }
  .dapi-apps-intro { margin: -.4rem 0 1rem; color: var(--muted); font-size: .92rem; line-height: 1.55; }
  .dapi-app { display: flex; align-items: center; gap: .75rem; min-width: 0; }
  .dapi-app > div { min-width: 0; }
  .dapi-app-mark { flex: none; display: grid; place-items: center; width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--line); background: color-mix(in oklab, var(--a) 8%, var(--surface)); color: var(--text); font-weight: 700; }
  .dapi-security { display: flex; gap: .65rem; align-items: flex-start; margin-top: .9rem; color: var(--muted); font-size: .88rem; line-height: 1.55; }
  .dapi-security svg { flex: none; width: 18px; height: 18px; margin-top: .1rem; fill: none; stroke: var(--a); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

  /* Not on this plan: what to do next. */
  .dapi-locked { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 1.1rem 1.4rem; align-items: center; margin-top: 1.2rem; padding: 1.4rem 1.5rem; border-radius: 20px; border: 1px solid color-mix(in oklab, var(--a, #6A45F2) 22%, var(--line)); background: var(--surface); }
  .dapi-locked-icon { display: grid; place-items: center; width: 56px; height: 56px; border-radius: 16px; background: color-mix(in oklab, var(--a) 12%, var(--surface)); }
  .dapi-locked-icon svg { width: 26px; height: 26px; fill: none; stroke: var(--a); stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
  .dapi-locked h2 { margin: 0 0 .3rem; font-size: 1.12rem; }
  .dapi-locked p { margin: 0; color: var(--muted); font-size: .94rem; line-height: 1.6; }
  .dapi-locked .dapi-form-actions { grid-column: 1 / -1; margin-top: 0; }

  @media (max-width: 900px) {
    .dapi-key { grid-template-columns: minmax(0, 1fr) auto; }
    .dapi-key-usage { grid-column: 1 / -1; grid-row: 2; }
  }
  @media (max-width: 760px) {
    #developerApiSection .usage-hero { grid-template-columns: auto minmax(0, 1fr); gap: 1.1rem 1.25rem; }
    #developerApiSection .usage-hero-cta { grid-column: 1 / -1; }
    .dapi-form-grid, .dapi-key-edit { grid-template-columns: minmax(0, 1fr); }
  }
  @media (max-width: 420px) {
    #developerApiSection .usage-hero { grid-template-columns: 1fr; justify-items: center; text-align: center; }
    #developerApiSection .usage-hero-main { width: 100%; }
    #developerApiSection .usage-hero-head, #developerApiSection .dapi-legend { justify-content: center; }
    #developerApiSection .usage-hero-cta { width: 100%; }
    .dapi-key { grid-template-columns: minmax(0, 1fr); }
    .dapi-key-actions { justify-content: flex-start; }
    .dapi-locked { grid-template-columns: minmax(0, 1fr); }
  }
</style>

<section id="developerApiSection" class="dashboard-panel" data-dashboard-section>
  <div class="hero-grid">
    <article class="page-card reveal command-card dapi">
      <h1>
        <span class="grad-text">{{ $d('title') }}</span>
        <span style="display:block; font-size:1rem; color:var(--muted); margin-top:.3rem; font-weight:500; letter-spacing:0;">{{ $d('subtitle') }}</span>
      </h1>

      @if(session('api_ok'))<div class="note" role="status">{{ session('api_ok') }}</div>@endif
      @if(session('api_error'))<div class="error" role="alert">{{ session('api_error') }}</div>@endif

      @if($isOk)
        {{-- One gauge: the API's share of this month's credits, beside the split of the whole balance. --}}
        <div class="usage-hero">
          <div class="usage-ring dapi-ring {{ $ring >= 95 ? 'is-low' : ($ring >= 80 ? 'is-high' : '') }}" style="--p: {{ $ring }}" role="img" aria-label="{{ $d('ring_label', ['percent' => $ring]) }}">
            <svg viewBox="0 0 120 120" aria-hidden="true">
              <defs>
                <linearGradient id="dapiRingGradient" x1="0" y1="0" x2="1" y2="1">
                  <stop offset="0" stop-color="#A28FFF"/>
                  <stop offset="1" stop-color="#5B3FE6"/>
                </linearGradient>
              </defs>
              <circle class="usage-ring-track" cx="60" cy="60" r="52"/>
              <circle class="usage-ring-arc" cx="60" cy="60" r="52" pathLength="100"/>
            </svg>
            <div class="usage-ring-inner">
              <b>{{ $ring }}%</b>
              <span>{{ $d('ring_caption') }}</span>
            </div>
          </div>
          <div class="usage-hero-main">
            <div class="usage-hero-head">
              <span class="usage-hero-label">{{ $d('hero_label') }}</span>
              <span class="usage-hero-plan">{{ $api['plan_name'] }}</span>
            </div>
            <div class="usage-hero-value">
              <strong>{{ number_format($apiCredits) }}</strong>
              <span>{{ $api['is_pack'] ? $d('hero_of_pack', ['total' => number_format($planCredits)]) : $d('hero_of', ['total' => number_format($planCredits)]) }}</span>
            </div>
            <div class="dapi-split" aria-hidden="true">
              <span class="is-api" style="width: {{ $share($apiCredits) }}%"></span>
              <span class="is-app" style="width: {{ $share($appCredits) }}%"></span>
            </div>
            <div class="dapi-legend">
              <span><i class="is-api"></i>{{ $d('split_api') }} <b>{{ number_format($apiCredits) }}</b></span>
              <span><i class="is-app"></i>{{ $d('split_app') }} <b>{{ number_format($appCredits) }}</b></span>
              <span><i class="is-left"></i>{{ $d('split_left') }} <b>{{ number_format($leftCredits) }}</b></span>
            </div>
            <div class="usage-hero-chips">
              <span class="usage-chip">{{ $api['is_pack']
                ? ($usageRow['pack_expires_at'] ? $d('pack_ends', ['date' => $date($usageRow['pack_expires_at'])]) : '')
                : $d(($api['plan_ends'] ?? false) ? 'plan_ends' : 'renews', ['date' => $date($api['resets_at'])]) }}</span>
            </div>
          </div>
          <a class="usage-hero-cta" href="{{ route('developers') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5zM4 20.5A2.5 2.5 0 0 0 6.5 23H20v-5"/></svg>
            {{ $d('docs_cta') }}
          </a>
        </div>

        {{-- The plan's limits, as the API applies them. --}}
        <div class="usage-tiles">
          <div class="usage-tile">
            <div class="usage-tile-head">
              <span class="usage-tile-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h3l2-6 4 12 2-6h5"/></svg></span>
              <small>{{ $d('tile_requests') }}</small>
            </div>
            <b>{{ number_format($api['api_requests']) }}</b>
            <small class="usage-tile-foot">{{ $d('tile_requests_foot', ['count' => number_format($activeKeys->count())]) }}</small>
          </div>
          <div class="usage-tile">
            <div class="usage-tile-head">
              <span class="usage-tile-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5M9 2h6"/></svg></span>
              <small>{{ $d('tile_rpm') }}</small>
            </div>
            <b>{{ number_format($api['limits']['rpm']) }}</b>
            <small class="usage-tile-foot">{{ $d('tile_rpm_foot', ['count' => number_format($api['limits']['concurrency'])]) }}</small>
          </div>
          <div class="usage-tile">
            <div class="usage-tile-head">
              <span class="usage-tile-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7V5h16v2M9 19h6M12 5v14"/></svg></span>
              <small>{{ $d('tile_chars') }}</small>
            </div>
            <b>{{ number_format($api['limits']['max_chars']) }}</b>
            <small class="usage-tile-foot dapi-formats">{{ $d('tile_chars_foot', ['count' => number_format($api['limits']['max_chars_async'])]) }} @foreach($api['limits']['formats'] as $format)<code>{{ $format }}</code>@endforeach</small>
          </div>
        </div>

        {{-- The studio options only some plans include. --}}
        @php
          $missing = in_array(false, $api['features'], true);
        @endphp
        @if($api['features'] !== [])
        <div class="usage-health dapi-features">
          <span class="usage-health-title">{{ $d('features_title') }}</span>
          <div class="usage-health-row">
            @foreach($api['features'] as $feature => $included)
              <div class="usage-health-item {{ $included ? '' : 'is-off' }}">
                <i class="dot {{ $included ? 'done' : 'off' }}" aria-hidden="true"></i>
                <small>{{ $d('features.'.$feature) }}<span class="usage-hero-caption">{{ $included ? $d('feature_included') : $d('feature_missing') }}</span></small>
              </div>
            @endforeach
          </div>
          @if($missing)<a href="{{ route('pricing') }}">{{ $k('upgrade_cta') }}</a>@endif
        </div>
        @endif
        <p class="mini-message" style="color: var(--muted); font-size: .88rem; margin-top: .8rem;">{{ $d('shared_note') }}</p>
      @else
        {{-- No API on this plan yet: what changes that. --}}
        <div class="dapi-locked">
          <span class="dapi-locked-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 9l-4 3 4 3M16 9l4 3-4 3M14 5l-4 14"/></svg></span>
          <div>
            @if($status === \App\Support\Api\ApiPlanAccess::TRIAL)
              <h2>{{ $k('trial_title') }}</h2>
              <p>{{ $k('trial_body') }}</p>
            @else
              <h2>{{ $k('not_in_plan_title') }}</h2>
              <p>{{ $api['api_plan_names'] === []
                ? $k('not_in_plan_body_none', ['plan' => $api['plan_name']])
                : $k('not_in_plan_body', ['plans' => implode($locale === 'ar' ? '، ' : ', ', $api['api_plan_names']), 'plan' => $api['plan_name']]) }}</p>
            @endif
          </div>
          <div class="dapi-form-actions">
            @unless($status === \App\Support\Api\ApiPlanAccess::TRIAL)
              <a class="dapi-btn primary" href="{{ route('pricing') }}">{{ $k('upgrade_cta') }}</a>
            @endunless
            <a class="dapi-btn" href="{{ route('developers') }}">{{ $d('docs_cta') }}</a>
          </div>
        </div>
      @endif
    </article>

    @if($isOk || $activeKeys->isNotEmpty())
      <article class="page-card reveal dapi" id="developerApiKeys">
        <div class="dapi-keys-head">
          <h2>{{ $k('list_title') }} <small>{{ $k('keys_count', ['count' => $activeKeys->count(), 'max' => $maxKeys]) }}</small></h2>
          @if($isOk && $activeKeys->count() < $maxKeys)
            <button type="button" class="dapi-btn primary" data-dapi-toggle="dapiCreate" aria-controls="dapiCreate" aria-expanded="{{ $openCreate ? 'true' : 'false' }}">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
              {{ $k('create_title') }}
            </button>
          @endif
        </div>

        @if($keyErrors->any())<div class="error" role="alert">{{ $keyErrors->first() }}</div>@endif

        @if($newKey)
          <div class="dapi-panel dapi-new" aria-live="polite">
            <h3>{{ $k('new_key_title') }}</h3>
            <p>{{ $k('new_key_body') }}</p>
            <div class="dapi-secret">
              <input type="text" id="dapiNewKey" value="{{ $newKey }}" readonly aria-label="{{ $k('new_key_title') }}" data-dapi-select>
              <button type="button" class="dapi-btn primary" data-dapi-copy="dapiNewKey" data-copied="{{ $k('copied') }}">{{ $k('copy') }}</button>
            </div>
          </div>
        @endif

        @if($isOk && $activeKeys->count() < $maxKeys)
          <form method="POST" action="{{ route('developers.keys.store') }}" class="dapi-panel dapi-form" id="dapiCreate" @unless($openCreate) hidden @endunless>
            @csrf
            <h3>{{ $k('create_title') }}</h3>
            <div class="dapi-form-grid">
              <div class="dapi-field">
                <label for="dapiKeyName">{{ $k('name_label') }}</label>
                <input type="text" id="dapiKeyName" name="name" maxlength="80" required value="{{ old('name') }}" placeholder="{{ $k('name_placeholder') }}">
              </div>
              <div class="dapi-field">
                <label for="dapiKeyLimit">{{ $k('limit_label') }}</label>
                <input type="number" id="dapiKeyLimit" name="monthly_credit_limit" min="1" step="1" inputmode="numeric" value="{{ old('monthly_credit_limit') }}" placeholder="{{ $k('limit_placeholder') }}" dir="ltr">
                <small>{{ $k('limit_help') }}</small>
              </div>
              <div class="dapi-field dapi-scopes">
                <fieldset>
                  <legend>{{ $k('permissions_label') }}</legend>
                  <div class="dapi-scope-list">
                    @foreach($scopes as $scope)
                      <label class="dapi-scope"><input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(old('scopes') === null || in_array($scope, (array) old('scopes'), true))>{{ __('developers.scopes.'.$scope) }}</label>
                    @endforeach
                  </div>
                </fieldset>
              </div>
            </div>
            <div class="dapi-form-actions">
              <button type="submit" class="dapi-btn primary">{{ $k('create_button') }}</button>
              @if($activeKeys->isNotEmpty())
                <button type="button" class="dapi-btn" data-dapi-toggle="dapiCreate" aria-controls="dapiCreate">{{ $k('cancel') }}</button>
              @endif
            </div>
          </form>
        @elseif($isOk)
          <p class="mini-message" style="color: var(--muted);">{{ $k('max_keys', ['count' => $maxKeys]) }}</p>
        @endif

        <div class="dapi-list">
          @forelse($activeKeys as $apiKey)
            @php
              $keyUsage = $api['month_usage']->get($apiKey->id);
              $keyCredits = (int) ($keyUsage?->credits ?? 0);
              $keyRequests = (int) ($keyUsage?->requests ?? 0);
              $keyLimit = $apiKey->monthly_credit_limit;
            @endphp
            <div class="dapi-key">
              <div>
                <span class="dapi-key-name">{{ $apiKey->name }}</span>
                <code class="dapi-key-mask" data-code>{{ $apiKey->maskedKey() }}</code>
                <div class="dapi-key-scopes">
                  @if($apiKey->scopes === null)
                    <span>{{ $k('all_permissions') }}</span>
                  @else
                    @foreach($apiKey->scopes as $scope)<span>{{ __('developers.scopes.'.$scope) }}</span>@endforeach
                  @endif
                </div>
              </div>
              <div class="dapi-key-usage">
                <div>{{ $keyLimit ? $k('credits_of_limit', ['used' => number_format($keyCredits), 'limit' => number_format($keyLimit)]) : $k('credits_used', ['used' => number_format($keyCredits)]) }}</div>
                @if($keyLimit)
                  <div class="meter-track" aria-hidden="true"><div class="meter-fill" style="width: {{ min(100, $keyCredits / max(1, $keyLimit) * 100) }}%"></div></div>
                @endif
                <div>{{ $k('requests_count', ['count' => number_format($keyRequests)]) }}</div>
                <div>{{ $apiKey->last_used_at ? $k('last_used', ['time' => $apiKey->last_used_at->locale($locale)->diffForHumans()]) : $k('never') }}</div>
              </div>
              <div class="dapi-key-actions">
                <button type="button" class="dapi-btn small" data-dapi-toggle="dapiEdit{{ $apiKey->id }}" aria-controls="dapiEdit{{ $apiKey->id }}" aria-expanded="false">{{ $k('edit') }}</button>
                <form method="POST" action="{{ route('developers.keys.revoke', $apiKey) }}" data-dapi-confirm="{{ $k('revoke_confirm') }}">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="dapi-btn small danger">{{ $k('revoke') }}</button>
                </form>
              </div>
              <form method="POST" action="{{ route('developers.keys.update', $apiKey) }}" class="dapi-key-edit" id="dapiEdit{{ $apiKey->id }}" hidden>
                @csrf
                @method('PUT')
                <div class="dapi-field">
                  <label for="dapiName{{ $apiKey->id }}">{{ $k('name_label') }}</label>
                  <input type="text" id="dapiName{{ $apiKey->id }}" name="name" maxlength="80" required value="{{ $apiKey->name }}">
                </div>
                <div class="dapi-field">
                  <label for="dapiLimit{{ $apiKey->id }}">{{ $k('limit_label') }}</label>
                  <input type="number" id="dapiLimit{{ $apiKey->id }}" name="monthly_credit_limit" min="1" step="1" inputmode="numeric" value="{{ $keyLimit }}" placeholder="{{ $k('limit_placeholder') }}" dir="ltr">
                </div>
                <button type="submit" class="dapi-btn small primary">{{ $k('save') }}</button>
              </form>
            </div>
          @empty
            <p class="dapi-empty">{{ $k('empty') }}</p>
          @endforelse
        </div>

        @if($api['revoked_keys']->isNotEmpty())
          <details class="dapi-revoked">
            <summary>{{ $k('revoked_title') }} ({{ $api['revoked_keys']->count() }})</summary>
            <ul>
              @foreach($api['revoked_keys'] as $apiKey)
                <li><span>{{ $apiKey->name }} <code data-code>{{ $apiKey->maskedKey() }}</code></span><span>{{ $k('revoked_on', ['date' => $date($apiKey->revoked_at)]) }}</span></li>
              @endforeach
            </ul>
          </details>
        @endif

        <p class="dapi-security">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.5 3 8.3 7 10 4-1.7 7-5.5 7-10V6z"/></svg>
          <span>{{ $k('security_body') }}</span>
        </p>
      </article>
    @endif

    {{-- AI apps connected with "Sign in with Listen" (App\Mcp\ConnectedApps): each has a key of its own. --}}
    @php
      $c = fn (string $key, array $replace = []) => __('connect.'.$key, $replace);
      $apps = $api['connected_apps'] ?? collect();
      $appsStatus = $api['apps_status'] ?? null;
    @endphp
    @if($appsStatus !== null && ($appsStatus === \App\Support\Api\ApiPlanAccess::OK || $apps->isNotEmpty()))
      <article class="page-card reveal dapi" id="developerApiApps">
        <div class="dapi-keys-head">
          <h2>{{ $c('apps_title') }} <small>{{ number_format($apps->count()) }}</small></h2>
        </div>
        <p class="dapi-apps-intro">{{ $c('apps_intro') }}</p>

        <div class="dapi-list">
          @forelse($apps as $connected)
            @php
              $appUsage = $api['month_usage']->get($connected->id);
              $appCredits = (int) ($appUsage?->credits ?? 0);
              $appRequests = (int) ($appUsage?->requests ?? 0);
              $appLimit = $connected->monthly_credit_limit;
            @endphp
            <div class="dapi-key">
              <div class="dapi-app">
                <span class="dapi-app-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim((string) $connected->name) ?: '?', 0, 1)) }}</span>
                <div>
                  <span class="dapi-key-name">{{ $connected->name }}</span>
                  <span class="dapi-key-mask">{{ $c('connected_on', ['date' => $date($connected->created_at)]) }}</span>
                </div>
              </div>
              <div class="dapi-key-usage">
                <div>{{ $appLimit ? $k('credits_of_limit', ['used' => number_format($appCredits), 'limit' => number_format($appLimit)]) : $k('credits_used', ['used' => number_format($appCredits)]) }}</div>
                @if($appLimit)
                  <div class="meter-track" aria-hidden="true"><div class="meter-fill" style="width: {{ min(100, $appCredits / max(1, $appLimit) * 100) }}%"></div></div>
                @endif
                <div>{{ $k('requests_count', ['count' => number_format($appRequests)]) }}</div>
                <div>{{ $connected->last_used_at ? $k('last_used', ['time' => $connected->last_used_at->locale($locale)->diffForHumans()]) : $k('never') }}</div>
              </div>
              <div class="dapi-key-actions">
                <button type="button" class="dapi-btn small" data-dapi-toggle="dapiEdit{{ $connected->id }}" aria-controls="dapiEdit{{ $connected->id }}" aria-expanded="false">{{ $k('edit') }}</button>
                <form method="POST" action="{{ route('developers.keys.revoke', $connected) }}" data-dapi-confirm="{{ $c('disconnect_confirm') }}">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="dapi-btn small danger">{{ $c('disconnect') }}</button>
                </form>
              </div>
              <form method="POST" action="{{ route('developers.keys.update', $connected) }}" class="dapi-key-edit" id="dapiEdit{{ $connected->id }}" hidden>
                @csrf
                @method('PUT')
                <div class="dapi-field">
                  <label for="dapiName{{ $connected->id }}">{{ $k('name_label') }}</label>
                  <input type="text" id="dapiName{{ $connected->id }}" name="name" maxlength="80" required value="{{ $connected->name }}">
                </div>
                <div class="dapi-field">
                  <label for="dapiLimit{{ $connected->id }}">{{ $k('limit_label') }}</label>
                  <input type="number" id="dapiLimit{{ $connected->id }}" name="monthly_credit_limit" min="1" step="1" inputmode="numeric" value="{{ $appLimit }}" placeholder="{{ $k('limit_placeholder') }}" dir="ltr">
                </div>
                <button type="submit" class="dapi-btn small primary">{{ $k('save') }}</button>
              </form>
            </div>
          @empty
            <p class="dapi-empty">{!! str_replace('@@', '<code data-code>'.e(url('/mcp')).'</code>', e($c('apps_empty', ['url' => '@@']))) !!}</p>
          @endforelse
        </div>
      </article>
    @endif
  </div>
</section>

<script>
  (function () {
    var section = document.getElementById('developerApiSection');
    if (!section) { return; }
    // Show and hide the create and edit forms.
    section.querySelectorAll('[data-dapi-toggle]').forEach(function (button) {
      button.addEventListener('click', function () {
        var target = document.getElementById(button.getAttribute('data-dapi-toggle'));
        if (!target) { return; }
        target.hidden = !target.hidden;
        section.querySelectorAll('[data-dapi-toggle="' + target.id + '"][aria-expanded]').forEach(function (other) {
          other.setAttribute('aria-expanded', target.hidden ? 'false' : 'true');
        });
        if (!target.hidden) {
          var field = target.querySelector('input[type="text"]');
          if (field) { field.focus(); }
        }
      });
    });
    // Revoking cannot be undone: ask first.
    section.querySelectorAll('[data-dapi-confirm]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.getAttribute('data-dapi-confirm'))) { event.preventDefault(); }
      });
    });
    // The new key: select on focus, copy with one click.
    section.querySelectorAll('[data-dapi-select]').forEach(function (input) {
      input.addEventListener('focus', function () { input.select(); });
    });
    section.querySelectorAll('[data-dapi-copy]').forEach(function (button) {
      button.addEventListener('click', function () {
        var input = document.getElementById(button.getAttribute('data-dapi-copy'));
        if (!input || !navigator.clipboard) { return; }
        navigator.clipboard.writeText(input.value).then(function () {
          var label = button.textContent;
          button.textContent = button.getAttribute('data-copied');
          setTimeout(function () { button.textContent = label; }, 1600);
        });
      });
    });
  })();
</script>
