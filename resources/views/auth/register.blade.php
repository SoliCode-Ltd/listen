<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen Register</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#070910;--surface:#101726;--line:#2b3959;--text:#f4f7ff;--muted:#a9b5d4;--a:#ff8a00;--b:#6f19d8;--danger:#ff6d97}
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Inter,system-ui,sans-serif;color:var(--text);background:radial-gradient(900px 460px at 90% -10%,rgba(111,25,216,.26),transparent 60%),var(--bg)}
    .card{width:min(500px,92%);background:linear-gradient(180deg,#131b2e,var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1.1rem}
    h1{margin:.2rem 0 .45rem}.muted{color:var(--muted)}
    label{font-size:.9rem;color:#dbe4fb}input{width:100%;padding:.72rem;border-radius:.7rem;border:1px solid var(--line);background:#0a1020;color:var(--text)}
    .grid{display:grid;gap:.72rem}.two{display:grid;grid-template-columns:1fr 1fr;gap:.72rem}.btn{width:100%;padding:.75rem;border:0;border-radius:.74rem;font-weight:800;color:#fff;background:linear-gradient(90deg,var(--a),var(--b));cursor:pointer}
    .err{margin-top:.5rem;padding:.55rem .65rem;background:rgba(255,109,151,.12);border:1px solid rgba(255,109,151,.4);border-radius:.65rem;color:#ffbdd1;font-size:.9rem}
    a{color:#d9e3ff}
    @media (max-width:700px){.two{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <form class="card" method="POST" action="{{ route('register.submit') }}">
    @csrf
    <h1>Create your Listen account</h1>
    <p class="muted">Start translating and listening to your content instantly.</p>

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
      <div class="err">
        {{ $errors->first() }}
      </div>
    @endif

    <p class="muted" style="margin-top:.8rem">Already have an account? <a href="{{ route('login') }}">Login</a></p>
  </form>
</body>
</html>
