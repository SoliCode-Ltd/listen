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
      gap: 2rem;
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
      width: min(100%, 420px);
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
      font-size: .88rem;
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
      font-size: .88rem;
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
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--surface) 50%, transparent);
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
      font-size: .88rem;
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
      border-radius: 1.1rem;
      border: 1px solid color-mix(in oklab, var(--line) 75%, var(--a) 25%);
      background: color-mix(in oklab, var(--surface) 82%, transparent);
    }
    .control-pair > div {
      padding: .95rem;
      border-radius: 1rem;
      border: 1px solid color-mix(in oklab, var(--line) 74%, var(--b) 26%);
      background: color-mix(in oklab, var(--surface) 80%, transparent);
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
      font-size: .88rem;
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
      .dashboard-menu {
        position: static;
      }
      .dashboard-menu-list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .plan-card {
        position: static;
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
      .dashboard-menu-list {
        grid-template-columns: 1fr;
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
        <nav class="dashboard-menu-list" aria-label="{{ __('ui.dashboard.menu_panel') }}">
          <a class="dashboard-menu-link active" href="#commandCenterSection" data-dashboard-nav>
            <span>{{ __('ui.dashboard.command_center') }}</span>
            <span></span>
          </a>
          <a class="dashboard-menu-link" href="#generateVoiceSection" data-dashboard-nav>
            <span>{{ __('ui.dashboard.generate_new_voice') }}</span>
            <span></span>
          </a>
          <a class="dashboard-menu-link" href="#planDefaultsSection" data-dashboard-nav>
            <span>{{ __('ui.dashboard.plan_and_defaults') }}</span>
            <span></span>
          </a>
          <a class="dashboard-menu-link" href="#recentVoicesSection" data-dashboard-nav>
            <span>{{ __('ui.dashboard.recent_voices') }}</span>
            <span></span>
          </a>
        </nav>
      </aside>

      <div class="dashboard-content">
    <section id="commandCenterSection" class="dashboard-panel active" data-dashboard-section>
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
    </section>

    <section id="generateVoiceSection" class="dashboard-panel" data-dashboard-section>
      <article class="page-card reveal queue-card" id="queueCardSection">
        <h1><span class="grad-text">{{ __('ui.dashboard.generate_new_voice') }}</span></h1>
        @if(session('ok'))<div class="note">{{ session('ok') }}</div>@endif
        @if(session('error'))<div class="error">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif

        <form id="voiceForm" method="POST" action="{{ route('dashboard.queue') }}" class="form-grid">
          @csrf
          <div class="editor-panel">
            <label>{{ __('ui.dashboard.title_optional') }}</label>
            <input name="title" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'auto' }}" value="{{ old('title') }}" placeholder="{{ __('ui.dashboard.notification_summary') }}" />
          </div>

          <div class="editor-panel">
            <label>{{ __('ui.dashboard.text_content') }}</label>
            <textarea name="content" id="contentTextarea" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'auto' }}" rows="8" required placeholder="{{ __('ui.dashboard.paste_text') }}">{{ old('content') }}</textarea>
            <div class="char-counter" id="charCounter">
              <span id="charCount">0</span> / <span id="charLimit">{{ number_format($maxCharsPerRequest) }}</span> {{ __('ui.dashboard.characters') }}
            </div>
          </div>

          <div class="two-col control-pair">
            <div>
              <label>{{ __('ui.dashboard.voice_language') }}</label>
              <select name="target_language" id="target_language">
                <option value="ar" {{ old('target_language', 'ar') === 'ar' ? 'selected' : '' }}>{{ __('ui.dashboard.arabic') }}</option>
                <option value="en" {{ old('target_language', 'ar') === 'en' ? 'selected' : '' }}>{{ __('ui.dashboard.english') }}</option>
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
                  <option value="neutral">{{ __('ui.dashboard.emotion_neutral') }}</option>
                  <option value="educational">{{ __('ui.dashboard.style_educational') }}</option>
                  <option value="commercial">{{ __('ui.dashboard.style_commercial') }}</option>
                  <option value="promo">{{ __('ui.dashboard.style_promo') }}</option>
                  <option value="podcast">{{ __('ui.dashboard.style_podcast') }}</option>
                  <option value="assistant">{{ __('ui.dashboard.style_assistant') }}</option>
                  <option value="narrative">{{ __('ui.dashboard.style_narrative') }}</option>
                  <option value="comedic">{{ __('ui.dashboard.style_comedic') }}</option>
                  <option value="corporate">{{ __('ui.dashboard.style_corporate') }}</option>
                  <option value="conversational">{{ __('ui.dashboard.style_conversational') }}</option>
                  <option value="inspirational">{{ __('ui.dashboard.style_inspirational') }}</option>
                  <option value="cinematic">{{ __('ui.dashboard.style_cinematic') }}</option>
                  <option value="documentary">{{ __('ui.dashboard.style_documentary') }}</option>
                  <option value="storytelling">{{ __('ui.dashboard.emotion_storytelling') }}</option>
                  <option value="ad_style">{{ __('ui.dashboard.emotion_ad_style') }}</option>
                  <option value="news">{{ __('ui.dashboard.emotion_news') }}</option>
                  <option value="sports_commentator">{{ __('ui.dashboard.style_sports_commentator') }}</option>
                  <option value="meditation">{{ __('ui.dashboard.style_meditation') }}</option>
                  <option value="audiobook">{{ __('ui.dashboard.style_audiobook') }}</option>
                  <option value="children_story">{{ __('ui.dashboard.style_children_story') }}</option>
                  <option value="radio_host">{{ __('ui.dashboard.style_radio_host') }}</option>
                </select>
              </div>

              <div class="perf-section">
                <div class="perf-section-label">💫 {{ __('ui.dashboard.emotion_tone') }}</div>
                <select name="emotion" id="emotion" required>
                  <option value="">-- {{ __('ui.dashboard.choose_emotion') }} --</option>
                  <option value="neutral">{{ __('ui.dashboard.emotion_neutral') }}</option>
                  <option value="warm">{{ __('ui.dashboard.emotion_warm') }}</option>
                  <option value="sad">{{ __('ui.dashboard.emotion_sad') }}</option>
                  <option value="excited">{{ __('ui.dashboard.emotion_excited') }}</option>
                  <option value="formal">{{ __('ui.dashboard.emotion_formal') }}</option>
                  <option value="calm">{{ __('ui.dashboard.emotion_calm') }}</option>
                  <option value="angry">{{ __('ui.dashboard.emotion_angry') }}</option>
                  <option value="hopeful">{{ __('ui.dashboard.emotion_hopeful') }}</option>
                  <option value="serious">{{ __('ui.dashboard.emotion_serious') }}</option>
                  <option value="playful">{{ __('ui.dashboard.emotion_playful') }}</option>
                  <option value="mysterious">{{ __('ui.dashboard.emotion_mysterious') }}</option>
                  <option value="confident">{{ __('ui.dashboard.emotion_confident') }}</option>
                </select>
              </div>
            </div>

            @if($customPromptEnabled ?? false)
            <div class="perf-section" style="margin-top:.6rem;">
              <div class="perf-section-label">✨ {{ __('ui.dashboard.style_prompt') }}</div>
              <textarea name="style_prompt" id="style_prompt" rows="2" maxlength="2000" placeholder="{{ __('ui.dashboard.style_prompt_placeholder') }}" style="resize:vertical; min-height:60px; line-height: 1.5;"></textarea>
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
                  <span class="slider-value" id="speedVal">1.0</span>x
                </div>
                <div class="slider-wrap">
                  <input type="range" name="speed" id="speed" min="0.5" max="2.0" step="0.1" value="1.0" oninput="document.getElementById('speedVal').textContent=this.value">
                </div>
              </div>
              <div class="perf-section">
                <div class="perf-section-label">
                  🎵 {{ __('ui.dashboard.pitch') }}
                  <span class="slider-value" id="pitchVal">0</span>
                </div>
                <div class="slider-wrap">
                  <input type="range" name="pitch" id="pitch" min="-10" max="10" step="1" value="0" oninput="document.getElementById('pitchVal').textContent=this.value">
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
                  <input type="checkbox" id="dialogueToggle">
                  <span class="toggle-slider"></span>
                </div>
              </label>
              <input type="hidden" name="is_dialogue" id="dialogueSelect" value="0">
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
                <input type="checkbox" id="translateToggle" {{ old('translate') ? 'checked' : '' }}>
                <span class="toggle-slider"></span>
              </div>
            </label>
            <input type="hidden" name="translate" id="translateSelect" value="{{ old('translate') ? '1' : '0' }}">
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
                <th>{{ __('ui.dashboard.table_id') }}</th><th>{{ __('ui.dashboard.table_title') }}</th><th>{{ __('ui.dashboard.table_status') }}</th><th>{{ __('ui.dashboard.table_flow') }}</th><th>{{ __('ui.dashboard.table_speaker') }}</th><th>{{ __('ui.dashboard.table_translate') }}</th><th>{{ __('ui.dashboard.table_audio') }}</th><th>{{ __('ui.dashboard.table_created') }}</th><th>{{ __('ui.dashboard.table_actions') }}</th>
              </tr>
            </thead>
            <tbody id="jobsTableBody">
              @forelse($jobs as $job)
                <tr data-job-id="{{ $job->id }}" data-job-data="{{ json_encode([
                  'id' => $job->id,
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
                ]) }}">
                  <td>#{{ $job->id }}</td>
                  <td>{{ $job->textItem?->title ?: '-' }}</td>
                  <td><span class="status {{ $job->status }}">{{ $statusLabelByKey($job->status) }}</span></td>
                  <td>{{ $languageLabelByCode($job->source_language) }} -> {{ $languageLabelByCode($job->target_language) }}</td>
	                  <td>{{ $speakerDisplayLabel($job->voice_name) }}</td>
	                  <td>{{ $job->translate ? __('ui.dashboard.yes') : __('ui.dashboard.no') }}</td>
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
                    <button
                      type="button"
                      class="pagination-btn job-delete-btn"
                      data-action="delete-job"
                      data-job-id="{{ $job->id }}"
                    >
	                      {{ __('ui.dashboard.delete') }}
	                    </button>
                  </td>
                </tr>
              @empty
	                <tr id="jobsEmptyRow"><td colspan="9" style="color: var(--muted);">{{ __('ui.dashboard.no_jobs_yet') }}</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        <div class="pagination" id="jobsPagination"></div>
      </div>
    </section>
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
    ];
  @endphp
  <script>
    const voiceCatalog = @json($voiceCatalog);
    const oldVoice = @json(old('voice_key'));
    const queueUrl = '{{ route('dashboard.queue') }}';
    const deleteJobBaseUrl = '{{ route('dashboard.jobs.destroy', ['job' => '__JOB_ID__']) }}'.replace('/__JOB_ID__', '');
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
    const generatedAudioWrap = document.getElementById('generatedAudioWrap');
    const generatedOutputPlaceholder = document.getElementById('generatedOutputPlaceholder');
    const generatedPreviewAudio = document.getElementById('generatedPreviewAudio');
    const generatedAudioMeta = document.getElementById('generatedAudioMeta');
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

    const dialectsByLanguage = {
      ar: [
        { value: '', label: '{{ __("ui.dashboard.dialect_auto") }}' },
        { value: 'ar-XA', label: '{{ __("ui.dialects.msa") }}' },
        { value: 'ar-SA', label: '{{ __("ui.dialects.saudi") }}' },
        { value: 'ar-AE', label: '{{ __("ui.dialects.emirati") }}' },
        { value: 'ar-EG', label: '{{ __("ui.dialects.egyptian") }}' },
        { value: 'ar-LB', label: '{{ __("ui.dialects.lebanese") }}' },
        { value: 'ar-SY', label: '{{ __("ui.dialects.syrian") }}' },
        { value: 'ar-JO', label: '{{ __("ui.dialects.jordanian") }}' },
        { value: 'ar-IQ', label: '{{ __("ui.dialects.iraqi") }}' },
        { value: 'ar-MA', label: '{{ __("ui.dialects.moroccan") }}' },
        { value: 'ar-KW', label: '{{ __("ui.dialects.kuwaiti") }}' },
        { value: 'ar-TN', label: '{{ __("ui.dialects.tunisian") }}' },
        { value: 'ar-OM', label: '{{ __("ui.dialects.omani") }}' },
      ],
      en: [
        { value: '', label: '{{ __("ui.dashboard.dialect_auto") }}' },
        { value: 'en-US', label: '{{ __("ui.dialects.american") }}' },
        { value: 'en-GB', label: '{{ __("ui.dialects.british") }}' },
        { value: 'en-AU', label: '{{ __("ui.dialects.australian") }}' },
      ],
    };

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
    }

    function renderVoiceOptions() {
      const lang = normalizeLanguage(targetLanguage?.value || 'ar');
      const raw = Array.isArray(voiceCatalog?.[lang]) ? voiceCatalog[lang] : [];
      const voices = sortVoices(raw);
      const remembered = String(preferredVoiceKeyByLanguage[lang] || '').trim();
      const current = String(remembered || '').trim();
      if (!voiceSelect) return;
      voiceSelect.innerHTML = '';
      if (!voices.length) {
        voiceSelect.innerHTML = '<option value="">' + i18n.no_speakers + '</option>';
        preferredVoiceKeyByLanguage[lang] = '';
        return;
      }
      for (const voice of voices) {
        const option = document.createElement('option');
        option.value = voice.key;
        option.textContent = voiceDisplayLabel(voice);
        voiceSelect.appendChild(option);
      }
      if (voices.some((v) => v.key === current)) voiceSelect.value = current;
      else voiceSelect.value = voices[0].key;
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
        return true;
      }
      const sourceLanguage = detectSourceLanguage(text);
      const currentLanguage = normalizeLanguage(targetLanguage.value);
      if (sourceLanguage === currentLanguage) return false;
      rememberCurrentVoiceSelection(currentLanguage);
      targetLanguage.value = sourceLanguage;
      renderVoiceOptions();
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

    function renderJobRow(job) {
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
      const translateLabel = job?.translate ? i18n.yes : i18n.no;
      const created = escapeHtml(job?.created_human || i18n.just_now);
      const errorMessage = escapeHtml(job?.error_message || i18n.generation_failed);
      const audioUrl = job?.audio_url ? escapeHtml(job.audio_url) : '';

      let audioCell = '<small style="color: var(--muted);">' + i18n.processing_ellipsis + '</small>';
      if (audioUrl) audioCell = '<audio controls preload="none" src="' + audioUrl + '" data-download-language="' + escapeHtml(targetLanguageCode) + '" data-download-text="' + textExcerpt + '" data-download-speaker="' + voiceLabel + '" data-download-quality="' + voiceTier + '"></audio>';
      else if (statusClass === 'failed') audioCell = '<small style="color: var(--danger);">' + errorMessage + '</small>';

      return '<tr data-job-id="' + Number(job?.id || 0) + '" data-job-data="' + escapeHtml(JSON.stringify(job)) + '">' +
        '<td>#' + Number(job?.id || 0) + '</td>' +
        '<td>' + title + '</td>' +
        '<td><span class="status ' + statusClass + '">' + statusText + '</span></td>' +
        '<td>' + sourceLanguage + ' -> ' + targetLanguageValue + '</td>' +
        '<td>' + voiceLabel + '</td>' +
        '<td>' + translateLabel + '</td>' +
        '<td>' + audioCell + '</td>' +
        '<td>' + created + '</td>' +
        '<td><button type="button" class="pagination-btn job-delete-btn" data-action="delete-job" data-job-id="' + Number(job?.id || 0) + '">' + i18n.delete + '</button></td>' +
      '</tr>';
    }

    function loadJobsFromTable() {
      const rows = jobsTableBody.querySelectorAll('tr[data-job-data]');
      allJobs = [];
      rows.forEach(function(row) {
        try {
          const data = JSON.parse(row.getAttribute('data-job-data'));
          if (data && data.id) allJobs.push(data);
        } catch (e) {}
      });
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
        jobsTableBody.innerHTML = '<tr id="jobsEmptyRow"><td colspan="9" style="color: var(--muted);">' + i18n.no_jobs_yet + '</td></tr>';
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
      if (!job || !job.id) return;
      const emptyRow = document.getElementById('jobsEmptyRow');
      if (emptyRow) emptyRow.remove();

      const existingIndex = allJobs.findIndex(function(j) { return j.id === job.id; });
      let isNew = false;
      if (existingIndex !== -1) {
        allJobs[existingIndex] = job;
      } else {
        allJobs.unshift(job);
        isNew = true;
      }

      jobsCurrentPage = 1;
      renderJobsPage();

      if (isNew) {
        setTimeout(function() {
          const firstRow = jobsTableBody.querySelector('tr[data-job-id="' + job.id + '"]');
          if (firstRow) {
            firstRow.classList.add('new-job-row');
            firstRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
        }, 10);
      }
    }

    async function deleteJob(jobId, triggerBtn) {
      const parsedJobId = Number(jobId || 0);
      if (!parsedJobId) return;

      if (!window.confirm(i18n.confirm_delete)) {
        return;
      }

      if (triggerBtn instanceof HTMLButtonElement) {
        triggerBtn.disabled = true;
      }

      let data = null;
      try {
        const response = await fetch(`${deleteJobBaseUrl}/${parsedJobId}`, {
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
        allJobs = allJobs.filter((job) => Number(job?.id || 0) !== deletedId);
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
      const deleteBtn = e.target.closest('[data-action="delete-job"]');
      if (!deleteBtn || deleteBtn.disabled) return;
      deleteJob(deleteBtn.getAttribute('data-job-id'), deleteBtn);
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
      updateCharCounter(); // initial count for old('content')
    }

    if (dashboardNavLinks.length) {
      const validSectionIds = dashboardSections.map(function(section) { return section.id; });
      const hashSectionId = String(window.location.hash || '').replace(/^#/, '');
      const savedSectionId = String(window.localStorage?.getItem('dashboard_active_section') || '');
      const initialSectionId = validSectionIds.includes(hashSectionId)
        ? hashSectionId
        : (validSectionIds.includes(savedSectionId) ? savedSectionId : (validSectionIds[0] || 'overviewSection'));

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
          }
        });
      });
    }

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
</body>
</html>
