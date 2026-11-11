{{-- The page the emailed link opens: a new password for the account the link was made for. --}}
@include('components.page-head', ['pageTitle' => __('ui.seo.reset_password_title'), 'page' => 'reset_password'])
@include('auth.partials.password-head')
</head>
<body class="auth-split-body">
  @include('components.header')

  <div class="auth-split">
  <div class="auth-pane">
  @include('components.auth.topbar')
  <main class="container auth-page">
    <div class="auth-card reveal">
      <h1>{{ __('ui.reset_password.reset_title') }}</h1>
      <p class="subtitle">{!! __('ui.reset_password.reset_subtitle', ['email' => '<bdi>'.e($email).'</bdi>']) !!}</p>

      @if($errors->any())
        <div class="error-box" role="alert">{{ $errors->first('password') ?: $errors->first() }}</div>
      @endif

      <form class="form-grid" method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ $email }}">
        <div>
          <label for="password">{{ __('ui.reset_password.new_password') }}</label>
          <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
          <p class="field-hint">{{ __('ui.reset_password.password_hint') }}</p>
        </div>
        <div>
          <label for="password_confirmation">{{ __('ui.reset_password.confirm_password') }}</label>
          <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8" autocomplete="new-password">
        </div>
        <button class="submit-btn" type="submit">{{ __('ui.reset_password.reset_button') }}</button>
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
