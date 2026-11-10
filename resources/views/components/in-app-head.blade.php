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
    a[href*="/premium-pack"], a[href*="/free-trial"], a[href$="/developers"] { display: none !important; }
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
    /* The member's app: the tools on a bar at the bottom, as in any phone app. */
    .app-tabs { position: fixed; inset-inline: 0; bottom: 0; z-index: 950; display: flex; align-items: stretch;
      background: var(--surface); border-top: 1px solid var(--line); padding-bottom: env(safe-area-inset-bottom, 0px);
      view-transition-name: la-tabs; }
    ::view-transition-group(la-tabs) { animation: none; }
    ::view-transition-old(la-tabs) { display: none; }
    ::view-transition-new(la-tabs) { animation: none; }
    .app-tab { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .25rem;
      min-height: 62px; padding: .5rem .25rem; color: var(--muted); font-size: .8rem; font-weight: 600; line-height: 1.4; text-decoration: none;
      -webkit-user-select: none; user-select: none; }
    .app-tab svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .app-tab span { max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .app-tab.is-on { color: var(--brand-accent); }
    html:not([data-theme="light"]) .app-tab.is-on { color: var(--brand-subtle, #A99CFF); }
    html:has(.app-tabs) body { padding-bottom: calc(62px + env(safe-area-inset-bottom, 0px)); }
    /* The bar switches between Studio and Agents, so the pages' own switch is not shown twice. */
    html:has(.app-tabs) .app-switch { display: none !important; }
    /* The studio's own sections (create, design, cloning...) sit in one strip under the header,
       so the bottom of the screen has a single bar. */
    @media (max-width: 640px) {
      html:has(.app-tabs) .dashboard-menu,
      html[dir="rtl"]:has(.app-tabs) .dashboard-menu { inset: var(--la-top, 65px) 0 auto 0; z-index: 96; border-top: 0;
        border-bottom: 1px solid var(--line); background: color-mix(in oklab, var(--bg) 92%, transparent); }
      html:has(.app-tabs) .dashboard-menu .dashboard-menu-list { display: flex !important; flex-wrap: nowrap; gap: .25rem !important; padding: .35rem .6rem; overflow-x: auto; scrollbar-width: none; }
      html:has(.app-tabs) .dashboard-menu .dashboard-menu-list::-webkit-scrollbar { display: none; }
      html:has(.app-tabs) .dashboard-menu .dm-short { max-width: none; overflow: visible; }
      html:has(.app-tabs) .dashboard-menu .dashboard-menu-link { flex: none; width: auto; min-width: max-content; flex-direction: row; gap: .4rem; min-height: 42px; padding: .3rem .8rem; font-size: .88rem; border-radius: 999px; }
      html:has(.app-tabs) .dashboard-menu .dashboard-menu-link svg { width: 18px; height: 18px; flex: none; }
      html:has(.app-tabs) .dashboard-menu .dashboard-menu-link.active { background: color-mix(in oklab, var(--brand-accent) 14%, transparent); }
      html:has(.app-tabs) .dashboard-shell { padding-top: 3.4rem !important; padding-bottom: 1.5rem !important; }
      html:has(.app-tabs) #generatedAudioWrap.output-dock { bottom: calc(62px + .75rem + env(safe-area-inset-bottom, 0px)); }
    }
    .app-header.is-app .header-center { display: none; }
    .app-header.is-app .header-inner { justify-content: space-between; }
    .app-header.is-app .locale-switch { display: none; }
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
