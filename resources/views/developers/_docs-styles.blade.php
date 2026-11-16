  <style>
    /*
     * Developer documentation.
     *
     * The page reads in four parts (getting started, guides, the API reference by resource,
     * then errors and limits), each opened by its own heading. The contents stay beside it
     * on wide screens, and fold into a bar at the top below 1200 px.
     *
     * Code is a deep violet ink in both themes, so an example reads the same day or night.
     * Colour carries meaning only: violet for POST, green for GET and answers that worked,
     * amber for 4xx, red for DELETE, 5xx and what is required.
     *
     * The one bold moment is the top: a real request beside its answer, the voice it
     * returned and the headers it came with. Everything else stays quiet and exact.
     */
    .docs-wrap {
      --ink: #15122a; --ink-head: #1c1836; --ink-raised: #1f1a3d; --ink-line: rgba(255, 255, 255, .08);
      --ink-text: #eceaf7; --ink-muted: #a29dc2; --lilac: #c9b8ff;
      --violet: var(--a, #7659fa); --get: #2fbf8a; --warn: #e0a100; --stop: #e5484d;
      --line-soft: color-mix(in oklab, var(--line) 65%, transparent);
      /* Arabic in code (a request's text) takes the site's own font, joined as it should be:
         the monospace font has no Arabic letters, and other monospace fonts draw them apart. */
      --mono: 'JetBrains Mono', var(--site-font, system-ui), ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      --docs-top: 64px;
      --measure: 44rem;
      width: min(1520px, calc(100% - 3rem)); margin: 0 auto; padding: 1.25rem 0 6rem;
      display: grid; grid-template-columns: 248px minmax(0, 1fr); gap: 3.5rem; align-items: start;
    }
    /* The site's font rule skips code and .mono: they take the site's own monospace font. */
    .docs-wrap code, .docs-wrap pre, .docs-wrap .mono, .docs-method, .docs-status, .code-tag { font-family: var(--mono); }
    .docs-main { min-width: 0; }
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }

    /* ── Contents ── */
    .docs-rail { position: sticky; top: calc(var(--docs-top) + 20px); }
    .docs-rail > .docs-nav { max-height: calc(100vh - var(--docs-top) - 36px); overflow-y: auto; padding: .25rem .5rem 2.5rem 0; scrollbar-width: thin; }
    html[dir="rtl"] .docs-rail > .docs-nav { padding: .25rem 0 2.5rem .5rem; }
    .docs-search { position: relative; margin-bottom: 1.5rem; }
    .docs-search input {
      width: 100%; height: 40px; padding: 0 .85rem 0 2.2rem; border-radius: 10px; border: 1px solid var(--line);
      background: var(--surface); color: var(--text); font: inherit; font-size: .9rem;
    }
    html[dir="rtl"] .docs-search input { padding: 0 2.2rem 0 .85rem; }
    .docs-search input:focus { outline: none; border-color: var(--violet); box-shadow: 0 0 0 3px color-mix(in oklab, var(--violet) 18%, transparent); }
    .docs-search svg { position: absolute; inset-inline-start: .75rem; top: 50%; width: 15px; height: 15px; transform: translateY(-50%); stroke: var(--muted); fill: none; stroke-width: 2; }
    .docs-nav-group { margin-bottom: 1.6rem; }
    .docs-nav-title { display: block; margin: 0 0 .4rem; padding: 0 .75rem; font-size: .82rem; font-weight: 650; color: var(--text); }
    .docs-nav a {
      position: relative; display: flex; align-items: baseline; gap: .6rem; padding: .33rem .75rem; border-radius: 7px;
      color: var(--muted); font-size: .9rem; line-height: 1.4; text-decoration: none;
    }
    .docs-nav a:hover { color: var(--text); background: color-mix(in oklab, var(--text) 5%, transparent); }
    .docs-nav a:focus-visible { outline: 2px solid var(--violet); outline-offset: -2px; }
    .docs-nav a.is-active { color: var(--text); font-weight: 600; background: color-mix(in oklab, var(--violet) 10%, transparent); }
    .docs-nav a.is-active::before { content: ""; position: absolute; inset-block: .35rem; inset-inline-start: 0; width: 2px; border-radius: 2px; background: var(--violet); }
    .docs-nav-resource { margin: 0 0 .55rem; }
    .docs-nav .docs-nav-resource-name { color: var(--text); font-weight: 600; }
    .docs-nav .docs-nav-endpoint { padding-inline-start: 1.05rem; font-size: .86rem; }
    .docs-nav .docs-method { flex: none; min-width: 2.85rem; font-size: .58rem; padding: .12rem .25rem; transform: translateY(-1px); }
    .docs-nav-empty { display: none; padding: .4rem .75rem; color: var(--muted); font-size: .88rem; }

    /* Below 1200 px: the contents in a bar at the top, and a sheet over the page. */
    .docs-bar { display: none; position: sticky; top: var(--docs-top); z-index: 40; margin: 0 -1.5rem; padding: .55rem 1.5rem; background: color-mix(in oklab, var(--bg) 90%, transparent); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-bottom: 1px solid var(--line); }
    .docs-bar-button {
      width: 100%; display: flex; align-items: center; gap: .6rem; min-height: 44px; padding: 0 .9rem; border-radius: 11px;
      border: 1px solid var(--line); background: var(--surface); color: var(--text); font: inherit; font-size: .95rem; font-weight: 650; cursor: pointer;
    }
    .docs-bar-button:focus-visible { outline: 2px solid var(--violet); outline-offset: 2px; }
    .docs-bar-button svg { flex: none; width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .docs-bar-current { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--muted); font-weight: 500; text-align: start; }
    .docs-bar-current:not(:empty)::before { content: "/"; margin-inline-end: .55rem; color: var(--line); }
    .docs-bar-chevron { color: var(--muted); }
    .docs-sheet { position: fixed; inset: 0; z-index: 90; display: flex; background: rgba(10, 8, 24, .5); }
    .docs-sheet[hidden] { display: none; }
    .docs-sheet-card { display: flex; flex-direction: column; width: min(380px, 90vw); height: 100%; background: var(--bg); border-inline-end: 1px solid var(--line); box-shadow: 0 0 60px -10px rgba(0, 0, 0, .45); animation: docs-sheet-in .22s cubic-bezier(.2, .7, .2, 1) both; }
    .docs-sheet-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .9rem .75rem .5rem 1.1rem; font-size: 1.02rem; font-weight: 700; color: var(--text); }
    html[dir="rtl"] .docs-sheet-head { padding: .9rem 1.1rem .5rem .75rem; }
    .docs-sheet-close { display: grid; place-items: center; width: 42px; height: 42px; border: 0; border-radius: 10px; background: transparent; color: var(--text); cursor: pointer; }
    .docs-sheet-close:hover { background: color-mix(in oklab, var(--text) 7%, transparent); }
    .docs-sheet-close:focus-visible { outline: 2px solid var(--violet); outline-offset: -2px; }
    .docs-sheet-close svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
    .docs-sheet .docs-nav { flex: 1; min-height: 0; overflow-y: auto; padding: .4rem .75rem 2.5rem; }
    .docs-sheet .docs-nav a { padding-block: .5rem; font-size: .95rem; }
    .docs-sheet .docs-nav .docs-nav-endpoint { font-size: .92rem; }
    html.docs-sheet-open, html.docs-sheet-open body { overflow: hidden; }
    @keyframes docs-sheet-in { from { opacity: 0; transform: translateX(-18px); } to { opacity: 1; transform: none; } }
    html[dir="rtl"] .docs-sheet-card { animation-name: docs-sheet-in-rtl; }
    @keyframes docs-sheet-in-rtl { from { opacity: 0; transform: translateX(18px); } to { opacity: 1; transform: none; } }

    /* ── The top: what Listen is, then a request beside its answer ── */
    .docs-hero { padding: 2.5rem 0 0; }
    .docs-hero-intro { max-width: 48rem; }
    .docs-hero h1 { margin: 0 0 1rem; font-size: clamp(2.3rem, 3.3vw, 3.2rem); font-weight: 760; letter-spacing: -.035em; line-height: 1.06; color: var(--text); }
    html[dir="rtl"] .docs-hero h1 { letter-spacing: 0; line-height: 1.3; }
    .docs-lede { margin: 0; max-width: 40em; color: var(--muted); font-size: 1.16rem; line-height: 1.65; }
    .docs-hero-actions { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; margin-top: 1.75rem; }
    .docs-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-height: 46px; padding: 0 1.2rem;
      border-radius: var(--btn-radius, 12px); border: 1px solid var(--line); background: var(--surface); color: var(--text);
      font-weight: 650; font-size: .96rem; text-decoration: none; transition: border-color .2s ease, background-color .2s ease;
    }
    .docs-btn:hover { border-color: color-mix(in oklab, var(--text) 30%, var(--line)); }
    .docs-btn.primary { background: var(--brand-accent, var(--violet)); border-color: transparent; color: #fff; }
    .docs-btn.primary:hover { background: var(--brand-hover, var(--violet)); }
    .docs-btn:focus-visible { outline: 2px solid var(--violet); outline-offset: 3px; }
    .docs-base { display: inline-flex; align-items: center; gap: .55rem; flex-wrap: wrap; margin-inline-start: .6rem; font-size: .92rem; color: var(--muted); }
    .docs-base code { padding: .32rem .6rem; border-radius: 8px; border: 1px solid var(--line); background: var(--surface); color: var(--text); font-size: .86rem; direction: ltr; unicode-bidi: isolate; }

    .docs-console {
      display: grid; grid-template-columns: minmax(0, 1.18fr) minmax(0, .82fr); margin-top: 2.5rem;
      border-radius: 16px; overflow: hidden; background: var(--ink); border: 1px solid var(--ink-line);
      box-shadow: 0 46px 80px -50px rgba(21, 18, 42, .75); direction: ltr; text-align: left;
    }
    .docs-console-pane { min-width: 0; display: flex; flex-direction: column; }
    .docs-console-pane + .docs-console-pane { border-left: 1px solid var(--ink-line); }
    .docs-console .code-panel { flex: 1; display: flex; flex-direction: column; border: 0; border-radius: 0; box-shadow: none; background: transparent; }
    /* This example is read, not scrolled: its long lines wrap. */
    .docs-console .code-body { flex: 1; max-height: none; white-space: pre-wrap; overflow-wrap: anywhere; }
    /* The answer's body under its headers: the recording, in a quiet strip at the foot of the
       pane, level with the end of the request beside it. */
    .docs-console .is-response .code-body { flex: 0 0 auto; padding-bottom: .5rem; }
    .docs-answer {
      display: grid; gap: .7rem; margin: auto 1.15rem 1.15rem; padding: .8rem .9rem .75rem;
      border: 1px solid var(--ink-line); border-radius: 12px; background: rgba(255, 255, 255, .035); color: var(--ink-text);
    }
    .docs-answer-player { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: .85rem; }
    .docs-play {
      width: 38px; height: 38px; border-radius: 50%; border: 0; display: grid; place-items: center; cursor: pointer;
      background: var(--lilac); color: var(--ink); transition: background-color .15s ease, transform .15s ease;
    }
    .docs-play:hover { background: #ddd2ff; }
    .docs-play:active { transform: scale(.96); }
    .docs-play:focus-visible { outline: 2px solid var(--lilac); outline-offset: 3px; }
    .docs-play svg { width: 16px; height: 16px; fill: currentColor; }
    .docs-play .i-play { margin-left: 2px; }
    .docs-play .i-pause { display: none; }
    .docs-play[aria-pressed="true"] .i-play { display: none; }
    .docs-play[aria-pressed="true"] .i-pause { display: block; }
    .docs-wave { --p: 0%; position: relative; height: 34px; min-width: 0; overflow: hidden; cursor: pointer; }
    .docs-wave-bars { position: absolute; inset: 0; display: flex; align-items: center; justify-content: space-between; gap: 2px; pointer-events: none; }
    .docs-wave-bars i { flex: 1 1 0; min-width: 2px; max-width: 4px; border-radius: 2px; background: rgba(255, 255, 255, .24); }
    .docs-wave-bars.is-played { clip-path: inset(0 calc(100% - var(--p)) 0 0); }
    .docs-wave-bars.is-played i { background: var(--lilac); }
    .docs-wave-head { position: absolute; top: 3px; bottom: 3px; left: var(--p); width: 2px; margin-left: -1px; border-radius: 1px; background: #fff; opacity: 0; transition: opacity .2s ease; pointer-events: none; }
    .docs-answer.is-playing .docs-wave-head, .docs-answer.is-started .docs-wave-head { opacity: .8; }
    .docs-answer-time { min-width: 2.4rem; font-size: .78rem; color: var(--ink-muted); font-variant-numeric: tabular-nums; text-align: right; }
    .docs-answer-about { display: flex; align-items: center; justify-content: space-between; gap: .75rem; min-width: 0; padding-top: .65rem; border-top: 1px solid var(--ink-line); }
    .docs-answer-voice { display: inline-flex; align-items: center; gap: .5rem; min-width: 0; font-size: .86rem; font-weight: 600; color: var(--ink-text); }
    .docs-answer-voice > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    html[dir="rtl"] .docs-answer-voice > span { direction: rtl; unicode-bidi: isolate; }
    .docs-answer-voice img { flex: none; width: 22px; height: 22px; border-radius: 50%; object-fit: cover; background: rgba(255, 255, 255, .1); }
    .docs-answer-file { flex: none; display: inline-flex; gap: .6rem; font-size: .74rem; color: var(--ink-muted); }

    /* ── The four parts ── */
    .docs-part { margin: 5.5rem 0 0; padding: 2.6rem 0 .75rem; border-top: 1px solid var(--line); scroll-margin-top: calc(var(--docs-top) + 24px); }
    .docs-part h2 { margin: 0; font-size: clamp(1.95rem, 2.5vw, 2.45rem); font-weight: 760; letter-spacing: -.03em; line-height: 1.12; color: var(--text); }
    html[dir="rtl"] .docs-part h2 { letter-spacing: 0; line-height: 1.3; }
    .docs-part p { margin: .65rem 0 0; max-width: var(--measure); color: var(--muted); font-size: 1.1rem; line-height: 1.6; }

    /* ── Sections: prose, with its code beside it ── */
    .docs-section { padding: 2.75rem 0; scroll-margin-top: calc(var(--docs-top) + 24px); }
    .docs-section + .docs-section { border-top: 1px solid var(--line-soft); }
    .docs-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.04fr); gap: 3rem; align-items: start; }
    .docs-text { min-width: 0; max-width: var(--measure); }
    .docs-text.is-wide { max-width: none; }
    .docs-measure { max-width: var(--measure); }
    .docs-code { min-width: 0; position: sticky; top: calc(var(--docs-top) + 28px); display: grid; gap: 1rem; }
    .docs-section h3, .docs-resource h3 { margin: 0 0 .9rem; font-size: 1.6rem; font-weight: 720; letter-spacing: -.022em; line-height: 1.25; color: var(--text); }
    .docs-text h4 { margin: 2rem 0 .5rem; font-size: 1.06rem; font-weight: 680; color: var(--text); }
    html[dir="rtl"] :is(.docs-section h3, .docs-resource h3, .docs-text h4) { letter-spacing: 0; }
    .docs-section p, .docs-section li, .docs-resource > p { color: var(--muted); line-height: 1.75; font-size: 1.04rem; }
    .docs-section p { margin: 0 0 1rem; }
    .docs-section ul, .docs-section ol { margin: .25rem 0 1.1rem; padding-inline-start: 1.25rem; }
    .docs-section ul { list-style: disc; }
    .docs-section ul li::marker { color: color-mix(in oklab, var(--violet) 70%, var(--muted)); }
    .docs-section li { margin: .4rem 0; }
    .docs-section a:not(.docs-btn):not(.docs-flag), .docs-resource > p a { color: var(--text); text-decoration-color: color-mix(in oklab, var(--violet) 55%, transparent); text-underline-offset: 3px; }
    .docs-anchor { margin-inline-start: .45rem; color: var(--muted) !important; text-decoration: none !important; opacity: 0; font-weight: 400; transition: opacity .15s ease; }
    :is(h3, h4):hover > .docs-anchor, .docs-anchor:focus-visible { opacity: 1; }

    .docs-text code, .docs-table code, .docs-index code, .docs-resource code {
      font-size: .86em; padding: .1rem .38rem; border-radius: 6px; white-space: nowrap;
      background: color-mix(in oklab, var(--violet) 10%, transparent); color: var(--text);
      direction: ltr; unicode-bidi: isolate;
    }
    .docs-note { margin: 1.25rem 0; padding: .95rem 1.1rem; border-radius: 12px; border-inline-start: 3px solid var(--violet); background: color-mix(in oklab, var(--violet) 6%, var(--surface)); color: var(--text) !important; font-size: .98rem !important; }
    .docs-note.warn { border-inline-start-color: var(--warn); background: color-mix(in oklab, var(--warn) 8%, var(--surface)); }
    .docs-steps { counter-reset: step; list-style: none !important; padding: 0 !important; }
    .docs-steps li { position: relative; padding-inline-start: 2.6rem; margin: 0 0 1.1rem !important; }
    .docs-steps li::before { counter-increment: step; content: counter(step); position: absolute; inset-inline-start: 0; top: .2rem; width: 1.75rem; height: 1.75rem; border-radius: 50%; display: grid; place-items: center; font-size: .82rem; font-weight: 700; color: var(--accent-text, var(--violet)); border: 1px solid color-mix(in oklab, var(--violet) 38%, var(--line)); }

    /* ── API reference: each resource opens with its endpoints, each endpoint is one block ── */
    .docs-resource { padding: 2.9rem 0 1.25rem; scroll-margin-top: calc(var(--docs-top) + 24px); }
    .docs-resource > p { max-width: var(--measure); margin: 0 0 1.35rem; }
    .docs-index { list-style: none; margin: 0; padding: 0; max-width: 54rem; border: 1px solid var(--line); border-radius: 12px; overflow: hidden; background: var(--surface); }
    .docs-index li + li { border-top: 1px solid var(--line); }
    .docs-index a { display: grid; grid-template-columns: 3.6rem auto minmax(0, 1fr); align-items: center; gap: .85rem; padding: .7rem 1rem; color: var(--text); text-decoration: none; }
    .docs-index a:hover { background: color-mix(in oklab, var(--violet) 6%, transparent); }
    .docs-index a:focus-visible { outline: 2px solid var(--violet); outline-offset: -2px; }
    .docs-index code { padding: 0; background: none; font-size: .9rem; font-weight: 500; overflow: hidden; text-overflow: ellipsis; }
    .docs-index-name { min-width: 0; color: var(--muted); font-size: .94rem; text-align: end; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .docs-endpoint { border-top: 1px solid var(--line-soft); }
    .docs-text .docs-endpoint-title { margin: 0 0 .8rem; font-size: 1.34rem; font-weight: 720; letter-spacing: -.015em; line-height: 1.3; }
    .docs-route { display: inline-flex; align-items: center; gap: .65rem; max-width: 100%; margin: 0 0 1.1rem; padding: .42rem .8rem .42rem .45rem; border-radius: 10px; border: 1px solid var(--line); background: var(--surface); direction: ltr; overflow-x: auto; scrollbar-width: none; }
    .docs-route code { padding: 0; background: none; font-size: .94rem; font-weight: 500; color: var(--text); white-space: nowrap; }
    .docs-method { display: inline-flex; justify-content: center; align-items: center; min-width: 3.4rem; padding: .22rem .4rem; border-radius: 5px; font-size: .68rem; font-weight: 700; letter-spacing: .02em; line-height: 1.2; direction: ltr; }
    .docs-method.m-post { background: color-mix(in oklab, var(--violet) 16%, transparent); color: var(--accent-text, var(--violet)); }
    .docs-method.m-get { background: color-mix(in oklab, var(--get) 16%, transparent); color: color-mix(in oklab, var(--get) 72%, var(--text)); }
    .docs-method.m-delete { background: color-mix(in oklab, var(--stop) 15%, transparent); color: color-mix(in oklab, var(--stop) 80%, var(--text)); }
    .docs-meta { font-size: .95rem !important; }

    /* Parameters: one compact row each, the name and its kind on the first line. */
    .docs-params-block { margin-top: 1.9rem; }
    .docs-params-title { margin: 0 0 .55rem; font-size: .98rem; font-weight: 680; color: var(--text); }
    .docs-params { margin: 0 0 .5rem; padding: 0; border-top: 1px solid var(--line); }
    .docs-param { padding: .78rem 0; border-bottom: 1px solid var(--line-soft); }
    .docs-param dt { display: flex; align-items: baseline; flex-wrap: wrap; gap: .2rem .6rem; margin: 0 0 .25rem; }
    .docs-param .docs-param-name, .docs-param dt code { padding: 0; background: none; font-size: .93rem; font-weight: 600; color: var(--text); white-space: normal; overflow-wrap: anywhere; }
    .docs-param-parent { color: var(--muted); font-weight: 400; }
    .docs-param dt time { font-family: var(--mono); font-size: .9rem; font-weight: 600; color: var(--text); }
    .docs-type { font-size: .82rem; color: var(--muted); }
    .docs-flag { font-size: .78rem; font-weight: 600; color: var(--muted); }
    .docs-flag.req { color: color-mix(in oklab, var(--stop) 78%, var(--text)); }
    .docs-flag.plan { padding: .05rem .5rem; border-radius: 999px; color: var(--accent-text, var(--violet)); background: color-mix(in oklab, var(--violet) 11%, transparent); text-decoration: none; }
    .docs-param dd { margin: 0; color: var(--muted); font-size: .97rem; line-height: 1.65; }

    /* Tables: the plans and the errors. */
    .docs-table-wrap { position: relative; overflow-x: auto; margin: .5rem 0 1.1rem; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); }
    .docs-table { width: 100%; border-collapse: collapse; font-size: .95rem; }
    .docs-table th, .docs-table td { padding: .72rem .95rem; text-align: start; vertical-align: top; border-bottom: 1px solid var(--line); line-height: 1.6; }
    .docs-table tbody tr:last-child > * { border-bottom: 0; }
    .docs-table thead th { font-size: .86rem; font-weight: 650; color: var(--text); background: color-mix(in oklab, var(--text) 3%, var(--surface)); }
    .docs-table td { color: var(--muted); }
    .docs-table td:first-child { color: var(--text); white-space: nowrap; }
    .docs-status { display: inline-block; min-width: 2.6rem; padding: .08rem .4rem; border-radius: 5px; text-align: center; font-size: .78rem; font-weight: 700; color: color-mix(in oklab, var(--warn) 78%, var(--text)); background: color-mix(in oklab, var(--warn) 13%, transparent); }
    .docs-status.s5 { color: color-mix(in oklab, var(--stop) 80%, var(--text)); background: color-mix(in oklab, var(--stop) 13%, transparent); }
    .docs-plans th[scope="row"] { position: sticky; inset-inline-start: 0; z-index: 1; font-weight: 500; color: var(--muted); white-space: nowrap; background: var(--surface); }
    .docs-plans thead th:first-child { position: sticky; inset-inline-start: 0; z-index: 2; }
    .docs-plans thead th:not(:first-child), .docs-plans td { text-align: center; }
    .docs-plans td { color: var(--text) !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .docs-plans .docs-plans-group th { padding-top: 1.1rem; font-weight: 650; color: var(--text); background: color-mix(in oklab, var(--text) 3%, var(--surface)); }
    .docs-yes, .docs-no { position: relative; display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; }
    .docs-yes { background: color-mix(in oklab, var(--violet) 15%, transparent); color: var(--accent-text, var(--violet)); }
    .docs-yes svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; }
    .docs-no { color: var(--muted); }
    .docs-no::before { content: ""; width: 10px; height: 2px; border-radius: 1px; background: currentColor; opacity: .55; }
    .docs-formats code { margin: 0 .12rem; }
    .docs-errors-wrap { margin-top: 1.9rem; }
    .docs-errors td:first-child { width: 4.5rem; }
    .docs-errors td:nth-child(2) { width: 1%; white-space: nowrap; }

    .docs-envs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 1.5rem; margin: .25rem 0 1rem; }
    .docs-env-group { grid-column: 1 / -1; margin: 1.2rem 0 .2rem; font-size: .95rem; font-weight: 650; color: var(--text); }
    .docs-env { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; padding: .55rem 0; border-bottom: 1px solid var(--line-soft); }
    .docs-env b { display: block; font-weight: 600; font-size: .95rem; color: var(--text); }
    .docs-env span { display: block; font-size: .88rem; color: var(--muted); line-height: 1.5; }
    .docs-env code { flex: none; }

    /* ── Code panels ── */
    .code-panel { background: var(--ink); border: 1px solid var(--ink-line); border-radius: 14px; overflow: hidden; direction: ltr; text-align: left; box-shadow: 0 24px 48px -34px rgba(21, 18, 42, .6); }
    .code-head { display: flex; align-items: center; gap: .25rem; min-height: 44px; padding: 0 .45rem; background: var(--ink-head); border-bottom: 1px solid var(--ink-line); }
    .code-tabs { display: flex; gap: .1rem; overflow-x: auto; scrollbar-width: none; flex: 1; min-width: 0; }
    .code-tabs::-webkit-scrollbar { display: none; }
    .code-label { flex: 1; display: inline-flex; align-items: center; gap: .55rem; min-width: 0; padding: 0 .55rem; font-size: .84rem; font-weight: 600; color: var(--ink-text); }
    html[dir="rtl"] .code-label { direction: rtl; justify-content: flex-end; }
    html[dir="rtl"] .code-label[data-code] { direction: ltr; justify-content: flex-start; }
    .code-tag { padding: .12rem .45rem; border-radius: 5px; font-size: .74rem; font-weight: 600; color: var(--ink-muted); background: rgba(255, 255, 255, .06); direction: ltr; }
    .code-tag.is-ok { color: #9be8c1; background: color-mix(in oklab, var(--get) 18%, transparent); }
    .code-tab { position: relative; flex: none; border: 0; background: transparent; color: var(--ink-muted); font: inherit; font-size: .84rem; font-weight: 600; padding: .75rem .62rem; cursor: pointer; white-space: nowrap; }
    .code-tab:hover { color: var(--ink-text); }
    .code-tab[aria-selected="true"] { color: #fff; }
    .code-tab[aria-selected="true"]::after { content: ""; position: absolute; left: .62rem; right: .62rem; bottom: -1px; height: 2px; border-radius: 2px; background: var(--lilac); }
    .code-tab:focus-visible, .code-copy:focus-visible { outline: 2px solid var(--lilac); outline-offset: -2px; border-radius: 6px; }
    .code-copy { flex: none; display: inline-flex; align-items: center; gap: .35rem; border: 0; background: transparent; color: var(--ink-muted); font: inherit; font-size: .8rem; padding: .38rem .58rem; border-radius: 7px; cursor: pointer; }
    .code-copy:hover { color: #fff; background: rgba(255, 255, 255, .07); }
    .code-copy svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; }
    .code-body { margin: 0; padding: 1.05rem 1.15rem 1.15rem; overflow: auto; max-height: 560px; font-size: .84rem; line-height: 1.72; color: var(--ink-text); white-space: pre; tab-size: 4; scrollbar-width: thin; scrollbar-color: rgba(255, 255, 255, .16) transparent; }
    .code-body:focus-visible { outline: 2px solid var(--lilac); outline-offset: -2px; }
    .code-body[hidden] { display: none; }
    .code-body code { font-size: inherit; }
    .tok-keyword { color: #c9b8ff; }
    .tok-string { color: #9be8c1; }
    .tok-number { color: #ffd08a; }
    .tok-comment { color: #7c77a0; font-style: italic; }
    .tok-key { color: #a8d4ff; }
    .tok-variable { color: #ffc48c; }
    .tok-attr { color: #e3b8ff; }
    .tok-type { color: #8de3e0; }

    /* ── Widths ── */
    @media (min-width: 961px) {
      .docs-hero h1 { white-space: nowrap; }
      html[dir="rtl"] .docs-section p, html[dir="rtl"] .docs-section li, html[dir="rtl"] .docs-resource > p { font-size: 1.1rem; }
      html[dir="rtl"] .docs-param dd { font-size: 1.02rem; }
      html[dir="rtl"] .docs-nav a { font-size: .95rem; }
      html[dir="rtl"] .docs-lede { font-size: 1.22rem; }
    }
    /* No room for the contents beside the page: they move to the bar at the top. */
    @media (max-width: 1199px) {
      .docs-wrap { grid-template-columns: minmax(0, 1fr); gap: 0; padding-top: 0; }
      .docs-rail { display: none; }
      .docs-bar { display: block; }
      .docs-section, .docs-resource, .docs-part { scroll-margin-top: calc(var(--docs-top) + 84px); }
      .docs-code { top: calc(var(--docs-top) + 88px); }
      .docs-row { gap: 2.25rem; }
    }
    @media (max-width: 1100px) {
      .docs-console { grid-template-columns: minmax(0, 1fr); }
      .docs-console-pane + .docs-console-pane { border-left: 0; border-top: 1px solid var(--ink-line); }
    }
    /* One column: each example follows the words it belongs to. */
    @media (max-width: 960px) {
      .docs-row { grid-template-columns: minmax(0, 1fr); gap: 1.6rem; }
      .docs-code { position: static; }
      .docs-text { max-width: none; }
      .docs-section { padding: 2.25rem 0; }
      .docs-part { margin-top: 4rem; padding-top: 2.1rem; }
      .docs-hero { padding-top: 1.75rem; }
    }
    @media (max-width: 640px) {
      .docs-wrap { width: calc(100% - 2rem); padding-bottom: 4rem; }
      .docs-bar { margin: 0 -1rem; padding: .5rem 1rem; }
      .docs-hero h1 { font-size: 2.15rem; }
      .docs-lede { font-size: 1.04rem; }
      .docs-hero-actions .docs-btn { flex: 1 1 100%; }
      .docs-base { margin-inline-start: 0; }
      .docs-console { margin-top: 1.75rem; border-radius: 14px; }
      /* A phone line holds the request's headers whole; the voice's name is never cut. */
      .docs-console .code-body { font-size: .74rem; padding-inline: 1rem; }
      .docs-answer { margin-inline: 1rem; }
      .docs-answer-about { flex-wrap: wrap; row-gap: .35rem; }
      .docs-answer-voice > span { white-space: normal; }
      .docs-part h2 { font-size: 1.75rem; }
      .docs-part p { font-size: 1rem; }
      .docs-section h3, .docs-resource h3 { font-size: 1.38rem; }
      .docs-text .docs-endpoint-title { font-size: 1.2rem; }
      .docs-section p, .docs-section li, .docs-resource > p { font-size: 1rem; line-height: 1.7; }
      .docs-param dd { font-size: .94rem; }
      .docs-envs { grid-template-columns: minmax(0, 1fr); }
      /* The plans' row names wrap, so the plans themselves keep the room. */
      .docs-plans th[scope="row"] { white-space: normal; min-width: 8.5rem; max-width: 10.5rem; }
      .docs-table th, .docs-table td { padding: .65rem .75rem; }
      .docs-errors td:nth-child(2) { white-space: normal; }
      .docs-errors code { white-space: normal; overflow-wrap: anywhere; }
      .docs-index a { grid-template-columns: 3.4rem minmax(0, 1fr); row-gap: .15rem; }
      .docs-index-name { grid-column: 2; text-align: start; white-space: normal; }
      .code-body { font-size: .8rem; }
    }
    @media (prefers-reduced-motion: reduce) {
      .docs-play, .docs-btn, .docs-anchor, .docs-wave-head { transition: none; }
      .docs-sheet-card { animation: none; }
    }
  </style>
