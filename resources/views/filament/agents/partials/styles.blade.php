{{-- The Agents section's own look (conversation bubbles, small tables, lists), in the admin's colours, light and dark. --}}
@once
    <style>
        .lsa-muted { color: rgb(var(--gray-500)); font-size: .875rem; line-height: 1.25rem; }
        .dark .lsa-muted { color: rgb(var(--gray-400)); }

        .lsa-items { display: flex; flex-direction: column; gap: .375rem; font-size: .875rem; line-height: 1.6; color: rgb(var(--gray-950)); }
        .dark .lsa-items { color: #fff; }
        .lsa-item { display: flex; gap: .5rem; align-items: baseline; }
        .lsa-marker { flex: none; min-width: 1.25rem; color: rgb(var(--gray-500)); font-variant-numeric: tabular-nums; }
        .lsa-item-text { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; }

        .lsa-chat { display: flex; flex-direction: column; gap: .875rem; margin: 0; padding: .25rem 0; list-style: none; }
        .lsa-turn { display: flex; flex-direction: column; max-width: min(46rem, 88%); }
        .lsa-turn.is-agent { align-self: flex-start; align-items: flex-start; }
        .lsa-turn.is-caller { align-self: flex-end; align-items: flex-end; }
        .lsa-who { display: flex; align-items: center; gap: .375rem; margin: 0 .375rem .25rem; font-size: .75rem; line-height: 1rem; color: rgb(var(--gray-500)); }
        .dark .lsa-who { color: rgb(var(--gray-400)); }
        .lsa-who-name { font-weight: 600; color: rgb(var(--gray-700)); }
        .dark .lsa-who-name { color: rgb(var(--gray-200)); }
        .lsa-tag { padding: 0 .375rem; border-radius: 9999px; background: rgba(var(--primary-500), .15); color: rgb(var(--primary-700)); font-weight: 500; }
        .dark .lsa-tag { color: rgb(var(--primary-300)); }
        .lsa-time { font-variant-numeric: tabular-nums; }
        .lsa-bubble { padding: .625rem .875rem; border-radius: 1rem; font-size: .9375rem; line-height: 1.7; color: rgb(var(--gray-950)); }
        .is-agent .lsa-bubble { background: rgba(var(--primary-500), .1); border: 1px solid rgba(var(--primary-500), .3); border-start-start-radius: .25rem; }
        .is-caller .lsa-bubble { background: rgb(var(--gray-100)); border: 1px solid rgb(var(--gray-200)); border-start-end-radius: .25rem; }
        .dark .lsa-bubble { color: #fff; }
        .dark .is-agent .lsa-bubble { background: rgba(var(--primary-400), .12); border-color: rgba(var(--primary-400), .3); }
        .dark .is-caller .lsa-bubble { background: rgba(255, 255, 255, .06); border-color: rgba(255, 255, 255, .1); }
        .lsa-bubble-text { white-space: pre-wrap; overflow-wrap: anywhere; }
        .lsa-used { display: flex; flex-wrap: wrap; gap: .125rem .75rem; margin: .3125rem .375rem 0; font-size: .75rem; line-height: 1.125rem; color: rgb(var(--gray-500)); font-variant-numeric: tabular-nums; }
        .dark .lsa-used { color: rgb(var(--gray-400)); }

        .lsa-scroll { overflow-x: auto; }
        .lsa-table { width: 100%; border-collapse: collapse; font-size: .875rem; line-height: 1.4; color: rgb(var(--gray-950)); }
        .dark .lsa-table { color: #fff; }
        .lsa-table th { padding: 0 1rem .5rem 0; text-align: start; font-weight: 500; white-space: nowrap; color: rgb(var(--gray-500)); }
        .dark .lsa-table th { color: rgb(var(--gray-400)); }
        .lsa-table td { padding: .625rem 1rem .625rem 0; vertical-align: top; border-top: 1px solid rgb(var(--gray-200)); }
        .dark .lsa-table td { border-top-color: rgba(255, 255, 255, .1); }
        .lsa-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .lsa-nowrap { white-space: nowrap; }
        .lsa-strong { font-weight: 600; }
        .lsa-link { color: rgb(var(--primary-600)); overflow-wrap: anywhere; }
        .lsa-link:hover { text-decoration: underline; }
        .dark .lsa-link { color: rgb(var(--primary-400)); }
        .lsa-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .75rem; }
        .lsa-error { color: rgb(var(--danger-600)); }
        .dark .lsa-error { color: rgb(var(--danger-400)); }

        .lsa-details { margin-top: .375rem; }
        .lsa-details summary { cursor: pointer; font-size: .8125rem; color: rgb(var(--primary-600)); }
        .dark .lsa-details summary { color: rgb(var(--primary-400)); }
        .lsa-pre { white-space: pre-wrap; overflow-wrap: anywhere; font-size: .875rem; line-height: 1.7; color: rgb(var(--gray-950)); }
        .dark .lsa-pre { color: #fff; }
        .lsa-box { max-height: 32rem; overflow-y: auto; padding: .75rem 1rem; border-radius: .75rem; background: rgb(var(--gray-50)); border: 1px solid rgb(var(--gray-200)); }
        .dark .lsa-box { background: rgba(255, 255, 255, .04); border-color: rgba(255, 255, 255, .1); }
        .lsa-details .lsa-box { margin-top: .5rem; max-height: 20rem; }
        .lsa-para { max-height: none; padding: .625rem .875rem; }

        .lsa-kv { display: grid; grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr)); gap: .75rem 1.5rem; margin: 0; }
        .lsa-kv dt { font-size: .75rem; line-height: 1rem; color: rgb(var(--gray-500)); }
        .dark .lsa-kv dt { color: rgb(var(--gray-400)); }
        .lsa-kv dd { margin: .125rem 0 0; font-size: .875rem; line-height: 1.4; color: rgb(var(--gray-950)); overflow-wrap: anywhere; }
        .dark .lsa-kv dd { color: #fff; }
        .lsa-kv .lsa-default { color: rgb(var(--gray-400)); font-size: .75rem; }
        .lsa-swatch { display: inline-block; width: .875rem; height: .875rem; margin-inline-end: .375rem; vertical-align: -2px; border-radius: .25rem; border: 1px solid rgba(0, 0, 0, .15); }

        .lsa-code { margin: 0; padding: .75rem 1rem; max-height: 32rem; overflow: auto; border-radius: .75rem; background: rgb(var(--gray-50)); border: 1px solid rgb(var(--gray-200)); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .75rem; line-height: 1.5; color: rgb(var(--gray-800)); white-space: pre-wrap; overflow-wrap: anywhere; }
        .dark .lsa-code { background: rgba(255, 255, 255, .04); border-color: rgba(255, 255, 255, .1); color: rgb(var(--gray-200)); }
    </style>
@endonce
