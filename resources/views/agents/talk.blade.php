@include('components.page-head', ['pageTitle' => __('agents.talk_page_title', ['business' => $agent->business_name]), 'page' => 'agent_talk'])
  <link rel="stylesheet" href="{{ asset('css/agents.css') }}?v={{ filemtime(public_path('css/agents.css')) }}">
</head>
<body>
  {{-- The link page: the very call card the website button opens (js/agent-widget.js), with the
       agent's own look, open in the middle of the page. Only the report link sits under it. --}}
  <main class="ag ag-talkpage" id="agents" style="max-width:none">
    <h1 class="sr-only">{{ $agent->business_name }}</h1>
    <div class="ag-talkpage-call" data-ag-talk-card></div>
    <button type="button" class="ag-talkpage-report" data-ag-report-open>{{ __('agents.report.link') }}</button>
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
  <script>
    window.LISTEN_TALK = {!! \Illuminate\Support\Js::from(['card' => $card, 'slug' => $widget->slug, 'origin' => url('')]) !!};
  </script>
  <script src="{{ asset('js/agent-call.js') }}?v={{ filemtime(public_path('js/agent-call.js')) }}" defer></script>
  <script src="{{ asset('js/agent-widget.js') }}?v={{ filemtime(public_path('js/agent-widget.js')) }}" defer></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var T = window.LISTEN_TALK, into = document.querySelector('[data-ag-talk-card]');
      if (!T || !into || !window.ListenAgentWidget || !window.ListenAgentWidget.page) return;
      var theme = document.documentElement.getAttribute('data-theme');
      var dark = theme ? theme === 'dark' : !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
      window.ListenAgentWidget.page(into, T.card, { origin: T.origin, slug: T.slug, callScript: T.card.assets && T.card.assets.call, dark: dark });
    });
  </script>
</body>
</html>
