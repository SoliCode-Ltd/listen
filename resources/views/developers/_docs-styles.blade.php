  <style>
    /* Developer documentation: sidebar, prose and dark code panels (in both themes). */
    .docs-wrap {
      --code-bg: #0c0f16; --code-head: #121723; --code-line: rgba(255, 255, 255, .08);
      --code-text: #e4e7ef; --code-muted: #8b93a7;
      width: min(1480px, 94%); margin: 0 auto; padding: 2rem 0 4.5rem;
      display: grid; grid-template-columns: 252px minmax(0, 1fr); gap: 2.75rem; align-items: start;
    }
    .docs-nav { position: sticky; top: 88px; max-height: calc(100vh - 104px); overflow-y: auto; padding-inline-end: .4rem; font-size: .9rem; scrollbar-width: thin; }
    .docs-search { position: relative; margin-bottom: 1.1rem; }
    .docs-search input {
      width: 100%; padding: .62rem .8rem .62rem 2.1rem; border-radius: 12px; border: 1px solid var(--line);
      background: var(--surface); color: var(--text); font: inherit; font-size: .88rem;
    }
    html[dir="rtl"] .docs-search input { padding: .62rem 2.1rem .62rem .8rem; }
    .docs-search svg { position: absolute; inset-inline-start: .7rem; top: 50%; width: 15px; height: 15px; transform: translateY(-50%); stroke: var(--muted); fill: none; stroke-width: 2; }
    .docs-nav-group { margin-bottom: 1.15rem; }
    .docs-nav-group > span { display: block; margin: 0 0 .4rem; padding: 0 .65rem; font-size: .74rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
    .docs-nav a { display: flex; align-items: center; gap: .5rem; padding: .36rem .65rem; border-radius: 9px; color: var(--muted); text-decoration: none; line-height: 1.35; }
    .docs-nav a:hover { color: var(--text); background: color-mix(in oklab, var(--text) 6%, transparent); }
    .docs-nav a.is-active { color: var(--text); background: color-mix(in oklab, var(--accent-text) 13%, transparent); font-weight: 600; }
    .docs-nav-empty { display: none; padding: .4rem .65rem; color: var(--muted); font-size: .85rem; }

    .docs-main { min-width: 0; }
    .docs-hero { padding: .5rem 0 2.2rem; border-bottom: 1px solid var(--line); margin-bottom: .5rem; }
    .docs-kicker { display: inline-flex; padding: .3rem .7rem; border-radius: var(--radius-full); border: 1px solid var(--line); font-size: .78rem; font-weight: 600; color: var(--muted); }
    .docs-hero h1 { margin: .9rem 0 .7rem; font-size: clamp(2rem, 4.2vw, 3rem); font-weight: 650; letter-spacing: -.03em; line-height: 1.1; }
    html[dir="rtl"] .docs-hero h1 { letter-spacing: 0; }
    .docs-hero p { margin: 0; max-width: 68ch; color: var(--muted); font-size: 1.06rem; line-height: 1.7; }
    .docs-hero-bar { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1rem; margin-top: 1.5rem; }
    .docs-base { display: inline-flex; align-items: center; gap: .55rem; padding: .45rem .75rem; border-radius: 12px; border: 1px solid var(--line); background: var(--surface); font-size: .86rem; color: var(--muted); }
    .docs-lang-pick { display: inline-flex; align-items: center; gap: .5rem; font-size: .86rem; color: var(--muted); }
    .docs-lang-pick select { padding: .45rem .7rem; border-radius: 10px; border: 1px solid var(--line); background: var(--surface); color: var(--text); font: inherit; font-size: .86rem; }

    .docs-section { padding: 2.6rem 0; border-bottom: 1px solid var(--line); scroll-margin-top: 84px; }
    .docs-section:last-child { border-bottom: 0; }
    .docs-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 2.5rem; align-items: start; }
    .docs-text { min-width: 0; max-width: 780px; }
    .docs-code { min-width: 0; position: sticky; top: 92px; display: grid; gap: 1rem; }
    .docs-section h2 { margin: 0 0 .9rem; font-size: 1.6rem; font-weight: 650; letter-spacing: -.02em; }
    .docs-section h3 { margin: 0 0 .7rem; font-size: 1.32rem; font-weight: 650; letter-spacing: -.01em; }
    html[dir="rtl"] .docs-section h2, html[dir="rtl"] .docs-section h3 { letter-spacing: 0; }
    .docs-section h4 { margin: 1.6rem 0 .6rem; font-size: .8rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
    .docs-section p, .docs-section li { color: var(--muted); line-height: 1.75; font-size: .97rem; }
    .docs-section p { margin: 0 0 .9rem; }
    .docs-section ul, .docs-section ol { margin: .2rem 0 1rem; padding-inline-start: 1.3rem; }
    .docs-section ul { list-style: disc; }
    .docs-section ol { list-style: decimal; }
    .docs-section li { margin: .35rem 0; }
    .docs-section a:not(.btn) { color: var(--text); text-underline-offset: 3px; }
    .docs-anchor { margin-inline-start: .4rem; color: var(--muted) !important; text-decoration: none; opacity: 0; font-weight: 400; }
    .docs-section h2:hover .docs-anchor, .docs-section h3:hover .docs-anchor { opacity: 1; }

    .docs-wrap code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
    .docs-text code, .docs-table code {
      font-size: .84em; padding: .12rem .4rem; border-radius: 6px; white-space: nowrap;
      background: color-mix(in oklab, var(--text) 8%, transparent); color: var(--text);
      direction: ltr; unicode-bidi: isolate;
    }
    .docs-route { display: inline-flex; align-items: center; gap: .6rem; margin: 0 0 1.1rem; padding: .5rem .75rem; border-radius: 12px; border: 1px solid var(--line); background: var(--surface); direction: ltr; max-width: 100%; overflow-x: auto; }
    .docs-route code { background: none !important; padding: 0 !important; font-size: .9rem !important; }
    .docs-method { display: inline-block; min-width: 3.1rem; text-align: center; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .7rem; font-weight: 800; letter-spacing: .03em; padding: .18rem .45rem; border-radius: 7px; direction: ltr; }
    .docs-method.m-post { background: color-mix(in oklab, var(--accent-text) 18%, transparent); color: var(--accent-text); }
    .docs-method.m-get { background: color-mix(in oklab, var(--ok) 18%, transparent); color: var(--ok); }
    .docs-method.m-delete { background: color-mix(in oklab, var(--danger) 16%, transparent); color: var(--danger); }
    .docs-nav .docs-method { min-width: 2.7rem; font-size: .62rem; padding: .12rem .3rem; }
    .docs-meta { font-size: .88rem !important; }

    .docs-table-wrap { overflow-x: auto; margin: .3rem 0 1rem; border: 1px solid var(--line); border-radius: 14px; background: var(--surface); }
    .docs-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .docs-table th, .docs-table td { padding: .7rem .9rem; text-align: start; vertical-align: top; border-bottom: 1px solid var(--line); line-height: 1.6; }
    .docs-table tr:last-child td { border-bottom: 0; }
    .docs-table th { font-size: .74rem; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); font-weight: 700; background: color-mix(in oklab, var(--text) 3%, transparent); }
    .docs-table td { color: var(--muted); }
    .docs-table td:first-child { color: var(--text); white-space: nowrap; }
    .docs-param-name { display: flex; flex-direction: column; align-items: flex-start; gap: .3rem; }
    .docs-pill { display: inline-block; font-size: .68rem; font-weight: 700; padding: .08rem .45rem; border-radius: var(--radius-full); border: 1px solid var(--line); color: var(--muted); }
    .docs-pill.req { color: var(--danger); border-color: color-mix(in oklab, var(--danger) 40%, transparent); }
    .docs-pill.pro { color: var(--accent-text); border-color: color-mix(in oklab, var(--accent-text) 40%, transparent); }

    .docs-callout { padding: .9rem 1.05rem; border-radius: 14px; border: 1px solid color-mix(in oklab, var(--accent-text) 30%, var(--line)); background: color-mix(in oklab, var(--accent-text) 7%, transparent); color: var(--text) !important; font-size: .93rem; line-height: 1.65; margin: 1rem 0; }
    .docs-callout.warn { border-color: color-mix(in oklab, var(--danger) 35%, var(--line)); background: color-mix(in oklab, var(--danger) 7%, transparent); }
    .docs-steps { counter-reset: step; list-style: none !important; padding: 0 !important; }
    .docs-steps li { position: relative; padding-inline-start: 2.4rem; margin: 0 0 .9rem !important; }
    .docs-steps li::before { counter-increment: step; content: counter(step); position: absolute; inset-inline-start: 0; top: .1rem; width: 1.6rem; height: 1.6rem; border-radius: 50%; display: grid; place-items: center; font-size: .8rem; font-weight: 700; color: var(--accent-text); background: color-mix(in oklab, var(--accent-text) 15%, transparent); }

    .code-panel { background: var(--code-bg); border: 1px solid var(--code-line); border-radius: 14px; overflow: hidden; direction: ltr; text-align: left; box-shadow: 0 18px 40px -24px rgba(0, 0, 0, .55); }
    .code-head { display: flex; align-items: center; gap: .2rem; padding: .35rem .45rem; background: var(--code-head); border-bottom: 1px solid var(--code-line); }
    .code-tabs { display: flex; gap: .15rem; overflow-x: auto; scrollbar-width: none; flex: 1; min-width: 0; }
    .code-tabs::-webkit-scrollbar { display: none; }
    .code-label { flex: 1; padding: .3rem .55rem; font-size: .78rem; font-weight: 700; color: var(--code-muted); letter-spacing: .02em; }
    html[dir="rtl"] .code-label { text-align: right; direction: rtl; }
    .code-tab { flex: none; border: 0; background: transparent; color: var(--code-muted); font: inherit; font-size: .78rem; font-weight: 600; padding: .32rem .62rem; border-radius: 8px; cursor: pointer; white-space: nowrap; }
    .code-tab:hover { color: var(--code-text); }
    .code-tab[aria-selected="true"] { color: #fff; background: rgba(255, 255, 255, .1); }
    .code-copy { flex: none; display: inline-flex; align-items: center; gap: .35rem; border: 1px solid var(--code-line); background: transparent; color: var(--code-muted); font: inherit; font-size: .75rem; padding: .28rem .6rem; border-radius: 8px; cursor: pointer; }
    .code-copy:hover { color: #fff; }
    .code-copy svg { width: 13px; height: 13px; stroke: currentColor; fill: none; stroke-width: 2; }
    .code-body { margin: 0; padding: 1rem 1.1rem; overflow: auto; max-height: 560px; font-size: .8rem; line-height: 1.65; color: var(--code-text); white-space: pre; tab-size: 4; }
    .code-body[hidden] { display: none; }
    .code-body code { font-size: inherit; }
    /* The site's font rule skips code and .mono elements; they share one monospace font. */
    .docs-wrap code, .docs-wrap .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
    .tok-keyword { color: #ff7ab2; }
    .tok-string { color: #a5d6a7; }
    .tok-number { color: #ffb86c; }
    .tok-comment { color: #6f7891; font-style: italic; }
    .tok-key { color: #82cfff; }
    .tok-variable { color: #e5c07b; }
    .tok-attr { color: #c3a6ff; }
    .tok-type { color: #5fd7d7; }

    .docs-nav-mobile { display: none; margin: 0 0 1.25rem; border: 1px solid var(--line); border-radius: 14px; background: var(--surface); }
    .docs-nav-mobile summary { padding: .8rem 1rem; cursor: pointer; font-weight: 600; }
    .docs-nav-mobile nav { padding: 0 .6rem .7rem; max-height: 60vh; overflow-y: auto; }

    @media (max-width: 1240px) {
      .docs-row { grid-template-columns: minmax(0, 1fr); gap: 1.5rem; }
      .docs-code { position: static; }
      .docs-text { max-width: none; }
    }
    @media (max-width: 960px) {
      .docs-wrap { grid-template-columns: minmax(0, 1fr); gap: 0; padding-top: 1.25rem; }
      .docs-wrap > .docs-nav { display: none; }
      .docs-nav-mobile { display: block; }
      .docs-nav-mobile .docs-nav { display: block; position: static; max-height: none; padding: 0; }
      .docs-section { padding: 2rem 0; }
    }
  </style>
