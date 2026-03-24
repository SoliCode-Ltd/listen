@include('components.page-head', ['pageTitle' => __('ui.seo.register_title'), 'page' => 'register'])
@php
  $authSettings = is_array($authSettings ?? null) ? $authSettings : [];
  $emailEnabled = (bool) ($authSettings['email_enabled'] ?? true);
  $googleEnabled = (bool) ($authSettings['google_enabled'] ?? true);
  $phoneEnabled = (bool) ($authSettings['phone_enabled'] ?? false);
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
      width: min(480px, 100%);
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
    .two-col {
      display: grid;
      grid-template-columns: 1fr 1fr;
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
      .two-col,
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
</head>
<body>
  @include('components.header')

  <main class="container auth-page">
    <div class="auth-card reveal">
      <h1>{{ __('ui.auth.register_title') }}</h1>
      <p class="subtitle">{{ __('ui.auth.register_subtitle') }}</p>

      @if($errors->has('auth'))
        <div class="error-box">{{ $errors->first('auth') }}</div>
      @endif

      @if($googleEnabled)
        <a class="google-btn" href="{{ route('auth.social.redirect') }}">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 31.367 32"><path d="M31.367,16.368a18.825,18.825,0,0,0-.276-3.251H16v6.161h8.642a7.4,7.4,0,0,1-3.2,4.862v4H26.6A15.626,15.626,0,0,0,31.367,16.368Z" fill="#4285f4"></path><path d="M16,32a15.317,15.317,0,0,0,10.606-3.862l-5.156-4A9.7,9.7,0,0,1,7.022,19.066H1.7v4.122A16,16,0,0,0,16,32Z" fill="#34a853"></path><path d="M7.016,19.066a9.583,9.583,0,0,1,0-6.125V8.819H1.7a16.017,16.017,0,0,0,0,14.369Z" fill="#fbbc04"></path><path d="M16,6.333a8.694,8.694,0,0,1,6.138,2.4h0l4.568-4.568A15.376,15.376,0,0,0,16,0,16,16,0,0,0,1.7,8.819l5.314,4.122A9.57,9.57,0,0,1,16,6.333Z" fill="#ea4335"></path></svg>
          {{ __('ui.auth.signup_google') }}
        </a>
      @endif

      @if($phoneEnabled)
        <div class="divider">{{ __('ui.auth.or_phone_signup') }}</div>
        <div class="phone-grid">
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
            <button type="button" class="submit-btn" id="verifyOtpBtn">{{ __('ui.auth.verify_and_create_account') }}</button>
          </div>

          <button type="button" class="phone-btn" id="resendOtpBtn" style="display:none;">{{ __('ui.auth.resend_code') }}</button>
          <div id="recaptcha-container"></div>
          <div class="status-box" id="phoneAuthStatus" style="display:none;"></div>
        </div>
      @endif

      @if($emailEnabled)
        <div class="divider">{{ __('ui.auth.or_email_signup') }}</div>

        <form class="form-grid" method="POST" action="{{ route('register.submit') }}">
          @csrf
          <div>
            <label for="name">{{ __('ui.auth.name') }}</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
          </div>
          <div>
            <label for="email">{{ __('ui.auth.email') }}</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email">
          </div>
          <div class="two-col">
            <div>
              <label for="password">{{ __('ui.auth.password') }}</label>
              <input type="password" id="password" name="password" required autocomplete="new-password">
            </div>
            <div>
              <label for="password_confirmation">{{ __('ui.auth.confirm_password') }}</label>
              <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
            </div>
          </div>
          <button class="submit-btn" type="submit">{{ __('ui.auth.create_account') }}</button>
        </form>

        @if($errors->any())
          <div class="error-box">{{ $errors->first() }}</div>
        @endif
      @endif

      <p class="auth-footer">
        {{ __('ui.auth.already_account') }} <a href="{{ route('login') }}">{{ __('ui.nav.sign_in') }}</a>
      </p>
    </div>
  </main>

  @if($phoneEnabled)
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.0/build/js/intlTelInput.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@23.8.0/build/js/utils.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.12.5/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/10.12.5/firebase-auth-compat.js"></script>
    <script>
      (() => {
        const firebaseConfig = @json($authSettings['firebase']['config'] ?? []);
        const recaptchaMode = @json($authSettings['firebase']['recaptcha_mode'] ?? 'invisible');
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

        if (!sendBtn || !verifyBtn || !phoneInput || !codeInput || !otpRow || !statusBox || typeof firebase === 'undefined' || typeof window.intlTelInput !== 'function') {
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

        try {
          if (!firebase.apps.length) {
            firebase.initializeApp(firebaseConfig);
          }
        } catch (error) {
          setStatus(i18n.genericError, true);
          return;
        }

        const auth = firebase.auth();

        function setStatus(message, isError = false, isSuccess = false) {
          statusBox.style.display = message ? 'block' : 'none';
          statusBox.textContent = message || '';
          statusBox.classList.remove('error', 'success');
          if (isError) statusBox.classList.add('error');
          if (isSuccess) statusBox.classList.add('success');
        }

        function setButtonsState(disabled) {
          sendBtn.disabled = disabled;
          verifyBtn.disabled = disabled;
          if (resendBtn) resendBtn.disabled = disabled;
        }

        function mapFirebaseError(error) {
          const code = String(error && error.code ? error.code : '').toLowerCase();
          if (code.includes('too-many-requests') || code.includes('quota-exceeded')) return i18n.tooManyRequests;
          if (code.includes('invalid-phone-number') || code.includes('missing-phone-number')) return i18n.invalidPhone;
          if (code.includes('invalid-verification-code')) return i18n.invalidCode;
          if (code.includes('code-expired') || code.includes('session-expired')) return i18n.expiredCode;
          if (code.includes('captcha-check-failed') || code.includes('recaptcha')) return i18n.recaptchaExpired;
          return i18n.genericError;
        }

        function ensureRecaptchaVerifier() {
          if (recaptchaVerifier) return recaptchaVerifier;

          recaptchaVerifier = new firebase.auth.RecaptchaVerifier('recaptcha-container', {
            size: recaptchaMode === 'normal' ? 'normal' : 'invisible',
            callback: () => {},
            'expired-callback': () => {
              setStatus(i18n.recaptchaExpired, true);
              if (recaptchaVerifier && typeof recaptchaVerifier.clear === 'function') {
                recaptchaVerifier.clear();
              }
              recaptchaVerifier = null;
            },
          }, auth);

          recaptchaVerifier.render().catch(() => setStatus(i18n.recaptchaExpired, true));

          return recaptchaVerifier;
        }

        function getPhoneNumberE164() {
          const raw = String(phoneInput.value || '').trim();
          if (!raw) return '';

          try {
            return iti.getNumber();
          } catch (_e) {
            return '';
          }
        }

        async function sendCode() {
          const phoneNumber = getPhoneNumberE164();
          if (!phoneNumber || (typeof iti.isValidNumber === 'function' && !iti.isValidNumber())) {
            setStatus(i18n.invalidPhone, true);
            return;
          }

          setButtonsState(true);
          setStatus(i18n.sending);

          try {
            const verifier = ensureRecaptchaVerifier();
            confirmationResult = await auth.signInWithPhoneNumber(phoneNumber, verifier);
            otpRow.style.display = 'grid';
            if (resendBtn) resendBtn.style.display = 'inline-flex';
            setStatus(i18n.codeSent);
          } catch (error) {
            setStatus(mapFirebaseError(error), true);
            if (recaptchaVerifier && typeof recaptchaVerifier.clear === 'function') {
              recaptchaVerifier.clear();
            }
            recaptchaVerifier = null;
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
              body: JSON.stringify({ id_token: idToken, intent: 'register' }),
            });

            const payload = await response.json();
            if (!response.ok || !payload || payload.ok !== true) {
              throw new Error(payload && payload.message ? payload.message : i18n.genericError);
            }

            setStatus(i18n.verified, false, true);
            window.location.assign(payload.redirect || @json(route('dashboard')));
          } catch (error) {
            const message = error instanceof Error && error.message ? error.message : mapFirebaseError(error);
            setStatus(message, true);
          } finally {
            setButtonsState(false);
          }
        }

        sendBtn.addEventListener('click', sendCode);
        verifyBtn.addEventListener('click', verifyCode);
        if (resendBtn) resendBtn.addEventListener('click', sendCode);
      })();
    </script>
  @endif

  @include('components.footer')
  @include('components.scripts')
</body>
</html>
