{{-- Extra credits inside the Android app (App\Support\InApp\InApp): nothing is paid in the app,
     so this popup takes the member to the studio's extra-credits window on the website, in the
     phone's browser, already signed in (AppShellController::extraCredits). It answers to the same
     calls as the website's dialog (window.ListenExtraCredits.open(), the "Add credits" buttons,
     opening by itself once when the credits run out), shows no price, and offers no way to pay
     here. The iPhone app never gets it: there nothing may lead to a place to pay. --}}
@if($canBuyExtraCredits ?? false)
@php($xcaOut = (int) ($usageSnapshot['remaining_chars'] ?? 0) < \App\Support\Credits::PER_MINUTE)
<dialog class="xca" id="xcaDialog" tabindex="-1" aria-labelledby="xcaTitle" aria-describedby="xcaText"
  @if(! empty($extraCreditsAutoOpenKey)) data-auto-open="{{ $extraCreditsAutoOpenKey }}" @endif
  data-link-url="{{ route('app.extra-credits') }}">
  <div class="xca-sheet">
    <span class="xca-grip" aria-hidden="true"></span>

    <section class="xca-view" data-xca-view="ask">
      <h2 class="xca-title" id="xcaTitle" data-title-out="{{ __('ui.extra_credits.title_out') }}" data-title-add="{{ __('ui.extra_credits.title_add') }}">{{ $xcaOut ? __('ui.extra_credits.title_out') : __('ui.extra_credits.title_add') }}</h2>
      <p class="xca-text" id="xcaText">{{ __('app.extra_credits.text') }}</p>
      <p class="xca-note" id="xcaNote" role="alert" hidden></p>
      <div class="xca-actions">
        <a class="xca-btn is-primary" id="xcaGo" href="{{ route('app.extra-credits') }}" data-no-prefetch>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg>
          <span>{{ __('app.web.button') }}</span>
        </a>
        <button type="button" class="xca-btn" data-xca-close>{{ __('ui.extra_credits.not_now') }}</button>
      </div>
    </section>

    <section class="xca-view" data-xca-view="away" hidden>
      <h2 class="xca-title">{{ __('app.web.title') }}</h2>
      <p class="xca-text">{{ __('app.extra_credits.away') }}</p>
      <div class="xca-actions">
        {{-- The studio's own reload, which keeps the text being written (dashboard: data-action="reload-page"). --}}
        <button type="button" class="xca-btn is-primary" data-action="reload-page">{{ __('app.extra_credits.refresh') }}</button>
        <button type="button" class="xca-btn" data-xca-close>{{ __('app.extra_credits.close') }}</button>
      </div>
    </section>
  </div>
</dialog>

<style>
  /* A sheet that rises from the bottom of the phone: one title, one sentence, two buttons. */
  .xca:focus { outline: none; }
  .xca { position: fixed; inset: auto 0 0 0; margin: 0; width: 100%; max-width: 520px; margin-inline: auto; padding: 0; border: 0; background: transparent; color: var(--text); overflow: visible; }
  .xca::backdrop { background: rgba(8, 8, 14, .48); opacity: 0; transition: opacity .22s ease-out; }
  .xca[open]::backdrop { opacity: 1; }
  .xca-sheet { background: var(--surface); border-radius: 22px 22px 0 0; padding: 10px 20px calc(20px + env(safe-area-inset-bottom, 0px)); box-shadow: 0 -18px 50px -20px rgba(0, 0, 0, .45);
    transform: translateY(100%); transition: transform .3s cubic-bezier(.2, .8, .2, 1); }
  .xca.is-in .xca-sheet { transform: none; }
  .xca.is-in::backdrop { opacity: 1; }
  .xca-grip { display: block; width: 38px; height: 4px; margin: 0 auto 16px; border-radius: 4px; background: var(--line-strong, var(--line)); }
  .xca-title { margin: 0; font-size: 1.15rem; font-weight: 700; line-height: 1.45; }
  .xca-text { margin: 8px 0 0; color: var(--muted); font-size: .95rem; line-height: 1.75; }
  .xca-note { margin: 12px 0 0; padding: 10px 12px; border-radius: 10px; font-size: .9rem; line-height: 1.6; color: #c1244f; background: color-mix(in oklab, #c1244f 10%, var(--surface)); }
  html:not([data-theme="light"]) .xca-note { color: #ff7a9a; }
  .xca-actions { display: grid; gap: 8px; margin-top: 20px; }
  .xca-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 50px; padding: 0 16px; border-radius: 13px; border: 1px solid var(--line-strong, var(--line));
    background: transparent; color: var(--text); font: inherit; font-size: 1rem; font-weight: 600; text-decoration: none; cursor: pointer; -webkit-tap-highlight-color: transparent; }
  .xca-btn.is-primary { border-color: transparent; background: var(--brand-accent); color: #fff; }
  .xca-btn[aria-busy="true"] { opacity: .7; pointer-events: none; }
  .xca-btn svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
  html[dir="rtl"] .xca-btn svg { transform: scaleX(-1); }
  .xca-btn:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }
  @media (prefers-reduced-motion: reduce) { .xca-sheet, .xca::backdrop { transition: none; } }
</style>

<script>
  (function () {
    const dialog = document.getElementById('xcaDialog');
    if (!dialog) return;
    const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const go = document.getElementById('xcaGo');
    const note = document.getElementById('xcaNote');
    const title = document.getElementById('xcaTitle');
    const failed = @json(__('app.extra_credits.failed'));
    let onClose = null;
    let leftForBrowser = false;

    function showView(name) {
      dialog.querySelectorAll('[data-xca-view]').forEach((view) => { view.hidden = view.dataset.xcaView !== name; });
    }

    // The same call as the website's dialog: the studio, dubbing and voice design use it when a
    // request is refused for credits. It resolves at once, as the dialog does once it is open.
    function open(context = {}) {
      onClose = typeof context.onClose === 'function' ? context.onClose : null;
      const remaining = Number(context.remaining);
      const out = Number.isFinite(remaining) ? remaining < {{ \App\Support\Credits::PER_MINUTE }} : true;
      title.textContent = out ? title.dataset.titleOut : title.dataset.titleAdd;
      note.hidden = true;
      showView(leftForBrowser ? 'away' : 'ask');
      if (!dialog.open) dialog.showModal();
      // The sheet itself takes the focus, so a tap never lights a ring round its first button.
      dialog.focus({ preventScroll: true });
      requestAnimationFrame(() => requestAnimationFrame(() => dialog.classList.add('is-in')));
      return Promise.resolve(true);
    }

    function close() {
      if (!dialog.open) return;
      const done = () => { dialog.close(); dialog.classList.remove('is-in'); };
      dialog.classList.remove('is-in');
      if (reduced()) done(); else setTimeout(done, 280);
      const callback = onClose;
      onClose = null;
      if (callback) callback(false);
    }

    // Opening the browser: the site makes a one-time link that signs the member in there, and the
    // app hands it to the phone's browser (listen-app.js, data-app-open-web). The popup then says
    // to finish there, and offers to refresh the studio once the credits are added.
    go.addEventListener('click', async (event) => {
      event.preventDefault();
      if (go.getAttribute('aria-busy') === 'true') return;
      go.setAttribute('aria-busy', 'true');
      note.hidden = true;
      try {
        const response = await fetch(dialog.dataset.linkUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.url) throw new Error('no link');
        const link = document.createElement('a');
        link.href = data.url;
        link.setAttribute('data-app-open-web', '');
        link.hidden = true;
        document.body.appendChild(link);
        link.click();
        link.remove();
        leftForBrowser = true;
        showView('away');
      } catch (_e) {
        note.textContent = failed;
        note.hidden = false;
      } finally {
        go.removeAttribute('aria-busy');
      }
    });

    // Closed only on purpose ("Not now", "Close", the phone's back gesture): a tap outside the sheet does nothing.
    dialog.addEventListener('click', (event) => {
      if (event.target.closest('[data-xca-close]')) close();
    });
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });

    window.ListenExtraCredits = { open, close, isOpen: () => dialog.open };

    document.addEventListener('click', (event) => {
      const opener = event.target.closest('[data-extra-credits-open]');
      if (!opener) return;
      event.preventDefault();
      open({ remaining: {{ (int) ($usageSnapshot['remaining_chars'] ?? 0) }} });
    });

    // A link with ?extra_credits=1 (the credits-ended email opened in the app), or the credits have
    // run out: the popup opens once by itself, as the website's dialog does, never over something else.
    const params = new URLSearchParams(window.location.search);
    if (params.get('extra_credits') === '1') {
      params.delete('extra_credits');
      const rest = params.toString();
      history.replaceState(null, '', window.location.pathname + (rest ? '?' + rest : '') + window.location.hash);
      window.addEventListener('load', () => open({ remaining: {{ (int) ($usageSnapshot['remaining_chars'] ?? 0) }} }));
    } else if (dialog.dataset.autoOpen) {
      const seenKey = 'listen_xc_seen';
      const seen = () => { try { return window.localStorage.getItem(seenKey) === dialog.dataset.autoOpen; } catch (_e) { return true; } };
      window.addEventListener('load', () => setTimeout(() => {
        const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);
        if (seen() || document.hidden || typing || document.querySelector('dialog[open], [aria-modal="true"]:not([hidden])')) return;
        try { window.localStorage.setItem(seenKey, dialog.dataset.autoOpen); } catch (_e) { return; }
        open({ remaining: 0 });
      }, 1400));
    }
  })();
</script>
@endif
