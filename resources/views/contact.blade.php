@php
  $contactWhatsapp = preg_replace('/\D+/', '', (string) \App\Models\SiteSetting::get('whatsapp_number', '201553212603'));
  $contactWhatsappUrl = 'https://wa.me/'.$contactWhatsapp.'?text='.rawurlencode(__('ui.contact.whatsapp_message'));
  $contactEmail = trim((string) \App\Models\SiteSetting::get('contact_email', '')) ?: 'help@listenai.studio';
  $contactTopics = ['billing', 'voices', 'account', 'other'];
  // A link can pick the topic for the visitor (the pricing page's "Contact us" picks plans and billing).
  $contactTopic = in_array(request()->query('topic'), $contactTopics, true) ? __('ui.contact.topic_'.request()->query('topic')) : '';
  // The wave's resting shape: tall in the middle, like the Listen mark, with some life in it.
  $contactBars = [];
  for ($i = 0; $i < 44; $i++) {
    $centre = 1 - abs($i - 21.5) / 24;
    $contactBars[] = round(max(.12, min(1, $centre * (.55 + .45 * abs(sin($i * 1.3) * cos($i * .47))))), 3);
  }
@endphp
@include('components.page-head', ['pageTitle' => __('ui.seo.contact_title'), 'page' => 'contact', 'theme' => (\App\Support\SeoHelper::defaultTheme() ?? 'dark')])
  <style>
    /* ── Contact ────────────────────────────────────────────────────
       On the site's own background, beside the form: a voice wave in the brand
       violet that breathes while the page rests and moves with every letter typed,
       the way Listen turns text into voice. */
    .contact-grid {
      width: min(1200px, 92%);
      margin: 0 auto;
      padding: clamp(2.75rem, 6vw, 4.5rem) 0;
      display: grid;
      grid-template-columns: minmax(0, 5fr) minmax(0, 6fr);
      grid-template-areas: "intro card" "ways card";
      grid-template-rows: auto 1fr;
      gap: 0 clamp(2rem, 5vw, 4.5rem);
    }

    .contact-intro { grid-area: intro; color: var(--text); }
    .contact-intro h1 {
      margin: 0 0 1rem;
      color: var(--text);
      font-size: var(--page-title-size);
      font-weight: 600;
      line-height: 1.12;
      letter-spacing: -.03em;
    }
    .contact-intro p { margin: 0; max-width: 40ch; color: var(--muted); font-size: 1.08rem; line-height: 1.7; }

    /* The wave */
    .cw-wave {
      display: flex;
      align-items: center;
      gap: clamp(3px, .45vw, 6px);
      height: clamp(110px, 12vw, 150px);
      margin: clamp(2rem, 4.5vw, 3.25rem) 0 0;
    }
    .cw-wave i {
      flex: 1 1 0;
      max-width: 8px;
      height: calc(var(--h) * 100%);
      min-height: 4px;
      border-radius: 99px;
      background: var(--brand-accent);
      opacity: calc(.3 + var(--h) * .7);
      transform-origin: center;
      transition: height .14s ease-out;
    }
    html:not([data-theme="light"]) .cw-wave i { background: var(--accent-text); }
    @media (prefers-reduced-motion: no-preference) {
      .cw-wave i { animation: cw-breathe 2.8s ease-in-out infinite; animation-delay: calc(var(--i) * -110ms); }
      .cw-wave.is-voicing i { animation: none; }
      .cw-wave.is-sent i { animation: cw-sent .9s cubic-bezier(.3, .7, .2, 1) both; animation-delay: calc(var(--i) * 14ms); }
      /* The one entrance: the wave rises from a flat line when the page opens. */
      .cw-wave.is-rising i { animation: cw-rise .8s cubic-bezier(.2, .8, .2, 1) both; animation-delay: calc(var(--i) * 12ms); }
    }
    @keyframes cw-breathe { 0%, 100% { transform: scaleY(1); } 50% { transform: scaleY(.55); } }
    @keyframes cw-rise { from { transform: scaleY(.04); } to { transform: scaleY(1); } }
    @keyframes cw-sent { 0% { transform: scaleY(1); } 40% { transform: scaleY(.06); } 100% { transform: scaleY(1); } }

    /* Other ways to reach the team */
    .contact-ways { grid-area: ways; align-self: end; margin-top: clamp(2rem, 4vw, 3rem); }
    .contact-ways h2 { margin: 0 0 .9rem; color: var(--muted); font-size: .95rem; font-weight: 600; }
    .contact-ways ul { display: grid; gap: .65rem; margin: 0; padding: 0; list-style: none; }
    .contact-way {
      display: flex;
      align-items: center;
      gap: .9rem;
      padding: .85rem 1rem;
      border: 1px solid var(--line);
      border-radius: var(--card-radius);
      background: var(--surface);
      box-shadow: var(--card-shadow);
      color: var(--text);
      text-decoration: none;
      transition: border-color .2s ease;
    }
    .contact-way:hover { border-color: color-mix(in oklab, var(--text) 18%, var(--line)); }
    .contact-way:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 3px; }
    .contact-way-icon { display: grid; place-items: center; flex: none; width: 42px; height: 42px; border-radius: .75rem; background: color-mix(in oklab, var(--a) 10%, var(--surface)); color: var(--accent-text); }
    .contact-way-icon svg { width: 20px; height: 20px; }
    .contact-way-text { display: flex; flex-direction: column; min-width: 0; gap: .1rem; }
    .contact-way-text strong { font-size: .98rem; font-weight: 700; }
    .contact-way-text span { color: var(--muted); font-size: .88rem; overflow-wrap: anywhere; }

    /* The form */
    .contact-card {
      grid-area: card;
      align-self: start;
      padding: clamp(1.5rem, 3.4vw, 2.5rem);
      border: 1px solid var(--line);
      border-radius: var(--card-radius);
      background: var(--surface);
      box-shadow: var(--card-shadow);
    }
    .contact-topics { margin: 0 0 1.5rem; padding: 0; border: 0; }
    .contact-topics legend { margin-bottom: .7rem; padding: 0; color: var(--text); font-size: .95rem; font-weight: 700; }
    .contact-topic-list { display: flex; flex-wrap: wrap; gap: .5rem; }
    .contact-topic {
      min-height: 40px;
      padding: 0 1rem;
      border: 1px solid var(--line-strong);
      border-radius: 999px;
      background: var(--surface);
      color: var(--muted);
      font: inherit;
      font-size: .9rem;
      font-weight: 600;
      cursor: pointer;
      transition: color .15s ease, border-color .15s ease, background-color .15s ease;
    }
    .contact-topic:hover { color: var(--text); border-color: color-mix(in oklab, var(--text) 45%, var(--line-strong)); }
    /* Chosen: the same solid violet chip as the voice filters. */
    .contact-topic[aria-pressed="true"] { border-color: var(--brand-accent); background: var(--brand-accent); color: #FFFFFF; }
    .contact-topic:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 2px; }

    .contact-form { display: grid; gap: 1.15rem; }
    .contact-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.15rem; }
    .contact-field { display: grid; gap: .45rem; }
    .contact-field label { color: var(--text); font-size: .92rem; font-weight: 600; }
    .contact-field input,
    .contact-field textarea {
      width: 100%;
      padding: .85rem 1rem;
      border: 1px solid var(--line-strong);
      border-radius: .75rem;
      background: var(--surface);
      color: var(--text);
      font: inherit;
      font-size: 1rem;
      transition: border-color .15s ease, box-shadow .15s ease;
    }
    .contact-field textarea { min-height: 9.5rem; resize: vertical; line-height: 1.6; }
    .contact-field input::placeholder, .contact-field textarea::placeholder { color: var(--muted); opacity: .75; }
    .contact-field input:focus, .contact-field textarea:focus {
      outline: none;
      border-color: var(--brand-accent);
      box-shadow: 0 0 0 4px color-mix(in oklab, var(--brand-accent) 16%, transparent);
    }
    .contact-field [aria-invalid="true"] { border-color: var(--danger); }
    .contact-field .text-danger { color: var(--danger); font-size: .86rem; }
    .contact-send {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: .6rem;
      width: 100%;
      min-height: 48px;
      margin-top: .35rem;
      border: 0;
      border-radius: var(--btn-radius);
      background: var(--brand-accent);
      box-shadow: var(--lift);
      color: #FFFFFF;
      font: inherit;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: background-color .2s ease;
    }
    .contact-send:hover { background: var(--brand-hover); }
    .contact-send:focus-visible { outline: 2px solid var(--brand-accent); outline-offset: 3px; }
    .contact-send:disabled { opacity: .7; cursor: progress; }
    .contact-send svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    html[dir="rtl"] .contact-send svg { transform: scaleX(-1); }
    #contactAlertContainer .alert { margin-bottom: 1.25rem; }

    @media (max-width: 960px) {
      .contact-grid {
        padding: clamp(2rem, 6vw, 2.75rem) 0 clamp(2.5rem, 8vw, 3.5rem);
        grid-template-columns: minmax(0, 1fr);
        grid-template-areas: "intro" "card" "ways";
        grid-template-rows: auto;
      }
      .contact-intro p { max-width: 52ch; }
      .cw-wave { height: 80px; margin: 1.5rem 0 1.75rem; }
      /* Below the form: the other ways to reach the team. */
      .contact-ways { margin-top: 2rem; }
    }
    @media (max-width: 560px) {
      .contact-row { grid-template-columns: minmax(0, 1fr); }
      .cw-wave { gap: 3px; }
    }
  </style>
</head>
<body>
  @include('components.header')

  <main class="contact-page">
    <div class="contact-shell">
      <div class="contact-grid">
        <section class="contact-intro" aria-labelledby="contactTitle">
          <h1 id="contactTitle">{{ __('ui.contact.title') }}</h1>
          <p>{{ __('ui.contact.subtitle') }}</p>
          <div class="cw-wave is-rising" data-contact-wave aria-hidden="true">
            @foreach($contactBars as $i => $h)<i style="--h: {{ $h }}; --i: {{ $i }}"></i>@endforeach
          </div>
        </section>

        <div class="contact-card">
          <div id="contactAlertContainer" aria-live="polite">
            @if(session('success'))
              <div class="alert alert-success">
                <svg class="alert-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                {{ session('success') }}
              </div>
            @endif

            @if(session('error'))
              <div class="alert alert-error">
                <svg class="alert-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                {{ session('error') }}
              </div>
            @endif
          </div>

          {{-- A topic fills in the subject; it can still be changed by hand. --}}
          <fieldset class="contact-topics">
            <legend>{{ __('ui.contact.topic_label') }}</legend>
            <div class="contact-topic-list">
              @foreach($contactTopics as $topic)
                <button type="button" class="contact-topic" data-contact-topic="{{ __('ui.contact.topic_'.$topic) }}" aria-pressed="false">{{ __('ui.contact.topic_'.$topic) }}</button>
              @endforeach
            </div>
          </fieldset>

          <form id="contactForm" action="{{ route('contact.submit') }}" method="POST" class="contact-form">
            @csrf
            <div class="contact-row">
              <div class="contact-field">
                <label for="name">{{ __('ui.contact.name') }}</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required autocomplete="name" placeholder="{{ __('ui.contact.name_placeholder') }}">
                <span class="text-danger" data-error-for="name" @unless($errors->has('name')) style="display: none;" @endunless>{{ $errors->first('name') }}</span>
              </div>
              <div class="contact-field">
                <label for="email">{{ __('ui.contact.email') }}</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="email" dir="ltr" placeholder="{{ __('ui.contact.email_placeholder') }}">
                <span class="text-danger" data-error-for="email" @unless($errors->has('email')) style="display: none;" @endunless>{{ $errors->first('email') }}</span>
              </div>
            </div>

            <div class="contact-field">
              <label for="subject">{{ __('ui.contact.subject') }}</label>
              <input type="text" id="subject" name="subject" value="{{ old('subject', $contactTopic) }}" required placeholder="{{ __('ui.contact.subject_placeholder') }}">
              <span class="text-danger" data-error-for="subject" @unless($errors->has('subject')) style="display: none;" @endunless>{{ $errors->first('subject') }}</span>
            </div>

            <div class="contact-field">
              <label for="message">{{ __('ui.contact.message') }}</label>
              <textarea id="message" name="message" rows="6" required placeholder="{{ __('ui.contact.message_placeholder') }}">{{ old('message') }}</textarea>
              <span class="text-danger" data-error-for="message" @unless($errors->has('message')) style="display: none;" @endunless>{{ $errors->first('message') }}</span>
            </div>

            <button type="submit" class="contact-send">
              <span data-submit-label>{{ __('ui.contact.send_message') }}</span>
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 2 11 13"/><path d="m22 2-7 20-4-9-9-4 20-7z"/></svg>
            </button>
          </form>
        </div>

        <aside class="contact-ways" aria-labelledby="contactWaysTitle">
          <h2 id="contactWaysTitle">{{ __('ui.contact.other_ways') }}</h2>
          <ul>
            <li>
              <a class="contact-way" href="{{ $contactWhatsappUrl }}" target="_blank" rel="noopener">
                <span class="contact-way-icon"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.05 2a9.9 9.9 0 0 0-8.5 14.96L2.2 22l5.2-1.36A9.9 9.9 0 1 0 12.05 2zm0 18.1a8.2 8.2 0 0 1-4.18-1.14l-.3-.18-3.1.81.83-3.02-.2-.31a8.2 8.2 0 1 1 6.95 3.84zm4.5-6.14c-.25-.12-1.46-.72-1.69-.8-.22-.08-.39-.12-.55.13-.17.24-.64.8-.78.96-.14.17-.29.19-.53.06a6.7 6.7 0 0 1-3.32-2.9c-.25-.43.25-.4.72-1.33.08-.17.04-.31-.02-.43l-.75-1.82c-.2-.48-.4-.41-.55-.42h-.47a.9.9 0 0 0-.66.31 2.77 2.77 0 0 0-.86 2.06 4.8 4.8 0 0 0 1 2.55 11 11 0 0 0 4.2 3.72c1.57.68 2.18.73 2.97.62.48-.07 1.46-.6 1.67-1.18.2-.58.2-1.08.14-1.18-.06-.1-.22-.17-.47-.29z"/></svg></span>
                <span class="contact-way-text">
                  <strong>{{ __('ui.contact.whatsapp') }}</strong>
                  <span>{{ __('ui.contact.whatsapp_hint') }} · <bdi dir="ltr">+{{ $contactWhatsapp }}</bdi></span>
                </span>
              </a>
            </li>
            <li>
              <a class="contact-way" href="mailto:{{ $contactEmail }}">
                <span class="contact-way-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg></span>
                <span class="contact-way-text">
                  <strong>{{ __('ui.contact.email_us') }}</strong>
                  <span><bdi dir="ltr">{{ $contactEmail }}</bdi></span>
                </span>
              </a>
            </li>
          </ul>
        </aside>
      </div>
    </div>
  </main>

  @include('components.footer')
  @include('components.scripts')
  @php
    $contactI18n = [
      'send_message' => __('ui.contact.send_message'),
      'sending' => __('ui.contact.sending'),
      'success' => __('ui.contact.success'),
      'fix_fields' => __('ui.contact.fix_fields'),
      'send_failed' => __('ui.contact.send_failed'),
      'network_error' => __('ui.contact.network_error'),
    ];
  @endphp
  <script>
    /* The wave: it rises once, breathes at rest, and moves with each letter typed. */
    (function () {
      var wave = document.querySelector('[data-contact-wave]');
      var form = document.getElementById('contactForm');
      if (!wave || !form) return;
      var bars = Array.prototype.slice.call(wave.children);
      var heights = bars.map(function (b) { return parseFloat(b.style.getPropertyValue('--h')) || .3; });
      var rest = 0;
      setTimeout(function () { wave.classList.remove('is-rising'); }, 1400);

      function letterHeight(code, k) {
        if (code === 32 || code === 10) return .14;
        return .3 + ((code * 37 + k * 11) % 70) / 100;
      }
      form.addEventListener('input', function (event) {
        var field = event.target;
        if (!field || typeof field.value !== 'string') return;
        var text = field.value;
        var code = text.length ? text.charCodeAt(text.length - 1) : 32;
        // The newest letter enters at the end of the wave, and the rest move along.
        heights.push(letterHeight(code, text.length));
        heights.shift();
        wave.classList.remove('is-rising', 'is-sent');
        wave.classList.add('is-voicing');
        bars.forEach(function (bar, k) { bar.style.setProperty('--h', heights[k].toFixed(3)); });
        clearTimeout(rest);
        rest = setTimeout(function () { wave.classList.remove('is-voicing'); }, 1200);
      });
      document.addEventListener('listen:contact-sent', function () {
        wave.classList.remove('is-voicing', 'is-rising');
        void wave.offsetWidth;
        wave.classList.add('is-sent');
        setTimeout(function () { wave.classList.remove('is-sent'); }, 1700);
      });
    })();

    /* A topic fills in the subject; a subject written by hand keeps its words, with the topic before them. */
    (function () {
      var subject = document.getElementById('subject');
      var topics = document.querySelectorAll('[data-contact-topic]');
      if (!subject || !topics.length) return;
      var labels = Array.prototype.map.call(topics, function (t) { return t.getAttribute('data-contact-topic'); });
      function topicOf(text) {
        for (var k = 0; k < labels.length; k++) {
          if (text === labels[k] || text.indexOf(labels[k] + ': ') === 0) return labels[k];
        }
        return null;
      }
      function mark() {
        var chosen = topicOf(subject.value.trim());
        topics.forEach(function (t) { t.setAttribute('aria-pressed', t.getAttribute('data-contact-topic') === chosen ? 'true' : 'false'); });
      }
      topics.forEach(function (topic) {
        topic.addEventListener('click', function () {
          var label = topic.getAttribute('data-contact-topic');
          var current = subject.value.trim();
          var previous = topicOf(current);
          var own = previous ? current.slice(previous.length).replace(/^:\s*/, '') : current;
          subject.value = own ? label + ': ' + own : label;
          mark();
        });
      });
      subject.addEventListener('input', mark);
      mark();
    })();

    (function () {
      var i18n = @json($contactI18n);
      var form = document.getElementById('contactForm');
      if (!form || !window.fetch) return;

      var submitButton = form.querySelector('button[type="submit"]');
      var submitLabel = form.querySelector('[data-submit-label]');
      var alertContainer = document.getElementById('contactAlertContainer');
      var defaultButtonText = submitLabel ? submitLabel.textContent : (i18n.send_message || 'Send message');

      var errorNodes = {};
      form.querySelectorAll('[data-error-for]').forEach(function (node) {
        var key = node.getAttribute('data-error-for');
        if (key) errorNodes[key] = node;
      });

      function renderAlert(kind, message) {
        if (!alertContainer) return;

        var icon;
        if (kind === 'success') {
          icon = '<svg class="alert-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
        } else {
          icon = '<svg class="alert-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
        }

        var box = document.createElement('div');
        box.className = 'alert alert-' + kind;
        box.innerHTML = icon;
        box.appendChild(document.createTextNode(message));
        alertContainer.innerHTML = '';
        alertContainer.appendChild(box);
      }

      function clearErrors() {
        Object.keys(errorNodes).forEach(function (field) {
          errorNodes[field].textContent = '';
          errorNodes[field].style.display = 'none';

          var input = form.querySelector('[name="' + field + '"]');
          if (input) input.removeAttribute('aria-invalid');
        });
      }

      function applyErrors(errors) {
        Object.keys(errors || {}).forEach(function (field) {
          if (!errorNodes[field]) return;
          errorNodes[field].textContent = Array.isArray(errors[field]) ? errors[field][0] : String(errors[field]);
          errorNodes[field].style.display = 'block';

          var input = form.querySelector('[name="' + field + '"]');
          if (input) input.setAttribute('aria-invalid', 'true');
        });
      }

      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        clearErrors();

        if (submitButton) submitButton.disabled = true;
        if (submitLabel) submitLabel.textContent = i18n.sending || 'Sending...';

        try {
          var response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
          });

          var payload = null;
          try {
            payload = await response.json();
          } catch (jsonError) {
            payload = null;
          }

          if (response.ok && payload && payload.ok) {
            form.reset();
            document.querySelectorAll('[data-contact-topic]').forEach(function (t) { t.setAttribute('aria-pressed', 'false'); });
            renderAlert('success', payload.message || i18n.success || 'Your message has been sent successfully!');
            document.dispatchEvent(new CustomEvent('listen:contact-sent'));
            return;
          }

          if (response.status === 422 && payload && payload.errors) {
            applyErrors(payload.errors);
            renderAlert('error', payload.message || i18n.fix_fields || 'Please correct the highlighted fields.');
            return;
          }

          renderAlert('error', (payload && payload.message) ? payload.message : (i18n.send_failed || 'Unable to send your message right now. Please try again.'));
        } catch (networkError) {
          renderAlert('error', i18n.network_error || 'Network error. Please check your connection and try again.');
        } finally {
          if (submitButton) submitButton.disabled = false;
          if (submitLabel) submitLabel.textContent = defaultButtonText;
        }
      });
    })();
  </script>
</body>
</html>
