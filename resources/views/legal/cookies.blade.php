@include('components.page-head', ['pageTitle' => __('ui.seo.cookies_title'), 'page' => 'cookies'])
  <style>
    .legal-page {
      padding: 2.5rem 0 3rem;
    }
    .legal-shell {
      width: min(860px, 100%);
      margin: 0 auto;
      padding: clamp(1.5rem, 3vw, 2.5rem);
      border-radius: var(--card-radius);
      border: 1px solid var(--line);
      background: var(--surface);
      box-shadow: var(--card-shadow);
    }
    .legal-kicker {
      display: inline-flex;
      align-items: center;
      gap: .45rem;
      margin-bottom: 1rem;
      padding: .38rem .74rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .8rem;
      font-weight: 600;
      color: var(--muted);
    }
    .legal-shell h1 {
      margin: 0 0 .5rem;
      font-size: clamp(1.8rem, 4vw, 2.6rem);
      font-weight: 600;
      letter-spacing: -.02em;
    }
    .legal-updated {
      margin: 0 0 1.2rem;
      color: var(--muted);
      font-size: .92rem;
    }
    .legal-intro {
      margin: 0 0 1.5rem;
      color: var(--muted);
      line-height: 1.65;
    }
    .legal-section + .legal-section {
      margin-top: 1.3rem;
    }
    .legal-section h2 {
      margin: 0 0 .5rem;
      font-size: 1.15rem;
    }
    .legal-section p {
      margin: 0;
      color: var(--muted);
      line-height: 1.65;
    }
    .legal-section ul {
      margin: .55rem 0 0 0;
      padding-left: 1.15rem;
      color: var(--muted);
      list-style: disc;
    }
    .legal-section li {
      margin: .35rem 0;
      line-height: 1.6;
    }
    .cookie-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: .85rem;
      margin-top: .75rem;
    }
    .cookie-card {
      border-radius: .75rem;
      background: var(--surface2);
      padding: .85rem 1rem;
    }
    /* An odd last card takes the whole row. */
    .cookie-card:last-child:nth-child(odd) {
      grid-column: 1 / -1;
    }
    .cookie-card h3 {
      margin: 0 0 .35rem;
      font-size: .95rem;
    }
    .cookie-card p {
      margin: 0;
      font-size: .95rem;
    }
    .cookie-card .recordings-switch {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: .5rem .85rem;
      margin-top: .75rem;
    }
    .cookie-card .recordings-switch[hidden] {
      display: none;
    }
    .legal-section a {
      color: var(--text);
      text-underline-offset: 3px;
    }
    @media (max-width: 700px) {
      .cookie-grid {
        grid-template-columns: 1fr;
      }
    }
    @media (max-width: 640px) {
      .legal-page {
        padding: 1.5rem 0 2rem;
      }
      .legal-shell {
        padding: 1.1rem;
      }
    }
  </style>
</head>
<body>

  @include('components.header')

  <main class="container legal-page">
    @php
      $effectiveDate = app()->getLocale() === 'ar' ? '21 نوفمبر 2026' : 'November 21, 2026';
      $contactEmail = trim((string) \App\Models\SiteSetting::get('contact_email', '')) ?: 'help@listenai.studio';
      $supportEmailLink = '<a href="mailto:'.e($contactEmail).'">'.e($contactEmail).'</a>';
    @endphp
    <article class="legal-shell">
      <span class="legal-kicker">{{ __('ui.legal.kicker') }}</span>
      <h1>{{ __('ui.legal.cookies.title') }}</h1>
      <p class="legal-updated">{{ __('ui.legal.effective_date', ['date' => $effectiveDate]) }}</p>
      <p class="legal-intro">{{ __('ui.legal.cookies.intro') }}</p>

      <section class="legal-section">
        <h2>{{ __('ui.legal.cookies.s1_title') }}</h2>
        <p>{{ __('ui.legal.cookies.s1_body') }}</p>
      </section>

      <section class="legal-section">
        <h2>{{ __('ui.legal.cookies.s2_title') }}</h2>
        <div class="cookie-grid">
          <article class="cookie-card">
            <h3>{{ __('ui.legal.cookies.c1_title') }}</h3>
            <p>{{ __('ui.legal.cookies.c1_body') }}</p>
          </article>
          <article class="cookie-card">
            <h3>{{ __('ui.legal.cookies.c2_title') }}</h3>
            <p>{{ __('ui.legal.cookies.c2_body') }}</p>
          </article>
          <article class="cookie-card">
            <h3>{{ __('ui.legal.cookies.c3_title') }}</h3>
            <p>{{ __('ui.legal.cookies.c3_body') }}</p>
          </article>
          <article class="cookie-card">
            <h3>{{ __('ui.legal.cookies.c4_title') }}</h3>
            <p>{{ __('ui.legal.cookies.c4_body') }}</p>
          </article>
          <article class="cookie-card">
            <h3>{{ __('ui.legal.cookies.c5_title') }}</h3>
            <p>{{ __('ui.legal.cookies.c5_body') }}</p>
          </article>
          <article class="cookie-card">
            <h3>{{ __('ui.legal.cookies.c6_title') }}</h3>
            <p>{{ __('ui.legal.cookies.c6_body') }}</p>
          </article>
          {{-- Visit recordings (App\Support\Clarity), with the visitor's own switch for this browser. --}}
          <article class="cookie-card" id="recordings">
            <h3>{{ __('ui.legal.cookies.c7_title') }}</h3>
            <p>{{ __('ui.legal.cookies.c7_body') }}</p>
            <p class="recordings-switch" hidden data-recordings
               data-turn-off="{{ __('ui.legal.cookies.c7_turn_off') }}"
               data-turn-on="{{ __('ui.legal.cookies.c7_turn_on') }}"
               data-is-off="{{ __('ui.legal.cookies.c7_is_off') }}"
               data-gpc="{{ __('ui.legal.cookies.c7_gpc') }}">
              <button type="button" class="btn btn-ghost"></button>
              <span role="status"></span>
            </p>
          </article>
        </div>
        <script>(function(){var box=document.querySelector('[data-recordings]');if(!box)return;var btn=box.querySelector('button'),state=box.querySelector('[role=status]'),key=@json(\App\Support\Clarity::OFF_KEY),n=navigator;var isOff=function(){try{return localStorage.getItem(key)==='1';}catch(e){return false;}};var draw=function(){if(n.globalPrivacyControl===true||n.doNotTrack==='1'){btn.hidden=true;state.textContent=box.dataset.gpc;return;}var off=isOff();btn.textContent=off?box.dataset.turnOn:box.dataset.turnOff;state.textContent=off?box.dataset.isOff:'';};btn.addEventListener('click',function(){var off=!isOff();try{if(off){localStorage.setItem(key,'1');sessionStorage.removeItem('listen_recording');}else{localStorage.removeItem(key);}}catch(e){}if(off&&typeof window.clarity==='function'){try{window.clarity('consent',false);}catch(e){}}draw();});box.hidden=false;draw();})();</script>
      </section>

      <section class="legal-section">
        <h2>{{ __('ui.legal.cookies.s3_title') }}</h2>
        <p>{{ __('ui.legal.cookies.s3_body') }}</p>
      </section>

      <section class="legal-section">
        <h2>{{ __('ui.legal.cookies.s4_title') }}</h2>
        <ul>
          <li>{{ __('ui.legal.cookies.s4_1') }}</li>
          <li>{{ __('ui.legal.cookies.s4_2') }}</li>
          <li>{{ __('ui.legal.cookies.s4_3') }}</li>
          <li>{{ __('ui.legal.cookies.s4_4') }}</li>
        </ul>
      </section>

      <section class="legal-section">
        <h2>{{ __('ui.legal.cookies.s5_title') }}</h2>
        <p>{{ __('ui.legal.cookies.s5_body') }}</p>
      </section>

      <section class="legal-section">
        <h2>{{ __('ui.legal.cookies.s6_title') }}</h2>
        <p>{!! __('ui.legal.cookies.s6_body', ['email' => $supportEmailLink]) !!}</p>
      </section>
    </article>
  </main>

  @include('components.footer')
  @include('components.scripts')
</body>
</html>

