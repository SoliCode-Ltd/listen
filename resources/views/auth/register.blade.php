<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen | Register</title>
  <link rel="icon" type="image/svg+xml" href="{{ asset('icons/headphones.svg') }}" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#070910;--surface:#11182a;--surface2:#15213a;--line:#2b3a5d;--text:#f4f7ff;--muted:#9fb0d4;--a:#fb8308;--b:#5906b2;--danger:#ee4a77}
    html[data-theme="light"]{--bg:#eff3ff;--surface:#fff;--surface2:#f7f9ff;--line:#d8e2f1;--text:#121a2d;--muted:#5b6c89}
    *{box-sizing:border-box}html,body{margin:0;padding:0;scroll-behavior:smooth}
    body{font-family:Inter,system-ui,sans-serif;background:radial-gradient(1200px 500px at 92% -20%,color-mix(in oklab,var(--b) 25%,transparent),transparent 60%),radial-gradient(900px 420px at -5% 0%,color-mix(in oklab,var(--a) 18%,transparent),transparent 58%),var(--bg);color:var(--text)}
    .container{width:min(1200px,92%);margin:auto}

    .header{position:sticky;top:0;z-index:60;background:color-mix(in oklab,var(--bg) 82%,transparent);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
    .nav{min-height:76px;display:flex;align-items:center;justify-content:space-between;gap:.8rem}
    .brand{display:flex;align-items:center;gap:.55rem;font-weight:900;text-decoration:none;color:var(--text)}
    .brand-icon{width:1.2rem;height:1.2rem;display:block}
    .brand-icon svg{width:100%;height:100%;display:block;fill:currentColor}
    .brand span{background:linear-gradient(90deg,var(--a),var(--b));-webkit-background-clip:text;background-clip:text;color:transparent}

    .desktop-nav,.desktop-actions{display:flex;align-items:center;gap:.55rem}
    .desktop-nav a,.mobile-menu a{text-decoration:none;color:var(--muted);font-weight:600;padding:.5rem .62rem;border-radius:.6rem}
    .desktop-nav a:hover,.mobile-menu a:hover{color:var(--text);background:color-mix(in oklab,var(--surface) 85%,transparent)}

    .btn{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;padding:.68rem .9rem;border-radius:.72rem;border:1px solid var(--line);background:var(--surface);color:var(--text);font-weight:700;text-decoration:none;cursor:pointer}
    .btn.primary{border:0;background:linear-gradient(90deg,var(--a),var(--b));color:#fff}
    .theme-btn{padding:.58rem;min-width:2.4rem}
    .theme-btn .icon{width:1rem;height:1rem;stroke:currentColor;fill:none;stroke-width:1.9}
    .theme-btn .sun{display:none}
    html[data-theme="light"] .theme-btn .sun{display:block}
    html[data-theme="light"] .theme-btn .moon{display:none}

    .mobile-controls{display:none;align-items:center;gap:.45rem}
    .mobile-menu{display:none;padding:0 0 1rem}
    .mobile-menu.open{display:grid;gap:.35rem}

    .auth-wrap{min-height:calc(100vh - 150px);display:grid;place-items:center;padding:2rem 0}
    .card{width:min(520px,100%);background:linear-gradient(180deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem}
    h1{margin:.2rem 0 .45rem}
    .muted{color:var(--muted)}
    label{font-size:.88rem;color:color-mix(in oklab,var(--text) 86%,var(--muted) 14%)}
    input{width:100%;padding:.7rem;border-radius:.68rem;border:1px solid var(--line);background:var(--surface);color:var(--text)}
    .grid{display:grid;gap:.62rem}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:.62rem}
    .submit{width:100%;padding:.78rem;border:0;border-radius:.74rem;font-weight:800;color:#fff;background:linear-gradient(90deg,var(--a),var(--b));cursor:pointer}
    .google-btn{width:100%;display:inline-flex;justify-content:center;align-items:center;gap:.5rem;padding:.74rem;border-radius:.74rem;border:1px solid var(--line);background:var(--surface);color:var(--text);text-decoration:none;font-weight:700}
    .divider{display:flex;align-items:center;gap:.6rem;color:var(--muted);font-size:.8rem}
    .divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--line)}
    .err{margin-top:.5rem;padding:.55rem .65rem;background:color-mix(in oklab,var(--danger) 14%,var(--surface) 86%);border:1px solid color-mix(in oklab,var(--danger) 45%,var(--line) 55%);border-radius:.65rem;color:color-mix(in oklab,var(--danger) 80%,var(--text) 20%);font-size:.9rem}

    .foot{border-top:1px solid var(--line);padding:1rem 0 2rem;color:var(--muted)}

    @media (max-width:960px){
      .desktop-nav,.desktop-actions{display:none}
      .mobile-controls{display:flex}
      .two{grid-template-columns:1fr}
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
  <header class="header">
    <div class="container nav">
      <a class="brand" href="{{ route('home') }}" aria-label="Listen home">
        <span class="brand-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" role="img"><path d="M12 3a8 8 0 0 0-8 8v6a3 3 0 0 0 3 3h1.5a2.5 2.5 0 0 0 2.5-2.5v-3A2.5 2.5 0 0 0 8.5 12H6a6 6 0 1 1 12 0h-2.5a2.5 2.5 0 0 0-2.5 2.5v3a2.5 2.5 0 0 0 2.5 2.5H17a3 3 0 0 0 3-3v-6a8 8 0 0 0-8-8Z"/></svg>
        </span>
        <span>Listen</span>
      </a>
      <nav class="desktop-nav">
        <a href="{{ route('home') }}">Home</a>
        <a href="{{ route('demo.page') }}">Demo</a>
        <a href="{{ route('pricing') }}">Pricing</a>
        <a href="{{ route('extension.page') }}">Chrome Extension</a>
      </nav>
      <div class="desktop-actions">
        <button type="button" class="btn theme-btn" id="themeToggleDesktop" aria-label="Toggle theme">
          <svg class="icon sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg>
          <svg class="icon moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 1 0 9.8 9.8z"></path></svg>
        </button>
        <a class="btn" href="{{ route('login') }}">Login</a>
      </div>
      <div class="mobile-controls">
        <button type="button" class="btn theme-btn" id="themeToggleMobile" aria-label="Toggle theme">
          <svg class="icon sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg>
          <svg class="icon moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 1 0 9.8 9.8z"></path></svg>
        </button>
        <button type="button" class="btn" id="mobileMenuBtn" aria-label="Menu">Menu</button>
      </div>
    </div>
    <div class="container mobile-menu" id="mobileMenu">
      <a href="{{ route('home') }}">Home</a>
      <a href="{{ route('demo.page') }}">Demo</a>
      <a href="{{ route('pricing') }}">Pricing</a>
      <a href="{{ route('extension.page') }}">Chrome Extension</a>
      <a href="{{ route('login') }}">Login</a>
    </div>
  </header>

  <main class="container auth-wrap">
    <form class="card" method="POST" action="{{ route('register.submit') }}">
      @csrf
      <h1>Create your account</h1>
      <p class="muted" style="margin:.1rem 0 .75rem">Start free and upgrade when usage grows.</p>

      <a class="google-btn" href="{{ route('auth.google.redirect') }}">G Sign up with Google</a>
      <div class="divider" style="margin:.72rem 0">or create with email</div>

      <div class="grid">
        <div>
          <label>Name</label>
          <input type="text" name="name" value="{{ old('name') }}" required>
        </div>
        <div>
          <label>Email</label>
          <input type="email" name="email" value="{{ old('email') }}" required>
        </div>
        <div class="two">
          <div>
            <label>Password</label>
            <input type="password" name="password" required>
          </div>
          <div>
            <label>Confirm password</label>
            <input type="password" name="password_confirmation" required>
          </div>
        </div>
        <button class="submit" type="submit">Create account</button>
      </div>

      @if($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
      <p class="muted" style="margin-top:.75rem">Already have an account? <a href="{{ route('login') }}" style="color:inherit">Login</a></p>
    </form>
  </main>

  <footer class="foot"><div class="container">&copy; {{ date('Y') }} Listen. Crafted by SoliCode Ltd.</div></footer>

  <script>
    const setTheme = () => {
      const current = document.documentElement.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('listen_theme', next);
    };
    document.getElementById('themeToggleDesktop')?.addEventListener('click', setTheme);
    document.getElementById('themeToggleMobile')?.addEventListener('click', setTheme);

    const menuBtn = document.getElementById('mobileMenuBtn');
    const mobileMenu = document.getElementById('mobileMenu');
    menuBtn?.addEventListener('click', () => mobileMenu?.classList.toggle('open'));
  </script>
</body>
</html>


