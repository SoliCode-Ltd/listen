<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen | Sign In</title>
  <link rel="icon" type="image/svg+xml" href="{{ asset('icons/headphones.svg') }}" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  @include('components.styles')
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
      const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
      document.documentElement.setAttribute('data-theme', saved || (prefersLight ? 'light' : 'dark'));
    })();
  </script>
</head>
<body>
  @include('components.header')

  <main class="container auth-page">
    <form class="auth-card reveal" method="POST" action="{{ route('login.submit') }}">
      @csrf
      <h1>Welcome back</h1>
      <p class="subtitle">Sign in to your Listen workspace.</p>

      <a class="google-btn" href="{{ route('auth.social.redirect') }}">
        <svg width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="currentColor" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="currentColor" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="currentColor" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
        Continue with Social Login
      </a>

      <div class="divider">or sign in with email</div>

      <div class="form-grid">
        <div>
          <label for="email">Email</label>
          <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email">
        </div>
        <div>
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <label class="remember-row">
          <input type="checkbox" name="remember" value="1">
          Remember me
        </label>
        <button class="submit-btn" type="submit">Sign In</button>
      </div>

      @error('email')
        <div class="error-box">{{ $message }}</div>
      @enderror

      <p class="auth-footer">
        No account? <a href="{{ route('register') }}">Create one</a>
      </p>
    </form>
  </main>

  @include('components.footer')
  @include('components.scripts')
</body>
</html>
