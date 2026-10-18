  <style>
    /*
     * Developer documentation. Three columns on wide screens: contents, prose,
     * and code that stays in view beside the prose it belongs to. Code panels
     * are a deep violet ink in both themes, so examples read the same anywhere.
     */
    .docs-wrap {
      --code-bg: #17142b; --code-head: #1f1b38; --code-line: rgba(255, 255, 255, .07);
      --code-text: #eceaf7; --code-muted: #a29dc2;
      --docs-accent: var(--a, #6A45F2);
      --mono: ui-monospace, "SF Mono", SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
      width: min(1500px, calc(100% - 2rem)); margin: 0 auto; padding: 1.5rem 0 5rem;
      display: grid; grid-template-columns: 236px minmax(0, 1fr); gap: 3rem; align-items: start;
    }
    /* The site's font rule skips code and .mono elements; they share one monospace font. */
    .docs-wrap code, .docs-wrap pre, .docs-wrap .mono { font-family: var(--mono); }

    /* ── Contents ── */
    .docs-nav { position: sticky; top: 88px; max-height: calc(100vh - 104px); overflow-y: auto; padding: .25rem .4rem 1rem 0; font-size: .875rem; scrollbar-width: thin; }
    html[dir="rtl"] .docs-nav { padding: .25rem 0 1rem .4rem; }
    .docs-search { position: relative; margin-bottom: 1.4rem; }
    .docs-search input {
      width: 100%; height: 38px; padding: 0 .8rem 0 2.15rem; border-radius: 10px; border: 1px solid var(--line);
      background: var(--surface); color: var(--text); font: inherit; font-size: .86rem;
    }
    html[dir="rtl"] .docs-search input { padding: 0 2.15rem 0 .8rem; }
    .docs-search input:focus { outline: none; border-color: var(--docs-accent); box-shadow: 0 0 0 3px color-mix(in oklab, var(--docs-accent) 16%, transparent); }
    .docs-search svg { position: absolute; inset-inline-start: .72rem; top: 50%; width: 15px; height: 15px; transform: translateY(-50%); stroke: var(--muted); fill: none; stroke-width: 2; }
    .docs-nav-group { margin-bottom: 1.35rem; }
    .docs-nav-group > span { display: block; margin: 0 0 .35rem; padding: 0 .7rem; font-size: .8rem; font-weight: 650; color: var(--text); }
    .docs-nav a {
      display: flex; align-items: center; gap: .55rem; padding: .32rem .7rem; border-radius: 8px;
      border-inline-start: 2px solid transparent; color: var(--muted); text-decoration: none; line-height: 1.4;
    }
    .docs-nav a:hover { color: var(--text); background: color-mix(in oklab, var(--text) 5%, transparent); }
    .docs-nav a.is-active { color: var(--text); font-weight: 600; border-inline-start-color: var(--docs-accent); border-radius: 0 8px 8px 0; background: color-mix(in oklab, var(--docs-accent) 8%, transparent); }
    html[dir="rtl"] .docs-nav a.is-active { border-radius: 8px 0 0 8px; }
    .docs-nav-empty { display: none; padding: .4rem .7rem; color: var(--muted); font-size: .85rem; }

    /* ── Hero: the request, and what it sounds like ── */
    .docs-main { min-width: 0; }
    .docs-hero { display: grid; grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr); gap: 3rem; align-items: center; padding: 2.25rem 0 3.25rem; border-bottom: 1px solid var(--line); }
    .docs-hero h1 { margin: 0 0 1rem; font-size: clamp(2.1rem, 3.4vw, 3rem); font-weight: 750; letter-spacing: -.04em; line-height: 1.1; color: var(--text); }
    html[dir="rtl"] .docs-hero h1 { letter-spacing: 0; line-height: 1.3; }
    .docs-lede { margin: 0; max-width: 34em; color: var(--muted); font-size: 1.1rem; line-height: 1.7; }
    .docs-hero-actions { display: flex; flex-wrap: wrap; gap: .7rem; margin-top: 1.75rem; }
    .docs-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-height: 44px; padding: 0 1.15rem;
      border-radius: var(--btn-radius, 12px); border: 1px solid var(--line); background: var(--surface); color: var(--text);
      font-weight: 650; font-size: .95rem; text-decoration: none; transition: border-color .2s ease, background-color .2s ease;
    }
    .docs-btn:hover { border-color: color-mix(in oklab, var(--text) 30%, var(--line)); }
    .docs-btn.primary { background: var(--brand-accent, var(--docs-accent)); border-color: transparent; color: #fff; }
    .docs-btn.primary:hover { background: var(--brand-hover, var(--docs-accent)); }
    .docs-btn:focus-visible { outline: 2px solid var(--docs-accent); outline-offset: 3px; }
    .docs-base { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; margin-top: 1.5rem; font-size: .88rem; color: var(--muted); }
    .docs-base code { padding: .3rem .55rem; border-radius: 8px; border: 1px solid var(--line); background: var(--surface); color: var(--text); font-size: .84rem; direction: ltr; unicode-bidi: isolate; }

    .docs-demo { min-width: 0; display: grid; gap: 0; border-radius: 18px; overflow: hidden; box-shadow: 0 30px 60px -36px rgba(23, 20, 43, .55); }
    .docs-demo .code-panel { border-radius: 0; border: 0; box-shadow: none; }
    .docs-demo .code-body { max-height: none; font-size: .82rem; }
    .docs-answer {
      display: grid; grid-template-columns: auto minmax(0, 1fr); gap: .9rem 1rem; align-items: center;
      padding: 1rem 1.1rem 1.1rem; background: #201c3b; border-top: 1px solid var(--code-line); color: var(--code-text); direction: ltr;
    }
    .docs-play {
      width: 46px; height: 46px; border-radius: 50%; border: 0; display: grid; place-items: center; cursor: pointer;
      background: #fff; color: #17142b; transition: transform .15s ease;
    }
    .docs-play:hover { transform: scale(1.05); }
    .docs-play:focus-visible { outline: 2px solid #c9b8ff; outline-offset: 3px; }
    .docs-play svg { width: 18px; height: 18px; fill: currentColor; }
    .docs-play .i-pause { display: none; }
    .docs-play[aria-pressed="true"] .i-play { display: none; }
    .docs-play[aria-pressed="true"] .i-pause { display: block; }
    .docs-wave { --p: 0%; position: relative; height: 40px; min-width: 0; overflow: hidden; cursor: pointer; }
    .docs-wave-bars { position: absolute; inset: 0; display: flex; align-items: center; justify-content: space-between; gap: 2px; pointer-events: none; }
    .docs-wave-bars i { flex: 1 1 0; min-width: 2px; border-radius: 2px; background: rgba(255, 255, 255, .22); }
    .docs-wave-bars.is-played { clip-path: inset(0 calc(100% - var(--p)) 0 0); }
    .docs-wave-bars.is-played i { background: #c9b8ff; }
    .docs-wave-head { position: absolute; top: 4px; bottom: 4px; left: var(--p); width: 2px; margin-left: -1px; border-radius: 1px; background: #fff; opacity: 0; transition: opacity .2s ease; pointer-events: none; }
    .docs-answer.is-playing .docs-wave-head, .docs-answer.is-started .docs-wave-head { opacity: .85; }
    .docs-answer-meta { grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; font-size: .82rem; color: var(--code-muted); }
    .docs-answer-voice { display: inline-flex; align-items: center; gap: .55rem; color: var(--code-text); font-weight: 600; }
    html[dir="rtl"] .docs-answer-voice { direction: rtl; }
    .docs-answer-voice img { width: 26px; height: 26px; border-radius: 50%; object-fit: cover; background: rgba(255, 255, 255, .1); }
    .docs-answer-voice small { display: block; font-weight: 400; color: var(--code-muted); font-size: .76rem; }
    .docs-answer-status { font-size: .76rem; color: #9be8c1; }

    /* ── Sections: prose beside its code ── */
    .docs-section { padding: 3rem 0; border-bottom: 1px solid var(--line); scroll-margin-top: 80px; }
    .docs-section:last-child { border-bottom: 0; }
    .docs-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 3rem; align-items: start; }
    .docs-text { min-width: 0; max-width: 720px; }
    .docs-code { min-width: 0; position: sticky; top: 92px; display: grid; gap: 1rem; }
    .docs-section h2 { margin: 0 0 1rem; font-size: 1.6rem; font-weight: 700; letter-spacing: -.025em; line-height: 1.25; color: var(--text); }
    .docs-section h3 { margin: 0 0 .85rem; font-size: 1.35rem; font-weight: 700; letter-spacing: -.02em; line-height: 1.3; color: var(--text); }
    html[dir="rtl"] .docs-section h2, html[dir="rtl"] .docs-section h3 { letter-spacing: 0; }
    .docs-section h4 { margin: 2rem 0 .5rem; font-size: 1rem; font-weight: 650; color: var(--text); }
    .docs-section p, .docs-section li { color: var(--muted); line-height: 1.75; font-size: 1rem; }
    .docs-section p { margin: 0 0 1rem; }
    .docs-section ul, .docs-section ol { margin: .25rem 0 1.1rem; padding-inline-start: 1.25rem; }
    .docs-section ul { list-style: disc; }
    .docs-section ul li::marker { color: color-mix(in oklab, var(--docs-accent) 70%, var(--muted)); }
    .docs-section li { margin: .4rem 0; }
    .docs-section a:not(.docs-btn) { color: var(--text); text-decoration-color: color-mix(in oklab, var(--docs-accent) 55%, transparent); text-underline-offset: 3px; }
    .docs-anchor { margin-inline-start: .45rem; color: var(--muted) !important; text-decoration: none !important; opacity: 0; font-weight: 400; transition: opacity .15s ease; }
    .docs-section h2:hover .docs-anchor, .docs-section h3:hover .docs-anchor, .docs-anchor:focus-visible { opacity: 1; }

    .docs-text code, .docs-table code, .docs-params code {
      font-size: .86em; padding: .1rem .38rem; border-radius: 6px; white-space: nowrap;
      background: color-mix(in oklab, var(--docs-accent) 9%, transparent); color: var(--text);
      direction: ltr; unicode-bidi: isolate;
    }
    .docs-note { margin: 1.25rem 0; padding: .95rem 1.1rem; border-radius: 12px; border-inline-start: 3px solid var(--docs-accent); background: color-mix(in oklab, var(--docs-accent) 6%, var(--surface)); color: var(--text) !important; font-size: .95rem !important; }
    .docs-note.warn { border-inline-start-color: #e0a100; background: color-mix(in oklab, #e0a100 8%, var(--surface)); }

    /* An endpoint: method and path, as one line of code. */
    .docs-route { display: inline-flex; align-items: center; gap: .65rem; max-width: 100%; margin: 0 0 1.1rem; padding: .45rem .8rem .45rem .5rem; border-radius: 10px; border: 1px solid var(--line); background: var(--surface); direction: ltr; overflow-x: auto; }
    .docs-route code { font-size: .9rem; color: var(--text); white-space: nowrap; }
    .docs-method { display: inline-block; min-width: 3.3rem; text-align: center; font-size: .7rem; font-weight: 750; letter-spacing: .02em; padding: .2rem .45rem; border-radius: 6px; direction: ltr; }
    .docs-method.m-post { background: color-mix(in oklab, var(--docs-accent) 16%, transparent); color: var(--accent-text, var(--docs-accent)); }
    .docs-method.m-get { background: color-mix(in oklab, #10a36b 16%, transparent); color: color-mix(in oklab, #10a36b 80%, var(--text)); }
    .docs-method.m-delete { background: color-mix(in oklab, #e5484d 15%, transparent); color: color-mix(in oklab, #e5484d 85%, var(--text)); }
    .docs-nav .docs-method { min-width: 2.75rem; font-size: .6rem; padding: .12rem .3rem; }
    .docs-meta { font-size: .9rem !important; }

    /* Parameters: a list, each name with its type and whether it is needed. */
    .docs-params { margin: 0 0 .5rem; padding: 0; border-top: 1px solid var(--line); }
    .docs-param { padding: .95rem 0; border-bottom: 1px solid var(--line); }
    .docs-param dt { display: flex; align-items: baseline; flex-wrap: wrap; gap: .3rem .6rem; margin: 0 0 .35rem; }
    .docs-param dt code { padding: 0; background: none; font-size: .92rem; font-weight: 650; color: var(--text); }
    .docs-type { font-size: .82rem; color: var(--muted); }
    .docs-flag { font-size: .76rem; font-weight: 650; color: var(--muted); }
    .docs-flag.req { color: color-mix(in oklab, #e5484d 80%, var(--text)); }
    .docs-flag.plan { padding: .05rem .5rem; border-radius: 999px; color: var(--accent-text, var(--docs-accent)); background: color-mix(in oklab, var(--docs-accent) 10%, transparent); text-decoration: none !important; }
    .docs-param dd { margin: 0; color: var(--muted); font-size: .95rem; line-height: 1.7; }

    /* Tables: errors, sound environments and the plans. */
    .docs-table-wrap { position: relative; overflow-x: auto; margin: .5rem 0 1.1rem; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); }
    .docs-table { width: 100%; border-collapse: collapse; font-size: .92rem; }
    .docs-table th, .docs-table td { padding: .7rem .95rem; text-align: start; vertical-align: top; border-bottom: 1px solid var(--line); line-height: 1.6; }
    .docs-table tbody tr:last-child > * { border-bottom: 0; }
    .docs-table thead th { font-size: .84rem; font-weight: 650; color: var(--text); background: color-mix(in oklab, var(--text) 2.5%, transparent); }
    .docs-table td { color: var(--muted); }
    .docs-table td:first-child { color: var(--text); white-space: nowrap; }
    .docs-status { font-size: .8rem; font-weight: 700; color: color-mix(in oklab, #d48a00 85%, var(--text)); }
    .docs-status.s5 { color: color-mix(in oklab, #e5484d 85%, var(--text)); }

    .docs-plans th[scope="row"] { font-weight: 500; color: var(--muted); white-space: nowrap; }
    .docs-plans thead th:not(:first-child), .docs-plans td { text-align: center; }
    .docs-plans td { color: var(--text) !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .docs-plans .docs-plans-group th { padding-top: 1.1rem; font-weight: 650; color: var(--text); background: color-mix(in oklab, var(--text) 2.5%, transparent); }
    .docs-yes, .docs-no { position: relative; display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; }
    .docs-yes { background: color-mix(in oklab, var(--docs-accent) 14%, transparent); color: var(--accent-text, var(--docs-accent)); }
    .docs-yes svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; }
    .docs-no { color: var(--muted); }
    .docs-no::before { content: ""; width: 10px; height: 2px; border-radius: 1px; background: currentColor; opacity: .55; }
    .docs-formats code { margin: 0 .12rem; }
    .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }

    .docs-envs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 1.5rem; margin: .25rem 0 1rem; }
    .docs-env-group { grid-column: 1 / -1; margin: 1.2rem 0 .2rem; font-size: .92rem; font-weight: 650; color: var(--text); }
    .docs-env { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; padding: .55rem 0; border-bottom: 1px solid var(--line); }
    .docs-env b { display: block; font-weight: 600; font-size: .93rem; color: var(--text); }
    .docs-env span { display: block; font-size: .85rem; color: var(--muted); line-height: 1.5; }
    .docs-env code { flex: none; }

    .docs-steps { counter-reset: step; list-style: none !important; padding: 0 !important; }
    .docs-steps li { position: relative; padding-inline-start: 2.5rem; margin: 0 0 1rem !important; }
    .docs-steps li::before { counter-increment: step; content: counter(step); position: absolute; inset-inline-start: 0; top: .15rem; width: 1.7rem; height: 1.7rem; border-radius: 50%; display: grid; place-items: center; font-size: .82rem; font-weight: 700; color: var(--accent-text, var(--docs-accent)); border: 1px solid color-mix(in oklab, var(--docs-accent) 35%, var(--line)); }

    /* ── Code panels ── */
    .code-panel { background: var(--code-bg); border: 1px solid var(--code-line); border-radius: 14px; overflow: hidden; direction: ltr; text-align: left; box-shadow: 0 24px 48px -32px rgba(23, 20, 43, .6); }
    .code-head { display: flex; align-items: center; gap: .25rem; min-height: 42px; padding: 0 .45rem; background: var(--code-head); border-bottom: 1px solid var(--code-line); }
    .code-tabs { display: flex; gap: .1rem; overflow-x: auto; scrollbar-width: none; flex: 1; min-width: 0; }
    .code-tabs::-webkit-scrollbar { display: none; }
    .code-label { flex: 1; padding: 0 .55rem; font-size: .8rem; font-weight: 600; color: var(--code-muted); }
    html[dir="rtl"] .code-label { text-align: right; direction: rtl; }
    .code-tab { position: relative; flex: none; border: 0; background: transparent; color: var(--code-muted); font: inherit; font-size: .8rem; font-weight: 600; padding: .7rem .6rem; cursor: pointer; white-space: nowrap; }
    .code-tab:hover { color: var(--code-text); }
    .code-tab[aria-selected="true"] { color: #fff; }
    .code-tab[aria-selected="true"]::after { content: ""; position: absolute; left: .6rem; right: .6rem; bottom: -1px; height: 2px; border-radius: 2px; background: #c9b8ff; }
    .code-tab:focus-visible, .code-copy:focus-visible { outline: 2px solid #c9b8ff; outline-offset: -2px; border-radius: 6px; }
    .code-copy { flex: none; display: inline-flex; align-items: center; gap: .35rem; border: 0; background: transparent; color: var(--code-muted); font: inherit; font-size: .78rem; padding: .35rem .55rem; border-radius: 7px; cursor: pointer; }
    .code-copy:hover { color: #fff; background: rgba(255, 255, 255, .07); }
    .code-copy svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; }
    .code-body { margin: 0; padding: 1.05rem 1.15rem; overflow: auto; max-height: 560px; font-size: .8rem; line-height: 1.7; color: var(--code-text); white-space: pre; tab-size: 4; scrollbar-width: thin; scrollbar-color: rgba(255, 255, 255, .15) transparent; }
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

    .docs-nav-mobile { display: none; margin: 0 0 1rem; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); }
    .docs-nav-mobile summary { padding: .8rem 1rem; cursor: pointer; font-weight: 650; }
    .docs-nav-mobile .docs-nav { padding: 0 .6rem .7rem; }

    @media (max-width: 1280px) {
      .docs-row, .docs-hero { grid-template-columns: minmax(0, 1fr); gap: 1.75rem; }
      .docs-code { position: static; }
      .docs-text { max-width: none; }
    }
    @media (max-width: 960px) {
      .docs-wrap { grid-template-columns: minmax(0, 1fr); gap: 0; padding-top: 1rem; }
      .docs-wrap > .docs-nav { display: none; }
      .docs-nav-mobile { display: block; }
      .docs-nav-mobile .docs-nav { position: static; max-height: 60vh; }
      .docs-section { padding: 2.25rem 0; }
      .docs-hero { padding: 1.25rem 0 2.25rem; }
    }
    @media (max-width: 640px) {
      .docs-envs { grid-template-columns: minmax(0, 1fr); }
      .docs-hero-actions .docs-btn { flex: 1 1 100%; }
    }
    @media (prefers-reduced-motion: reduce) {
      .docs-play, .docs-btn, .docs-anchor { transition: none; }
    }

    /* Desktop: a larger reading size for the prose, the lists and the code. */
    @media (min-width: 961px) {
      /* The page title stays on one line. */
      .docs-hero h1 { white-space: nowrap; font-size: clamp(2.1rem, 2.9vw, 3rem); }
      .docs-nav { font-size: .95rem; }
      .docs-nav-group > span { font-size: .86rem; }
      .docs-search input { height: 40px; font-size: .93rem; }
      .docs-lede { font-size: 1.22rem; }
      .docs-base { font-size: .95rem; }
      .docs-base code { font-size: .9rem; }
      .docs-section h2 { font-size: 1.9rem; }
      .docs-section h3 { font-size: 1.55rem; }
      .docs-section h4 { font-size: 1.1rem; }
      .docs-section p, .docs-section li { font-size: 1.09rem; line-height: 1.8; }
      .docs-meta { font-size: .97rem !important; }
      .docs-note { font-size: 1.03rem !important; }
      .docs-route code { font-size: .97rem; }
      .docs-method { font-size: .74rem; }
      .docs-param dt code { font-size: 1rem; }
      .docs-type { font-size: .88rem; }
      .docs-flag { font-size: .82rem; }
      .docs-param dd { font-size: 1.03rem; }
      .docs-table { font-size: 1rem; }
      .docs-table thead th { font-size: .9rem; }
      .docs-env b { font-size: 1rem; }
      .docs-env span { font-size: .92rem; }
      .code-body { font-size: .87rem; }
      .docs-demo .code-body { font-size: .86rem; }
      .code-tab, .code-label { font-size: .85rem; }
      .code-copy { font-size: .82rem; }
      .docs-answer-meta { font-size: .88rem; }
      /* Arabic letters read smaller than Latin ones at the same size. */
      html[dir="rtl"] .docs-section p, html[dir="rtl"] .docs-section li { font-size: 1.14rem; }
      html[dir="rtl"] .docs-param dd { font-size: 1.08rem; }
      html[dir="rtl"] .docs-nav { font-size: 1rem; }
      html[dir="rtl"] .docs-lede { font-size: 1.28rem; }
    }
  </style>
