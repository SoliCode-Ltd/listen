@include('components.page-head', ['pageTitle' => __('agents.talk_page_title', ['business' => $agent->business_name]), 'page' => 'agent_talk'])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
</head>
<body>
  <main class="ag ag-talkpage" id="agents" style="max-width:none">
    @php $female = ($voice['gender'] ?? null) === 'female'; @endphp
    <section class="ag-card ag-talkpage-card" data-ag-standalone>
      {{-- Who answers: the business, and its assistant's face next to its name (as on the website's call card). --}}
      <header class="ag-talkpage-who">
        <span class="ag-talkpage-face {{ $available ? 'is-live' : '' }}" aria-hidden="true">
          @if(! empty($voice['avatar']))<img src="{{ $voice['avatar'] }}" alt="" width="56" height="56">@else<b>{{ mb_substr($agent->business_name, 0, 1) }}</b>@endif
        </span>
        <div>
          <h1>{{ $agent->business_name }}</h1>
          <p><b>{{ $agent->name }}</b> · {{ __($female ? 'agents.widget_subtitle_female' : 'agents.widget_subtitle') }}</p>
        </div>
      </header>
      @include('agents.partials.talk', ['agent' => $agent, 'avatar' => $voice['avatar'] ?? null, 'showCost' => false, 'female' => ($voice['gender'] ?? null) === 'female', 'callback' => true])
      @unless($available)
        <p class="ag-note">{{ __('agents.error_paused') }}</p>
      @endunless
      <p class="ag-talkpage-mic"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/></svg>{{ __('agents.talk_page_mic') }}</p>
      <p class="ag-talkpage-foot"><a href="{{ url('/') }}" target="_blank" rel="noopener">{{ __('agents.powered') }}</a></p>
      <button type="button" class="ag-talkpage-report" data-ag-report-open>{{ __('agents.report.link') }}</button>
    </section>
  </main>

  {{-- "Report this agent": for a caller who was asked for card numbers or money, or treated badly. --}}
  <dialog class="ag-report" data-ag-report aria-labelledby="ag-report-title">
    <form method="dialog" class="ag-report-form" data-ag-report-form>
      <h2 id="ag-report-title">{{ __('agents.report.title') }}</h2>
      <p>{{ __('agents.report.text') }}</p>
      <fieldset class="ag-report-reasons">
        <legend class="sr-only">{{ __('agents.report.title') }}</legend>
        @foreach(__('agents.report.reasons') as $key => $label)
          <label class="ag-report-reason"><input type="radio" name="reason" value="{{ $key }}" @checked($loop->first)><span>{{ $label }}</span></label>
        @endforeach
      </fieldset>
      <label class="ag-label" for="ag-report-note">{{ __('agents.report.note') }}</label>
      <textarea class="ag-textarea" id="ag-report-note" name="note" rows="3" maxlength="500" dir="auto"></textarea>
      <p class="ag-report-done" data-ag-report-done hidden role="status"></p>
      <div class="ag-report-actions">
        <button type="button" class="ag-btn ag-btn-ghost" data-ag-report-close>{{ __('agents.report.cancel') }}</button>
        <button type="submit" class="ag-btn ag-btn-primary" data-ag-report-send>{{ __('agents.report.send') }}</button>
      </div>
    </form>
  </dialog>
  <script>
    (function () {
      var open = document.querySelector('[data-ag-report-open]'), box = document.querySelector('[data-ag-report]');
      if (!open || !box || !box.showModal) { if (open) open.hidden = true; return; }
      var form = box.querySelector('[data-ag-report-form]'), done = box.querySelector('[data-ag-report-done]'), send = box.querySelector('[data-ag-report-send]');
      open.addEventListener('click', function () { box.showModal(); });
      box.querySelector('[data-ag-report-close]').addEventListener('click', function () { box.close(); });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        send.disabled = true;
        var reason = (form.querySelector('input[name="reason"]:checked') || {}).value || 'other';
        fetch(@json(route('agent-api.report')), { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, credentials: 'omit',
          body: JSON.stringify({ slug: @json($widget->slug), reason: reason, note: form.note.value }) })
          .then(function (r) { return r.json(); })
          .then(function (d) { done.textContent = d.message || ''; done.hidden = false; form.querySelector('.ag-report-reasons').hidden = true; send.hidden = true; open.hidden = true; setTimeout(function () { box.close(); }, 2600); })
          .catch(function () { send.disabled = false; });
      });
    })();
  </script>

  @include('components.scripts', ['noSupportChat' => true])
  @include('components.orb-script')
  @php
    $agentsPage = [
      'mode' => 'link',
      'slug' => $widget->slug,
      'available' => $available,
      'start' => url('/agent-api/calls'),
      // Every line busy: the caller leaves a number to be called back.
      'callback' => url('/agent-api/callbacks'),
      'agent' => ['name' => $agent->name, 'language' => $agent->language, 'gender' => $voice['gender'] ?? null],
      'text' => [
        'states' => \App\Support\Agents\Gendered::lines((array) __('agents.talk_states', ['name' => $agent->name]), ($voice['gender'] ?? null) === 'female'),
        'start' => __('agents.talk_start'), 'end' => __('agents.talk_end'),
        'mic' => __('agents.talk_mic_blocked'), 'you' => __('agents.talk_you'), 'ended_after' => __('agents.talk_ended_after'),
        'paused' => __('agents.error_paused'),
      ] + \App\Http\Controllers\AgentCallController::lineText($agent, app()->getLocale()),
    ];
  @endphp
  <script>
    window.LISTEN_AGENTS = {!! \Illuminate\Support\Js::from($agentsPage) !!};
  </script>
  <script src="{{ asset('js/agent-call.js') }}?v={{ filemtime(public_path('js/agent-call.js')) }}" defer></script>
  <script src="{{ asset('js/agents.js') }}?v={{ filemtime(public_path('js/agents.js')) }}" defer></script>
</body>
</html>
