<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#070910;--surface:#101725;--surface-2:#141d30;--line:#2a3756;--text:#f4f7ff;--muted:#a8b4d3;--a:#ff8a00;--b:#6f19d8;--ok:#38d39f;--danger:#ff668e;--shadow:0 16px 40px rgba(0,0,0,.28)}
    html[data-theme="light"]{--bg:#f3f6ff;--surface:#ffffff;--surface-2:#f7f9ff;--line:#dce2f0;--text:#101626;--muted:#53607a;--a:#f78a10;--b:#6119c0;--ok:#1e9e72;--danger:#da3f72;--shadow:0 12px 30px rgba(33,51,91,.12)}
    *{box-sizing:border-box}body{margin:0;background:radial-gradient(960px 520px at 90% -10%,color-mix(in oklab,var(--b) 24%,transparent),transparent 60%),var(--bg);font-family:Inter,system-ui,sans-serif;color:var(--text)}
    .container{width:min(1180px,92%);margin:0 auto}
    .header{position:sticky;top:0;z-index:40;background:color-mix(in oklab,var(--bg) 82%,transparent);backdrop-filter:blur(9px);border-bottom:1px solid var(--line)}
    .nav{min-height:76px;display:flex;align-items:center;justify-content:space-between;gap:1rem}
    .brand{font-weight:900}.brand span{background:linear-gradient(90deg,var(--a),var(--b));-webkit-background-clip:text;color:transparent}
    .btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;padding:.66rem .9rem;border-radius:.72rem;border:1px solid var(--line);color:var(--text);font-weight:700;background:var(--surface);cursor:pointer}
    .btn.primary{border:0;background:linear-gradient(90deg,var(--a),var(--b));color:#fff}

    .hero{padding:1rem 0}
    .hero-grid{display:grid;grid-template-columns:1.08fr .92fr;gap:.95rem}
    .card{background:linear-gradient(180deg,var(--surface-2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem;box-shadow:var(--shadow)}
    h1{margin:.2rem 0 .5rem;font-size:1.45rem}h2{margin:.2rem 0 .7rem;font-size:1.1rem}h3{margin:.1rem 0 .4rem;font-size:1rem}.muted{color:var(--muted)}

    .meter{margin-top:.75rem}
    .meter-track{height:12px;border-radius:999px;background:color-mix(in oklab,var(--surface) 82%,var(--line) 18%);border:1px solid var(--line);overflow:hidden}
    .meter-fill{height:100%;background:linear-gradient(90deg,var(--a),var(--b));width:{{ $usagePercent }}%}
    .kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem;margin-top:.85rem}
    .kpi{background:var(--surface);border:1px solid var(--line);border-radius:.82rem;padding:.72rem}
    .kpi b{display:block;font-size:1.22rem}

    .main{display:grid;grid-template-columns:1.02fr .98fr;gap:.95rem;padding-bottom:1rem}
    .form-grid{display:grid;gap:.72rem}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:.72rem}
    label{font-size:.88rem;color:color-mix(in oklab,var(--text) 85%,var(--muted) 15%)}
    input,select,textarea{width:100%;padding:.72rem;border-radius:.68rem;background:var(--surface);border:1px solid var(--line);color:var(--text)}
    .note{padding:.7rem;border-radius:.7rem;border:1px solid color-mix(in oklab,var(--ok) 55%,var(--line) 45%);background:color-mix(in oklab,var(--ok) 14%,var(--surface) 86%);color:color-mix(in oklab,var(--ok) 80%,var(--text) 20%);margin:.5rem 0 .8rem}
    .error{padding:.7rem;border-radius:.7rem;border:1px solid color-mix(in oklab,var(--danger) 55%,var(--line) 45%);background:color-mix(in oklab,var(--danger) 13%,var(--surface) 87%);color:color-mix(in oklab,var(--danger) 80%,var(--text) 20%);margin:.5rem 0 .8rem}

    .side-cards{display:grid;gap:.72rem}
    .mini{display:grid;grid-template-columns:1fr 1fr;gap:.58rem}
    .mini .box{background:var(--surface);border:1px solid var(--line);border-radius:.72rem;padding:.62rem}

    table{width:100%;border-collapse:collapse;font-size:.91rem}
    th,td{padding:.62rem;border-bottom:1px solid color-mix(in oklab,var(--line) 65%,transparent);text-align:left;vertical-align:top}
    th{color:color-mix(in oklab,var(--text) 85%,var(--muted) 15%);font-weight:700}
    .status{display:inline-block;padding:.2rem .48rem;border-radius:999px;font-size:.74rem;border:1px solid var(--line);color:var(--text)}
    .status.queued{background:color-mix(in oklab,var(--a) 12%,var(--surface) 88%);border-color:color-mix(in oklab,var(--a) 45%,var(--line) 55%)}
    .status.done{background:color-mix(in oklab,var(--ok) 12%,var(--surface) 88%);border-color:color-mix(in oklab,var(--ok) 45%,var(--line) 55%)}
    .status.failed{background:color-mix(in oklab,var(--danger) 12%,var(--surface) 88%);border-color:color-mix(in oklab,var(--danger) 45%,var(--line) 55%)}
    audio{max-width:240px;width:240px;height:32px}

    .footer{color:var(--muted);border-top:1px solid var(--line);padding:1rem 0 1.6rem;margin-top:.3rem}

    @media (max-width:1020px){.hero-grid,.main,.kpis,.two,.mini{grid-template-columns:1fr}}
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
      <div class="brand">🎧 <span>Listen</span> Dashboard</div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <small class="muted">{{ auth()->user()->name }} · {{ auth()->user()->email }}</small>
        <button type="button" class="btn" id="themeToggle">Theme</button>
        <a class="btn" href="{{ route('home') }}">Website</a>
        <a class="btn primary" href="#queue">New Job</a>
        <form method="POST" action="{{ route('logout') }}" style="margin:0">
          @csrf
          <button class="btn" type="submit">Logout</button>
        </form>
      </div>
    </div>
  </header>

  <main class="container">
    <section class="hero">
      <div class="hero-grid">
        <article class="card">
          <h1>Command Center</h1>
          <p class="muted">Manage queued voice generation, monitor usage, and keep delivery smooth for your customers.</p>

          <div class="meter">
            <div style="display:flex;justify-content:space-between;margin-bottom:.4rem">
              <small class="muted">Plan usage ({{ $activePlan?->name ?? 'Free' }})</small>
              <small>{{ $usage->total_minutes }} / {{ $planMinutes }} min ({{ $usagePercent }}%)</small>
            </div>
            <div class="meter-track"><div class="meter-fill"></div></div>
          </div>

          <div class="kpis">
            <div class="kpi"><small class="muted">Requests</small><b>{{ $usage->total_requests }}</b></div>
            <div class="kpi"><small class="muted">Characters</small><b>{{ number_format($usage->total_chars) }}</b></div>
            <div class="kpi"><small class="muted">Minutes</small><b>{{ $usage->total_minutes }}</b></div>
            <div class="kpi"><small class="muted">Total Jobs</small><b>{{ $stats['total'] }}</b></div>
          </div>
        </article>

        <aside class="card">
          <h2>Pipeline health</h2>
          <div class="mini">
            <div class="box"><small class="muted">Queued</small><div><b>{{ $stats['queued'] }}</b></div></div>
            <div class="box"><small class="muted">Done</small><div><b>{{ $stats['done'] }}</b></div></div>
            <div class="box"><small class="muted">Failed</small><div><b>{{ $stats['failed'] }}</b></div></div>
            <div class="box"><small class="muted">Provider</small><div><b>Google Cloud TTS</b></div></div>
          </div>
          <p class="muted" style="margin:.8rem 0 0">This workspace is optimized for subscription-based text-to-speech delivery.</p>
        </aside>
      </div>
    </section>

    <section class="main" id="queue">
      <article class="card">
        <h2>Queue a new job</h2>
        @if(session('ok'))
          <div class="note">{{ session('ok') }}</div>
        @endif
        @if($errors->any())
          <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('dashboard.queue') }}" class="form-grid">
          @csrf
          <div class="two">
            <div>
              <label>Title (optional)</label>
              <input name="title" placeholder="Invoice follow-up" value="{{ old('title') }}" />
            </div>
            <div>
              <label>Source type</label>
              <select name="source_type">
                <option value="email">Email</option>
                <option value="message">Message</option>
                <option value="file">File</option>
              </select>
            </div>
          </div>

          <div>
            <label>Text content</label>
            <textarea name="content" rows="8" placeholder="Paste your content..." required>{{ old('content') }}</textarea>
          </div>

          <div class="two">
            <div>
              <label>Target language</label>
              <select name="target_language">
                <option value="ar">Arabic</option>
                <option value="en">English</option>
              </select>
            </div>
            <div>
              <label>Voice name (optional)</label>
              <input name="voice_name" placeholder="ar-XA-Wavenet-D" value="{{ old('voice_name') }}" />
            </div>
          </div>

          <div>
            <label>Translate first</label>
            <select name="translate">
              <option value="1">Yes</option>
              <option value="0">No</option>
            </select>
          </div>

          <button class="btn primary" type="submit">Queue Voice Job</button>
        </form>
      </article>

      <aside class="side-cards">
        <section class="card">
          <h3>Current plan</h3>
          <p class="muted">{{ $activePlan?->name ?? 'Free' }}</p>
          <p class="muted">Monthly minutes: {{ $planMinutes }}</p>
          <p class="muted">Max chars per request: {{ $activePlan?->max_chars_per_request ?? 600 }}</p>
        </section>

        <section class="card">
          <h3>SaaS checklist</h3>
          <ul class="muted" style="margin:.35rem 0 0 1rem;padding:0">
            <li>Auth and isolated user data</li>
            <li>Plan-based usage enforcement</li>
            <li>Queued background generation</li>
            <li>Persistent audio storage</li>
            <li>Ready for payment integration</li>
          </ul>
        </section>
      </aside>
    </section>

    <section class="card" style="margin-bottom:1rem">
      <h2>Recent jobs</h2>
      <div style="overflow:auto">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Status</th>
              <th>Flow</th>
              <th>Translate</th>
              <th>Estimated</th>
              <th>Preview</th>
              <th>Audio</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            @forelse($jobs as $job)
              <tr>
                <td>#{{ $job->id }}</td>
                <td><span class="status {{ $job->status }}">{{ strtoupper($job->status) }}</span></td>
                <td>{{ $job->source_language ?? '-' }} → {{ $job->target_language }}</td>
                <td>{{ $job->translate ? 'Yes' : 'No' }}</td>
                <td>{{ $job->duration_seconds ? $job->duration_seconds . 's' : '-' }}</td>
                <td>{{ \\Illuminate\\Support\\Str::limit($job->input_text, 90) }}</td>
                <td>
                  @if($job->audio_path)
                    <audio controls preload="none" src="{{ \\Illuminate\\Support\\Facades\\Storage::disk('public')->url($job->audio_path) }}"></audio>
                  @elseif($job->status === 'failed')
                    <small style="color:color-mix(in oklab,var(--danger) 80%,var(--text) 20%)">{{ $job->error_message ?: 'Generation failed' }}</small>
                  @else
                    <small class="muted">Processing...</small>
                  @endif
                </td>
                <td>{{ $job->created_at?->diffForHumans() }}</td>
              </tr>
            @empty
              <tr><td colspan="8" class="muted">No jobs yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container">© {{ date('Y') }} Listen — SaaS Voice Platform.</div>
  </footer>

  <script>
    const toggle = document.getElementById('themeToggle');
    toggle?.addEventListener('click', () => {
      const current = document.documentElement.getAttribute('data-theme') || 'dark';
      const next = current === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      localStorage.setItem('listen_theme', next);
    });
  </script>
</body>
</html>
