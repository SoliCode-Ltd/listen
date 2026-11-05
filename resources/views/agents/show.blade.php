@include('components.page-head', ['pageTitle' => $agent->name.' · '.__('agents.title'), 'page' => 'agents'])
  {{-- The studio's dialect panel (the agent's dialect is picked as in the studio). --}}
  <link rel="stylesheet" href="{{ asset('css/voice-studio.css') }}?v={{ filemtime(public_path('css/voice-studio.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
</head>
<body>
  @include('components.header')
  @php
    $done = count(array_filter($checklist));
    $total = count($checklist);
    $script = $agent->script ?? [];
    $minutes = intdiv($stats['seconds'], 60);
    $maxMinutes = max(1, intdiv((int) $limits['call_seconds'], 60));
    $minuteLabels = [];
    foreach (range(1, $maxMinutes) as $m) { $minuteLabels[$m] = trans_choice('agents.edit_minutes', $m, ['count' => $m]); }
    // The owner's daily ceiling for callers, in steps (0: none).
    $dailySteps = [0, 15, 30, 60, 120, 240, 480, 720];
    $daily = (int) $agent->setting('daily_minutes', 0);
    $dailyAt = array_search($daily, $dailySteps, true);
    if ($dailyAt === false) { $dailySteps[] = $daily; sort($dailySteps); $dailyAt = array_search($daily, $dailySteps, true); }
    $dailyLabels = array_map(fn ($v) => $v === 0 ? __('agents.edit_daily_off') : trans_choice('agents.edit_daily_value', $v, ['count' => $v]), $dailySteps);
    $suspended = $agent->setting('suspended');
    $blocked = $agent->setting('review.verdict') === 'block' ? (string) $agent->setting('review.reason') : null;
    // What the agent speaks, chosen like the studio's dialect: any voice speaks it.
    $arabicUi = app()->getLocale() === 'ar';
    $english = $agent->language === 'en';
    $speaks = $english ? \App\Support\EnglishAccents::ALL : \App\Support\ArabicDialects::ALL;
    $speaksCode = isset($speaks[$agent->dialect ?? '']) ? $agent->dialect : ($english ? 'en-US' : 'ar-XA-WH');
    $speaksLabel = $speaks[$speaksCode][$arabicUi ? 'ar' : 'en'] ?? '';
  @endphp

  <main class="ag ag-page" id="agents">
    {{-- The studio's frame: the agent's sections in a menu down the side, then all the agents, help and
         the legal pages at its foot. A strip of tabs across the top on tablets and phones. --}}
    <div class="ag-layout">
      <aside class="ag-side">
        <div class="ag-side-inner">
          <x-app-switch current="agents" />
          <nav class="ag-tabs" role="tablist" aria-label="{{ $agent->name }}">
            @php
              $tabIcons = [
                'overview' => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.6"/>',
                'setup' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
                'knowledge' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H20v3H6.5"/>',
                'design' => '<path d="M12 3a9 9 0 1 0 0 18c1 0 1.6-.8 1.6-1.6 0-.5-.2-.9-.5-1.2-.3-.3-.5-.7-.5-1.2 0-.9.7-1.6 1.6-1.6H16a5 5 0 0 0 5-5c0-4.1-4-7.4-9-7.4z"/><circle cx="7.5" cy="11" r="1.2"/><circle cx="10.5" cy="7" r="1.2"/><circle cx="15.5" cy="7.5" r="1.2"/>',
                'live' => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.4 2.3 3.6 5.1 3.6 8.5s-1.2 6.2-3.6 8.5c-2.4-2.3-3.6-5.1-3.6-8.5s1.2-6.2 3.6-8.5z"/>',
                'conversations' => '<path d="M20 12a7.5 7.5 0 0 1-11 6.6L4 20l1.4-4.4A7.5 7.5 0 1 1 20 12z"/>',
                'customers' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.8 20a6.4 6.4 0 0 1 12.4 0M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 14a6.4 6.4 0 0 1 2.7 6"/>',
              ];
            @endphp
            @foreach(['overview', 'setup', 'knowledge', 'design', 'live', 'conversations', 'customers'] as $tab)
              @php $n = ['conversations' => $conversations->count(), 'customers' => $stats['contacts']][$tab] ?? 0; @endphp
              <button type="button" role="tab" class="ag-tab" data-ag-tab="{{ $tab }}"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $tabIcons[$tab] !!}</svg><span>{{ __('agents.tabs.'.$tab) }}</span>@if($n > 0)<small class="ag-tab-count">{{ $n > 99 ? '99+' : $n }}</small>@endif</button>
            @endforeach
          </nav>
          @include('agents.partials.side-foot', ['back' => true])
        </div>
      </aside>
    <div class="ag-main">
    {{-- What needs the owner now: Listen suspended it, the check paused it, or callers could not reach it today. --}}
    @if($suspended)
      <p class="ag-notice is-bad" role="alert">{{ __('agents.suspended_note', ['reason' => rtrim((string) ($suspended['reason'] ?? ''), '.').'.']) }}</p>
    @elseif($blocked !== null)
      <p class="ag-notice is-bad" role="alert">{{ __('agents.review_paused_note', ['reason' => rtrim($blocked ?: __('agents.review_default'), '.').'.']) }}</p>
    @elseif($missed)
      {{-- Every line busy: how many left a number (they are in Customers), and a plan with more lines. --}}
      <div class="ag-notice is-warn" role="status">
        <span>{{ trans_choice('agents.missed', $missed['count'], ['count' => $missed['count'], 'name' => $agent->name, 'reason' => __('agents.missed_reasons.'.$missed['reason'])]) }}@if(($missed['callbacks'] ?? 0) > 0) {{ trans_choice('agents.missed_callbacks', $missed['callbacks'], ['count' => $missed['callbacks']]) }}@endif</span>
        @if(($missed['callbacks'] ?? 0) > 0 || $missed['reason'] === 'lines')
          <span class="ag-notice-actions">
            @if(($missed['callbacks'] ?? 0) > 0)<button type="button" class="ag-btn ag-btn-sm" data-ag-tab-go="customers">{{ __('agents.missed_see_customers') }}</button>@endif
            @if($missed['reason'] === 'lines')<a class="ag-btn ag-btn-sm ag-btn-primary" href="{{ route('pricing') }}">{{ __('agents.missed_more_lines') }}</a>@endif
          </span>
        @endif
      </div>
    @endif

    {{-- The agent: its voice's face (the call card below has the page's one orb), name, status, business and voice. --}}
    <section class="ag-hero">
      <span class="ag-hero-face {{ $agent->isLive() ? 'is-live' : '' }}" style="--m: {{ $widget->look('accent') }}" aria-hidden="true">
        @if($voice && $voice['avatar'])<img src="{{ $voice['avatar'] }}" alt="" width="72" height="72">@else<b>{{ mb_substr($agent->name, 0, 1) }}</b>@endif
      </span>
      <div class="ag-hero-text">
        <div class="ag-hero-title">
          <h1 data-ag-hero-name>{{ $agent->name }}</h1>
          <span class="ag-pill is-{{ $agent->status }}" data-ag-status><i></i>{{ __('agents.status.'.$agent->status) }}</span>
        </div>
        <p class="ag-hero-meta">
          <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V8l8-5 8 5v13M9 21v-6h6v6"/></svg>{{ $agent->business_name }}</span>
          @if($voice)
            <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg><bdi data-ag-hero-voice>{{ __('agents.voice_of', ['name' => $voice['name']]) }}</bdi>@if($speaksLabel !== '')<small data-ag-speaks-label>{{ $speaksLabel }}</small>@endif</span>
          @endif
        </p>
      </div>
      <div class="ag-hero-actions">
        <button type="button" class="ag-btn" data-ag-tab-go="overview" data-ag-talk-open>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg><bdi data-ag-talk-label>{{ __('agents.talk_to', ['name' => $agent->name]) }}</bdi>
        </button>
        @if($agent->isLive())
          <button type="button" class="ag-btn" data-ag-status-set="paused">{{ __('agents.live_pause') }}</button>
        @elseif(! $limits['included'] && ! $balance['upgrade']['none'])
          {{-- A plan without agents: the way to one, instead of a button that cannot work. --}}
          <a class="ag-btn ag-btn-primary" href="{{ $balance['upgrade']['url'] }}">{{ __('agents.balance.upgrade', ['plan' => $balance['upgrade']['plan']]) }}</a>
        @elseif(! $suspended)
          <button type="button" class="ag-btn ag-btn-primary" data-ag-status-set="live">{{ $agent->status === 'paused' ? __('agents.live_resume') : __('agents.live_go') }}</button>
        @endif
      </div>
    </section>

    {{-- Overview: next steps, this month, and talking to it. --}}
    <section class="ag-panel" data-ag-panel="overview">
      {{-- The account's call time lives on the agents list; here only a line when it needs the owner. --}}
      @if(in_array($balance['state'], ['low', 'out', 'plan'], true))
        @php $bDate = $balance['renews_at'] ? $balance['renews_at']->locale(app()->getLocale())->translatedFormat('j F') : null; @endphp
        <p class="ag-balance-line {{ $balance['state'] === 'low' ? '' : 'is-out' }}" role="status">
          <span>{{ match ($balance['state']) {
            'low' => $bDate ? __('agents.balance.state_low', ['date' => $bDate]) : __('agents.balance.state_low_nodate'),
            'out' => __('agents.balance.state_out'),
            default => $balance['upgrade']['plan'] ? __('agents.balance.state_plan', ['plan' => $balance['upgrade']['plan']]) : __('agents.balance.state_plan_none'),
          } }}</span>
          <a href="{{ route('voice-agents.index') }}">{{ __('agents.balance.title') }}</a>
        </p>
      @endif
      <div class="ag-stats">
        @foreach(['calls' => $stats['calls'], 'minutes' => $minutes, 'contacts' => $stats['contacts'], 'credits' => $stats['credits']] as $key => $value)
          <div class="ag-card ag-stat">
            <span class="ag-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24">{!! ['calls' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>', 'minutes' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>', 'contacts' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>', 'credits' => '<path d="M12 3l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.4 6.8 19.1l1-5.8L3.5 9.2l5.9-.9z"/>'][$key] !!}</svg></span>
            <b>{{ number_format($value) }}</b><span>{{ __('agents.overview_'.$key) }}</span>
          </div>
        @endforeach
      </div>
      <div class="ag-two">
        <div class="ag-card ag-section">
          @include('agents.partials.talk', ['agent' => $agent, 'avatar' => $voice['avatar'] ?? null, 'freeTestLeft' => $freeTestLeft, 'perMinute' => $perMinute, 'female' => ($voice['gender'] ?? null) === 'female', 'testBlock' => $testBlock ?? null])
        </div>
        <div class="ag-card ag-checklist">
          <h2>{{ __('agents.checklist_title') }} <span class="ag-progress" style="--p: {{ (int) round($done / $total * 100) }}; position:relative"><span>{{ $done }}/{{ $total }}</span></span></h2>
          @foreach($checklist as $key => $ok)
            <button type="button" class="ag-check {{ $ok ? 'is-done' : '' }}" data-ag-tab-go="{{ ['created' => 'setup', 'voice' => 'setup', 'tested' => 'overview', 'facts' => 'knowledge', 'live' => 'live'][$key] }}" @if($key === 'tested') data-ag-talk-open @endif><i></i><span>{{ __('agents.checklist.'.$key) }}</span>@unless($ok)<svg class="ag-check-go" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>@endunless</button>
          @endforeach
          <p class="ag-hint" style="margin-top:.4rem">{{ __('agents.per_minute', ['credits' => number_format($perMinute)]) }}</p>
        </div>
      </div>
    </section>

    {{-- Setup, in plain words. --}}
    <section class="ag-panel" data-ag-panel="setup">
      {{-- Each setting with what it is for beside it; saving stays at hand at the bottom. --}}
      <form class="ag-settings" data-ag-script-form>
        <div class="ag-card ag-settings-card">
          <div class="ag-setting">
            <div class="ag-setting-label"><label for="ag-name-edit">{{ __('agents.edit_name') }}</label><p>{{ __('agents.edit_name_hint') }}</p></div>
            <input class="ag-input" id="ag-name-edit" name="name" maxlength="80" dir="auto" required value="{{ $agent->name }}">
          </div>
          <div class="ag-setting">
            <div class="ag-setting-label"><label for="ag-first">{{ __('agents.edit_first') }}</label><p>{{ __('agents.edit_first_hint') }}</p></div>
            <textarea class="ag-textarea" id="ag-first" name="first_message" rows="2" dir="auto" style="min-height:84px">{{ $agent->first_message }}</textarea>
          </div>
          <div class="ag-setting">
            <div class="ag-setting-label"><label for="ag-identity">{{ __('agents.edit_identity') }}</label><p>{{ __('agents.edit_identity_hint') }}</p></div>
            <textarea class="ag-textarea" id="ag-identity" name="identity" dir="auto" style="min-height:104px">{{ $script['identity'] ?? '' }}</textarea>
          </div>
          @foreach(['never' => 'edit_never', 'steps' => 'edit_steps'] as $listKey => $label)
            <div class="ag-setting">
              <div class="ag-setting-label"><span class="ag-label">{{ __('agents.'.$label) }}</span><p>{{ __('agents.'.$label.'_hint') }}</p></div>
              <div class="ag-setting-control">
                <div class="ag-list-editor {{ $listKey === 'steps' ? 'is-numbered' : '' }}" data-ag-list="{{ $listKey }}">
                  @foreach(array_values((array) ($script[$listKey] ?? [])) ?: [''] as $item)
                    <div class="ag-list-item"><textarea class="ag-input ag-grow" rows="1" dir="auto" aria-label="{{ __('agents.'.$label) }}">{{ $item }}</textarea><button type="button" class="ag-btn ag-btn-sm ag-btn-ghost ag-list-x" data-ag-list-remove aria-label="{{ __('agents.edit_remove') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
                  @endforeach
                </div>
                <button type="button" class="ag-btn ag-btn-sm ag-list-add" data-ag-list-add="{{ $listKey }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>{{ __('agents.edit_add') }}</button>
              </div>
            </div>
          @endforeach
          <div class="ag-setting">
            <div class="ag-setting-label"><label for="ag-handoff">{{ __('agents.edit_handoff') }}</label><p>{{ __('agents.edit_handoff_hint') }}</p></div>
            <textarea class="ag-textarea" id="ag-handoff" name="handoff" dir="auto" style="min-height:84px">{{ $script['handoff'] ?? '' }}</textarea>
          </div>
          {{-- The moments a call needs the owner's decision (set up with what most businesses choose). --}}
          @php $choices = array_values(array_filter((array) ($agent->choices ?? []), fn ($c) => is_array($c) && filled($c['question'] ?? null) && count((array) ($c['options'] ?? [])) > 1)); @endphp
          @if($choices)
            <div class="ag-setting">
              <div class="ag-setting-label"><span class="ag-label">{{ __('agents.decisions_title') }}</span><p>{{ __('agents.decisions_hint') }}</p></div>
              <div class="ag-setting-control ag-decisions">
                @foreach($choices as $qi => $choice)
                  <fieldset class="ag-decision" data-ag-choice data-question="{{ $choice['question'] }}">
                    <legend dir="auto">{{ $choice['question'] }}</legend>
                    <div class="ag-options">
                      @foreach(array_values((array) $choice['options']) as $option)
                        <label class="ag-option"><input type="radio" name="ag-choice-{{ $qi }}" value="{{ $option }}" @checked($option === ($choice['answer'] ?? null))><span dir="auto">{{ $option }}</span></label>
                      @endforeach
                    </div>
                  </fieldset>
                @endforeach
              </div>
            </div>
          @endif
          <div class="ag-setting">
            <div class="ag-setting-label"><label for="ag-max">{{ __('agents.edit_max_call') }}</label><p>{{ __('agents.edit_max_call_hint') }}</p></div>
            <div class="ag-setting-control ag-range">
              <input id="ag-max" type="range" min="1" max="{{ $maxMinutes }}" step="1" value="{{ min($maxMinutes, intdiv($agent->max_call_seconds, 60)) }}" name="max_minutes" data-ag-max data-labels="{{ json_encode($minuteLabels, JSON_UNESCAPED_UNICODE) }}">
              <output data-ag-max-out>{{ $minuteLabels[min($maxMinutes, intdiv($agent->max_call_seconds, 60))] ?? '' }}</output>
            </div>
          </div>
          <div class="ag-setting">
            <div class="ag-setting-label"><label for="ag-daily">{{ __('agents.edit_daily') }}</label><p>{{ __('agents.edit_daily_hint') }}</p></div>
            <div class="ag-setting-control ag-range">
              <input id="ag-daily" type="range" min="0" max="{{ count($dailySteps) - 1 }}" step="1" value="{{ $dailyAt }}" data-ag-daily data-steps="{{ json_encode($dailySteps) }}" data-labels="{{ json_encode($dailyLabels, JSON_UNESCAPED_UNICODE) }}">
              <output data-ag-daily-out>{{ $dailyLabels[$dailyAt] }}</output>
            </div>
          </div>
          <div class="ag-setting">
            <div class="ag-setting-label"><span class="ag-label" id="ag-dialect-label">{{ $english ? __('agents.accent_label') : __('agents.dialect_label') }}</span><p>{{ $english ? __('agents.accent_page_hint') : __('agents.dialect_page_hint') }}</p></div>
            <div class="ag-setting-control ag-speaks">
              @include('agents.partials.speaks-pick', ['id' => 'ag-dialect', 'labelId' => 'ag-dialect-label', 'english' => $english, 'value' => $speaksCode, 'faces' => collect($voices)->pluck('avatar')->filter()->take(3)->all()])
            </div>
          </div>
          <div class="ag-setting">
            <div class="ag-setting-label"><span class="ag-label">{{ __('agents.voice_heading') }}</span><p>{{ __('agents.voice_hint') }}</p></div>
            <details class="ag-voice-pick">
              <summary>
                <span class="ag-voice-current">
                  @if($voice && $voice['avatar'])<img src="{{ $voice['avatar'] }}" alt="" width="40" height="40">@endif
                  <span><b data-ag-voice-name>{{ $voice['name'] ?? '' }}</b><small data-ag-speaks-label>{{ $speaksLabel }}</small></span>
                </span>
                <em class="ag-voice-change">{{ __('agents.voice_change') }}</em>
              </summary>
              <div class="ag-voice-tools" style="margin-top:.9rem">
                <div class="ag-seg" role="radiogroup">
                  <label><input type="radio" name="ag-voice-scope" value="suggested" checked><span>{{ __('agents.voice_suggested') }}</span></label>
                  <label><input type="radio" name="ag-voice-scope" value="all"><span>{{ __('agents.voice_all') }}</span></label>
                </div>
                <div class="ag-seg" role="radiogroup">
                  <label><input type="radio" name="ag-voice-gender" value="any" checked><span>{{ __('agents.filter_any') }}</span></label>
                  <label><input type="radio" name="ag-voice-gender" value="female"><span>{{ __('agents.filter_female') }}</span></label>
                  <label><input type="radio" name="ag-voice-gender" value="male"><span>{{ __('agents.filter_male') }}</span></label>
                </div>
              </div>
              <div class="ag-voices" data-ag-voices></div>
            </details>
          </div>
        </div>
        <div class="ag-savebar">
          <a class="ag-btn ag-btn-ghost" href="{{ route('voice-agents.edit', $agent) }}">{{ __('agents.edit_wizard') }}</a>
          <button type="submit" class="ag-btn ag-btn-primary">{{ __('agents.edit_save') }}</button>
        </div>
      </form>
    </section>

    {{-- What it knows. --}}
    <section class="ag-panel" data-ag-panel="knowledge">
      <div class="ag-kb">
        <div class="ag-kb-main">
          {{-- Latest updates: what changed since the website was read (an upcoming event, a new date or
               price). The agent reads it first and it wins over the website, the notes and the files. --}}
          <form class="ag-card ag-kb-editor ag-kb-updates" data-ag-updates-form>
            <div class="ag-kb-head">
              <h2>{{ __('agents.updates_title') }} <span class="ag-kb-badge">{{ __('agents.updates_badge') }}</span></h2>
              <p class="ag-hint">{{ __('agents.updates_hint') }}</p>
            </div>
            <textarea class="ag-textarea" name="updates" dir="auto" maxlength="{{ \App\Support\Agents\AgentInstructions::MAX_UPDATES_CHARS }}" placeholder="{{ __('agents.updates_placeholder') }}" data-ag-updates-text>{{ $agent->setting('updates') }}</textarea>
            <div class="ag-kb-foot">
              <span class="ag-hint" data-ag-updates-at>{{ \App\Http\Controllers\AgentController::updatesAt($agent) }}</span>
              <button type="submit" class="ag-btn ag-btn-primary">{{ __('agents.edit_save') }}</button>
            </div>
          </form>
          {{-- Your website: every page of it, which the agent looks things up in during a call (AgentSite). --}}
          @if($agent->website_url)
            @php $siteRead = \App\Support\Agents\AgentSite::state($agent); @endphp
            <section class="ag-card ag-kb-site" data-ag-site data-state="{{ $siteRead['state'] }}" aria-labelledby="ag-site-title">
              <div class="ag-kb-head">
                <h2 id="ag-site-title">{{ __('agents.site.title') }}</h2>
                <p class="ag-hint">{{ __('agents.site.hint') }}</p>
              </div>
              <div class="ag-kb-site-foot">
                <p class="ag-link-read is-{{ $siteRead['state'] }}" data-ag-site-text role="status">{{ $siteRead['text'] }}</p>
                <button type="button" class="ag-btn ag-btn-sm" data-ag-site-again @disabled($siteRead['state'] === 'reading')><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 5v6h-6"/></svg>{{ __('agents.site.again') }}</button>
              </div>
            </section>
          @endif
          {{-- Social media links: the business's own accounts and website (found on its website or added
               here), which the agent writes in the call's chat when a caller asks (AgentLinks). --}}
          @php $agentLinks = \App\Support\Agents\AgentLinkReads::rows($agent); $linksMax = \App\Support\Agents\AgentLinks::MAX; @endphp
          <section class="ag-card ag-sites ag-kb-links" data-ag-links data-max="{{ $linksMax }}" aria-labelledby="ag-links-title">
            <div class="ag-kb-links-head">
              <div class="ag-kb-head">
                <h2 id="ag-links-title">{{ __('agents.links_title') }}</h2>
                <p class="ag-hint">{{ __('agents.links_hint') }}</p>
              </div>
              <span class="ag-sites-count" data-ag-links-count data-pattern="{{ __('agents.live_sites_count') }}">{{ __('agents.live_sites_count', ['count' => count($agentLinks), 'max' => $linksMax]) }}</span>
            </div>
            <ul class="ag-sites-list" aria-labelledby="ag-links-title" data-ag-links-list>
              @foreach($agentLinks as $link)
                <li data-url="{{ $link['url'] }}" data-state="{{ $link['read']['state'] ?? '' }}">
                  <span class="ag-site-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($link['label'], 0, 1)) }}</span>
                  <span class="ag-site-name">
                    <bdi dir="ltr"><a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer nofollow">{{ $link['label'] }}</a></bdi>
                    @if($link['network'] === 'website')<small>{{ __('agents.links_your_site') }}</small>@endif
                    {{-- What the agent read from it (AgentLinkReads); a page of another website is only read. --}}
                    @if($link['read'] || ! $link['shown'])
                      <small class="ag-link-read is-{{ $link['read']['state'] ?? 'none' }}" data-ag-link-read>@unless($link['shown'])<i class="ag-link-tag" title="{{ __('agents.links_read_only_hint') }}">{{ __('agents.links_read_only') }}</i>@endunless{{ $link['read']['text'] ?? '' }}</small>
                    @endif
                  </span>
                  <button type="button" class="ag-sites-remove" data-ag-link-remove aria-label="{{ __('agents.links_remove', ['link' => $link['label']]) }}">{{ __('agents.live_site_remove_short') }}</button>
                </li>
              @endforeach
            </ul>
            <div class="ag-sites-empty" data-ag-links-empty @if($agentLinks !== []) hidden @endif>
              <span class="ag-site-mark is-empty" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/></svg></span>
              <p class="ag-site-name"><small>{{ __('agents.links_empty') }}</small></p>
            </div>
            <div class="ag-sites-foot">
              <form class="ag-sites-add" data-ag-links-add novalidate @if(count($agentLinks) >= $linksMax) hidden @endif>
                <label class="ag-sites-field">
                  <input type="text" inputmode="url" autocomplete="off" autocapitalize="off" spellcheck="false" dir="ltr" maxlength="500" placeholder="{{ __('agents.links_placeholder') }}" aria-label="{{ __('agents.links_placeholder') }}" data-ag-link-input>
                </label>
                <button type="submit" class="ag-btn ag-btn-primary"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>{{ __('agents.links_add') }}</button>
              </form>
              <p class="ag-error" data-ag-links-error role="alert" hidden></p>
              @if($agent->website_url)
                <div class="ag-kb-links-find">
                  <button type="button" class="ag-btn ag-btn-sm" data-ag-links-find><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/></svg>{{ __('agents.links_find') }}</button>
                  <span class="ag-hint" data-ag-links-note role="status"></span>
                </div>
              @endif
            </div>
          </section>
          {{-- Files: the owner's menu, price list or brochure, read once and used on every call. --}}
          <section class="ag-card ag-kb-files" data-ag-files
            data-index-url="{{ route('voice-agents.files.index', $agent) }}"
            data-store-url="{{ route('voice-agents.files.store', $agent) }}"
            data-file-url="{{ route('voice-agents.files.show', ['agent' => $agent, 'source' => '__ID__']) }}">
            <div class="ag-kb-head">
              <h2>{{ __('agents.files.title') }}</h2>
              <p class="ag-hint">{{ __('agents.files.hint') }}</p>
            </div>
            <label class="ag-drop" data-ag-drop>
              <input type="file" multiple accept="{{ collect(array_keys(\App\Services\Agents\FileReader::TYPES))->map(fn ($e) => '.'.$e)->implode(',') }}" data-ag-file-input>
              <span class="ag-drop-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></svg></span>
              <b>{{ __('agents.files.drop') }}</b>
              <small>{{ __('agents.files.types', ['mb' => intdiv(\App\Http\Controllers\AgentFileController::MAX_KB, 1024)]) }}</small>
            </label>
            <ul class="ag-files" data-ag-files-list aria-live="polite"></ul>
            <div class="ag-files-used" data-ag-files-used hidden><span></span><i><b></b></i></div>
          </section>
        <form class="ag-card ag-kb-editor" data-ag-knowledge-form>
          <div class="ag-kb-head">
            <h2>{{ __('agents.knowledge_notes_title') }}</h2>
            <p class="ag-hint">{{ __('agents.knowledge_notes_hint') }}</p>
          </div>
          <textarea class="ag-textarea" name="knowledge" dir="auto" maxlength="40000" data-ag-kb-text>{{ $agent->knowledge }}</textarea>
          <div class="ag-kb-foot">
            <span class="ag-hint ag-num" dir="ltr" data-ag-kb-count>{{ number_format(mb_strlen((string) $agent->knowledge)) }} / {{ number_format(40000) }}</span>
            <button type="submit" class="ag-btn ag-btn-primary">{{ __('agents.edit_save') }}</button>
          </div>
        </form>
        </div>
        <aside class="ag-card ag-kb-tips">
          <h3>{{ __('agents.knowledge_tips_title') }}</h3>
          <ul>
            @foreach(__('agents.knowledge_tips') as $tip)
              <li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>{{ $tip }}</li>
            @endforeach
          </ul>
          <div class="ag-kb-example">
            <b>{{ __('agents.knowledge_example_title') }}</b>
            <p dir="auto">{{ __('agents.knowledge_example') }}</p>
          </div>
        </aside>
      </div>
    </section>

    {{-- Design: how the call button looks. --}}
    <section class="ag-panel" data-ag-panel="design">
      @include('agents.partials.style', ['agent' => $agent, 'widget' => $widget])
    </section>

    {{-- Go live. --}}
    <section class="ag-panel" data-ag-panel="live">
      @include('agents.partials.golive', ['agent' => $agent, 'widget' => $widget, 'embed' => $embed])
    </section>

    {{-- Conversations. --}}
    <section class="ag-panel" data-ag-panel="conversations">
      @if($conversations->isEmpty())
        <div class="ag-card ag-empty-state">
          <span class="ag-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg></span>
          <h3>{{ __('agents.conversations_empty_title') }}</h3>
          <p>{{ __('agents.conversations_empty') }}</p>
          <div class="ag-empty-actions"><button type="button" class="ag-btn ag-btn-primary" data-ag-tab-go="overview" data-ag-talk-open><bdi data-ag-talk-label>{{ __('agents.talk_to', ['name' => $agent->name]) }}</bdi></button><button type="button" class="ag-btn" data-ag-tab-go="live">{{ __('agents.empty_share') }}</button></div>
        </div>
      @else
        <div class="ag-list">
          @foreach($conversations as $c)
            <article class="ag-card ag-convo">
              <header class="ag-convo-top">
                <span class="ag-convo-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg></span>
                <div class="ag-convo-meta">
                  <b>{{ __('agents.conversation_channel.'.$c->channel) }}</b>
                  <span>{{ __('agents.conversation_duration', ['m' => intdiv($c->duration_seconds, 60), 's' => $c->duration_seconds % 60]) }}@if($c->credits > 0) · {{ __('agents.conversation_credits', ['count' => number_format($c->credits)]) }}@endif</span>
                </div>
                <time datetime="{{ $c->started_at->toIso8601String() }}">{{ $c->started_at->diffForHumans() }}</time>
              </header>
              {{-- Who called, when the website said who is signed in (CallerIdentity). --}}
              @if(! empty($c->caller['email']))
                <p class="ag-convo-caller">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.6"/><path d="M4.8 20a7.2 7.2 0 0 1 14.4 0"/></svg>
                  <span>{{ __('agents.conversation_caller') }}</span>
                  @if(! empty($c->caller['name']))<b><bdi>{{ $c->caller['name'] }}</bdi></b>@endif
                  <a href="mailto:{{ $c->caller['email'] }}" dir="ltr">{{ $c->caller['email'] }}</a>
                </p>
              @endif
              @if($c->summary)<p class="ag-convo-summary" dir="auto">{{ $c->summary }}</p>@endif
              @if($c->lead)
                <div class="ag-convo-lead">
                  <b>{{ __('agents.conversation_details') }}</b>
                  <ul>
                    @if(! empty($c->lead['name']))<li dir="auto">{{ $c->lead['name'] }}</li>@endif
                    @if(! empty($c->lead['phone']))<li><a href="tel:{{ $c->lead['phone'] }}" dir="ltr">{{ $c->lead['phone'] }}</a></li>@endif
                    @if(! empty($c->lead['email']))<li><a href="mailto:{{ $c->lead['email'] }}" dir="ltr">{{ $c->lead['email'] }}</a></li>@endif
                    @if(! empty($c->lead['note']))<li dir="auto">{{ $c->lead['note'] }}</li>@endif
                  </ul>
                </div>
              @endif
              @if(! empty($c->transcript))
                <details class="ag-convo-more">
                  <summary><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>{{ __('agents.conversation_transcript') }}</summary>
                  <div class="ag-transcript" style="max-height:none">
                    @foreach($c->transcript as $t)
                      @if(($t['kind'] ?? null) === 'links')
                        {{-- The links the agent wrote in the chat when the caller asked for them. --}}
                        <div class="ag-bubble is-agent is-links" style="opacity:1;transform:none"><small>{{ $agent->name }} · {{ __('agents.links_shown') }}</small><bdi dir="ltr">{{ (string) $t['text'] }}</bdi></div>
                      @else
                        <div class="ag-bubble is-{{ $t['role'] }}" style="opacity:1;transform:none" dir="auto"><small>{{ $t['role'] === 'agent' ? $agent->name : __('agents.talk_you') }}</small>{{ \App\Support\Agents\SpokenNumbers::forDisplay((string) $t['text'], $agent->language !== 'en') }}</div>
                      @endif
                    @endforeach
                  </div>
                </details>
              @endif
            </article>
          @endforeach
        </div>
      @endif
    </section>

    {{-- Customers. --}}
    <section class="ag-panel" data-ag-panel="customers">
      @if($contacts->isEmpty())
        <div class="ag-card ag-empty-state">
          <span class="ag-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18.5 14.2A6.5 6.5 0 0 1 21.5 20"/></svg></span>
          <h3>{{ __('agents.customers_empty_title') }}</h3>
          <p>{{ __('agents.customers_empty') }}</p>
          <div class="ag-empty-actions"><button type="button" class="ag-btn ag-btn-primary" data-ag-tab-go="overview" data-ag-talk-open><bdi data-ag-talk-label>{{ __('agents.talk_to', ['name' => $agent->name]) }}</bdi></button><button type="button" class="ag-btn" data-ag-tab-go="live">{{ __('agents.empty_share') }}</button></div>
        </div>
      @else
        <div class="ag-card" style="overflow:hidden">
          <table class="ag-table">
            <thead><tr><th>{{ __('agents.customer_name') }}</th><th>{{ __('agents.customer_contact') }}</th><th>{{ __('agents.customer_note') }}</th><th></th></tr></thead>
            <tbody>
              @foreach($contacts as $contact)
                <tr>
                  <td dir="auto"><strong>{{ $contact->name ?: '—' }}</strong><div class="ag-hint">{{ $contact->last_contact_at?->diffForHumans() }}</div></td>
                  <td class="ag-num"><span dir="ltr">{{ $contact->phone ?: $contact->email }}</span></td>
                  <td dir="auto">{{ $contact->note }}</td>
                  <td style="white-space:nowrap">
                    @if($contact->phone)
                      <a class="ag-btn ag-btn-sm" href="tel:{{ $contact->phone }}">{{ __('agents.customer_call') }}</a>
                      <button type="button" class="ag-btn ag-btn-sm ag-btn-ghost" data-ag-copy="{{ $contact->phone }}">{{ __('agents.customer_copy') }}</button>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </section>
    </div>
    </div>
    <div class="ag-toast" data-ag-toast role="status" aria-live="polite"></div>
  </main>

  @include('components.scripts')
  @include('components.orb-script')
  @php
    $agentsPage = [
      'mode' => 'agent',
      'csrf' => csrf_token(),
      'locale' => app()->getLocale(),
      'agent' => [
        'id' => $agent->public_id, 'name' => $agent->name, 'status' => $agent->status, 'language' => $agent->language,
        'dialect' => $agent->dialect, 'speaker_id' => $agent->speaker_id, 'gender' => $voice['gender'] ?? null,
        // Knowledge → Social media links: an agent made before links were read has its website searched once.
        'links_looked' => $agent->setting('links_found_at') !== null || (bool) $agent->setting('links_edited'),
        'has_website' => (bool) $agent->website_url,
        'urls' => [
          'show' => route('voice-agents.show', $agent), 'update' => route('voice-agents.update', $agent),
          'test' => route('voice-agents.test-call', $agent), 'correct' => route('voice-agents.correct', $agent),
          'write' => route('voice-agents.write', $agent), 'edit' => route('voice-agents.edit', $agent),
          'links' => route('voice-agents.links', $agent), 'link_reads' => route('voice-agents.links.reads', $agent),
        ],
      ],
      'regions' => collect(\App\Support\ArabicDialects::ALL)->map(fn ($d) => $d['region'])->all(),
      'voices' => [$agent->language => $voices],
      // The Design tab's preview: the button as the website gets it, in the agent's language.
      'design' => (function () use ($agent, $widget, $voice) {
        $l = $agent->language === 'en' ? 'en' : 'ar';
        $female = ($voice['gender'] ?? null) === 'female';
        $states = \App\Support\Agents\Gendered::lines((array) trans('agents.talk_states', ['name' => $agent->name], $l), $female);
        return [
          'look' => $widget->style(),
          'palettes' => \App\Models\AgentWidget::PALETTES,
          'defaults' => \App\Models\AgentWidget::DEFAULT_APPEARANCE,
          'rtl' => $l === 'ar',
          'cfg' => [
            'name' => $agent->name, 'business' => $agent->business_name, 'language' => $agent->language, 'dir' => $l === 'ar' ? 'rtl' : 'ltr',
            'avatar' => $voice['avatar'] ?? null,
            'text' => [
              'label' => trans('agents.widget_label', [], $l), 'title' => $agent->business_name,
              'subtitle' => trans($female ? 'agents.widget_subtitle_female' : 'agents.widget_subtitle', [], $l),
              'start' => trans('agents.talk_start', [], $l), 'end' => trans('agents.talk_end', [], $l), 'states' => $states,
              'powered' => trans('agents.powered', [], $l), 'close' => trans('agents.widget_close', [], $l),
              'ended_after' => trans('agents.talk_ended_after', [], $l),
              'sample' => [
                ['role' => 'agent', 'text' => $agent->first_message ?: trans('agents.design_sample.hello', ['name' => $agent->name, 'business' => $agent->business_name], $l)],
                ['role' => 'caller', 'text' => trans('agents.design_sample.ask', [], $l)],
                ['role' => 'agent', 'text' => trans('agents.design_sample.answer', [], $l)],
              ],
            ],
          ],
          'text' => ['saving' => __('agents.design.saving'), 'saved' => __('agents.design.saved'), 'failed' => __('agents.design.failed')],
        ];
      })(),
      'text' => [
        // With :name and each line's woman's form: the page fills them for its voice, also after a new one.
        'states' => (array) __('agents.talk_states'),
        'voice_of' => __('agents.voice_of'), 'talk_to' => __('agents.talk_to'),
        'voice_renamed' => __('agents.voice_renamed'), 'voice_renamed_female' => \App\Support\Agents\Gendered::line('agents.voice_renamed', true),
        'start' => __('agents.talk_start'), 'end' => __('agents.talk_end'),
        'mic' => __('agents.talk_mic_blocked'), 'you' => __('agents.talk_you'), 'ended_after' => __('agents.talk_ended_after'),
        'correct' => __('agents.talk_correct'), 'correct_placeholder' => __('agents.talk_correct_placeholder'),
        'correct_save' => __('agents.talk_correct_save'), 'correct_saved' => __('agents.talk_correct_saved'),
        'saved_contact' => __('agents.talk_saved_contact'), 'copied' => __('agents.live_copied'), 'saved' => __('agents.saved'),
        'site_remove' => __('agents.live_site_remove'), 'site_remove_short' => __('agents.live_site_remove_short'), 'site_invalid' => __('agents.live_site_invalid'), 'site_exists' => __('agents.live_site_exists'), 'site_max' => __('agents.live_site_max'),
        'links_remove' => __('agents.links_remove'), 'links_saved' => __('agents.links_saved'), 'links_finding' => __('agents.links_finding'), 'links_your_site' => __('agents.links_your_site'), 'site_again_done' => __('agents.site.again_done'), 'site_again_error' => __('agents.site.again_error'), 'links_read_only' => __('agents.links_read_only'), 'links_read_only_hint' => __('agents.links_read_only_hint'),
        'play' => __('agents.voice_play'), 'pause' => __('agents.voice_pause'), 'pick' => __('agents.voice_pick'), 'recommended' => __('agents.recommended'),
        'status' => __('agents.status'),
        'files' => __('agents.files'),
        'balance' => ['upgrade' => __('agents.balance.upgrade')],
      ],
    ];
  @endphp
  <script>
    window.LISTEN_AGENTS = {!! \Illuminate\Support\Js::from($agentsPage) !!};
  </script>
  <script src="{{ asset('js/vendor/qrcode.js') }}?v={{ filemtime(public_path('js/vendor/qrcode.js')) }}" defer></script>
  <script src="{{ asset('js/agent-call.js') }}?v={{ filemtime(public_path('js/agent-call.js')) }}" defer></script>
  <script src="{{ asset('js/agent-widget.js') }}?v={{ filemtime(public_path('js/agent-widget.js')) }}" defer></script>
  @include('agents.partials.speaks-meta', ['english' => $english])
  <script src="{{ asset('js/voice-studio-icons.js') }}?v={{ filemtime(public_path('js/voice-studio-icons.js')) }}" defer></script>
  <script src="{{ asset('js/voice-studio.js') }}?v={{ filemtime(public_path('js/voice-studio.js')) }}" defer></script>
  <script src="{{ asset('js/agents.js') }}?v={{ filemtime(public_path('js/agents.js')) }}" defer></script>
</body>
</html>
