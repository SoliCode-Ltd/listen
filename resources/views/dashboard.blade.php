<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Listen Dashboard — Command Center</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{--bg:#070910;--surface:#101725;--surface-2:#141d30;--line:#2a3756;--text:#f4f7ff;--muted:#a8b4d3;--a:#ff8a00;--b:#6f19d8;--ok:#38d39f;--danger:#ff668e}
    *{box-sizing:border-box}body{margin:0;background:radial-gradient(960px 520px at 90% -10%,rgba(111,25,216,.24),transparent 60%),var(--bg);font-family:Inter,system-ui,sans-serif;color:var(--text)}
    .container{width:min(1180px,92%);margin:0 auto}
    .header{position:sticky;top:0;z-index:40;background:rgba(7,9,16,.72);backdrop-filter:blur(9px);border-bottom:1px solid var(--line)}
    .nav{min-height:76px;display:flex;align-items:center;justify-content:space-between;gap:1rem}
    .brand{font-weight:900}.brand span{background:linear-gradient(90deg,var(--a),var(--b));-webkit-background-clip:text;color:transparent}
    .btn{display:inline-block;text-decoration:none;padding:.66rem .9rem;border-radius:.72rem;border:1px solid var(--line);color:var(--text);font-weight:700;background:#11192b}
    .btn.primary{border:0;background:linear-gradient(90deg,var(--a),var(--b))}

    .hero{padding:1rem 0}
    .hero-grid{display:grid;grid-template-columns:1.08fr .92fr;gap:.95rem}
    .card{background:linear-gradient(180deg,var(--surface-2),var(--surface));border:1px solid var(--line);border-radius:1rem;padding:1rem}
    h1{margin:.2rem 0 .5rem;font-size:1.45rem}h2{margin:.2rem 0 .7rem;font-size:1.1rem}h3{margin:.1rem 0 .4rem;font-size:1rem}.muted{color:var(--muted)}

    .meter{margin-top:.75rem}
    .meter-track{height:12px;border-radius:999px;background:#0c1222;border:1px solid var(--line);overflow:hidden}
    .meter-fill{height:100%;background:linear-gradient(90deg,var(--a),var(--b));width:{{ $usagePercent }}%}
    .kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem;margin-top:.85rem}
    .kpi{background:#0f1526;border:1px solid var(--line);border-radius:.82rem;padding:.72rem}
    .kpi b{display:block;font-size:1.22rem}

    .main{display:grid;grid-template-columns:1.02fr .98fr;gap:.95rem;padding-bottom:1rem}
    .form-grid{display:grid;gap:.72rem}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:.72rem}
    label{font-size:.88rem;color:#d5dff8}
    input,select,textarea{width:100%;padding:.72rem;border-radius:.68rem;background:#0b1120;border:1px solid var(--line);color:var(--text)}
    .note{padding:.7rem;border-radius:.7rem;border:1px solid rgba(56,211,159,.35);background:rgba(56,211,159,.12);color:#9bf2d1;margin:.5rem 0 .8rem}

    .side-cards{display:grid;gap:.72rem}
    .mini{display:grid;grid-template-columns:1fr 1fr;gap:.58rem}
    .mini .box{background:#0f1526;border:1px solid var(--line);border-radius:.72rem;padding:.62rem}

    table{width:100%;border-collapse:collapse;font-size:.91rem}
    th,td{padding:.62rem;border-bottom:1px solid #1f2a46;text-align:left;vertical-align:top}
    th{color:#d2dbf4;font-weight:700}
    .status{display:inline-block;padding:.2rem .48rem;border-radius:999px;font-size:.74rem;border:1px solid #394564;color:#dce3f6}
    .status.queued{background:rgba(255,138,0,.12);border-color:rgba(255,138,0,.45)}
    .status.done{background:rgba(56,211,159,.12);border-color:rgba(56,211,159,.45)}
    .status.failed{background:rgba(255,102,142,.12);border-color:rgba(255,102,142,.45)}
    audio{max-width:240px;width:240px;height:32px}

    .footer{color:var(--muted);border-top:1px solid var(--line);padding:1rem 0 1.6rem;margin-top:.3rem}

    @media (max-width:1020px){.hero-grid,.main,.kpis,.two,.mini{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <header class="header">
    <div class="container nav">
      <div class="brand">🎧 <span>Listen</span> Dashboard</div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <small class="muted">{{ auth()->user()->name }} · {{ auth()->user()->email }}</small>
        <a class="btn" href="{{ route('home') }}">Landing</a>
        <a class="btn" href="/listen/api/reader/jobs?limit=20" target="_blank">Jobs API</a>
        <a class="btn primary" href="#queue">Queue new</a>
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
          <p class="muted">Monitor usage, queue new voice jobs, and track generation status from one place.</p>

          <div class="meter">
            <div style="display:flex;justify-content:space-between;margin-bottom:.4rem">
              <small class="muted">Plan usage ({{ $activePlan?->name ?? 'Pro' }})</small>
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
            <div class="box"><small class="muted">Status</small><div><b style="color:var(--ok)">Operational</b></div></div>
          </div>
          <p class="muted" style="margin:.8rem 0 0">Next step: wire background workers + real TTS provider to auto-complete jobs with audio URLs.</p>
        </aside>
      </div>
    </section>

    <section class="main" id="queue">
      <article class="card">
        <h2>Queue a new job</h2>
        @if(session('ok'))
          <div class="note">{{ session('ok') }}</div>
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
              <label>Voice name</label>
              <input name="voice_name" placeholder="default" value="{{ old('voice_name') }}" />
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
          <h3>Integration endpoints</h3>
          <p class="muted">POST <code>/listen/api/reader/queue</code></p>
          <p class="muted">GET <code>/listen/api/reader/jobs</code></p>
        </section>

        <section class="card">
          <h3>Production checklist</h3>
          <ul class="muted" style="margin:.35rem 0 0 1rem;padding:0">
            <li>Auth + user sessions</li>
            <li>Queue worker (Redis/Horizon)</li>
            <li>TTS provider integration</li>
            <li>Audio file storage + signed URLs</li>
            <li>Billing and plan enforcement</li>
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
                <td>{{ \Illuminate\Support\Str::limit($job->input_text, 90) }}</td>
                <td>
                  @if($job->audio_path)
                    <audio controls preload="none" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($job->audio_path) }}"></audio>
                  @elseif($job->status === 'failed')
                    <small style="color:#ffb7ca">{{ $job->error_message ?: 'Generation failed' }}</small>
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
    <div class="container">© {{ date('Y') }} Listen — high-end TTS control dashboard.</div>
  </footer>
</body>
</html>
