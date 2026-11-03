@include('components.page-head', ['pageTitle' => $agent->name.' · '.__('agents.title'), 'page' => 'agents'])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
</head>
<body>
  @include('components.header')
  @php
    $done = count(array_filter($checklist));
    $total = count($checklist);
    $script = $agent->script ?? [];
    $minutes = intdiv($stats['seconds'], 60);
    $maxMinutes = max(1, intdiv((int) config('listen.agents.max_call_seconds', 600), 60));
    $minuteLabels = [];
    foreach (range(1, $maxMinutes) as $m) { $minuteLabels[$m] = trans_choice('agents.edit_minutes', $m, ['count' => $m]); }
  @endphp

  <main class="ag" id="agents">
    <section class="ag-card ag-hero" data-orb-host>
      <x-orb :name="$agent->orbName()" :class="$agent->isLive() ? 'is-live' : ''" />
      <div class="ag-hero-text">
        <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
          <h1>{{ $agent->name }}</h1>
          <span class="ag-pill is-{{ $agent->status }}" data-ag-status><i></i>{{ __('agents.status.'.$agent->status) }}</span>
        </div>
        <p>{{ $agent->business_name }}@if($voice) · {{ __('agents.voice_of', ['name' => $voice['name']]) }}@endif</p>
      </div>
      <div class="ag-hero-actions">
        <button type="button" class="ag-btn" data-ag-tab-go="overview" data-ag-talk-open>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>{{ __('agents.steps.talk') }}
        </button>
        @if($agent->isLive())
          <button type="button" class="ag-btn" data-ag-status-set="paused">{{ __('agents.live_pause') }}</button>
        @else
          <button type="button" class="ag-btn ag-btn-primary" data-ag-status-set="live">{{ $agent->status === 'paused' ? __('agents.live_resume') : __('agents.live_go') }}</button>
        @endif
      </div>
    </section>

    <nav class="ag-tabs" role="tablist" aria-label="{{ $agent->name }}">
      @foreach(['overview', 'setup', 'knowledge', 'design', 'live', 'conversations', 'customers'] as $tab)
        <button type="button" role="tab" class="ag-tab" data-ag-tab="{{ $tab }}">{{ __('agents.tabs.'.$tab) }}</button>
      @endforeach
    </nav>

    {{-- Overview: next steps, this month, and talking to it. --}}
    <section class="ag-panel" data-ag-panel="overview">
      <div class="ag-stats" style="margin-bottom:1rem">
        <div class="ag-card ag-stat"><b>{{ number_format($stats['calls']) }}</b><span>{{ __('agents.overview_calls') }}</span></div>
        <div class="ag-card ag-stat"><b>{{ number_format($minutes) }}</b><span>{{ __('agents.overview_minutes') }}</span></div>
        <div class="ag-card ag-stat"><b>{{ number_format($stats['contacts']) }}</b><span>{{ __('agents.overview_contacts') }}</span></div>
        <div class="ag-card ag-stat"><b>{{ number_format($stats['credits']) }}</b><span>{{ __('agents.overview_credits') }}</span></div>
      </div>
      <div class="ag-two">
        <div class="ag-card ag-section">
          @include('agents.partials.talk', ['agent' => $agent, 'avatar' => $voice['avatar'] ?? null, 'freeTestLeft' => $freeTestLeft, 'perMinute' => $perMinute])
        </div>
        <div class="ag-card ag-checklist">
          <h2>{{ __('agents.checklist_title') }} <span class="ag-progress" style="--p: {{ (int) round($done / $total * 100) }}; position:relative"><span>{{ $done }}/{{ $total }}</span></span></h2>
          @foreach($checklist as $key => $ok)
            <button type="button" class="ag-check {{ $ok ? 'is-done' : '' }}" style="background:none;border:0;font:inherit;text-align:start;cursor:pointer" data-ag-tab-go="{{ ['created' => 'setup', 'voice' => 'setup', 'tested' => 'overview', 'facts' => 'knowledge', 'live' => 'live'][$key] }}"><i></i>{{ __('agents.checklist.'.$key) }}</button>
          @endforeach
          <p class="ag-hint" style="margin-top:.4rem">{{ __('agents.per_minute', ['credits' => number_format($perMinute)]) }}</p>
        </div>
      </div>
    </section>

    {{-- Setup, in plain words. --}}
    <section class="ag-panel" data-ag-panel="setup">
      <form class="ag-card ag-section" data-ag-script-form>
        <div class="ag-field">
          <label for="ag-first">{{ __('agents.edit_first') }}</label>
          <textarea class="ag-textarea" id="ag-first" name="first_message" rows="2" dir="auto" style="min-height:76px">{{ $agent->first_message }}</textarea>
        </div>
        <div class="ag-field">
          <label for="ag-identity">{{ __('agents.edit_identity') }}</label>
          <textarea class="ag-textarea" id="ag-identity" name="identity" dir="auto" style="min-height:90px">{{ $script['identity'] ?? '' }}</textarea>
        </div>
        @foreach(['never' => 'edit_never', 'steps' => 'edit_steps'] as $listKey => $label)
          <div class="ag-field">
            <span class="ag-label">{{ __('agents.'.$label) }}</span>
            <div class="ag-list-editor" data-ag-list="{{ $listKey }}">
              @foreach(array_values((array) ($script[$listKey] ?? [])) ?: [''] as $item)
                <div class="ag-list-item"><input class="ag-input" type="text" value="{{ $item }}" dir="auto"><button type="button" class="ag-btn ag-btn-sm ag-btn-ghost" data-ag-list-remove aria-label="{{ __('agents.edit_remove') }}">✕</button></div>
              @endforeach
            </div>
            <button type="button" class="ag-btn ag-btn-sm" style="justify-self:start" data-ag-list-add="{{ $listKey }}">+ {{ __('agents.edit_add') }}</button>
          </div>
        @endforeach
        <div class="ag-field">
          <label for="ag-handoff">{{ __('agents.edit_handoff') }}</label>
          <textarea class="ag-textarea" id="ag-handoff" name="handoff" dir="auto" style="min-height:76px">{{ $script['handoff'] ?? '' }}</textarea>
        </div>
        <div class="ag-field">
          <label for="ag-max">{{ __('agents.edit_max_call') }}: <output data-ag-max-out>{{ $minuteLabels[intdiv($agent->max_call_seconds, 60)] ?? '' }}</output></label>
          <input id="ag-max" type="range" min="1" max="{{ $maxMinutes }}" step="1" value="{{ intdiv($agent->max_call_seconds, 60) }}" name="max_minutes" data-ag-max data-labels="{{ json_encode($minuteLabels, JSON_UNESCAPED_UNICODE) }}" style="accent-color:var(--a)">
        </div>
        <details class="ag-field ag-voice-pick">
          <summary>
            <span class="ag-label">{{ __('agents.voice_heading') }}</span>
            <span class="ag-voice-current">
              @if($voice && $voice['avatar'])<img src="{{ $voice['avatar'] }}" alt="" width="34" height="34">@endif
              @if($voice)<span>{{ $voice['name'] }}</span>@endif
              <b>{{ __('agents.voice_change') }}</b>
            </span>
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
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
          <button type="submit" class="ag-btn ag-btn-primary">{{ __('agents.edit_save') }}</button>
          <a class="ag-btn ag-btn-ghost" href="{{ route('voice-agents.edit', $agent) }}">{{ __('agents.edit_wizard') }}</a>
        </div>
      </form>
    </section>

    {{-- What it knows. --}}
    <section class="ag-panel" data-ag-panel="knowledge">
      <form class="ag-card ag-section" data-ag-knowledge-form>
        <div>
          <h2>{{ __('agents.knowledge_heading') }}</h2>
          <p class="ag-hint" style="margin-top:.3rem">{{ __('agents.knowledge_hint') }}</p>
        </div>
        <textarea class="ag-textarea" name="knowledge" dir="auto" style="min-height:360px">{{ $agent->knowledge }}</textarea>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap">
          <button type="submit" class="ag-btn ag-btn-primary">{{ __('agents.edit_save') }}</button>
        </div>
      </form>
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
        <div class="ag-card ag-empty"><p>{{ __('agents.conversations_empty') }}</p></div>
      @else
        <div class="ag-list">
          @foreach($conversations as $c)
            <article class="ag-card ag-convo">
              <div class="ag-convo-top">
                <span class="ag-pill">{{ __('agents.conversation_channel.'.$c->channel) }}</span>
                <span class="ag-muted">{{ __('agents.conversation_duration', ['m' => intdiv($c->duration_seconds, 60), 's' => $c->duration_seconds % 60]) }}</span>
                @if($c->credits > 0)<span class="ag-muted">· {{ __('agents.conversation_credits', ['count' => number_format($c->credits)]) }}</span>@endif
                <time datetime="{{ $c->started_at->toIso8601String() }}">{{ $c->started_at->diffForHumans() }}</time>
              </div>
              @if($c->summary)<p>{{ $c->summary }}</p>@endif
              @if($c->lead)
                <p class="ag-note" style="margin-top:.6rem"><strong>{{ __('agents.conversation_details') }}:</strong>&nbsp;{{ implode(' · ', array_filter([$c->lead['name'] ?? null, $c->lead['phone'] ?? null, $c->lead['email'] ?? null, $c->lead['note'] ?? null])) }}</p>
              @endif
              @if(! empty($c->transcript))
                <details>
                  <summary>{{ __('agents.conversation_transcript') }}</summary>
                  <div class="ag-transcript" style="max-height:none">
                    @foreach($c->transcript as $t)
                      <div class="ag-bubble is-{{ $t['role'] }}" style="opacity:1;transform:none" dir="auto"><small>{{ $t['role'] === 'agent' ? $agent->name : __('agents.talk_you') }}</small>{{ $t['text'] }}</div>
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
        <div class="ag-card ag-empty"><p>{{ __('agents.customers_empty') }}</p></div>
      @else
        <div class="ag-card" style="overflow:hidden">
          <table class="ag-table">
            <thead><tr><th>{{ __('agents.customer_name') }}</th><th>{{ __('agents.customer_contact') }}</th><th>{{ __('agents.customer_note') }}</th><th></th></tr></thead>
            <tbody>
              @foreach($contacts as $contact)
                <tr>
                  <td dir="auto"><strong>{{ $contact->name ?: '—' }}</strong><div class="ag-hint">{{ $contact->last_contact_at?->diffForHumans() }}</div></td>
                  <td class="ag-num">{{ $contact->phone ?: $contact->email }}</td>
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
        'urls' => [
          'show' => route('voice-agents.show', $agent), 'update' => route('voice-agents.update', $agent),
          'test' => route('voice-agents.test-call', $agent), 'correct' => route('voice-agents.correct', $agent),
          'write' => route('voice-agents.write', $agent), 'edit' => route('voice-agents.edit', $agent),
        ],
      ],
      'regions' => collect(\App\Support\ArabicDialects::ALL)->map(fn ($d) => $d['region'])->all(),
      'voices' => [$agent->language => $voices],
      // The Design tab's preview: the button as the website gets it, in the agent's language.
      'design' => (function () use ($agent, $widget, $voice) {
        $l = $agent->language === 'en' ? 'en' : 'ar';
        $female = ($voice['gender'] ?? null) === 'female';
        $states = (array) trans('agents.talk_states', ['name' => $agent->name], $l);
        if ($female && isset($states['speaking_female'])) { $states['speaking'] = $states['speaking_female']; }
        unset($states['speaking_female']);
        return [
          'look' => $widget->style(),
          'palettes' => \App\Models\AgentWidget::PALETTES,
          'defaults' => \App\Models\AgentWidget::DEFAULT_APPEARANCE,
          'rtl' => $l === 'ar',
          'cfg' => [
            'name' => $agent->name, 'business' => $agent->business_name, 'language' => $agent->language, 'dir' => $l === 'ar' ? 'rtl' : 'ltr',
            'text' => [
              'label' => trans('agents.widget_label', [], $l), 'title' => $agent->business_name,
              'subtitle' => trans($female ? 'agents.widget_subtitle_female' : 'agents.widget_subtitle', [], $l),
              'start' => trans('agents.talk_start', [], $l), 'end' => trans('agents.talk_end', [], $l), 'states' => $states,
              'powered' => trans('agents.powered', [], $l), 'mute' => trans('agents.talk_mute', [], $l),
              'unmute' => trans('agents.talk_unmute', [], $l), 'close' => trans('agents.widget_close', [], $l),
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
        'states' => __('agents.talk_states', ['name' => $agent->name]),
        'start' => __('agents.talk_start'), 'end' => __('agents.talk_end'),
        'mute' => __('agents.talk_mute'), 'unmute' => __('agents.talk_unmute'),
        'mic' => __('agents.talk_mic_blocked'), 'you' => __('agents.talk_you'),
        'correct' => __('agents.talk_correct'), 'correct_placeholder' => __('agents.talk_correct_placeholder'),
        'correct_save' => __('agents.talk_correct_save'), 'correct_saved' => __('agents.talk_correct_saved'),
        'saved_contact' => __('agents.talk_saved_contact'), 'copied' => __('agents.live_copied'), 'saved' => __('agents.saved'),
        'play' => __('agents.voice_play'), 'pick' => __('agents.voice_pick'), 'recommended' => __('agents.recommended'),
        'status' => __('agents.status'),
      ],
    ];
  @endphp
  <script>
    window.LISTEN_AGENTS = {!! \Illuminate\Support\Js::from($agentsPage) !!};
  </script>
  <script src="{{ asset('js/vendor/qrcode.js') }}?v={{ filemtime(public_path('js/vendor/qrcode.js')) }}" defer></script>
  <script src="{{ asset('js/agent-call.js') }}?v={{ filemtime(public_path('js/agent-call.js')) }}" defer></script>
  <script src="{{ asset('js/agent-widget.js') }}?v={{ filemtime(public_path('js/agent-widget.js')) }}" defer></script>
  <script src="{{ asset('js/agents.js') }}?v={{ filemtime(public_path('js/agents.js')) }}" defer></script>
</body>
</html>
