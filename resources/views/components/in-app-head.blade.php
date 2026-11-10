{{-- Inside the Listen app (App\Support\InApp\InApp) only. --}}
@if(\App\Support\InApp\InApp::active())
  <meta name="listen-in-app" content="1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <style id="listen-in-app-style">
    @if(\App\Support\InApp\InApp::hidesPlans())
    /* Inside the app every purchase link is the account page's (AppServiceProvider): only the
       menu shows that link, so an "upgrade" button a page or a script still draws never appears. */
    a[href*="/app/plan"]:not([data-in-app-plan]),
    a[href*="/pricing"], a[href*="/billing"], a[href*="/manage-subscription"],
    a[href*="/premium-pack"], a[href*="/free-trial"] { display: none !important; }
    @endif
    /* The app is one hand on a phone: no hover-only chrome, no text selection on controls. */
    html { -webkit-tap-highlight-color: transparent; overscroll-behavior-y: none; }
    button, .btn, .nav-link { -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; }
    .in-app-note { color: var(--muted); font-size: .92rem; line-height: 1.7; margin: .6rem 0 0; }

    /* Moving between pages: the old page stays until the new one is ready, then one short
       cross-fade with the header held still, so it reads as one app and not as pages loading. */
    @view-transition { navigation: auto; }
    ::view-transition-old(root) { animation: 110ms ease-out both la-page-out; }
    ::view-transition-new(root) { animation: 190ms ease-out both la-page-in; }
    @keyframes la-page-out { to { opacity: 0; } }
    @keyframes la-page-in { from { opacity: 0; transform: translateY(6px); } }
    .app-header { view-transition-name: la-header; }
    ::view-transition-group(la-header) { animation: none; }
    ::view-transition-old(la-header) { display: none; }
    ::view-transition-new(la-header) { animation: none; }
    @media (prefers-reduced-motion: reduce) {
      ::view-transition-old(root), ::view-transition-new(root) { animation: none; }
    }
    /* A tap answers at once, before the next page has come. */
    a:active, button:active, .btn:active { opacity: .72; transition: opacity 0s; }
  </style>
  {{-- The next page starts loading as the finger comes down on its link, not when it lifts.
       Never a page that signs out, pays, downloads or hands over to the browser. --}}
  @if(\App\Support\InApp\InApp::fromApp())
  <script type="speculationrules">
  {"prefetch":[{"where":{"and":[{"href_matches":"/*"},{"not":{"href_matches":["/*/logout*","/logout*","/app/*","/*/app/signin/*","/*/billing*","/*/manage-subscription*","/*/free-trial*","/*/premium-pack*","/*/download*","/audio-download*","/media/*","/admin*","/*/auth/*","/*/email/*"]}},{"not":{"selector_matches":"[download], [target=_blank], [data-no-prefetch]"}}]},"eagerness":"moderate"}]}
  </script>
  @endif
@endif
