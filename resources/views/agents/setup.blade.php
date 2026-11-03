@include('components.page-head', ['pageTitle' => __('agents.setup_title'), 'page' => 'agents'])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
</head>
<body>
  @include('components.header')
  @php
    $arabicUi = app()->getLocale() === 'ar';
    $lang = $agent->language ?? ($arabicUi ? 'ar' : 'en');
    $dialect = $agent->dialect ?? 'ar-EG';
    $regions = ['nile' => $arabicUi ? 'مصر والسودان' : 'Egypt & Sudan', 'gulf' => $arabicUi ? 'الخليج' : 'Gulf', 'levant' => $arabicUi ? 'الشام' : 'Levant', 'iraq_yemen' => $arabicUi ? 'العراق واليمن' : 'Iraq & Yemen', 'maghreb' => $arabicUi ? 'المغرب العربي' : 'Maghreb', 'fusha' => $arabicUi ? 'الفصحى' : 'Fusha'];
    $grouped = collect($dialects)->groupBy('region');
    $voiceAvatar = null;
    $voiceGender = null;
    if ($agent?->speaker_id) {
      foreach (array_merge($voicesAr, $voicesEn) as $v) { if ($v['id'] === $agent->speaker_id) { $voiceAvatar = $v['avatar']; $voiceGender = $v['gender']; } }
    }
  @endphp

  <main class="ag" id="agents">
    <div class="ag-setup" data-ag-setup>
      <nav class="ag-card ag-rail" aria-label="{{ __('agents.setup_title') }}">
        <div class="ag-rail-title">{{ __('agents.setup_title') }}</div>
        @foreach(['business', 'voice', 'talk', 'live'] as $i => $step)
          <button type="button" class="ag-step-link" data-ag-step-link="{{ $i }}" {{ $i > 0 && ! $agent ? 'disabled' : '' }}>
            <b><span>{{ $i + 1 }}</span></b>{{ __('agents.steps.'.$step) }}
          </button>
        @endforeach
        <div class="ag-bar"><i data-ag-bar></i></div>
      </nav>

      <section class="ag-card ag-stagecard">
        {{-- Step 1: the business. --}}
        <div class="ag-pane" data-ag-pane="0">
          <div data-ag-business>
            <div class="ag-pane-head">
              <p class="ag-muted" style="font-weight:700;font-size:.86rem">{{ __('agents.step_of', ['n' => 1, 'total' => 4]) }}</p>
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
              <div class="ag-row">
                <div class="ag-field">
                  <span class="ag-label">{{ __('agents.language_label') }}</span>
                  <div class="ag-seg">
                    <label><input type="radio" name="language" value="ar" {{ $lang === 'ar' ? 'checked' : '' }}><span>{{ __('agents.language_ar') }}</span></label>
                    <label><input type="radio" name="language" value="en" {{ $lang === 'en' ? 'checked' : '' }}><span>{{ __('agents.language_en') }}</span></label>
                  </div>
                </div>
                <div class="ag-field" data-ag-dialect-field @if($lang === 'en') hidden @endif>
                  <label for="ag-dialect">{{ __('agents.dialect_label') }}</label>
                  <select class="ag-select" id="ag-dialect" name="dialect">
                    @foreach($regions as $region => $regionLabel)
                      @if($grouped->has($region))
                        <optgroup label="{{ $regionLabel }}">
                          @foreach($grouped[$region] as $d)
                            <option value="{{ $d['code'] }}" {{ $dialect === $d['code'] ? 'selected' : '' }}>{{ $d['label'] }}</option>
                          @endforeach
                        </optgroup>
                      @endif
                    @endforeach
                  </select>
                </div>
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

          {{-- While the agent is written, then the two decisions. --}}
          <div class="ag-writing" data-ag-writing hidden>
            <x-orb :name="$agent?->orbName() ?? 'listen'" class="is-live" />
            <h2>{{ __('agents.writing_title') }}</h2>
            <ul class="ag-checks" data-ag-checks>
              @foreach(['read', 'facts', 'first', 'rules', 'collect'] as $k)
                <li data-ag-check="{{ $k }}"><i></i>{{ __('agents.writing_steps.'.$k) }}</li>
              @endforeach
            </ul>
            <p class="ag-hint" data-ag-slow hidden>{{ __('agents.writing_slow') }}</p>
          </div>
          <div data-ag-decisions-wrap hidden>
            <div class="ag-pane-head">
              <h2>{{ __('agents.decisions_title') }}</h2>
              <p>{{ __('agents.decisions_hint') }}</p>
            </div>
            <p class="ag-note" data-ag-site-note hidden>{{ __('agents.website_unreadable') }}</p>
            <div class="ag-decisions" data-ag-decisions></div>
            <div class="ag-actions">
              <button type="button" class="ag-btn ag-btn-ghost" data-ag-rewrite>{{ __('agents.back') }}</button>
              <button type="button" class="ag-btn ag-btn-primary ag-btn-lg" data-ag-decided>{{ __('agents.next') }}</button>
            </div>
          </div>
        </div>

        {{-- Step 2: the voice. --}}
        <div class="ag-pane" data-ag-pane="1">
          <div class="ag-pane-head">
            <p class="ag-muted" style="font-weight:700;font-size:.86rem">{{ __('agents.step_of', ['n' => 2, 'total' => 4]) }}</p>
            <h2>{{ __('agents.voice_heading') }}</h2>
            <p>{{ __('agents.voice_hint') }}</p>
          </div>
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
            <p class="ag-muted" style="font-weight:700;font-size:.86rem">{{ __('agents.step_of', ['n' => 3, 'total' => 4]) }}</p>
            <h2 data-ag-talk-title>{{ __('agents.talk_heading', ['name' => $agent->name ?? __('agents.agent_name_placeholder')]) }}</h2>
            <p>{{ __('agents.talk_hint') }}</p>
          </div>
          @include('agents.partials.talk', ['agent' => $agent, 'avatar' => $voiceAvatar, 'freeTestLeft' => $freeTestLeft, 'perMinute' => $perMinute])
          <div class="ag-actions">
            <button type="button" class="ag-btn ag-btn-ghost" data-ag-go="1">{{ __('agents.back') }}</button>
            <button type="button" class="ag-btn ag-btn-primary ag-btn-lg" data-ag-go="3">{{ __('agents.next') }}</button>
          </div>
        </div>

        {{-- Step 4: go live. --}}
        <div class="ag-pane" data-ag-pane="3">
          <div class="ag-pane-head">
            <p class="ag-muted" style="font-weight:700;font-size:.86rem">{{ __('agents.step_of', ['n' => 4, 'total' => 4]) }}</p>
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
            <button type="button" class="ag-btn ag-btn-primary ag-btn-lg" data-ag-golive-btn>{{ __('agents.live_go') }}</button>
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
        'mute' => __('agents.talk_mute'), 'unmute' => __('agents.talk_unmute'),
        'mic' => __('agents.talk_mic_blocked'), 'you' => __('agents.talk_you'),
        'correct' => __('agents.talk_correct'), 'correct_placeholder' => __('agents.talk_correct_placeholder'),
        'correct_save' => __('agents.talk_correct_save'), 'correct_saved' => __('agents.talk_correct_saved'),
        'saved_contact' => __('agents.talk_saved_contact'), 'copied' => __('agents.live_copied'), 'saved' => __('agents.saved'),
        'play' => __('agents.voice_play'), 'pick' => __('agents.voice_pick'), 'recommended' => __('agents.recommended'),
        'talk_heading' => __('agents.talk_heading'), 'error_name' => __('agents.error_name'),
      ],
    ];
  @endphp
  <script>
    window.LISTEN_AGENTS = {!! \Illuminate\Support\Js::from($agentsPage) !!};
  </script>
  <script src="{{ asset('js/vendor/qrcode.js') }}?v={{ filemtime(public_path('js/vendor/qrcode.js')) }}" defer></script>
  <script src="{{ asset('js/agent-call.js') }}?v={{ filemtime(public_path('js/agent-call.js')) }}" defer></script>
  <script src="{{ asset('js/agents.js') }}?v={{ filemtime(public_path('js/agents.js')) }}" defer></script>
</body>
</html>
