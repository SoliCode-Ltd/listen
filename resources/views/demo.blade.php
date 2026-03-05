@include('components.page-head', ['pageTitle' => __('ui.seo.demo_title'), 'page' => 'demo'])
  <style>
    .bg-orb {
      position: fixed;
      z-index: -1;
      pointer-events: none;
      border-radius: 999px;
      filter: blur(36px);
      opacity: .25;
      animation: float 10s ease-in-out infinite;
    }
    .bg-orb.a {
      width: 16rem;
      height: 16rem;
      background: color-mix(in oklab, var(--a) 74%, white 26%);
      left: -4rem;
      top: 15vh;
    }
    .bg-orb.b {
      width: 20rem;
      height: 20rem;
      background: color-mix(in oklab, var(--b) 75%, white 25%);
      right: -4rem;
      bottom: 8vh;
      animation-delay: -3s;
    }

    .main-content {
      padding: 2.5rem 0 3rem;
    }
    .hero-grid {
      display: grid;
      grid-template-columns: 1.1fr .9fr;
      gap: 1.25rem;
      align-items: center;
    }
    .page-card {
      background: linear-gradient(180deg, var(--surface2), var(--surface));
      border: 1px solid var(--line);
      border-radius: var(--radius-lg);
      padding: 1.5rem;
      box-shadow: var(--shadow);
      transition: transform .22s ease, border-color .22s ease;
    }
    .page-card:hover {
      transform: translateY(-3px);
      border-color: color-mix(in oklab, var(--a) 45%, var(--line));
    }
    .eyebrow {
      display: inline-block;
      padding: .35rem .7rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      font-size: .75rem;
      color: var(--muted);
      letter-spacing: .1em;
      text-transform: uppercase;
    }
    .page-card h1 {
      margin: .7rem 0 .7rem;
      font-size: clamp(1.8rem, 4vw, 2.8rem);
      line-height: 1.08;
      letter-spacing: -.02em;
    }
    .hero-list {
      display: grid;
      gap: .5rem;
      margin-top: .85rem;
      color: var(--muted);
      font-size: .95rem;
    }
    .sound-wave {
      margin-top: 1rem;
      display: flex;
      align-items: flex-end;
      gap: 4px;
      height: 36px;
    }
    .sound-wave span {
      display: block;
      width: 4px;
      border-radius: var(--radius-full);
      background: linear-gradient(180deg, var(--a), var(--b));
      animation: wavePulse 1s ease-in-out infinite;
      opacity: .9;
    }
    .sound-wave span:nth-child(2) { animation-delay: .08s; }
    .sound-wave span:nth-child(3) { animation-delay: .16s; }
    .sound-wave span:nth-child(4) { animation-delay: .24s; }
    .sound-wave span:nth-child(5) { animation-delay: .32s; }
    .sound-wave span:nth-child(6) { animation-delay: .40s; }
    .sound-wave span:nth-child(7) { animation-delay: .48s; }
    .sound-wave span:nth-child(8) { animation-delay: .56s; }
    .sound-wave span:nth-child(9) { animation-delay: .64s; }
    .sound-wave span:nth-child(10) { animation-delay: .72s; }
    .sound-wave span:nth-child(11) { animation-delay: .80s; }
    .sound-wave span:nth-child(12) { animation-delay: .88s; }
    .sound-wave.dynamic span { animation-duration: .75s; }
    @keyframes wavePulse {
      0%, 100% { height: 6px; }
      50% { height: 34px; }
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: .7rem;
      margin-top: .85rem;
    }
    .stat {
      background: var(--surface);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: .75rem;
    }
    .stat small {
      color: var(--muted);
      font-size: .8rem;
    }
    .stat b {
      display: block;
      font-size: 1.1rem;
      margin-top: .2rem;
    }

    .lab {
      padding: 1rem 0 2.5rem;
    }
    .lab-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 1.25rem;
    }
    .form-grid {
      display: grid;
      gap: 1rem;
    }
    .form-grid > div {
      padding-inline: .1rem;
    }
    .two-col {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: .8rem;
    }
    .demo-controls {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: .8rem;
    }
    label {
      display: block;
      margin: 0 0 .45rem;
      font-size: .92rem;
      font-weight: 600;
      color: color-mix(in oklab, var(--text) 88%, var(--muted));
    }
    input, select, textarea {
      width: 100%;
      padding: .9rem;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: var(--surface);
      color: var(--text);
      font-size: .95rem;
    }
    input[dir="auto"], textarea[dir="auto"] {
      unicode-bidi: plaintext;
      text-align: start;
    }
    input:focus, select:focus, textarea:focus {
      outline: none;
      border-color: var(--b);
      box-shadow: 0 0 0 3px var(--brand-accent-glow);
    }
    select {
      appearance: none;
      -webkit-appearance: none;
      background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239fb0d4' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
      background-repeat: no-repeat;
      background-position: right 1rem center;
      background-size: 1.1em;
      padding-right: 2.8rem;
      cursor: pointer;
    }
    select:hover {
      border-color: color-mix(in oklab, var(--b) 40%, var(--line));
    }
    html[data-theme="light"] select {
      background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%235b6c89' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    }
    textarea {
      min-height: 170px;
      resize: vertical;
    }

    .chips {
      display: flex;
      gap: .5rem;
      flex-wrap: wrap;
    }
    .chip {
      padding: .45rem .7rem;
      border-radius: var(--radius-full);
      border: 1px solid var(--line);
      background: var(--surface);
      font-size: .8rem;
      color: var(--muted);
      cursor: pointer;
      transition: all .2s ease;
    }
    .chip:hover {
      transform: translateY(-1px);
      border-color: color-mix(in oklab, var(--b) 45%, var(--line));
    }

    .studio-panel {
      margin-top: .6rem;
      padding: .85rem;
      border: 1px solid color-mix(in oklab, var(--a) 25%, var(--line));
      border-radius: var(--radius);
      background: color-mix(in oklab, var(--a) 5%, var(--surface));
    }
    .studio-label {
      display: flex;
      align-items: center;
      gap: .4rem;
      font-size: .88rem;
      font-weight: 600;
      color: color-mix(in oklab, var(--a) 60%, var(--text));
      margin-bottom: .6rem;
    }
    .studio-section {
      padding: .65rem .75rem;
      border-radius: calc(var(--radius) - 2px);
      background: color-mix(in oklab, var(--a) 3%, var(--surface));
      border: 1px solid color-mix(in oklab, var(--a) 12%, var(--line));
    }
    .studio-section-label {
      display: flex;
      align-items: center;
      gap: .35rem;
      font-size: .82rem;
      font-weight: 600;
      color: color-mix(in oklab, var(--a) 50%, var(--text));
      margin-bottom: .45rem;
      letter-spacing: .01em;
    }
    .premium-badge {
      display: inline-flex;
      align-items: center;
      padding: .15rem .45rem;
      font-size: .7rem;
      font-weight: 600;
      border-radius: var(--radius-full);
      background: linear-gradient(135deg, var(--a), var(--b));
      color: white;
      text-transform: uppercase;
      letter-spacing: .03em;
      margin-inline-start: .35rem;
    }
    .studio-panel input[type="range"] {
      -webkit-appearance: none;
      appearance: none;
      height: 6px;
      border-radius: var(--radius-full);
      background: var(--line);
      outline: none;
    }
    .studio-panel input[type="range"]::-webkit-slider-thumb {
      -webkit-appearance: none;
      appearance: none;
      width: 16px;
      height: 16px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--a), var(--b));
      cursor: pointer;
      border: 2px solid var(--surface);
      box-shadow: 0 0 4px rgba(0,0,0,.2);
    }

    .hint {
        padding:0.5rem 0 0 0;
      font-size: .85rem;
      color: var(--muted);
    }
    .error-box {
      margin-top: .7rem;
      padding: .7rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, #ef4c78 48%, var(--line));
      background: color-mix(in oklab, #ef4c78 13%, var(--surface));
      color: color-mix(in oklab, #ef4c78 78%, var(--text));
      font-size: .9rem;
    }
    .quota {
      margin: .6rem .1rem .9rem;
      padding: .85rem .95rem;
      border-radius: var(--radius);
      border: 1px solid var(--line);
      background: color-mix(in oklab, var(--surface) 84%, transparent);
    }
    .quota-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: .7rem;
      margin-bottom: .5rem;
      flex-wrap: wrap;
    }
    .quota-label {
      font-size: .85rem;
      color: var(--muted);
    }
    .quota-value {
      font-size: .9rem;
      font-weight: 700;
      white-space: nowrap;
    }
    .quota-track {
      height: 10px;
      border-radius: var(--radius-full);
      border: 1px solid color-mix(in oklab, var(--line) 75%, transparent);
      background: color-mix(in oklab, var(--surface2) 85%, transparent);
      overflow: hidden;
    }
    .quota-fill {
      height: 100%;
      width: 0;
      background: linear-gradient(90deg, var(--a), var(--b));
      transition: width .25s ease;
    }
    .quota-note {
      margin-top: .5rem;
      font-size: .82rem;
      color: var(--muted);
    }
    .quota-upgrade {
      display: none;
      margin-top: .7rem;
      padding: .85rem .95rem;
      border-radius: var(--radius);
      border: 1px solid color-mix(in oklab, var(--a) 45%, var(--line));
      background: color-mix(in oklab, var(--a) 11%, var(--surface));
    }
    .quota-upgrade p {
      margin: 0 0 .6rem;
      color: var(--muted);
      font-size: .88rem;
    }

    .audio-box {
      margin-top: .8rem;
      padding: .8rem;
      border: 1px solid var(--line);
      border-radius: var(--radius);
      background: var(--surface);
    }
    .audio-box audio {
      width: 100%;
      height: 34px;
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

    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-14px); }
    }

    @media (max-width: 960px) {
      .hero-grid,
      .lab-grid,
      .two-col,
      .demo-controls,
      .stats {
        grid-template-columns: 1fr;
      }
      .main-content {
        padding: 1.5rem 0 1rem;
      }
      .lab {
        padding: .6rem 0 1.8rem;
      }
      .page-card {
        padding: 1.1rem;
      }
      .sound-wave {
        margin-top: .8rem;
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
  <div class="bg-orb a"></div>
  <div class="bg-orb b"></div>

  @include('components.header')

  <main class="container main-content">
    <section>
      <div class="hero-grid">
        <article class="page-card reveal">
          <!-- <span class="eyebrow">Live voice test studio</span> -->
          <h1>{{ __('ui.demo.hero_title') }}</h1>
          <p style="color: var(--muted); margin: 0;">{{ __('ui.demo.hero_subtitle') }}</p>
          <div class="hero-list">
            <div>- {{ __('ui.demo.hero_point_1') }}</div>
            <div>- {{ __('ui.demo.hero_point_2') }}</div>
            <div>- {{ __('ui.demo.hero_point_3') }}</div>
            <div>- {{ __('ui.demo.hero_point_4') }}</div>
          </div>
          <div class="sound-wave" id="heroWave" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span><span></span><span></span>
          </div>
        </article>

        <aside class="page-card reveal">
          <h3 style="margin: .1rem 0 .5rem; font-size: 1.1rem;">{{ __('ui.demo.voice_coverage') }}</h3>
          <div class="stats">
            <div class="stat"><small>{{ __('ui.demo.arabic_voices') }}</small><b>{{ collect($voiceCatalog)->sum(fn ($byLang) => count($byLang['ar'] ?? [])) }}</b></div>
            <div class="stat"><small>{{ __('ui.demo.english_voices') }}</small><b>{{ collect($voiceCatalog)->sum(fn ($byLang) => count($byLang['en'] ?? [])) }}</b></div>
            <div class="stat"><small>{{ __('ui.demo.arabic_default') }}</small><b>{{ app()->getLocale() === 'ar' ? (($defaultVoiceAr ?? [])['label_ar'] ?? ($defaultVoiceAr ?? [])['label'] ?? 'سارة') : (($defaultVoiceAr ?? [])['label'] ?? 'Sara') }}</b></div>
            <div class="stat"><small>{{ __('ui.demo.english_default') }}</small><b>{{ app()->getLocale() === 'ar' ? (($defaultVoiceEn ?? [])['label_ar'] ?? ($defaultVoiceEn ?? [])['label'] ?? 'إيما') : (($defaultVoiceEn ?? [])['label'] ?? 'Emma') }}</b></div>
          </div>
        </aside>
      </div>
    </section>

    <section class="lab">
      <div class="lab-grid">
        <section class="page-card reveal">
          <h3 style="margin: .1rem 0 .7rem; font-size: 1.1rem;">{{ __('ui.demo.try_flow_title') }}</h3>
          <p class="hint" style="margin: -.2rem 0 .5rem;">{{ __('ui.demo.try_flow_subtitle') }}</p>
          <div class="quota" id="quotaBox">
            <div class="quota-head">
              <span class="quota-label">{{ __('ui.demo.guest_starter_allowance') }}</span>
              <strong class="quota-value" id="quotaValue">
                {{ number_format((int) ($demoQuota['remaining_chars'] ?? $guestAllowanceChars)) }} / {{ number_format((int) ($demoQuota['limit_chars'] ?? $guestAllowanceChars)) }} {{ __('ui.demo.chars_left') }}
              </strong>
            </div>
            <div class="quota-track">
              <div class="quota-fill" id="quotaFill"></div>
            </div>
            <div class="quota-note">{{ __('ui.demo.one_time_guest_credit', ['chars' => number_format((int) $starterPlanChars)]) }}</div>
          </div>
          <form id="demoForm" class="form-grid">
            <div>
              <label for="text">{{ __('ui.demo.text_input') }}</label>
              <textarea id="text" name="text" dir="auto" placeholder="{{ __('ui.demo.text_placeholder') }}" required></textarea>
            </div>

            <div class="chips">
              <button class="chip" type="button" data-sample="Welcome to our platform. We build professional software with world-class quality.">{{ __('ui.demo.english_sample') }}</button>
              <button class="chip" type="button" data-sample="{{ __('ui.demo.arabic_sample_text') }}">{{ __('ui.demo.arabic_sample') }}</button>
            </div>

            <div class="two-col demo-controls">
              <div>
                <label for="voice_tier">{{ __('ui.demo.voice_quality') }}</label>
                <select id="voice_tier" name="voice_tier">
                  @foreach($demoTiers as $tier)
                    <option value="{{ $tier['key'] }}" {{ $tier['key'] === $defaultTier ? 'selected' : '' }}>
                      {{ $tier['label'] }}
                    </option>
                  @endforeach
                </select>
              </div>
              <div>
                <label for="target_language">{{ __('ui.demo.voice_language') }}</label>
                <select id="target_language" name="target_language">
                  <option value="ar">{{ __('ui.demo.arabic') }}</option>
                  <option value="en">{{ __('ui.demo.english') }}</option>
                </select>
              </div>
              <div>
                <label for="voice_gender">{{ __('ui.demo.gender') }}</label>
                <select id="voice_gender">
                  <option value="all">{{ __('ui.demo.any') }}</option>
                  <option value="female">{{ __('ui.demo.female') }}</option>
                  <option value="male">{{ __('ui.demo.male') }}</option>
                </select>
              </div>
              <div>
                <label for="voice_key">{{ __('ui.demo.speaker') }}</label>
                <select id="voice_key" name="voice_key"></select>
              </div>
            </div>

            {{-- Performance Controls --}}
            <div class="studio-panel" id="studioPanel" style="display:none;">
              <div class="studio-label">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                {{ __('ui.demo.voice_studio', ['default' => 'Performance Controls']) }}
              </div>
              {{-- Performance Style Section --}}
              <div class="studio-section">
                <div class="studio-section-label">🎭 {{ __('ui.demo.performance_style', ['default' => 'Performance Style']) }}</div>
                <select name="performance_style" id="performance_style">
                  <option value="">-- {{ __('ui.demo.choose_performance_style', ['default' => 'Choose Performance Style']) }} --</option>
                  <option value="neutral">{{ __('ui.dashboard.emotion_neutral', ['default' => 'Neutral']) }}</option>
                  <option value="educational">{{ __('ui.demo.style_educational', ['default' => 'Educational']) }}</option>
                  <option value="commercial">{{ __('ui.demo.style_commercial', ['default' => 'Commercial']) }}</option>
                  <option value="promo">{{ __('ui.demo.style_promo', ['default' => 'Promotional']) }}</option>
                  <option value="podcast">{{ __('ui.demo.style_podcast', ['default' => 'Podcast']) }}</option>
                  <option value="assistant">{{ __('ui.demo.style_assistant', ['default' => 'Virtual Assistant']) }}</option>
                  <option value="narrative">{{ __('ui.demo.style_narrative', ['default' => 'Narrative']) }}</option>
                  <option value="comedic">{{ __('ui.demo.style_comedic', ['default' => 'Comedic']) }}</option>
                  <option value="corporate">{{ __('ui.demo.style_corporate', ['default' => 'Corporate']) }}</option>
                  <option value="conversational">{{ __('ui.demo.style_conversational', ['default' => 'Conversational']) }}</option>
                  <option value="inspirational">{{ __('ui.demo.style_inspirational', ['default' => 'Inspirational']) }}</option>
                  <option value="cinematic">{{ __('ui.demo.style_cinematic', ['default' => 'Cinematic']) }}</option>
                  <option value="documentary">{{ __('ui.demo.style_documentary', ['default' => 'Documentary']) }}</option>
                  <option value="storytelling">{{ __('ui.dashboard.emotion_storytelling', ['default' => 'Storytelling']) }}</option>
                  <option value="ad_style">{{ __('ui.dashboard.emotion_ad_style', ['default' => 'Ad Style']) }}</option>
                  <option value="news">{{ __('ui.dashboard.emotion_news', ['default' => 'News']) }}</option>
                </select>
              </div>

              {{-- Custom Prompt Section (Premium only - Gemini) --}}
              <div class="studio-section" id="stylePromptSection" style="margin-top:.6rem; display:none;">
                <div class="studio-section-label">✨ {{ __('ui.demo.style_prompt', ['default' => 'Custom Prompt']) }} <span class="premium-badge">{{ __('ui.pricing.premium', ['default' => 'Premium']) }}</span></div>
                <textarea name="style_prompt" id="style_prompt" rows="2" maxlength="2000" placeholder="{{ __('ui.demo.style_prompt_placeholder', ['default' => 'Describe how you want the voice to sound (e.g., "Speak with enthusiasm and energy, like a sports commentator")']) }}" style="width:100%; resize:vertical; min-height:60px;"></textarea>
                <!-- <div class="hint" style="margin-top:4px;">{{ __('ui.demo.style_prompt_hint', ['default' => 'Available for Premium voices (Gemini). Max 2000 characters.']) }}</div> -->
              </div>

              {{-- Emotion Section --}}
              <div class="studio-section" style="margin-top:.6rem;">
                <div class="studio-section-label">💫 {{ __('ui.demo.emotion', ['default' => 'Emotion']) }}</div>
                <select name="emotion" id="emotion">
                  <option value="">-- {{ __('ui.demo.choose_emotion', ['default' => 'Choose Emotion']) }} --</option>
                  <option value="neutral">{{ __('ui.dashboard.emotion_neutral', ['default' => 'Neutral']) }}</option>
                  <option value="warm">{{ __('ui.dashboard.emotion_warm', ['default' => 'Warm']) }}</option>
                  <option value="sad">{{ __('ui.dashboard.emotion_sad', ['default' => 'Sad']) }}</option>
                  <option value="excited">{{ __('ui.dashboard.emotion_excited', ['default' => 'Excited']) }}</option>
                  <option value="formal">{{ __('ui.dashboard.emotion_formal', ['default' => 'Formal']) }}</option>
                  <option value="calm">{{ __('ui.dashboard.emotion_calm', ['default' => 'Calm']) }}</option>
                  <option value="angry">{{ __('ui.dashboard.emotion_angry', ['default' => 'Angry']) }}</option>
                </select>
              </div>
              <div class="two-col" style="margin-top: .5rem;">
                <div>
                  <label for="dialect">{{ __('ui.demo.dialect', ['default' => 'Dialect']) }}</label>
                  <select name="dialect" id="dialect">
                    <option value="">{{ __('ui.dashboard.dialect_auto', ['default' => 'Auto-detect']) }}</option>
                  </select>
                </div>
                <div>
                  <label for="speed">{{ __('ui.demo.speed', ['default' => 'Speed']) }} (<span id="speedVal">1.0</span>x)</label>
                  <input type="range" name="speed" id="speed" min="0.5" max="2.0" step="0.1" value="1.0" style="width:100%"
                    oninput="document.getElementById('speedVal').textContent=this.value">
                </div>
              </div>
              <div class="two-col" style="margin-top: .5rem;">
                <div>
                  <label for="pitch">{{ __('ui.demo.pitch', ['default' => 'Pitch']) }} (<span id="pitchVal">0</span>)</label>
                  <input type="range" name="pitch" id="pitch" min="-10" max="10" step="1" value="0" style="width:100%"
                    oninput="document.getElementById('pitchVal').textContent=this.value">
                </div>
              </div>
            </div>

            <div>
              <div class="setting-card" style="margin-top: .5rem;">
                <div class="setting-info">
                  <label for="translateToggle">{{ __('ui.demo.translate_before_speaking') }}</label>
                  <div class="hint" id="translateHint">{{ __('ui.demo.translate_enabled_hint') }}</div>
                </div>
                <label class="toggle-switch" aria-label="{{ __('ui.demo.translate_before_speaking') }}">
                  <input type="checkbox" id="translateToggle" checked>
                  <span class="toggle-slider"></span>
                </label>
                <input type="hidden" name="translate" id="translate" value="1">
              </div>
            </div>

            <div style="display: flex; gap: .6rem; flex-wrap: wrap;">
              <button class="btn btn-primary" type="button" id="previewBtn">{{ __('ui.demo.generate_voice') }}</button>
            </div>
          </form>

          <div id="errorBox" class="error-box" style="display: none;"></div>
          <div class="quota-upgrade" id="upgradeBox">
            <p id="upgradeText">{{ __('ui.demo.upgrade_text', ['chars' => number_format((int) $starterPlanChars)]) }}</p>
            <a class="btn btn-primary" href="{{ route('register') }}">{{ __('ui.demo.create_free_account') }}</a>
          </div>

          <div class="audio-box" id="audioWrap" style="display: none;">
            <audio id="previewAudio" controls preload="none"></audio>
            <div class="hint" id="audioMeta" style="margin-top: .55rem;"></div>
          </div>
        </section>
      </div>
    </section>


  </main>

  @include('components.footer')
  @include('components.scripts')
  @php
    $demoI18n = [
      'upgrade_text' => __('ui.demo.upgrade_text_raw'),
      'chars_left' => __('ui.demo.chars_left'),
      'quota_total_start' => __('ui.demo.quota_total_start'),
      'translate_enabled_hint' => __('ui.demo.translate_enabled_hint'),
      'translate_disabled_hint' => __('ui.demo.translate_disabled_hint'),
      'any' => __('ui.demo.any'),
      'no_speakers' => __('ui.demo.no_speakers'),
      'request_failed' => __('ui.demo.request_failed'),
      'text_required' => __('ui.demo.text_required'),
      'selected_chars_exceed' => __('ui.demo.selected_chars_exceed'),
      'generating' => __('ui.demo.generating'),
      'no_audio_returned' => __('ui.demo.no_audio_returned'),
      'selected_speaker' => __('ui.demo.selected_speaker'),
      'preview_failed' => __('ui.demo.preview_failed'),
      'generate_voice' => __('ui.demo.generate_voice'),
      'create_account_unlock' => __('ui.demo.create_account_unlock'),
      'speaker_prefix' => __('ui.demo.speaker_prefix'),
    ];
  @endphp
  <script>
    const voiceCatalogByTier = @json($voiceCatalog);
    const form = document.getElementById('demoForm');
    const voiceTier = document.getElementById('voice_tier');
    const targetLanguage = document.getElementById('target_language');
    const voiceGender = document.getElementById('voice_gender');
    const voiceSelect = document.getElementById('voice_key');
    const dialectSelect = document.getElementById('dialect');
    const performanceStyleSelect = document.getElementById('performance_style');
    const emotionSelect = document.getElementById('emotion');
    const speedInput = document.getElementById('speed');
    const pitchInput = document.getElementById('pitch');
    const speedValue = document.getElementById('speedVal');
    const pitchValue = document.getElementById('pitchVal');
    const studioPanel = document.getElementById('studioPanel');
    const stylePromptSection = document.getElementById('stylePromptSection');
    const stylePromptInput = document.getElementById('style_prompt');
    const previewBtn = document.getElementById('previewBtn');
    const audioWrap = document.getElementById('audioWrap');
    const previewAudio = document.getElementById('previewAudio');
    const audioMeta = document.getElementById('audioMeta');
    const errorBox = document.getElementById('errorBox');
    const quotaBox = document.getElementById('quotaBox');
    const quotaValue = document.getElementById('quotaValue');
    const quotaFill = document.getElementById('quotaFill');
    const upgradeBox = document.getElementById('upgradeBox');
    const upgradeText = document.getElementById('upgradeText');
    const translateSelect = document.getElementById('translate');
    const translateToggle = document.getElementById('translateToggle');
    const translateHint = document.getElementById('translateHint');
    const heroWave = document.getElementById('heroWave');
    const textInput = document.getElementById('text');

    const dialectsByLanguage = {
      ar: [
        { value: '', label: '{{ __("ui.dashboard.dialect_auto", ["default" => "Auto-detect"]) }}' },
        { value: 'ar-XA', label: '{{ __("ui.dialects.msa", ["default" => "Modern Standard Arabic"]) }}' },
        { value: 'ar-SA', label: '{{ __("ui.dialects.saudi", ["default" => "Saudi"]) }}' },
        { value: 'ar-AE', label: '{{ __("ui.dialects.emirati", ["default" => "Emirati"]) }}' },
        { value: 'ar-EG', label: '{{ __("ui.dialects.egyptian", ["default" => "Egyptian"]) }}' },
        { value: 'ar-LB', label: '{{ __("ui.dialects.lebanese", ["default" => "Lebanese"]) }}' },
        { value: 'ar-SY', label: '{{ __("ui.dialects.syrian", ["default" => "Syrian"]) }}' },
        { value: 'ar-JO', label: '{{ __("ui.dialects.jordanian", ["default" => "Jordanian"]) }}' },
        { value: 'ar-IQ', label: '{{ __("ui.dialects.iraqi", ["default" => "Iraqi"]) }}' },
        { value: 'ar-MA', label: '{{ __("ui.dialects.moroccan", ["default" => "Moroccan"]) }}' },
        { value: 'ar-KW', label: '{{ __("ui.dialects.kuwaiti", ["default" => "Kuwaiti"]) }}' },
      ],
      en: [
        { value: '', label: '{{ __("ui.dashboard.dialect_auto", ["default" => "Auto-detect"]) }}' },
        { value: 'en-US', label: '{{ __("ui.dialects.american", ["default" => "American"]) }}' },
        { value: 'en-GB', label: '{{ __("ui.dialects.british", ["default" => "British"]) }}' },
        { value: 'en-AU', label: '{{ __("ui.dialects.australian", ["default" => "Australian"]) }}' },
        { value: 'en-IN', label: '{{ __("ui.dialects.indian", ["default" => "Indian"]) }}' },
        { value: 'en-ZA', label: '{{ __("ui.dialects.south_african", ["default" => "South African"]) }}' },
      ],
    };

    function renderDialectOptions() {
      if (!dialectSelect || !targetLanguage) return;
      const lang = normalizeLanguage(targetLanguage.value);
      const dialects = dialectsByLanguage[lang] || dialectsByLanguage['ar'];
      dialectSelect.innerHTML = '';
      for (const d of dialects) {
        const opt = document.createElement('option');
        opt.value = d.value;
        opt.textContent = d.label;
        dialectSelect.appendChild(opt);
      }
    }

    function normalizeTier(value) {
      const tier = String(value || 'standard').trim().toLowerCase();
      return ['standard', 'hd'].includes(tier) ? tier : 'standard';
    }

    function resetStudioControls() {
      if (performanceStyleSelect) performanceStyleSelect.value = 'neutral';
      if (emotionSelect) emotionSelect.value = 'neutral';
      if (speedInput) speedInput.value = '1.0';
      if (pitchInput) pitchInput.value = '0';
      if (speedValue) speedValue.textContent = '1.0';
      if (pitchValue) pitchValue.textContent = '0';
      if (dialectSelect) dialectSelect.value = '';
    }

    function applyStudioTierState() {
      const tier = voiceTier?.value || 'standard';
      const isHd = (tier === 'hd');

      // Performance controls are only available for HD tier.
      if (studioPanel) {
        studioPanel.style.display = isHd ? '' : 'none';
      }

      // Custom Prompt is only available for HD/Premium tier (Gemini voices)
      if (stylePromptSection) {
        stylePromptSection.style.display = isHd ? '' : 'none';
      }

      [performanceStyleSelect, emotionSelect, dialectSelect, speedInput, pitchInput, stylePromptInput].forEach((el) => {
        if (!el) return;
        el.disabled = !isHd;
      });

      // Reset to defaults when switching to standard
      if (!isHd) {
        if (performanceStyleSelect) performanceStyleSelect.value = '';
        if (emotionSelect) emotionSelect.value = '';
        if (dialectSelect) dialectSelect.value = '';
        if (speedInput) { speedInput.value = '1.0'; }
        if (pitchInput) { pitchInput.value = '0'; }
        if (stylePromptInput) stylePromptInput.value = '';
        const speedValue = document.getElementById('speedVal');
        const pitchValue = document.getElementById('pitchVal');
        if (speedValue) speedValue.textContent = '1.0';
        if (pitchValue) pitchValue.textContent = '0';
      }
    }
    const demoQuota = @json($demoQuota ?? null);
    const starterPlanChars = Number(@json((int) $starterPlanChars));
    const i18n = @json($demoI18n);
    const pageLocale = document.documentElement.lang || 'en';

    const csrfHeaders = {
      'X-CSRF-TOKEN': '{{ csrf_token() }}',
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    };

    let currentQuota = {
      limit_chars: Number(demoQuota?.limit_chars || 300),
      remaining_chars: Number(demoQuota?.remaining_chars || 300),
      used_chars: Number(demoQuota?.used_chars || 0),
      is_exhausted: Boolean(demoQuota?.is_exhausted),
    };
    let preferredTranslateLanguage = targetLanguage?.value === 'en' ? 'en' : 'ar';
    const preferredVoiceKeyByLanguage = { ar: '', en: '' };
    let clientFingerprint = '';
    let fingerprintPromise = null;

    function formatNumber(value) {
      return new Intl.NumberFormat('en-US').format(Number(value || 0));
    }

    function countBillableChars(value) {
      const text = String(value || '');
      try {
        return (text.match(new RegExp('[\\p{L}\\p{N}]', 'gu')) || []).length;
      } catch (_error) {
        return (text.match(/[A-Za-z0-9]/g) || []).length;
      }
    }

    function normalizeLanguage(value) {
      return value === 'en' ? 'en' : 'ar';
    }

    function oppositeLanguage(value) {
      return normalizeLanguage(value) === 'en' ? 'ar' : 'en';
    }

    function detectSourceLanguage(value) {
      return /[\u0600-\u06FF]/u.test(String(value || '')) ? 'ar' : 'en';
    }

    function isTranslateEnabled() {
      return String(translateSelect?.value || '1') === '1';
    }

    function rememberCurrentVoiceSelection(language = normalizeLanguage(targetLanguage?.value)) {
      const selectedVoiceKey = String(voiceSelect?.value || '').trim();
      if (!selectedVoiceKey) return;
      preferredVoiceKeyByLanguage[normalizeLanguage(language)] = selectedVoiceKey;
    }

    function hideAudioPreview() {
      audioWrap.style.display = 'none';
      previewAudio.pause();
      previewAudio.removeAttribute('src');
      delete previewAudio.dataset.downloadLanguage;
      delete previewAudio.dataset.downloadText;
      delete previewAudio.dataset.downloadSpeaker;
      delete previewAudio.dataset.downloadQuality;
      previewAudio.load();
      audioMeta.textContent = '';
    }

    function setError(message = '') {
      if (!message) {
        errorBox.style.display = 'none';
        errorBox.textContent = '';
        return;
      }
      errorBox.style.display = 'block';
      errorBox.textContent = message;
    }

    function setUpgradeVisible(show, customMessage = '') {
      if (!upgradeBox) return;
      upgradeBox.style.display = show ? 'block' : 'none';
      if (show && upgradeText) {
        const fallback = i18n.upgrade_text.replace(':chars', formatNumber(starterPlanChars));
        upgradeText.textContent = customMessage || fallback;
      }
    }

    function renderQuota(quota) {
      if (!quota || typeof quota !== 'object') return;
      currentQuota = { ...currentQuota, ...quota };
      if (quotaValue) {
        quotaValue.textContent = `${formatNumber(currentQuota.remaining_chars)} / ${formatNumber(currentQuota.limit_chars)} ${i18n.chars_left}`;
      }
      if (quotaFill) {
        const remaining = Math.max(0, Number(currentQuota.remaining_chars || 0));
        const limit = Math.max(1, Number(currentQuota.limit_chars || 1));
        const percent = Math.max(0, Math.min(100, Math.round((remaining / limit) * 100)));
        quotaFill.style.width = `${percent}%`;
      }
      if (quotaBox) {
        const pending = countBillableChars(textInput?.value || '');
        if (pending > 0) quotaBox.title = `${formatNumber(pending)} billable chars selected`;
      }
      setUpgradeVisible(Boolean(currentQuota.is_exhausted), i18n.quota_total_start.replace(':total', formatNumber(currentQuota.limit_chars)).replace(':chars', formatNumber(starterPlanChars)));
    }

    async function buildFingerprint() {
      const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
      const raw = [
        navigator.userAgent || '',
        navigator.language || '',
        Array.isArray(navigator.languages) ? navigator.languages.join(',') : '',
        navigator.platform || '',
        String(navigator.hardwareConcurrency || ''),
        String(navigator.maxTouchPoints || ''),
        timezone,
        `${screen.width}x${screen.height}`,
        String(screen.colorDepth || ''),
        String(window.devicePixelRatio || ''),
      ].join('|');
      if (!window.crypto?.subtle || !window.TextEncoder) return raw;
      const digest = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
      return Array.from(new Uint8Array(digest)).map((v) => v.toString(16).padStart(2, '0')).join('');
    }

    async function ensureFingerprint() {
      if (clientFingerprint) return clientFingerprint;
      if (!fingerprintPromise) {
        fingerprintPromise = buildFingerprint()
          .then((fp) => {
            clientFingerprint = String(fp || '').slice(0, 256);
            if (clientFingerprint) {
              document.cookie = `listen_demo_fp=${encodeURIComponent(clientFingerprint)}; Max-Age=31536000; Path=/; SameSite=Lax`;
            }
            return clientFingerprint;
          })
          .catch(() => {
            clientFingerprint = '';
            return '';
          });
      }

      return fingerprintPromise;
    }

    function updateTranslateHint() {
      if (!translateHint || !translateSelect) return;
      translateHint.textContent = translateSelect.value === '1'
        ? i18n.translate_enabled_hint
        : i18n.translate_disabled_hint;
    }

    function getFilteredVoices() {
      const tier = voiceTier?.value || 'standard';
      const lang = targetLanguage.value;
      const gender = voiceGender.value;
      const byTier = voiceCatalogByTier?.[tier] || {};
      const list = Array.isArray(byTier?.[lang]) ? byTier[lang] : [];
      const filtered = list.filter((voice) => gender === 'all' ? true : voice.gender === gender);
      return sortVoices(filtered);
    }

    function cleanSpeakerLabel(value) {
      return String(value || '')
        .replace(/^\++\s*/, '')
        .replace(/\b[a-z]{2}-[A-Z]{2}\b/g, '')
        .replace(/\s{2,}/g, ' ')
        .trim();
    }

    function voiceDisplayLabel(voice) {
      const label = pageLocale === 'ar' && voice.label_ar ? voice.label_ar : voice.label;
      return cleanSpeakerLabel(label);
    }

    function formatTierLabel(value) {
      const text = String(value || '').trim();
      if (!text) return '';
      return `\u2066${text}\u2069`;
    }

    function sortVoices(list) {
      const tierRank = { standard: 1, hd: 2 };
      return [...list].sort((a, b) => {
        const aRank = tierRank[String(a?.tier || 'standard')] ?? 99;
        const bRank = tierRank[String(b?.tier || 'standard')] ?? 99;
        if (aRank !== bRank) return aRank - bRank;

        const byLabel = String(a?.label || '').localeCompare(String(b?.label || ''), undefined, { sensitivity: 'base' });
        if (byLabel !== 0) return byLabel;

        return String(a?.key || '').localeCompare(String(b?.key || ''), undefined, { sensitivity: 'base' });
      });
    }

    function renderVoiceOptions() {
      if (!voiceSelect || !targetLanguage) return;
      const voices = getFilteredVoices();
      const lang = normalizeLanguage(targetLanguage.value);
      const remembered = String(preferredVoiceKeyByLanguage[lang] || '').trim();
      const current = String(remembered || '').trim();
      voiceSelect.innerHTML = '';
      if (!voices.length) {
        voiceSelect.innerHTML = '<option value="">' + i18n.no_speakers + '</option>';
        preferredVoiceKeyByLanguage[lang] = '';
        return;
      }
      for (const voice of voices) {
        const option = document.createElement('option');
        option.value = voice.key;
        option.textContent = voiceDisplayLabel(voice);
        voiceSelect.appendChild(option);
      }
      if (voices.some((v) => v.key === current)) voiceSelect.value = current;
      else voiceSelect.value = voices[0].key;
      preferredVoiceKeyByLanguage[lang] = String(voiceSelect.value || '').trim();
    }

    function syncLanguageToCurrentTextWhenTranslationOff(fallbackLanguage = null) {
      if (!targetLanguage || !textInput || isTranslateEnabled()) return false;
      const text = String(textInput.value || '').trim();
      if (!text) {
        const fallback = fallbackLanguage ? normalizeLanguage(fallbackLanguage) : null;
        if (!fallback) return false;
        const currentLanguage = normalizeLanguage(targetLanguage.value);
        if (currentLanguage === fallback) return false;
        rememberCurrentVoiceSelection(currentLanguage);
        targetLanguage.value = fallback;
        renderVoiceOptions();
        return true;
      }
      const sourceLanguage = detectSourceLanguage(text);
      const currentLanguage = normalizeLanguage(targetLanguage.value);
      if (sourceLanguage === currentLanguage) return false;
      rememberCurrentVoiceSelection(currentLanguage);
      targetLanguage.value = sourceLanguage;
      renderVoiceOptions();
      return true;
    }

    function restorePreferredTranslationLanguage() {
      if (!targetLanguage || !isTranslateEnabled()) return false;
      const currentLanguage = normalizeLanguage(targetLanguage.value);
      const desiredLanguage = normalizeLanguage(preferredTranslateLanguage);
      if (currentLanguage === desiredLanguage) return false;
      rememberCurrentVoiceSelection(currentLanguage);
      targetLanguage.value = desiredLanguage;
      renderVoiceOptions();
      return true;
    }

    async function callEndpoint(endpoint) {
      setError('');
      setUpgradeVisible(false);
      const fd = new FormData(form);
      const headers = { ...csrfHeaders };
      await ensureFingerprint();
      if (clientFingerprint) headers['X-Listen-Fingerprint'] = clientFingerprint;
      const res = await fetch(endpoint, { method: 'POST', headers, body: fd });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data?.ok === false) {
        const firstError = data?.errors && typeof data.errors === 'object' ? Object.values(data.errors)[0] : null;
        const firstErrorText = Array.isArray(firstError) ? String(firstError[0] || '') : '';
        const error = new Error(String(data?.message || firstErrorText || i18n.request_failed));
        error.payload = data;
        throw error;
      }
      return data;
    }

    previewBtn?.addEventListener('click', async () => {
      heroWave?.classList.add('dynamic');
      try {
        if (!isTranslateEnabled()) {
          syncLanguageToCurrentTextWhenTranslationOff();
        }

        const rawText = String(textInput?.value || '');
        const trimmed = rawText.trim();
        const selectedChars = countBillableChars(trimmed);
        if (!trimmed) {
          hideAudioPreview();
          setError(i18n.text_required);
          return;
        }
        if (selectedChars > Number(currentQuota.remaining_chars || 0)) {
          hideAudioPreview();
          const message = i18n.selected_chars_exceed.replace(':selected', formatNumber(selectedChars)).replace(':remaining', formatNumber(currentQuota.remaining_chars));
          setError(message);
          setUpgradeVisible(true, i18n.create_account_unlock.replace(':chars', formatNumber(starterPlanChars)));
          return;
        }
        previewBtn.disabled = true;
        previewBtn.textContent = i18n.generating;
        hideAudioPreview();
        const data = await callEndpoint('{{ route('demo.preview') }}');
        renderQuota(data?.quota || null);
        if (!data?.audio_url) {
          hideAudioPreview();
          setError(i18n.no_audio_returned);
          return;
        }
        audioWrap.style.display = 'block';
        if (window.initCustomAudioPlayers) window.initCustomAudioPlayers();
        previewAudio.dataset.downloadLanguage = String(data?.target_language || normalizeLanguage(targetLanguage?.value || 'ar'));
        previewAudio.dataset.downloadText = String(trimmed).slice(0, 42);
        previewAudio.dataset.downloadSpeaker = cleanSpeakerLabel(String(data?.voice?.label || i18n.selected_speaker));
        previewAudio.dataset.downloadQuality = String(data?.voice_tier_label || 'Standard');
        previewAudio.src = data.audio_url;
        previewAudio.load();
        const voiceData = data?.voice || {};
        const label = pageLocale === 'ar' && voiceData.label_ar ? cleanSpeakerLabel(voiceData.label_ar) : cleanSpeakerLabel(voiceData.label || i18n.selected_speaker);
        const quality = data?.voice_tier_label ? ` - ${formatTierLabel(data.voice_tier_label)}` : '';
        audioMeta.textContent = `${i18n.speaker_prefix} ${label}${quality} - ${data.translate_note}`;
      } catch (err) {
        hideAudioPreview();
        const message = String(err?.message || i18n.preview_failed);
        if (err?.payload?.quota) {
          renderQuota(err.payload.quota);
        }
        setError(message);
        if (message.toLowerCase().includes('quota') || message.toLowerCase().includes('limit')) {
          setUpgradeVisible(true, i18n.create_account_unlock.replace(':chars', formatNumber(starterPlanChars)));
          renderQuota({ ...currentQuota, is_exhausted: true, remaining_chars: 0 });
        }
      } finally {
        previewBtn.disabled = false;
        previewBtn.textContent = i18n.generate_voice;
        setTimeout(() => heroWave?.classList.remove('dynamic'), 900);
      }
    });

    document.querySelectorAll('[data-sample]').forEach((btn) => {
      btn.addEventListener('click', () => {
        document.getElementById('text').value = btn.getAttribute('data-sample') || '';
      });
    });
    form?.addEventListener('submit', (event) => {
      event.preventDefault();
      previewBtn?.click();
    });

    voiceTier?.addEventListener('change', () => {
      renderVoiceOptions();
      renderDialectOptions();
      applyStudioTierState();
    });
    targetLanguage?.addEventListener('change', () => {
      if (isTranslateEnabled()) {
        preferredTranslateLanguage = normalizeLanguage(targetLanguage.value);
      }
      renderVoiceOptions();
      renderDialectOptions();
      applyStudioTierState();
    });
    voiceGender?.addEventListener('change', renderVoiceOptions);
    voiceSelect?.addEventListener('change', () => {
      rememberCurrentVoiceSelection();
    });
    translateToggle?.addEventListener('change', () => {
      if (translateSelect) translateSelect.value = translateToggle.checked ? '1' : '0';
      if (translateToggle.checked) {
        restorePreferredTranslationLanguage();
      } else {
        preferredTranslateLanguage = normalizeLanguage(targetLanguage?.value);
        syncLanguageToCurrentTextWhenTranslationOff(oppositeLanguage(preferredTranslateLanguage));
      }
      updateTranslateHint();
    });
    textInput?.addEventListener('input', () => {
      if (currentQuota.remaining_chars <= 0) {
        setUpgradeVisible(true, i18n.create_account_unlock.replace(':chars', formatNumber(starterPlanChars)));
      }
    });
    if (translateToggle && translateSelect) {
      translateToggle.checked = translateSelect.value === '1';
    }
    if (!isTranslateEnabled()) {
      preferredTranslateLanguage = oppositeLanguage(normalizeLanguage(targetLanguage?.value));
    }
    renderVoiceOptions();
    renderDialectOptions();
    applyStudioTierState();
    updateTranslateHint();
    renderQuota(currentQuota);

    ensureFingerprint().catch(() => {});


  </script>
</body>
</html>

