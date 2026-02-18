<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen Login</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#070910;--surface:#101726;--line:#2b3959;--text:#f4f7ff;--muted:#a9b5d4;--a:#ff8a00;--b:#6f19d8;--danger:#ff6d97}
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Inter,system-ui,sans-serif;color:var(--text);background:radial-gradient(900px 460px at 90% -10%,rgba(111,25,216,.26),transparent 60%),var(--bg)}
    .card{width:min(460px,92%);background:linear-gradient(180deg,#131b2e,var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1.1rem}
    h1{margin:.2rem 0 .45rem}.muted{color:var(--muted)}
    label{font-size:.9rem;color:#dbe4fb}input{width:100%;padding:.72rem;border-radius:.7rem;border:1px solid var(--line);background:#0a1020;color:var(--text)}
    .grid{display:grid;gap:.72rem}.btn{width:100%;padding:.75rem;border:0;border-radius:.74rem;font-weight:800;color:#fff;background:linear-gradient(90deg,var(--a),var(--b));cursor:pointer}
    .err{margin-top:.5rem;padding:.55rem .65rem;background:rgba(255,109,151,.12);border:1px solid rgba(255,109,151,.4);border-radius:.65rem;color:#ffbdd1;font-size:.9rem}
    a{color:#d9e3ff}
  </style>
</head>
<body>
  <form class="card" method="POST" action="{{ route('login.submit') }}">
    @csrf
    <h1>Welcome back to Listen</h1>
    <p class="muted">Login to access your TTS dashboard and queued jobs.</p>

    <div class="grid">
      <div>
        <label>Email</label>
        <input type="email" name="email" value="{{ old('email') }}" required>
      </div>
      <div>
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
      <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem">
        <input style="width:auto" type="checkbox" name="remember" value="1"> Remember me
      </label>
      <button class="btn" type="submit">Login</button>
    </div>

    @error('email')<div class="err">{{ $message }}</div>@enderror

    <p class="muted" style="margin-top:.8rem">No account? <a href="{{ route('register') }}">Create one</a></p>
    <p class="muted" style="font-size:.85rem">Default seeded account: test@example.com / password</p>
  </form>
</body>
</html>
