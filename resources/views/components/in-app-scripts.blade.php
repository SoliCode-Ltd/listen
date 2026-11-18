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
    // The iPhone app: the member chooses where the file goes, so the line does not name a folder.
    $appShell['platform'] = \App\Support\InApp\InApp::platform() ?? 'android';
    // "Sign in with Apple" (iPhone app, signed out): where the page sends Apple's answer, and the
    // one-time value that answer must carry back (App\Services\Auth\AppleSignIn).
    if (! auth()->check() && \App\Support\InApp\InApp::offersAppleSignIn()) {
      $appShell['apple'] = [
        'url' => route('auth.apple.verify', [], false),
        'nonce' => \App\Services\Auth\AppleSignIn::nonce(request()),
      ];
      $appShell['t']['apple_failed'] = __('app.signin.apple_failed');
    }
    if ($appShell['platform'] === 'ios') {
      $appShell['t']['saved'] = __('app.shell.saved_plain');
      // The picture beside the title on the lock screen.
      $appShell['artwork'] = \App\Support\VersionedAsset::url('icon-512.png');
    }
  @endphp
  <script>window.LISTEN_APP = @json($appShell);</script>
  <script src="{{ \App\Support\VersionedAsset::url('js/listen-app.js') }}" defer></script>
@endif
