  <style>
    .dev-page { padding: 2.5rem 0 4rem; }
    .dev-hero { text-align: center; padding: 1rem 0 2rem; }
    .dev-kicker {
      display: inline-flex; align-items: center; gap: .45rem;
      margin-bottom: 1rem; padding: .38rem .8rem;
      border-radius: var(--radius-full); border: 1px solid var(--line);
      font-size: .8rem; font-weight: 600; color: var(--muted);
    }
    .dev-hero h1 { margin: 0 0 .75rem; font-size: var(--page-title-size); font-weight: 600; letter-spacing: -.03em; }
    html[dir="rtl"] .dev-hero h1 { letter-spacing: 0; }
    .dev-hero p { margin: 0 auto; max-width: 64ch; color: var(--muted); font-size: 1.05rem; line-height: 1.65; }
    .dev-actions { display: flex; flex-wrap: wrap; gap: .75rem; justify-content: center; margin-top: 1.5rem; }

    .dev-card {
      background: var(--surface); border: 1px solid var(--line);
      border-radius: var(--card-radius); box-shadow: var(--card-shadow);
      padding: clamp(1.1rem, 2.5vw, 1.75rem);
    }
    .dev-card + .dev-card { margin-top: 1.25rem; }
    .dev-card h2 { margin: 0 0 .75rem; font-size: 1.3rem; font-weight: 600; }
    .dev-card h3 { margin: 1.4rem 0 .5rem; font-size: 1.02rem; font-weight: 600; }
    .dev-card p, .dev-card li { color: var(--muted); line-height: 1.7; }
    .dev-card p { margin: 0 0 .75rem; }
    .dev-card ul, .dev-card ol { margin: .25rem 0 .75rem; padding-inline-start: 1.25rem; }
    .dev-card li { margin: .3rem 0; }
    .dev-card ul { list-style: disc; }
    .dev-card ol { list-style: decimal; }
    .dev-card a:not(.btn) { color: var(--text); text-underline-offset: 3px; }
    .dev-muted { color: var(--muted); font-size: .9rem; }

    .dev-layout { display: grid; grid-template-columns: 230px minmax(0, 1fr); gap: 2rem; align-items: start; }
    .dev-toc { position: sticky; top: 96px; font-size: .9rem; }
    .dev-toc strong { display: block; margin-bottom: .6rem; font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
    .dev-toc a { display: block; padding: .32rem .7rem; border-radius: 10px; color: var(--muted); text-decoration: none; }
    .dev-toc a:hover { color: var(--text); background: color-mix(in oklab, var(--text) 6%, transparent); }
    .dev-section { scroll-margin-top: 96px; }

    code, .dev-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
    .dev-card code {
      font-size: .86em; padding: .1rem .38rem; border-radius: 6px;
      background: color-mix(in oklab, var(--text) 8%, transparent); color: var(--text);
      direction: ltr; unicode-bidi: isolate; white-space: nowrap;
    }
    .dev-code-wrap { margin: .75rem 0 1rem; border: 1px solid var(--line); border-radius: 14px; overflow: hidden; direction: ltr; text-align: left; }
    .dev-code-bar { display: flex; align-items: center; gap: .25rem; padding: .35rem .5rem; border-bottom: 1px solid var(--line); background: color-mix(in oklab, var(--text) 4%, transparent); }
    .dev-tab { border: 0; background: transparent; color: var(--muted); font: inherit; font-size: .82rem; font-weight: 600; padding: .35rem .7rem; border-radius: 8px; cursor: pointer; }
    .dev-tab[aria-selected="true"] { color: var(--text); background: color-mix(in oklab, var(--text) 9%, transparent); }
    .dev-copy { margin-left: auto; border: 1px solid var(--line); background: var(--surface); color: var(--muted); font: inherit; font-size: .78rem; padding: .3rem .65rem; border-radius: 8px; cursor: pointer; }
    .dev-copy:hover { color: var(--text); }
    .dev-code {
      margin: 0; padding: 1rem 1.1rem; overflow-x: auto; font-size: .84rem; line-height: 1.6;
      background: color-mix(in oklab, var(--text) 3%, var(--surface)); color: var(--text); white-space: pre;
    }
    .dev-code[hidden] { display: none; }
    .dev-code code { padding: 0; background: none; border-radius: 0; font-size: inherit; white-space: pre; color: inherit; }

    .dev-table-wrap { overflow-x: auto; margin: .5rem 0 1rem; border: 1px solid var(--line); border-radius: 14px; }
    .dev-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .dev-table th, .dev-table td { padding: .65rem .85rem; text-align: start; vertical-align: top; border-bottom: 1px solid var(--line); }
    .dev-table tr:last-child td { border-bottom: 0; }
    .dev-table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); font-weight: 600; background: color-mix(in oklab, var(--text) 3%, transparent); }
    .dev-table td { color: var(--muted); }
    .dev-table td:first-child { color: var(--text); white-space: nowrap; }
    .dev-pill { display: inline-block; font-size: .72rem; font-weight: 600; padding: .1rem .45rem; border-radius: var(--radius-full); border: 1px solid var(--line); color: var(--muted); margin-inline-start: .35rem; }
    .dev-pill.req { color: var(--accent-text); border-color: color-mix(in oklab, var(--accent-text) 40%, transparent); }
    .dev-endpoint { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin: .25rem 0 1rem; direction: ltr; justify-content: flex-start; }
    html[dir="rtl"] .dev-endpoint { justify-content: flex-end; }
    .dev-method { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .76rem; font-weight: 700; padding: .2rem .55rem; border-radius: 8px; background: color-mix(in oklab, var(--accent-text) 16%, transparent); color: var(--accent-text); }
    .dev-method.get { background: color-mix(in oklab, var(--ok) 16%, transparent); color: var(--ok); }
    .dev-method.delete { background: color-mix(in oklab, var(--danger) 14%, transparent); color: var(--danger); }
    .dev-path { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .92rem; color: var(--text); }

    .dev-note { padding: .85rem 1rem; border-radius: 14px; border: 1px solid color-mix(in oklab, var(--accent-text) 30%, var(--line)); background: color-mix(in oklab, var(--accent-text) 7%, transparent); color: var(--text); font-size: .93rem; line-height: 1.6; margin: .75rem 0 1rem; }
    .dev-note.warn { border-color: color-mix(in oklab, var(--danger) 35%, var(--line)); background: color-mix(in oklab, var(--danger) 7%, transparent); }

    @media (max-width: 960px) {
      .dev-layout { grid-template-columns: minmax(0, 1fr); }
      .dev-toc { position: static; display: none; }
    }
    @media (max-width: 640px) {
      .dev-page { padding: 1.5rem 0 2.5rem; }
    }
  </style>
