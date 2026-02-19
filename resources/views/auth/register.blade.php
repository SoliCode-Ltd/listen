<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen Register</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#070910;--surface:#101726;--line:#2b3959;--text:#f4f7ff;--muted:#a9b5d4;--a:#ff8a00;--b:#6f19d8;--danger:#ff6d97}
    html[data-theme="light"]{--bg:#f3f6ff;--surface:#ffffff;--line:#dce2f0;--text:#101626;--muted:#53607a;--a:#f78a10;--b:#6119c0;--danger:#da3f72}
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Inter,system-ui,sans-serif;color:var(--text);background:radial-gradient(900px 460px at 90% -10%,color-mix(in oklab,var(--b) 26%,transparent),transparent 60%),var(--bg)}
    .card{width:min(500px,92%);background:linear-gradient(180deg,color-mix(in oklab,var(--surface) 80%,var(--line) 20%),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1.1rem}
    h1{margin:.2rem 0 .45rem}.muted{color:var(--muted)}
    label{font-size:.9rem;color:color-mix(in oklab,var(--text) 86%,var(--muted) 14%)}input{width:100%;padding:.72rem;border-radius:.7rem;border:1px solid var(--line);background:var(--surface);color:var(--text)}
    .grid{display:grid;gap:.72rem}.two{display:grid;grid-template-columns:1fr 1fr;gap:.72rem}
    .btn{width:100%;padding:.75rem;border:0;border-radius:.74rem;font-weight:800;color:#fff;background:linear-gradient(90deg,var(--a),var(--b));cursor:pointer}
    .btn-theme{padding:.55rem .72rem;border-radius:.66rem;border:1px solid var(--line);background:var(--surface);color:var(--text);font-weight:700}
    .err{margin-top:.5rem;padding:.55rem .65rem;background:color-mix(in oklab,var(--danger) 14%,var(--surface) 86%);border:1px solid color-mix(in oklab,var(--danger) 45%,var(--line) 55%);border-radius:.65rem;color:color-mix(in oklab,var(--danger) 80%,var(--text) 20%);font-size:.9rem}
    a{color:color-mix(in oklab,var(--b) 65%,var(--text) 35%);font-weight:700}
    @media (max-width:700px){.two{grid-template-columns:1fr}}
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
  <form class="card" method="POST" action="{{ route('register.submit') }}">
    @csrf
    <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem">
      <h1>Create your Listen account</h1>
      <button class="btn-theme" type="button" id="themeToggle" aria-label="Toggle theme">🌓</button>
    </div>
    <p class="muted">Start your SaaS voice workspace in minutes.</p>

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
      <button class="btn" type="submit">Create account</button>
    </div>

    @if($errors->any())
      <div class="err">{{ $errors->first() }}</div>
    @endif

    <p class="muted" style="margin-top:.8rem">Already have an account? <a href="{{ route('login') }}">Login</a></p>
  </form>

  <script>
    document.getElementById('themeToggle')?.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('listen_theme', next);
    });
  </script>
</body>
</html>
