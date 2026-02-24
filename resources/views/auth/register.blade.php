<!doctype html>
<html lang="en" data-theme="{{ \App\Support\SeoHelper::defaultTheme() }}">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  @include('components.seo', ['pageTitle' => 'Create Account', 'page' => 'register'])
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

    @media (max-width: 640px) {
      .two-col {
        grid-template-columns: 1fr;
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
    <form class="auth-card reveal" method="POST" action="{{ route('register.submit') }}">
      @csrf
      <h1>Create your account</h1>
      <p class="subtitle">Start free and upgrade when usage grows.</p>

      <a class="google-btn" href="{{ route('auth.social.redirect') }}">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 31.367 32"><path d="M31.367,16.368a18.825,18.825,0,0,0-.276-3.251H16v6.161h8.642a7.4,7.4,0,0,1-3.2,4.862v4H26.6A15.626,15.626,0,0,0,31.367,16.368Z" fill="#4285f4"></path><path d="M16,32a15.317,15.317,0,0,0,10.606-3.862l-5.156-4A9.7,9.7,0,0,1,7.022,19.066H1.7v4.122A16,16,0,0,0,16,32Z" fill="#34a853"></path><path d="M7.016,19.066a9.583,9.583,0,0,1,0-6.125V8.819H1.7a16.017,16.017,0,0,0,0,14.369Z" fill="#fbbc04"></path><path d="M16,6.333a8.694,8.694,0,0,1,6.138,2.4h0l4.568-4.568A15.376,15.376,0,0,0,16,0,16,16,0,0,0,1.7,8.819l5.314,4.122A9.57,9.57,0,0,1,16,6.333Z" fill="#ea4335"></path></svg>
        Sign up with Google
      </a>

      <div class="divider">or create with email</div>

      <div class="form-grid">
        <div>
          <label for="name">Name</label>
          <input type="text" id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
        </div>
        <div>
          <label for="email">Email</label>
          <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email">
        </div>
        <div class="two-col">
          <div>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="new-password">
          </div>
          <div>
            <label for="password_confirmation">Confirm password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
          </div>
        </div>
        <button class="submit-btn" type="submit">Create Account</button>
      </div>

      @if($errors->any())
        <div class="error-box">{{ $errors->first() }}</div>
      @endif

      <p class="auth-footer">
        Already have an account? <a href="{{ route('login') }}">Sign in</a>
      </p>
    </form>
  </main>

  @include('components.footer')
  @include('components.scripts')
</body>
</html>
