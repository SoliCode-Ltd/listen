@include('components.page-head', ['pageTitle' => __('ui.seo.login_title'), 'page' => 'login'])
@php
  $authSettings = is_array($authSettings ?? null) ? $authSettings : [];
  $emailEnabled = (bool) ($authSettings['email_enabled'] ?? true);
  $googleEnabled = (bool) ($authSettings['google_enabled'] ?? true);
  $phoneEnabled = (bool) ($authSettings['phone_enabled'] ?? false);
  $firebaseGoogleEnabled = (bool) ($authSettings['firebase_google_enabled'] ?? false);
  $phoneI18n = [
    'missingPhone' => __('ui.auth.phone_missing_number'),
    'missingCode' => __('ui.auth.phone_missing_code'),
    'sending' => __('ui.auth.phone_sending_code'),
    'codeSent' => __('ui.auth.phone_code_sent'),
    'verifying' => __('ui.auth.phone_verifying'),
    'verified' => __('ui.auth.phone_verified_redirecting'),
    'genericError' => __('ui.auth.phone_generic_error'),
    'tooManyRequests' => __('ui.auth.phone_too_many_requests'),
    'invalidPhone' => __('ui.auth.phone_invalid_number'),
    'invalidCode' => __('ui.auth.phone_invalid_code'),
    'expiredCode' => __('ui.auth.phone_code_expired'),
    'recaptchaExpired' => __('ui.auth.phone_recaptcha_expired'),
    'serviceUnavailable' => __('ui.auth.phone_service_unavailable'),
    'configurationError' => __('ui.auth.phone_configuration_error'),
  ];
@endphp
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.0/build/css/intlTelInput.css">
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
    .phone-grid {
      display: grid;
      gap: .75rem;
    }
    .otp-row {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: .55rem;
      align-items: end;
    }
    .phone-input-wrapper {
      display: flex;
      flex-direction: column;
    }
    .phone-input-row {
      display: grid;
      grid-template-columns: 1fr auto;
      gap: .55rem;
      align-items: end;
      direction: ltr;
    }
    .phone-input-row .iti {
      width: 100%;
      direction: ltr !important;
    }
    .phone-input-row .iti input[type="tel"] {
      width: 100%;
      height: 46px;
      padding-top: 0 !important;
      padding-bottom: 0 !important;
      padding-right: 6px !important;
      padding-left: .75rem;
      padding-inline-end: 6px !important;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--text);
      font-size: .95rem;
      line-height: 46px;
      direction: ltr !important;
      text-align: left !important;
      box-sizing: border-box;
    }
    .phone-input-row .iti input[type="tel"]:focus {
      outline: none;
      border-color: var(--a);
      box-shadow: 0 0 0 1px var(--a);
    }
    .phone-input-row .iti input[type="tel"]::placeholder {
      color: var(--muted);
      opacity: .55;
      line-height: inherit;
    }
    .phone-input-row .iti__country-container {
      position: absolute !important;
      top: 0 !important;
      bottom: 0 !important;
      left: 0 !important;
      right: auto !important;
      inset-inline-start: 0 !important;
      inset-inline-end: auto !important;
    }
    .phone-input-row .iti__selected-country {
      background: transparent !important;
      border: none !important;
      border-right: 1px solid var(--line) !important;
      border-radius: var(--radius) 0 0 var(--radius);
      padding: 0 10px 0 12px !important;
      height: 100%;
      direction: ltr !important;
      display: flex;
      align-items: center;
    }
    .phone-input-row .iti__selected-country-primary {
      display: flex;
      align-items: center;
      gap: 6px;
      direction: ltr;
    }
    .phone-input-row .iti__selected-dial-code {
      color: var(--muted);
      font-size: .9rem;
      direction: ltr;
      margin: 0;
    }
    .phone-input-row .iti__arrow {
      margin-left: 6px;
      border-top-color: var(--muted);
    }
    .phone-input-row .iti__arrow--up {
      border-bottom-color: var(--muted);
    }
    .phone-input-row .iti__country-list {
      left: 0 !important;
      right: auto !important;
      text-align: left;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--text);
      box-shadow: 0 14px 30px rgba(0,0,0,.18);
      max-height: 220px;
    }
    .phone-input-row .iti__country-list .iti__country {
      padding: 8px 12px;
    }
    .phone-input-row .iti__country-list .iti__country:hover,
    .phone-input-row .iti__country-list .iti__country--highlight {
      background: color-mix(in oklab, var(--a) 12%, var(--surface));
    }
    .phone-input-row .iti__country-list .iti__dial-code {
      color: var(--muted);
    }
    .phone-input-row .iti__search-input {
      background: var(--surface2);
      color: var(--text);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 8px 10px;
    }
    .phone-send-btn {
      height: 46px;
      padding: 0 1.25rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: var(--surface);
      color: var(--text);
      font-weight: 600;
      font-size: .9rem;
      cursor: pointer;
      transition: all .2s ease;
      white-space: nowrap;
    }
    .phone-send-btn:hover {
      border-color: var(--a);
      color: var(--a);
      background: color-mix(in oklab, var(--a) 6%, var(--surface));
    }
    .phone-send-btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }
    html[dir="rtl"] .phone-input-row {
      direction: ltr !important;
    }
    html[dir="rtl"] .phone-input-row .iti {
      direction: ltr !important;
    }
    html[dir="rtl"] .phone-input-row .iti__country-container {
      left: 0 !important;
      right: auto !important;
      inset-inline-start: 0 !important;
      inset-inline-end: auto !important;
    }
    html[dir="rtl"] .phone-input-row .iti input[type="tel"] {
      direction: ltr !important;
      text-align: left !important;
    }
    label {
      display: block;
      font-size: .9rem;
      color: color-mix(in oklab, var(--text) 86%, var(--muted));
      margin-bottom: .35rem;
    }
    input[type="email"],
    input[type="password"],
    input[type="text"],
    input[type="tel"] {
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
    .iti {
      width: 100%;
      direction: ltr;
    }
    html[dir="rtl"] .iti {
      direction: ltr;
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
    .submit-btn,
    .phone-btn {
      padding: .85rem;
      border: 0;
      border-radius: var(--radius);
      font-weight: 700;
      font-size: .95rem;
      cursor: pointer;
      transition: all .2s ease;
    }
    .submit-btn {
      width: 100%;
      color: #fff;
      background: linear-gradient(135deg, var(--a), var(--b));
    }
    .phone-btn {
      color: var(--text);
      background: var(--surface);
      border: 1px solid var(--line);
      min-width: 116px;
      text-align: center;
    }
    .submit-btn:hover,
    .phone-btn:hover {
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
    .status-box {
      margin-top: .1rem;
      padding: .65rem .75rem;
      border-radius: var(--radius);
      font-size: .88rem;
      line-height: 1.5;
      background: color-mix(in oklab, var(--a) 11%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--a) 36%, var(--line));
      color: color-mix(in oklab, var(--a) 70%, var(--text));
    }
    .status-box.error {
      background: color-mix(in oklab, var(--danger) 14%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--danger) 45%, var(--line));
      color: color-mix(in oklab, var(--danger) 80%, var(--text));
    }
    .status-box.success {
      background: color-mix(in oklab, #2bb673 16%, var(--surface));
      border-color: color-mix(in oklab, #2bb673 45%, var(--line));
      color: color-mix(in oklab, #2bb673 70%, var(--text));
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

    @media (max-width: 640px) {
      .auth-page {
        padding: 1.25rem 0 2rem;
      }
      .auth-card {
        padding: 1.25rem;
      }
      .auth-card h1 {
        font-size: 1.35rem;
      }
      .phone-input-row,
      .otp-row {
        grid-template-columns: 1fr;
      }
      .phone-send-btn,
      .phone-btn {
        width: 100%;
        height: 44px;
      }
      .phone-input-row .iti input[type="tel"] {
        height: 44px;
        line-height: 44px;
        font-size: .9rem;
      }
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
</head>
<body class="auth-split-body">
  <script>
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) window.location.reload();
    });
  </script>
  @include('components.header')

  <div class="auth-split">
  <div class="auth-pane">
  @include('components.auth.topbar')
  <main class="container auth-page">
    <div class="auth-card reveal">
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

      <div class="blocked-notice" id="jsBlockedNotice" role="alert" aria-live="polite" style="display:none;">
        <div class="blocked-notice-header">
          <div class="blocked-notice-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
          </div>
          <div class="blocked-notice-title" id="jsBlockedTitle"></div>
        </div>
        <div class="blocked-notice-content">
          <p class="blocked-notice-body" id="jsBlockedBody"></p>
          <div class="blocked-notice-reason" id="jsBlockedReasonWrap" style="display:none;">
            <b>{{ __('ui.auth.blocked_notice_reason_label') }}</b>
            <span id="jsBlockedReason"></span>
          </div>
          <div class="blocked-notice-contact" id="jsBlockedContact"></div>
        </div>
      </div>

      @if($errors->has('auth'))
        <div class="error-box">{{ $errors->first('auth') }}</div>
      @endif

      @if($googleEnabled)
        <a class="google-btn" href="{{ route('auth.social.redirect') }}">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 31.367 32"><path d="M31.367,16.368a18.825,18.825,0,0,0-.276-3.251H16v6.161h8.642a7.4,7.4,0,0,1-3.2,4.862v4H26.6A15.626,15.626,0,0,0,31.367,16.368Z" fill="#4285f4"></path><path d="M16,32a15.317,15.317,0,0,0,10.606-3.862l-5.156-4A9.7,9.7,0,0,1,7.022,19.066H1.7v4.122A16,16,0,0,0,16,32Z" fill="#34a853"></path><path d="M7.016,19.066a9.583,9.583,0,0,1,0-6.125V8.819H1.7a16.017,16.017,0,0,0,0,14.369Z" fill="#fbbc04"></path><path d="M16,6.333a8.694,8.694,0,0,1,6.138,2.4h0l4.568-4.568A15.376,15.376,0,0,0,16,0,16,16,0,0,0,1.7,8.819l5.314,4.122A9.57,9.57,0,0,1,16,6.333Z" fill="#ea4335"></path></svg>
          {{ __('ui.auth.continue_google') }}
        </a>
      @endif

      @if($firebaseGoogleEnabled)
        <button type="button" class="google-btn" id="firebaseGoogleSignInBtn">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 31.367 32"><path d="M31.367,16.368a18.825,18.825,0,0,0-.276-3.251H16v6.161h8.642a7.4,7.4,0,0,1-3.2,4.862v4H26.6A15.626,15.626,0,0,0,31.367,16.368Z" fill="#4285f4"></path><path d="M16,32a15.317,15.317,0,0,0,10.606-3.862l-5.156-4A9.7,9.7,0,0,1,7.022,19.066H1.7v4.122A16,16,0,0,0,16,32Z" fill="#34a853"></path><path d="M7.016,19.066a9.583,9.583,0,0,1,0-6.125V8.819H1.7a16.017,16.017,0,0,0,0,14.369Z" fill="#fbbc04"></path><path d="M16,6.333a8.694,8.694,0,0,1,6.138,2.4h0l4.568-4.568A15.376,15.376,0,0,0,16,0,16,16,0,0,0,1.7,8.819l5.314,4.122A9.57,9.57,0,0,1,16,6.333Z" fill="#ea4335"></path></svg>
          {{ __('ui.auth.continue_google') }}
        </button>
        <div class="status-box" id="firebaseGoogleStatus" style="display:none;"></div>
      @endif

      @if($phoneEnabled)
        <div class="divider">{{ __('ui.auth.or_phone_signin') }}</div>
        <div class="phone-grid" id="phoneAuthSection">
          <div class="phone-input-wrapper">
            <label for="phone_number">{{ __('ui.auth.phone_number') }}</label>
            <div class="phone-input-row">
              <input type="tel" id="phone_number" inputmode="tel" autocomplete="tel" dir="ltr">
              <button type="button" class="phone-send-btn" id="sendOtpBtn" dir="auto">{{ __('ui.auth.send_code') }}</button>
            </div>
          </div>

          <div class="otp-row" id="otpRow" style="display:none;">
            <div>
              <label for="otp_code">{{ __('ui.auth.verification_code') }}</label>
              <input type="text" id="otp_code" inputmode="numeric" maxlength="10" autocomplete="one-time-code" placeholder="123456">
            </div>
            <button type="button" class="submit-btn" id="verifyOtpBtn">{{ __('ui.auth.verify_and_signin') }}</button>
          </div>

          <button type="button" class="phone-btn" id="resendOtpBtn" style="display:none;">{{ __('ui.auth.resend_code') }}</button>
          <div id="recaptcha-container"></div>
          <div class="status-box" id="phoneAuthStatus" style="display:none;"></div>
        </div>
      @endif

      @if($emailEnabled)
        <div class="divider">{{ __('ui.auth.or_email_signin') }}</div>

        <form class="form-grid" method="POST" action="{{ route('login.submit') }}">
          @csrf
          <div>
            <label for="email">{{ __('ui.auth.email') }}</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email">
          </div>
          <div>
            <label for="password">{{ __('ui.auth.password') }}</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
          </div>
          <label class="remember-row">
            <input type="checkbox" name="remember" value="1" checked>
            {{ __('ui.auth.remember_me') }}
          </label>
          <button class="submit-btn" type="submit">{{ __('ui.auth.sign_in') }}</button>
        </form>

        @error('email')
          <div class="error-box">{{ $message }}</div>
        @enderror
      @endif

      <p class="auth-footer">
        {{ __('ui.auth.no_account') }} <a href="{{ route('register') }}">{{ __('ui.auth.create_one') }}</a>
      </p>
    </div>
  </main>
  @include('components.auth.legal')
  </div>
  @include('components.auth.showcase', ['authMode' => 'login'])
  </div>

  @php($blockedI18n = [
    'title' => __('ui.auth.blocked_notice_title'),
    'body' => __('ui.auth.blocked_notice_body'),
    'contact_prompt' => __('ui.auth.blocked_notice_contact_prompt'),
    'email' => 'help@listenai.studio',
  ])
  <script>
    window.__showBlockedNotice = function(notice) {
      var wrap = document.getElementById('jsBlockedNotice');
      if (!wrap || !notice) return;
      var defaults = @json($blockedI18n);
      document.getElementById('jsBlockedTitle').textContent = notice.title || defaults.title;
      document.getElementById('jsBlockedBody').textContent = notice.body || defaults.body;
      var reasonWrap = document.getElementById('jsBlockedReasonWrap');
      if (notice.reason) {
        document.getElementById('jsBlockedReason').textContent = notice.reason;
        reasonWrap.style.display = '';
      } else {
        reasonWrap.style.display = 'none';
      }
      var email = notice.email || defaults.email;
      var contactEl = document.getElementById('jsBlockedContact');
      contactEl.textContent = '';
      contactEl.appendChild(document.createTextNode(defaults.contact_prompt + ' '));
      var a = document.createElement('a');
      a.href = 'mailto:' + email;
      a.textContent = email;
      contactEl.appendChild(a);
      wrap.style.display = '';
      wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };
  </script>

  @if($phoneEnabled)
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.0/build/js/intlTelInput.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.0/build/js/utils.js"></script>
    <script type="module">
      import { initializeApp, getApps, getApp } from "https://www.gstatic.com/firebasejs/12.11.0/firebase-app.js";
      import { getAuth, RecaptchaVerifier, signInWithPhoneNumber } from "https://www.gstatic.com/firebasejs/12.11.0/firebase-auth.js";

      (() => {
        const rawFirebaseConfig = @json($authSettings['firebase']['config'] ?? []);
        const recaptchaMode = @json($authSettings['firebase']['recaptcha_mode'] ?? 'invisible');
        const firebaseTestingMode = @json($authSettings['firebase']['testing_mode'] ?? false);
        const verifyEndpoint = @json(route('auth.phone.verify'));
        const csrfToken = @json(csrf_token());
        const i18n = @json($phoneI18n);

        const sendBtn = document.getElementById('sendOtpBtn');
        const verifyBtn = document.getElementById('verifyOtpBtn');
        const resendBtn = document.getElementById('resendOtpBtn');
        const phoneInput = document.getElementById('phone_number');
        const codeInput = document.getElementById('otp_code');
        const otpRow = document.getElementById('otpRow');
        const statusBox = document.getElementById('phoneAuthStatus');

        if (!sendBtn || !verifyBtn || !phoneInput || !codeInput || !otpRow || !statusBox || typeof window.intlTelInput !== 'function') {
          return;
        }

        const stripSpaces = (value) => String(value ?? '').replace(/\s+/g, '').trim();
        const firebaseConfig = {
          apiKey: stripSpaces(rawFirebaseConfig.apiKey),
          authDomain: String(rawFirebaseConfig.authDomain || '')
            .trim()
            .replace(/^https?:\/\//i, '')
            .replace(/\/+$/, '')
            .split('/')[0]
            .toLowerCase(),
          projectId: stripSpaces(rawFirebaseConfig.projectId),
          appId: stripSpaces(rawFirebaseConfig.appId),
          messagingSenderId: stripSpaces(rawFirebaseConfig.messagingSenderId),
          storageBucket: String(rawFirebaseConfig.storageBucket || '').trim(),
          measurementId: stripSpaces(rawFirebaseConfig.measurementId),
        };

        const missingRequiredConfig = ['apiKey', 'authDomain', 'projectId', 'appId'].filter((key) => !firebaseConfig[key]);
        if (missingRequiredConfig.length > 0) {
          setStatus(i18n.configurationError, true);
          console.error('Firebase config missing required keys', { missingRequiredConfig });
          sendBtn.disabled = true;
          return;
        }

        const iti = window.intlTelInput(phoneInput, {
          initialCountry: document.documentElement.getAttribute('dir') === 'rtl' ? 'eg' : 'us',
          preferredCountries: ['eg', 'sa', 'ae', 'us', 'gb'],
          separateDialCode: true,
          strictMode: true,
          formatOnDisplay: true,
          nationalMode: true,
          autoPlaceholder: 'aggressive',
          dropdownContainer: document.body,
          utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.0/build/js/utils.js',
        });
        const itiRoot = phoneInput.closest('.iti');
        const forcePhoneUiLtr = () => {
          if (!itiRoot) return;
          itiRoot.setAttribute('dir', 'ltr');
          itiRoot.style.direction = 'ltr';
          phoneInput.style.direction = 'ltr';
          phoneInput.style.textAlign = 'left';
          const countryContainer = itiRoot.querySelector('.iti__country-container');
          if (countryContainer) {
            countryContainer.style.left = '0';
            countryContainer.style.right = 'auto';
            countryContainer.style.insetInlineStart = '0';
            countryContainer.style.insetInlineEnd = 'auto';
          }
          const selectedCountry = itiRoot.querySelector('.iti__selected-country');
          if (selectedCountry) {
            selectedCountry.style.direction = 'ltr';
            const w = selectedCountry.offsetWidth;
            if (w > 0) {
              phoneInput.style.paddingLeft = (w + 8) + 'px';
              phoneInput.style.paddingInlineStart = (w + 8) + 'px';
              phoneInput.style.paddingRight = '6px';
              phoneInput.style.paddingInlineEnd = '6px';
            }
          }
        };
        forcePhoneUiLtr();
        setTimeout(forcePhoneUiLtr, 50);
        phoneInput.addEventListener('countrychange', () => {
          setTimeout(forcePhoneUiLtr, 10);
        });

        let confirmationResult = null;
        let recaptchaVerifier = null;
        let auth;
        const sendCooldownStorageKey = 'listen_phone_send_cooldown_until_login';
        let sendCooldownUntil = Number(sessionStorage.getItem(sendCooldownStorageKey) || 0);
        let sendCooldownTimer = null;
        let requestInFlight = false;

        try {
          const app = getApps().length ? getApp() : initializeApp(firebaseConfig);
          auth = getAuth(app);
          const pageLang = String(document.documentElement.lang || '').toLowerCase();
          auth.languageCode = pageLang.startsWith('ar') ? 'ar' : 'en';
          if (firebaseTestingMode && auth.settings) {
            auth.settings.appVerificationDisabledForTesting = true;
          }
        } catch (error) {
          setStatus(i18n.serviceUnavailable, true);
          console.error('Firebase init failed', error);
          return;
        }

        function setStatus(message, isError = false, isSuccess = false) {
          statusBox.style.display = message ? 'block' : 'none';
          statusBox.textContent = message || '';
          statusBox.classList.remove('error', 'success');
          if (isError) statusBox.classList.add('error');
          if (isSuccess) statusBox.classList.add('success');
        }

        function setButtonsState(disabled) {
          requestInFlight = disabled;
          verifyBtn.disabled = disabled;
          if (disabled) {
            sendBtn.disabled = true;
            if (resendBtn) resendBtn.disabled = true;
            return;
          }

          const cooldownActive = Date.now() < sendCooldownUntil;
          sendBtn.disabled = cooldownActive;
          if (resendBtn) resendBtn.disabled = cooldownActive;
        }

        function startSendCooldown(seconds) {
          sendCooldownUntil = Date.now() + (Math.max(0, seconds) * 1000);
          sessionStorage.setItem(sendCooldownStorageKey, String(sendCooldownUntil));
          sendBtn.disabled = true;
          if (resendBtn) resendBtn.disabled = true;

          if (sendCooldownTimer) {
            clearInterval(sendCooldownTimer);
          }

          sendCooldownTimer = setInterval(() => {
            if (Date.now() < sendCooldownUntil) return;
            clearInterval(sendCooldownTimer);
            sendCooldownTimer = null;
            sendCooldownUntil = 0;
            sessionStorage.removeItem(sendCooldownStorageKey);

            if (!requestInFlight) {
              sendBtn.disabled = false;
              if (resendBtn) resendBtn.disabled = false;
            }
          }, 1000);
        }

        function isTooManyRequestsError(error) {
          const code = String(error && error.code ? error.code : '').toLowerCase();
          return code.includes('too-many-requests') || code.includes('quota-exceeded');
        }

        function mapFirebaseError(error) {
          const code = String(error && error.code ? error.code : '').toLowerCase();
          if (code.includes('too-many-requests') || code.includes('quota-exceeded')) return i18n.tooManyRequests;
          if (code.includes('invalid-phone-number') || code.includes('missing-phone-number')) return i18n.invalidPhone;
          if (code.includes('invalid-verification-code')) return i18n.invalidCode;
          if (code.includes('code-expired') || code.includes('session-expired')) return i18n.expiredCode;
          if (code.includes('captcha-check-failed') || code.includes('recaptcha')) return i18n.recaptchaExpired;
          if (code.includes('operation-not-allowed')) return i18n.serviceUnavailable;
          if (code.includes('unauthorized-domain') || code.includes('app-not-authorized')) return i18n.genericError;
          if (code.includes('invalid-app-credential') || code.includes('missing-app-credential')) return i18n.serviceUnavailable;
          if (code.includes('invalid-api-key')) return i18n.serviceUnavailable;
          if (code.includes('invalid-auth-domain')) return i18n.serviceUnavailable;
          return i18n.genericError;
        }

        function clearRecaptchaVerifier() {
          if (recaptchaVerifier) {
            try { recaptchaVerifier.clear(); } catch (_e) {}
            recaptchaVerifier = null;
          }
          const container = document.getElementById('recaptcha-container');
          if (container) container.innerHTML = '';
        }

        function createRecaptchaVerifier() {
          clearRecaptchaVerifier();

          recaptchaVerifier = new RecaptchaVerifier(auth, 'recaptcha-container', {
            size: recaptchaMode === 'normal' ? 'normal' : 'invisible',
            callback: () => {},
            'expired-callback': () => {
              clearRecaptchaVerifier();
            },
          });

          return recaptchaVerifier;
        }

        function getPhoneNumberE164() {
          const raw = String(phoneInput.value || '').trim();
          if (!raw) {
            return '';
          }

          try {
            return iti.getNumber();
          } catch (_e) {
            return '';
          }
        }

        async function sendCode() {
          if (Date.now() < sendCooldownUntil) {
            setStatus(i18n.tooManyRequests, true);
            return;
          }

          const phoneNumber = getPhoneNumberE164();
          if (!phoneNumber || (typeof iti.isValidNumber === 'function' && !iti.isValidNumber())) {
            setStatus(i18n.invalidPhone, true);
            return;
          }

          setButtonsState(true);
          setStatus(i18n.sending);

          try {
            const verifier = createRecaptchaVerifier();
            confirmationResult = await signInWithPhoneNumber(auth, phoneNumber, verifier);
            otpRow.style.display = 'grid';
            if (resendBtn) resendBtn.style.display = 'inline-flex';
            setStatus(i18n.codeSent);
            startSendCooldown(30);
          } catch (error) {
            console.error('Phone sendCode failed', error);
            if (isTooManyRequestsError(error)) {
              startSendCooldown(300);
            }
            setStatus(mapFirebaseError(error), true);
            clearRecaptchaVerifier();
          } finally {
            setButtonsState(false);
          }
        }

        async function verifyCode() {
          const code = String(codeInput.value || '').trim();
          if (!confirmationResult) {
            setStatus(i18n.genericError, true);
            return;
          }
          if (!code) {
            setStatus(i18n.missingCode, true);
            return;
          }

          setButtonsState(true);
          setStatus(i18n.verifying);

          try {
            const credential = await confirmationResult.confirm(code);
            const idToken = await credential.user.getIdToken(true);

            const response = await fetch(verifyEndpoint, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
              },
              body: JSON.stringify({ id_token: idToken, intent: 'login' }),
            });

            const payload = await response.json();
            if (!response.ok || !payload || payload.ok !== true) {
              if (payload && payload.blocked_notice && typeof window.__showBlockedNotice === 'function') {
                window.__showBlockedNotice(payload.blocked_notice);
                setStatus('');
              } else {
                setStatus((payload && payload.message) ? payload.message : i18n.genericError, true);
              }
              return;
            }

            setStatus(i18n.verified, false, true);
            window.location.assign(payload.redirect || @json(route('dashboard')));
          } catch (error) {
            console.error('Phone verifyCode failed', error);
            setStatus(mapFirebaseError(error), true);
          } finally {
            setButtonsState(false);
          }
        }

        sendBtn.addEventListener('click', sendCode);
        verifyBtn.addEventListener('click', verifyCode);
        if (resendBtn) resendBtn.addEventListener('click', sendCode);
        if (Date.now() < sendCooldownUntil) {
          startSendCooldown(Math.ceil((sendCooldownUntil - Date.now()) / 1000));
        } else {
          sessionStorage.removeItem(sendCooldownStorageKey);
        }
      })();
    </script>
  @endif

  @if($firebaseGoogleEnabled)
    <script type="module">
      import { initializeApp, getApps, getApp } from "https://www.gstatic.com/firebasejs/12.11.0/firebase-app.js";
      import { getAuth, GoogleAuthProvider, signInWithPopup } from "https://www.gstatic.com/firebasejs/12.11.0/firebase-auth.js";

      (() => {
        const rawFirebaseConfig = @json($authSettings['firebase']['config'] ?? []);
        const verifyEndpoint = @json(route('auth.firebase-google.verify'));
        const csrfToken = @json(csrf_token());
        const dashboardUrl = @json(route('dashboard'));

        const btn = document.getElementById('firebaseGoogleSignInBtn');
        const statusBox = document.getElementById('firebaseGoogleStatus');
        if (!btn) return;

        const stripSpaces = (value) => String(value ?? '').replace(/\s+/g, '').trim();
        const firebaseConfig = {
          apiKey: stripSpaces(rawFirebaseConfig.apiKey),
          authDomain: String(rawFirebaseConfig.authDomain || '')
            .trim().replace(/^https?:\/\//i, '').replace(/\/+$/, '').split('/')[0].toLowerCase(),
          projectId: stripSpaces(rawFirebaseConfig.projectId),
          appId: stripSpaces(rawFirebaseConfig.appId),
          messagingSenderId: stripSpaces(rawFirebaseConfig.messagingSenderId),
          storageBucket: String(rawFirebaseConfig.storageBucket || '').trim(),
          measurementId: stripSpaces(rawFirebaseConfig.measurementId),
        };

        if (!firebaseConfig.apiKey || !firebaseConfig.authDomain || !firebaseConfig.projectId || !firebaseConfig.appId) {
          btn.disabled = true;
          return;
        }

        let auth;
        try {
          const app = getApps().length ? getApp() : initializeApp(firebaseConfig);
          auth = getAuth(app);
        } catch (e) {
          console.error('Firebase init failed for Google', e);
          btn.disabled = true;
          return;
        }

        function setStatus(msg, isError = false) {
          if (!statusBox) return;
          statusBox.style.display = msg ? 'block' : 'none';
          statusBox.textContent = msg || '';
          statusBox.classList.remove('error', 'success');
          if (isError) statusBox.classList.add('error');
        }

        btn.addEventListener('click', async () => {
          btn.disabled = true;
          setStatus('');
          try {
            const provider = new GoogleAuthProvider();
            provider.addScope('email');
            provider.addScope('profile');
            const result = await signInWithPopup(auth, provider);
            const idToken = await result.user.getIdToken(true);

            const response = await fetch(verifyEndpoint, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
              },
              body: JSON.stringify({ id_token: idToken, intent: 'login' }),
            });

            const payload = await response.json();
            if (!response.ok || !payload?.ok) {
              if (payload?.blocked_notice && typeof window.__showBlockedNotice === 'function') {
                window.__showBlockedNotice(payload.blocked_notice);
                setStatus('');
              } else {
                setStatus(payload?.message || @json(__('ui.backend.auth_firebase_google_failed')), true);
              }
              return;
            }

            window.location.assign(payload.redirect || dashboardUrl);
          } catch (error) {
            if (error.code === 'auth/popup-closed-by-user' || error.code === 'auth/cancelled-popup-request') return;
            console.error('Firebase Google sign-in failed', error);
            setStatus(@json(__('ui.backend.auth_firebase_google_failed')), true);
          } finally {
            btn.disabled = false;
          }
        });
      })();
    </script>
  @endif

  @include('components.footer')
  @include('components.scripts')
</body>
</html>
