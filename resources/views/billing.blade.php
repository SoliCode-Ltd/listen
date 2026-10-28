@include('components.page-head', ['pageTitle' => __('ui.seo.billing_title'), 'page' => 'billing'])
  <script src="https://js.stripe.com/v3/"></script>
  <style>
    .main-content {
      padding: 2rem 0 3.5rem;
    }
    .page-header {
      margin-bottom: 1.75rem;
    }
    .page-header h1 {
      margin: 0 0 .4rem;
      font-size: 1.75rem;
      font-weight: 700;
    }
    .page-header p {
      margin: 0;
      color: var(--muted);
      font-size: 1rem;
    }

    /* ── Status bar ── */
    .status-grid {
      display: grid;
      grid-template-columns: 1fr 1fr 340px;
      gap: .65rem 1.25rem;
    }
    .status-item {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: .75rem .9rem;
      animation: fadeUp .2s ease both;
    }
    .status-grid .status-item:nth-child(2) { animation-delay: .04s; }
    .status-grid .status-item:nth-child(3) { animation-delay: .08s; }
    .status-item small {
      display: block;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .05em;
      font-size: .75rem;
      margin-bottom: .25rem;
    }
    .status-item b {
      display: block;
      font-size: 1rem;
    }

    /* ── Grid layout ── */
    .grid-layout {
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 1.25rem;
      align-items: start;
    }

    /* ── Cards ── */
    .card {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      animation: fadeUp .22s ease both;
    }
    .grid-layout .card:nth-child(2) { animation-delay: .07s; }
    .card-header {
      margin-bottom: 1.15rem;
      padding-bottom: .75rem;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 50%, transparent);
    }
    .card-header h2 {
      margin: 0 0 .2rem;
      font-size: 1.15rem;
      font-weight: 700;
    }
    .card-header p {
      margin: 0;
      color: var(--muted);
      font-size: .95rem;
    }

    /* ── Form ── */
    .form-grid {
      display: grid;
      gap: .9rem;
    }
    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .75rem;
    }
    .form-group label {
      display: block;
      margin-bottom: .4rem;
      font-size: .95rem;
      font-weight: 600;
      color: color-mix(in oklab, var(--text) 85%, var(--muted));
    }
    select {
      width: 100%;
      padding: .75rem 2.25rem .75rem .75rem;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--text);
      font-size: .95rem;
      cursor: pointer;
      transition: border-color .15s ease, box-shadow .15s ease;
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23A1A1AA' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 14 18 9'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right .75rem center;
      background-size: 1rem;
    }
    html[dir="rtl"] select {
      padding: .75rem .75rem .75rem 2.25rem;
      background-position: left .75rem center;
    }
    select:focus {
      outline: none;
      border-color: var(--a);
      box-shadow: 0 0 0 3px color-mix(in oklab, var(--a) 12%, transparent);
    }

    /* ── Payment method cards ── */
    .payment-methods {
      display: grid;
      gap: .6rem;
      margin-top: .15rem;
    }
    .payment-method-grid {
      display: flex;
      gap: .5rem;
    }
    .payment-method-card {
      position: relative;
      flex: 1;
      border: 2px solid var(--line);
      border-radius: var(--radius-lg);
      background: var(--surface);
      padding: .85rem .9rem;
      cursor: pointer;
      transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      gap: .55rem;
    }
    .payment-method-card:hover {
      border-color: color-mix(in oklab, var(--a) 45%, var(--line));
      box-shadow: 0 4px 16px color-mix(in oklab, var(--a) 8%, transparent);
    }
    .payment-method-card input {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }
    .payment-method-card.active {
      border-color: var(--a);
      background: color-mix(in oklab, var(--a) 4%, var(--surface));
      box-shadow: 0 0 0 3px color-mix(in oklab, var(--a) 12%, transparent);
    }
    .payment-method-card.unavailable {
      opacity: .35;
      cursor: not-allowed;
      box-shadow: none;
    }

    .payment-method-card .method-title {
      display: flex;
      align-items: center;
      gap: .5rem;
      font-weight: 700;
    }
    .method-brand {
      display: flex;
      align-items: center;
      gap: .6rem;
      width: 100%;
    }
    .method-logo {
      width: 2.5rem;
      height: 2.5rem;
      border-radius: .5rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      padding: .3rem;
    }
    .method-logo img {
      max-width: 100%;
      max-height: 100%;
      display: block;
      object-fit: contain;
      object-position: center;
    }
    .method-logo-fallback {
      font-size: .65rem;
      font-weight: 800;
      letter-spacing: .04em;
      text-transform: uppercase;
      color: #6366f1;
    }
    .method-copy {
      display: flex;
      flex-direction: column;
      min-width: 0;
    }
    .method-name {
      font-size: 1rem;
      font-weight: 700;
      line-height: 1.2;
    }

    /* ── Checkout panels ── */
    .checkout-panel {
      margin-top: .5rem;
      overflow: hidden;
    }
    .manual-panel {
      display: none;
      padding: 1rem;
      gap: 1rem;
    }
    .manual-panel.show {
      display: grid;
    }
    .manual-main-header h3,
    .manual-proof-card h3,
    .manual-review-card h3 {
      margin: 0 0 .4rem;
      font-size: 1.05rem;
      font-weight: 700;
    }
    .manual-main-header p {
      margin-bottom: 2rem !important;
      color: var(--muted);
      line-height: 1.6;
      font-size: .95rem;
    }
    .manual-main-grid {
      display: none;
    }
    .manual-proof-card,
    .manual-review-card {
      padding: 1rem;
    }
    .manual-proof-card h3,
    .manual-review-card h3 {
      margin: 0 0 .6rem;
      font-size: 1rem;
    }
    .manual-proof-card .proof-upload-area {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: .6rem;
      width: 100%;
      padding: 1.5rem 1rem;
      border-radius: var(--radius);
      border: 2px dashed color-mix(in oklab, var(--a) 30%, var(--line));
      background: color-mix(in oklab, var(--surface2) 50%, transparent);
      color: var(--text);
      cursor: pointer;
      transition: border-color .15s ease, background .15s ease;
      text-align: center;
    }
    .manual-proof-card .proof-upload-area:hover {
      border-color: var(--a);
      background: color-mix(in oklab, var(--a) 4%, var(--surface));
    }
    .manual-proof-card .proof-upload-area.has-file {
      border-color: var(--a);
      border-style: solid;
    }
    .proof-upload-icon {
      width: 2.5rem;
      height: 2.5rem;
      color: var(--muted);
    }
    .proof-upload-area.has-file .proof-upload-icon {
      color: var(--a);
    }
    .proof-upload-text {
      font-size: .95rem;
      color: var(--muted);
    }
    .proof-upload-text strong {
      color: var(--a);
      font-weight: 600;
    }
    .proof-upload-hint {
      font-size: .95rem;
      color: var(--muted);
      opacity: .7;
    }
    .proof-file-name {
      font-size: .92rem;
      color: var(--text);
      font-weight: 600;
    }
    .manual-proof-card input[type="file"] {
      display: none;
    }
    .manual-proof-card p,
    .manual-review-card p {
      margin: 0;
      color: var(--muted);
      line-height: 1.6;
      font-size: .95rem;
    }
    .manual-meta-footer {
      margin-top: .75rem;
    }
    .manual-pay-actions {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: .75rem;
    }
    .manual-qr-wrap {
      border-radius: var(--radius);
      padding: 0;
      display: none;
      flex-direction: column;
      align-items: center;
      gap: .75rem;
      background: transparent;
      width: 100%;
      max-width: 320px;
      box-sizing: border-box;
    }
    .manual-qr-wrap.show {
      display: flex;
    }
    .manual-qr-wrap img {
      width: 100%;
      aspect-ratio: 1;
      object-fit: contain;
      border-radius: .6rem;
    }
    .manual-pay-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 100%;
      max-width: 320px;
      box-sizing: border-box;
      padding: .7rem 1rem;
      border-radius: var(--radius);
      border: none;
      background: linear-gradient(135deg, var(--a), var(--b));
      color: white;
      text-decoration: none;
      font-weight: 600;
      font-size: .92rem;
      transition: opacity .15s ease, transform .1s ease;
    }
    .manual-pay-link:hover {
      opacity: .9;
    }

    /* ── Stripe container ── */
    .stripe-container {
      padding: 1rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--surface2) 40%, var(--surface));
    }
    .stripe-container.hidden {
      display: none;
    }
    .stripe-label {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      font-size: .95rem;
      font-weight: 700;
      color: var(--muted);
      margin-bottom: .6rem;
    }
    .stripe-label svg {
      width: 1rem;
      height: 1rem;
      fill: currentColor;
    }
    .stripe-label .card-icon {
      width: 1.05rem;
      height: 1.05rem;
      display: inline-block;
      flex-shrink: 0;
    }
    .stripe-label .card-icon svg {
      width: 100%;
      height: 100%;
      display: block;
      fill: none;
      stroke: none;
    }
    /* The saved card: the check beside two lines, what is used and how to change it. */
    .saved-card-notice {
      display: grid;
      grid-template-columns: auto 1fr;
      align-items: center;
      gap: .1rem .65rem;
      padding: .7rem .9rem;
      margin-bottom: .65rem;
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--ok) 8%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--ok) 28%, transparent);
      font-size: .95rem;
      line-height: 1.5;
      color: var(--fg);
    }
    .saved-card-notice .saved-card-icon {
      grid-row: 1 / span 2;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .saved-card-notice strong {
      color: var(--fg);
      font-weight: 600;
      white-space: nowrap;
    }
    .saved-card-notice .saved-card-hint {
      grid-column: 2;
      font-size: .82rem;
      color: var(--muted);
    }
    .card-error {
      margin-top: .4rem;
      font-size: .95rem;
      color: var(--danger);
      min-height: 1.1em;
    }
    .save-card-checkbox {
      display: flex;
      align-items: center;
      gap: .5rem;
      padding: .55rem .7rem;
      border-radius: var(--radius);
      cursor: pointer;
      font-size: .95rem;
      color: var(--fg);
      transition: border-color .15s ease;
    }
    .save-card-checkbox:hover {
      border-color: var(--a);
    }
    .save-card-checkbox input[type="checkbox"] {
      appearance: none;
      -webkit-appearance: none;
      width: 1.15rem;
      height: 1.15rem;
      border: 1.5px solid var(--line);
      border-radius: 4px;
      background: var(--surface);
      cursor: pointer;
      position: relative;
      transition: all .15s ease;
      flex-shrink: 0;
      margin: 0;
    }
    .save-card-checkbox input[type="checkbox"]:hover {
      border-color: var(--a);
    }
    .save-card-checkbox input[type="checkbox"]:checked {
      background: linear-gradient(135deg, var(--a), var(--b));
      border-color: transparent;
    }
    .save-card-checkbox input[type="checkbox"]:checked::after {
      content: "";
      position: absolute;
      top: 45%;
      left: 50%;
      transform: translate(-50%, -50%) rotate(45deg);
      width: 4px;
      height: 8px;
      border-right: 2px solid white;
      border-bottom: 2px solid white;
    }
    .consent-box {
      margin-top: .6rem;
      padding: .7rem .85rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--surface2) 60%, var(--surface));
      font-size: .95rem;
      color: var(--muted);
      line-height: 1.6;
    }
    .consent-row {
      margin-top: .4rem;
      color: var(--text);
      font-size: .95rem;
    }

    /* ── Action buttons ── */
    .actions {
      margin-top: 1rem;
      display: flex;
      gap: .5rem;
      flex-wrap: wrap;
    }
    .actions .btn {
      flex: 1;
      justify-content: center;
    }
    .btn.is-loading {
      opacity: .75;
      pointer-events: none;
    }
    .btn[hidden],
    .message[hidden],
    #trialBtn[hidden],
    #trialNote[hidden],
    #checkoutChoice[hidden] {
      display: none !important;
    }
    .checkout-choice .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip: rect(0 0 0 0);
      white-space: nowrap;
    }
    .checkout-choice {
      display: grid;
      gap: .6rem;
      margin: 1rem 0 0;
      padding: 0;
      border: 0;
    }
    .choice-card {
      display: flex;
      align-items: flex-start;
      gap: .75rem;
      padding: .85rem 1rem;
      border: 2px solid var(--line);
      border-radius: var(--radius-lg);
      background: var(--surface);
      cursor: pointer;
      transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .choice-card:hover {
      border-color: color-mix(in oklab, var(--a) 45%, var(--line));
    }
    .choice-card:has(input:checked) {
      border-color: var(--a);
      background: color-mix(in oklab, var(--a) 5%, var(--surface));
      box-shadow: 0 0 0 3px color-mix(in oklab, var(--a) 12%, transparent);
    }
    .choice-card input {
      flex: none;
      width: 18px;
      height: 18px;
      margin: .15rem 0 0;
      accent-color: var(--a);
    }
    .choice-body {
      display: grid;
      flex: 1;
      gap: .25rem;
      min-width: 0;
    }
    .choice-title {
      font-size: 1rem;
      font-weight: 700;
      line-height: 1.4;
      color: var(--text);
    }
    .choice-sub {
      color: color-mix(in oklab, var(--text) 78%, var(--muted));
      font-size: .92rem;
      line-height: 1.65;
    }
    .trial-cap-note {
      margin: .85rem 0 0;
      padding: .7rem .85rem;
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--a) 5%, var(--surface));
      color: color-mix(in oklab, var(--text) 72%, var(--muted));
      font-size: .85rem;
      line-height: 1.65;
    }

    /* ── Messages ── */
    .message {
      margin-top: .75rem;
      padding: .75rem .9rem;
      border-radius: var(--radius);
      display: none;
      font-size: .92rem;
    }
    .message.success {
      display: block;
      border: 1px solid color-mix(in oklab, var(--ok) 40%, var(--line));
      background: color-mix(in oklab, var(--ok) 8%, var(--surface));
      color: color-mix(in oklab, var(--ok) 85%, var(--text));
    }
    .message.error {
      display: block;
      border: 1px solid color-mix(in oklab, var(--danger) 40%, var(--line));
      background: color-mix(in oklab, var(--danger) 8%, var(--surface));
      color: color-mix(in oklab, var(--danger) 85%, var(--text));
    }

    /* ── After a declined card: what else the member can do ── */
    .decline-help {
      margin-top: .6rem;
      padding: .8rem .9rem .9rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--a) 4%, var(--surface));
    }
    .decline-help[hidden] {
      display: none;
    }
    .decline-help-title {
      margin: 0 0 .6rem;
      font-size: .85rem;
      font-weight: 600;
      color: color-mix(in oklab, var(--text) 80%, var(--muted));
    }
    .decline-help-actions {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem;
    }
    .decline-help-actions .btn {
      flex: 1 1 auto;
      min-height: 2.6rem;
      padding: .55rem .9rem;
      font-size: .9rem;
    }
    .decline-help-actions .btn img {
      height: 1.15rem;
      width: auto;
      margin-inline-end: .45rem;
    }

    /* ── Quote sidebar ── */
    .quote-section {
      display: flex;
      flex-direction: column;
      gap: .75rem;
      position: sticky;
      top: 1.5rem;
    }
    .quote-highlight {
      text-align: center;
      padding: 1.25rem 1rem;
      border: 1px solid color-mix(in oklab, var(--a) 30%, var(--line));
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--a) 4%, var(--surface));
    }
    .quote-highlight small {
      display: block;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .06em;
      font-size: .78rem;
      margin-bottom: .3rem;
    }
    .quote-highlight .amount {
      font-size: 2rem;
      font-weight: 800;
      font-family: var(--site-font, sans-serif);
      margin: 0;
      background: linear-gradient(135deg, var(--a), var(--b));
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      direction: ltr;
      unicode-bidi: isolate;
      display: inline-block;
    }
    .quote-highlight small:empty {
      display: none;
    }
    .quote-highlight .hint {
      margin: .35rem 0 0;
      color: var(--muted);
      font-size: .95rem;
    }
    .quote-highlight .amount-about {
      margin-top: .1rem;
      font-size: .85rem;
    }
    .quote-details {

      overflow: hidden;
    }
    .quote-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: .65rem .85rem;
      border-bottom: 1px solid color-mix(in oklab, var(--line) 40%, transparent);
      font-size: .95rem;
    }
    .quote-row:last-child {
      border-bottom: none;
    }
    .quote-row dt {
      color: var(--muted);
      font-size: .9rem;
    }
    .quote-row dd {
      font-weight: 600;
      font-size: .92rem;
      font-family: var(--site-font, sans-serif);
    }
    .quote-row.is-hidden {
      display: none;
    }
    /* A paid plan changing: what today's amount is made of. */
    .order-details {
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: .9rem 1rem .8rem;
    }
    .order-details h3 {
      margin: 0 0 .55rem;
      font-size: .95rem;
      font-weight: 700;
    }
    .order-lines {
      margin: 0;
      display: grid;
      gap: .5rem;
    }
    .order-line,
    .order-total {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      gap: 1rem;
      margin: 0;
    }
    .order-line dt {
      color: var(--muted);
      font-size: .9rem;
      line-height: 1.45;
    }
    .order-line dd,
    .order-total dd {
      margin: 0;
      font-variant-numeric: tabular-nums;
      white-space: nowrap;
      unicode-bidi: isolate;
    }
    .order-line dd {
      font-size: .92rem;
      font-weight: 600;
    }
    .order-line.is-credit dd {
      color: color-mix(in oklab, var(--ok) 80%, var(--text));
    }
    .order-total {
      margin-top: .7rem;
      padding-top: .7rem;
      border-top: 1px solid var(--line);
    }
    .order-total dt {
      font-weight: 700;
    }
    .order-total dd {
      font-weight: 800;
      font-size: 1.05rem;
    }
    .order-note {
      margin: .6rem 0 0;
      color: var(--muted);
      font-size: .85rem;
      line-height: 1.55;
    }
    .quick-links {
      margin-top: .35rem;
      display: flex;
      flex-direction: column;
      gap: .4rem;
    }
    .quick-links .btn {
      width: 100%;
      justify-content: center;
    }

    /* ── Animations ── */
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* ── Responsive ── */
    @media (max-width: 980px) {
      .grid-layout {
        grid-template-columns: 1fr;
      }
      .quote-section {
        position: static;
      }
      /* The summary inside the form: what is due, right before paying. The plan,
         cycle and payment method are chosen just above, so they are not repeated. */
      .quote-slot:empty {
        display: none;
      }
      .quote-slot .quote-section {
        padding: 0;
        border: 0;
        background: none;
        box-shadow: none;
        animation: none;
        gap: .6rem;
      }
      .quote-slot .quote-section > .card-header,
      .quote-slot .quote-details {
        display: none;
      }
      .quote-slot .quote-highlight {
        text-align: center;
        padding: .85rem 1rem;
      }
      .quote-slot .quote-highlight small {
        margin: 0 0 .15rem;
        font-size: .82rem;
        letter-spacing: 0;
        text-transform: none;
      }
      .quote-slot .quote-highlight .amount {
        font-size: 1.5rem;
      }
      .quote-slot .quote-highlight .hint {
        margin: .25rem 0 0;
        font-size: .85rem;
        line-height: 1.5;
      }
      .form-row {
        grid-template-columns: 1fr;
      }
      .status-grid {
        grid-template-columns: 1fr 1fr 1fr;
        gap: .65rem;
      }
      .payment-method-grid {
        flex-direction: column;
      }
      .manual-main-grid {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 640px) {
      .main-content {
        padding: 1.25rem 0 2rem;
        overflow-x: hidden;
      }
      .page-header h1 {
        font-size: 1.35rem;
      }
      .card {
        padding: 1rem;
      }
      .grid-layout,
      .checkout-panel,
      .manual-panel,
      .manual-proof-card,
      .manual-pay-actions {
        min-width: 0;
      }
      .manual-qr-wrap,
      .manual-pay-link {
        width: 100%;
        max-width: 100%;
      }
      /* The account at a glance: one quiet box of three lines, not three cards. */
      .status-grid {
        grid-template-columns: 1fr;
        gap: 0;
        border: 1px solid var(--line);
        border-radius: var(--radius);
        background: var(--surface);
      }
      .status-item {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 1rem;
        border: 0;
        border-radius: 0;
        background: none;
        padding: .6rem .9rem;
      }
      .status-item + .status-item {
        border-top: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
      }
      .status-item small {
        margin: 0;
        font-size: .82rem;
        letter-spacing: 0;
        text-transform: none;
      }
      .status-item b {
        font-size: .92rem;
        text-align: end;
      }
      .stripe-container {
        padding: .75rem;
      }
      .actions .btn {
        flex: 1 1 100%;
      }
      .quote-highlight {
        padding: .85rem;
      }
      .quote-highlight .amount {
        font-size: 1.5rem;
      }
      .quote-row {
        align-items: flex-start;
        flex-direction: column;
        gap: .15rem;
      }
    }
  </style>
</head>
<body>
  @php
    $planNameBySlug = static function (?string $slug, ?string $fallback = null): string {
      // Dynamic plan name lookup — supports all plans
      $knownSlugs = [
        'free' => __('ui.pricing.plan_name_free'),
        'starter' => __('ui.pricing.plan_name_starter', ['default' => 'Starter']),
        'creator' => __('ui.pricing.plan_name_creator'),
        'pro' => __('ui.pricing.plan_name_pro'),
        'studio_max' => __('ui.pricing.plan_name_studio_max', ['default' => 'Studio Max']),
      ];
      return $knownSlugs[(string) $slug] ?? (string) ($fallback ?: ucfirst((string) $slug) ?: '-');
    };
    $normalizeTierLabel = static function (?string $label): string {
      $raw = trim((string) $label);
      $value = strtolower($raw);
      $display = $value === 'standard' ? __('ui.dashboard.standard') : $raw;
      if ($display !== '' && preg_match('/[A-Za-z0-9+]/', $display) && !preg_match('/[\x{0600}-\x{06FF}]/u', $display)) {
        return "\u{2066}{$display}\u{2069}";
      }
      return $display;
    };
    // The names the pricing page shows (the admin's name for this language first).
    $activePlanName = $activePlan
      ? ($activePlan->getLocalizedName() ?: $planNameBySlug($activePlan->slug, $activePlan->name))
      : __('ui.dashboard.free_plan');
    $plansForUi = collect($plans)->map(function (array $plan) use ($planNameBySlug, $normalizeTierLabel): array {
      $plan['localized_name'] = ($plan['localized_name'] ?? '') ?: $planNameBySlug((string) ($plan['slug'] ?? ''), (string) ($plan['name'] ?? ''));
      $plan['localized_voice_tier'] = $normalizeTierLabel((string) ($plan['voice_tier'] ?? ''));
      return $plan;
    })->values()->all();
    $upgradePlansForUi = collect($plansForUi)
      ->reject(fn (array $plan): bool => (string) ($plan['slug'] ?? '') === (string) ($currentPlanSlug ?? ''))
      ->values()
      ->all();
    $upgradePlanSlugs = collect($upgradePlansForUi)->pluck('slug')->all();
    $showEgyptManualMethods = (bool) ($countryContext['is_egypt'] ?? false);
    $selectedPlanValue = old('plan', $selectedPlan);
    if (!in_array((string) $selectedPlanValue, $upgradePlanSlugs, true)) {
      $selectedPlanValue = (string) ($upgradePlanSlugs[0] ?? '');
    }
    $hasUpgradePlans = !empty($upgradePlansForUi);
    $selectedBillingCycle = old('billing_cycle', (string) request('billing_cycle', 'monthly'));
    $selectedPaymentMethod = old('payment_method', (string) request('payment_method', ''));
    $initialMessageText = session('manual_payment_success')
      ?: $errors->first('manual_payment')
      ?: $errors->first('payment_method');
    $initialMessageType = session('manual_payment_success')
      ? 'success'
      : ($initialMessageText !== '' ? 'error' : '');
    $paymentBrandAssets = is_array($paymentBrandAssets ?? null) ? $paymentBrandAssets : [];
  @endphp
  @include('components.header')

  <main class="container main-content">
    <div class="page-header">
      <h1>{{ __('ui.billing.title') }}</h1>
      <p>{{ __('ui.billing.subtitle') }}</p>
    </div>
    @if(!empty($paymentOverdueNotice ?? null))
      <div class="message error" id="paymentOverdueNotice" role="status" style="margin-top:0;margin-bottom:1.25rem;">
        {{ $paymentOverdueNotice }}
        <a href="{{ route('manage-subscription.page') }}" style="color:inherit;font-weight:600;text-decoration:underline;">{{ __('ui.billing.manage_subscription') }}</a>
      </div>
    @endif

    <div class="status-grid" style="margin-bottom: 1.25rem;">
      <div class="status-item">
        <small>{{ __('ui.billing.current_plan') }}</small>
        <b>{{ $activePlanName }}</b>
      </div>
      <div class="status-item">
        <small>{{ __('ui.billing.voice_quality') }}</small>
        <b>{{ $normalizeTierLabel($activeTierLabel) }}</b>
      </div>
      <div class="status-item">
        <small>{{ __('ui.billing.saved_card') }}</small>
        <b>
          @if($defaultPaymentMethod)
            <bdi dir="ltr">{{ strtoupper((string) ($defaultPaymentMethod->card->brand ?? 'card')) }} •••• {{ $defaultPaymentMethod->card->last4 ?? '' }}</bdi>
          @else
            {{ __('ui.billing.not_saved') }}
          @endif
        </b>
      </div>
    </div>

    <div class="grid-layout">
      <div class="card">
        <div class="card-header">
          <h2>{{ __('ui.billing.upgrade_plan') }}</h2>
          <p>{{ __('ui.billing.upgrade_plan_hint') }}</p>
        </div>

        <form id="billingForm" class="form-grid" method="POST" action="{{ url()->current() }}">
          @csrf
          <input type="hidden" name="plan" id="manualPlanField" value="{{ $selectedPlanValue }}">
          <input type="hidden" name="billing_cycle" id="manualCycleField" value="{{ $selectedBillingCycle }}">
          <input type="hidden" name="payment_method" id="manualMethodField" value="{{ $selectedPaymentMethod }}">
          <div class="form-row">
            <div class="form-group">
              <label for="plan">{{ __('ui.billing.plan') }}</label>
              <select id="plan" {{ $hasUpgradePlans ? '' : 'disabled' }}>
                @if($hasUpgradePlans)
                  @foreach($upgradePlansForUi as $plan)
                    <option value="{{ $plan['slug'] }}" {{ $selectedPlanValue === $plan['slug'] ? 'selected' : '' }}>
                      {{ $plan['localized_name'] }}
                    </option>
                  @endforeach
                @else
                  <option value="">{{ __('ui.billing.no_upgrade_plans_available') }}</option>
                @endif
              </select>
            </div>
            <div class="form-group">
              <label for="cycle">{{ __('ui.billing.billing_cycle') }}</label>
              <select id="cycle" {{ $hasUpgradePlans ? '' : 'disabled' }}>
                <option value="monthly" {{ $selectedBillingCycle === 'monthly' ? 'selected' : '' }}>{{ __('ui.billing.monthly') }}</option>
                <option value="yearly" {{ $selectedBillingCycle === 'yearly' ? 'selected' : '' }}>{{ __('ui.billing.yearly') }}</option>
              </select>
            </div>
          </div>

          <div class="payment-methods">
            <div class="form-group">
              <label>{{ __('ui.billing.payment_method_label') }}</label>
              <div class="payment-method-grid" id="paymentMethodGrid">
                <label class="payment-method-card" data-method-card="stripe">
                  <input type="radio" name="payment_method_choice" value="stripe" @checked($selectedPaymentMethod === '' || $selectedPaymentMethod === 'stripe')>
                  <div class="method-title">
                    <div class="method-brand">
                      <span class="method-logo" aria-hidden="true">
                        @if(!empty($paymentBrandAssets['stripe']))
                          <img src="{{ $paymentBrandAssets['stripe'] }}" alt="{{ __('ui.billing.payment_method_stripe') }}">
                        @else
                          <span class="method-logo-fallback">Stripe</span>
                        @endif
                      </span>
                      <span class="method-copy">
                        <span class="method-name">{{ __('ui.billing.payment_method_stripe') }}</span>
                      </span>
                    </div>
                  </div>
                </label>

                @if($showEgyptManualMethods)
                  <label class="payment-method-card" data-method-card="instapay">
                    <input type="radio" name="payment_method_choice" value="instapay">
                    <div class="method-title">
                      <div class="method-brand">
                        <span class="method-logo" aria-hidden="true">
                          @if(!empty($paymentBrandAssets['instapay']))
                            <img src="{{ $paymentBrandAssets['instapay'] }}" alt="{{ __('ui.billing.payment_method_instapay') }}">
                          @else
                            <span class="method-logo-fallback">InstaPay</span>
                          @endif
                        </span>
                        <span class="method-copy">
                          <span class="method-name">{{ __('ui.billing.payment_method_instapay') }}</span>
                        </span>
                      </div>
                    </div>
                  </label>

                  <label class="payment-method-card" data-method-card="vodafone_cash">
                    <input type="radio" name="payment_method_choice" value="vodafone_cash">
                    <div class="method-title">
                      <div class="method-brand">
                        <span class="method-logo" aria-hidden="true">
                          @if(!empty($paymentBrandAssets['vodafone_cash']))
                            <img src="{{ $paymentBrandAssets['vodafone_cash'] }}" alt="{{ __('ui.billing.payment_method_vodafone_cash') }}">
                          @else
                            <span class="method-logo-fallback">Vodafone</span>
                          @endif
                        </span>
                        <span class="method-copy">
                          <span class="method-name">{{ __('ui.billing.payment_method_vodafone_cash') }}</span>
                        </span>
                      </div>
                    </div>
                  </label>
                @endif
              </div>
            </div>
          </div>

          {{-- Phones: the charge summary moves here, between the choices and paying (quoteSlot script). --}}
          <div class="quote-slot" id="quoteSlot"></div>

          <div class="checkout-panel">
            <div class="manual-panel" id="manualPanel">
              <div class="manual-proof-card manual-main-card">
                <div class="manual-main-header">
                  <h3>{{ __('ui.billing.manual_page_title') }}</h3>
                  <p id="manualIntroText">{{ __('ui.billing.manual_callout') }}</p>
                </div>
                <div class="manual-main-grid">
                  <div class="manual-main-amount">
                    <small>{{ __('ui.billing.manual_exact_amount') }}</small>
                    <span class="manual-amount" id="manualAmount">-</span>
                    <p id="manualCycleHint">{{ __('ui.billing.select_plan_for_preview') }}</p>
                  </div>
                </div>
                <div class="manual-meta-footer">
                  <div class="manual-pay-actions">
                    <div class="manual-qr-wrap" id="manualQrWrap">
                      <img id="manualQrImage" src="" alt="{{ __('ui.billing.manual_qr_alt') }}">
                    </div>
                    <a class="manual-pay-link" id="manualPaymentLink" href="#" target="_blank" rel="noopener noreferrer" style="display:none;">
                      {{ __('ui.billing.manual_open_payment_link') }}
                    </a>
                  </div>
                </div>
                <h3 style="margin-top: 1.25rem;">{{ __('ui.billing.manual_confirm_title') }}</h3>
                <p style="margin-bottom: .75rem;">{{ __('ui.billing.manual_confirm_subtitle') }}</p>
                <div class="proof-upload-area" id="proofUploadArea" role="button" tabindex="0">
                  <svg class="proof-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                  </svg>
                  <span class="proof-upload-text" id="proofUploadText">{{ __('ui.billing.upload_proof_prompt') }}</span>
                  <span class="proof-upload-hint" id="proofUploadHint">{{ __('ui.billing.upload_proof_hint') }}</span>
                </div>
                <input type="file" id="proofImage" name="proof_image" accept="image/*">
              </div>
            </div>

            <div class="stripe-container" id="stripeContainer">
              <div class="stripe-label">
                <span class="card-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24">
                    <defs>
                      <linearGradient id="billingCardGradient" x1="2" y1="2" x2="22" y2="22" gradientUnits="userSpaceOnUse">
                        <stop offset="0%" stop-color="#3B82F6"></stop>
                        <stop offset="50%" stop-color="#8B5CF6"></stop>
                        <stop offset="100%" stop-color="#F97316"></stop>
                      </linearGradient>
                    </defs>
                    <rect x="2" y="4" width="20" height="16" rx="4" fill="url(#billingCardGradient)"></rect>
                    <rect x="2" y="8" width="20" height="2" fill="rgba(255,255,255,.75)"></rect>
                    <rect x="5" y="13" width="4.8" height="3.4" rx="1" fill="rgba(255,255,255,.92)"></rect>
                    <rect x="11.5" y="14.2" width="7.3" height="1.1" rx=".55" fill="rgba(255,255,255,.88)"></rect>
                  </svg>
                </span>
                {{ __('ui.billing.credit_or_debit_card') }}
              </div>
              @if($defaultPaymentMethod)
                <div class="saved-card-notice">
                  <span class="saved-card-icon">
                    <svg viewBox="0 0 24 24" width="20" height="20">
                      <defs>
                        <linearGradient id="savedCardGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                          <stop offset="0%" stop-color="var(--ok)"/>
                          <stop offset="100%" stop-color="#10B981"/>
                        </linearGradient>
                      </defs>
                      <circle cx="12" cy="12" r="12" fill="url(#savedCardGrad)" opacity="0.2"/>
                      <circle cx="12" cy="12" r="9" fill="url(#savedCardGrad)"/>
                      <path d="M8.5 12L10.5 14.5L16 9" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                    </svg>
                  </span>
                  <span>{{ __('ui.billing.using_saved_card') }} <strong><bdi dir="ltr">{{ strtoupper($defaultPaymentMethod->card->brand) }} •••• {{ $defaultPaymentMethod->card->last4 }}</bdi></strong></span>
                  <span class="saved-card-hint">{{ __('ui.billing.enter_new_card_optional') }}</span>
                </div>
              @endif
              <x-wallet-pay id="billingWallet" />
              <x-card-fields id="card" />
              <div id="card-error" class="card-error" aria-live="polite"></div>
              <label class="save-card-checkbox">
                <input type="checkbox" id="saveCard" checked>
                <span>{{ __('ui.billing.save_card_for_future') }}</span>
              </label>
              @if(!empty($trialOffer['enabled']) && !empty($trialOffer['show_admin_test_cards']))
                <div class="consent-box" id="adminTrialCards">
                  <strong>{{ __('ui.billing.trial_admin_test_title') }}</strong>
                  <p>{{ __('ui.billing.trial_admin_test_body') }}</p>
                  <ul>
                    @foreach(($trialOffer['test_cards'] ?? []) as $testCard)
                      <li><code>{{ $testCard['number'] }}</code> — {{ $testCard['label'] }}</li>
                    @endforeach
                  </ul>
                  @if(empty($trialOffer['stripe_test_mode']))
                    <p>{{ __('ui.billing.trial_admin_live_mode_warning') }}</p>
                  @endif
                </div>
              @endif
              <div class="consent-box">
                @if($stripeReady)
                  <span>{{ __('ui.billing.card_processed_by_stripe') }}</span> · {{ __('ui.billing.auto_renew_notice') }}
                @else
                  <span style="color: var(--danger);">{{ __('ui.billing.stripe_not_configured') }}</span> · {{ __('ui.billing.auto_renew_notice') }}
                @endif
              </div>
            </div>
          </div>

          @if(!empty($trialOffer['enabled']) && !empty($trialOffer['available']))
            <fieldset class="checkout-choice" id="checkoutChoice" hidden>
              <legend class="sr-only">{{ __('ui.billing.checkout_choice_label') }}</legend>
              <label class="choice-card">
                <input type="radio" name="checkout_mode" value="trial" checked>
                <span class="choice-body">
                  <span class="choice-title">{{ __('ui.billing.trial_choice_title', ['days' => (int) ($trialOffer['days'] ?? 7)]) }}</span>
                </span>
              </label>
              <label class="choice-card">
                <input type="radio" name="checkout_mode" value="pay">
                <span class="choice-body">
                  <span class="choice-title">{{ __('ui.billing.pay_choice_title') }}</span>
                  <span class="choice-sub" id="payChoiceSub"></span>
                </span>
              </label>
            </fieldset>
          @endif

          <div class="actions">
            <button class="btn btn-primary" id="subscribeBtn" type="submit" {{ $stripeReady ? '' : 'disabled' }}>
              {{ __('ui.billing.confirm_and_pay') }}
            </button>
            @if(!empty($trialOffer['enabled']) && !empty($trialOffer['available']))
              <button class="btn btn-primary" id="trialBtn" type="button" hidden {{ $stripeReady ? '' : 'disabled' }}>
                {{ __('ui.billing.start_creator_trial', ['days' => (int) ($trialOffer['days'] ?? 7)]) }}
              </button>
            @endif
            <a class="btn" href="{{ route('manage-subscription.page') }}">{{ __('ui.billing.manage_subscription') }}</a>
          </div>
          <div id="message" class="message {{ $initialMessageType }}" role="status">{{ $initialMessageText }}</div>
          <div class="decline-help" id="declineHelp" hidden>
            <p class="decline-help-title">{{ __('ui.billing.decline_options_title') }}</p>
            <div class="decline-help-actions" id="declineActions"></div>
          </div>
        </form>
      </div>

      <div class="card quote-section" id="quoteSection">
        <div class="card-header">
          <h2>{{ __('ui.billing.charge_preview') }}</h2>
          <p>{{ __('ui.billing.charge_preview_hint') }}</p>
        </div>

        <div class="quote-highlight">
          <small id="quoteAmountLabel">{{ __('ui.billing.amount_to_be_charged') }}</small>
          <p class="amount" id="quoteAmount" dir="ltr">-</p>
          <p class="hint" id="quoteHint">{{ __('ui.billing.select_plan_for_preview') }}</p>
        </div>

        <section class="order-details" id="orderDetails" aria-labelledby="orderDetailsTitle" hidden>
          <h3 id="orderDetailsTitle">{{ __('ui.billing.order_details') }}</h3>
          <dl class="order-lines" id="orderLines"></dl>
          <div class="order-total">
            <dt>{{ __('ui.billing.order_total') }}</dt>
            <dd id="orderTotal" dir="ltr">-</dd>
          </div>
          {{-- A change of plan starts no fresh allowance (App\Support\CreditPeriod). --}}
          <p class="order-note">{{ __('ui.billing.order_usage_note') }}</p>
        </section>

        <div class="quote-details">
          <div class="quote-row">
            <dt>{{ __('ui.billing.plan') }}</dt>
            <dd id="quotePlan">-</dd>
          </div>
          <div class="quote-row">
            <dt>{{ __('ui.billing.payment_method_label') }}</dt>
            <dd id="quoteMethod">-</dd>
          </div>
          <div class="quote-row">
            <dt>{{ __('ui.billing.billing_cycle') }}</dt>
            <dd id="quoteCycle">-</dd>
          </div>
        </div>
        <p class="trial-cap-note" id="trialNote" hidden></p>
      </div>
    </div>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @php
    $billingI18n = [
      'confirm_and_pay' => __('ui.billing.confirm_and_pay'),
      'continue_manual' => __('ui.billing.i_completed_manual_payment'),
      'every' => __('ui.billing.every'),
      'month' => __('ui.billing.month'),
      'months' => __('ui.billing.months'),
      'year' => __('ui.billing.year'),
      'years' => __('ui.billing.years'),
      'select_plan_for_preview' => __('ui.billing.select_plan_for_preview'),
      'you_will_be_charged' => __('ui.billing.you_will_be_charged'),
      'you_will_pay_manually' => __('ui.billing.you_will_pay_manually'),
      'stripe_not_configured' => __('ui.billing.stripe_not_configured'),
      'invalid_card_details' => __('ui.billing.invalid_card_details'),
      'processing_payment' => __('ui.billing.processing_payment'),
      'choose_plan_and_cycle' => __('ui.billing.choose_plan_and_cycle'),
      'enter_card_details' => __('ui.billing.enter_card_details'),
      'securing_card' => __('ui.billing.securing_card'),
      'finalizing_subscription' => __('ui.billing.finalizing_subscription'),
      'waiting_bank_confirmation' => __('ui.billing.waiting_bank_confirmation'),
      'payment_auth_failed' => __('ui.billing.payment_auth_failed'),
      'payment_confirmed_redirecting' => __('ui.billing.payment_confirmed_redirecting'),
      'upgrade_failed' => __('ui.billing.upgrade_failed'),
      'subscription_updated_redirecting' => __('ui.billing.subscription_updated_redirecting'),
      'payment_method_unavailable' => __('ui.billing.payment_method_unavailable'),
      'payment_method_label' => __('ui.billing.payment_method_label'),
      'manual_callout' => __('ui.billing.manual_callout'),
      'manual_locked_to_egypt' => __('ui.billing.manual_locked_to_egypt'),
      'manual_exact_amount' => __('ui.billing.manual_exact_amount'),
      'amount_to_be_charged' => __('ui.billing.amount_to_be_charged'),
      'manual_submit' => __('ui.billing.i_completed_manual_payment'),
      'manual_request_received' => __('ui.billing.manual_request_received'),
      'no_upgrade_plans_available' => __('ui.billing.no_upgrade_plans_available'),
      'proof_image_required' => __('ui.billing.proof_image_required'),
      'monthly_label' => __('ui.billing.monthly'),
      'yearly_label' => __('ui.billing.yearly'),
      'cycle_with_discount' => __('ui.billing.cycle_with_discount'),
      'start_creator_trial' => __('ui.billing.start_creator_trial'),
      'trial_starting' => __('ui.billing.trial_starting'),
      'trial_started_redirecting' => __('ui.billing.trial_started_redirecting'),
      'trial_note' => __('ui.billing.trial_note'),
      'pay_choice_sub' => __('ui.billing.pay_choice_sub'),
      'due_today' => __('ui.billing.due_today'),
      'trial_quote_hint' => __('ui.billing.trial_quote_hint'),
      'order_renews_month' => __('ui.billing.order_renews_month'),
      'order_renews_year' => __('ui.billing.order_renews_year'),
      'order_credit_left' => __('ui.billing.order_credit_left'),
      'order_loading' => __('ui.billing.order_loading'),
      'order_fallback_hint' => __('ui.billing.order_fallback_hint', ['plan' => $activePlanName]),
      'card_form_unavailable' => __('ui.billing.card_form_unavailable'),
      'decline_no_funds' => __('ui.billing.decline_no_funds'),
      'decline_card_details' => __('ui.billing.decline_card_details'),
      'decline_try_again' => __('ui.billing.decline_try_again'),
      'decline_refused' => __('ui.billing.decline_refused'),
      'decline_pay_with' => __('ui.billing.decline_pay_with'),
      'decline_pay_monthly' => __('ui.billing.decline_pay_monthly'),
      'decline_smaller_plan' => __('ui.billing.decline_smaller_plan'),
      'decline_amount_month' => __('ui.billing.decline_amount_month'),
      'decline_amount_year' => __('ui.billing.decline_amount_year'),
      'decline_other_card' => __('ui.billing.decline_other_card'),
      'decline_check_card' => __('ui.billing.decline_check_card'),
      'decline_try_again_button' => __('ui.billing.decline_try_again_button'),
    ];
  @endphp
  <script>
    const stripeKey = @json($stripeKey);
    const csrfToken = '{{ csrf_token() }}';
    const userName = @json((string) auth()->user()->name);
    const userEmail = @json((string) auth()->user()->email);
    const hasDefaultPaymentMethod = @json((bool) $defaultPaymentMethod);
    const yearlyDiscountPercent = Number(@json((int) ($yearlyDiscountPercent ?? 0)));
    const planCatalog = @json(collect($upgradePlansForUi)->keyBy('slug')->all());
    const initialPaymentMethod = @json($selectedPaymentMethod);
    const gadsConversionSendTo = @json(\App\Support\SeoHelper::googleAdsConversionSendTo());
    const gadsTrialConversionSendTo = @json(\App\Support\SeoHelper::googleAdsTrialConversionSendTo());
    const trialOffer = @json($trialOffer ?? ['available' => false, 'quota' => []]);
    // Why a bank refused a card, by Stripe's decline code (App\Services\Billing\CardDecline).
    const declineCodes = @json(\App\Services\Billing\CardDecline::CODES);
    const paymentBrandAssets = @json($paymentBrandAssets);
    const trialBtn = document.getElementById('trialBtn');
    const trialBtnDefaultText = trialBtn ? trialBtn.textContent.trim() : '';
    const checkoutChoice = document.getElementById('checkoutChoice');
    const checkoutModeInputs = Array.from(document.querySelectorAll('input[name="checkout_mode"]'));
    const payChoiceSub = document.getElementById('payChoiceSub');
    const trialNote = document.getElementById('trialNote');

    const messageBox = document.getElementById('message');
    const declineHelp = document.getElementById('declineHelp');
    const declineActions = document.getElementById('declineActions');
    const subscribeBtn = document.getElementById('subscribeBtn');
    const planSelect = document.getElementById('plan');
    const cycleSelect = document.getElementById('cycle');
    const cycleMonthlyOption = cycleSelect?.querySelector('option[value="monthly"]') || null;
    const cycleYearlyOption = cycleSelect?.querySelector('option[value="yearly"]') || null;
    const cardError = document.getElementById('card-error');
    const saveCardCheckbox = document.getElementById('saveCard');
    const stripeContainer = document.getElementById('stripeContainer');
    const manualPanel = document.getElementById('manualPanel');
    const manualPlanField = document.getElementById('manualPlanField');
    const manualCycleField = document.getElementById('manualCycleField');
    const manualMethodField = document.getElementById('manualMethodField');
    const manualAmount = document.getElementById('manualAmount');
    const manualCycleHint = document.getElementById('manualCycleHint');
    const manualIntroText = document.getElementById('manualIntroText');
    const manualPaymentLink = document.getElementById('manualPaymentLink');
    const manualQrWrap = document.getElementById('manualQrWrap');
    const manualQrImage = document.getElementById('manualQrImage');
    const paymentMethodInputs = Array.from(document.querySelectorAll('input[name="payment_method_choice"]'));

    // Tablets and phones: the charge summary sits in the form, after the plan and
    // payment choices and right before paying; wider screens keep it beside the form.
    (function placeQuote() {
      const quoteSection = document.getElementById('quoteSection');
      const quoteSlot = document.getElementById('quoteSlot');
      if (!quoteSection || !quoteSlot || !window.matchMedia) return;
      const home = quoteSection.parentElement;
      const narrow = window.matchMedia('(max-width: 980px)');
      const place = function() {
        if (narrow.matches) {
          if (quoteSection.parentElement !== quoteSlot) quoteSlot.appendChild(quoteSection);
        } else if (quoteSection.parentElement !== home) {
          home.appendChild(quoteSection);
        }
      };
      place();
      if (narrow.addEventListener) narrow.addEventListener('change', place);
      else if (narrow.addListener) narrow.addListener(place);
    })();

    const quoteAmount = document.getElementById('quoteAmount');
    const quoteAmountLabel = document.getElementById('quoteAmountLabel');
    const quoteHint = document.getElementById('quoteHint');
    const quotePlan = document.getElementById('quotePlan');
    const quoteCycle = document.getElementById('quoteCycle');
    const quoteMethod = document.getElementById('quoteMethod');
    const orderDetails = document.getElementById('orderDetails');
    const orderLines = document.getElementById('orderLines');
    const orderTotal = document.getElementById('orderTotal');
    // A member on a paid plan pays the difference today: the order details come from the server.
    const planChange = {
      enabled: @json((bool) ($paidPlanChange ?? false)),
      url: '{{ route('billing.plan-change.preview') }}',
      cache: {},
      seq: 0,
    };
    // Apple Pay / Google Pay (components/wallet-pay), made once Stripe has loaded below.
    let billingWallet = null;
    // A wallet's payment method on its way through the Pay flow: { id, done, closed }.
    let walletPayment = null;
    const i18n = @json($billingI18n);
    const localeCode = String(document.documentElement.lang || '').toLowerCase();
    const isArabicLocale = localeCode.startsWith('ar');
    const subscribeBtnDefaultText = subscribeBtn ? subscribeBtn.textContent.trim() : i18n.confirm_and_pay;
    // True from the moment a payment (or a trial) is sent until its answer is in:
    // choosing another plan, cycle or method meanwhile never turns Pay back on.
    let paymentInFlight = false;
    const LIGHT_CARD_STYLE = {
      base: {
        color: '#13213d',
        iconColor: '#2e4f8f',
        fontSize: '15px',
        '::placeholder': { color: '#7a8da8' },
      },
      invalid: { color: '#c1244f', iconColor: '#c1244f' },
    };
    const DARK_CARD_STYLE = {
      base: {
        color: '#f5f7ff',
        iconColor: '#9fb0d4',
        fontSize: '15px',
        '::placeholder': { color: '#8f9bbb' },
      },
      invalid: { color: '#ff7a9a', iconColor: '#ff7a9a' },
    };

    function resolveCardStyle() {
      return document.documentElement.getAttribute('data-theme') === 'light'
        ? LIGHT_CARD_STYLE
        : DARK_CARD_STYLE;
    }

    function hasArabicChars(value) {
      return /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF]/.test(String(value || ''));
    }

    function normalizeStripeMessage(message, fallback) {
      const raw = String(message || '').trim();
      if (!raw) return fallback || '';
      if (hasArabicChars(raw) || !isArabicLocale) return raw;

      const lowered = raw.toLowerCase();
      // A refusal is never "invalid details": the member would retype the same card.
      if (lowered.includes('insufficient funds')) return i18n.decline_no_funds;
      if (lowered.includes('declined')) return i18n.decline_refused;

      const invalidCard =
        lowered.includes('card number') ||
        lowered.includes('cvc') ||
        lowered.includes('expiry') ||
        lowered.includes('expired card') ||
        lowered.includes('invalid card') ||
        lowered.includes('incomplete');

      if (invalidCard) return i18n.invalid_card_details;
      if (lowered.includes('authentication') || lowered.includes('3d secure') || lowered.includes('requires_action')) {
        return i18n.payment_auth_failed;
      }

      return fallback || i18n.invalid_card_details;
    }

    function setMessage(type, text) {
      hideDeclineHelp();
      messageBox.className = 'message';
      messageBox.textContent = '';
      if (!text) return;
      messageBox.classList.add(type === 'error' ? 'error' : 'success');
      messageBox.textContent = text;
    }

    // ── A card the bank refused: why, and what else the member can do ──

    // The kind of decline (no_funds, card_details, try_again, refused) for an error from Stripe.js.
    function declineKindOf(stripeError) {
      return declineCodes[String(stripeError?.decline_code || '').toLowerCase()]
        || declineCodes[String(stripeError?.code || '').toLowerCase()]
        || 'refused';
    }

    // An error that carries the decline's kind, so the page can offer the next step.
    function declineError(kind, message) {
      const error = new Error(String(message || i18n['decline_' + kind] || i18n.decline_refused));
      error.declineKind = kind;
      return error;
    }

    // Stripe.js says why the bank refused: our words, in the page's language.
    function stripeDeclineError(stripeError) {
      const kind = declineKindOf(stripeError);
      return declineError(kind, i18n['decline_' + kind]);
    }

    function hideDeclineHelp() {
      if (!declineHelp) return;
      declineHelp.hidden = true;
      if (declineActions) declineActions.textContent = '';
    }

    function declineButton(label, onClick, options = {}) {
      const button = document.createElement('button');
      button.className = options.primary ? 'btn btn-primary' : 'btn';
      button.type = 'button';
      button.addEventListener('click', onClick);
      if (options.logo) {
        const img = document.createElement('img');
        img.src = options.logo;
        img.alt = '';
        img.setAttribute('aria-hidden', 'true');
        button.appendChild(img);
      }
      button.appendChild(document.createTextNode(label));
      if (options.method) button.dataset.declineAction = 'method-' + options.method;
      else if (options.action) button.dataset.declineAction = options.action;
      return button;
    }

    function withAmount(template, values) {
      let text = String(template || '');
      Object.keys(values).forEach(function(key) {
        const value = key === 'amount' ? `⁦${values[key]}⁩` : String(values[key]);
        text = text.replace(':' + key, value);
      });
      return text;
    }

    // Moves the member to another choice on this form, then to the Pay button.
    function chooseInstead(change) {
      change();
      setMessage('', '');
      subscribeBtn?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      subscribeBtn?.focus({ preventScroll: true });
    }

    function selectPaymentMethod(method) {
      const input = paymentMethodInputs.find(function(item) { return String(item.value) === method; });
      if (!input || input.disabled) return;
      input.checked = true;
      input.dispatchEvent(new Event('change', { bubbles: true }));
      setMessage('', '');
      manualPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function showDeclineHelp(kind) {
      if (!declineHelp || !declineActions) return;
      declineActions.textContent = '';
      const selected = selectedMeta();
      const plan = currentPlan();
      const cycle = String(cycleSelect?.value || 'monthly');
      const buttons = [];

      // In Egypt: pay the same plan from a wallet or bank app instead of the card.
      ['instapay', 'vodafone_cash'].forEach(function(method) {
        const methodConfig = plan?.payment_methods?.[method];
        const input = paymentMethodInputs.find(function(item) { return String(item.value) === method; });
        if (!methodConfig || methodConfig.available !== true || !input) return;
        buttons.push(declineButton(
          withAmount(i18n.decline_pay_with, { method: String(methodConfig.label || method) }),
          function() { selectPaymentMethod(method); },
          { method, logo: paymentBrandAssets?.[method] || '' }
        ));
      });

      // Too little money on the card: a smaller amount today. Only for a new plan:
      // a member changing plans is offered no step down, which would charge nothing.
      if (kind === 'no_funds' && !planChange.enabled && selected) {
        if (cycle === 'yearly' && plan?.payment_methods?.stripe?.monthly && cycleMonthlyOption && !cycleMonthlyOption.disabled) {
          buttons.push(declineButton(
            withAmount(i18n.decline_pay_monthly, { amount: cardCharge(plan.payment_methods.stripe.monthly).amount }),
            function() {
              chooseInstead(function() {
                cycleSelect.value = 'monthly';
                cycleSelect.dispatchEvent(new Event('change', { bubbles: true }));
              });
            },
            { action: 'monthly' }
          ));
        }

        const price = function(item) { return Number(item?.payment_methods?.stripe?.[cycle]?.base_usd_cents || item?.payment_methods?.stripe?.[cycle]?.amount_cents || 0); };
        const smaller = Object.values(planCatalog)
          .filter(function(item) { return item.slug !== plan?.slug && price(item) > 0 && price(item) < price(plan); })
          .sort(function(a, b) { return price(b) - price(a); })[0];
        if (smaller) {
          buttons.push(declineButton(
            // Its price for the same cycle, said as a month or a year beside "monthly instead".
            String(i18n.decline_smaller_plan || '')
              .replace(':plan', String(smaller.localized_name || smaller.name || ''))
              .replace(':amount', withAmount(cycle === 'yearly' ? i18n.decline_amount_year : i18n.decline_amount_month, {
                amount: cardCharge(smaller.payment_methods.stripe[cycle]).amount,
              })),
            function() {
              chooseInstead(function() {
                planSelect.value = String(smaller.slug);
                planSelect.dispatchEvent(new Event('change', { bubbles: true }));
              });
            },
            { action: 'smaller-plan' }
          ));
        }
      }

      if (kind === 'try_again') {
        buttons.unshift(declineButton(i18n.decline_try_again_button, function() {
          setMessage('', '');
          document.getElementById('billingForm')?.requestSubmit();
        }, { primary: true, action: 'try-again' }));
      }

      if (card) {
        buttons.push(declineButton(kind === 'card_details' ? i18n.decline_check_card : i18n.decline_other_card, function() {
          if (selectedMethod() !== 'stripe') selectPaymentMethod('stripe');
          if (kind !== 'card_details') cardForm.clear();
          setMessage('', '');
          stripeContainer?.scrollIntoView({ behavior: 'smooth', block: 'center' });
          cardForm.focus();
        }, { action: 'card' }));
      }

      if (!buttons.length) return;
      buttons.forEach(function(button) { declineActions.appendChild(button); });
      declineHelp.hidden = false;
      // On a phone the options can sit below the screen: bring them into view.
      declineHelp.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // Shows an error from the payment; a declined card also gets what to do next.
    function showPaymentError(error, fallback) {
      if (error?.declineKind) {
        setMessage('error', error.message);
        showDeclineHelp(error.declineKind);
        return;
      }
      setMessage('error', normalizeStripeMessage(error?.message, fallback));
    }

    function currentPlan() {
      return planCatalog[String(planSelect?.value || '')] || null;
    }

    function cycleOptionLabel(baseLabel, discountPercent) {
      const pct = Math.max(0, Math.min(99, Math.round(Number(discountPercent || 0))));
      if (pct <= 0) return String(baseLabel || '');
      return String(i18n.cycle_with_discount || ':cycle (:percent%)')
        .replace(':cycle', String(baseLabel || ''))
        .replace(':percent', String(pct));
    }

    function syncCycleOptionLabels() {
      const plan = currentPlan();
      const promoPercent = plan && Boolean(plan.has_discount)
        ? Math.max(0, Math.min(99, Math.round(Number(plan.discount_percentage || 0))))
        : 0;
      // An offer is for monthly billing only: yearly keeps its usual saving.
      const yearlyPercent = Math.max(0, Math.min(99, yearlyDiscountPercent));

      if (cycleMonthlyOption) {
        cycleMonthlyOption.textContent = cycleOptionLabel(i18n.monthly_label, promoPercent);
      }
      if (cycleYearlyOption) {
        cycleYearlyOption.textContent = cycleOptionLabel(i18n.yearly_label, yearlyPercent);
      }
    }

    function preferredMethodForPlan(plan) {
      if (!plan) return 'stripe';
      const preferred = String(plan.preferred_payment_method || 'stripe');
      const preferredMeta = plan.payment_methods?.[preferred];
      return preferredMeta && preferredMeta.available ? preferred : 'stripe';
    }

    function selectedMethod() {
      const checked = paymentMethodInputs.find(function(input) { return input.checked; });
      return checked ? String(checked.value || 'stripe') : preferredMethodForPlan(currentPlan());
    }

    function selectedMeta() {
      const plan = currentPlan();
      if (!plan) return null;

      const cycle = String(cycleSelect?.value || 'monthly');
      const method = selectedMethod();
      const methodMeta = plan.payment_methods?.[method] || null;
      const meta = methodMeta ? methodMeta[cycle] : null;

      if (!methodMeta || methodMeta.available !== true || !meta) {
        return null;
      }

      return { plan, cycle, method, methodMeta, meta };
    }

    function syncManualFields() {
      if (manualPlanField) manualPlanField.value = String(planSelect?.value || '');
      if (manualCycleField) manualCycleField.value = String(cycleSelect?.value || 'monthly');
      if (manualMethodField) manualMethodField.value = String(selectedMethod() || 'stripe');
    }

    function cycleLabel(meta) {
      const intervalCount = Number(meta?.interval_count || 1);
      const interval = String(meta?.interval || 'month');
      const unit = interval === 'year'
        ? (intervalCount > 1 ? i18n.years : i18n.year)
        : (intervalCount > 1 ? i18n.months : i18n.month);
      return intervalCount > 1 ? `${i18n.every} ${intervalCount} ${unit}` : `${i18n.every} ${unit}`;
    }

    // Every amount on the page is in the visitor's own currency, as on the pricing page.
    function cardCharge(meta) {
      return { amount: String(meta?.amount_formatted || '') };
    }

    function syncPaymentMethods() {
      const plan = currentPlan();
      const preferred = preferredMethodForPlan(plan);

      paymentMethodInputs.forEach(function(input) {
        const card = document.querySelector(`[data-method-card="${input.value}"]`);
        const methodConfig = plan?.payment_methods?.[input.value];
        const available = Boolean(methodConfig && methodConfig.available);

        input.disabled = !available;
        if (card) {
          card.classList.toggle('unavailable', !available);
          card.classList.toggle('active', input.checked && available);
        }
      });

      const checked = paymentMethodInputs.find(function(input) {
        return input.checked && !input.disabled;
      });

      if (!checked) {
        const fallbackInput = paymentMethodInputs.find(function(input) {
          return String(input.value || '') === preferred && !input.disabled;
        }) || paymentMethodInputs.find(function(input) {
          return !input.disabled;
        });

        if (fallbackInput) {
          fallbackInput.checked = true;
        }
      }

      paymentMethodInputs.forEach(function(input) {
        const card = document.querySelector(`[data-method-card="${input.value}"]`);
        if (card) {
          card.classList.toggle('active', input.checked && !input.disabled);
        }
      });

      syncManualFields();
    }

    function renderMode() {
      const method = selectedMethod();
      const usingStripe = method === 'stripe';

      stripeContainer?.classList.toggle('hidden', !usingStripe);
      manualPanel?.classList.toggle('show', !usingStripe);

      if (subscribeBtn && !paymentInFlight) {
        subscribeBtn.textContent = usingStripe ? subscribeBtnDefaultText : i18n.manual_submit;
        const hasPlan = Boolean(currentPlan());
        subscribeBtn.disabled = !hasPlan || (usingStripe ? !stripe : false);
      }

      const trial = trialState();
      const trialMode = trial.ready && selectedCheckoutMode() === 'trial';
      if (checkoutChoice) {
        checkoutChoice.hidden = !trial.ready;
      }
      if (subscribeBtn) {
        subscribeBtn.hidden = trialMode;
      }
      if (trialBtn) {
        trialBtn.hidden = !trialMode;
        trialBtn.style.display = trialMode ? '' : 'none';
      }
      if (trial.ready) {
        if (payChoiceSub) {
          payChoiceSub.textContent = String(i18n.pay_choice_sub || '').replace(':amount', trial.amount);
        }
      }
      if (trialNote) {
        if (!trialMode) {
          trialNote.hidden = true;
          trialNote.textContent = '';
        } else {
          const quota = (trialOffer.plan_quotas || {})[String(trial.plan?.slug || '')] || trialOffer.quota || {};
          trialNote.hidden = false;
          // 0-9 with commas in both languages, as the server writes numbers.
          trialNote.textContent = String(i18n.trial_note || '')
            .replace(':quota', String(quota.name || trialOffer.quota_plan_name || ''))
            .replace(':chars', Number(quota.chars || 0).toLocaleString('en-US'))
            .replace(':per_request', Number(quota.per_request || 0).toLocaleString('en-US'))
            .replace(':minutes', Number(quota.minutes || 0).toLocaleString('en-US'));
        }
      }
    }

    function selectedCheckoutMode() {
      const checked = checkoutModeInputs.find(function(input) { return input.checked; });
      return checked ? String(checked.value) : 'pay';
    }

    function trialState() {
      const plan = currentPlan();
      const cycle = String(cycleSelect?.value || 'monthly');
      const planCycles = (trialOffer.plan_cycles || {})[String(plan?.slug || '')] || [];
      const ready = Boolean(trialBtn && trialOffer.enabled && trialOffer.available)
        && selectedMethod() === 'stripe'
        && planCycles.indexOf(cycle) !== -1;
      // A trial is followed by a card payment.
      const amount = cardCharge(cycle === 'yearly' ? plan?.yearly : plan?.monthly).amount;
      const end = new Date();
      end.setDate(end.getDate() + Number(trialOffer.days || 7));

      return {
        ready: ready,
        plan: plan,
        amount: `\u2066${amount}\u2069`,
        zeroPlain: zeroAmount(amount),
        // Arabic month names with 0-9, as the dates the server writes.
        date: end.toLocaleDateString(isArabicLocale ? 'ar-u-nu-latn' : (document.documentElement.lang || undefined), { year: 'numeric', month: 'long', day: 'numeric' }),
      };
    }

    function zeroAmount(formatted) {
      const text = String(formatted || '');
      const zeroed = text.replace(/[0-9\u0660-\u0669]+(?:[.,\u066B\u066C\s][0-9\u0660-\u0669]+)*/, '0');
      return zeroed === text ? '0' : zeroed;
    }

    function isTrialCheckout() {
      return trialState().ready && selectedCheckoutMode() === 'trial';
    }

    function renderManualPanel(selected) {
      if (!manualPanel) return;

      if (!selected || selected.method === 'stripe') {
        manualAmount.textContent = '-';
        manualCycleHint.textContent = i18n.select_plan_for_preview;
        manualIntroText.textContent = i18n.manual_callout;
        if (manualPaymentLink) {
          manualPaymentLink.style.display = 'none';
          manualPaymentLink.removeAttribute('href');
        }
        if (manualQrWrap) {
          manualQrWrap.classList.remove('show');
        }
        if (manualQrImage) {
          manualQrImage.removeAttribute('src');
        }
        return;
      }

      const destination = selected.methodMeta?.destination || {};
      manualAmount.textContent = String(selected.meta.amount_formatted || '-');
      manualCycleHint.textContent = `${i18n.you_will_pay_manually} ${String(selected.meta.amount_formatted || '-')}.`;
      manualIntroText.textContent = `${String(selected.methodMeta.label || '')} - ${String(selected.methodMeta.description || '')}`;
      const paymentLink = String(destination.payment_link || '').trim();
      const qrImageUrl = String(destination.qr_image_url || '').trim();
      if (manualPaymentLink) {
        if (paymentLink) {
          manualPaymentLink.style.display = 'inline-flex';
          manualPaymentLink.setAttribute('href', paymentLink);
        } else {
          manualPaymentLink.style.display = 'none';
          manualPaymentLink.removeAttribute('href');
        }
      }
      if (manualQrWrap) {
        manualQrWrap.classList.toggle('show', Boolean(qrImageUrl));
      }
      if (manualQrImage) {
        if (qrImageUrl) {
          manualQrImage.setAttribute('src', qrImageUrl);
        } else {
          manualQrImage.removeAttribute('src');
        }
      }
    }

    function renderChargePreview() {
      syncCycleOptionLabels();
      const selected = selectedMeta();
      if (!selected) {
        quoteAmount.textContent = '-';
        quoteAmountLabel.textContent = i18n.amount_to_be_charged;
        quotePlan.textContent = '-';
        quoteMethod.textContent = '-';
        quoteCycle.textContent = '-';
        quoteHint.textContent = currentPlan() ? i18n.payment_method_unavailable : i18n.no_upgrade_plans_available;
        renderManualPanel(null);
        renderPlanChange(null, '');
        syncWallet(null);
        return;
      }

      let amountText = cardCharge(selected.meta).amount || '-';
      const amountBidi = `\u2066${amountText}\u2069`;
      quoteAmount.textContent = amountText;
      quoteAmountLabel.textContent = selected.method === 'stripe'
        ? i18n.amount_to_be_charged
        : i18n.manual_exact_amount;
      quotePlan.textContent = String(selected.plan.localized_name || selected.plan.name || selected.plan.slug || '-');
      quoteMethod.textContent = String(selected.methodMeta.label || i18n.payment_method_label);
      quoteCycle.textContent = cycleLabel(selected.meta);
      quoteHint.textContent = selected.method === 'stripe'
        ? `${i18n.you_will_be_charged} ${amountBidi} (${cycleLabel(selected.meta)}).`
        : `${i18n.you_will_pay_manually} ${amountBidi}.`;
      // A discount: today's price, how long it lasts, and the regular price after it.
      // InstaPay / Vodafone Cash pay once, so their note is about this payment.
      const discountHint = selected.plan.has_discount
        ? String(selected.plan.discount_terms?.[(selected.method === 'stripe' ? '' : 'manual_') + selected.cycle] || '')
        : '';
      if (discountHint) {
        if (selected.method === 'stripe') quoteAmountLabel.textContent = i18n.due_today;
        quoteHint.textContent = discountHint;
      }
      if (isTrialCheckout()) {
        const trial = trialState();
        quoteAmount.textContent = trial.zeroPlain;
        quoteAmountLabel.textContent = i18n.due_today;
        quoteHint.textContent = String(i18n.trial_quote_hint || '')
          .replace(':amount', trial.amount)
          .replace(':date', trial.date);
      }
      renderManualPanel(selected);
      syncManualFields();
      renderPlanChange(selected, discountHint);
      syncWallet(selected);
    }

    // What the wallet sheet shows: today's charge for a new card subscription, in the currency
    // the card is charged in. Not for a trial or a change from a paid plan (those charge
    // another amount today), nor for InstaPay / Vodafone Cash.
    function walletCharge(selected) {
      if (!selected || selected.method !== 'stripe' || isTrialCheckout() || planChange.enabled) return null;
      const meta = selected.meta || {};
      const currency = String(meta.charge_currency || '').toUpperCase();
      const amount = currency === 'USD'
        ? Number(meta.base_usd_cents || 0)
        : (String(meta.currency || '').toUpperCase() === currency ? Number(meta.amount_cents || 0) : 0);
      if (!currency || !(amount > 0)) return null;
      const name = String(selected.plan.localized_name || selected.plan.name || selected.plan.slug || '');
      return { currency: currency, amount: Math.round(amount), label: `${name} (${cycleLabel(meta)})` };
    }

    function syncWallet(selected) {
      const charge = walletCharge(selected);
      if (billingWallet) billingWallet.set(charge, Boolean(charge));
    }

    // Closes the wallet's sheet: a tick when the payment went ahead, a cross when it did not.
    function settleWallet(payment, ok) {
      if (!payment || payment.closed) return;
      payment.closed = true;
      payment.done(ok ? 'success' : 'fail');
    }

    function planChangeKey(selected) {
      return `${String(selected.plan.slug || '')}|${String(selected.cycle || 'monthly')}`;
    }

    function planChangeApplies(selected) {
      return planChange.enabled && Boolean(selected) && selected.method === 'stripe' && !isTrialCheckout();
    }

    // Today's order details for this plan and cycle, fresh for ten minutes.
    async function loadPlanChange(selected) {
      const key = planChangeKey(selected);
      const cached = planChange.cache[key];
      if (cached && Date.now() - cached.at < 10 * 60 * 1000) return cached.data;
      const response = await post(planChange.url, { plan: selected.plan.slug, billing_cycle: selected.cycle });
      const data = response.ok && response.data?.ok ? response.data : null;
      if (data) planChange.cache[key] = { at: Date.now(), data: data };
      return data;
    }

    function planChangeProrationDate(selected) {
      if (!planChangeApplies(selected)) return null;
      const data = planChange.cache[planChangeKey(selected)]?.data;
      return data && data.change ? Number(data.proration_date || 0) || null : null;
    }

    function renderPlanChange(selected, discountHint) {
      if (!orderDetails) return;
      const seq = ++planChange.seq;
      if (!planChangeApplies(selected)) {
        orderDetails.hidden = true;
        return;
      }
      if (!planChange.cache[planChangeKey(selected)]) {
        quoteAmountLabel.textContent = i18n.due_today;
        quoteAmount.textContent = '\u2026';
        quoteHint.textContent = i18n.order_loading;
      }

      loadPlanChange(selected).then(function(data) {
        if (seq !== planChange.seq) return;
        if (data && !data.change) {
          orderDetails.hidden = true;
          return;
        }
        if (!data) throw new Error('preview');
        paintPlanChange(selected, data, discountHint);
      }).catch(function() {
        if (seq !== planChange.seq) return;
        // No preview: the most it can be is the new plan's price, less the credit.
        orderDetails.hidden = true;
        quoteAmountLabel.textContent = '';
        quoteAmount.textContent = cardCharge(selected.meta).amount || '-';
        quoteHint.textContent = i18n.order_fallback_hint;
      });
    }

    function paintPlanChange(selected, data, discountHint) {
      const isolate = (text) => `\u2066${String(text || '')}\u2069`;
      orderLines.replaceChildren(...(data.lines || []).map(function(line) {
        const row = document.createElement('div');
        row.className = 'order-line' + (line.kind === 'credit' ? ' is-credit' : '');
        const label = document.createElement('dt');
        label.textContent = String(line.label || '');
        const amount = document.createElement('dd');
        amount.dir = 'ltr';
        amount.textContent = (line.kind === 'credit' ? '\u2212' : '') + String(line.amount || '');
        row.append(label, amount);
        return row;
      }));
      orderTotal.textContent = String(data.total || '-');
      orderDetails.hidden = false;

      quoteAmountLabel.textContent = i18n.due_today;
      quoteAmount.textContent = String(data.total || '-');
      const notes = [];
      if (discountHint) {
        notes.push(discountHint);
      } else if (data.renews_on) {
        notes.push(String(selected.cycle === 'yearly' ? i18n.order_renews_year : i18n.order_renews_month)
          .replace(':amount', isolate(selected.meta.amount_formatted))
          .replace(':date', String(data.renews_on)));
      }
      if (data.credit_left) notes.push(String(i18n.order_credit_left).replace(':amount', isolate(data.credit_left)));
      quoteHint.textContent = notes.join(' ');
    }

    function setSubscribeButtonBusy(isBusy, text) {
      paymentInFlight = Boolean(isBusy);
      if (!subscribeBtn) return;
      const usingStripe = selectedMethod() === 'stripe';
      subscribeBtn.disabled = Boolean(isBusy) || (usingStripe && !stripe);
      subscribeBtn.classList.toggle('is-loading', Boolean(isBusy));
      subscribeBtn.textContent = text || (usingStripe ? subscribeBtnDefaultText : i18n.manual_submit);
    }

    async function post(url, payload = {}) {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
      });

      const data = await res.json().catch(() => ({}));
      return { ok: res.ok, status: res.status, data };
    }

    async function postForm(url, formData) {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: formData,
      });

      const data = await res.json().catch(() => ({}));
      return { ok: res.ok, status: res.status, data };
    }

    function responseMessage(response, fallback) {
      const data = response?.data || {};
      const direct = String(data.message || '').trim();
      if (direct !== '') return direct;

      const errors = data.errors && typeof data.errors === 'object' ? data.errors : null;
      if (errors) {
        for (const key of Object.keys(errors)) {
          const first = Array.isArray(errors[key]) ? String(errors[key][0] || '').trim() : '';
          if (first !== '') return first;
        }
      }

      return fallback;
    }

    // Stripe.js loads from Stripe's own servers. When it is blocked or did not load,
    // the card form says so and the rest of the page keeps working: InstaPay and
    // Vodafone Cash need nothing from it.
    let stripe = null;
    try {
      stripe = stripeKey && typeof Stripe === 'function' ? Stripe(stripeKey) : null;
    } catch (_error) {
      stripe = null;
    }
    const cardFormMissing = Boolean(stripeKey) && !stripe;
    const cardUnavailableText = cardFormMissing ? i18n.card_form_unavailable : i18n.stripe_not_configured;
    const elements = stripe ? window.ListenCardElements(stripe) : null;
    // The card in three boxes (components/card-fields): the number, the expiry date and
    // the code. Stripe takes the card from the number field and reads the other two with it.
    let cardComplete = false;
    const cardForm = elements
      ? window.ListenCardFields(elements, 'card', resolveCardStyle(), (state) => {
          cardComplete = state.complete;
          cardError.textContent = state.error
            ? normalizeStripeMessage(state.error.message, i18n.invalid_card_details)
            : '';
        })
      : null;
    const card = cardForm ? cardForm.card : null;
    if (cardFormMissing && cardError) cardError.textContent = cardUnavailableText;

    // The wallet's payment method goes through Pay exactly as a card typed into the boxes.
    billingWallet = elements && window.ListenWalletPay ? window.ListenWalletPay(stripe, elements, {
      id: 'billingWallet',
      country: @json((string) config('services.stripe.country', 'US')),
      charge: walletCharge(selectedMeta()),
      theme: document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark',
      onPay: function(methodId, done) {
        if (paymentInFlight) { done('fail'); return; }
        walletPayment = { id: methodId, done: done, closed: false };
        document.getElementById('billingForm')?.requestSubmit();
      },
    }) : null;
    syncWallet(selectedMeta());

    if (card && 'MutationObserver' in window) {
      let currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
      const observer = new MutationObserver(() => {
        const nextTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        if (nextTheme === currentTheme) return;
        currentTheme = nextTheme;
        cardForm.update({ style: resolveCardStyle() });
      });
      observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme'],
      });
    }

    async function collectCardPaymentMethod() {
      if (!stripe || !card) throw new Error(cardUnavailableText);

      const result = await stripe.createPaymentMethod({
        type: 'card',
        card,
        billing_details: {
          name: userName || undefined,
          email: userEmail || undefined,
        },
      });

      if (result.error) {
        throw new Error(normalizeStripeMessage(result.error.message, i18n.invalid_card_details));
      }

      return String(result.paymentMethod?.id || '');
    }

    paymentMethodInputs.forEach(function(input) {
      input.addEventListener('change', function() {
        syncPaymentMethods();
        renderMode();
        renderChargePreview();
      });
    });

    function sendAdsConversion(sendTo, meta, transactionId) {
      return new Promise(function(resolve) {
        if (typeof gtag !== 'function' || !sendTo) {
          resolve();
          return;
        }
        const payload = { send_to: sendTo, event_callback: resolve };
        const chargeCurrency = String(meta?.charge_currency || meta?.currency || '').toUpperCase();
        const minor = chargeCurrency === 'USD' && meta?.base_usd_cents ? meta.base_usd_cents : meta?.amount_cents;
        if (chargeCurrency && Number(minor) > 0) {
          payload.value = Math.round(Number(minor)) / 100;
          payload.currency = chargeCurrency;
        }
        if (transactionId) {
          payload.transaction_id = String(transactionId);
        }
        gtag('event', 'conversion', payload);
        // Navigating away before the hit is sent drops the conversion.
        setTimeout(resolve, 1500);
      });
    }

    function trackPurchaseConversion(meta) {
      return sendAdsConversion(gadsConversionSendTo, meta, '');
    }

    trialBtn?.addEventListener('click', async () => {
      if (paymentInFlight) return;
      setMessage('', '');
      let leaving = false;
      try {
        if (!stripe || !card) throw new Error(cardUnavailableText);
        if (!cardComplete) throw new Error(i18n.enter_card_details);

        paymentInFlight = true;
        if (trialBtn) {
          trialBtn.disabled = true;
          trialBtn.classList.add('is-loading');
          trialBtn.textContent = i18n.trial_starting;
        }

        const setupResponse = await post('{{ route('billing.trial.setup') }}', {
          plan: String(currentPlan()?.slug || ''),
          billing_cycle: String(cycleSelect?.value || 'monthly'),
        });
        if (!setupResponse.ok || setupResponse.data?.ok === false) {
          throw new Error(responseMessage(setupResponse, i18n.upgrade_failed));
        }

        const confirmed = await stripe.confirmCardSetup(String(setupResponse.data.client_secret || ''), {
          payment_method: {
            card,
            billing_details: {
              name: userName || undefined,
              email: userEmail || undefined,
            },
          },
        });
        if (confirmed.error) {
          if (confirmed.error.type === 'card_error') throw stripeDeclineError(confirmed.error);
          throw new Error(normalizeStripeMessage(confirmed.error.message, i18n.payment_auth_failed));
        }

        const confirmResponse = await post('{{ route('billing.trial.confirm') }}', {
          setup_intent_id: String(confirmed.setupIntent?.id || ''),
        });
        if (!confirmResponse.ok || confirmResponse.data?.ok === false) {
          throw new Error(responseMessage(confirmResponse, i18n.upgrade_failed));
        }

        setMessage('ok', confirmResponse.data?.message || i18n.trial_started_redirecting);
        const trialPlan = currentPlan();
        await sendAdsConversion(
          gadsTrialConversionSendTo,
          trialPlan ? trialPlan[String(cycleSelect?.value || 'monthly')] : null,
          String(confirmed.setupIntent?.id || '')
        );
        leaving = true;
        window.location.href = '{{ route('manage-subscription.page') }}';
      } catch (error) {
        showPaymentError(error, i18n.upgrade_failed);
      } finally {
        // On the way to the next page the button stays off.
        if (!leaving) {
          paymentInFlight = false;
          if (trialBtn) {
            trialBtn.disabled = false;
            trialBtn.classList.remove('is-loading');
            trialBtn.textContent = trialBtnDefaultText;
          }
        }
      }
    });

    document.getElementById('billingForm')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (paymentInFlight) return;
      if (isTrialCheckout()) {
        trialBtn?.click();
        return;
      }
      setMessage('', '');
      let leaving = false;
      // A wallet's payment (Apple Pay / Google Pay) is used once, for this Pay only.
      const wallet = walletPayment;
      walletPayment = null;

      try {
        const selected = selectedMeta();
        if (!selected) {
          throw new Error(i18n.payment_method_unavailable);
        }

        if (selected.method !== 'stripe') {
          const proofInput = document.getElementById('proofImage');
          if (!proofInput || !proofInput.files || proofInput.files.length === 0) {
            throw new Error(i18n.proof_image_required);
          }
          setSubscribeButtonBusy(true, i18n.manual_submit);
          const formData = new FormData();
          formData.set('plan', String(selected.plan.slug || ''));
          formData.set('billing_cycle', String(selected.cycle || 'monthly'));
          formData.set('payment_method', String(selected.method || 'instapay'));
          formData.set('proof_image', proofInput.files[0]);

          const response = await postForm('{{ route('billing.manual.confirm') }}', formData);
          if (!response.ok || response.data?.ok === false) {
            throw new Error(responseMessage(response, i18n.upgrade_failed));
          }

          await trackPurchaseConversion(selected.meta);
          setMessage('ok', response.data?.message || i18n.manual_request_received);
          proofInput.value = '';
          proofUploadArea?.classList.remove('has-file');
          if (proofUploadText) {
            proofUploadText.textContent = proofUploadArea?.dataset.defaultText || proofUploadText.textContent;
          }
          if (proofUploadHint) {
            proofUploadHint.style.display = '';
          }
          return;
        }

        setSubscribeButtonBusy(true, i18n.processing_payment);

        let paymentMethod = '';
        if (wallet) {
          paymentMethod = wallet.id;
        } else if (cardComplete) {
          setSubscribeButtonBusy(true, i18n.securing_card);
          paymentMethod = await collectCardPaymentMethod();
        } else if (!hasDefaultPaymentMethod) {
          throw new Error(i18n.enter_card_details);
        }

        setSubscribeButtonBusy(true, i18n.finalizing_subscription);
        const subscribeBody = {
          plan: String(selected.plan.slug || ''),
          billing_cycle: String(selected.cycle || 'monthly'),
          payment_method: paymentMethod || null,
          save_card: saveCardCheckbox ? saveCardCheckbox.checked : true,
        };
        const prorationDate = planChangeProrationDate(selected);
        if (prorationDate) subscribeBody.proration_date = prorationDate;
        let response = await post('{{ route('billing.subscribe') }}', subscribeBody);
        // The wallet's sheet closes once our server has answered: a bank check, if any, comes after it.
        settleWallet(wallet, !response.data?.decline && (
          (response.ok && response.data?.ok !== false)
          || (response.status === 402 && Boolean(response.data?.requires_action))
          || (response.status === 409 && Boolean(response.data?.already_on_trial || response.data?.requires_trial_end_confirmation))
        ));

        // A payment still overdue in its grace days is paid from Manage Subscription, never replaced here.
        if (response.status === 409 && response.data?.payment_overdue) {
          setMessage('error', String(response.data.message || ''));
          leaving = true;
          window.setTimeout(function() {
            window.location.href = String(response.data.manage_url || '{{ route('manage-subscription.page') }}');
          }, 2500);
          return;
        }

        // On a free trial: the same plan charges nothing; another plan ends the trial only if the member agrees.
        if (response.status === 409 && response.data?.already_on_trial) {
          setMessage('ok', response.data.message);
          return;
        }
        if (response.status === 409 && response.data?.requires_trial_end_confirmation) {
          if (!window.confirm(String(response.data.message || ''))) {
            setMessage('', '');
            return;
          }
          response = await post('{{ route('billing.subscribe') }}', Object.assign({}, subscribeBody, { end_trial: true }));
        }

        if (response.status === 402 && response.data?.requires_action && response.data?.client_secret) {
          setSubscribeButtonBusy(true, i18n.waiting_bank_confirmation);
          const confirmation = await stripe.confirmCardPayment(
            String(response.data.client_secret),
            paymentMethod ? { payment_method: paymentMethod } : undefined
          );
          if (confirmation.error) {
            // After the bank's check the card can still be refused (not enough money, for example).
            if (confirmation.error.type === 'card_error') throw stripeDeclineError(confirmation.error);
            throw new Error(normalizeStripeMessage(confirmation.error.message, i18n.payment_auth_failed));
          }

          await trackPurchaseConversion(selected.meta);
          setMessage('ok', i18n.payment_confirmed_redirecting);
          leaving = true;
          window.location.href = '{{ route('manage-subscription.page') }}';
          return;
        }

        if (response.data?.decline) {
          throw declineError(String(response.data.decline), response.data.message);
        }
        if (!response.ok || response.data?.ok === false) {
          throw new Error(response.data?.message || i18n.upgrade_failed);
        }

        // Only a real charge is a purchase (not a resumed plan or a card change).
        if (response.data?.charged !== false) {
          await trackPurchaseConversion(selected.meta);
        }
        setMessage('ok', response.data?.message || i18n.subscription_updated_redirecting);
        leaving = true;
        window.location.href = '{{ route('manage-subscription.page') }}';
      } catch (error) {
        // Includes the server's 409 while another payment of this member is going through.
        settleWallet(wallet, false);
        showPaymentError(error, i18n.upgrade_failed);
      } finally {
        settleWallet(wallet, false);
        // On the way to the next page Pay stays off; otherwise it comes back.
        if (!leaving) setSubscribeButtonBusy(false);
      }
    });

    planSelect?.addEventListener('change', function() {
      syncPaymentMethods();
      renderMode();
      renderChargePreview();
    });
    checkoutModeInputs.forEach(function(input) {
      input.addEventListener('change', function() {
        setMessage('', '');
        renderMode();
        renderChargePreview();
      });
    });
    cycleSelect?.addEventListener('change', function() {
      syncManualFields();
      renderMode();
      renderChargePreview();
    });

    if (initialPaymentMethod) {
      const initialInput = paymentMethodInputs.find(function(input) {
        return String(input.value || '') === initialPaymentMethod;
      });
      if (initialInput && !initialInput.disabled) {
        initialInput.checked = true;
      }
    }

    syncPaymentMethods();
    renderMode();
    renderChargePreview();

    // Custom file upload area
    const proofUploadArea = document.getElementById('proofUploadArea');
    const proofImage = document.getElementById('proofImage');
    const proofUploadText = document.getElementById('proofUploadText');
    const proofUploadHint = document.getElementById('proofUploadHint');
    if (proofUploadArea && proofImage) {
      if (!proofUploadArea.dataset.defaultText) {
        proofUploadArea.dataset.defaultText = proofUploadText ? proofUploadText.textContent : '';
      }
      proofUploadArea.addEventListener('click', function() {
        proofImage.click();
      });
      proofUploadArea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          proofImage.click();
        }
      });
      proofImage.addEventListener('change', function() {
        if (proofImage.files && proofImage.files.length > 0) {
          const fileName = proofImage.files[0].name;
          proofUploadArea.classList.add('has-file');
          proofUploadText.innerHTML = '';
          const fileNameSpan = document.createElement('span');
          fileNameSpan.className = 'proof-file-name';
          fileNameSpan.textContent = fileName;
          proofUploadText.appendChild(fileNameSpan);
          if (proofUploadHint) proofUploadHint.style.display = 'none';
        } else {
          proofUploadArea.classList.remove('has-file');
          proofUploadText.textContent = proofUploadArea.dataset.defaultText || proofUploadText.textContent;
          if (proofUploadHint) proofUploadHint.style.display = '';
        }
      });
    }
  </script>
</body>
</html>
