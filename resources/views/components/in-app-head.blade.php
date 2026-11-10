{{-- Inside the Listen app (App\Support\InApp\InApp) only. --}}
@inapp
  <meta name="listen-in-app" content="1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <style id="listen-in-app-style">
    /* Inside the app every purchase link is the plan page's (AppServiceProvider): only the menu
       shows that link, so an "upgrade" button a page or a script still draws never appears. */
    a[href*="/app/plan"]:not([data-in-app-plan]),
    a[href*="/pricing"], a[href*="/billing"], a[href*="/manage-subscription"],
    a[href*="/premium-pack"], a[href*="/free-trial"] { display: none !important; }
    /* The app is one hand on a phone: no hover-only chrome, no text selection on controls. */
    html { -webkit-tap-highlight-color: transparent; overscroll-behavior-y: none; }
    button, .btn, .nav-link { -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; }
    .in-app-note { color: var(--muted); font-size: .92rem; line-height: 1.7; margin: .6rem 0 0; }
  </style>
@endinapp
