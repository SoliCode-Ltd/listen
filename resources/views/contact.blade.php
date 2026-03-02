@include('components.page-head', ['pageTitle' => __('ui.seo.contact_title'), 'page' => 'contact', 'theme' => (\App\Support\SeoHelper::defaultTheme() ?? 'dark')])
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
    @keyframes float {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-14px); }
    }
  </style>
</head>
<body>
  <div class="bg-orb a"></div>
  <div class="bg-orb b"></div>
  @include('components.header')

  <main class="page-content contact-page page-wrapper">
    <div class="contact-hero">
      <div class="hero-bg-accent" aria-hidden="true"></div>
      <div class="container text-left" style="max-width: 740px; margin: 0 auto; z-index: 10; position: relative; padding-bottom: 1rem;">
        <h1 class="hero-title animate-in" style="font-size: clamp(2.75rem, 5vw, 4rem); font-weight: 800; letter-spacing: -.03em; margin-bottom: 1.25rem; font-family: var(--font-display); background: linear-gradient(135deg, var(--text) 40%, color-mix(in oklab, var(--text) 40%, transparent)); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">{{ __('ui.contact.title') }}</h1>
        <p class="hero-subtitle animate-in" style="animation-delay: 0.1s; color: var(--muted); font-size: 1.2rem; line-height: 1.6; margin-bottom: 0; max-width: 580px;">
          {{ __('ui.contact.subtitle') }}
        </p>
      </div>
    </div>

    <div class="container pb-120" style="max-width: 740px; margin: 0 auto; padding-bottom: 6rem; position: relative; z-index: 10;">
      <div class="contact-form-card animate-in" style="animation-delay: 0.2s;">
        
        <div id="contactAlertContainer" aria-live="polite">
          @if(session('success'))
            <div class="alert alert-success">
              <svg class="alert-icon" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
              {{ session('success') }}
            </div>
          @endif

          @if(session('error'))
            <div class="alert alert-error">
              <svg class="alert-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
              {{ session('error') }}
            </div>
          @endif
        </div>

        <form id="contactForm" action="{{ route('contact.submit') }}" method="POST" class="styled-form">
          @csrf
          
          <div class="form-grid">
            <div class="form-group">
              <label for="name">{{ __('ui.contact.name') }}</label>
              <input type="text" id="name" name="name" class="form-control" value="{{ old('name') }}" required placeholder="{{ __('ui.contact.name_placeholder') }}">
              <span class="text-danger" data-error-for="name" @unless($errors->has('name')) style="display: none;" @endunless>{{ $errors->first('name') }}</span>
            </div>
            
            <div class="form-group">
              <label for="email">{{ __('ui.contact.email') }}</label>
              <input type="email" id="email" name="email" class="form-control" value="{{ old('email') }}" required placeholder="{{ __('ui.contact.email_placeholder') }}">
              <span class="text-danger" data-error-for="email" @unless($errors->has('email')) style="display: none;" @endunless>{{ $errors->first('email') }}</span>
            </div>
          </div>
          
          <div class="form-group">
            <label for="subject">{{ __('ui.contact.subject') }}</label>
            <input type="text" id="subject" name="subject" class="form-control" value="{{ old('subject') }}" required placeholder="{{ __('ui.contact.subject_placeholder') }}">
            <span class="text-danger" data-error-for="subject" @unless($errors->has('subject')) style="display: none;" @endunless>{{ $errors->first('subject') }}</span>
          </div>
          
          <div class="form-group">
            <label for="message">{{ __('ui.contact.message') }}</label>
            <textarea id="message" name="message" class="form-control" rows="5" required placeholder="{{ __('ui.contact.message_placeholder') }}">{{ old('message') }}</textarea>
            <span class="text-danger" data-error-for="message" @unless($errors->has('message')) style="display: none;" @endunless>{{ $errors->first('message') }}</span>
          </div>
          
          <div class="form-submit">
            <button type="submit" class="btn btn-primary btn-lg btn-block contact-submit-btn">
              <span data-submit-label>{{ __('ui.contact.send_message') }}</span>
              <svg viewBox="0 0 24 24" class="btn-icon-right"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
            </button>
          </div>
        </form>
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
    (function () {
      var i18n = @json($contactI18n);
      var form = document.getElementById('contactForm');
      if (!form || !window.fetch) return;

      var submitButton = form.querySelector('button[type="submit"]');
      var submitLabel = form.querySelector('[data-submit-label]');
      var alertContainer = document.getElementById('contactAlertContainer');
      var defaultButtonText = submitLabel ? submitLabel.textContent : (i18n.send_message || 'Send Message');

      var errorNodes = {};
      form.querySelectorAll('[data-error-for]').forEach(function (node) {
        var key = node.getAttribute('data-error-for');
        if (key) errorNodes[key] = node;
      });

      function renderAlert(kind, message) {
        if (!alertContainer) return;

        var icon;
        if (kind === 'success') {
          icon = '<svg class="alert-icon" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
        } else {
          icon = '<svg class="alert-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
        }

        alertContainer.innerHTML = '<div class="alert alert-' + kind + '">' + icon + message + '</div>';
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
            renderAlert('success', payload.message || i18n.success || 'Your message has been sent successfully!');
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

