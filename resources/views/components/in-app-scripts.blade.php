{{-- The Listen app's own part of every page (public/js/listen-app.js), only inside the app's
     web view: a browser, and the browser tab the app signs members in through, never get it. --}}
@if(\App\Support\InApp\InApp::fromApp())
  @php
    $appRoute = (string) (Route::currentRouteName() ?? '');
    $appShell = [
      'locale' => app()->getLocale() === 'en' ? 'en' : 'ar',
      'signedIn' => auth()->check(),
      'csrf' => csrf_token(),
      'devicesUrl' => route('app.devices.register', [], false),
      // The app's first pages: back leaves the app instead of going to a page before sign-in.
      'root' => in_array($appRoute, ['dashboard', 'demo.page', 'login', 'onboarding.start'], true),
      // Pull to refresh only where nothing typed can be lost.
      'refresh' => in_array($appRoute, ['app.plan', 'samples', 'voice-agents.index', 'voice-agents.show', 'legal.privacy', 'legal.terms', 'legal.cookies'], true),
      't' => __('app.shell') + ['signin_failed' => __('app.signin_failed')],
    ];
  @endphp
  <script>window.LISTEN_APP = @json($appShell);</script>
  <script src="{{ \App\Support\VersionedAsset::url('js/listen-app.js') }}" defer></script>
@endif
