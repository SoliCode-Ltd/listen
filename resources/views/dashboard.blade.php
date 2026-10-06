@include('components.page-head', ['pageTitle' => __('ui.seo.dashboard_title'), 'page' => 'dashboard'])
  <style>
    /* Background orbs */
    .bg-orb {
      position: fixed;
      z-index: -1;
      pointer-events: none;
      border-radius: 999px;
      filter: blur(60px);
      opacity: .18;
      animation: float 12s ease-in-out infinite;
    }

    /* Custom voice studio */
    .vc-shell { margin-top: .5rem; min-width: 0; }
    .vc-intro { margin-bottom: 1.5rem; }
    .vc-intro p { margin: .55rem 0 0; color: var(--muted); line-height: 1.7; }
    .vc-meta {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: .85rem 1.1rem;
      margin-top: 1.15rem;
    }
    .vc-count { font-size: .9rem; color: var(--muted); margin: 0; white-space: nowrap; }
    .vc-reconsent-banner {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: .75rem;
      padding: .85rem 1rem;
      margin-bottom: 1rem;
      border-radius: .8rem;
      border: 1px solid color-mix(in oklab, var(--a) 32%, var(--line));
      background: color-mix(in oklab, var(--a) 9%, var(--surface));
    }
    .vc-reconsent-banner[hidden] { display: none; }
    .vc-reconsent-banner strong { display: block; font-size: .95rem; overflow-wrap: anywhere; }
    .vc-reconsent-banner p { margin: .25rem 0 0; color: var(--muted); font-size: .84rem; }
    .vc-segmented {
      display: inline-flex;
      padding: .2rem;
      gap: .2rem;
      border-radius: 999px;
      border: 1px solid color-mix(in oklab, var(--line) 80%, transparent);
      background: color-mix(in oklab, var(--surface2) 55%, var(--surface));
    }
    .vc-segment {
      border: 0;
      background: transparent;
      color: var(--muted);
      font: inherit;
      font-weight: 700;
      font-size: .84rem;
      padding: .42rem .95rem;
      min-height: 36px;
      border-radius: 999px;
      cursor: pointer;
    }
    .vc-segment.active { background: var(--a); color: #fff; }
    .vc-consent-tips {
      list-style: none;
      margin: .85rem 0 0;
      padding: 0;
      font-size: .92rem;
      line-height: 1.7;
      color: var(--muted);
      display: grid;
      gap: .55rem;
    }
    .vc-consent-tips li { display: flex; align-items: flex-start; gap: .5rem; }
    .vc-consent-tips li::before {
      content: "";
      width: .42rem;
      height: .42rem;
      margin-top: .38rem;
      border-radius: 99px;
      flex-shrink: 0;
      background: var(--a);
    }
    .vc-playback {
      display: none;
      justify-content: center;
      width: 100%;
      margin-top: 1.15rem;
    }
    .vc-playback.is-ready { display: flex; }
    .vc-playback[hidden] { display: none !important; }
    .vc-preview-audio {
      width: min(100%, 28rem);
      max-width: 28rem;
      margin-inline: auto;
    }
    .vc-recorder.countdown .vc-timer { color: var(--a); }
    .vc-tech-detail { margin: -.25rem 0 .7rem; font-size: .76rem; color: var(--muted); }
    .vc-tech-detail summary { cursor: pointer; font-weight: 700; }
    .vc-tech-detail code {
      display: block;
      margin-top: .35rem;
      padding: .5rem .6rem;
      border-radius: .5rem;
      background: color-mix(in oklab, var(--surface2) 70%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--line) 70%, transparent);
      white-space: pre-wrap;
      overflow-wrap: anywhere;
      text-align: left;
      font-family: var(--font-mono);
    }
    #voice_key.vc-speaker-picked {
      border-color: color-mix(in oklab, var(--a) 55%, var(--line));
      box-shadow: 0 0 0 3px color-mix(in oklab, var(--a) 22%, transparent);
    }
    .vc-notice {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem 1.25rem;
      margin-top: 1rem;
      padding: 1rem 1.05rem;
      border-radius: 1rem;
      text-align: start;
      background: color-mix(in oklab, var(--surface) 88%, var(--a));
      border: 1px solid color-mix(in oklab, var(--a) 28%, var(--line));
      box-shadow: 0 10px 28px color-mix(in oklab, var(--a) 8%, transparent);
      color: var(--text);
    }
    .vc-notice p {
      margin: 0;
      flex: 1;
      min-width: 0;
      font-size: .98rem;
      line-height: 1.75;
      font-weight: 600;
    }
    .vc-notice .btn {
      flex: none;
      min-height: 42px;
      padding: .55rem 1rem;
      white-space: nowrap;
    }
    .vc-alert { padding: 1rem 1.15rem; border-radius: var(--radius); margin-bottom: 1rem; font-size: .98rem; line-height: 1.75; display: flex; align-items: flex-start; gap: .6rem; scroll-margin-top: 6.5rem; scroll-margin-bottom: 2rem; }
    .vc-alert.success { background: color-mix(in oklab, #22c55e 12%, var(--surface)); border: 1px solid color-mix(in oklab, #22c55e 30%, var(--line)); color: #16a34a; }
    .vc-alert.error { background: color-mix(in oklab, #ef4444 12%, var(--surface)); border: 1px solid color-mix(in oklab, #ef4444 30%, var(--line)); color: #dc2626; }
    .vc-disabled-banner {
      padding: 1.4rem;
      border-radius: 1rem;
      border: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
      background: color-mix(in oklab, var(--surface) 70%, transparent);
      text-align: center;
      margin-bottom: 1rem;
    }
    .vc-upgrade {
      display: flex;
      flex-direction: column;
      align-items: center;
      width: 100%;
      margin: .35rem 0 0;
      padding: 1.7rem 1.4rem 1.5rem;
      border-radius: 1.25rem;
      text-align: center;
      border: 1px solid color-mix(in oklab, var(--a) 30%, var(--line));
      background:
        radial-gradient(70% 80% at 50% 0%, color-mix(in oklab, var(--a) 14%, transparent), transparent 64%),
        var(--surface);
      box-shadow: 0 16px 36px color-mix(in oklab, var(--a) 8%, transparent);
    }
    .vc-upgrade-mark {
      width: 3.4rem;
      height: 3.4rem;
      border-radius: 1rem;
      display: grid;
      place-items: center;
      color: var(--a);
      background: color-mix(in oklab, var(--a) 12%, var(--surface2));
      border: 1px solid color-mix(in oklab, var(--a) 26%, transparent);
    }
    .vc-upgrade-mark svg {
      width: 2rem;
      height: 2rem;
      display: block;
    }
    .vc-upgrade h2 {
      margin: 1rem 0 .4rem;
      max-width: 24rem;
      font-size: clamp(1.2rem, 2.2vw, 1.45rem);
      line-height: 1.35;
    }
    html[dir="rtl"] .vc-upgrade h2 {
      line-height: 1.6;
    }
    .vc-upgrade > p {
      margin: 0;
      max-width: 38rem;
      color: var(--muted);
      font-size: 1rem;
      line-height: 1.7;
    }
    html[dir="rtl"] .vc-upgrade > p {
      font-size: 1.02rem;
      line-height: 1.9;
    }
    .vc-upgrade .features-list {
      list-style: none;
      width: min(100%, 32rem);
      margin: 1.15rem 0 0;
      padding: .9rem 1rem;
      display: grid;
      gap: .7rem;
      text-align: start;
      border-radius: .9rem;
      background: color-mix(in oklab, var(--surface2) 88%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--line) 75%, transparent);
    }
    .vc-upgrade .features-list li {
      display: flex;
      align-items: flex-start;
      gap: .65rem;
      margin: 0;
      font-size: .98rem;
      line-height: 1.55;
    }
    html[dir="rtl"] .vc-upgrade .features-list li {
      font-size: 1rem;
      line-height: 1.8;
    }
    .vc-upgrade .features-list svg {
      width: 1.15rem;
      height: 1.15rem;
      flex: none;
      margin-top: .15rem;
      color: var(--a);
    }
    .vc-upgrade .btn {
      margin-top: 1.2rem;
      min-height: 48px;
      min-width: 11.5rem;
      padding-inline: 1.45rem;
    }
    .vc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: .85rem; margin-bottom: 1rem; }
    .vc-card {
      border-radius: 1rem;
      border: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
      background: color-mix(in oklab, var(--surface) 72%, transparent);
      padding: 1rem;
      scroll-margin-top: 7rem;
      transition: box-shadow .28s ease, border-color .28s ease;
    }
    .vc-card.vc-card-highlight {
      border-color: color-mix(in oklab, var(--a) 55%, var(--line));
      box-shadow: 0 0 0 2px color-mix(in oklab, var(--a) 24%, transparent);
    }
    .vc-card-header { display: flex; justify-content: space-between; gap: .5rem; align-items: flex-start; margin-bottom: .7rem; flex-wrap: wrap; }
    .vc-card-name { margin: 0; font-size: 1rem; font-weight: 700; min-width: 0; overflow-wrap: anywhere; }
    .vc-card-meta { font-size: .84rem; color: var(--muted); display: grid; gap: .2rem; margin-bottom: .7rem; }
    .vc-status { display: inline-flex; align-items: center; gap: .35rem; padding: .16rem .55rem; border-radius: 999px; font-size: .75rem; font-weight: 700; border: 1px solid transparent; }
    .vc-status.ready { color: #16a34a; background: color-mix(in oklab, #22c55e 14%, var(--surface)); border-color: color-mix(in oklab, #22c55e 28%, var(--line)); }
    .vc-status.processing, .vc-status.pending, .vc-status.validating { color: var(--a); background: color-mix(in oklab, var(--a) 14%, var(--surface)); border-color: color-mix(in oklab, var(--a) 28%, var(--line)); }
    .vc-status.failed { color: #dc2626; background: color-mix(in oklab, #ef4444 14%, var(--surface)); border-color: color-mix(in oklab, #ef4444 28%, var(--line)); }
    .vc-status .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; animation: pulse-dot 1.4s ease-in-out infinite; }
    @keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:.35} }
    .vc-card-actions { display: flex; flex-wrap: wrap; gap: .45rem; }
    .vc-card-actions .btn { font-size: .82rem; padding: .38rem .72rem; }
    .vc-progress { margin: .45rem 0 .7rem; }
    .vc-progress.is-hidden { display: none; }
    .vc-progress-track {
      width: 100%;
      height: 8px;
      border-radius: 999px;
      background: color-mix(in oklab, var(--line) 55%, transparent);
      overflow: hidden;
      border: 1px solid color-mix(in oklab, var(--line) 70%, transparent);
    }
    .vc-progress-fill {
      height: 100%;
      width: 0%;
      border-radius: 999px;
      background: linear-gradient(90deg, var(--a), var(--b));
      transition: width .35s ease;
    }
    .vc-progress-meta {
      margin-top: .38rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .5rem;
      font-size: .78rem;
      color: var(--muted);
      font-weight: 600;
    }
    .vc-progress-delay {
      margin-top: .35rem;
      font-size: .76rem;
      color: color-mix(in oklab, #d97706 82%, var(--text));
      display: none;
    }
    .vc-progress.is-delayed .vc-progress-delay { display: block; }
    .vc-empty {
      text-align: center;
      border-radius: 1rem;
      border: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
      background: color-mix(in oklab, var(--surface) 72%, transparent);
      padding: 2rem 1.6rem;
      margin: .4rem 0 1.5rem;
    }
    .vc-empty h3 { margin: 0 0 .55rem; }
    .vc-empty p { margin: 0 auto 1.15rem; max-width: 38rem; color: var(--muted); line-height: 1.75; }
    .vc-create-panel {
      border-radius: 1rem;
      border: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
      background: color-mix(in oklab, var(--surface) 72%, transparent);
      padding: 1.4rem 1.45rem 1.5rem;
      margin-top: 1.5rem;
    }
    .vc-create-panel h2 { margin: 0 0 .9rem; font-size: 1.08rem; }
    .vc-form-group { margin-bottom: 1.2rem; }
    .vc-form-group label { display: block; margin-bottom: .35rem; font-size: .88rem; font-weight: 700; }
    .vc-tabs { display: flex; gap: .45rem; margin-bottom: .8rem; }
    .vc-tab { border: 1px solid var(--line); border-radius: var(--radius); padding: .5rem .95rem; background: var(--surface); color: var(--muted); font-weight: 700; font-size: .82rem; cursor: pointer; }
    .vc-tab.active { color: var(--text); border-color: color-mix(in oklab, var(--a) 40%, var(--line)); background: color-mix(in oklab, var(--a) 10%, var(--surface)); }
    .vc-upload-zone {
      border: 2px dashed color-mix(in oklab, var(--line) 80%, transparent);
      border-radius: .9rem;
      padding: 1.1rem;
      text-align: center;
      cursor: pointer;
      position: relative;
      transition: border-color .2s, background .2s;
    }
    .vc-upload-zone.dragover,
    .vc-upload-zone:hover { border-color: var(--a); background: color-mix(in oklab, var(--a) 6%, var(--surface)); }
    .vc-upload-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
    .vc-upload-zone p { margin: 0; color: var(--muted); font-size: .86rem; }
    .vc-upload-zone .formats { margin-top: .35rem; font-size: .8rem; opacity: .8; }
    .vc-file-info {
      margin-top: .65rem;
      border-radius: .7rem;
      border: 1px solid color-mix(in oklab, var(--a) 28%, var(--line));
      background: color-mix(in oklab, var(--a) 8%, var(--surface));
      padding: .65rem .8rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: .6rem;
      font-size: .85rem;
    }
    .vc-file-info span:first-child { min-width: 0; overflow-wrap: anywhere; }
    .vc-file-info .remove { cursor: pointer; font-weight: 700; color: var(--muted); flex-shrink: 0; }
    .vc-file-info .remove:hover { color: #dc2626; }
    .vc-recorder { text-align: center; border: 1px solid color-mix(in oklab, var(--line) 60%, transparent); border-radius: .9rem; padding: 1rem; background: color-mix(in oklab, var(--surface) 78%, transparent); }
    .vc-recorder .rec-btn { width: 68px; height: 68px; border-radius: 999px; border: 3px solid var(--line); background: var(--surface); display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all .2s; }
    .vc-recorder .rec-btn:hover { border-color: var(--a); }
    .vc-recorder .rec-btn.recording { border-color: #ef4444; box-shadow: 0 0 0 10px rgba(239,68,68,.14); }
    .vc-recorder .rec-btn .inner { width: 24px; height: 24px; border-radius: 999px; background: #ef4444; transition: all .2s; }
    .vc-recorder .rec-btn.recording .inner { border-radius: 4px; width: 20px; height: 20px; }
    .vc-timer { margin: .7rem 0 .25rem; font-size: 1.35rem; font-weight: 800; font-variant-numeric: tabular-nums; }
    .vc-recorder .hint { margin: 0; color: var(--muted); font-size: .84rem; }
    .vc-recorder .rec-actions { margin-top: .7rem; display: flex; justify-content: center; gap: .4rem; }
    .vc-guidelines { border-radius: .8rem; border: 1px solid color-mix(in oklab, var(--a) 26%, var(--line)); background: color-mix(in oklab, var(--a) 7%, var(--surface)); padding: 1.05rem 1.15rem; margin-bottom: 1.25rem; }
    .vc-guidelines h4 { margin: 0 0 .65rem; font-size: .92rem; color: var(--a); }
    .vc-guidelines ul { list-style: none; margin: 0; padding: 0; font-size: .92rem; line-height: 1.7; color: var(--muted); display: grid; gap: .55rem; }
    .vc-guidelines li { display: flex; align-items: flex-start; gap: .5rem; }
    .vc-guidelines li::before {
      content: "";
      width: .42rem;
      height: .42rem;
      margin-top: .38rem;
      border-radius: 99px;
      flex-shrink: 0;
      background: var(--a);
    }
    .vc-consent { margin-top: 1rem; margin-bottom: .95rem; border-radius: .8rem; border: 1px solid color-mix(in oklab, var(--line) 70%, transparent); background: color-mix(in oklab, var(--surface2) 55%, var(--surface)); padding: .75rem .85rem; display: flex; align-items: flex-start; gap: .6rem; }
    .vc-consent input[type=checkbox] { margin-top: .1rem; width: 18px; height: 18px; accent-color: var(--a); }
    .vc-consent label { margin: 0; font-size: .85rem; line-height: 1.45; color: var(--text); min-width: 0; }
    .vc-privacy { display: flex; align-items: flex-start; gap: .4rem; color: var(--muted); font-size: .8rem; margin-bottom: .95rem; }
    .vc-privacy svg { width: 14px; height: 14px; margin-top: .1rem; opacity: .7; flex-shrink: 0; }
    .vc-preview-pill {
      display: inline-flex;
      align-items: center;
      margin-top: 0;
      padding: .22rem .65rem;
      border-radius: 999px;
      font-size: .75rem;
      font-weight: 700;
      letter-spacing: .01em;
      color: var(--a);
      border: 1px solid color-mix(in oklab, var(--a) 32%, var(--line));
      background: color-mix(in oklab, var(--a) 10%, var(--surface));
    }
    .vc-field-grid { display: grid; grid-template-columns: 1.2fr .8fr; gap: 1.15rem; }
    .vc-form-group input[type="text"],
    .vc-form-group select,
    .vc-language-value {
      width: 100%;
      border-radius: .8rem;
      border: 1px solid color-mix(in oklab, var(--line) 80%, transparent);
      background: color-mix(in oklab, var(--surface) 88%, transparent);
      color: var(--text);
      padding: .72rem .85rem;
      font: inherit;
      line-height: 1.45;
    }
    .vc-form-group input[type="text"]:focus,
    .vc-form-group select:focus {
      outline: none;
      border-color: color-mix(in oklab, var(--a) 55%, var(--line));
      box-shadow: 0 0 0 3px color-mix(in oklab, var(--a) 18%, transparent);
    }
    .vc-form-group .hint { margin: .35rem 0 0; color: var(--muted); font-size: .78rem; font-weight: 500; }
    .vc-steps { display: flex; gap: .75rem; margin: 0 0 1.25rem; }
    .vc-step {
      flex: 1;
      display: flex;
      align-items: center;
      gap: .55rem;
      border: 1px solid color-mix(in oklab, var(--line) 75%, transparent);
      background: transparent;
      color: var(--muted);
      border-radius: .9rem;
      padding: .55rem .7rem;
      font-weight: 700;
      font-size: .86rem;
      cursor: pointer;
      text-align: start;
    }
    .vc-step.active,
    .vc-step.done {
      color: var(--text);
      border-color: color-mix(in oklab, var(--a) 46%, var(--line));
      background: color-mix(in oklab, var(--a) 10%, var(--surface));
    }
    .vc-step.done .vc-step-index {
      background: #16a34a;
      color: #fff;
    }
    .vc-step-index {
      width: 1.55rem;
      height: 1.55rem;
      border-radius: 999px;
      display: grid;
      place-items: center;
      flex-shrink: 0;
      background: color-mix(in oklab, var(--a) 16%, var(--surface));
      color: var(--a);
      font-size: .78rem;
    }
    .vc-step-panel[hidden] { display: none !important; }
    .vc-script {
      border-radius: 1rem;
      padding: 1rem 1.05rem 1.05rem;
      margin-bottom: .9rem;
      background: linear-gradient(180deg, color-mix(in oklab, var(--a) 12%, var(--surface)), color-mix(in oklab, var(--surface) 92%, transparent));
      border: 1px solid color-mix(in oklab, var(--a) 28%, var(--line));
    }
    .vc-script-toolbar { display: flex; justify-content: space-between; align-items: center; gap: .6rem; }
    .vc-script h3 { margin: 0; font-size: .92rem; }
    .vc-script p { margin: .55rem 0 0; font-size: 1.08rem; line-height: 1.75; font-weight: 650; }
    #vcConsentScript {
      padding: .15rem 0 .1rem;
      letter-spacing: -.01em;
    }
    #vcConsentScript[dir="rtl"] {
      font-family: var(--font-body-ar);
      font-size: 1.28rem;
      line-height: 2.05;
    }
    html[dir="rtl"] .vc-shell {
      line-height: 1.8;
    }
    html[dir="rtl"] .vc-intro p,
    html[dir="rtl"] .vc-empty p,
    html[dir="rtl"] .vc-guidelines li,
    html[dir="rtl"] .vc-consent-tips li,
    html[dir="rtl"] .vc-notice p,
    html[dir="rtl"] .vc-alert,
    html[dir="rtl"] .vc-form-group label,
    html[dir="rtl"] .vc-form-group .hint,
    html[dir="rtl"] .vc-card-meta,
    html[dir="rtl"] .vc-consent label,
    html[dir="rtl"] .vc-recorder .hint {
      font-size: 1rem;
      line-height: 1.85;
    }
    .vc-script .hint { margin: .55rem 0 0; color: var(--muted); font-size: .8rem; }
    .vc-or {
      display: flex;
      align-items: center;
      gap: .7rem;
      margin: .85rem 0 .15rem;
      color: var(--muted);
      font-size: .75rem;
      font-weight: 700;
      letter-spacing: .04em;
      text-transform: uppercase;
    }
    .vc-or::before,
    .vc-or::after {
      content: "";
      flex: 1;
      height: 1px;
      background: color-mix(in oklab, var(--line) 80%, transparent);
    }
    html[dir="rtl"] .vc-or {
      letter-spacing: 0;
      text-transform: none;
      font-size: .84rem;
    }
    .vc-copy {
      border: 1px solid color-mix(in oklab, var(--line) 80%, transparent);
      background: var(--surface);
      color: var(--text);
      border-radius: 999px;
      padding: .28rem .7rem;
      font-size: .75rem;
      font-weight: 700;
      cursor: pointer;
    }
    .vc-range { margin-top: .45rem; font-size: .8rem; font-weight: 700; color: var(--muted); }
    .vc-range.ok { color: #16a34a; }
    .vc-range.bad { color: #dc2626; }
    .vc-actions-row { display: flex; gap: .5rem; }
    .vc-actions-row .btn { flex: 1; }
    .vc-consent-block { margin-top: .2rem; }
    .vc-bars { display: none; height: 18px; align-items: flex-end; justify-content: center; gap: 3px; margin-top: .45rem; }
    .vc-recorder.live .vc-bars { display: flex; }
    .vc-bars span {
      width: 3px;
      height: 6px;
      border-radius: 99px;
      background: var(--a);
      animation: vc-bar .9s ease-in-out infinite;
    }
    .vc-bars span:nth-child(2) { animation-delay: .12s; }
    .vc-bars span:nth-child(3) { animation-delay: .24s; }
    .vc-bars span:nth-child(4) { animation-delay: .08s; }
    .vc-bars span:nth-child(5) { animation-delay: .2s; }
    @keyframes vc-bar {
      0%, 100% { height: 5px; opacity: .45; }
      50% { height: 16px; opacity: 1; }
    }
    @media (max-width: 720px) {
      .vc-field-grid,
      .vc-actions-row {
        grid-template-columns: 1fr;
        flex-direction: column;
      }
      .vc-steps { flex-direction: row; }
      .vc-tab,
      .vc-step { flex: 1 1 0; min-height: 44px; }
      .vc-create-panel,
      .vc-empty,
      .vc-disabled-banner,
      .vc-card {
        padding: .9rem;
      }
      .vc-upgrade {
        padding: 1.25rem 1rem 1.15rem;
      }
      .vc-tabs,
      .vc-script-toolbar,
      .vc-card-actions,
      .vc-actions-row,
      .vc-meta {
        flex-wrap: wrap;
      }
      .vc-notice {
        flex-direction: column;
        align-items: stretch;
      }
      .vc-actions-row .btn,
      .vc-card-actions .btn,
      .vc-upgrade .btn,
      .vc-notice .btn,
      #vcNextStep,
      #vcEmptyState .btn {
        width: 100%;
        min-height: 44px;
      }
      .vc-script-toolbar { align-items: flex-start; }
      .vc-copy { min-height: 36px; }
      #vcConsentScript,
      #vcConsentScript[dir="rtl"] {
        font-size: 1rem;
        line-height: 1.7;
      }
      .vc-grid { grid-template-columns: minmax(0, 1fr); }
      .vc-upload-zone p,
      .vc-guidelines li,
      .vc-consent label,
      .vc-privacy,
      .vc-intro p,
      .vc-notice {
        overflow-wrap: anywhere;
      }
    }
    @media (max-width: 420px) {
      .vc-step { font-size: .8rem; padding: .5rem .6rem; }
      .vc-recorder { padding: .85rem .6rem; }
      .vc-shell h1 { font-size: 1.35rem; }
    }
    .bg-orb.a {
      width: 22rem;
      height: 22rem;
      background: color-mix(in oklab, var(--a) 60%, transparent);
      left: -6rem;
      top: 12vh;
    }
    .bg-orb.b {
      width: 26rem;
      height: 26rem;
      background: color-mix(in oklab, var(--b) 55%, transparent);
      right: -6rem;
      bottom: 6vh;
      animation-delay: -4s;
    }
    @keyframes float {
      0%, 100% { transform: translateY(0) scale(1); }
      50% { transform: translateY(-18px) scale(1.03); }
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    .main-content {
      padding: 1.5rem 0 3rem;
    }
    .dashboard-layout {
      display: grid;
      grid-template-columns: minmax(240px, 280px) minmax(0, 1fr);
      gap: 2.75rem;
      align-items: start;
    }
    .dashboard-content {
      min-width: 0;
    }
    .dashboard-panel {
      display: none;
    }
    .dashboard-panel.active {
      display: block;
    }
    .dashboard-panel + .dashboard-panel, #commandCenterSection, .reveal.dashboard-menu {
      margin-top: 1.5rem;
    }
    .dashboard-panel-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 1rem;
    }
    .dashboard-menu {
      position: sticky;
      top: 5.5rem;
      padding: 0 1rem 0 0;
    }
    html[dir="rtl"] .dashboard-menu {
      padding: 0 0 0 1rem;
    }
    .dashboard-menu-title {
      margin: 0 0 1.5rem;
      font-size: .8rem;
      text-transform: uppercase;
      letter-spacing: .1em;
      font-weight: 800;
      color: color-mix(in oklab, var(--text) 50%, var(--muted));
      padding-inline-start: 1rem;
    }
    .dashboard-menu-list {
      display: grid;
      gap: .25rem;
    }
    .dashboard-menu-link {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .8rem;
      padding: .85rem 1rem;
      border-radius: .75rem;
      border: 1px solid transparent;
      background: transparent;
      color: color-mix(in oklab, var(--text) 70%, var(--muted));
      text-decoration: none;
      font-size: .95rem;
      font-weight: 600;
      transition: all .25s cubic-bezier(.4, 0, .2, 1);
      position: relative;
    }
    .dashboard-menu-link::before {
      content: '';
      position: absolute;
      left: 0;
      top: 50%;
      bottom: 50%;
      width: 4px;
      background: linear-gradient(180deg, var(--a), var(--b));
      border-radius: 0 4px 4px 0;
      opacity: 0;
      transition: all .25s cubic-bezier(.4, 0, .2, 1);
    }
    html[dir="rtl"] .dashboard-menu-link::before {
      left: auto;
      right: 0;
      border-radius: 4px 0 0 4px;
    }
    .dashboard-menu-link:hover {
      color: var(--text);
      background: color-mix(in oklab, var(--line) 20%, transparent);
      transform: translateX(4px);
    }
    html[dir="rtl"] .dashboard-menu-link:hover {
      transform: translateX(-4px);
    }
    .dashboard-menu-link.active {
      color: var(--text);
      background: color-mix(in oklab, var(--line) 25%, transparent);
      font-weight: 700;
    }
    .dashboard-menu-link.active::before {
      opacity: 1;
      top: 15%;
      bottom: 15%;
    }
    .dashboard-menu-link span:last-child {
      font-size: .75rem;
      font-weight: 800;
      font-family: var(--font-mono);
      color: color-mix(in oklab, var(--a) 60%, var(--muted));
      opacity: .8;
    }
    .hero-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 1.5rem;
    }
    .page-card {
      background: color-mix(in oklab, var(--surface) 50%, transparent);
      backdrop-filter: blur(28px);
      -webkit-backdrop-filter: blur(28px);
      border: 1px solid color-mix(in oklab, var(--line) 40%, transparent);
      border-radius: 1.5rem;
      padding: clamp(1.5rem, 3vw, 2.25rem);
      box-shadow: 0 12px 40px rgba(0, 0, 0, .06), inset 0 1px 1px rgba(255,255,255,.03);
      position: relative;
      transition: all .3s ease;
    }
    .page-card:hover {
      border-color: color-mix(in oklab, var(--a) 25%, var(--line));
      box-shadow: 0 16px 50px rgba(0, 0, 0, .1), inset 0 1px 1px rgba(255,255,255,.05);
    }
    .page-card h1 {
      margin: 0 0 1.2rem;
      font-size: 1.65rem;
      font-weight: 800;
      letter-spacing: -.03em;
    }
    .page-card h1:first-child {
      margin-top: 0;
    }
    .page-card h2 {
      margin: 0 0 .6rem;
      font-size: 1.25rem;
      letter-spacing: -.01em;
    }
    .page-card    .meter {
      margin-top: 1.25rem;
      padding: 1.15rem;
      border-radius: 1rem;
      border: 1px solid color-mix(in oklab, var(--line) 40%, transparent);
      background: color-mix(in oklab, var(--surface) 40%, transparent);
      box-shadow: 0 4px 16px rgba(0,0,0,0.03), inset 0 1px 1px rgba(255,255,255,.03);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      transition: all 0.3s cubic-bezier(.4, 0, .2, 1);
    }
    .meter:hover {
      border-color: color-mix(in oklab, var(--b) 30%, var(--line));
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(0,0,0,0.08), inset 0 1px 1px rgba(255,255,255,.05);
    }
    .meter-header {
      margin-bottom: .75rem;
    }
    .meter-header small {
      color: var(--muted);
      font-weight: 500;
    }
    .meter-track {
      display: flex;
      align-items: stretch;
      justify-content: flex-start;
      height: 10px;
      border-radius: var(--radius-full);
      background: color-mix(in oklab, var(--line) 30%, transparent);
      box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
      overflow: hidden;
      border: 1px solid color-mix(in oklab, var(--line) 40%, transparent);
    }
    html[dir="rtl"] .meter-track {
      direction: rtl;
    }
    .meter-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--a), var(--b));
      transition: width .4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .meter-fill.requests {
      background: linear-gradient(90deg, var(--b), var(--a));
    }
    html[dir="rtl"] .meter-fill {
      background: linear-gradient(270deg, var(--a), var(--b));
    }
    html[dir="rtl"] .meter-fill.requests {
      background: linear-gradient(270deg, var(--b), var(--a));
    }

    .kpis {
      margin-top: 1.25rem;
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1rem;
    }
    .kpi {
      border-radius: 1rem;
      padding: 1.15rem;
      border: 1px solid color-mix(in oklab, var(--line) 40%, transparent);
      background: color-mix(in oklab, var(--surface) 40%, transparent);
      box-shadow: 0 4px 16px rgba(0,0,0,0.03), inset 0 1px 1px rgba(255,255,255,.03);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      transition: all 0.3s cubic-bezier(.4, 0, .2, 1);
    }
    .kpi small {
      color: var(--muted);
      font-size: .85rem;
      font-weight: 500;
    }
    .kpi b {
      display: block;
      font-size: 1.35rem;
      margin-top: .3rem;
      font-weight: 800;
    }

    .mini {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1rem;
    }
    .mini .box {
      border-radius: 1rem;
      padding: 1.15rem;
      border: 1px solid color-mix(in oklab, var(--line) 40%, transparent);
      background: color-mix(in oklab, var(--surface) 40%, transparent);
      box-shadow: 0 4px 16px rgba(0,0,0,0.03), inset 0 1px 1px rgba(255,255,255,.03);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      transition: all 0.3s cubic-bezier(.4, 0, .2, 1);
    }
    .kpi:hover, .mini .box:hover {
      border-color: color-mix(in oklab, var(--a) 30%, var(--line));
      transform: translateY(-3px);
      box-shadow: 0 12px 24px rgba(0,0,0,0.08), inset 0 1px 1px rgba(255,255,255,.05);
    }
    .mini .box small {
      color: var(--muted);
      font-weight: 500;
      font-size: .85rem;
    }
    .mini .box b {
      display: block;
      margin-top: .3rem;
      font-size: 1.35rem;
      font-weight: 800;
    }

    .main-grid {
      display: grid;
      grid-template-columns: minmax(0, 1.22fr) minmax(320px, .78fr);
      gap: 1rem;
      margin-top: 1.5rem;
    }
    .form-grid {
      display: grid;
      gap: .75rem;
    }
    .dashboard-generate-btn {
      justify-self: center;
      min-width: 200px;
      display: inline-flex;
      align-items: center;
      margin-top: 1.5rem;
      justify-content: center;
      gap: .6rem;
      padding: .95rem 2rem;
      border-radius: 10px;
      border: none;
      background: linear-gradient(135deg, var(--a), var(--b));
      color: #fff;
      font-size: 1rem;
      font-weight: 700;
      font-family: var(--font-body);
      cursor: pointer;
      transition: all .3s cubic-bezier(.4, 0, .2, 1);
      box-shadow: 0 4px 18px color-mix(in oklab, var(--a) 30%, transparent);
      position: relative;
      overflow: hidden;
    }
    .dashboard-generate-btn::after {
      content: "";
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, var(--b), var(--a));
      opacity: 0;
      transition: opacity .35s;
    }
    .dashboard-generate-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 32px color-mix(in oklab, var(--a) 45%, transparent);
    }
    .dashboard-generate-btn:hover::after {
      opacity: 1;
    }
    .dashboard-generate-btn span {
      position: relative;
      z-index: 1;
      display: inline-flex;
      align-items: center;
      gap: .5rem;
    }
    .dashboard-generate-btn svg {
      width: 18px;
      height: 18px;
      fill: currentColor;
      position: relative;
      z-index: 1;
    }
    .dashboard-generate-btn:disabled {
      opacity: .6;
      cursor: not-allowed;
      transform: none;
    }
    html[dir="rtl"] .dashboard-generate-btn {
      font-family: var(--font-body-ar);
    }
    #formMessage {
      justify-self: center;
    }
    .two-col {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .7rem;
    }
    .perf-panel {
      margin-top: .6rem;
      padding: 1.4rem;
      border-radius: 1.1rem;
      border: 1px solid color-mix(in oklab, var(--a) 25%, var(--line));
      /* background:
        radial-gradient(circle at 100% 0%, color-mix(in oklab, var(--a) 10%, transparent), transparent 35%),
        color-mix(in oklab, var(--surface) 84%, transparent); */
    }
    .perf-panel .perf-header {
      display: flex;
      align-items: center;
      gap: .4rem;
      font-size: .95rem;
      font-weight: 700;
      color: color-mix(in oklab, var(--a) 55%, var(--text));
      margin-bottom: 1rem;
    }
    .perf-panel .perf-header svg {
      width: 16px;
      height: 16px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
    }
    .perf-section {
      padding: .85rem 1rem;
      border-radius: .85rem;
      /* background: color-mix(in oklab, var(--surface2) 30%, var(--surface)); */
      /* border: 1px solid color-mix(in oklab, var(--a) 12%, var(--line)); */
    }
    .perf-section+.perf-section {
      /* margin-top: .6rem; */
    }
    .perf-row-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .6rem;
      margin-top: .6rem;
    }
    .perf-row-2 .perf-section + .perf-section {
      margin-top: 0;
    }
    .perf-section-label {
      display: flex;
      align-items: center;
      gap: .35rem;
      font-size: .82rem;
      font-weight: 700;
      color: color-mix(in oklab, var(--a) 45%, var(--text));
      margin-bottom: .5rem;
    }
    .slider-value {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 1.8em;
      padding: .1rem .4rem;
      margin-inline-start: .25rem;
      border-radius: var(--radius-full);
      background: linear-gradient(135deg, color-mix(in oklab, var(--a) 20%, transparent), color-mix(in oklab, var(--b) 20%, transparent));
      color: var(--text);
      font-size: .78rem;
      font-weight: 700;
      font-variant-numeric: tabular-nums;
      white-space: nowrap;
    }
    .slider-wrap {
      position: relative;
      padding: .35rem 0 .2rem;
    }
    .slider-wrap input[type="range"] {
      -webkit-appearance: none;
      appearance: none;
      width: 100%;
      height: 6px;
      border-radius: var(--radius-full);
      background: linear-gradient(
        90deg,
        color-mix(in oklab, var(--a) 35%, var(--line)),
        color-mix(in oklab, var(--b) 25%, var(--line))
      );
      outline: none;
      cursor: pointer;
      transition: background .2s;
    }
    .slider-wrap input[type="range"]::-webkit-slider-thumb {
      -webkit-appearance: none;
      appearance: none;
      width: 20px;
      height: 20px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--a), var(--b));
      cursor: pointer;
      border: 3px solid var(--surface);
      box-shadow:
        0 0 0 1px color-mix(in oklab, var(--a) 30%, transparent),
        0 2px 8px rgba(0,0,0,.25);
      transition: transform .15s, box-shadow .15s;
    }
    .slider-wrap input[type="range"]::-webkit-slider-thumb:hover {
      transform: scale(1.15);
      box-shadow:
        0 0 0 1px color-mix(in oklab, var(--a) 50%, transparent),
        0 4px 14px rgba(0,0,0,.3);
    }
    .slider-wrap input[type="range"]:active::-webkit-slider-thumb {
      transform: scale(1.2);
    }
    .slider-wrap input[type="range"]::-moz-range-thumb {
      width: 20px;
      height: 20px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--a), var(--b));
      cursor: pointer;
      border: 3px solid var(--surface);
      box-shadow:
        0 0 0 1px color-mix(in oklab, var(--a) 30%, transparent),
        0 2px 8px rgba(0,0,0,.25);
    }
    .slider-wrap input[type="range"]::-moz-range-track {
      height: 6px;
      border-radius: var(--radius-full);
      background: linear-gradient(
        90deg,
        color-mix(in oklab, var(--a) 35%, var(--line)),
        color-mix(in oklab, var(--b) 25%, var(--line))
      );
      border: none;
    }
    .premium-badge {
      font-size: 0.65rem;
      background: linear-gradient(135deg, var(--a), var(--b));
      color: white;
      padding: 0.15rem 0.45rem;
      border-radius: var(--radius-full);
      font-weight: 700;
      letter-spacing: 0.05em;
      margin-inline-start: 0.35rem;
      white-space: nowrap;
    }
    .s-card {
      border-radius: var(--radius-lg);
      border: 1px solid color-mix(in oklab, var(--a) 15%, var(--line));
      background: color-mix(in oklab, var(--surface) 80%, transparent);
      margin-top: .6rem;
    }
    .compact-toggle-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      padding: .85rem 1rem;
      cursor: pointer;
    }
    .compact-toggle-row .t-title {
      font-weight: 700;
      color: var(--text);
      font-size: .9rem;
      margin-bottom: .2rem;
    }
    .compact-toggle-row .t-desc {
      font-size: .8rem;
      color: var(--muted);
      line-height: 1.3;
    }
    .output-panel {
      min-height: 120px;
      margin-top: 1rem;
    }
    .output-panel .panel-title {
      display: flex;
      align-items: center;
      gap: .4rem;
      font-size: .82rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: var(--muted);
      margin-bottom: .6rem;
    }
    .output-panel .panel-title svg {
      width: 14px;
      height: 14px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
    }
    .output-panel audio {
      width: 100%;
      height: 34px;
    }
    .output-panel .audio-meta {
      margin-top: .45rem;
      font-size: .8rem;
      color: var(--muted);
      line-height: 1.45;
    }
    .output-placeholder {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: .5rem;
      padding: 1.5rem;
      text-align: center;
      color: var(--muted);
      font-size: .85rem;
      opacity: .6;
    }
    .output-placeholder svg {
      width: 32px;
      height: 32px;
      stroke: currentColor;
      fill: none;
      stroke-width: 1.5;
      opacity: .5;
    }
    html[dir="rtl"] .output-panel .panel-title {
      letter-spacing: 0;
    }
    label {
      display: block;
      font-size: .95rem;
      color: color-mix(in oklab, var(--text) 86%, var(--muted));
      margin-bottom: .35rem;
    }
    .control-pair label {
      margin-bottom: .4rem;
      font-size: .8rem;
      font-weight: 700;
      color: color-mix(in oklab, var(--text) 80%, var(--muted));
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    html[dir="rtl"] .control-pair label {
      letter-spacing: 0;
      text-transform: none;
    }
    input, select, textarea {
      width: 100%;
      padding: .85rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--line) 80%, transparent);
      background: color-mix(in oklab, var(--surface) 60%, transparent);
      color: var(--text);
      font-size: .95rem;
      transition: all .2s ease;
    }
    input:hover, select:hover, textarea:hover {
      border-color: color-mix(in oklab, var(--b) 45%, var(--line));
      background: color-mix(in oklab, var(--surface) 85%, transparent);
    }
    input:focus, select:focus, textarea:focus {
      outline: none;
      border-color: var(--brand-accent);
      box-shadow: 0 0 0 4px var(--brand-accent-glow);
      background: var(--surface);
    }
    .editor-panel input,
    .editor-panel textarea {
      border-radius: .85rem;
      border: 1px solid color-mix(in oklab, var(--line) 80%, transparent);
      background: color-mix(in oklab, var(--surface2) 40%, transparent);
      color: var(--text);
      font-family: var(--font-body);
      transition: all .25s ease;
    }
    .editor-panel input {
      font-size: .98rem;
      line-height: 1.5;
      padding: .95rem 1rem;
    }
    .editor-panel textarea {
      min-height: 180px;
      padding: 1rem;
      font-size: 1rem;
      line-height: 1.7;
      resize: vertical;
    }
    .perf-section textarea {
      font-family: var(--font-body);
    }
    input[dir="auto"], textarea[dir="auto"] {
      unicode-bidi: plaintext;
      text-align: start;
    }
    html[dir="rtl"] .editor-panel input,
    html[dir="rtl"] .editor-panel textarea {
      font-family: var(--font-body);
    }
    html[dir="rtl"] .perf-section textarea {
      font-family: var(--font-body);
    }
    html[lang="ar"] input[name="title"],
    html[lang="ar"] textarea[name="content"] {
      direction: rtl;
      text-align: right;
    }
    html[lang="ar"] input[name="title"]::placeholder,
    html[lang="ar"] textarea[name="content"]::placeholder {
      direction: rtl;
      text-align: right;
    }
    input:focus, select:focus, textarea:focus {
      outline: none;
      border-color: var(--a);
      box-shadow: 0 0 0 3px var(--brand-accent-glow);
    }
    .queue-card select,
    .perf-section select {
      padding: .85rem 1.1rem;
      padding-inline-end: 2.8rem;
      border-radius: .75rem;
      border: 1px solid var(--line);
      background-color: color-mix(in oklab, var(--surface2) 50%, var(--surface));
      color: var(--text);
      font-size: .9rem;
      font-family: var(--font-body);
      line-height: 1.35;
      cursor: pointer;
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%239fb0d4' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
      background-repeat: no-repeat;
      background-size: 1.05em;
      background-position: calc(100% - .9rem) center;
      transition: border-color .25s, box-shadow .25s, background-color .2s;
    }
    .queue-card select:hover,
    .perf-section select:hover {
      border-color: color-mix(in oklab, var(--b) 35%, var(--line));
      background-color: color-mix(in oklab, var(--surface2) 65%, var(--surface));
    }
    .char-counter {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: .25rem;
      margin-top: .4rem;
      padding: .25rem .5rem;
      font-size: .8rem;
      color: var(--muted);

    }
    .vocal-tags {
      margin-top: .5rem;
      border: 1px solid var(--line, rgba(255,255,255,.12));
      border-radius: 12px;
      padding: .5rem .75rem;
      background: color-mix(in oklab, var(--surface2, #1b1b24) 60%, transparent);
    }
    .vocal-tags > summary {
      cursor: pointer;
      font-weight: 600;
      font-size: .9rem;
      display: flex;
      flex-wrap: wrap;
      align-items: baseline;
      gap: .5rem;
    }
    .vocal-tags > summary small {
      font-weight: 400;
      color: var(--muted);
      font-size: .78rem;
    }
    .vocal-tag-groups {
      display: grid;
      gap: .45rem;
      margin-top: .6rem;
    }
    .vocal-tag-group {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: .35rem;
    }
    .vocal-tag-group-label {
      min-width: 5.5rem;
      font-size: .78rem;
      color: var(--muted);
    }
    .vocal-tag-chip {
      border: 1px solid var(--line, rgba(255,255,255,.14));
      background: var(--surface, transparent);
      color: inherit;
      border-radius: 999px;
      padding: .2rem .6rem;
      font-size: .78rem;
      font-family: var(--font-mono, ui-monospace, monospace);
      cursor: pointer;
      min-height: 32px;
    }
    .vocal-tag-chip:hover,
    .vocal-tag-chip:focus-visible {
      border-color: var(--a);
      outline: none;
    }
    .vocal-tag-tips {
      margin-top: .6rem;
      font-size: .82rem;
    }
    .vocal-tag-tips summary {
      cursor: pointer;
      color: var(--muted);
    }
    .vocal-tag-tips ul {
      margin: .4rem 0 0;
      padding-inline-start: 1.1rem;
      display: grid;
      gap: .3rem;
      line-height: 1.6;
    }
    .char-counter.warning {
      color: var(--warning, #f59e0b);
    }
    .char-counter.danger {
      color: var(--danger);
      font-weight: 600;
    }
    html[data-theme="light"] .queue-card select,
    html[data-theme="light"] .perf-section select {
      background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%235b6c89' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    }
    html[dir="rtl"] .queue-card select,
    html[dir="rtl"] .perf-section select {
      background-position: .9rem center;
      padding-inline-start: 1.1rem;
      padding-inline-end: 2.8rem;
      font-family: var(--font-body-ar);
    }

    .note {
      padding: .75rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--ok) 55%, var(--line));
      background: color-mix(in oklab, var(--ok) 14%, var(--surface));
      color: color-mix(in oklab, var(--ok) 80%, var(--text));
      margin: .5rem 0 .8rem;
      font-size: .9rem;
    }
    .error {
      padding: .75rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--danger) 55%, var(--line));
      background: color-mix(in oklab, var(--danger) 13%, var(--surface));
      color: color-mix(in oklab, var(--danger) 80%, var(--text));
      margin: .5rem 0 .8rem;
      font-size: .9rem;
    }

     .table-card {
       margin-top: 1.5rem;
     }
     .table-header {
       display: flex;
       align-items: center;
       justify-content: space-between;
       gap: 1rem;
       margin-bottom: 1rem;
       flex-wrap: wrap;
     }
     .table-header h2 {
       margin: 0;
     }
     .jobs-count {
       font-size: .85rem;
       color: var(--muted);
     }
     table {
       width: 100%;
       border-collapse: collapse;
       font-size: .9rem;
     }
    th, td {
      padding: 1.1rem .85rem;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 40%, transparent);
      text-align: left;
      vertical-align: middle;
    }
    th {
      color: color-mix(in oklab, var(--text) 70%, var(--muted));
      font-weight: 800;
      text-transform: uppercase;
      font-size: .8rem;
      letter-spacing: .05em;
    }
    .status {
      display: inline-block;
      padding: .25rem .5rem;
      border-radius: var(--radius-full);
      font-size: .75rem;
      border: 1px solid var(--line);
    }
    .status.queued { background: color-mix(in oklab, var(--a) 12%, var(--surface)); }
    .status.processing { background: color-mix(in oklab, var(--a) 12%, var(--surface)); }
    .status.done { background: color-mix(in oklab, var(--ok) 12%, var(--surface)); }
    .status.failed { background: color-mix(in oklab, var(--danger) 12%, var(--surface)); }
    table audio {
      max-width: 230px;
      width: 230px;
      height: 32px;
    }

    .sidebar-info p {
      margin: 0 0 .45rem;
      font-size: .9rem;
      color: var(--muted);
    }
    .info-list {
      display: flex;
      flex-direction: column;
      gap: 0;
      margin-bottom: 1.25rem;
      background: color-mix(in oklab, var(--surface2) 30%, var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 0 .85rem;
    }
    .info-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .8rem 0;
      border-bottom: 1px dashed color-mix(in oklab, var(--line) 60%, transparent);
      font-size: .95rem;
    }
    .info-row:last-child {
      border-bottom: none;
    }
    .info-label {
      color: var(--muted);
    }
    .info-value {
      color: var(--text);
      font-weight: 600;
      text-align: right;
    }
    .sidebar-info hr {
      border: 0;
      border-top: 1px solid var(--line);
      margin: 1.25rem 0;
    }
    .sidebar-actions {
      display: flex;
      gap: .55rem;
      flex-wrap: wrap;
      margin-top: .85rem;
    }
    .sidebar-actions .btn {
      flex: 1;
      text-align: center;
      justify-content: center;
    }
    .key-inline-actions {
      display: flex;
      justify-content: flex-end;
      margin-top: .65rem;
    }
    .key-inline-actions .btn {
      width: 100%;
      justify-content: center;
    }
    .mini-message {
      margin-top: .65rem;
    }

    .dashboard-shell {
      position: relative;
    }
    .hero-grid,
    .main-grid {
      gap: 1rem;
      align-items: stretch;
    }
    .page-card {
      position: relative;
      overflow: visible;
      border-radius: calc(var(--radius-lg) + .2rem);
    }
    .page-card::before {
      content: "";
      position: absolute;
      inset: 0 0 auto 0;
      height: 1px;
      background: linear-gradient(90deg, transparent, color-mix(in oklab, white 40%, var(--a)), transparent);
      opacity: .7;
      pointer-events: none;
      border-top-left-radius: inherit;
      border-top-right-radius: inherit;
    }
    .command-card {
      /* Padding inherited uniformly from .page-card */
    }
    .command-card .summary-block {
      margin-top: 1.5rem;
    }
    .command-card .summary-block h1 {
      margin: 0 0 1rem;
      font-size: 1.4rem;
    }
    .meter {
      margin-top: 1rem;
      padding: 1rem;
      border-radius: 1rem;
      border: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
      background: color-mix(in oklab, var(--surface) 50%, transparent);
      box-shadow: 0 4px 16px rgba(0,0,0,0.05);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      transition: all 0.25s ease;
    }
    .meter:hover {
      border-color: color-mix(in oklab, var(--b) 30%, var(--line));
    }
    .meter-header {
      margin-bottom: .65rem;
    }
    .kpis,
    .mini {
      gap: .8rem;
      margin-top: 1rem;
    }
    .mini {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .kpi,
    .mini .box {
      border-radius: 1rem;
      padding: 1rem;
      border: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
      background: color-mix(in oklab, var(--surface) 50%, transparent);
      box-shadow: 0 4px 16px rgba(0,0,0,0.05);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      transition: all 0.25s ease;
    }
    .kpi:hover, .mini .box:hover {
      border-color: color-mix(in oklab, var(--a) 30%, var(--line));
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(0,0,0,0.1);
    }
    .kpi b,
    .mini .box b {
      font-size: 1.35rem;
    }
    .queue-card {
      /* Padding inherited uniformly from .page-card */
    }
    .form-grid > div {
      position: relative;
    }
    .form-grid > button,
    .form-grid > #formMessage {
      position: relative;
    }
    .editor-panel {
      padding: 1rem;
      /* border-radius: 1.1rem;
      border: 1px solid color-mix(in oklab, var(--line) 75%, var(--a) 25%);
      background: color-mix(in oklab, var(--surface) 82%, transparent); */
    }
    .control-pair > div {
      padding: .95rem;
      border-radius: 1rem;
}
    /* Duplicate studio-panel definitions removed since we consolidated into perf-panel above */
    .plan-card {
      position: sticky;
      top: 5.5rem;
    }
    .dashboard-panel .plan-card {
      position: static;
      top: auto;
    }
    .info-list {
      border-radius: 1.05rem;
      padding: 0 1rem;
      /* background:
        radial-gradient(circle at 100% 0%, color-mix(in oklab, var(--b) 10%, transparent), transparent 34%),
        color-mix(in oklab, var(--surface) 84%, transparent);
      border-color: color-mix(in oklab, var(--line) 74%, var(--b) 26%); */
    }
    .jobs-shell {
      /* Padding inherited uniformly from .page-card */
    }
    .table-header {
      margin-bottom: 1.1rem;
    }
    .table-header h1:first-child {
      margin-top: 0;
    }
    table {
      border-collapse: separate;
      border-spacing: 0;
    }
    thead th {
      position: sticky;
      top: 0;
      background: color-mix(in oklab, var(--surface2) 92%, transparent);
      backdrop-filter: blur(8px);
    }
    tbody tr {
      background: color-mix(in oklab, var(--surface) 40%, transparent);
      transition: background .2s ease;
    }
    tbody tr:hover td {
      background: color-mix(in oklab, var(--surface2) 48%, transparent);
    }
    th:first-child, td:first-child {
      border-inline-start: 1px solid color-mix(in oklab, var(--line) 40%, transparent);
    }
    th:last-child, td:last-child {
      border-inline-end: 1px solid color-mix(in oklab, var(--line) 40%, transparent);
    }
    thead th:first-child {
      border-top-inline-start-radius: 1rem;
    }
    thead th:last-child {
      border-top-inline-end-radius: 1rem;
    }

    .pagination {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: .4rem;
      margin-top: 1.25rem;
      flex-wrap: wrap;
    }
    .pagination-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 2.2rem;
      height: 2.2rem;
      padding: 0 .5rem;
      border-radius: 10px;
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--muted);
      font-size: .85rem;
      font-weight: 600;
      cursor: pointer;
      transition: all .2s ease;
    }
    .pagination-btn:hover:not(:disabled) {
      color: var(--text);
      border-color: color-mix(in oklab, var(--a) 40%, var(--line));
    }
    .pagination-btn:disabled {
      opacity: .4;
      cursor: not-allowed;
    }
    .pagination-btn.active {
      background: linear-gradient(135deg, var(--a), var(--b));
      border-color: transparent;
      color: #fff;
    }
    .job-delete-btn {
      min-width: auto;
      height: 1.95rem;
      padding: 0 .7rem;
      font-size: .8rem;
      color: color-mix(in oklab, var(--danger) 85%, var(--text));
      border-color: color-mix(in oklab, var(--danger) 35%, var(--line));
      background: color-mix(in oklab, var(--danger) 8%, var(--surface));
    }
    .job-delete-btn:hover:not(:disabled) {
      color: color-mix(in oklab, var(--danger) 92%, var(--text));
      border-color: color-mix(in oklab, var(--danger) 55%, var(--line));
      background: color-mix(in oklab, var(--danger) 12%, var(--surface));
    }
    .job-actions {
      display: flex;
      align-items: center;
      gap: .45rem;
      flex-wrap: wrap;
    }
    .history-source-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-inline-start: .45rem;
      padding: .12rem .5rem;
      border-radius: 999px;
      border: 1px solid color-mix(in oklab, var(--b) 35%, var(--line));
      background: color-mix(in oklab, var(--b) 12%, var(--surface));
      color: color-mix(in oklab, var(--b) 78%, var(--text));
      font-size: .68rem;
      font-weight: 700;
      letter-spacing: .02em;
      white-space: nowrap;
      vertical-align: middle;
    }
    .job-actions-muted {
      color: var(--muted);
      font-size: .78rem;
      font-weight: 600;
    }
    .job-reuse-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: auto;
      height: 1.95rem;
      padding: 0 .7rem;
      border-radius: 10px;
      border: 1px solid color-mix(in oklab, var(--a) 28%, var(--line));
      background: color-mix(in oklab, var(--a) 10%, var(--surface));
      color: color-mix(in oklab, var(--a) 76%, var(--text));
      font-size: .8rem;
      font-weight: 700;
      text-decoration: none;
      transition: all .2s ease;
    }
    .job-reuse-btn:hover {
      color: var(--text);
      border-color: color-mix(in oklab, var(--a) 48%, var(--line));
      background: color-mix(in oklab, var(--a) 14%, var(--surface));
    }
    .pagination-info {
      font-size: .82rem;
      color: var(--muted);
      padding: 0 .5rem;
    }

    .reveal {
      opacity: 0;
      transform: translateY(12px);
      transition: opacity .5s ease, transform .5s ease;
    }
    .reveal.show {
      opacity: 1;
      transform: translateY(0);
    }

    @keyframes rowSlideIn {
      from { opacity: 0; transform: translateY(-15px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @keyframes rowHighlight {
      0% { background-color: color-mix(in oklab, var(--a) 20%, transparent); }
      100% { background-color: transparent; }
    }
    .new-job-row {
      animation: rowSlideIn 1.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }
    .new-job-row td {
      animation: rowHighlight 3.5s ease-out forwards;
    }

    .s-card {
      background: color-mix(in oklab, var(--surface) 72%, transparent);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      border: 1px solid color-mix(in oklab, var(--line) 65%, transparent);
      border-radius: 1.15rem;
      padding: 1.25rem;
      position: relative;
      overflow: hidden;
      box-shadow: 0 8px 32px rgba(0, 0, 0, .18);
      transition: border-color .25s, box-shadow .25s;
    }
    .s-card::before {
      content: "";
      position: absolute;
      inset: 0 0 auto 0;
      height: 1px;
      background: linear-gradient(90deg, transparent, color-mix(in oklab, white 22%, var(--a)), transparent);
      opacity: .5;
      pointer-events: none;
    }
    .s-card:hover {
      border-color: color-mix(in oklab, var(--a) 30%, var(--line));
    }
    .output-panel {
      min-height: 120px;
      margin-top: 1.5rem;
      padding: 1.5rem;
      background: color-mix(in oklab, var(--surface2) 30%, var(--surface));
      border-radius: 1.25rem;
    }
    .output-panel .panel-title {
      display: flex;
      align-items: center;
      gap: .5rem;
      font-size: .95rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .04em;
      color: var(--muted);
      margin-bottom: .6rem;
    }
    .output-panel .panel-title svg {
      width: 14px;
      height: 14px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
    }
    .output-panel .custom-audio-wrapper {
      max-width: 100%;
      padding: .75rem 1rem;
      border-radius: 2rem;
      margin: 1rem 0;
      background: color-mix(in oklab, var(--surface) 80%, transparent);
    }
    .output-panel .custom-audio-time {
      font-size: .85rem;
    }
    .output-panel .audio-meta {
      margin-top: .75rem;
      font-size: .85rem;
      color: color-mix(in oklab, var(--text) 70%, var(--muted));
      line-height: 1.5;
      font-weight: 500;
    }
    .output-placeholder {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: .5rem;
      padding: 1.5rem;
      text-align: center;
      color: var(--muted);
      font-size: .85rem;
      opacity: .6;
    }
    .output-placeholder svg {
      width: 32px;
      height: 32px;
      stroke: currentColor;
      fill: none;
      stroke-width: 1.5;
      opacity: .5;
    }
    html[dir="rtl"] .output-panel .panel-title {
      letter-spacing: 0;
    }

    @media (max-width: 980px) {
      .dashboard-layout,
      .hero-grid,
      .kpis,
      .two-col,
      .mini {
        grid-template-columns: 1fr;
      }
      .dashboard-menu,
      html[dir="rtl"] .dashboard-menu {
        position: static;
        padding: 0;
      }
      .dashboard-menu-link:hover,
      html[dir="rtl"] .dashboard-menu-link:hover {
        transform: none;
      }
      .dashboard-menu-list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .dashboard-menu-link {
        min-width: 0;
      }
      .dashboard-menu-link span:first-child {
        min-width: 0;
        overflow-wrap: anywhere;
      }
      .plan-card {
        position: static;
      }
      .dashboard-content,
      .page-card {
        min-width: 0;
        overflow-wrap: anywhere;
      }
    }
    @media (max-width: 640px) {
      .main-content {
        padding: 1rem 0 2rem;
      }
      .page-card {
        padding: 1rem;
      }
      .page-card h1 {
        font-size: 1.3rem;
      }
      .dashboard-layout {
        gap: 1rem;
      }
      .dashboard-menu {
        min-width: 0;
      }
      .reveal.dashboard-menu {
        margin-top: 0;
      }
      .dashboard-menu-list {
        display: flex;
        gap: .45rem;
        overflow-x: auto;
        overscroll-behavior-x: contain;
        scroll-snap-type: x proximity;
        scrollbar-width: none;
        padding-bottom: .2rem;
      }
      .dashboard-menu-list::-webkit-scrollbar {
        display: none;
      }
      .dashboard-menu-link {
        flex: 0 0 auto;
        padding: .55rem .95rem;
        border: 1px solid var(--line);
        border-radius: var(--radius-full, 999px);
        font-size: .88rem;
        white-space: nowrap;
        scroll-snap-align: start;
      }
      .dashboard-menu-link::before,
      .dashboard-menu-link span:last-child {
        display: none;
      }
      .dashboard-menu-link span:first-child {
        overflow-wrap: normal;
      }
      .dashboard-menu-link.active {
        border-color: color-mix(in oklab, var(--a) 55%, var(--line));
        background: color-mix(in oklab, var(--a) 18%, transparent);
      }
      .perf-panel {
        padding: 1rem .75rem;
      }
      .perf-section {
        padding: .6rem .35rem;
      }
      .perf-row-2 {
        grid-template-columns: minmax(0, 1fr);
        gap: 0;
      }
      .control-pair > div,
      .editor-panel {
        padding: .85rem;
      }
      .dashboard-generate-btn {
        width: 100%;
        min-width: 0;
      }
      #formMessage {
        width: 100%;
      }
      .meter-header {
        align-items: flex-start;
        flex-direction: column;
        gap: .2rem;
      }
      audio {
        width: 180px;
        max-width: 180px;
      }
      th, td {
        white-space: nowrap;
      }
      .sidebar-actions .btn {
        flex: 1 1 100%;
        justify-content: center;
      }
      .pagination {
        justify-content: flex-start;
      }
    }
  </style>
  <script>
    (() => {
      const saved = localStorage.getItem('listen_theme');
      document.documentElement.setAttribute('data-theme', saved || document.documentElement.getAttribute('data-theme') || 'dark');
    })();
  </script>
</head>
<body>
  @php
    $planNameBySlug = static function (?string $slug, ?string $fallback = null): string {
      return match ((string) $slug) {
        'free' => __('ui.pricing.plan_name_free'),
        'starter' => __('ui.pricing.plan_name_starter'),
        'creator' => __('ui.pricing.plan_name_creator'),
        'pro' => __('ui.pricing.plan_name_pro'),
        'studio_max' => __('ui.pricing.plan_name_studio_max'),
        default => (string) ($fallback ?: '-'),
      };
    };
    $statusLabelByKey = static function (?string $status): string {
      return match (strtolower((string) $status)) {
        'queued' => __('ui.dashboard.status_queued'),
        'processing' => __('ui.dashboard.status_processing'),
        'done' => __('ui.dashboard.status_done'),
        'failed' => __('ui.dashboard.status_failed'),
        default => strtoupper((string) $status),
      };
    };
    $languageLabelByCode = static function (?string $code): string {
      return match ((string) $code) {
        'ar' => __('ui.dashboard.arabic'),
        'en' => __('ui.dashboard.english'),
        default => (string) ($code ?: '-'),
      };
    };
    $processingPriorityLabel = static function (?string $value): string {
      return strtolower((string) $value) === 'high'
        ? __('ui.dashboard.priority_high')
        : __('ui.dashboard.priority_standard');
    };
    $cleanSpeakerLabel = static function (?string $value): string {
      return ltrim(trim((string) $value), '+');
    };
    $formatTierLabel = static function (?string $label): string {
      $text = trim((string) $label);
      if ($text !== '' && preg_match('/[A-Za-z0-9+]/', $text) && !preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
        return "\u{2066}{$text}\u{2069}";
      }
      return $text;
    };
    $activePlanDisplayName = $planNameBySlug($activePlan?->slug ?? 'free', $activePlan?->name ?? __('ui.dashboard.free_plan'));
    $speakerDisplayLabel = static function (?string $voiceName) use ($voiceNameMap, $cleanSpeakerLabel): string {
      $entry = $voiceNameMap[$voiceName] ?? null;
      if (! $entry) return $cleanSpeakerLabel(__('ui.dashboard.default_speaker'));
      $label = app()->getLocale() === 'ar'
        ? ($entry['label_ar'] ?? $entry['label'] ?? __('ui.dashboard.default_speaker'))
        : ($entry['label'] ?? __('ui.dashboard.default_speaker'));
      return $cleanSpeakerLabel($label);
    };
  @endphp
  @include('components.header')

  <!-- Background Orbs -->
  <div class="bg-orb a"></div>
  <div class="bg-orb b"></div>

  <main class="main-content dashboard-shell" style="max-width: 1700px; padding: 2rem clamp(1rem, 3vw, 3rem); margin: 0 auto; min-height: 80vh;">
    <div class="dashboard-layout">
      <aside class="reveal dashboard-menu">
@includeWhen(\App\Support\SiteDesign::isNew(), 'components.design-new.dashboard-sidebar-head')
        <nav class="dashboard-menu-list" aria-label="{{ __('ui.dashboard.menu_panel') }}">
          <a class="dashboard-menu-link active" href="#commandCenterSection" data-dashboard-nav>
            <span>{{ __('ui.dashboard.command_center') }}</span>
            <span></span>
          </a>
          <a class="dashboard-menu-link" href="#generateVoiceSection" data-dashboard-nav>
            <span>{{ __('ui.dashboard.generate_new_voice') }}</span>
            <span></span>
          </a>

          @if(($voiceCloningVisible ?? false) || ($voiceCloningUpgrade ?? false))
          <a class="dashboard-menu-link" href="#voiceCloningSection" data-dashboard-nav >
            <span>{{ __('ui.nav.voice_cloning') }}</span>
            <span></span>
          </a>
          @endif

          <a class="dashboard-menu-link" href="#planDefaultsSection" data-dashboard-nav>
            <span>{{ __('ui.dashboard.plan_and_defaults') }}</span>
            <span></span>
          </a>
          <a class="dashboard-menu-link" href="#recentVoicesSection" data-dashboard-nav>
            <span>{{ __('ui.dashboard.recent_voices') }}</span>
            <span></span>
          </a>

        </nav>
@includeWhen(\App\Support\SiteDesign::isNew(), 'components.design-new.dashboard-sidebar-foot')
      </aside>

      <div class="dashboard-content">
    <section id="commandCenterSection" class="dashboard-panel active" data-dashboard-section>
@if(\App\Support\SiteDesign::isNew())
@include('components.design-new.dashboard-overview')
@else
      <div class="hero-grid">
        <article class="page-card reveal command-card">
          <h1>
            <span class="grad-text">{{ __('ui.dashboard.command_center') }}</span>
            <span style="display:block; font-size:1rem; color:var(--muted); margin-top:.3rem; font-weight:500; letter-spacing:0;">{{ __('ui.dashboard.command_center_subtitle') }}</span>
          </h1>

          @php
            $remainingChars = max(0, (int) $planChars - (int) $usage->total_chars);
            $remainingRequests = $requestsUnlimited
              ? null
              : max(0, (int) $planRequests - (int) $usage->total_requests);
          @endphp

          <div class="meter">
            <div class="meter-header">
              <small>{{ __('ui.dashboard.characters_usage', ['plan' => $activePlanDisplayName]) }}</small>
              <small id="usageCharsText">{{ __('ui.dashboard.chars_remaining_of_total', ['remaining' => number_format($remainingChars), 'total' => number_format($planChars)]) }}</small>
            </div>
            <div class="meter-track"><div class="meter-fill" id="usageCharsMeter" style="width:{{ max(0, 100 - $usageCharsPercent) }}%"></div></div>
          </div>

          <div class="meter">
            <div class="meter-header">
              <small>{{ __('ui.dashboard.requests_usage') }}</small>
              <small id="usageRequestsText">
                @if ($requestsUnlimited)
                  {{ __('ui.dashboard.used_requests_of_unlimited', ['used' => number_format($usage->total_requests)]) }}
                @else
                  {{ __('ui.dashboard.requests_remaining_of_total', ['remaining' => number_format($remainingRequests), 'total' => number_format($planRequests)]) }}
                @endif
              </small>
            </div>
            <div class="meter-track"><div class="meter-fill requests" id="usageRequestsMeter" style="width:{{ max(0, 100 - $usageRequestsPercent) }}%"></div></div>
          </div>

          <div class="kpis">
            <div class="kpi"><small>{{ __('ui.dashboard.requests') }}</small><b id="kpiRequests">{{ $usage->total_requests }}</b></div>
            <div class="kpi"><small>{{ __('ui.dashboard.used_characters') }}</small><b id="kpiChars">{{ number_format($usage->total_chars) }}</b></div>
            <div class="kpi"><small>{{ __('ui.dashboard.total_jobs') }}</small><b id="kpiTotal">{{ $stats['total'] }}</b></div>
          </div>

          <div class="summary-block">
            <h1><span class="grad-text">{{ __('ui.dashboard.system_health') }}</span></h1>
            <div class="mini">
              <div class="box"><small>{{ __('ui.dashboard.processing') }}</small><div><b id="statProcessing">{{ $stats['processing'] }}</b></div></div>
              <div class="box"><small>{{ __('ui.dashboard.done') }}</small><div><b id="statDone">{{ $stats['done'] }}</b></div></div>
              <div class="box"><small>{{ __('ui.dashboard.failed') }}</small><div><b id="statFailed">{{ $stats['failed'] }}</b></div></div>
            </div>
          </div>

        </article>
      </div>
@endif
    </section>

    <section id="generateVoiceSection" class="dashboard-panel" data-dashboard-section>
      <article class="page-card reveal queue-card" id="queueCardSection">
        <h1><span class="grad-text">{{ __('ui.dashboard.generate_new_voice') }}</span></h1>
        @if(session('ok'))<div class="note">{{ session('ok') }}</div>@endif
        @if(session('error'))<div class="error">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
        @php
          $prefillValue = fn (string $key, mixed $default = null) => old($key, data_get($reusePreset ?? [], $key, $default));
          $prefillTranslate = (string) old('translate', data_get($reusePreset ?? [], 'translate') ? '1' : '0');
          $prefillDialogue = (string) old('is_dialogue', data_get($reusePreset ?? [], 'is_dialogue') ? '1' : '0');
        @endphp
        @if($reusePreset)
          <div class="note">{{ __('ui.dashboard.reusing_settings_notice', ['number' => $reusePreset['display_number'] ?? $reusePreset['job_id']]) }}</div>
        @endif

        <form id="voiceForm" method="POST" action="{{ route('dashboard.queue') }}" class="form-grid">
          @csrf
          <div class="editor-panel">
            <label>{{ __('ui.dashboard.title_optional') }}</label>
            <input name="title" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'auto' }}" value="{{ $prefillValue('title') }}" placeholder="{{ __('ui.dashboard.notification_summary') }}" />
          </div>

          <div class="editor-panel">
            <label>{{ __('ui.dashboard.text_content') }}</label>
            <textarea name="content" id="contentTextarea" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'auto' }}" rows="8" required placeholder="{{ __('ui.dashboard.paste_text') }}">{{ $prefillValue('content') }}</textarea>
            <div class="char-counter" id="charCounter">
              <span id="charCount">0</span> / <span id="charLimit">{{ number_format($maxCharsPerRequest) }}</span> {{ __('ui.dashboard.characters') }}
            </div>
            @if(($planTier ?? 'standard') === 'hd')
            <details class="vocal-tags" id="vocalTags">
              <summary>🎙️ {{ __('ui.dashboard.vocal_tags') }} <small>{{ __('ui.dashboard.vocal_tags_hint') }}</small></summary>
              <div class="vocal-tag-groups">
                @foreach(\App\Support\GeminiVocalTags::GROUPS as $group => $groupTags)
                  <div class="vocal-tag-group">
                    <span class="vocal-tag-group-label">{{ __('ui.dashboard.vocal_tag_groups.'.$group) }}</span>
                    @foreach($groupTags as $vocalTag)
                      <button type="button" class="vocal-tag-chip" data-vocal-tag="{{ $vocalTag }}" dir="ltr">&lt;{{ $vocalTag }}&gt;</button>
                    @endforeach
                  </div>
                @endforeach
              </div>
              <details class="vocal-tag-tips">
                <summary>💡 {{ __('ui.dashboard.vocal_tags_tips') }}</summary>
                <ul>
                  @foreach(__('ui.dashboard.vocal_tags_tip_items') as $tip)
                    <li>{{ $tip }}</li>
                  @endforeach
                </ul>
              </details>
            </details>
            @endif
          </div>

          <div class="two-col control-pair">
            <div>
              <label>{{ __('ui.dashboard.voice_language') }}</label>
              <select name="target_language" id="target_language">
                <option value="ar" {{ $prefillValue('target_language', 'ar') === 'ar' ? 'selected' : '' }}>{{ __('ui.dashboard.arabic') }}</option>
                <option value="en" {{ $prefillValue('target_language', 'ar') === 'en' ? 'selected' : '' }}>{{ __('ui.dashboard.english') }}</option>
              </select>
            </div>
            <div>
              <label>{{ __('ui.dashboard.speaker') }}</label>
              <select name="voice_key" id="voice_key"></select>
            </div>
          </div>

          {{-- Performance Controls --}}
          @if(($planTier ?? 'standard') === 'hd')
          <div class="perf-panel" id="studioPanel">
            <div class="perf-header">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
              {{ __('ui.dashboard.voice_studio') }} <span class="premium-badge">{{ __('ui.pricing.premium') }}</span>
            </div>

            <div class="perf-row-2">
              <div class="perf-section">
                <div class="perf-section-label">🎭 {{ __('ui.dashboard.performance_style') }}</div>
                <select name="performance_style" id="performance_style" required>
                  <option value="">-- {{ __('ui.dashboard.choose_performance_style') }} --</option>
                  @foreach(($studioPerformanceStyles ?? []) as $styleOption)
                    <option value="{{ $styleOption['value'] ?? '' }}">
                      {{ app()->getLocale() === 'ar' ? ($styleOption['label_ar'] ?? $styleOption['label'] ?? ($styleOption['value'] ?? '')) : ($styleOption['label'] ?? ($styleOption['value'] ?? '')) }}
                    </option>
                  @endforeach
                </select>
              </div>

              <div class="perf-section">
                <div class="perf-section-label">💫 {{ __('ui.dashboard.emotion_tone') }}</div>
                <select name="emotion" id="emotion" required>
                  <option value="">-- {{ __('ui.dashboard.choose_emotion') }} --</option>
                  @foreach(($studioEmotions ?? []) as $emotionOption)
                    <option value="{{ $emotionOption['value'] ?? '' }}">
                      {{ app()->getLocale() === 'ar' ? ($emotionOption['label_ar'] ?? $emotionOption['label'] ?? ($emotionOption['value'] ?? '')) : ($emotionOption['label'] ?? ($emotionOption['value'] ?? '')) }}
                    </option>
                  @endforeach
                </select>
              </div>
            </div>

            @if($customPromptEnabled ?? false)
            <div class="perf-section" style="margin-top:.6rem;">
              <div class="perf-section-label">✨ {{ __('ui.dashboard.style_prompt') }}</div>
              <textarea name="style_prompt" id="style_prompt" rows="2" maxlength="2000" placeholder="{{ __('ui.dashboard.style_prompt_placeholder') }}" style="resize:vertical; min-height:60px; line-height: 1.5;">{{ $prefillValue('style_prompt') }}</textarea>
            </div>
            @endif

            <div class="perf-section">
              <div class="perf-section-label">🌍 {{ __('ui.dashboard.dialect') }}</div>
              <select name="dialect" id="dialect">
                <option value="">{{ __('ui.dashboard.dialect_auto') }}</option>
              </select>
            </div>

            <div class="perf-row-2">
              <div class="perf-section">
                <div class="perf-section-label">
                  ⚡ {{ __('ui.dashboard.speed') }}
                  <span class="slider-value" id="speedVal">{{ $prefillValue('speed', '1.0') }}</span>x
                </div>
                <div class="slider-wrap">
                  <input type="range" name="speed" id="speed" min="0.5" max="2.0" step="0.1" value="{{ $prefillValue('speed', '1.0') }}" oninput="document.getElementById('speedVal').textContent=this.value">
                </div>
              </div>
              <div class="perf-section">
                <div class="perf-section-label">
                  🎵 {{ __('ui.dashboard.pitch') }}
                  <span class="slider-value" id="pitchVal">{{ $prefillValue('pitch', '0') }}</span>
                </div>
                <div class="slider-wrap">
                  <input type="range" name="pitch" id="pitch" min="-10" max="10" step="1" value="{{ $prefillValue('pitch', '0') }}" oninput="document.getElementById('pitchVal').textContent=this.value">
                </div>
              </div>
            </div>
            @if($dialogueEnabled ?? false)
            <div class="s-card" style="margin-top: .6rem;">
              <label class="compact-toggle-row" for="dialogueToggle">
                <div class="txt">
                  <div class="t-title">🗣️ {{ __('ui.dashboard.dialogue_mode') }}</div>
                  <div class="t-desc" id="dialogueHint">{{ __('ui.dashboard.dialogue_hint') }}</div>
                </div>
                <div class="toggle-switch">
                  <input type="checkbox" id="dialogueToggle" {{ $prefillDialogue === '1' ? 'checked' : '' }}>
                  <span class="toggle-slider"></span>
                </div>
              </label>
              <input type="hidden" name="is_dialogue" id="dialogueSelect" value="{{ $prefillDialogue }}">
            </div>
            @endif
          </div>
          @endif

          <div class="s-card">
            <label class="compact-toggle-row" for="translateToggle">
              <div class="txt">
                <div class="t-title">🌐 {{ __('ui.dashboard.translate_before_speaking') }}</div>
                <div class="t-desc" id="translateHint">{{ __('ui.dashboard.translate_enabled_hint') }}</div>
              </div>
              <div class="toggle-switch" aria-label="{{ __('ui.dashboard.translate_before_speaking') }}">
                <input type="checkbox" id="translateToggle" {{ $prefillTranslate === '1' ? 'checked' : '' }}>
                <span class="toggle-slider"></span>
              </div>
            </label>
            <input type="hidden" name="translate" id="translateSelect" value="{{ $prefillTranslate }}">
          </div>

          <button class="dashboard-generate-btn" id="generateBtn" type="submit">
            <span>
              <svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              {{ __('ui.dashboard.generate_voice_now') }}
            </span>
          </button>
          <div id="formMessage"></div>
        </form>

        <div class="s-card output-panel" id="generatedAudioWrap" style="display:none;">
          <div class="panel-title">
            <svg viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            {{ __('ui.dashboard.instant_preview') }}
          </div>
          <audio id="generatedPreviewAudio" controls preload="none"></audio>
          <div class="audio-meta" id="generatedAudioMeta"></div>
        </div>

        <div class="s-card output-panel reveal show" id="generatedOutputPlaceholder">
          <div class="output-placeholder">
            <svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
            {{ __('ui.dashboard.instant_preview') }}
          </div>
        </div>
      </article>
    </section>

    <section id="planDefaultsSection" class="dashboard-panel" data-dashboard-section>
      <article class="page-card reveal sidebar-info plan-card">
        <h1><span class="grad-text">{{ __('ui.dashboard.plan_and_defaults') }}</span></h1>

        <div class="info-list">
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.current_plan') }}</span><span class="info-value"><span class="status done">{{ $activePlanDisplayName }}</span></span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.voice_quality') }}</span><span class="info-value">{{ $formatTierLabel($planTierLabel) }}</span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.monthly_chars') }}</span><span class="info-value">{{ number_format($planChars) }}</span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.monthly_reqs') }}</span><span class="info-value">{{ $requestsUnlimited ? __('ui.dashboard.unlimited') : $planRequests }}</span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.max_chars_per_req') }}</span><span class="info-value">{{ $activePlan?->max_chars_per_request ?? 600 }}</span></div>
        </div>

        <div class="info-list">
          <div class="info-row"><span class="info-label">{{ __('ui.pricing.topup_chars') }}</span><span class="info-value">{{ (($activePlan?->topup_char_price_cents ?? null) !== null || ($activePlan?->overage_price_per_million_cents ?? null) !== null) ? "\u{2066}$".number_format(((int) (($activePlan?->topup_char_price_cents ?? $activePlan?->overage_price_per_million_cents) ?? 0)) / 100, 0)."\u{2069}".__('ui.pricing.topup_per_million') : __('ui.upgrade.not_allowed') }}</span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.commercial_use') }}</span><span class="info-value">{{ ($activePlan?->commercial_use_allowed ?? false) ? __('ui.dashboard.yes') : __('ui.dashboard.no') }}</span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.processing') }}</span><span class="info-value">{{ $processingPriorityLabel((string) ($activePlan?->processing_priority ?? 'standard')) }}</span></div>
        </div>

        <div class="info-list">
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.arabic_default') }}</span><span class="info-value">{{ app()->getLocale() === 'ar' ? (($voiceNameMap[$defaultVoiceAr] ?? [])['label_ar'] ?? ($voiceNameMap[$defaultVoiceAr] ?? [])['label'] ?? __('ui.dashboard.default')) : (($voiceNameMap[$defaultVoiceAr] ?? [])['label'] ?? __('ui.dashboard.default')) }}</span></div>
          <div class="info-row"><span class="info-label">{{ __('ui.dashboard.english_default') }}</span><span class="info-value">{{ app()->getLocale() === 'ar' ? (($voiceNameMap[$defaultVoiceEn] ?? [])['label_ar'] ?? ($voiceNameMap[$defaultVoiceEn] ?? [])['label'] ?? __('ui.dashboard.default')) : (($voiceNameMap[$defaultVoiceEn] ?? [])['label'] ?? __('ui.dashboard.default')) }}</span></div>
        </div>

        <div class="info-list">
          <div class="info-row">
            <span class="info-label">{{ __('ui.dashboard.extension_api_key') }}</span>
            <span class="info-value">
              @if($extensionApiState['has_api_key'])
                <span dir="ltr" id="extensionKeyValue">{{ $extensionApiState['masked_key'] }}</span>
              @else
                {{ __('ui.dashboard.extension_key_not_created') }}
              @endif
            </span>
          </div>
          <div class="info-row">
            <span class="info-label">{{ __('ui.dashboard.extension_key_created') }}</span>
            <span class="info-value">
              {{ $extensionApiState['has_api_key'] ? ($extensionApiState['api_key_created_human'] ?? __('ui.dashboard.just_now')) : '-' }}
            </span>
          </div>
          @if($extensionApiState['has_api_key'])
          <div class="key-inline-actions">
            <button
              type="button"
              class="btn"
              id="copyExtensionKeyBtn"
              data-copy-value="{{ $extensionApiState['masked_key'] }}"
            >
              {{ __('ui.extension.copy_to_clipboard') }}
            </button>
          </div>
          <div id="extensionKeyMessage" class="mini-message"></div>
          @endif
        </div>

        <div class="sidebar-actions">
          <a class="btn" href="{{ route('pricing') }}">{{ __('ui.dashboard.pricing_and_plans') }}</a>
          <a class="btn btn-primary" href="{{ route('billing.page') }}">{{ __('ui.dashboard.upgrade_plan') }}</a>
        </div>
        <div class="sidebar-actions" style="margin-top: .6rem;">
          <a class="btn" href="{{ route('manage-subscription.page') }}">{{ __('ui.dashboard.manage_subscription') }}</a>
          <a class="btn" href="{{ route('browsers-extension.page') }}#api-management">
            {{ __('ui.dashboard.manage_extension_key') }}
          </a>
        </div>
      </article>
    </section>

    <section id="recentVoicesSection" class="dashboard-panel" data-dashboard-section>
      <div class="page-card table-card reveal jobs-shell">
        <div class="table-header">
          <h1><span class="grad-text">{{ __('ui.dashboard.recent_voices') }}</span></h1>
          <span class="jobs-count" id="jobsCount"></span>
        </div>
        <div style="overflow-x: auto;">
          <table>
            <thead>
              <tr>
                <th>{{ __('ui.dashboard.table_id') }}</th><th>{{ __('ui.dashboard.table_title') }}</th><th>{{ __('ui.dashboard.table_status') }}</th><th>{{ __('ui.dashboard.table_flow') }}</th><th>{{ __('ui.dashboard.table_speaker') }}</th><th>{{ __('ui.dashboard.table_audio') }}</th><th>{{ __('ui.dashboard.table_created') }}</th><th>{{ __('ui.dashboard.table_actions') }}</th>
              </tr>
            </thead>
            <tbody id="jobsTableBody">
              @forelse($jobs as $job)
                <tr data-job-id="{{ $job->id }}" data-job-data="{{ json_encode([
                  'id' => $job->id,
                  'row_key' => 'audio:'.$job->id,
                  'display_number' => $job->displayNumber(),
                  'title' => $job->textItem?->title,
                  'text_excerpt' => \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $job->input_text)), 42, ''),
                  'status' => $job->status,
                  'source_language' => $job->source_language,
                  'target_language' => $job->target_language,
	                  'voice_label' => $speakerDisplayLabel($job->voice_name),
                  'voice_tier' => \App\Support\VoiceTier::label((string) ($job->voice_tier ?: \App\Support\VoiceTier::STANDARD)),
                  'translate' => $job->translate,
                  'duration_seconds' => $job->duration_seconds,
                  'audio_url' => $job->audio_path ? \App\Support\AudioUrl::fromPath($job->audio_path) : null,
                  'error_message' => $job->error_message,
                  'created_human' => $job->created_at?->diffForHumans(),
                  'created_at_ts' => $job->created_at?->getTimestamp(),
                  'can_reuse_settings' => $job->hasReusablePremiumSettings(),
                  'reuse_url' => $job->hasReusablePremiumSettings() ? route('dashboard', ['reuse' => $job->displayNumber()]).'#generateVoiceSection' : null,
                  'is_demo_history' => false,
                ]) }}">
                  <td>#{{ $job->displayNumber() }}</td>
                  <td>{{ $job->textItem?->title ?: '-' }}</td>
                  <td><span class="status {{ $job->status }}">{{ $statusLabelByKey($job->status) }}</span></td>
                  <td>{{ $languageLabelByCode($job->source_language) }} -> {{ $languageLabelByCode($job->target_language) }}</td>
	                  <td>{{ $speakerDisplayLabel($job->voice_name) }}</td>
                  <td>
                    @if($job->audio_path)
                      <audio
                        controls
                        preload="none"
                        src="{{ \App\Support\AudioUrl::fromPath($job->audio_path) }}"
                        data-download-language="{{ $job->target_language }}"
                        data-download-text="{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $job->input_text)), 42, '') }}"
	                        data-download-speaker="{{ $speakerDisplayLabel($job->voice_name) }}"
                        data-download-quality="{{ \App\Support\VoiceTier::label((string) ($job->voice_tier ?: \App\Support\VoiceTier::STANDARD)) }}"
                      ></audio>
                    @elseif($job->status === 'failed')
	                      <small style="color: var(--danger);">{{ $job->error_message ?: __('ui.dashboard.generation_failed') }}</small>
	                    @else
	                      <small style="color: var(--muted);">{{ __('ui.dashboard.processing_ellipsis') }}</small>
	                    @endif
                  </td>
                  <td>{{ $job->created_at?->diffForHumans() }}</td>
                  <td>
                    <div class="job-actions">
                      @if($job->hasReusablePremiumSettings())
                        <a class="job-reuse-btn" href="{{ route('dashboard', ['reuse' => $job->displayNumber()]) }}#generateVoiceSection">{{ __('ui.dashboard.reuse_settings') }}</a>
                      @endif
                      <button
                        type="button"
                        class="pagination-btn job-delete-btn"
                        data-action="delete-job"
                        data-job-id="{{ $job->id }}"
                      >
	                      {{ __('ui.dashboard.delete') }}
	                    </button>
                    </div>
                  </td>
                </tr>
              @empty
	                <tr id="jobsEmptyRow"><td colspan="8" style="color: var(--muted);">{{ __('ui.dashboard.no_jobs_yet') }}</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        <div class="pagination" id="jobsPagination"></div>
      </div>
    </section>

    @if(($voiceCloningVisible ?? false) || ($voiceCloningUpgrade ?? false))
    @php
      $vcStudioOpen = ($voiceCloningPlanEnabled ?? false)
          && ($voiceCloningEnabledGlobally ?? false)
          && ($voiceCloningMaxVoices ?? 0) > 0;
      $vcCanCreate = $vcStudioOpen && ($voiceCloningCount ?? 0) < ($voiceCloningMaxVoices ?? 0);
      $vcAtLimit = $vcStudioOpen
          && ($voiceCloningCount ?? 0) > 0
          && ($voiceCloningCount ?? 0) >= ($voiceCloningMaxVoices ?? 0);
    @endphp
    <section id="voiceCloningSection" class="dashboard-panel" data-dashboard-section>
      <article class="page-card reveal vc-shell">
        <div class="vc-intro">
          <h1><span class="grad-text">{{ __('ui.voice_cloning.page_title') }}</span></h1>
          <p>{{ __('ui.voice_cloning.page_subtitle') }}</p>
          @if(($voiceCloningVisible ?? false) || $vcStudioOpen)
          <div class="vc-meta">
            @if(($voiceCloningVisible ?? false) && \App\Support\VoiceCloningAccess::isPreviewOnly())
            <span class="vc-preview-pill">{{ __('ui.voice_cloning.preview_badge') }}</span>
            @endif
            @if($vcStudioOpen)
            <div class="vc-count">{{ __('ui.voice_cloning.voice_count', ['count' => $voiceCloningCount, 'max' => $voiceCloningMaxVoices]) }}</div>
            @endif
          </div>
          @endif
        </div>

        @if($voiceCloningUpgrade ?? false)
          <section class="vc-upgrade" aria-labelledby="vcUpgradeTitle">
            <div class="vc-upgrade-mark" aria-hidden="true">
              <svg viewBox="0 0 128 128" fill="currentColor">
                <rect x="8" y="59" width="10" height="10" rx="5"/>
                <rect x="26" y="42" width="10" height="44" rx="5"/>
                <rect x="44" y="28" width="10" height="72" rx="5"/>
                <rect x="62" y="14" width="10" height="100" rx="5"/>
                <rect x="80" y="28" width="10" height="72" rx="5"/>
                <rect x="98" y="42" width="10" height="44" rx="5"/>
                <rect x="116" y="59" width="10" height="10" rx="5"/>
              </svg>
            </div>
            <h2 id="vcUpgradeTitle">{{ __('ui.voice_cloning.upgrade_title') }}</h2>
            <p>{{ __('ui.voice_cloning.upgrade_subtitle') }}</p>
            <ul class="features-list">
              <li>
                <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="currentColor" opacity=".14"/><path d="M6 10.2 8.6 12.8 14.2 7.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>{{ __('ui.voice_cloning.upgrade_feature_1') }}</span>
              </li>
              <li>
                <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="currentColor" opacity=".14"/><path d="M6 10.2 8.6 12.8 14.2 7.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>{{ __('ui.voice_cloning.upgrade_feature_2') }}</span>
              </li>
            </ul>
            <a class="btn btn-primary" href="{{ route('billing.page') }}">{{ __('ui.voice_cloning.upgrade_cta') }}</a>
          </section>
        @else
        <div id="vcAlerts"></div>

        @if(!($voiceCloningEnabledGlobally ?? false))
          <section class="vc-disabled-banner">
            <p>{{ __('ui.voice_cloning.feature_disabled') }}</p>
          </section>
        @else
          @if(!($voiceCloningPlanEnabled ?? false))
            <section class="vc-disabled-banner">
              <p>{{ __('ui.voice_cloning.plan_disabled') }}</p>
            </section>
          @endif

          <h2 id="vcVoicesTitle" style="font-size:1.05rem;margin-bottom:.75rem;{{ ($voiceCloningList?->count() ?? 0) > 0 ? '' : 'display:none;' }}">{{ __('ui.voice_cloning.your_voices_title') }}</h2>
          <section class="vc-grid" id="vcVoiceGrid" style="{{ ($voiceCloningList?->count() ?? 0) > 0 ? '' : 'display:none;' }}">
            @foreach($voiceCloningList as $voice)
              @php
                $vcInitialProgress = \App\Services\VoiceCloningService::progressPercent(
                    (string) $voice->status,
                    \App\Services\VoiceCloningService::elapsedSeconds($voice->attemptStartedAt()),
                );
                $vcLanguageLabel = match ((string) $voice->language_code) {
                    'ar-XA' => __('ui.voice_cloning.language_ar'),
                    'en-US' => __('ui.voice_cloning.language_en_us'),
                    'en-GB' => __('ui.voice_cloning.language_en_gb'),
                    'en-AU' => __('ui.voice_cloning.language_en_au'),
                    default => (string) $voice->language_code,
                };
                $vcCreatedLabel = $voice->created_at
                    ? $voice->created_at->locale(app()->getLocale() === 'ar' ? 'ar' : 'en')->isoFormat('D MMM YYYY')
                    : '';
              @endphp
                <article class="vc-card" data-voice-id="{{ $voice->id }}" data-voice-name="{{ $voice->name }}" data-language-code="{{ $voice->language_code }}" data-status="{{ $voice->status }}" data-created-ts="{{ $voice->created_at?->getTimestamp() }}" data-started-ts="{{ $voice->attemptStartedAt()?->getTimestamp() }}" data-failure-reason="{{ $voice->isFailed() ? \App\Services\VoiceCloningService::publicFailureMessage($voice) : '' }}">
                  <div class="vc-card-header">
                    <h3 class="vc-card-name">{{ $voice->name }}</h3>
                    <span class="vc-status {{ $voice->status }}">
                      @if($voice->isProcessing())<span class="dot"></span>@endif
                      {{ __('ui.voice_cloning.status_' . $voice->status) }}
                    </span>
                  </div>
                  <div class="vc-card-meta">
                    <span>{{ __('ui.voice_cloning.created_on') }} {{ $vcCreatedLabel }}</span>
                    <span>{{ $vcLanguageLabel }} &middot; {{ $voice->source_type === 'recording' ? __('ui.voice_cloning.record_tab') : __('ui.voice_cloning.upload_tab') }}</span>
                  </div>
                    @if($voice->isFailed())
                    <div class="vc-alert error vc-failure-hint">{{ \App\Services\VoiceCloningService::publicFailureMessage($voice) }}</div>
                    @if(auth()->user()?->is_admin === true)
                      @php $vcDetail = \App\Services\VoiceCloningService::studioFailureDetail($voice); @endphp
                      @if($vcDetail !== '')
                        <details class="vc-tech-detail">
                          <summary>{{ __('ui.voice_cloning.technical_details') }}</summary>
                          <code dir="ltr">{{ $vcDetail }}</code>
                        </details>
                      @endif
                    @endif
                  @endif
                  @if($voice->isProcessing())
                    <div class="vc-processing-hint" style="font-size:.83rem;color:var(--muted);margin-bottom:.6rem">{{ __('ui.voice_cloning.processing_hint') }}</div>
                  @endif
                  <div class="vc-progress {{ $voice->isProcessing() ? '' : 'is-hidden' }}" data-vc-progress>
                    <div class="vc-progress-track">
                      <div class="vc-progress-fill" data-vc-progress-fill style="width: {{ $vcInitialProgress }}%"></div>
                    </div>
                    <div class="vc-progress-meta">
                      <span data-vc-progress-status>{{ __('ui.voice_cloning.status_' . $voice->status) }}</span>
                      <span data-vc-progress-percent>{{ $vcInitialProgress }}%</span>
                    </div>
                    <div class="vc-progress-delay" data-vc-progress-delay></div>
                  </div>
                  <div class="vc-card-actions">
                    @if(\App\Services\VoiceCloningService::canRetry($voice))
                      <button type="button" class="btn btn-primary vc-retry-btn" data-voice-id="{{ $voice->id }}">{{ __('ui.voice_cloning.retry_button') }}</button>
                    @endif
                    @if(\App\Services\VoiceCloningService::canReplaceConsent($voice))
                      <button type="button" class="btn btn-primary vc-reconsent-btn" data-voice-id="{{ $voice->id }}">{{ __('ui.voice_cloning.replace_consent_button') }}</button>
                    @endif
                    @if($voice->isReady())
                      <a href="{{ route('dashboard', ['cloned_voice' => $voice->id]) }}#generateVoiceSection" class="btn btn-primary" data-vc-action="use">{{ __('ui.voice_cloning.use_in_generator') }}</a>
                    @endif
                    @if(\App\Services\VoiceCloningService::canUserDelete($voice))
                      <button
                        type="button"
                        class="btn btn-ghost vc-delete-btn"
                        data-voice-id="{{ $voice->id }}"
                        data-confirm="{{ __('ui.voice_cloning.delete_confirm') }}"
                      >
                        {{ __('ui.voice_cloning.delete_voice') }}
                      </button>
                    @endif
                  </div>
                </article>
            @endforeach
          </section>
          @if($vcStudioOpen)
            <section class="vc-empty" id="vcEmptyState" @if(($voiceCloningList?->count() ?? 0) > 0) style="display:none" @endif>
              <h3>{{ __('ui.voice_cloning.empty_title') }}</h3>
              <p>{{ __('ui.voice_cloning.empty_subtitle') }}</p>
              <button type="button" class="btn btn-primary" onclick="document.getElementById('vcCreatePanel')?.scrollIntoView({behavior:'smooth', block:'start'})">{{ __('ui.voice_cloning.create_first') }}</button>
            </section>
          @endif

          @if($vcStudioOpen)
            <section class="vc-create-panel" id="vcCreatePanel" @if(! $vcCanCreate) style="display:none" @endif>
              <h2 id="vcCreateTitle">{{ __('ui.voice_cloning.create_title') }}</h2>

              <div class="vc-reconsent-banner" id="vcReconsentBanner" hidden>
                <div>
                  <strong id="vcReconsentTitle"></strong>
                  <p>{{ __('ui.voice_cloning.replace_consent_hint') }}</p>
                </div>
                <button type="button" class="btn btn-ghost" id="vcReconsentCancel">{{ __('ui.voice_cloning.cancel') }}</button>
              </div>

              <div class="vc-guidelines" id="vcGuidelines">
                <h4>{{ __('ui.voice_cloning.guidelines_title') }}</h4>
                <ul>
                  <li>{{ __('ui.voice_cloning.guideline_1', ['min' => $voiceCloningMinDuration, 'max' => $voiceCloningMaxDuration]) }}</li>
                  <li>{{ __('ui.voice_cloning.guideline_2') }}</li>
                  <li>{{ __('ui.voice_cloning.guideline_3') }}</li>
                  <li>{{ __('ui.voice_cloning.guideline_4') }}</li>
                  <li>{{ __('ui.voice_cloning.guideline_5') }}</li>
                </ul>
              </div>

              <div class="vc-steps" role="tablist">
                <button type="button" class="vc-step active" id="vcStepSample" data-vc-step="1">
                  <span class="vc-step-index">1</span>
                  <span>{{ __('ui.voice_cloning.step_sample') }}</span>
                </button>
                <button type="button" class="vc-step" id="vcStepConsent" data-vc-step="2">
                  <span class="vc-step-index">2</span>
                  <span>{{ __('ui.voice_cloning.step_consent') }}</span>
                </button>
              </div>

              <form id="vcCreateForm" enctype="multipart/form-data" novalidate>
                @csrf
                <input type="hidden" name="source_type" id="vcSourceType" value="upload">
                <input type="hidden" name="audio_duration" id="vcAudioDuration" value="0">
                <input type="hidden" name="consent_duration" id="vcConsentDuration" value="0">

                <div class="vc-step-panel" data-vc-step-panel="1">
                  <div class="vc-field-grid">
                    <div class="vc-form-group">
                      <label for="vcName">{{ __('ui.voice_cloning.voice_name_label') }}</label>
                      <input type="text" id="vcName" name="name" minlength="2" maxlength="50" placeholder="{{ __('ui.voice_cloning.voice_name_placeholder') }}">
                    </div>
                    <div class="vc-form-group">
                      <label id="vcLanguageLabel">{{ __('ui.voice_cloning.language_label') }}</label>
                      <div class="vc-language-value" role="text" aria-labelledby="vcLanguageLabel">{{ app()->getLocale() === 'ar' ? __('ui.voice_cloning.language_ar') : __('ui.voice_cloning.language_en') }}</div>
                      <input type="hidden" name="language_code" id="vcLanguage" value="{{ app()->getLocale() === 'ar' ? 'ar-XA' : 'en-US' }}">
                    </div>
                  </div>

                  <div class="vc-tabs">
                    <button type="button" class="vc-tab active" data-tab="upload">{{ __('ui.voice_cloning.upload_tab') }}</button>
                    <button type="button" class="vc-tab" data-tab="recording">{{ __('ui.voice_cloning.record_tab') }}</button>
                  </div>

                  <div class="vc-tab-panel" id="vcUploadPanel">
                    <div class="vc-upload-zone" id="vcUploadZone">
                      <input type="file" name="audio_file" id="vcFileInput" accept=".wav,.mp3,.m4a,.mp4,.ogg,.webm,.flac,audio/*">
                      <p>{{ __('ui.voice_cloning.upload_hint') }}</p>
                      <p class="formats">{{ __('ui.voice_cloning.upload_formats') }} &middot; {{ __('ui.voice_cloning.upload_max_size', ['size' => $voiceCloningMaxUploadMb]) }}</p>
                    </div>
                    <div class="vc-file-info" id="vcFileInfo" style="display:none">
                      <span id="vcFileName"></span>
                      <span class="remove" id="vcFileRemove">&times;</span>
                    </div>
                    <p class="vc-range" id="vcSampleRange">{{ __('ui.voice_cloning.duration_range', ['min' => $voiceCloningMinDuration, 'max' => $voiceCloningMaxDuration]) }}</p>
                  </div>

                  <div class="vc-tab-panel" id="vcRecordPanel" style="display:none">
                    <div class="vc-recorder" id="vcRecorder">
                      <button type="button" class="rec-btn" id="vcRecBtn" aria-label="{{ __('ui.voice_cloning.record_start') }}">
                        <span class="inner"></span>
                      </button>
                      <div class="vc-bars" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
                      <div class="vc-timer" id="vcTimer">00:00</div>
                      <p class="hint" id="vcRecHint">{{ __('ui.voice_cloning.record_start') }}</p>
                      <p class="hint" style="margin-top:.45rem">{{ __('ui.voice_cloning.min_duration_note', ['min' => $voiceCloningMinDuration, 'max' => $voiceCloningMaxDuration]) }}</p>
                      <div class="vc-playback" id="vcRecPlayback" hidden>
                        <audio class="vc-preview-audio" id="vcRecPreview" controls preload="none" hidden aria-label="{{ __('ui.voice_cloning.listen_back') }}"></audio>
                      </div>
                      <div class="rec-actions" id="vcRecActions" style="display:none">
                        <button type="button" class="btn btn-ghost" id="vcRecRetry">{{ __('ui.voice_cloning.record_retry') }}</button>
                      </div>
                    </div>
                  </div>

                  <button type="button" class="btn btn-primary" id="vcNextStep" style="width:100%;margin-top:.9rem">{{ __('ui.voice_cloning.next_step') }}</button>
                </div>

                <div class="vc-step-panel" data-vc-step-panel="2" hidden>
                  @php
                    $vcConsentLocale = app()->getLocale() === 'ar' ? 'ar-XA' : 'en-US';
                    $vcConsentText = $voiceCloningConsentScripts[$vcConsentLocale] ?? '';
                  @endphp
                  <div class="vc-form-group vc-consent-language">
                    <label for="vcConsentLanguage">{{ __('ui.voice_cloning.consent_language_label') }}</label>
                    <div class="vc-segmented" role="radiogroup" aria-label="{{ __('ui.voice_cloning.consent_language_label') }}">
                      @if($vcConsentLocale === 'ar-XA')
                        <button type="button" class="vc-segment active" data-consent-lang="ar-XA" role="radio" aria-checked="true">العربية</button>
                      @else
                        <button type="button" class="vc-segment active" data-consent-lang="en-US" role="radio" aria-checked="true">English</button>
                      @endif
                    </div>
                    <input type="hidden" name="consent_language" id="vcConsentLanguage" value="{{ $vcConsentLocale }}">
                    <p class="hint">{{ __('ui.voice_cloning.consent_language_hint') }}</p>
                  </div>

                  <div class="vc-script">
                    <div class="vc-script-toolbar">
                      <h3>{{ __('ui.voice_cloning.consent_title') }}</h3>
                      <button type="button" class="vc-copy" id="vcCopyConsent">{{ __('ui.voice_cloning.consent_copy') }}</button>
                    </div>
                    <p id="vcConsentScript" dir="{{ $vcConsentLocale === 'ar-XA' ? 'rtl' : 'ltr' }}">{{ $vcConsentText }}</p>
                    <p class="hint">{{ __('ui.voice_cloning.consent_script_hint') }}</p>
                    <ul class="vc-consent-tips">
                      <li>{{ __('ui.voice_cloning.consent_tip_1') }}</li>
                      <li>{{ __('ui.voice_cloning.consent_tip_latin') }}</li>
                      <li>{{ __('ui.voice_cloning.consent_tip_2') }}</li>
                      <li>{{ __('ui.voice_cloning.consent_tip_3') }}</li>
                    </ul>
                  </div>

                  <div class="vc-consent-block">
                    <label>{{ __('ui.voice_cloning.consent_record_label') }}</label>
                    <div class="vc-recorder" id="vcConsentRecorder" style="margin-top:.45rem">
                      <button type="button" class="rec-btn" id="vcConsentRecBtn" aria-label="{{ __('ui.voice_cloning.consent_record_label') }}">
                        <span class="inner"></span>
                      </button>
                      <div class="vc-bars" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
                      <div class="vc-timer" id="vcConsentTimer">00:00</div>
                      <p class="hint" id="vcConsentRecHint">{{ __('ui.voice_cloning.record_start') }}</p>
                      <div class="vc-playback" id="vcConsentPlayback" hidden>
                        <audio class="vc-preview-audio" id="vcConsentPreview" controls preload="none" hidden aria-label="{{ __('ui.voice_cloning.listen_back') }}"></audio>
                      </div>
                      <div class="rec-actions" id="vcConsentRecActions" style="display:none">
                        <button type="button" class="btn btn-ghost" id="vcConsentRecRetry">{{ __('ui.voice_cloning.record_retry') }}</button>
                      </div>
                    </div>
                  </div>

                  <p class="vc-or">{{ __('ui.voice_cloning.consent_or') }}</p>
                  <div class="vc-form-group" style="margin-top:.15rem">
                    <label for="vcConsentFileInput">{{ __('ui.voice_cloning.consent_upload_label') }}</label>
                    <div class="vc-upload-zone" id="vcConsentUploadZone">
                      <input type="file" name="consent_audio" id="vcConsentFileInput" accept=".wav,.mp3,.m4a,.mp4,.ogg,.webm,.flac,audio/*">
                      <p>{{ __('ui.voice_cloning.upload_hint') }}</p>
                    </div>
                    <div class="vc-file-info" id="vcConsentFileInfo" style="display:none">
                      <span id="vcConsentFileName"></span>
                      <span class="remove" id="vcConsentFileRemove">&times;</span>
                    </div>
                  </div>

                  <div class="vc-consent">
                    <input type="checkbox" id="vcConsent" name="consent_confirmed" value="1" required>
                    <label for="vcConsent">{{ __('ui.voice_cloning.consent_label') }}</label>
                  </div>

                  <div class="vc-privacy">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    {{ __('ui.voice_cloning.privacy_note') }}
                  </div>

                  <div class="vc-actions-row">
                    <button type="button" class="btn btn-ghost" id="vcBackStep">{{ __('ui.voice_cloning.back_step') }}</button>
                    <button type="submit" class="btn btn-primary" id="vcSubmitBtn">
                      <span id="vcSubmitText">{{ __('ui.voice_cloning.submit_button') }}</span>
                      <span id="vcSubmitLoading" style="display:none">{{ __('ui.voice_cloning.submitting') }}</span>
                    </button>
                  </div>
                </div>
              </form>
            </section>
            <div class="vc-notice" id="vcLimitNotice" @if(! $vcAtLimit) style="display:none" @endif>
              <p>{{ __('ui.voice_cloning.limit_reached', ['max' => $voiceCloningMaxVoices]) }}</p>
              <a class="btn btn-primary" href="{{ route('billing.page') }}">{{ __('ui.voice_cloning.upgrade_cta') }}</a>
            </div><!-- /vcLimitNotice -->
          @endif
        @endif
        @endif
      </article>
    </section>
    @endif
      </div>
    </div>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @php
    $dashboardI18n = [
      'any' => __('ui.dashboard.any'),
      'no_speakers' => __('ui.dashboard.no_speakers'),
      'translate_enabled_hint' => __('ui.dashboard.translate_enabled_hint'),
      'translate_disabled_hint' => __('ui.dashboard.translate_disabled_hint'),
      'chars' => __('ui.dashboard.chars'),
      'requests' => __('ui.dashboard.requests'),
      'unlimited' => __('ui.dashboard.unlimited'),
      'status_queued' => __('ui.dashboard.status_queued'),
      'status_processing' => __('ui.dashboard.status_processing'),
      'status_done' => __('ui.dashboard.status_done'),
      'status_failed' => __('ui.dashboard.status_failed'),
      'default_speaker' => __('ui.dashboard.default_speaker'),
      'standard' => __('ui.dashboard.standard'),
      'priority_high' => __('ui.dashboard.priority_high'),
      'priority_standard' => __('ui.dashboard.priority_standard'),
      'preview' => __('ui.dashboard.preview'),
      'yes' => __('ui.dashboard.yes'),
      'no' => __('ui.dashboard.no'),
      'just_now' => __('ui.dashboard.just_now'),
      'generation_failed' => __('ui.dashboard.generation_failed'),
      'processing_ellipsis' => __('ui.dashboard.processing_ellipsis'),
      'delete' => __('ui.dashboard.delete'),
      'no_jobs_yet' => __('ui.dashboard.no_jobs_yet'),
      'showing_zero' => __('ui.dashboard.showing_zero'),
      'showing_range' => __('ui.dashboard.showing_range'),
      'voices' => __('ui.dashboard.voices'),
      'prev' => __('ui.dashboard.prev'),
      'next' => __('ui.dashboard.next'),
      'reuse_settings' => __('ui.dashboard.reuse_settings'),
      'language_ar' => __('ui.dashboard.arabic'),
      'language_en' => __('ui.dashboard.english'),
      'confirm_delete' => __('ui.dashboard.confirm_delete'),
      'unable_delete_voice' => __('ui.dashboard.unable_delete_voice'),
      'voice_generation_failed' => __('ui.dashboard.voice_generation_failed'),
      'server_overload' => __('ui.dashboard.server_overload'),
      'generate_voice_now' => __('ui.dashboard.generate_voice_now'),
      'generating' => __('ui.dashboard.generating'),
      'voice_generated_success' => __('ui.dashboard.voice_generated_success'),
      'copied' => __('ui.extension.copied'),
      'copy_failed' => __('ui.extension.copy_failed'),
      'instant_preview' => __('ui.dashboard.instant_preview'),
      'generated_audio_ready' => __('ui.dashboard.generated_audio_ready'),
      'speaker_prefix' => __('ui.dashboard.speaker_prefix'),
      'performance_style_required' => __('ui.backend.performance_style_required'),
      'emotion_required' => __('ui.backend.emotion_required'),
      'chars_remaining_of_total' => __('ui.dashboard.chars_remaining_of_total'),
      'requests_remaining_of_total' => __('ui.dashboard.requests_remaining_of_total'),
      'used_requests_of_unlimited' => __('ui.dashboard.used_requests_of_unlimited'),
      'ready_in_seconds' => __('ui.dashboard.ready_in_seconds'),
      'preparing_next' => __('ui.dashboard.preparing_next'),
      'demo_paid_tag' => app()->getLocale() === 'ar' ? 'ديمو مجاني' : 'Free Demo',
      'demo_history_locked' => app()->getLocale() === 'ar' ? 'عنصر من الديمو' : 'Demo entry',
    ];
  @endphp
  <script>
    const voiceCatalog = @json($voiceCatalog);
    const clonedVoices = @json(($voiceCloningVisible ?? false) ? ($clonedVoices ?? []) : []);
    const clonedVoiceGroupLabel = @json(($voiceCloningVisible ?? false) ? __('ui.voice_cloning.cloned_voice_group') : '');
    const clonedVoiceBadge = @json(($voiceCloningVisible ?? false) ? __('ui.voice_cloning.cloned_voice_label') : '');
    const oldVoice = @json($prefillValue('voice_key'));
    const defaultVoiceKeyByLanguage = {
      ar: String(@json((string) ($voiceNameMap[$defaultVoiceAr]['key'] ?? '')) || '').trim(),
      en: String(@json((string) ($voiceNameMap[$defaultVoiceEn]['key'] ?? '')) || '').trim(),
    };
    const initialStudioValueByField = {
      performanceStyle: String(@json($prefillValue('performance_style')) || '').trim(),
      emotion: String(@json($prefillValue('emotion')) || '').trim(),
      dialect: String(@json($prefillValue('dialect')) || '').trim(),
    };
    const studioDefaultsByLanguage = @json($studioDefaultsByLanguage ?? []);
    const studioDialectsByLanguageRaw = @json($studioDialectsByLanguage ?? []);
    let initialStudioValuesApplied = false;
    const queueUrl = '{{ route('dashboard.queue') }}';
    const deleteJobBaseUrl = '{{ route('dashboard.jobs.destroy', ['job' => '__JOB_ID__']) }}'.replace('/__JOB_ID__', '');
    const deleteDemoJobBaseUrl = '{{ route('dashboard.demo-jobs.destroy', ['generation' => '__GEN_ID__']) }}'.replace('/__GEN_ID__', '');
    const csrfToken = '{{ csrf_token() }}';

    const voiceForm = document.getElementById('voiceForm');
    const formMessage = document.getElementById('formMessage');
    const generateBtn = document.getElementById('generateBtn');
    const targetLanguage = document.getElementById('target_language');
    const voiceSelect = document.getElementById('voice_key');
    const contentInput = document.getElementById('contentTextarea');
    const charCounter = document.getElementById('charCounter');
    const charCountEl = document.getElementById('charCount');
    const maxCharsPerRequest = {{ $maxCharsPerRequest }};
    const translateSelect = document.getElementById('translateSelect');
    const translateToggle = document.getElementById('translateToggle');
    const translateHint = document.getElementById('translateHint');
    const jobsTableBody = document.getElementById('jobsTableBody');
    const usageCharsText = document.getElementById('usageCharsText');
    const usageRequestsText = document.getElementById('usageRequestsText');
    const usageCharsMeter = document.getElementById('usageCharsMeter');
    const usageRequestsMeter = document.getElementById('usageRequestsMeter');
    const kpiRequests = document.getElementById('kpiRequests');
    const kpiChars = document.getElementById('kpiChars');
    const kpiTotal = document.getElementById('kpiTotal');
    const statProcessing = document.getElementById('statProcessing');
    const statDone = document.getElementById('statDone');
    const statFailed = document.getElementById('statFailed');
    const jobsCount = document.getElementById('jobsCount');
    const jobsPagination = document.getElementById('jobsPagination');
    const copyExtensionKeyBtn = document.getElementById('copyExtensionKeyBtn');
    const extensionKeyMessage = document.getElementById('extensionKeyMessage');
    const dashboardNavLinks = Array.from(document.querySelectorAll('[data-dashboard-nav]'));
    const dashboardSections = Array.from(document.querySelectorAll('[data-dashboard-section]'));
    const validDashboardSectionIds = dashboardSections.map(function(section) { return section.id; });
    const generatedAudioWrap = document.getElementById('generatedAudioWrap');
    const generatedOutputPlaceholder = document.getElementById('generatedOutputPlaceholder');
    const generatedPreviewAudio = document.getElementById('generatedPreviewAudio');
    const generatedAudioMeta = document.getElementById('generatedAudioMeta');
    const demoHistoryJobs = @json($demoHistoryJobs ?? []);
    const i18n = @json($dashboardI18n);
    const pageLocale = document.documentElement.lang || 'en';

    let preferredTranslateLanguage = targetLanguage?.value === 'en' ? 'en' : 'ar';
    const preferredVoiceKeyByLanguage = { ar: '', en: '' };
    const dialectSelect = document.getElementById('dialect');
    const performanceStyleSelect = document.getElementById('performance_style');
    const emotionSelect = document.getElementById('emotion');
    let feedbackScrollFrame = null;
    let allJobs = [];

    // Cooldown state for rate limiting with good UX
    let cooldownEndTime = 0;
    let cooldownIntervalId = null;
    const COOLDOWN_SECONDS = 5; // Seconds to wait between generations

    function renderGenerateButtonContent(label, loading = false) {
      if (!generateBtn) return;
      const icon = loading
        ? '<svg viewBox="0 0 24 24" width="18" height="18" style="animation:spin .8s linear infinite;fill:currentColor"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8z" opacity=".3"/><path d="M20 12h2A10 10 0 0 0 12 2v2a8 8 0 0 1 8 8z"/></svg>'
        : '<svg viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
      generateBtn.innerHTML = `<span>${icon}${escapeHtml(label)}</span>`;
    }

    // Cooldown UI functions for smooth rate limiting
    function getRemainingCooldown() {
      return Math.max(0, Math.ceil((cooldownEndTime - Date.now()) / 1000));
    }

    function startCooldownCountdown(seconds) {
      if (cooldownIntervalId) clearInterval(cooldownIntervalId);
      cooldownEndTime = Date.now() + (seconds * 1000);
      generateBtn.disabled = true;
      updateCooldownDisplay();
      cooldownIntervalId = setInterval(() => {
        const remaining = getRemainingCooldown();
        if (remaining <= 0) {
          clearInterval(cooldownIntervalId);
          cooldownIntervalId = null;
          cooldownEndTime = 0;
          generateBtn.disabled = false;
          renderGenerateButtonContent(i18n.generate_voice_now);
        } else {
          updateCooldownDisplay();
        }
      }, 1000);
    }

    function updateCooldownDisplay() {
      const remaining = getRemainingCooldown();
      const text = (i18n.ready_in_seconds || 'Ready in :seconds...').replace(':seconds', remaining);
      const icon = '<svg viewBox="0 0 24 24" width="18" height="18" style="opacity:0.5"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 15v-4H7l5-7v4h4l-5 7z" fill="currentColor"/></svg>';
      if (generateBtn) generateBtn.innerHTML = `<span>${icon}${escapeHtml(text)}</span>`;
    }

    const autoDialectLabel = '{{ __("ui.dashboard.dialect_auto") }}';
    const dialectsByLanguage = {
      ar: [{ value: '', label: autoDialectLabel }],
      en: [{ value: '', label: autoDialectLabel }],
    };
    ['ar', 'en'].forEach((langCode) => {
      const rows = Array.isArray(studioDialectsByLanguageRaw?.[langCode]) ? studioDialectsByLanguageRaw[langCode] : [];
      rows.forEach((row) => {
        const value = String(row?.value || '').trim();
        if (!value) return;
        const localizedLabel = pageLocale === 'ar'
          ? String(row?.label_ar || row?.label || value).trim()
          : String(row?.label || row?.label_ar || value).trim();
        dialectsByLanguage[langCode].push({ value, label: localizedLabel || value });
      });
    });

    function renderDialectOptions() {
      if (!dialectSelect || !targetLanguage) return;
      const lang = normalizeLanguage(targetLanguage.value);
      const dialects = dialectsByLanguage[lang] || dialectsByLanguage['ar'];
      dialectSelect.innerHTML = '';
      for (const d of dialects) {
        const opt = document.createElement('option');
        opt.value = d.value;
        opt.textContent = d.label;
        dialectSelect.appendChild(opt);
      }
    }

    function setSelectValueIfAvailable(selectEl, value) {
      const normalized = String(value || '').trim();
      if (!selectEl || !normalized) return false;
      const hasOption = Array.from(selectEl.options || []).some((opt) => String(opt.value || '') === normalized);
      if (!hasOption) return false;
      selectEl.value = normalized;
      return true;
    }

    function applyStudioDefaults() {
      if (!initialStudioValuesApplied) {
        setSelectValueIfAvailable(performanceStyleSelect, initialStudioValueByField.performanceStyle);
        setSelectValueIfAvailable(emotionSelect, initialStudioValueByField.emotion);
        setSelectValueIfAvailable(dialectSelect, initialStudioValueByField.dialect);
        initialStudioValuesApplied = true;
      }

      const lang = normalizeLanguage(targetLanguage?.value || 'ar');
      const defaults = studioDefaultsByLanguage?.[lang] || {};

      if (performanceStyleSelect && !String(performanceStyleSelect.value || '').trim()) {
        setSelectValueIfAvailable(performanceStyleSelect, defaults.performance_style || 'neutral');
      }
      if (emotionSelect && !String(emotionSelect.value || '').trim()) {
        setSelectValueIfAvailable(emotionSelect, defaults.emotion || 'neutral');
      }
      if (dialectSelect && !String(dialectSelect.value || '').trim()) {
        setSelectValueIfAvailable(dialectSelect, defaults.dialect || '');
      }
    }
    let jobsCurrentPage = 1;
    const jobsPerPage = 10;

    function formatNumber(value) {
      return new Intl.NumberFormat(pageLocale === 'ar' ? 'ar' : 'en-US').format(Number(value || 0));
    }

    function normalizeLanguage(value) {
      return value === 'en' ? 'en' : 'ar';
    }

    function oppositeLanguage(value) {
      return normalizeLanguage(value) === 'en' ? 'ar' : 'en';
    }

    function detectSourceLanguage(value) {
      return /[\u0600-\u06FF]/u.test(String(value || '')) ? 'ar' : 'en';
    }

    function languageLabel(code) {
      const normalized = String(code || '').toLowerCase();
      if (normalized === 'ar') return i18n.language_ar;
      if (normalized === 'en') return i18n.language_en;
      return code || '-';
    }

    function statusLabel(status) {
      const normalized = String(status || '').toLowerCase();
      if (normalized === 'queued') return i18n.status_queued;
      if (normalized === 'processing') return i18n.status_processing;
      if (normalized === 'done') return i18n.status_done;
      if (normalized === 'failed') return i18n.status_failed;
      return String(status || '').toUpperCase();
    }

    function isTranslateEnabled() {
      return String(translateSelect?.value || '1') === '1';
    }

    function rememberCurrentVoiceSelection(language = normalizeLanguage(targetLanguage?.value)) {
      const selectedVoiceKey = String(voiceSelect?.value || '').trim();
      if (!selectedVoiceKey) return;
      preferredVoiceKeyByLanguage[normalizeLanguage(language)] = selectedVoiceKey;
    }

    function cleanSpeakerLabel(value) {
      return String(value || '')
        .replace(/^\++\s*/, '')
        .replace(/\b[a-z]{2}-[A-Z]{2}\b/g, '')
        .replace(/\s{2,}/g, ' ')
        .trim();
    }

    function voiceDisplayLabel(voice) {
      const label = pageLocale === 'ar' && voice.label_ar ? voice.label_ar : voice.label;
      return cleanSpeakerLabel(label);
    }

    function formatTierLabel(value) {
      const text = String(value || '').trim();
      if (!text) return '';
      return `\u2066${text}\u2069`;
    }

    function sortVoices(list) {
      const tierRank = { standard: 1, hd: 2 };
      return [...list].sort((a, b) => {
        const aRank = tierRank[String(a?.tier || 'standard')] ?? 99;
        const bRank = tierRank[String(b?.tier || 'standard')] ?? 99;
        if (aRank !== bRank) return aRank - bRank;

        const byLabel = String(a?.label || '').localeCompare(String(b?.label || ''), undefined, { sensitivity: 'base' });
        if (byLabel !== 0) return byLabel;

        return String(a?.key || '').localeCompare(String(b?.key || ''), undefined, { sensitivity: 'base' });
      });
    }

    function escapeHtml(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function escapeCss(value) {
      if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
        return CSS.escape(String(value ?? ''));
      }
      return String(value ?? '').replace(/[^a-zA-Z0-9_-]/g, '\\$&');
    }

    function showFormMessage(type, message) {
      if (!formMessage) return;
      if (!message) { formMessage.innerHTML = ''; return; }
      const cls = type === 'error' ? 'error' : 'note';
      formMessage.innerHTML = `<div class="${cls}">${escapeHtml(message)}</div>`;
      scrollFeedbackIntoView(formMessage);
    }

    function scrollFeedbackIntoView(element) {
      if (!element) return;
      if (feedbackScrollFrame) {
        cancelAnimationFrame(feedbackScrollFrame);
      }
      feedbackScrollFrame = requestAnimationFrame(() => {
        feedbackScrollFrame = null;
        const rect = element.getBoundingClientRect();
        const topOffset = 108;
        const bottomOffset = 24;
        const isVisible = rect.top >= topOffset && rect.bottom <= (window.innerHeight - bottomOffset);
        if (isVisible) return;
        const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        element.scrollIntoView({
          behavior: reduceMotion ? 'auto' : 'smooth',
          block: 'nearest',
          inline: 'nearest',
        });
      });
    }

    function showExtensionKeyMessage(type, message) {
      if (!extensionKeyMessage) return;
      if (!message) {
        extensionKeyMessage.innerHTML = '';
        return;
      }
      const cls = type === 'error' ? 'error' : 'note';
      extensionKeyMessage.innerHTML = `<div class="${cls}">${escapeHtml(message)}</div>`;
    }

    function hideGeneratedPreview() {
      if (generatedPreviewAudio) {
        generatedPreviewAudio.pause();
        generatedPreviewAudio.removeAttribute('src');
        delete generatedPreviewAudio.dataset.downloadLanguage;
        delete generatedPreviewAudio.dataset.downloadText;
        delete generatedPreviewAudio.dataset.downloadSpeaker;
        delete generatedPreviewAudio.dataset.downloadQuality;
        generatedPreviewAudio.load();
      }
      if (generatedAudioMeta) generatedAudioMeta.textContent = '';
      if (generatedAudioWrap) generatedAudioWrap.style.display = 'none';
      if (generatedOutputPlaceholder) generatedOutputPlaceholder.style.display = '';
    }

    function showGeneratedPreview(job) {
      if (!generatedPreviewAudio || !generatedAudioWrap || !generatedOutputPlaceholder) return;
      const audioUrl = String(job?.audio_url || '').trim();
      if (!audioUrl) {
        hideGeneratedPreview();
        return;
      }

      const voiceLabel = cleanSpeakerLabel(String(job?.voice_label || i18n.default_speaker));
      const voiceTier = String(job?.voice_tier || i18n.standard).trim();

      generatedOutputPlaceholder.style.display = 'none';
      generatedAudioWrap.style.display = 'block';
      generatedPreviewAudio.dataset.downloadLanguage = String(job?.target_language || '');
      generatedPreviewAudio.dataset.downloadText = String(job?.text_excerpt || job?.title || '').slice(0, 42);
      generatedPreviewAudio.dataset.downloadSpeaker = voiceLabel;
      generatedPreviewAudio.dataset.downloadQuality = voiceTier;
      generatedPreviewAudio.src = audioUrl;
      generatedPreviewAudio.load();
      generatedPreviewAudio.addEventListener('canplaythrough', function autoPlayHandler() {
        generatedPreviewAudio.removeEventListener('canplaythrough', autoPlayHandler);
        generatedPreviewAudio.play().catch(() => {});
      });

      if (generatedAudioMeta) {
        const meta = [`${i18n.speaker_prefix} ${voiceLabel}`];
        if (voiceTier) meta.push(formatTierLabel(voiceTier));
        meta.push(i18n.generated_audio_ready);
        generatedAudioMeta.textContent = meta.join(' - ');
      }
    }

    function setActiveDashboardSection(sectionId) {
      dashboardNavLinks.forEach(function(link) {
        const targetId = String(link.getAttribute('href') || '').replace(/^#/, '');
        link.classList.toggle('active', targetId === sectionId);
      });
      dashboardSections.forEach(function(section) {
        section.classList.toggle('active', section.id === sectionId);
      });
      const activeLink = dashboardNavLinks.find(function(link) { return link.classList.contains('active'); });
      const navList = activeLink?.parentElement;
      if (navList && navList.scrollWidth > navList.clientWidth + 1) {
        const listBox = navList.getBoundingClientRect();
        const linkBox = activeLink.getBoundingClientRect();
        navList.scrollBy({ left: (linkBox.left + linkBox.width / 2) - (listBox.left + listBox.width / 2), behavior: 'smooth' });
      }
    }

    function syncSectionFromHash(scroll = false) {
      const hashSectionId = String(window.location.hash || '').replace(/^#/, '');
      if (!validDashboardSectionIds.includes(hashSectionId)) {
        return false;
      }

      setActiveDashboardSection(hashSectionId);

      if (window.localStorage) {
        window.localStorage.setItem('dashboard_active_section', hashSectionId);
      }

      if (scroll) {
        const section = document.getElementById(hashSectionId);
        section?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }

      return true;
    }

    function renderVoiceOptions() {
      const lang = normalizeLanguage(targetLanguage?.value || 'ar');
      const raw = Array.isArray(voiceCatalog?.[lang]) ? voiceCatalog[lang] : [];
      const voices = sortVoices(raw);
      const remembered = String(preferredVoiceKeyByLanguage[lang] || '').trim();
      const defaultVoiceKey = String(defaultVoiceKeyByLanguage?.[lang] || '').trim();
      const current = String(remembered || defaultVoiceKey || '').trim();
      if (!voiceSelect) return;
      voiceSelect.innerHTML = '';

      // Inject cloned voices at the top as an optgroup (if any match this language)
      const langPrefix = lang === 'ar' ? 'ar' : 'en';
      const matchingCloned = (clonedVoices || []).filter(v => v.language_code && v.language_code.startsWith(langPrefix));
      if (matchingCloned.length > 0) {
        const group = document.createElement('optgroup');
        group.label = clonedVoiceGroupLabel;
        for (const cv of matchingCloned) {
          const opt = document.createElement('option');
          opt.value = cv.key;
          opt.textContent = `${cv.name} (${clonedVoiceBadge})`;
          group.appendChild(opt);
        }
        voiceSelect.appendChild(group);
      }

      if (!voices.length && !matchingCloned.length) {
        voiceSelect.innerHTML = '<option value="">' + i18n.no_speakers + '</option>';
        preferredVoiceKeyByLanguage[lang] = '';
        return;
      }

      // Built-in voices
      if (voices.length) {
        const builtInGroup = document.createElement('optgroup');
        builtInGroup.label = lang === 'ar' ? 'الأصوات المتاحة' : 'Available Voices';
        for (const voice of voices) {
          const option = document.createElement('option');
          option.value = voice.key;
          option.textContent = voiceDisplayLabel(voice);
          builtInGroup.appendChild(option);
        }
        voiceSelect.appendChild(builtInGroup);
      }

      // Select appropriate default
      const allKeys = [...matchingCloned.map(c => c.key), ...voices.map(v => v.key)];
      if (allKeys.includes(current)) voiceSelect.value = current;
      else voiceSelect.value = allKeys[0] || '';
      preferredVoiceKeyByLanguage[lang] = String(voiceSelect.value || '').trim();
    }

    function syncLanguageToCurrentTextWhenTranslationOff(fallbackLanguage = null) {
      if (!targetLanguage || !contentInput || isTranslateEnabled()) return false;
      const text = String(contentInput.value || '').trim();
      if (!text) {
        const fallback = fallbackLanguage ? normalizeLanguage(fallbackLanguage) : null;
        if (!fallback) return false;
        const currentLanguage = normalizeLanguage(targetLanguage.value);
        if (currentLanguage === fallback) return false;
        rememberCurrentVoiceSelection(currentLanguage);
        targetLanguage.value = fallback;
        renderVoiceOptions();
        renderDialectOptions();
        applyStudioDefaults();
        return true;
      }
      const sourceLanguage = detectSourceLanguage(text);
      const currentLanguage = normalizeLanguage(targetLanguage.value);
      if (sourceLanguage === currentLanguage) return false;
      rememberCurrentVoiceSelection(currentLanguage);
      targetLanguage.value = sourceLanguage;
      renderVoiceOptions();
      renderDialectOptions();
      applyStudioDefaults();
      return true;
    }

    function restorePreferredTranslationLanguage() {
      if (!targetLanguage || !isTranslateEnabled()) return false;
      const currentLanguage = normalizeLanguage(targetLanguage.value);
      const desiredLanguage = normalizeLanguage(preferredTranslateLanguage);
      if (currentLanguage === desiredLanguage) return false;
      rememberCurrentVoiceSelection(currentLanguage);
      targetLanguage.value = desiredLanguage;
      renderVoiceOptions();
      renderDialectOptions();
      applyStudioDefaults();
      return true;
    }

    function updateTranslateHint() {
      if (!translateSelect || !translateHint) return;
      translateHint.textContent = translateSelect.value === '1'
        ? i18n.translate_enabled_hint
        : i18n.translate_disabled_hint;
    }

    function formatQuotaText(template, replacements) {
      let text = String(template || '');
      Object.entries(replacements || {}).forEach(function(entry) {
        const [key, value] = entry;
        text = text.replace(':' + key, String(value));
      });

      return text;
    }

    function remainingQuota(total, used) {
      return Math.max(0, Number(total || 0) - Number(used || 0));
    }

    function meterWidthFromUsedPercent(percent) {
      return `${Math.max(0, 100 - Number(percent || 0))}%`;
    }

    function updateUsagePanel(usage) {
      if (!usage || typeof usage !== 'object') return;
      const requestsUnlimited = Boolean(usage.requests_unlimited);
      if (usageCharsText) usageCharsText.textContent = formatQuotaText(i18n.chars_remaining_of_total, {
        remaining: formatNumber(remainingQuota(usage.plan_chars, usage.total_chars)),
        total: formatNumber(usage.plan_chars),
      });
      if (usageRequestsText) usageRequestsText.textContent = requestsUnlimited
        ? formatQuotaText(i18n.used_requests_of_unlimited, { used: formatNumber(usage.total_requests) })
        : formatQuotaText(i18n.requests_remaining_of_total, {
          remaining: formatNumber(remainingQuota(usage.plan_requests, usage.total_requests)),
          total: formatNumber(usage.plan_requests),
        });
      if (usageCharsMeter) usageCharsMeter.style.width = meterWidthFromUsedPercent(usage.chars_percent);
      if (usageRequestsMeter) usageRequestsMeter.style.width = meterWidthFromUsedPercent(usage.requests_percent);
      if (kpiRequests) kpiRequests.textContent = formatNumber(usage.total_requests);
      if (kpiChars) kpiChars.textContent = formatNumber(usage.total_chars);
    }

    function updateStats(stats) {
      if (!stats || typeof stats !== 'object') return;
      if (statProcessing) statProcessing.textContent = formatNumber(stats.processing);
      if (statDone) statDone.textContent = formatNumber(stats.done);
      if (statFailed) statFailed.textContent = formatNumber(stats.failed);
      if (kpiTotal) kpiTotal.textContent = formatNumber(stats.total);
    }

    function isDemoHistoryJob(job) {
      return Boolean(job?.is_demo_history);
    }

    function resolveJobKey(job) {
      const explicit = String(job?.row_key || '').trim();
      if (explicit) return explicit;
      const rawId = String(job?.id ?? '').trim();
      if (!rawId) return '';
      return (isDemoHistoryJob(job) ? 'demo:' : 'audio:') + rawId;
    }

    function normalizeJob(job) {
      if (!job || typeof job !== 'object') return null;
      const normalized = { ...job };
      normalized.is_demo_history = isDemoHistoryJob(normalized);
      normalized.row_key = resolveJobKey(normalized);
      if (!String(normalized.status || '').trim()) {
        normalized.status = 'done';
      }
      return normalized;
    }

    function sortAllJobsByNewest() {
      allJobs.sort(function(a, b) {
        const aTs = Number(a?.created_at_ts || 0);
        const bTs = Number(b?.created_at_ts || 0);
        if (aTs !== bTs) return bTs - aTs;

        const aId = Number(String(a?.id ?? '').replace(/[^\d]/g, '') || 0);
        const bId = Number(String(b?.id ?? '').replace(/[^\d]/g, '') || 0);
        return bId - aId;
      });
    }

    function renderJobRow(job) {
      const isDemoHistory = isDemoHistoryJob(job);
      const jobKey = resolveJobKey(job);
      const status = String(job?.status || 'processing').toLowerCase();
      const statusClass = ['queued', 'processing', 'done', 'failed'].includes(status) ? status : 'processing';
      const statusText = statusLabel(statusClass);
      const title = escapeHtml(job?.title || '-');
      const sourceLanguageCode = String(job?.source_language || '-');
      const targetLanguageCode = String(job?.target_language || '-');
      const sourceLanguage = escapeHtml(languageLabel(sourceLanguageCode));
      const targetLanguageValue = escapeHtml(languageLabel(targetLanguageCode));
      const voiceLabelValue = cleanSpeakerLabel(job?.voice_label || i18n.default_speaker);
      const voiceLabel = escapeHtml(voiceLabelValue);
      const voiceTierValue = String(job?.voice_tier || i18n.standard).trim();
      const voiceTier = escapeHtml(voiceTierValue);
      const textExcerpt = escapeHtml(job?.text_excerpt || job?.title || i18n.preview);
      const created = escapeHtml(job?.created_human || i18n.just_now);
      const errorMessage = escapeHtml(job?.error_message || i18n.generation_failed);
      const audioUrl = job?.audio_url ? escapeHtml(job.audio_url) : '';
      const displayNumber = escapeHtml(String(job?.display_number ?? job?.id ?? 0));
      const titleWithBadge = isDemoHistory
        ? (title + '<span class="history-source-badge">' + escapeHtml(i18n.demo_paid_tag) + '</span>')
        : title;

      let audioCell = '<small style="color: var(--muted);">' + i18n.processing_ellipsis + '</small>';
      if (audioUrl) audioCell = '<audio controls preload="none" src="' + audioUrl + '" data-download-language="' + escapeHtml(targetLanguageCode) + '" data-download-text="' + textExcerpt + '" data-download-speaker="' + voiceLabel + '" data-download-quality="' + voiceTier + '"></audio>';
      else if (statusClass === 'failed') audioCell = '<small style="color: var(--danger);">' + errorMessage + '</small>';

      const actions = [];
      if (job?.can_reuse_settings && job?.reuse_url) {
        actions.push('<a class="job-reuse-btn" href="' + escapeHtml(job.reuse_url) + '">' + i18n.reuse_settings + '</a>');
      }
      const deleteSource = isDemoHistory ? 'demo' : 'audio';
      actions.push('<button type="button" class="pagination-btn job-delete-btn" data-action="delete-job" data-job-source="' + deleteSource + '" data-job-id="' + Number(job?.id || 0) + '">' + i18n.delete + '</button>');

      return '<tr data-job-key="' + escapeHtml(jobKey) + '" data-job-id="' + escapeHtml(String(job?.id ?? '')) + '" data-job-data="' + escapeHtml(JSON.stringify(job)) + '">' +
        '<td>#' + displayNumber + '</td>' +
        '<td>' + titleWithBadge + '</td>' +
        '<td><span class="status ' + statusClass + '">' + statusText + '</span></td>' +
        '<td>' + sourceLanguage + ' -> ' + targetLanguageValue + '</td>' +
        '<td>' + voiceLabel + '</td>' +
        '<td>' + audioCell + '</td>' +
        '<td>' + created + '</td>' +
        '<td><div class="job-actions">' + actions.join('') + '</div></td>' +
      '</tr>';
    }

    function loadJobsFromTable() {
      const rows = jobsTableBody.querySelectorAll('tr[data-job-data]');
      const mergedJobs = [];
      rows.forEach(function(row) {
        try {
          const data = JSON.parse(row.getAttribute('data-job-data'));
          const normalized = normalizeJob(data);
          if (normalized && normalized.id !== undefined && normalized.id !== null) {
            mergedJobs.push(normalized);
          }
        } catch (e) {}
      });

      if (Array.isArray(demoHistoryJobs)) {
        demoHistoryJobs.forEach(function(job) {
          const normalized = normalizeJob(job);
          if (!normalized || normalized.id === undefined || normalized.id === null) return;
          normalized.is_demo_history = true;
          normalized.row_key = resolveJobKey(normalized);
          mergedJobs.push(normalized);
        });
      }

      const uniqueJobs = new Map();
      mergedJobs.forEach(function(job) {
        const key = resolveJobKey(job);
        if (!key || uniqueJobs.has(key)) return;
        uniqueJobs.set(key, job);
      });
      allJobs = Array.from(uniqueJobs.values());
      sortAllJobsByNewest();
    }

    function renderJobsPage() {
      if (!jobsTableBody) return;
      const totalLoaded = allJobs.length;
      const totalPages = Math.ceil(totalLoaded / jobsPerPage) || 1;
      const start = (jobsCurrentPage - 1) * jobsPerPage;
      const end = start + jobsPerPage;
      const pageJobs = allJobs.slice(start, end);

      jobsTableBody.innerHTML = '';
      if (pageJobs.length === 0) {
        jobsTableBody.innerHTML = '<tr id="jobsEmptyRow"><td colspan="8" style="color: var(--muted);">' + i18n.no_jobs_yet + '</td></tr>';
      } else {
        pageJobs.forEach(function(job) {
          jobsTableBody.insertAdjacentHTML('beforeend', renderJobRow(job));
        });
      }

      if (jobsCount) {
        if (totalLoaded === 0) {
          jobsCount.textContent = i18n.showing_zero;
        } else {
          const showingFrom = start + 1;
          const showingTo = Math.min(end, totalLoaded);
          jobsCount.textContent = i18n.showing_range
            .replace(':from', String(showingFrom))
            .replace(':to', String(showingTo))
            .replace(':total', formatNumber(totalLoaded))
            .replace(':label', i18n.voices);
        }
      }
      renderPagination(totalPages);
      if (window.initCustomAudioPlayers) window.initCustomAudioPlayers();
    }

    function renderPagination(totalPages) {
      if (!jobsPagination) return;
      if (totalPages <= 1) {
        jobsPagination.innerHTML = '';
        return;
      }

      let html = '';
      html += '<button type="button" class="pagination-btn" data-page="prev"' + (jobsCurrentPage === 1 ? ' disabled' : '') + '>' + i18n.prev + '</button>';

      for (let i = 1; i <= totalPages; i++) {
        if (totalPages > 7) {
          if (i === 1 || i === totalPages || (i >= jobsCurrentPage - 1 && i <= jobsCurrentPage + 1)) {
            html += '<button type="button" class="pagination-btn' + (i === jobsCurrentPage ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
          } else if (i === jobsCurrentPage - 2 || i === jobsCurrentPage + 2) {
            html += '<span class="pagination-info">...</span>';
          }
        } else {
          html += '<button type="button" class="pagination-btn' + (i === jobsCurrentPage ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
        }
      }

      html += '<button type="button" class="pagination-btn" data-page="next"' + (jobsCurrentPage === totalPages ? ' disabled' : '') + '>' + i18n.next + '</button>';
      jobsPagination.innerHTML = html;
    }

    function goToPage(page) {
      const totalPages = Math.ceil(allJobs.length / jobsPerPage) || 1;
      if (page === 'prev') page = jobsCurrentPage - 1;
      if (page === 'next') page = jobsCurrentPage + 1;
      page = Number(page);
      if (isNaN(page) || page < 1 || page > totalPages || page === jobsCurrentPage) return;
      jobsCurrentPage = page;
      renderJobsPage();
    }

    function upsertJobRow(job) {
      const normalized = normalizeJob(job);
      if (!normalized || !normalized.id) return;
      const emptyRow = document.getElementById('jobsEmptyRow');
      if (emptyRow) emptyRow.remove();

      const incomingKey = resolveJobKey(normalized);
      const existingIndex = allJobs.findIndex(function(j) { return resolveJobKey(j) === incomingKey; });
      let isNew = false;
      if (existingIndex !== -1) {
        allJobs[existingIndex] = normalized;
      } else {
        allJobs.unshift(normalized);
        isNew = true;
      }
      sortAllJobsByNewest();

      jobsCurrentPage = 1;
      renderJobsPage();

      if (isNew) {
        setTimeout(function() {
          const firstRow = jobsTableBody.querySelector('tr[data-job-key="' + escapeCss(incomingKey) + '"]');
          if (firstRow) {
            firstRow.classList.add('new-job-row');
            firstRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
        }, 10);
      }
    }

    async function deleteJob(jobId, source, triggerBtn) {
      const parsedJobId = Number(jobId || 0);
      if (!parsedJobId) return;
      const parsedSource = source === 'demo' ? 'demo' : 'audio';

      if (!window.confirm(i18n.confirm_delete)) {
        return;
      }

      if (triggerBtn instanceof HTMLButtonElement) {
        triggerBtn.disabled = true;
      }

      let data = null;
      try {
        const baseUrl = parsedSource === 'demo' ? deleteDemoJobBaseUrl : deleteJobBaseUrl;
        const response = await fetch(`${baseUrl}/${parsedJobId}`, {
          method: 'DELETE',
          headers: {
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        });

        data = await response.json().catch(() => ({}));
        if (!response.ok || data?.ok === false) {
          throw new Error(extractErrorMessage(data, i18n.unable_delete_voice));
        }

        const deletedId = Number(data?.deleted_id || parsedJobId);
        allJobs = allJobs.filter((job) => !(isDemoHistoryJob(job) === (parsedSource === 'demo') && Number(job?.id || 0) === deletedId));
        if (data?.stats) updateStats(data.stats);
        const totalPages = Math.ceil(allJobs.length / jobsPerPage) || 1;
        jobsCurrentPage = Math.min(jobsCurrentPage, totalPages);
        renderJobsPage();
        showFormMessage(null, '');
      } catch (error) {
        showFormMessage('error', extractErrorMessage(data, error?.message || i18n.unable_delete_voice));
      } finally {
        if (triggerBtn instanceof HTMLButtonElement) {
          triggerBtn.disabled = false;
        }
      }
    }

    jobsPagination?.addEventListener('click', function(e) {
      const btn = e.target.closest('.pagination-btn');
      if (!btn || btn.disabled) return;
      goToPage(btn.getAttribute('data-page'));
    });
    jobsTableBody?.addEventListener('click', function(e) {
      const reuseLink = e.target.closest('.job-reuse-btn');
      if (reuseLink instanceof HTMLAnchorElement) {
        let parsedUrl = null;
        try {
          parsedUrl = new URL(reuseLink.href, window.location.origin);
        } catch (error) {
          parsedUrl = null;
        }

        if (
          parsedUrl
          && parsedUrl.pathname === window.location.pathname
          && parsedUrl.search === window.location.search
        ) {
          const targetId = String(parsedUrl.hash || '').replace(/^#/, '');
          if (validDashboardSectionIds.includes(targetId)) {
            e.preventDefault();
            setActiveDashboardSection(targetId);
            if (window.localStorage) {
              window.localStorage.setItem('dashboard_active_section', targetId);
            }
            if (window.location.hash !== '#' + targetId) {
              history.replaceState(null, '', '#' + targetId);
            }
            const section = document.getElementById(targetId);
            section?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
          return;
        }
      }

      const deleteBtn = e.target.closest('[data-action="delete-job"]');
      if (!deleteBtn || deleteBtn.disabled) return;
      deleteJob(deleteBtn.getAttribute('data-job-id'), deleteBtn.getAttribute('data-job-source'), deleteBtn);
    });

    function extractErrorMessage(data, fallback = i18n.voice_generation_failed) {
      // Helper to detect technical/internal error messages
      const isTechnicalError = (msg) => {
        if (!msg || typeof msg !== 'string') return false;
        const patterns = [
          /maximum execution time/i,
          /timeout/i,
          /memory.*exhausted/i,
          /fatal error/i,
          /internal server error/i,
          /500/,
          /502/,
          /503/,
          /504/,
          /gateway/i,
          /service unavailable/i,
        ];
        return patterns.some(p => p.test(msg));
      };
      let message = null;
      if (data?.message) message = String(data.message);
      else if (data?.error_message) message = String(data.error_message);
      else {
        const errors = data?.errors;
        if (errors && typeof errors === 'object') {
          const keys = Object.keys(errors);
          if (keys.length > 0 && Array.isArray(errors[keys[0]]) && errors[keys[0]][0]) {
            message = String(errors[keys[0]][0]);
          }
        }
      }
      if (message && isTechnicalError(message)) {
        return i18n.server_overload || fallback;
      }
      return message || fallback;
    }

    async function submitVoiceForm(event) {
      event.preventDefault();
      if (!voiceForm || !generateBtn) return;
      // Check if still in cooldown
      const remaining = getRemainingCooldown();
      if (remaining > 0) {
        return; // Silently ignore - button should be disabled anyway
      }
      if (translateToggle && translateSelect) {
        translateSelect.value = translateToggle.checked ? '1' : '0';
      }
      if (!isTranslateEnabled()) {
        syncLanguageToCurrentTextWhenTranslationOff();
      }
      showFormMessage(null, '');
      if (performanceStyleSelect && !String(performanceStyleSelect.value || '').trim()) {
        showFormMessage('error', i18n.performance_style_required);
        performanceStyleSelect.focus();
        return;
      }
      if (emotionSelect && !String(emotionSelect.value || '').trim()) {
        showFormMessage('error', i18n.emotion_required);
        emotionSelect.focus();
        return;
      }
      const defaultText = i18n.generate_voice_now;
      generateBtn.disabled = true;
      renderGenerateButtonContent(i18n.generating, true);
      let data = null;
      let wasSuccessful = false;
      try {
        const response = await fetch(queueUrl, {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          body: new FormData(voiceForm),
        });
        data = await response.json().catch(() => ({}));
        if (data?.job) upsertJobRow(data.job);
        if (data?.usage) updateUsagePanel(data.usage);
        if (data?.stats) updateStats(data.stats);
        if (!response.ok || data?.ok === false) throw new Error(extractErrorMessage(data));
        wasSuccessful = true;
        if (data?.job?.audio_url) {
          showGeneratedPreview(data.job);
          // Scroll smoothly to the generated audio section
          if (generatedAudioWrap) {
            generatedAudioWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
        }
        // Start cooldown after successful generation
        startCooldownCountdown(COOLDOWN_SECONDS);
      } catch (error) {
        const message = extractErrorMessage(data, error?.message || i18n.voice_generation_failed);
        showFormMessage('error', message);
        // Check if server returned a rate limit with retry seconds
        const secondsMatch = message.match(/(\d+)\s*(second|ثانية|ثواني)/i);
        if (secondsMatch) {
          startCooldownCountdown(parseInt(secondsMatch[1], 10));
        } else {
          // Re-enable button on other errors
          generateBtn.disabled = false;
          renderGenerateButtonContent(defaultText);
        }
      }
    }

    targetLanguage?.addEventListener('change', function() {
      if (isTranslateEnabled()) {
        preferredTranslateLanguage = normalizeLanguage(targetLanguage.value);
      }
      renderVoiceOptions();
      renderDialectOptions();
      applyStudioDefaults();
    });
    voiceSelect?.addEventListener('change', function() {
      rememberCurrentVoiceSelection();
    });
    translateToggle?.addEventListener('change', function() {
      if (translateSelect) translateSelect.value = translateToggle.checked ? '1' : '0';
      if (translateToggle.checked) {
        restorePreferredTranslationLanguage();
      } else {
        preferredTranslateLanguage = normalizeLanguage(targetLanguage?.value);
        syncLanguageToCurrentTextWhenTranslationOff(oppositeLanguage(preferredTranslateLanguage));
      }
      updateTranslateHint();
    });
    voiceForm?.addEventListener('submit', submitVoiceForm);

    if (oldVoice) {
      preferredVoiceKeyByLanguage[normalizeLanguage(targetLanguage?.value)] = String(oldVoice || '').trim();
    }
    if (translateToggle && translateSelect) {
      translateToggle.checked = translateSelect.value === '1';
    }
    if (!isTranslateEnabled()) {
      preferredTranslateLanguage = oppositeLanguage(normalizeLanguage(targetLanguage?.value));
    }
    renderGenerateButtonContent(i18n.generate_voice_now);
    renderVoiceOptions();
    renderDialectOptions();
    applyStudioDefaults();

    function clonedVoiceLanguage(languageCode) {
      return String(languageCode || '').toLowerCase().startsWith('en') ? 'en' : 'ar';
    }

    window.openClonedVoiceInGenerator = function(voice) {
      const id = String(voice?.id || '').trim();
      if (!/^\d+$/.test(id) || !voiceSelect || !targetLanguage) return false;
      const key = `cloned_${id}`;
      const name = String(voice?.name || '').trim() || `Voice ${id}`;
      const languageCode = String(voice?.language_code || 'ar-XA');
      const lang = clonedVoiceLanguage(languageCode);
      if (!clonedVoices.some((entry) => String(entry.key) === key)) {
        clonedVoices.push({ id: Number(id), name, language_code: languageCode, key });
      }
      preferredVoiceKeyByLanguage[lang] = key;
      if (normalizeLanguage(targetLanguage.value) !== lang) {
        targetLanguage.value = lang;
        renderDialectOptions();
        applyStudioDefaults();
      }
      renderVoiceOptions();
      if (!setSelectValueIfAvailable(voiceSelect, key)) return false;
      preferredVoiceKeyByLanguage[lang] = key;
      voiceSelect.classList.add('vc-speaker-picked');
      setTimeout(() => voiceSelect.classList.remove('vc-speaker-picked'), 2200);
      setActiveDashboardSection('generateVoiceSection');
      if (window.localStorage) {
        window.localStorage.setItem('dashboard_active_section', 'generateVoiceSection');
      }
      const nextUrl = `${window.location.pathname}?cloned_voice=${encodeURIComponent(id)}#generateVoiceSection`;
      if (`${window.location.pathname}${window.location.search}${window.location.hash}` !== nextUrl) {
        history.replaceState(null, '', nextUrl);
      }
      document.getElementById('generateVoiceSection')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      voiceSelect.focus({ preventScroll: true });
      return true;
    };
    updateTranslateHint();
    loadJobsFromTable();
    renderJobsPage();

    // Dialogue toggle binding
    const dialogueToggle = document.getElementById('dialogueToggle');
    const dialogueSelect = document.getElementById('dialogueSelect');
    if (dialogueToggle && dialogueSelect) {
      dialogueToggle.addEventListener('change', function() {
        dialogueSelect.value = dialogueToggle.checked ? '1' : '0';
      });
    }

    // Live character counter
    function updateCharCounter() {
      if (!contentInput || !charCountEl || !charCounter) return;
      const length = contentInput.value.length; // includes spaces
      charCountEl.textContent = length.toLocaleString();
      charCounter.classList.remove('warning', 'danger');
      if (length > maxCharsPerRequest) {
        charCounter.classList.add('danger');
      } else if (length > maxCharsPerRequest * 0.9) {
        charCounter.classList.add('warning');
      }
    }
    if (contentInput) {
      contentInput.addEventListener('input', updateCharCounter);
      // Sound tags: insert <tag> at the cursor, with spaces around it.
      document.querySelectorAll('[data-vocal-tag]').forEach((chip) => {
        chip.addEventListener('click', () => {
          const tag = '<' + chip.dataset.vocalTag + '>';
          const value = contentInput.value;
          const start = contentInput.selectionStart ?? value.length;
          const end = contentInput.selectionEnd ?? start;
          const before = value.slice(0, start);
          const after = value.slice(end);
          const insert = (before && !/\s$/.test(before) ? ' ' : '') + tag + (after && !/^\s/.test(after) ? ' ' : '');
          contentInput.value = before + insert + after;
          const caret = before.length + insert.length;
          contentInput.focus();
          contentInput.setSelectionRange(caret, caret);
          contentInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
      });
      updateCharCounter(); // initial count for old('content')
    }
    if (dashboardNavLinks.length) {
      const hashSectionId = String(window.location.hash || '').replace(/^#/, '');
      const savedSectionId = String(window.localStorage?.getItem('dashboard_active_section') || '');
      const initialSectionId = validDashboardSectionIds.includes(hashSectionId)
        ? hashSectionId
        : (validDashboardSectionIds.includes(savedSectionId) ? savedSectionId : (validDashboardSectionIds[0] || 'overviewSection'));

      if (initialSectionId) {
        setActiveDashboardSection(initialSectionId);
      }

      dashboardNavLinks.forEach(function(link) {
        link.addEventListener('click', function(event) {
          event.preventDefault();
          const targetId = String(link.getAttribute('href') || '').replace(/^#/, '');
          if (!targetId) return;
          setActiveDashboardSection(targetId);
          if (window.localStorage) {
            window.localStorage.setItem('dashboard_active_section', targetId);
          }
          if (window.location.hash !== '#' + targetId) {
            history.replaceState(null, '', '#' + targetId);
          } else {
            const section = document.getElementById(targetId);
            section?.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        });
      });
    }

    window.addEventListener('hashchange', function() {
      syncSectionFromHash(true);
    });

    (function openRequestedClonedVoice() {
      const params = new URLSearchParams(window.location.search);
      const id = String(params.get('cloned_voice') || '').trim();
      if (!/^\d+$/.test(id) || typeof window.openClonedVoiceInGenerator !== 'function') return;
      const match = clonedVoices.find((entry) => String(entry.id) === id);
      if (!match) return;
      window.openClonedVoiceInGenerator(match);
    })();

    copyExtensionKeyBtn?.addEventListener('click', async function() {
      const raw = String(copyExtensionKeyBtn.getAttribute('data-copy-value') || '').trim();
      if (!raw) {
        showExtensionKeyMessage('error', i18n.copy_failed);
        return;
      }

      try {
        await navigator.clipboard.writeText(raw);
        showExtensionKeyMessage('ok', i18n.copied);
      } catch (error) {
        showExtensionKeyMessage('error', i18n.copy_failed);
      }
    });
  </script>
  @if(($voiceCloningVisible ?? false) && ($voiceCloningEnabledGlobally ?? false))
  <script>
    (function() {
      'use strict';

      const form = document.getElementById('vcCreateForm');
      const voiceGrid = document.getElementById('vcVoiceGrid');
      const voicesTitle = document.getElementById('vcVoicesTitle');
      const emptyState = document.getElementById('vcEmptyState');
      const countText = document.querySelector('.vc-count');
      const createPanel = document.getElementById('vcCreatePanel');
      const limitNotice = document.getElementById('vcLimitNotice');
      const MIN_DURATION = {{ (int) ($voiceCloningMinDuration ?? 10) }};
      const MAX_DURATION = {{ (int) ($voiceCloningMaxDuration ?? 30) }};
      const MIN_CONSENT = {{ (int) ($voiceCloningMinConsent ?? 3) }};
      const MAX_VOICES = {{ (int) ($voiceCloningMaxVoices ?? 0) }};
      const consentScripts = @json($voiceCloningConsentScripts ?? []);
      const sampleTooShortText = @json(__('ui.voice_cloning.validation.audio_too_short', ['min' => $voiceCloningMinDuration ?? 10]));
      const sampleTooLongText = @json(__('ui.voice_cloning.validation.audio_too_long', ['max' => $voiceCloningMaxDuration ?? 30]));
      const consentMissingText = @json(__('ui.voice_cloning.validation.consent_audio_required'));
      const consentTooShortText = @json(__('ui.voice_cloning.validation.consent_too_short', ['min' => $voiceCloningMinConsent ?? 3]));
      const consentRequiredText = @json(__('ui.voice_cloning.validation.consent_required'));
      const nameRequiredText = @json(__('ui.voice_cloning.validation.name_required'));
      const unreadableFileText = @json(__('ui.voice_cloning.validation.audio_invalid_type'));
      const copyLabel = @json(__('ui.voice_cloning.consent_copy'));
      const copiedLabel = @json(__('ui.voice_cloning.consent_copied'));
      const sampleReadyText = @json(__('ui.voice_cloning.sample_ready'));
      const consentReadyText = @json(__('ui.voice_cloning.consent_ready'));
      const recordStartText = @json(__('ui.voice_cloning.record_start'));
      const recordingText = @json(__('ui.voice_cloning.recording_in_progress'));
      const tooShortRecordText = @json(__('ui.voice_cloning.recording_too_short', ['min' => $voiceCloningMinDuration ?? 10]));
      const tooLongRecordText = @json(__('ui.voice_cloning.recording_too_long', ['max' => $voiceCloningMaxDuration ?? 30]));
      let currentVoiceCount = {{ (int) ($voiceCloningCount ?? 0) }};
      const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
      const STORE_URL = '{{ route("voice-cloning.store") }}';
      const STATUS_URL_BASE = '{{ route("voice-cloning.status", ["id" => "__VOICE_ID__"]) }}'.replace('__VOICE_ID__', '');
      const DELETE_URL_BASE = '{{ route("voice-cloning.destroy", ["id" => "__VOICE_ID__"]) }}'.replace('__VOICE_ID__', '');
      const RETRY_URL_TEMPLATE = '{{ route("voice-cloning.retry", ["id" => "__VOICE_ID__"]) }}';
      const CONSENT_URL_TEMPLATE = '{{ route("voice-cloning.consent", ["id" => "__VOICE_ID__"]) }}';
      const reconsentTitleTemplate = @json(__('ui.voice_cloning.replace_consent_title', ['name' => '__NAME__']));
      const reconsentButtonText = @json(__('ui.voice_cloning.replace_consent_button'));
      const reconsentSubmitText = @json(__('ui.voice_cloning.replace_consent_submit'));
      const createSubmitText = @json(__('ui.voice_cloning.submit_button'));
      const getReadyTemplate = @json(__('ui.voice_cloning.get_ready', ['n' => '__N__']));
      const speakNowText = @json(__('ui.voice_cloning.speak_now'));
      const MIC_CONSTRAINTS = {
        audio: {
          channelCount: 1,
          echoCancellation: false,
          noiseSuppression: false,
          autoGainControl: true,
        },
      };
      let reconsentVoiceId = null;

      async function openMicrophone() {
        try {
          return await navigator.mediaDevices.getUserMedia(MIC_CONSTRAINTS);
        } catch (error) {
          if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') throw error;
          return navigator.mediaDevices.getUserMedia({ audio: true });
        }
      }

      function showPreview(audioId, blob) {
        const audio = document.getElementById(audioId);
        if (!audio) return;
        const playback = audio.closest('.vc-playback');
        if (audio.dataset.url) URL.revokeObjectURL(audio.dataset.url);
        if (!blob) {
          audio.removeAttribute('src');
          audio.dataset.url = '';
          audio.hidden = true;
          playback?.classList.remove('is-ready');
          if (playback) playback.hidden = true;
          return;
        }
        const url = URL.createObjectURL(blob);
        audio.dataset.url = url;
        audio.src = url;
        audio.hidden = false;
        if (playback) {
          playback.hidden = false;
          playback.classList.add('is-ready');
        }
      }
      const retryText = @json(__('ui.voice_cloning.retry_button'));
      const techDetailsText = @json(__('ui.voice_cloning.technical_details'));
      const GENERATOR_URL_BASE = '{{ route("dashboard") }}';
      const pageLocale = String(document.documentElement.lang || '{{ app()->getLocale() }}').toLowerCase();
      const statusLabels = {
        pending: @json(__('ui.voice_cloning.status_pending')),
        validating: @json(__('ui.voice_cloning.status_validating')),
        processing: @json(__('ui.voice_cloning.status_processing')),
        ready: @json(__('ui.voice_cloning.status_ready')),
        failed: @json(__('ui.voice_cloning.status_failed')),
      };
      const sourceTypeLabels = {
        upload: @json(__('ui.voice_cloning.upload_tab')),
        recording: @json(__('ui.voice_cloning.record_tab')),
      };
      const countTemplate = @json(__('ui.voice_cloning.voice_count', ['count' => '__COUNT__', 'max' => '__MAX__']));
      const processingHintText = @json(__('ui.voice_cloning.processing_hint'));
      const failedHintText = @json(__('ui.voice_cloning.processing_failed_generic'));
      const useInGeneratorText = @json(__('ui.voice_cloning.use_in_generator'));
      const deleteVoiceText = @json(__('ui.voice_cloning.delete_voice'));
      const deleteConfirmText = @json(__('ui.voice_cloning.delete_confirm'));
      const delayedHintText = @json(__('ui.voice_cloning.processing_delayed'));
      const languageLabels = {
        'ar-XA': @json(__('ui.voice_cloning.language_ar')),
        'en-US': @json(__('ui.voice_cloning.language_en_us')),
        'en-GB': @json(__('ui.voice_cloning.language_en_gb')),
        'en-AU': @json(__('ui.voice_cloning.language_en_au')),
      };
      const pollers = new Map();

      function scrollAlertIntoView(alert) {
        if (!alert) return;
        alert.style.scrollMarginTop = '6.5rem';
        alert.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
      }

      function showAlert(type, message) {
        if (type !== 'error') return;
        const container = document.getElementById('vcAlerts');
        if (!container || !message) return;
        const text = String(message);
        const current = container.querySelector('.vc-alert');
        if (current && current.textContent === text) {
          scrollAlertIntoView(current);
          return;
        }
        container.replaceChildren();
        const alert = document.createElement('div');
        alert.className = 'vc-alert error';
        alert.setAttribute('role', 'alert');
        alert.textContent = text;
        container.appendChild(alert);
        scrollAlertIntoView(alert);
        setTimeout(() => {
          if (alert.isConnected) alert.remove();
        }, 7000);
      }

      function escapeHtml(value) {
        return String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function escapeSelector(value) {
        const input = String(value ?? '');
        if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
          return CSS.escape(input);
        }
        return input.replace(/[^a-zA-Z0-9_-]/g, '\\$&');
      }

      function formatCount() {
        return countTemplate
          .replace('__COUNT__', String(currentVoiceCount))
          .replace('__MAX__', String(MAX_VOICES));
      }

      function updateCountUI() {
        if (countText) {
          countText.textContent = formatCount();
        }

        const canCreateMore = MAX_VOICES > 0 && currentVoiceCount < MAX_VOICES;
        if (createPanel) {
          createPanel.style.display = canCreateMore ? '' : 'none';
        }
        if (limitNotice) {
          const atLimit = MAX_VOICES > 0 && currentVoiceCount > 0 && currentVoiceCount >= MAX_VOICES;
          limitNotice.style.display = atLimit ? '' : 'none';
        }
      }

      function updateEmptyStateUI() {
        const hasCards = Boolean(voiceGrid && voiceGrid.querySelector('.vc-card'));
        if (voiceGrid) {
          voiceGrid.style.display = hasCards ? '' : 'none';
        }
        if (voicesTitle) {
          voicesTitle.style.display = hasCards ? '' : 'none';
        }
        if (emptyState) {
          emptyState.style.display = hasCards ? 'none' : '';
        }
      }

      function currentDisplayedPercent(card) {
        const node = card?.querySelector('[data-vc-progress-percent]');
        const value = Number(String(node?.textContent || '').replace(/[^\d.]/g, ''));
        return Number.isFinite(value) ? value : 0;
      }

      function statusProgress(status, elapsedSeconds = 0, serverProgress = null, previous = 0) {
        const normalized = String(status || '').toLowerCase();
        if (normalized === 'ready' || normalized === 'failed') return 100;

        const elapsed = Math.max(0, Number(elapsedSeconds) || 0);
        const range = {
          pending: [12, 36],
          validating: [40, 66],
          processing: [70, 96],
        }[normalized] || [8, 90];
        const floor = range[0];
        const cap = range[1];
        let next = Math.min(cap, floor + Math.floor(elapsed / 3));
        const server = Number(serverProgress);
        if (Number.isFinite(server)) {
          next = Math.max(next, Math.min(normalized === 'pending' || normalized === 'validating' || normalized === 'processing' ? cap : 100, server));
        }
        const prior = Number(previous);
        if (Number.isFinite(prior) && prior > 0 && prior < 100) {
          next = Math.max(next, Math.min(cap, prior));
        }
        return Math.max(floor, Math.min(cap, next));
      }

      function ensureProcessingHint(card, visible) {
        let hint = card.querySelector('.vc-processing-hint');
        if (visible) {
          if (!hint) {
            hint = document.createElement('div');
            hint.className = 'vc-processing-hint';
            hint.style.cssText = 'font-size:.83rem;color:var(--muted);margin-bottom:.6rem';
            const actions = card.querySelector('.vc-card-actions');
            card.insertBefore(hint, actions || null);
          }
          hint.textContent = processingHintText;
        } else if (hint) {
          hint.remove();
        }
      }

      function ensureFailureHint(card, visible, message = null) {
        let hint = card.querySelector('.vc-failure-hint');
        if (visible) {
          if (!hint) {
            hint = document.createElement('div');
            hint.className = 'vc-alert error vc-failure-hint';
            hint.style.cssText = 'margin-bottom:.6rem;font-size:.82rem';
            const actions = card.querySelector('.vc-card-actions');
            card.insertBefore(hint, actions || null);
          }
          hint.textContent = String(message || failedHintText);
        } else if (hint) {
          hint.remove();
        }
      }

      function ensureTechDetail(card, detail) {
        let box = card.querySelector('.vc-tech-detail');
        const text = String(detail || '').trim();
        if (!text) {
          box?.remove();
          return;
        }
        if (!box) {
          box = document.createElement('details');
          box.className = 'vc-tech-detail';
          const summary = document.createElement('summary');
          summary.textContent = techDetailsText;
          const code = document.createElement('code');
          code.setAttribute('dir', 'ltr');
          box.append(summary, code);
          const hint = card.querySelector('.vc-failure-hint');
          if (hint) hint.after(box);
          else card.insertBefore(box, card.querySelector('.vc-card-actions') || null);
        }
        box.querySelector('code').textContent = text;
      }

      function setRetryButton(card, voiceId, show) {
        const actions = card.querySelector('.vc-card-actions');
        if (!actions) return;
        let button = actions.querySelector('.vc-retry-btn');
        if (!show) {
          button?.remove();
          return;
        }
        if (!button) {
          button = document.createElement('button');
          button.type = 'button';
          button.className = 'btn btn-primary vc-retry-btn';
          button.textContent = retryText;
          actions.insertBefore(button, actions.firstChild || null);
        }
        button.dataset.voiceId = String(voiceId);
        button.disabled = false;
      }

      function setReconsentButton(card, voiceId, show) {
        const actions = card.querySelector('.vc-card-actions');
        if (!actions) return;
        let button = actions.querySelector('.vc-reconsent-btn');
        if (!show) {
          button?.remove();
          return;
        }
        if (!button) {
          button = document.createElement('button');
          button.type = 'button';
          button.className = 'btn btn-primary vc-reconsent-btn';
          button.textContent = reconsentButtonText;
          actions.insertBefore(button, actions.firstChild || null);
        }
        button.dataset.voiceId = String(voiceId);
      }

      function setReconsentMode(voiceId, voiceName) {
        reconsentVoiceId = voiceId ? String(voiceId) : null;
        const active = Boolean(reconsentVoiceId);
        const banner = document.getElementById('vcReconsentBanner');
        const title = document.getElementById('vcReconsentTitle');
        const createTitle = document.getElementById('vcCreateTitle');
        const guidelines = document.getElementById('vcGuidelines');
        const steps = document.querySelector('.vc-steps');
        const backButton = document.getElementById('vcBackStep');
        const submitText = document.getElementById('vcSubmitText');
        if (banner) banner.hidden = !active;
        if (title) title.textContent = active ? reconsentTitleTemplate.replace('__NAME__', String(voiceName || '')) : '';
        if (createTitle) createTitle.hidden = active;
        if (guidelines) guidelines.hidden = active;
        if (steps) steps.hidden = active;
        if (backButton) backButton.hidden = active;
        if (submitText) submitText.textContent = active ? reconsentSubmitText : createSubmitText;
        consentBlob = null;
        showPreview('vcConsentPreview', null);
        setConsentDuration(0);
        const consentTimerEl = document.getElementById('vcConsentTimer');
        if (consentTimerEl) consentTimerEl.textContent = '00:00';
        const consentFile = document.getElementById('vcConsentFileInput');
        if (consentFile) consentFile.value = '';
        const consentCheckbox = document.getElementById('vcConsent');
        if (consentCheckbox) consentCheckbox.checked = false;
        if (active) {
          if (createPanel) createPanel.style.display = '';
          showStep(2);
          createPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
          showStep(1);
          updateCountUI();
        }
      }

      function ensureProgressUI(card) {
        let progress = card.querySelector('[data-vc-progress]');
        if (!progress) {
          progress = document.createElement('div');
          progress.className = 'vc-progress';
          progress.setAttribute('data-vc-progress', '1');
          progress.innerHTML = `
            <div class="vc-progress-track">
              <div class="vc-progress-fill" data-vc-progress-fill></div>
            </div>
            <div class="vc-progress-meta">
              <span data-vc-progress-status></span>
              <span data-vc-progress-percent></span>
            </div>
            <div class="vc-progress-delay" data-vc-progress-delay></div>
          `;
          const actions = card.querySelector('.vc-card-actions');
          card.insertBefore(progress, actions || null);
        }
        return progress;
      }

      function updateProgressUI(card, status, elapsedSeconds, delayed = false, serverProgress = null) {
        const progress = ensureProgressUI(card);
        const normalized = String(status || '').toLowerCase();
        const isProcessing = ['pending', 'validating', 'processing'].includes(normalized);
        if (!isProcessing) {
          progress.classList.add('is-hidden');
          progress.classList.remove('is-delayed');
          return;
        }

        progress.classList.remove('is-hidden');
        progress.classList.toggle('is-delayed', Boolean(delayed));

        const percent = statusProgress(normalized, Number(elapsedSeconds || 0), serverProgress, currentDisplayedPercent(card));
        const fill = progress.querySelector('[data-vc-progress-fill]');
        const statusText = progress.querySelector('[data-vc-progress-status]');
        const percentText = progress.querySelector('[data-vc-progress-percent]');
        const delayText = progress.querySelector('[data-vc-progress-delay]');
        if (fill) fill.style.width = `${percent}%`;
        if (statusText) statusText.textContent = statusLabels[normalized] || normalized.toUpperCase();
        if (percentText) percentText.textContent = `${Math.round(percent)}%`;
        if (delayText) delayText.textContent = delayed ? delayedHintText : '';
      }

      function setUseButton(card, voiceId, show) {
        const actions = card.querySelector('.vc-card-actions');
        if (!actions) return;
        let link = actions.querySelector('[data-vc-action="use"]');

        if (!show) {
          link?.remove();
          return;
        }

        const href = `${GENERATOR_URL_BASE}?cloned_voice=${encodeURIComponent(String(voiceId))}#generateVoiceSection`;
        if (!link) {
          link = document.createElement('a');
          link.className = 'btn btn-primary';
          link.setAttribute('data-vc-action', 'use');
          link.textContent = useInGeneratorText;
          actions.insertBefore(link, actions.firstChild || null);
        }
        link.setAttribute('href', href);
      }

      function setDeleteButton(card, show) {
        const actions = card.querySelector('.vc-card-actions');
        if (!actions) return;
        let button = actions.querySelector('.vc-delete-btn');
        if (!show) {
          button?.remove();
          return;
        }
        if (button) return;
        button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-ghost vc-delete-btn';
        button.dataset.voiceId = String(card.dataset.voiceId || '');
        button.dataset.confirm = deleteConfirmText;
        button.textContent = deleteVoiceText;
        actions.appendChild(button);
      }

      function focusCard(voiceId) {
        if (!voiceGrid || !voiceId) return;
        const card = voiceGrid.querySelector(`.vc-card[data-voice-id="${escapeSelector(String(voiceId))}"]`);
        if (!card) return;
        card.classList.add('vc-card-highlight');
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => card.classList.remove('vc-card-highlight'), 1800);
      }

      function applyStatusToCard(card, payload) {
        if (!card || !payload) return;
        const previousStatus = String(card.dataset.status || '').toLowerCase();
        const status = String(payload.status || previousStatus || 'pending').toLowerCase();
        if (payload.language_code) card.dataset.languageCode = String(payload.language_code);
        if (payload.name) card.dataset.voiceName = String(payload.name);
        card.dataset.status = status;
        if (status === 'failed' && previousStatus && previousStatus !== 'failed') {
          currentVoiceCount = Math.max(0, currentVoiceCount - 1);
          updateCountUI();
        }

        const createdTs = Number(payload.created_at_ts || card.dataset.createdTs || Math.floor(Date.now() / 1000));
        card.dataset.createdTs = String(createdTs);
        // A retry starts a new attempt; progress is measured from that, not from creation.
        const startedTs = Number(payload.started_at_ts || card.dataset.startedTs || createdTs);
        card.dataset.startedTs = String(startedTs);

        const rawElapsed = Number(payload.elapsed_seconds);
        const fallbackElapsed = Math.max(0, Math.floor(Date.now() / 1000) - startedTs);
        const elapsedSeconds = Number.isFinite(rawElapsed) ? Math.max(0, rawElapsed) : fallbackElapsed;
        const serverProgress = Number(payload.progress_percent ?? NaN);
        const failureReason = String(payload.failure_reason || card.dataset.failureReason || '').trim();
        if (failureReason) {
          card.dataset.failureReason = failureReason;
        }
        const isDelayed = Boolean(payload.is_delayed || (['pending', 'validating', 'processing'].includes(status) && elapsedSeconds >= 90));

        const statusBadge = card.querySelector('.vc-status');
        if (statusBadge) {
          statusBadge.className = `vc-status ${status}`;
          statusBadge.innerHTML = `${['pending', 'validating', 'processing'].includes(status) ? '<span class="dot"></span>' : ''}${escapeHtml(statusLabels[status] || status.toUpperCase())}`;
        }

        ensureProcessingHint(card, ['pending', 'validating', 'processing'].includes(status));
        ensureFailureHint(card, status === 'failed', failureReason);
        if (status !== 'failed') ensureTechDetail(card, '');
        else if (payload.failure_detail !== undefined) ensureTechDetail(card, payload.failure_detail);
        updateProgressUI(card, status, elapsedSeconds, isDelayed, serverProgress);
        setUseButton(card, payload.id || card.dataset.voiceId, status === 'ready');
        setDeleteButton(card, status !== 'ready');
        if (status !== 'failed') setRetryButton(card, payload.id || card.dataset.voiceId, false);
        else if (payload.can_retry !== undefined) setRetryButton(card, payload.id || card.dataset.voiceId, Boolean(payload.can_retry));
        if (status !== 'failed') setReconsentButton(card, payload.id || card.dataset.voiceId, false);
        else if (payload.can_replace_consent !== undefined) setReconsentButton(card, payload.id || card.dataset.voiceId, Boolean(payload.can_replace_consent));
      }

      function startPolling(voiceId) {
        const id = String(voiceId || '').trim();
        if (!id || pollers.has(id)) return;

        const run = async () => {
          const card = document.querySelector(`.vc-card[data-voice-id="${escapeSelector(id)}"]`);
          if (!card) {
            stopPolling(id);
            return;
          }
          try {
            const response = await fetch(`${STATUS_URL_BASE}${id}`, {
              headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
            });
            if (!response.ok) {
              if ([401, 403, 404, 419].includes(response.status)) stopPolling(id);
              return;
            }
            const data = await response.json().catch(() => ({}));
            applyStatusToCard(card, data);
            if (String(data?.status || '').toLowerCase() === 'ready' || String(data?.status || '').toLowerCase() === 'failed') {
              stopPolling(id);
            }
          } catch (_error) {
            // Keep polling on transient errors.
          }
        };

        run();
        const timerId = setInterval(run, 4500);
        pollers.set(id, timerId);
      }

      function stopPolling(voiceId) {
        const id = String(voiceId || '').trim();
        if (!id) return;
        const timerId = pollers.get(id);
        if (timerId) {
          clearInterval(timerId);
          pollers.delete(id);
        }
      }

      function buildVoiceCardHtml(voice) {
        const voiceId = String(voice?.id || '').trim();
        const status = String(voice?.status || 'pending').toLowerCase();
        const isProcessing = ['pending', 'validating', 'processing'].includes(status);
        const failureReason = escapeHtml(String(voice?.failure_reason || failedHintText));
        const sourceType = String(voice?.source_type || 'upload').toLowerCase();
        const sourceLabel = sourceTypeLabels[sourceType] || sourceTypeLabels.upload;
        const createdTs = Number(voice?.created_at_ts || Math.floor(Date.now() / 1000));
        const createdLabel = new Date(createdTs * 1000).toLocaleDateString(pageLocale.startsWith('ar') ? 'ar-EG' : 'en-US', {
          year: 'numeric',
          month: 'short',
          day: '2-digit',
        });
        const percent = statusProgress(status, 0, Number(voice?.progress_percent ?? NaN));
        const languageCode = String(voice?.language_code || 'ar-XA');
        const languageLabel = languageLabels[languageCode] || languageCode;
        const name = escapeHtml(String(voice?.name || `Voice ${voiceId}`));
        const statusLabel = escapeHtml(statusLabels[status] || status.toUpperCase());
        const metaLabel = `${escapeHtml(languageLabel)} &middot; ${escapeHtml(sourceLabel)}`;

        return `
          <article class="vc-card" data-voice-id="${escapeHtml(voiceId)}" data-voice-name="${name}" data-language-code="${escapeHtml(languageCode)}" data-status="${escapeHtml(status)}" data-created-ts="${createdTs}" data-started-ts="${Number(voice?.started_at_ts || createdTs)}" data-failure-reason="${failureReason}">
            <div class="vc-card-header">
              <h3 class="vc-card-name">${name}</h3>
              <span class="vc-status ${escapeHtml(status)}">${isProcessing ? '<span class="dot"></span>' : ''}${statusLabel}</span>
            </div>
            <div class="vc-card-meta">
              <span>{{ __('ui.voice_cloning.created_on') }} ${escapeHtml(createdLabel)}</span>
              <span>${metaLabel}</span>
            </div>
            ${status === 'failed' ? `<div class="vc-alert error vc-failure-hint" style="margin-bottom:.6rem;font-size:.82rem">${failureReason}</div>` : ''}
            ${isProcessing ? `<div class="vc-processing-hint" style="font-size:.83rem;color:var(--muted);margin-bottom:.6rem">${escapeHtml(processingHintText)}</div>` : ''}
            <div class="vc-progress ${isProcessing ? '' : 'is-hidden'}" data-vc-progress>
              <div class="vc-progress-track">
                <div class="vc-progress-fill" data-vc-progress-fill style="width: ${Math.round(percent)}%"></div>
              </div>
              <div class="vc-progress-meta">
                <span data-vc-progress-status>${statusLabel}</span>
                <span data-vc-progress-percent>${Math.round(percent)}%</span>
              </div>
              <div class="vc-progress-delay" data-vc-progress-delay></div>
            </div>
            <div class="vc-card-actions">
              ${status === 'ready' ? `<a href="${GENERATOR_URL_BASE}?cloned_voice=${encodeURIComponent(voiceId)}#generateVoiceSection" class="btn btn-primary" data-vc-action="use">${escapeHtml(useInGeneratorText)}</a>` : `<button type="button" class="btn btn-ghost vc-delete-btn" data-voice-id="${escapeHtml(voiceId)}" data-confirm="${escapeHtml(deleteConfirmText)}">${escapeHtml(deleteVoiceText)}</button>`}
            </div>
          </article>
        `;
      }

      function upsertVoiceCard(voice) {
        if (!voiceGrid || !voice?.id) return;
        const voiceId = String(voice.id);
        let card = voiceGrid.querySelector(`.vc-card[data-voice-id="${escapeSelector(voiceId)}"]`);
        if (!card) {
          voiceGrid.insertAdjacentHTML('afterbegin', buildVoiceCardHtml(voice));
          card = voiceGrid.querySelector(`.vc-card[data-voice-id="${escapeSelector(voiceId)}"]`);
        }
        if (!card) return;
        applyStatusToCard(card, voice);
      }

      function resetCreateFormUi() {
        form?.reset();
        showPreview('vcRecPreview', null);
        showPreview('vcConsentPreview', null);
        setConsentLanguage(document.getElementById('vcLanguage')?.value || 'ar-XA');
        document.getElementById('vcSourceType').value = 'upload';
        uploadDuration = 0;
        recordDuration = 0;
        uploadMeasureToken += 1;
        consentMeasureToken += 1;
        setAudioDuration(0);
        setConsentDuration(0);
        recordedBlob = null;
        consentBlob = null;

        if (timer) timer.textContent = '00:00';
        if (recHint) {
          recHint.textContent = recordStartText;
          recHint.style.color = '';
        }
        if (recActions) recActions.style.display = 'none';
        recBtn?.classList.remove('recording');
        document.getElementById('vcRecorder')?.classList.remove('live');

        if (fileInput) fileInput.value = '';
        if (fileInfo) fileInfo.style.display = 'none';
        if (uploadZone) uploadZone.style.display = '';
        const sampleRange = document.getElementById('vcSampleRange');
        if (sampleRange) sampleRange.className = 'vc-range';

        const consentTimer = document.getElementById('vcConsentTimer');
        const consentHint = document.getElementById('vcConsentRecHint');
        const consentActions = document.getElementById('vcConsentRecActions');
        const consentFile = document.getElementById('vcConsentFileInput');
        const consentInfo = document.getElementById('vcConsentFileInfo');
        const consentZone = document.getElementById('vcConsentUploadZone');
        if (consentTimer) consentTimer.textContent = '00:00';
        if (consentHint) {
          consentHint.textContent = recordStartText;
          consentHint.style.color = '';
        }
        if (consentActions) consentActions.style.display = 'none';
        document.getElementById('vcConsentRecBtn')?.classList.remove('recording');
        document.getElementById('vcConsentRecorder')?.classList.remove('live');
        if (consentFile) consentFile.value = '';
        if (consentInfo) consentInfo.style.display = 'none';
        if (consentZone) consentZone.style.display = '';

        document.querySelectorAll('.vc-tab').forEach((tab) => tab.classList.remove('active'));
        document.querySelector('.vc-tab[data-tab="upload"]')?.classList.add('active');
        const uploadPanel = document.getElementById('vcUploadPanel');
        const recordPanel = document.getElementById('vcRecordPanel');
        if (uploadPanel) uploadPanel.style.display = '';
        if (recordPanel) recordPanel.style.display = 'none';
        showStep(1);
        syncConsentScript();
      }

      document.querySelectorAll('.vc-tab').forEach((tab) => {
        tab.addEventListener('click', function() {
          document.querySelectorAll('.vc-tab').forEach((t) => t.classList.remove('active'));
          this.classList.add('active');
          const mode = this.dataset.tab || 'upload';
          const sourceType = document.getElementById('vcSourceType');
          const uploadPanel = document.getElementById('vcUploadPanel');
          const recordPanel = document.getElementById('vcRecordPanel');
          if (sourceType) sourceType.value = mode;
          setAudioDuration(mode === 'recording' ? recordDuration : uploadDuration);
          if (uploadPanel) uploadPanel.style.display = mode === 'upload' ? '' : 'none';
          if (recordPanel) recordPanel.style.display = mode === 'recording' ? '' : 'none';
        });
      });

      const uploadZone = document.getElementById('vcUploadZone');
      const fileInput = document.getElementById('vcFileInput');
      const fileInfo = document.getElementById('vcFileInfo');
      const fileName = document.getElementById('vcFileName');
      const fileRemove = document.getElementById('vcFileRemove');

      function setAudioDuration(seconds) {
        const durationEl = document.getElementById('vcAudioDuration');
        if (durationEl) durationEl.value = String(seconds || 0);
      }

      let uploadDuration = 0;
      let recordDuration = 0;
      let uploadMeasureToken = 0;
      let consentMeasureToken = 0;

      // Seconds of audio in a file, or null when the browser cannot read it.
      // Recorder WebM files report Infinity until fully decoded, so decode as a fallback.
      async function measureDuration(file) {
        const fromMedia = await new Promise((resolve) => {
          const audio = new Audio();
          const url = URL.createObjectURL(file);
          let settled = false;
          const done = (value) => {
            if (settled) return;
            settled = true;
            URL.revokeObjectURL(url);
            resolve(value);
          };
          audio.preload = 'metadata';
          audio.addEventListener('loadedmetadata', () => done(audio.duration));
          audio.addEventListener('error', () => done(NaN));
          setTimeout(() => done(NaN), 8000);
          audio.src = url;
        });
        if (Number.isFinite(fromMedia) && fromMedia > 0) return fromMedia;

        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return null;
        let context = null;
        try {
          context = new AudioCtx();
          const raw = await file.arrayBuffer();
          const decoded = await new Promise((resolve, reject) => {
            const pending = context.decodeAudioData(raw, resolve, reject);
            if (pending && typeof pending.then === 'function') pending.then(resolve, reject);
          });
          return Number.isFinite(decoded?.duration) ? decoded.duration : null;
        } catch (_error) {
          return null;
        } finally {
          try { await context?.close?.(); } catch (_error) {}
        }
      }

      async function handleFileSelect(file) {
        if (!file || !fileName || !fileInfo || !uploadZone) return;
        fileName.textContent = `{{ __('ui.voice_cloning.upload_selected') }} ${file.name} (${(file.size / 1024 / 1024).toFixed(1)} MB)`;
        fileInfo.style.display = '';
        uploadZone.style.display = 'none';
        const showingUpload = () => String(document.getElementById('vcSourceType')?.value || 'upload') === 'upload';
        uploadDuration = 0;
        if (showingUpload()) setAudioDuration(0);
        const token = ++uploadMeasureToken;
        const measured = await measureDuration(file);
        if (token !== uploadMeasureToken) return;
        const range = document.getElementById('vcSampleRange');
        if (measured === null) {
          if (range) {
            range.textContent = '';
            range.className = 'vc-range bad';
          }
          showAlert('error', unreadableFileText);
          return;
        }
        const duration = Math.round(measured);
        uploadDuration = duration;
        if (showingUpload()) setAudioDuration(duration);
        if (range) {
          range.textContent = `${duration}s`;
          range.className = duration >= MIN_DURATION && duration <= MAX_DURATION ? 'vc-range ok' : 'vc-range bad';
        }
        if (duration < MIN_DURATION) {
          showAlert('error', sampleTooShortText);
        } else if (duration > MAX_DURATION) {
          showAlert('error', sampleTooLongText);
        }
      }

      if (uploadZone && fileInput && fileInfo) {
        ['dragover', 'dragenter'].forEach((eventName) => {
          uploadZone.addEventListener(eventName, (event) => {
            event.preventDefault();
            uploadZone.classList.add('dragover');
          });
        });
        ['dragleave', 'drop'].forEach((eventName) => {
          uploadZone.addEventListener(eventName, (event) => {
            event.preventDefault();
            uploadZone.classList.remove('dragover');
          });
        });
        uploadZone.addEventListener('drop', (event) => {
          const dropped = event.dataTransfer?.files?.[0];
          if (!dropped) return;
          fileInput.files = event.dataTransfer.files;
          handleFileSelect(dropped);
        });
        fileInput.addEventListener('change', function() {
          if (this.files?.[0]) handleFileSelect(this.files[0]);
        });
      }

      if (fileRemove && fileInput && fileInfo && uploadZone) {
        fileRemove.addEventListener('click', function() {
          fileInput.value = '';
          fileInfo.style.display = 'none';
          uploadZone.style.display = '';
          uploadMeasureToken += 1;
          uploadDuration = 0;
          setAudioDuration(0);
        });
      }

      let mediaRecorder = null;
      let audioChunks = [];
      let recordingStart = 0;
      let timerInterval = null;
      let recordedBlob = null;
      let consentBlob = null;
      let consentRecorder = null;
      let consentChunks = [];
      let consentStarted = 0;
      let consentTimerInterval = null;

      const recBtn = document.getElementById('vcRecBtn');
      const timer = document.getElementById('vcTimer');
      const recHint = document.getElementById('vcRecHint');
      const recActions = document.getElementById('vcRecActions');
      const recRetry = document.getElementById('vcRecRetry');

      function getSupportedMime() {
        const mimes = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
        for (const mime of mimes) {
          if (MediaRecorder.isTypeSupported(mime)) return mime;
        }
        return '';
      }

      const STOP_TAIL_MS = 700;
      let sampleStopping = false;
      let consentStopping = false;
      let consentCountdownTimer = null;

      // Keep recording briefly after the tap so the last word is not cut off.
      function stopRecording() {
        if (!mediaRecorder || mediaRecorder.state !== 'recording' || sampleStopping) return;
        sampleStopping = true;
        if (recBtn) recBtn.classList.remove('recording');
        const recorder = mediaRecorder;
        setTimeout(() => {
          if (timerInterval) clearInterval(timerInterval);
          if (recorder.state === 'recording') recorder.stop();
          sampleStopping = false;
        }, STOP_TAIL_MS);
      }

      async function startRecording() {
        try {
          const stream = await openMicrophone();
          audioChunks = [];
          mediaRecorder = new MediaRecorder(stream, { mimeType: getSupportedMime() });
          mediaRecorder.ondataavailable = (event) => { if (event.data.size > 0) audioChunks.push(event.data); };
          mediaRecorder.onstop = () => {
            stream.getTracks().forEach((track) => track.stop());
            const recorderMimeType = String(mediaRecorder.mimeType || 'audio/webm').trim() || 'audio/webm';
            recordedBlob = new Blob(audioChunks, { type: recorderMimeType });
            showPreview('vcRecPreview', recordedBlob);
            const duration = Math.round((Date.now() - recordingStart) / 1000);
            recordDuration = duration;
            setAudioDuration(duration);
            document.getElementById('vcRecorder')?.classList.remove('live');
            if (recHint) {
              if (duration < MIN_DURATION) {
                recHint.textContent = tooShortRecordText;
                recHint.style.color = '#dc2626';
              } else if (duration > MAX_DURATION) {
                recHint.textContent = tooLongRecordText;
                recHint.style.color = '#dc2626';
              } else {
                recHint.textContent = `${duration}s · ${sampleReadyText}`;
                recHint.style.color = '#16a34a';
              }
            }
            if (recActions) recActions.style.display = '';
          };

          mediaRecorder.start(250);
          recordingStart = Date.now();
          showPreview('vcRecPreview', null);
          if (recBtn) recBtn.classList.add('recording');
          document.getElementById('vcRecorder')?.classList.add('live');
          if (recHint) {
            recHint.textContent = recordingText;
            recHint.style.color = '';
          }
          if (recActions) recActions.style.display = 'none';

          timerInterval = setInterval(() => {
            const elapsed = Math.round((Date.now() - recordingStart) / 1000);
            const m = String(Math.floor(elapsed / 60)).padStart(2, '0');
            const s = String(elapsed % 60).padStart(2, '0');
            if (timer) timer.textContent = `${m}:${s}`;
            if (elapsed >= MAX_DURATION) stopRecording();
          }, 250);
        } catch (error) {
          if (error?.name === 'NotAllowedError') {
            showAlert('error', '{{ __("ui.voice_cloning.mic_permission_denied") }}');
          } else {
            showAlert('error', '{{ __("ui.voice_cloning.mic_not_available") }}');
          }
        }
      }

      if (recBtn) {
        recBtn.addEventListener('click', async function() {
          if (mediaRecorder && mediaRecorder.state === 'recording') stopRecording();
          else await startRecording();
        });
      }

      if (recRetry) {
        recRetry.addEventListener('click', function() {
          recordedBlob = null;
          showPreview('vcRecPreview', null);
          if (timer) timer.textContent = '00:00';
          if (recHint) {
            recHint.textContent = recordStartText;
            recHint.style.color = '';
          }
          if (recActions) recActions.style.display = 'none';
          if (recBtn) recBtn.classList.remove('recording');
          recordDuration = 0;
          setAudioDuration(0);
        });
      }

      function setConsentDuration(seconds) {
        const durationEl = document.getElementById('vcConsentDuration');
        if (durationEl) durationEl.value = String(seconds || 0);
      }

      function encodeWav(samples, sampleRate) {
        const buffer = new ArrayBuffer(44 + samples.length * 2);
        const view = new DataView(buffer);
        const writeText = (offset, text) => {
          for (let i = 0; i < text.length; i += 1) view.setUint8(offset + i, text.charCodeAt(i));
        };
        writeText(0, 'RIFF');
        view.setUint32(4, 36 + samples.length * 2, true);
        writeText(8, 'WAVE');
        writeText(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, 1, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, sampleRate * 2, true);
        view.setUint16(32, 2, true);
        view.setUint16(34, 16, true);
        writeText(36, 'data');
        view.setUint32(40, samples.length * 2, true);
        let offset = 44;
        for (let i = 0; i < samples.length; i += 1) {
          const s = Math.max(-1, Math.min(1, samples[i]));
          view.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7fff, true);
          offset += 2;
        }
        return new Blob([view], { type: 'audio/wav' });
      }

      // The voice service wants 24 kHz mono 16-bit WAV. Converting here means the server
      // never needs ffmpeg or exec(), which many hosts disable.
      async function toGeminiWav(blob) {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        const OfflineCtx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
        if (!blob || !AudioCtx || !OfflineCtx) return blob;
        let context = null;
        try {
          const raw = await blob.arrayBuffer();
          context = new AudioCtx();
          const decoded = await new Promise((resolve, reject) => {
            const pending = context.decodeAudioData(raw.slice(0), resolve, reject);
            if (pending && typeof pending.then === 'function') pending.then(resolve, reject);
          });
          const sampleRate = 24000;
          const length = Math.max(1, Math.ceil(decoded.duration * sampleRate));
          const offline = new OfflineCtx(1, length, sampleRate);
          const source = offline.createBufferSource();
          source.buffer = decoded;
          source.connect(offline.destination);
          source.start(0);
          const rendered = await offline.startRendering();
          return encodeWav(rendered.getChannelData(0), sampleRate);
        } catch (_error) {
          return blob;
        } finally {
          try { await context?.close?.(); } catch (_error) {}
        }
      }

      function wavName(base, blob) {
        return `${base}.${blobExtension(blob)}`;
      }

      function blobExtension(blob) {
        const blobType = String(blob?.type || '').toLowerCase();
        if (blobType.includes('wav')) return 'wav';
        if (blobType.includes('webm')) return 'webm';
        if (blobType.includes('ogg')) return 'ogg';
        if (blobType.includes('flac')) return 'flac';
        if (blobType.includes('mpeg') || blobType.includes('mp3')) return 'mp3';
        const fileExt = String(blob?.name || '').split('.').pop().toLowerCase();
        if (['wav', 'mp3', 'm4a', 'mp4', 'ogg', 'webm', 'flac'].includes(fileExt)) return fileExt;
        return 'mp4';
      }

      function showStep(step) {
        document.querySelectorAll('[data-vc-step-panel]').forEach((panel) => {
          panel.hidden = String(panel.getAttribute('data-vc-step-panel')) !== String(step);
        });
        document.querySelectorAll('.vc-step').forEach((button) => {
          const stepNumber = Number(button.getAttribute('data-vc-step'));
          button.classList.toggle('active', stepNumber === Number(step));
          button.classList.toggle('done', stepNumber < Number(step));
        });
      }

      function setConsentLanguage(language) {
        const value = String(language || '').toLowerCase().startsWith('ar') ? 'ar-XA' : 'en-US';
        const input = document.getElementById('vcConsentLanguage');
        if (input) input.value = value;
        document.querySelectorAll('[data-consent-lang]').forEach((button) => {
          const active = button.getAttribute('data-consent-lang') === value;
          button.classList.toggle('active', active);
          button.setAttribute('aria-checked', active ? 'true' : 'false');
        });
        syncConsentScript();
      }

      function syncConsentScript() {
        const language = document.getElementById('vcConsentLanguage')?.value || 'ar-XA';
        const script = document.getElementById('vcConsentScript');
        if (!script) return;
        script.textContent = consentScripts[language] || consentScripts['en-US'] || '';
        script.dir = String(language).toLowerCase().startsWith('ar') ? 'rtl' : 'ltr';
      }

      document.querySelectorAll('[data-consent-lang]').forEach((button) => {
        button.addEventListener('click', function() {
          const next = button.getAttribute('data-consent-lang');
          if (next === document.getElementById('vcConsentLanguage')?.value) return;
          setConsentLanguage(next);
          if (consentBlob) {
            consentBlob = null;
            showPreview('vcConsentPreview', null);
            setConsentDuration(0);
            if (consentTimer) consentTimer.textContent = '00:00';
            if (consentHint) {
              consentHint.textContent = recordStartText;
              consentHint.style.color = '';
            }
            if (consentActions) consentActions.style.display = 'none';
          }
        });
      });

      function sampleIsReady() {
        const name = String(document.getElementById('vcName')?.value || '').trim();
        if (name.length < 2) {
          showAlert('error', nameRequiredText);
          return false;
        }
        const sourceType = String(document.getElementById('vcSourceType')?.value || 'upload');
        const duration = Number(document.getElementById('vcAudioDuration')?.value || 0);
        const hasFile = sourceType === 'recording' ? Boolean(recordedBlob) : Boolean(fileInput?.files?.[0]);
        if (!hasFile) {
          showAlert('error', sampleTooShortText);
          return false;
        }
        if (duration < MIN_DURATION) {
          showAlert('error', sampleTooShortText);
          return false;
        }
        if (duration > MAX_DURATION) {
          showAlert('error', sampleTooLongText);
          return false;
        }
        return true;
      }

      document.getElementById('vcLanguage')?.addEventListener('change', function() {
        setConsentLanguage(this.value);
      });
      document.getElementById('vcNextStep')?.addEventListener('click', function() {
        if (sampleIsReady()) showStep(2);
      });
      document.getElementById('vcBackStep')?.addEventListener('click', function() {
        showStep(1);
      });
      document.getElementById('vcStepSample')?.addEventListener('click', function() {
        showStep(1);
      });
      document.getElementById('vcStepConsent')?.addEventListener('click', function() {
        if (sampleIsReady()) showStep(2);
      });
      document.getElementById('vcCopyConsent')?.addEventListener('click', async function() {
        const text = document.getElementById('vcConsentScript')?.textContent || '';
        try {
          await navigator.clipboard.writeText(text);
          this.textContent = copiedLabel;
          setTimeout(() => { this.textContent = copyLabel; }, 1400);
        } catch (_error) {
          showAlert('error', copyLabel);
        }
      });

      const consentRecBtn = document.getElementById('vcConsentRecBtn');
      const consentTimer = document.getElementById('vcConsentTimer');
      const consentHint = document.getElementById('vcConsentRecHint');
      const consentActions = document.getElementById('vcConsentRecActions');
      const consentRetry = document.getElementById('vcConsentRecRetry');
      const consentFileInput = document.getElementById('vcConsentFileInput');
      const consentFileInfo = document.getElementById('vcConsentFileInfo');
      const consentFileName = document.getElementById('vcConsentFileName');
      const consentFileRemove = document.getElementById('vcConsentFileRemove');
      const consentUploadZone = document.getElementById('vcConsentUploadZone');

      function markConsentFile(file, duration) {
        consentBlob = null;
        if (consentFileName && consentFileInfo && consentUploadZone) {
          consentFileName.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(1)} MB)`;
          consentFileInfo.style.display = '';
          consentUploadZone.style.display = 'none';
        }
        if (duration) setConsentDuration(duration);
      }

      consentFileInput?.addEventListener('change', function() {
        const file = this.files?.[0];
        if (!file) return;
        markConsentFile(file);
        setConsentDuration(0);
        showPreview('vcConsentPreview', file);
        const token = ++consentMeasureToken;
        measureDuration(file).then((measured) => {
          if (token !== consentMeasureToken) return;
          if (measured === null) {
            showAlert('error', unreadableFileText);
            return;
          }
          const duration = Math.round(measured);
          setConsentDuration(duration);
          if (duration < MIN_CONSENT) showAlert('error', consentTooShortText);
          else if (duration > MAX_DURATION) showAlert('error', sampleTooLongText);
        });
      });
      consentFileRemove?.addEventListener('click', function() {
        if (consentFileInput) consentFileInput.value = '';
        showPreview('vcConsentPreview', null);
        if (consentFileInfo) consentFileInfo.style.display = 'none';
        if (consentUploadZone) consentUploadZone.style.display = '';
        consentMeasureToken += 1;
        setConsentDuration(0);
      });

      function stopConsentRecording() {
        if (!consentRecorder || consentRecorder.state !== 'recording' || consentStopping) return;
        consentStopping = true;
        if (consentCountdownTimer) clearInterval(consentCountdownTimer);
        consentRecBtn?.classList.remove('recording');
        const recorder = consentRecorder;
        setTimeout(() => {
          document.getElementById('vcConsentRecorder')?.classList.remove('live', 'countdown');
          if (consentTimerInterval) clearInterval(consentTimerInterval);
          if (recorder.state === 'recording') recorder.stop();
          consentStopping = false;
        }, STOP_TAIL_MS);
      }

      // The recorder is already running during the countdown, so the first
      // word of the consent sentence is never clipped.
      function runConsentCountdown() {
        const box = document.getElementById('vcConsentRecorder');
        let remaining = 3;
        box?.classList.add('countdown');
        const render = () => {
          if (!consentHint) return;
          consentHint.style.color = '';
          consentHint.textContent = remaining > 0
            ? getReadyTemplate.replace('__N__', String(remaining))
            : speakNowText;
          if (remaining <= 0) {
            consentHint.style.color = '#16a34a';
            box?.classList.remove('countdown');
          }
        };
        render();
        if (consentCountdownTimer) clearInterval(consentCountdownTimer);
        consentCountdownTimer = setInterval(() => {
          remaining -= 1;
          render();
          if (remaining <= 0) clearInterval(consentCountdownTimer);
        }, 1000);
      }

      async function startConsentRecording() {
        try {
          const stream = await openMicrophone();
          consentChunks = [];
          consentRecorder = new MediaRecorder(stream, { mimeType: getSupportedMime() });
          consentRecorder.ondataavailable = (event) => { if (event.data.size > 0) consentChunks.push(event.data); };
          consentRecorder.onstop = () => {
            stream.getTracks().forEach((track) => track.stop());
            const recorderMimeType = String(consentRecorder.mimeType || 'audio/webm').trim() || 'audio/webm';
            consentBlob = new Blob(consentChunks, { type: recorderMimeType });
            showPreview('vcConsentPreview', consentBlob);
            if (consentFileInput) consentFileInput.value = '';
            if (consentFileInfo) consentFileInfo.style.display = 'none';
            if (consentUploadZone) consentUploadZone.style.display = '';
            const duration = Math.round((Date.now() - consentStarted) / 1000);
            consentMeasureToken += 1;
            setConsentDuration(duration);
            if (consentHint) {
              if (duration < MIN_CONSENT) {
                consentHint.textContent = consentTooShortText;
                consentHint.style.color = '#dc2626';
              } else if (duration > MAX_DURATION) {
                consentHint.textContent = tooLongRecordText;
                consentHint.style.color = '#dc2626';
              } else {
                consentHint.textContent = `${duration}s · ${consentReadyText}`;
                consentHint.style.color = '#16a34a';
              }
            }
            if (consentActions) consentActions.style.display = '';
          };
          consentRecorder.start(250);
          consentStarted = Date.now();
          consentRecBtn?.classList.add('recording');
          document.getElementById('vcConsentRecorder')?.classList.add('live');
          showPreview('vcConsentPreview', null);
          runConsentCountdown();
          if (consentActions) consentActions.style.display = 'none';
          consentTimerInterval = setInterval(() => {
            const elapsed = Math.round((Date.now() - consentStarted) / 1000);
            const m = String(Math.floor(elapsed / 60)).padStart(2, '0');
            const s = String(elapsed % 60).padStart(2, '0');
            if (consentTimer) consentTimer.textContent = `${m}:${s}`;
            if (elapsed >= MAX_DURATION) stopConsentRecording();
          }, 250);
        } catch (error) {
          showAlert('error', error?.name === 'NotAllowedError'
            ? @json(__('ui.voice_cloning.mic_permission_denied'))
            : @json(__('ui.voice_cloning.mic_not_available')));
        }
      }

      consentRecBtn?.addEventListener('click', async function() {
        if (consentRecorder && consentRecorder.state === 'recording') stopConsentRecording();
        else await startConsentRecording();
      });
      consentRetry?.addEventListener('click', function() {
        consentBlob = null;
        showPreview('vcConsentPreview', null);
        if (consentTimer) consentTimer.textContent = '00:00';
        if (consentHint) {
          consentHint.textContent = recordStartText;
          consentHint.style.color = '';
        }
        if (consentActions) consentActions.style.display = 'none';
        consentRecBtn?.classList.remove('recording');
        setConsentDuration(0);
      });

      syncConsentScript();

      if (form) {
        form.addEventListener('submit', async function(event) {
          event.preventDefault();
          const submitBtn = document.getElementById('vcSubmitBtn');
          const submitText = document.getElementById('vcSubmitText');
          const submitLoading = document.getElementById('vcSubmitLoading');
          if (submitBtn) submitBtn.disabled = true;
          if (submitText) submitText.style.display = 'none';
          if (submitLoading) submitLoading.style.display = '';

          try {
            const formData = new FormData(form);
            const sourceType = String(document.getElementById('vcSourceType')?.value || 'upload');
            formData.set('source_type', sourceType);
            const consentDuration = Number(document.getElementById('vcConsentDuration')?.value || 0);
            const hasConsent = Boolean(consentBlob || consentFileInput?.files?.[0]);
            if (!hasConsent) {
              showAlert('error', consentMissingText);
              showStep(2);
              return;
            }
            if (consentDuration < MIN_CONSENT) {
              showAlert('error', consentTooShortText);
              showStep(2);
              return;
            }
            if (consentDuration > MAX_DURATION) {
              showAlert('error', sampleTooLongText);
              showStep(2);
              return;
            }
            if (!document.getElementById('vcConsent')?.checked) {
              showAlert('error', consentRequiredText);
              showStep(2);
              return;
            }

            if (reconsentVoiceId) {
              const voiceId = reconsentVoiceId;
              const consentOnly = new FormData();
              consentOnly.set('_token', CSRF);
              consentOnly.set('consent_confirmed', '1');
              consentOnly.set('consent_duration', String(consentDuration));
              consentOnly.set('consent_language', document.getElementById('vcConsentLanguage')?.value || 'ar-XA');
              const consentWav = await toGeminiWav(consentBlob || consentFileInput?.files?.[0]);
              consentOnly.set('consent_audio', consentWav, wavName('consent', consentWav));
              const response = await fetch(CONSENT_URL_TEMPLATE.replace('__VOICE_ID__', encodeURIComponent(voiceId)), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: consentOnly,
              });
              const data = await response.json().catch(() => ({}));
              if (response.ok && data?.success && data.voice) {
                const card = voiceGrid?.querySelector(`.vc-card[data-voice-id="${escapeSelector(voiceId)}"]`);
                if (card) {
                  card.dataset.startedTs = String(data.voice.started_at_ts || Math.floor(Date.now() / 1000));
                  card.dataset.failureReason = '';
                  const progressFill = card.querySelector('[data-vc-progress-fill]');
                  const progressPercent = card.querySelector('[data-vc-progress-percent]');
                  if (progressFill) progressFill.style.width = '0%';
                  if (progressPercent) progressPercent.textContent = '0%';
                  currentVoiceCount += 1;
                  card.dataset.status = 'pending';
                  applyStatusToCard(card, data.voice);
                  focusCard(voiceId);
                }
                setReconsentMode(null);
                resetCreateFormUi();
                startPolling(voiceId);
                return;
              }
              const errorText = data?.error || data?.message || Object.values(data?.errors || {}).flat().join(' ') || 'Error';
              showAlert('error', errorText);
              return;
            }

            const sampleSource = sourceType === 'recording'
              ? recordedBlob
              : (document.getElementById('vcFileInput')?.files?.[0] || null);
            if (sampleSource) {
              const sampleWav = await toGeminiWav(sampleSource);
              formData.set('audio_file', sampleWav, wavName('sample', sampleWav));
            }
            const consentSource = consentBlob || consentFileInput?.files?.[0] || null;
            if (consentSource) {
              const consentWav = await toGeminiWav(consentSource);
              formData.set('consent_audio', consentWav, wavName('consent', consentWav));
            }

            const response = await fetch(STORE_URL, {
              method: 'POST',
              headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
              body: formData,
            });
            const data = await response.json().catch(() => ({}));
            if (response.ok && data?.success) {
              if (data.voice?.id) {
                const exists = Boolean(voiceGrid?.querySelector(`.vc-card[data-voice-id="${escapeSelector(String(data.voice.id))}"]`));
                upsertVoiceCard(data.voice);
                if (!exists) {
                  currentVoiceCount += 1;
                  updateCountUI();
                }
                updateEmptyStateUI();
                focusCard(data.voice.id);
                if (['pending', 'validating', 'processing'].includes(String(data.voice.status || '').toLowerCase())) {
                  startPolling(data.voice.id);
                }
              }
              resetCreateFormUi();
              return;
            }
            const errorText = data?.error || data?.message || Object.values(data?.errors || {}).flat().join(' ') || 'Error';
            showAlert('error', errorText);
          } catch (error) {
            showAlert('error', error?.message || 'Network error');
          } finally {
            if (submitBtn) submitBtn.disabled = false;
            if (submitText) submitText.style.display = '';
            if (submitLoading) submitLoading.style.display = 'none';
          }
        });
      }

      document.getElementById('vcReconsentCancel')?.addEventListener('click', function() {
        setReconsentMode(null);
      });

      voiceGrid?.addEventListener('click', async function(event) {
        const target = event.target;
        const useLink = target instanceof Element ? target.closest('[data-vc-action="use"]') : null;
        if (useLink) {
          event.preventDefault();
          const card = useLink.closest('.vc-card');
          if (card && typeof window.openClonedVoiceInGenerator === 'function') {
            window.openClonedVoiceInGenerator({
              id: card.dataset.voiceId,
              name: card.dataset.voiceName,
              language_code: card.dataset.languageCode,
            });
          }
          return;
        }
        const reconsentButton = target instanceof Element ? target.closest('.vc-reconsent-btn') : null;
        if (reconsentButton) {
          const card = reconsentButton.closest('.vc-card');
          setReconsentMode(reconsentButton.dataset.voiceId, card?.dataset.voiceName || '');
          return;
        }
        const retryButton = target instanceof Element ? target.closest('.vc-retry-btn') : null;
        if (retryButton) {
          const retryId = String(retryButton.dataset.voiceId || '').trim();
          if (!retryId) return;
          retryButton.disabled = true;
          try {
            const response = await fetch(RETRY_URL_TEMPLATE.replace('__VOICE_ID__', encodeURIComponent(retryId)), {
              method: 'POST',
              headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            });
            const data = await response.json().catch(() => ({}));
            if (response.ok && data?.success && data.voice) {
              const card = retryButton.closest('.vc-card');
              if (card) {
                card.dataset.startedTs = String(data.voice.started_at_ts || Math.floor(Date.now() / 1000));
                card.dataset.failureReason = '';
                const progressFill = card.querySelector('[data-vc-progress-fill]');
                const progressPercent = card.querySelector('[data-vc-progress-percent]');
                if (progressFill) progressFill.style.width = '0%';
                if (progressPercent) progressPercent.textContent = '0%';
                currentVoiceCount += 1;
                updateCountUI();
                card.dataset.status = 'pending';
                applyStatusToCard(card, data.voice);
              }
              startPolling(retryId);
              return;
            }
            retryButton.disabled = false;
            showAlert('error', data?.error || data?.message || 'Error');
          } catch (error) {
            retryButton.disabled = false;
            showAlert('error', error?.message || 'Network error');
          }
          return;
        }
        const button = target instanceof Element ? target.closest('.vc-delete-btn') : null;
        if (!button) return;
        if (!confirm(button.dataset.confirm || '')) return;
        const voiceId = String(button.dataset.voiceId || '').trim();
        if (!voiceId) return;
        try {
          const response = await fetch(`${DELETE_URL_BASE}${voiceId}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
          });
          const data = await response.json().catch(() => ({}));
          if (response.ok && data?.success) {
            stopPolling(voiceId);
            const card = voiceGrid.querySelector(`.vc-card[data-voice-id="${escapeSelector(voiceId)}"]`);
            if (card) {
              const occupying = String(card.dataset.status || '') !== 'failed';
              card.remove();
              if (occupying) {
                currentVoiceCount = Math.max(0, currentVoiceCount - 1);
                updateCountUI();
              }
              updateEmptyStateUI();
            }
            return;
          }
          showAlert('error', data?.error || 'Failed');
        } catch (error) {
          showAlert('error', error?.message || 'Failed');
        }
      });

      voiceGrid?.querySelectorAll('.vc-card[data-status="pending"], .vc-card[data-status="validating"], .vc-card[data-status="processing"]').forEach((card) => {
        const voiceId = String(card.getAttribute('data-voice-id') || '').trim();
        if (voiceId) startPolling(voiceId);
      });

      setInterval(() => {
        voiceGrid?.querySelectorAll('.vc-card[data-status="pending"], .vc-card[data-status="validating"], .vc-card[data-status="processing"]').forEach((card) => {
          const status = String(card.getAttribute('data-status') || '').toLowerCase();
          const startedTs = Number(card.getAttribute('data-started-ts') || card.getAttribute('data-created-ts') || Math.floor(Date.now() / 1000));
          const elapsedSeconds = Math.max(0, Math.floor(Date.now() / 1000) - startedTs);
          const delayed = elapsedSeconds >= 90;
          updateProgressUI(card, status, elapsedSeconds, delayed);
        });
      }, 1000);

      updateCountUI();
      updateEmptyStateUI();

      document.querySelectorAll('.vc-card').forEach((card) => {
        applyStatusToCard(card, {
          id: card.getAttribute('data-voice-id'),
          status: card.getAttribute('data-status'),
          created_at_ts: Number(card.getAttribute('data-created-ts') || 0),
          started_at_ts: Number(card.getAttribute('data-started-ts') || 0),
        });
      });
    })();
  </script>
  @endif
</body>
</html>
