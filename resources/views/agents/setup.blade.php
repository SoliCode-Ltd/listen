@include('components.page-head', ['pageTitle' => __('agents.setup_title'), 'page' => 'agents'])
  {{-- The studio's dialect panel (the agent's dialect is picked as in the studio). --}}
  <link rel="stylesheet" href="{{ asset('css/voice-studio.css') }}?v={{ filemtime(public_path('css/voice-studio.css')) }}">
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
  {{-- From one step to a step that needs the page drawn again (publishing an agent made here): a cross-fade. --}}
  <style>@media (prefers-reduced-motion: no-preference) { @view-transition { navigation: auto; } }</style>
  <link rel="stylesheet" href="{{ asset('css/agents-phone.css') }}?v={{ filemtime(public_path('css/agents-phone.css')) }}">
</head>
<body>
  @include('components.header')
  @php
    $arabicUi = app()->getLocale() === 'ar';
    $lang = $agent->language ?? ($arabicUi ? 'ar' : 'en');
    $dialect = $agent->dialect ?? 'ar-EG';
    $voiceAvatar = null;
    $voiceGender = null;
    if ($agent?->speaker_id) {
      foreach (array_merge($voicesAr, $voicesEn) as $v) { if ($v['id'] === $agent->speaker_id) { $voiceAvatar = $v['avatar']; $voiceGender = $v['gender']; } }
    }
  @endphp

  <main class="ag" id="agents">
    <div class="ag-setup" data-ag-setup>
      {{-- A wizard across the page: the way out, then the four steps on one line that fills as you go. --}}
      <header class="ag-wizard-top">
        <div class="ag-wizard-bar">
          <a class="ag-wizard-exit" href="{{ route('voice-agents.index') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>{{ __('agents.title') }}</a>
          <span class="ag-wizard-title">{{ __('agents.setup_title') }}</span>
        </div>
        <nav class="ag-wsteps" aria-label="{{ __('agents.setup_title') }}">
          <span class="ag-bar" aria-hidden="true"><i data-ag-bar></i></span>
          <ol>
            @foreach(['business', 'voice', 'talk', 'live'] as $i => $step)
              <li>
                <button type="button" class="ag-step-link" data-ag-step-link="{{ $i }}" {{ $i > 0 && ! $agent ? 'disabled' : '' }}>
                  <b><span>{{ $i + 1 }}</span></b>{{ __('agents.steps.'.$step) }}
                </button>
              </li>
            @endforeach
          </ol>
        </nav>
      </header>

      <section class="ag-card ag-stagecard">
        {{-- Step 1: the business. --}}
        <div class="ag-pane" data-ag-pane="0">
          <div data-ag-business>
            <div class="ag-pane-head">
              <h2>{{ __('agents.business_heading') }}</h2>
              <p>{{ __('agents.business_hint') }}</p>
            </div>
            <form class="ag-fields" data-ag-business-form novalidate>
              <div class="ag-field">
                <label for="ag-website">{{ __('agents.website_label') }}</label>
                <div class="ag-input-prefix"><span>https://</span><input class="ag-input" id="ag-website" name="website_url" type="text" inputmode="url" autocomplete="url" placeholder="{{ __('agents.website_placeholder') }}" value="{{ $agent?->website_url ? preg_replace('#^https?://#', '', $agent->website_url) : '' }}"></div>
                <span class="ag-hint">{{ __('agents.website_hint') }}</span>
              </div>
              <div class="ag-row">
                <div class="ag-field">
                  <label for="ag-biz">{{ __('agents.business_name_label') }}</label>
                  <input class="ag-input" id="ag-biz" name="business_name" type="text" maxlength="120" required placeholder="{{ __('agents.business_name_placeholder') }}" value="{{ $agent->business_name ?? '' }}">
                </div>
                <div class="ag-field">
                  <label for="ag-name">{{ __('agents.agent_name_label') }}</label>
                  <input class="ag-input" id="ag-name" name="name" type="text" maxlength="80" required placeholder="{{ __('agents.agent_name_placeholder') }}" value="{{ $agent->name ?? '' }}">
                  <span class="ag-hint">{{ __('agents.agent_name_hint') }}</span>
                </div>
              </div>
              <div class="ag-field">
                <span class="ag-label">{{ __('agents.type_label') }}</span>
                <div class="ag-chips" role="radiogroup">
                  @foreach(\App\Models\Agent::BUSINESS_TYPES as $type)
                    <label class="ag-chip"><input type="radio" name="business_type" value="{{ $type }}" {{ ($agent->business_type ?? '') === $type ? 'checked' : '' }}><span>{{ __('agents.types.'.$type) }}</span></label>
                  @endforeach
                </div>
              </div>
              <div class="ag-field">
                <span class="ag-label">{{ __('agents.jobs_label') }}</span>
                <span class="ag-hint">{{ __('agents.jobs_hint') }}</span>
                <div class="ag-chips">
                  @foreach(\App\Models\Agent::JOBS as $job)
                    <label class="ag-chip"><input type="checkbox" name="jobs[]" value="{{ $job }}" {{ in_array($job, $agent->jobs ?? ['answer', 'leads'], true) ? 'checked' : '' }}><span>{{ __('agents.jobs.'.$job) }}</span></label>
                  @endforeach
                </div>
              </div>
              {{-- The customers' language as a small switch beside the dialect card; the note that any
                   voice speaks it runs under both, the form's full width. --}}
              <div class="ag-speaks-block">
                <div class="ag-row ag-row-speaks">
                  <div class="ag-field">
                    <span class="ag-label" id="ag-language-label">{{ __('agents.language_label') }}</span>
                    <div class="ag-seg ag-seg-fit" role="radiogroup" aria-labelledby="ag-language-label">
                      <label><input type="radio" name="language" value="ar" {{ $lang === 'ar' ? 'checked' : '' }}><span>{{ __('agents.language_ar') }}</span></label>
                      <label><input type="radio" name="language" value="en" {{ $lang === 'en' ? 'checked' : '' }}><span>{{ __('agents.language_en') }}</span></label>
                    </div>
                  </div>
                  <div class="ag-field ag-speaks" data-ag-dialect-field @if($lang === 'en') hidden @endif>
                    <span class="ag-label" id="ag-dialect-label">{{ __('agents.dialect_label') }}</span>
                    @include('agents.partials.speaks-pick', ['id' => 'ag-dialect', 'name' => 'dialect', 'labelId' => 'ag-dialect-label', 'english' => false, 'value' => $dialect, 'note' => false])
                  </div>
                  {{-- English agents: any English accent, as Arabic agents speak any Arabic dialect. --}}
                  <div class="ag-field ag-speaks" data-ag-accent-field @if($lang !== 'en') hidden @endif>
                    <span class="ag-label" id="ag-accent-label">{{ __('agents.accent_label') }}</span>
                    @include('agents.partials.speaks-pick', ['id' => 'ag-accent', 'name' => 'accent', 'labelId' => 'ag-accent-label', 'english' => true, 'value' => $agent->dialect ?? null, 'note' => false])
                  </div>
                </div>
                <div data-ag-dialect-note @if($lang === 'en') hidden @endif>@include('agents.partials.speaks-note', ['english' => false, 'faces' => collect($voicesAr)->pluck('avatar')->filter()->take(3)->all()])</div>
                <div data-ag-accent-note @if($lang !== 'en') hidden @endif>@include('agents.partials.speaks-note', ['english' => true, 'faces' => collect($voicesEn)->pluck('avatar')->filter()->take(3)->all()])</div>
              </div>
              <div class="ag-field">
                <label for="ag-notes">{{ __('agents.notes_label') }}</label>
                <textarea class="ag-textarea" id="ag-notes" name="notes" maxlength="8000" placeholder="{{ __('agents.notes_placeholder') }}" dir="auto">{{ $agent ? optional($agent->sources()->where('kind', 'text')->where('title', 'Notes')->first())->content : '' }}</textarea>
                <span class="ag-hint">{{ __('agents.notes_hint') }}</span>
              </div>
              <p class="ag-error" data-ag-error role="alert" hidden></p>
              <div class="ag-actions">
                <a class="ag-btn ag-btn-ghost" href="{{ route('voice-agents.index') }}">{{ __('agents.back') }}</a>
                <button type="submit" class="ag-btn ag-btn-primary ag-btn-lg" data-ag-write>
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.7 4.6 4.8 1.7-4.8 1.7L12 16.1l-1.7-4.6-4.8-1.7 4.8-1.7z"/></svg>{{ __('agents.write_agent') }}
                </button>
              </div>
            </form>
          </div>

          {{-- While the agent is set up: what is happening, a line at a time (the website line only when
               there is a website), then a moment to say it is ready before its voice is chosen. --}}
          <div class="ag-writing" data-ag-writing hidden aria-live="polite">
            <x-orb :name="$agent?->orbName() ?? 'listen'" class="is-live" />
            <h2 data-ag-writing-title data-title="{{ __('agents.writing_title') }}" data-ready="{{ __('agents.writing_ready') }}">{{ __('agents.writing_title', ['name' => $agent->name ?? __('agents.agent_name_placeholder')]) }}</h2>
            <ul class="ag-checks" data-ag-checks>
              @foreach(['read', 'facts', 'first', 'rules', 'collect'] as $k)
                <li data-ag-check="{{ $k }}"><i></i>{{ __('agents.writing_steps.'.$k) }}</li>
              @endforeach
            </ul>
            <p class="ag-hint ag-writing-slow" data-ag-slow data-site="{{ __('agents.writing_slow_site') }}" data-plain="{{ __('agents.writing_slow') }}" hidden></p>
          </div>
        </div>

        {{-- Step 2: the voice. --}}
        <div class="ag-pane" data-ag-pane="1">
          <div class="ag-pane-head">
            <h2>{{ __('agents.voice_heading') }}</h2>
            <p>{{ __('agents.voice_hint') }}</p>
          </div>
          <p class="ag-note" data-ag-site-note hidden>{{ __('agents.website_unreadable') }}</p>
          <div class="ag-voice-tools">
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
          <div class="ag-actions">
            <button type="button" class="ag-btn ag-btn-ghost" data-ag-go="0">{{ __('agents.back') }}</button>
            <button type="button" class="ag-btn ag-btn-primary ag-btn-lg" data-ag-voice-next disabled>{{ __('agents.next') }}</button>
          </div>
        </div>

        {{-- Step 3: talk to it. --}}
        <div class="ag-pane" data-ag-pane="2">
          <div class="ag-pane-head" style="text-align:center">
            <h2 data-ag-talk-title>{{ __('agents.talk_heading', ['name' => $agent->name ?? __('agents.agent_name_placeholder')]) }}</h2>
            <p data-ag-talk-hint data-male="{{ __('agents.talk_hint') }}" data-female="{{ __('agents.talk_hint_female') }}">{{ \App\Support\Agents\Gendered::line('agents.talk_hint', ($voiceGender ?? null) === 'female') }}</p>
          </div>
          @include('agents.partials.talk', ['agent' => $agent, 'avatar' => $voiceAvatar, 'freeTestLeft' => $freeTestLeft, 'perMinute' => $perMinute, 'female' => ($voiceGender ?? null) === 'female', 'testBlock' => $testBlock ?? null])
          <div class="ag-actions">
            <button type="button" class="ag-btn ag-btn-ghost" data-ag-go="1">{{ __('agents.back') }}</button>
            <button type="button" class="ag-btn ag-btn-primary ag-btn-lg" data-ag-go="3">{{ __('agents.next') }}</button>
          </div>
        </div>

        {{-- Step 4: go live. --}}
        <div class="ag-pane" data-ag-pane="3">
          <div class="ag-pane-head">
            <h2>{{ __('agents.live_heading') }}</h2>
            <p>{{ __('agents.live_hint') }}</p>
          </div>
          <div data-ag-golive-slot>
            @if($agent)
              @include('agents.partials.golive', ['agent' => $agent, 'widget' => $widget, 'embed' => $embed])
            @endif
          </div>
          <div class="ag-golive" style="margin-top:1.2rem">
            <div class="ag-success" data-ag-success>
              <svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="29"/><path d="M20 33l8 8 16-17"/></svg>
              <strong>{{ __('agents.live_done') }}</strong>
            </div>
            @if($limits['included'])
              <button type="button" class="ag-btn ag-btn-primary ag-btn-lg" data-ag-golive-btn>{{ __('agents.live_go') }}</button>
            @else
              {{-- A plan without agents: built and tested, and the way to put it live. --}}
              <p class="ag-golive-plan">{{ $upgrade['plan'] ? __('agents.balance.state_plan', ['plan' => $upgrade['plan']]) : __('agents.balance.state_plan_none') }}</p>
              @unless($upgrade['none'])
                <a class="ag-btn ag-btn-primary ag-btn-lg" href="{{ $upgrade['url'] }}">{{ __('agents.balance.upgrade', ['plan' => $upgrade['plan']]) }}</a>
              @endunless
            @endif
          </div>
          <div class="ag-actions">
            <button type="button" class="ag-btn ag-btn-ghost" data-ag-go="2">{{ __('agents.back') }}</button>
            <a class="ag-btn" href="{{ $agent ? route('voice-agents.show', $agent) : route('voice-agents.index') }}" data-ag-open-agent>{{ __('agents.open_agent') }}</a>
          </div>
        </div>
      </section>
    </div>
    <div class="ag-toast" data-ag-toast role="status" aria-live="polite"></div>
  </main>

  @include('components.scripts')
  @include('components.orb-script')
  @php
    $agentsPage = [
      'mode' => 'setup',
      'csrf' => csrf_token(),
      'locale' => app()->getLocale(),
      'agent' => $agent ? [
        'id' => $agent->public_id, 'name' => $agent->name, 'status' => $agent->status, 'language' => $agent->language,
        'dialect' => $agent->dialect, 'speaker_id' => $agent->speaker_id, 'gender' => $voiceGender,
        'urls' => [
          'show' => route('voice-agents.show', $agent), 'update' => route('voice-agents.update', $agent),
          'test' => route('voice-agents.test-call', $agent), 'correct' => route('voice-agents.correct', $agent),
          'write' => route('voice-agents.write', $agent), 'edit' => route('voice-agents.edit', $agent),
        ],
      ] : null,
      'urls' => ['store' => route('voice-agents.store')],
      'regions' => collect(\App\Support\ArabicDialects::ALL)->map(fn ($d) => $d['region'])->all(),
      'voices' => ['ar' => $voicesAr, 'en' => $voicesEn],
      'text' => [
        'states' => __('agents.talk_states'),
        'start' => __('agents.talk_start'), 'end' => __('agents.talk_end'),
        'mic' => __('agents.talk_mic_blocked'), 'network' => __('agents.network_error'), 'you' => __('agents.talk_you'), 'ended_after' => __('agents.talk_ended_after'),
        'correct' => __('agents.talk_correct'), 'correct_placeholder' => __('agents.talk_correct_placeholder'),
        'correct_save' => __('agents.talk_correct_save'), 'correct_saved' => __('agents.talk_correct_saved'),
        'saved_contact' => __('agents.talk_saved_contact'), 'copied' => __('agents.live_copied'), 'saved' => __('agents.saved'),
        'site_remove' => __('agents.live_site_remove'), 'site_remove_short' => __('agents.live_site_remove_short'), 'site_invalid' => __('agents.live_site_invalid'), 'site_exists' => __('agents.live_site_exists'), 'site_max' => __('agents.live_site_max'),
        'play' => __('agents.voice_play'), 'pause' => __('agents.voice_pause'), 'pick' => __('agents.voice_pick'), 'recommended' => __('agents.recommended'),
        'talk_heading' => __('agents.talk_heading'), 'error_name' => __('agents.error_name'),
        'voice_renamed' => __('agents.voice_renamed'), 'voice_renamed_female' => \App\Support\Agents\Gendered::line('agents.voice_renamed', true),
        'balance' => ['upgrade' => __('agents.balance.upgrade')],
      ],
    ];
  @endphp
  <script>
    window.LISTEN_AGENTS = {!! \Illuminate\Support\Js::from($agentsPage) !!};
  </script>
  <script src="{{ asset('js/vendor/qrcode.js') }}?v={{ filemtime(public_path('js/vendor/qrcode.js')) }}" defer></script>
  <script src="{{ asset('js/agent-call.js') }}?v={{ filemtime(public_path('js/agent-call.js')) }}" defer></script>
  @include('agents.partials.speaks-meta', ['english' => null])
  <script src="{{ asset('js/voice-studio-icons.js') }}?v={{ filemtime(public_path('js/voice-studio-icons.js')) }}" defer></script>
  <script src="{{ asset('js/voice-studio.js') }}?v={{ filemtime(public_path('js/voice-studio.js')) }}" defer></script>
  <script src="{{ asset('js/agents.js') }}?v={{ filemtime(public_path('js/agents.js')) }}" defer></script>
</body>
</html>
