@include('components.page-head', ['pageTitle' => __('ui.dub.seo_title'), 'page' => 'dub'])
  <style>
    .dub-main{padding:1.5rem 0 3.5rem}
    .dub-hero{text-align:center;max-width:720px;margin:0 auto 2rem}
    .dub-badge{display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .8rem;border-radius:var(--radius-full);background:linear-gradient(135deg,var(--a),var(--b));color:#fff;font-size:.82rem;font-weight:700;margin-bottom:.9rem}
    .dub-hero h1{margin:0 0 .6rem;font-size:clamp(1.9rem,4.4vw,2.8rem);font-weight:800;line-height:1.15}
    .dub-hero p{margin:0;color:var(--muted);font-size:1.07rem;line-height:1.6}

    .dub-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:.8rem;max-width:860px;margin:0 auto 2rem;padding:0;list-style:none;counter-reset:step}
    .dub-steps li{counter-increment:step;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:1rem 1.1rem;display:flex;gap:.75rem;align-items:flex-start}
    .dub-steps li::before{content:counter(step);flex:0 0 2rem;height:2rem;border-radius:999px;background:linear-gradient(135deg,var(--a),var(--b));color:#fff;font-weight:800;display:grid;place-items:center}
    .dub-steps strong{display:block;margin-bottom:.15rem}
    .dub-steps span{color:var(--muted);font-size:.9rem;line-height:1.45}

    .dub-card{max-width:860px;margin:0 auto 2rem;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);box-shadow:var(--shadow);padding:1.5rem}
    .dub-grid{display:grid;grid-template-columns:1.1fr 1fr;gap:1.4rem}
    .dub-drop{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:.5rem;min-height:250px;border:2px dashed var(--line-strong);border-radius:var(--radius-lg);padding:1.2rem;cursor:pointer;transition:border-color .2s,background .2s;background:var(--surface2)}
    .dub-drop:hover,.dub-drop.is-over{border-color:var(--a);background:var(--brand-subtle)}
    .dub-drop svg{width:44px;height:44px;color:var(--a)}
    .dub-drop strong{font-size:1.05rem}
    .dub-drop small{color:var(--muted)}
    .dub-drop input{position:absolute;inset:0;opacity:0;cursor:pointer}
    .dub-drop video{width:100%;max-height:260px;border-radius:var(--radius);background:#000}
    .dub-drop.has-video{padding:.6rem;cursor:default}
    .dub-drop.has-video input{display:none}
    .dub-drop [hidden]{display:none!important}
    #dubDropEmpty{display:flex;flex-direction:column;align-items:center;gap:.45rem}
    .dub-file{display:flex;justify-content:space-between;gap:.5rem;width:100%;font-size:.86rem;color:var(--muted)}
    .dub-file button{background:none;border:0;color:var(--accent-text);font-weight:700;cursor:pointer;padding:0}

    .dub-field{margin-bottom:1rem}
    .dub-field label,.dub-field .dub-label{display:block;font-weight:700;margin-bottom:.4rem;font-size:.93rem}
    .dub-field select{width:100%;padding:.65rem .75rem;border:1px solid var(--line-strong);border-radius:var(--radius);background:var(--surface);color:var(--text);font:inherit}
    .dub-voices{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:.45rem}
    .dub-voice{position:relative}
    .dub-voice input{position:absolute;opacity:0}
    .dub-voice span{display:flex;flex-direction:column;padding:.5rem .6rem;border:1px solid var(--line-strong);border-radius:var(--radius);cursor:pointer;font-weight:700;font-size:.9rem;transition:border-color .15s,background .15s}
    .dub-voice small{font-weight:500;color:var(--muted);font-size:.76rem}
    .dub-voice input:checked + span{border-color:var(--a);background:color-mix(in oklab,var(--a) 22%,var(--surface));box-shadow:0 0 0 1px var(--a)}
    .dub-voice input:focus-visible + span{outline:2px solid var(--a);outline-offset:2px}
    .dub-two{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}

    .dub-cost{display:flex;gap:.6rem;align-items:flex-start;padding:.75rem .9rem;border-radius:var(--radius);background:var(--surface2);border:1px solid var(--line);font-size:.9rem;line-height:1.5;margin:.3rem 0 1rem}
    .dub-cost svg{flex:0 0 20px;width:20px;height:20px;color:var(--a);margin-top:.1rem}
    .dub-error{color:var(--danger);font-weight:600;margin:.2rem 0 .8rem;font-size:.92rem}
    .dub-submit{width:100%}
    .dub-submit:disabled{opacity:.55;cursor:not-allowed}

    .dub-note{max-width:860px;margin:0 auto 1.2rem;padding:1rem 1.2rem;border-radius:var(--radius-lg);border:1px solid var(--line);background:var(--surface)}
    .dub-note h2{margin:0 0 .35rem;font-size:1.15rem}
    .dub-note p{margin:0 0 .8rem;color:var(--muted)}
    .dub-note .actions{display:flex;gap:.6rem;flex-wrap:wrap}

    .dub-list{max-width:860px;margin:0 auto}
    .dub-list h2{font-size:1.3rem;margin:0 0 .3rem}
    .dub-list > p{color:var(--muted);margin:0 0 1rem;font-size:.92rem}
    .dub-item{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:1rem;margin-bottom:1rem;box-shadow:var(--shadow-sm)}
    .dub-item-head{display:flex;justify-content:space-between;align-items:center;gap:.6rem;margin-bottom:.7rem;flex-wrap:wrap}
    .dub-item-head strong{overflow-wrap:anywhere}
    .dub-item-head small{color:var(--muted)}
    .dub-item video{width:100%;max-height:480px;border-radius:var(--radius);background:#000;display:block}
    .dub-item-actions{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.8rem;align-items:center}
    .dub-item-actions small{color:var(--muted)}
    .dub-progress{display:grid;gap:.5rem;padding:.4rem 0}
    .dub-progress div{display:flex;gap:.6rem;align-items:center;color:var(--muted)}
    .dub-progress div::before{content:'';width:12px;height:12px;border-radius:999px;border:2px solid var(--line-strong);flex:0 0 12px}
    .dub-progress div.is-on{color:var(--text);font-weight:700}
    .dub-progress div.is-on::before{border-color:var(--a);border-top-color:transparent;animation:dubspin .8s linear infinite}
    .dub-progress div.is-done::before{background:var(--a);border-color:var(--a)}
    @keyframes dubspin{to{transform:rotate(360deg)}}
    .dub-failed{color:var(--danger);font-weight:600}

    @media (max-width:760px){
      .dub-steps{grid-template-columns:1fr}
      .dub-grid{grid-template-columns:1fr}
      .dub-card{padding:1rem}
    }
  </style>
</head>
<body>
  @include('components.header')

  <main class="container dub-main">
    <section class="dub-hero">
      <span class="dub-badge">{{ __('ui.dub.badge') }}</span>
      <h1>{{ __('ui.dub.title') }}</h1>
      <p>{{ __('ui.dub.subtitle', ['seconds' => $maxSeconds]) }}</p>
    </section>

    <ol class="dub-steps">
      <li><div><strong>{{ __('ui.dub.step1_title') }}</strong><span>{{ __('ui.dub.step1_body', ['seconds' => $maxSeconds]) }}</span></div></li>
      <li><div><strong>{{ __('ui.dub.step2_title') }}</strong><span>{{ __('ui.dub.step2_body') }}</span></div></li>
      <li><div><strong>{{ __('ui.dub.step3_title') }}</strong><span>{{ __('ui.dub.step3_body') }}</span></div></li>
    </ol>

    @if(session('dub_error'))
      <div class="dub-note" role="alert"><p class="dub-error" style="margin:0">{{ session('dub_error') }}</p></div>
    @endif

    @if(!$user)
      <section class="dub-note">
        <h2>{{ __('ui.dub.guest_title') }}</h2>
        <p>{{ __('ui.dub.guest_body') }}</p>
        <div class="actions">
          <a class="btn btn-primary" href="{{ route('register') }}">{{ __('ui.dub.guest_register') }}</a>
          <a class="btn btn-ghost" href="{{ route('login') }}">{{ __('ui.dub.guest_login') }}</a>
        </div>
      </section>
    @elseif(!$included && $freeUsed)
      <section class="dub-note">
        <h2>{{ __('ui.dub.free_used_title') }}</h2>
        <p>{{ $upgradePlan ? __('ui.dub.free_used_body', ['plan' => $arabic && filled($upgradePlan->name_ar) ? $upgradePlan->name_ar : $upgradePlan->name]) : __('ui.dub.free_used_body_any') }}</p>
        <div class="actions">
          <a class="btn btn-primary" href="{{ $upgradePlan ? route('billing.page', ['plan' => $upgradePlan->slug]) : route('pricing') }}">{{ __('ui.dub.see_plans') }}</a>
        </div>
      </section>
    @elseif($needsEmail)
      <section class="dub-note">
        <h2>{{ __('ui.dub.verify_title') }}</h2>
        <p>{{ __('ui.dub.verify_email', ['email' => $user->email]) }}</p>
        <form method="POST" action="{{ route('verification.send') }}" class="actions">
          @csrf
          <button type="submit" class="btn btn-ghost">{{ __('ui.dub.verify_resend') }}</button>
        </form>
      </section>
    @endif

    @if($user && ($included || (!$freeUsed && !$needsEmail)))
    <form class="dub-card" id="dubForm" novalidate>
      <div class="dub-grid">
        <div>
          <div class="dub-drop" id="dubDrop">
            <input type="file" id="dubVideo" name="video" accept="video/mp4,video/quicktime,video/webm,video/x-matroska,video/x-m4v,video/3gpp,.mp4,.mov,.webm,.mkv,.m4v,.3gp">
            <div id="dubDropEmpty">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="13" height="14" rx="2"/><path d="m16 10 5-3v10l-5-3"/></svg>
              <strong>{{ __('ui.dub.drop_title') }}</strong>
              <small>{{ __('ui.dub.drop_hint', ['seconds' => $maxSeconds, 'mb' => $maxMb]) }}</small>
            </div>
            <video id="dubPreview" controls playsinline hidden></video>
            <div class="dub-file" id="dubFile" hidden><span id="dubFileName"></span><button type="button" id="dubChange">{{ __('ui.dub.change_video') }}</button></div>
          </div>
        </div>
        <div>
          <div class="dub-field">
            <label for="dubDialect">{{ __('ui.dub.dialect') }}</label>
            <select id="dubDialect" name="dialect">
              @foreach($dialects as $group)
                <optgroup label="{{ $group['label'] }}">
                  @foreach($group['options'] as $code => $label)
                    <option value="{{ $code }}" @selected($code === 'ar-SA')>{{ $label }}</option>
                  @endforeach
                </optgroup>
              @endforeach
            </select>
          </div>
          <div class="dub-field" role="radiogroup" aria-labelledby="dubVoiceLabel">
            <span class="dub-label" id="dubVoiceLabel">{{ __('ui.dub.voice') }}</span>
            <div class="dub-voices">
              @foreach($voices as $value => $voice)
                <label class="dub-voice">
                  <input type="radio" name="voice" value="{{ $value }}" @checked($loop->first)>
                  <span>{{ $arabic ? $voice['ar'] : $voice['en'] }}<small>{{ __('ui.dub.voice_'.$voice['gender']) }}</small></span>
                </label>
              @endforeach
            </div>
          </div>
          <div class="dub-two">
            <div class="dub-field">
              <label for="dubStyle">{{ __('ui.dub.style') }}</label>
              <select id="dubStyle" name="performance_style">
                @foreach($styles as $value => $label)
                  <option value="{{ $value }}" @selected($value === 'conversational')>{{ $label }}</option>
                @endforeach
              </select>
            </div>
            <div class="dub-field">
              <label for="dubEmotion">{{ __('ui.dub.emotion') }}</label>
              <select id="dubEmotion" name="emotion">
                <option value="">{{ __('ui.dub.emotion_auto') }}</option>
                @foreach($emotions as $value => $label)
                  <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="dub-cost" id="dubCost">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
        <span id="dubCostText">{{ $included ? __('ui.dub.cost_member_pick') : __('ui.dub.cost_free') }}</span>
      </div>
      <p class="dub-error" id="dubError" role="alert" hidden></p>
      <button type="submit" class="btn btn-primary dub-submit" id="dubSubmit" disabled>{{ $included ? __('ui.dub.submit') : __('ui.dub.submit_free') }}</button>
    </form>
    @endif

    @if($user)
    <section class="dub-list" id="dubList" @if(empty($dubs)) hidden @endif>
      <h2>{{ __('ui.dub.your_dubs') }}</h2>
      <p>{{ __('ui.dub.kept_for', ['hours' => $keepHours]) }}</p>
      <div id="dubItems"></div>
    </section>
    @endif
  </main>

  @include('components.footer')
  @include('components.scripts')

  @if($user)
  <script>
  (function () {
    'use strict';
    const T = @json(__('ui.dub.js'));
    const INCLUDED = @json($included);
    const MIN = {{ $minSeconds }}, MAX = {{ $maxSeconds }}, MAX_MB = {{ $maxMb }};
    const STORE_URL = @json(route('dub.store'));
    const DIALECTS = @json(collect($dialects)->flatMap(fn ($g) => $g['options']));
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || @json(csrf_token());
    const items = document.getElementById('dubItems');
    const list = document.getElementById('dubList');
    const form = document.getElementById('dubForm');

    // The device itself, as the demo page works it out (App\Services\DemoLinkedAccounts), so accounts made on one device share one free dub.
    const device = { id: '', touch: false };
    async function sha256(str) {
      if (!window.crypto?.subtle || !window.TextEncoder) return '';
      const digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(str));
      return Array.from(new Uint8Array(digest)).map((v) => v.toString(16).padStart(2, '0')).join('');
    }
    function canvasPrint() {
      try {
        const canvas = document.createElement('canvas'); canvas.width = 200; canvas.height = 50;
        const ctx = canvas.getContext('2d'); if (!ctx) return '';
        ctx.textBaseline = 'top'; ctx.font = '14px Arial'; ctx.fillStyle = '#f60'; ctx.fillRect(125, 1, 62, 20);
        ctx.fillStyle = '#069'; ctx.fillText('Listen Demo FP 🎵', 2, 15);
        ctx.fillStyle = 'rgba(102, 204, 0, 0.7)'; ctx.fillText('Listen Demo FP 🎵', 4, 17);
        ctx.strokeStyle = '#2a4'; ctx.arc(50, 25, 20, 0, Math.PI * 2, true); ctx.stroke();
        return canvas.toDataURL();
      } catch (e) { return ''; }
    }
    function webglPrint() {
      try {
        const gl = document.createElement('canvas').getContext('webgl') || document.createElement('canvas').getContext('experimental-webgl');
        if (!gl) return '';
        const info = gl.getExtension('WEBGL_debug_renderer_info');
        return [info ? gl.getParameter(info.UNMASKED_VENDOR_WEBGL) : '', info ? gl.getParameter(info.UNMASKED_RENDERER_WEBGL) : '',
          gl.getParameter(gl.VERSION) || '', gl.getParameter(gl.SHADING_LANGUAGE_VERSION) || '', (gl.getSupportedExtensions() || []).sort().join(',')].join('|');
      } catch (e) { return ''; }
    }
    function fontsPrint() {
      const fonts = ['Arial', 'Arial Black', 'Calibri', 'Cambria', 'Comic Sans MS', 'Courier New', 'Georgia', 'Helvetica', 'Impact', 'Lucida Console', 'Tahoma', 'Times New Roman', 'Trebuchet MS', 'Verdana', 'Wingdings'];
      const bases = ['monospace', 'sans-serif', 'serif'];
      const span = document.createElement('span');
      span.style.cssText = 'position:absolute;left:-9999px;font-size:72px'; span.textContent = 'mmmmmmmmmmlli';
      document.body.appendChild(span);
      const widths = {};
      for (const b of bases) { span.style.fontFamily = b; widths[b] = span.offsetWidth; }
      const found = fonts.filter((f) => bases.some((b) => { span.style.fontFamily = `'${f}',${b}`; return span.offsetWidth !== widths[b]; }));
      document.body.removeChild(span);
      return found.join(',');
    }
    function browserFamily() {
      const ua = navigator.userAgent || '';
      const browser = /Edg\//.test(ua) ? 'edge' : /OPR\/|Opera/.test(ua) ? 'opera' : /Firefox\//.test(ua) ? 'firefox'
        : /Chrome\/|CriOS\//.test(ua) ? 'chrome' : /Safari\//.test(ua) ? 'safari' : 'other';
      const system = /Windows/.test(ua) ? 'windows' : /Android/.test(ua) ? 'android' : /iPhone|iPad|iPod/.test(ua) ? 'ios'
        : /Mac OS X|Macintosh/.test(ua) ? 'mac' : /CrOS/.test(ua) ? 'chromeos' : /Linux/.test(ua) ? 'linux' : 'other';
      return browser + ':' + system;
    }
    const devicePromise = (async () => {
      try {
        device.touch = (navigator.maxTouchPoints || 0) > 0 || /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '');
        device.id = await sha256([await sha256(canvasPrint()), await sha256(webglPrint()), await sha256(fontsPrint()),
          String(navigator.hardwareConcurrency || ''), String(navigator.deviceMemory || ''), String(navigator.maxTouchPoints || ''),
          navigator.platform || '', Intl.DateTimeFormat().resolvedOptions().timeZone || '', browserFamily()].join('|'));
      } catch (e) {}
    })();

    function fmtSeconds(s) { s = Math.round(s); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }
    function el(tag, cls, text) { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }

    /* ── One dub in the list ── */
    const timers = {};
    function render(dub) {
      list.hidden = false;
      let card = document.getElementById('dub-' + dub.id);
      if (!card) { card = el('article', 'dub-item'); card.id = 'dub-' + dub.id; items.prepend(card); }
      card.replaceChildren();
      const head = el('div', 'dub-item-head');
      head.append(el('strong', '', dub.name || T.your_video), el('small', '', (DIALECTS[dub.dialect] || dub.dialect) + ' · ' + fmtSeconds(dub.seconds)));
      card.append(head);

      if (dub.status === 'queued' || dub.status === 'processing') {
        const steps = el('div', 'dub-progress');
        const started = card.dataset.started ? Number(card.dataset.started) : Date.now();
        card.dataset.started = String(started);
        const secs = (Date.now() - started) / 1000;
        const at = secs < 12 ? 0 : secs < 45 ? 1 : 2;
        [T.step_listen, T.step_voice, T.step_picture].forEach((label, i) => {
          steps.append(el('div', i < at ? 'is-done' : i === at ? 'is-on' : '', label));
        });
        card.append(steps, el('small', '', T.wait_note));
        clearTimeout(timers[dub.id]);
        timers[dub.id] = setTimeout(() => poll(dub), 3000);
        return;
      }
      if (dub.status === 'failed') {
        card.append(el('p', 'dub-failed', dub.error || T.failed));
        return;
      }
      if (!dub.watch_url) { card.append(el('small', '', T.expired)); return; }
      const video = el('video'); video.controls = true; video.playsInline = true; video.preload = 'metadata'; video.src = dub.watch_url;
      card.append(video);
      const actions = el('div', 'dub-item-actions');
      if (dub.download_url) {
        const a = el('a', 'btn ' + (dub.free && !INCLUDED ? 'btn-ghost' : 'btn-primary'), dub.free && !INCLUDED ? T.download_clean_subscribe : T.download);
        a.href = dub.download_url;
        actions.append(a);
      }
      if (dub.free) actions.append(el('small', '', T.free_mark_note));
      card.append(actions);
    }
    async function poll(dub) {
      try {
        const res = await fetch(dub.status_url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
        if (res.ok) { render(await res.json()); return; }
      } catch (e) {}
      timers[dub.id] = setTimeout(() => poll(dub), 6000);
    }
    (@json($dubs)).slice().reverse().forEach(render);

    if (!form) return;

    /* ── Picking the video ── */
    const input = document.getElementById('dubVideo');
    const drop = document.getElementById('dubDrop');
    const preview = document.getElementById('dubPreview');
    const empty = document.getElementById('dubDropEmpty');
    const fileBox = document.getElementById('dubFile');
    const submit = document.getElementById('dubSubmit');
    const error = document.getElementById('dubError');
    const costText = document.getElementById('dubCostText');
    let chosen = null, seconds = 0;

    function showError(msg) { error.textContent = msg || ''; error.hidden = !msg; }
    function reset() {
      chosen = null; seconds = 0; input.value = ''; preview.removeAttribute('src'); preview.hidden = true; fileBox.hidden = true;
      empty.hidden = false; drop.classList.remove('has-video'); submit.disabled = true;
      if (INCLUDED) costText.textContent = T.cost_member_pick;
    }
    function pick(file) {
      showError('');
      if (!file) return;
      if (!file.type.startsWith('video/') && !/\.(mp4|mov|webm|mkv|m4v|3gp)$/i.test(file.name)) { showError(T.not_video); return; }
      if (file.size > MAX_MB * 1024 * 1024) { showError(T.too_big.replace(':mb', MAX_MB)); return; }
      const url = URL.createObjectURL(file);
      preview.onloadedmetadata = () => {
        seconds = preview.duration || 0;
        if (seconds && seconds < MIN) { showError(T.too_short.replace(':seconds', MIN)); reset(); return; }
        if (seconds > MAX + 1) { showError(T.too_long.replace(':seconds', MAX)); reset(); return; }
        chosen = file; submit.disabled = false;
        if (INCLUDED) costText.textContent = T.cost_member.replace(':time', fmtSeconds(Math.min(seconds, MAX)));
      };
      // A file the browser cannot play may still be read by the server.
      preview.onerror = () => { chosen = file; submit.disabled = false; };
      preview.src = url; preview.hidden = false; empty.hidden = true; fileBox.hidden = false; drop.classList.add('has-video');
      document.getElementById('dubFileName').textContent = file.name;
    }
    input.addEventListener('change', () => pick(input.files[0]));
    document.getElementById('dubChange').addEventListener('click', () => { reset(); input.click(); });
    ['dragenter', 'dragover'].forEach((e) => drop.addEventListener(e, (ev) => { ev.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((e) => drop.addEventListener(e, (ev) => { ev.preventDefault(); drop.classList.remove('is-over'); }));
    drop.addEventListener('drop', (ev) => { if (!drop.classList.contains('has-video')) pick(ev.dataTransfer.files[0]); });

    /* ── Sending it ── */
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!chosen) { showError(T.pick_first); return; }
      showError('');
      submit.disabled = true; submit.textContent = T.uploading;
      await devicePromise;
      const data = new FormData(form);
      data.set('video', chosen);
      const headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF };
      if (device.id) { headers['X-Client-Device'] = device.id; headers['X-Client-Device-Touch'] = device.touch ? '1' : '0'; }
      try {
        const res = await fetch(STORE_URL, { method: 'POST', body: data, headers, credentials: 'same-origin' });
        const body = await res.json().catch(() => ({}));
        if (res.status === 202) {
          render(body);
          list.scrollIntoView({ behavior: 'smooth', block: 'start' });
          // A free dub is one per person: the form goes once it is on its way.
          if (!INCLUDED) { form.remove(); return; }
          reset();
        } else if (res.status === 413) {
          showError(T.too_big.replace(':mb', MAX_MB));
        } else if (res.status === 419) {
          showError(T.expired_page);
        } else {
          const errors = body.errors ? Object.values(body.errors).flat() : [];
          showError(errors[0] || body.message || T.failed);
        }
      } catch (e) {
        showError(T.network);
      }
      submit.textContent = INCLUDED ? T.submit : T.submit_free;
      submit.disabled = !chosen;
    });
  })();
  </script>
  @endif
</body>
</html>
