{{-- The look of the two password pages (ask for the link, choose a new password): the sign-in
     page's own card, fields, button and messages, without its Google and phone parts. --}}
  <style>
    .auth-page {
      min-height: calc(100vh - 180px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem 0 3rem;
    }
    .auth-card {
      width: min(440px, 100%);
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 2rem;
      box-shadow: var(--shadow);
    }
    .auth-card h1 {
      margin: 0 0 .35rem;
      font-size: 1.6rem;
    }
    .auth-card .subtitle {
      color: var(--muted);
      margin: 0 0 1.25rem;
      font-size: .95rem;
      line-height: 1.6;
    }
    .form-grid {
      display: grid;
      gap: .75rem;
    }
    .status-box + .form-grid,
    .error-box + .form-grid {
      margin-top: 1.1rem;
    }
    .error-box {
      line-height: 1.55;
    }
    label {
      display: block;
      font-size: .9rem;
      color: color-mix(in oklab, var(--text) 86%, var(--muted));
      margin-bottom: .35rem;
    }
    input[type="email"],
    input[type="password"] {
      width: 100%;
      padding: .75rem;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--text);
      font-size: .95rem;
    }
    input:focus {
      outline: none;
      border-color: var(--a);
    }
    .field-hint {
      margin: .35rem 0 0;
      color: var(--muted);
      font-size: .82rem;
    }
    .submit-btn {
      width: 100%;
      padding: .85rem;
      border: 0;
      border-radius: var(--radius);
      font-weight: 700;
      font-size: .95rem;
      cursor: pointer;
      color: #fff;
      background: linear-gradient(135deg, var(--a), var(--b));
      transition: all .2s ease;
    }
    .submit-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 20px color-mix(in oklab, var(--b) 30%, transparent);
    }
    .error-box {
      margin-top: .6rem;
      padding: .65rem .75rem;
      background: color-mix(in oklab, var(--danger) 14%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--danger) 45%, var(--line));
      border-radius: var(--radius);
      color: color-mix(in oklab, var(--danger) 80%, var(--text));
      font-size: .9rem;
    }
    .status-box.success {
      margin: 0 0 1rem;
      padding: .65rem .75rem;
      border: 1px solid color-mix(in oklab, #2bb673 45%, var(--line));
      border-radius: var(--radius);
      background: color-mix(in oklab, #2bb673 16%, var(--surface));
      color: color-mix(in oklab, #2bb673 70%, var(--text));
      font-size: .9rem;
      font-weight: 500;
      line-height: 1.6;
    }
    .auth-footer {
      margin-top: 1rem;
      text-align: center;
      color: var(--muted);
      font-size: .9rem;
    }
    .auth-footer a {
      color: var(--text);
      text-decoration: none;
    }
    .auth-footer a:hover {
      text-decoration: underline;
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
    @media (max-width: 640px) {
      .auth-page { padding: 1.25rem 0 2rem; }
      .auth-card { padding: 1.25rem; }
      .auth-card h1 { font-size: 1.35rem; }
    }
  </style>
  <script>
    (() => {
      const saved = localStorage.getItem('listen_theme');
      document.documentElement.setAttribute('data-theme', saved || document.documentElement.getAttribute('data-theme') || 'dark');
    })();
  </script>
  @php($authCssFile = public_path('css/auth.css'))
  <link rel="stylesheet" href="{{ asset('css/auth.css') }}?v={{ is_file($authCssFile) ? filemtime($authCssFile) : '1' }}">
  @php($voiceShowcaseCss = public_path('css/voice-showcase.css'))
  <link rel="stylesheet" href="{{ asset('css/voice-showcase.css') }}?v={{ is_file($voiceShowcaseCss) ? filemtime($voiceShowcaseCss) : '1' }}">
