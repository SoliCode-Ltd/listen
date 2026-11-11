{{-- "Forgot your password?" from the sign-in page: the account's email, and a link is sent to it.
     The page says the same whether or not the address has an account. --}}
@include('components.page-head', ['pageTitle' => __('ui.seo.forgot_password_title'), 'page' => 'forgot_password'])
@include('auth.partials.password-head')
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
      <h1>{{ __('ui.reset_password.forgot_title') }}</h1>
      <p class="subtitle">{{ __('ui.reset_password.forgot_subtitle') }}</p>

      @if(session('password_reset_notice'))
        <div class="status-box success" role="status">{{ session('password_reset_notice') }}</div>
      @endif

      @if($errors->has('auth'))
        <div class="error-box">{{ $errors->first('auth') }}</div>
      @endif

      @error('email')
        <div class="error-box" role="alert">{{ $message }}</div>
      @enderror

      <form class="form-grid" method="POST" action="{{ route('password.email') }}">
        @csrf
        <div>
          <label for="email">{{ __('ui.auth.email') }}</label>
          <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email" maxlength="255" dir="ltr" @unless(session('password_reset_notice')) autofocus @endunless>
        </div>
        <button class="submit-btn" type="submit">{{ __('ui.reset_password.send_button') }}</button>
      </form>

      <p class="auth-footer">
        <a href="{{ route('login') }}">{{ __('ui.reset_password.back_to_sign_in') }}</a>
      </p>
    </div>
  </main>
  @include('components.auth.legal')
  </div>
  @include('components.auth.showcase', ['authMode' => 'login'])
  </div>

  @include('components.footer')
  @include('components.scripts')
</body>
</html>
