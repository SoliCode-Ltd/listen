@include('components.page-head', ['pageTitle' => __('ui.seo.login_title'), 'page' => 'login'])
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
      background: linear-gradient(180deg, var(--surface2), var(--surface));
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
    }
    .google-btn {
      width: 100%;
      display: inline-flex;
      justify-content: center;
      align-items: center;
      gap: .55rem;
      padding: .8rem;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--text);
      text-decoration: none;
      font-weight: 600;
      font-size: .95rem;
      transition: all .2s ease;
    }
    .google-btn:hover {
      transform: translateY(-1px);
      border-color: color-mix(in oklab, var(--b) 35%, var(--line));
    }
    .divider {
      display: flex;
      align-items: center;
      gap: .7rem;
      color: var(--muted);
      font-size: .82rem;
      margin: 1rem 0;
    }
    .divider::before,
    .divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--line);
    }
    .form-grid {
      display: grid;
      gap: .75rem;
    }
    label {
      display: block;
      font-size: .9rem;
      color: color-mix(in oklab, var(--text) 86%, var(--muted));
      margin-bottom: .35rem;
    }
    input[type="email"],
    input[type="password"],
    input[type="text"] {
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
    .remember-row {
      display: flex;
      align-items: center;
      gap: .5rem;
      font-size: .9rem;
    }
    .remember-row input {
      width: auto;
    }
    .submit-btn {
      width: 100%;
      padding: .85rem;
      border: 0;
      border-radius: var(--radius);
      font-weight: 700;
      font-size: .95rem;
      color: #fff;
      background: linear-gradient(135deg, var(--a), var(--b));
      cursor: pointer;
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
    .blocked-notice {
      margin: 0 0 1.25rem;
      padding: 0;
      border-radius: calc(var(--radius) + 4px);
      border: 1px solid color-mix(in oklab, var(--danger) 22%, var(--line));
      background: var(--surface);
      color: var(--text);
      box-shadow: 0 4px 24px rgba(0, 0, 0, .06);
      overflow: hidden;
    }
    .blocked-notice-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: .5rem;
      padding: 1.1rem 1.15rem .9rem;
      background: linear-gradient(135deg, color-mix(in oklab, var(--danger) 10%, var(--surface2)), var(--surface2));
      border-bottom: 1px solid color-mix(in oklab, var(--danger) 12%, var(--line));
    }
    .blocked-notice-icon {
      flex-shrink: 0;
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      background: color-mix(in oklab, var(--danger) 14%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--danger) 20%, var(--line));
    }
    .blocked-notice-icon svg {
      width: 18px;
      height: 18px;
      color: var(--danger);
    }
    .blocked-notice-title {
      font-size: .95rem;
      font-weight: 700;
      color: color-mix(in oklab, var(--danger) 55%, var(--text));
      line-height: 1.35;
    }
    .blocked-notice-content {
      padding: .9rem 1.15rem 1rem;
      text-align: center;
    }
    .blocked-notice-body {
      margin: 0;
      font-size: .88rem;
      line-height: 1.65;
      color: var(--muted);
    }
    .blocked-notice-reason {
      margin-top: .7rem;
      padding: .55rem .75rem;
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--danger) 6%, var(--surface2));
      border: 1px solid color-mix(in oklab, var(--danger) 15%, var(--line));
      font-size: .86rem;
      line-height: 1.5;
      overflow-wrap: anywhere;
      color: var(--text);
    }
    .blocked-notice-reason b {
      color: color-mix(in oklab, var(--danger) 55%, var(--muted));
      font-weight: 600;
      font-size: .8rem;
      margin-inline-end: .3rem;
    }
    .blocked-notice-contact {
      margin-top: .75rem;
      padding-top: .7rem;
      border-top: 1px solid color-mix(in oklab, var(--line) 60%, transparent);
      font-size: .85rem;
      line-height: 1.55;
      color: var(--muted);
      text-align: center;
    }
    .blocked-notice-contact a {
      color: var(--a);
      font-weight: 600;
      text-decoration: none;
      transition: color .2s ease;
      direction: ltr;
      unicode-bidi: embed;
    }
    .blocked-notice-contact a:hover {
      color: var(--b);
      text-decoration: underline;
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
  </style>
  <script>
    (() => {
      const saved = localStorage.getItem('listen_theme');
      document.documentElement.setAttribute('data-theme', saved || document.documentElement.getAttribute('data-theme') || 'dark');
    })();
  </script>
</head>
<body>
  @include('components.header')

  <main class="container auth-page">
    <form class="auth-card reveal" method="POST" action="{{ route('login.submit') }}">
      @csrf
      <h1>{{ __('ui.auth.login_title') }}</h1>
      <p class="subtitle">{{ __('ui.auth.login_subtitle') }}</p>

      @if(is_array(session('blocked_notice')))
        @php($blockedNotice = session('blocked_notice'))
        <div class="blocked-notice" role="alert" aria-live="polite">
          <div class="blocked-notice-header">
            <div class="blocked-notice-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            </div>
            <div class="blocked-notice-title">{{ $blockedNotice['title'] ?? __('ui.auth.blocked_notice_title') }}</div>
          </div>
          <div class="blocked-notice-content">
            <p class="blocked-notice-body">{{ $blockedNotice['body'] ?? __('ui.auth.blocked_notice_body') }}</p>
            @if(!empty($blockedNotice['reason']))
              <div class="blocked-notice-reason">
                <b>{{ __('ui.auth.blocked_notice_reason_label') }}</b>
                <span>{{ $blockedNotice['reason'] }}</span>
              </div>
            @endif
            <div class="blocked-notice-contact">
              {{ __('ui.auth.blocked_notice_contact_prompt') }}
              @php($supportEmail = $blockedNotice['email'] ?? 'help@listenai.studio')
              <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
            </div>
          </div>
        </div>
      @endif

      <a class="google-btn" href="{{ route('auth.social.redirect') }}">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 31.367 32"><path d="M31.367,16.368a18.825,18.825,0,0,0-.276-3.251H16v6.161h8.642a7.4,7.4,0,0,1-3.2,4.862v4H26.6A15.626,15.626,0,0,0,31.367,16.368Z" fill="#4285f4"></path><path d="M16,32a15.317,15.317,0,0,0,10.606-3.862l-5.156-4A9.7,9.7,0,0,1,7.022,19.066H1.7v4.122A16,16,0,0,0,16,32Z" fill="#34a853"></path><path d="M7.016,19.066a9.583,9.583,0,0,1,0-6.125V8.819H1.7a16.017,16.017,0,0,0,0,14.369Z" fill="#fbbc04"></path><path d="M16,6.333a8.694,8.694,0,0,1,6.138,2.4h0l4.568-4.568A15.376,15.376,0,0,0,16,0,16,16,0,0,0,1.7,8.819l5.314,4.122A9.57,9.57,0,0,1,16,6.333Z" fill="#ea4335"></path></svg>
        {{ __('ui.auth.continue_google') }}
      </a>

      <div class="divider">{{ __('ui.auth.or_email_signin') }}</div>

      <div class="form-grid">
        <div>
          <label for="email">{{ __('ui.auth.email') }}</label>
          <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email">
        </div>
        <div>
          <label for="password">{{ __('ui.auth.password') }}</label>
          <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <label class="remember-row">
          <input type="checkbox" name="remember" value="1">
          {{ __('ui.auth.remember_me') }}
        </label>
        <button class="submit-btn" type="submit">{{ __('ui.auth.sign_in') }}</button>
      </div>

      @error('email')
        <div class="error-box">{{ $message }}</div>
      @enderror

      <p class="auth-footer">
        {{ __('ui.auth.no_account') }} <a href="{{ route('register') }}">{{ __('ui.auth.create_one') }}</a>
      </p>
    </form>
  </main>

  @include('components.footer')
  @include('components.scripts')
</body>
</html>

