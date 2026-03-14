@php
  $locale = app()->getLocale();
  $isAr = $locale === 'ar';
  $whatsappNumber = '201553212603';
  $whatsappMessage = $isAr
    ? 'مرحباً، أريد الاستفسار عن خدمات منصة إسمع'
    : 'Hello, I would like to ask about Listen Studio services';
  $whatsappUrl = 'https://wa.me/' . $whatsappNumber . '?text=' . urlencode($whatsappMessage);
@endphp

<div id="whatsappWidget" class="wa-widget" aria-label="{{ $isAr ? 'تواصل عبر واتساب' : 'Contact via WhatsApp' }}">
  {{-- Floating Button --}}
  <button
    id="waFloatingBtn"
    class="wa-fab"
    type="button"
    aria-label="{{ $isAr ? 'فتح واتساب' : 'Open WhatsApp' }}"
    aria-expanded="false"
    aria-controls="waPanel"
  >
    <svg class="wa-fab-icon wa-icon-whatsapp" viewBox="0 0 24 24" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
    <svg class="wa-fab-icon wa-icon-close" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>
  </button>

  {{-- Panel --}}
  <div id="waPanel" class="wa-panel" role="dialog" aria-modal="false" aria-label="{{ $isAr ? 'تواصل معنا عبر واتساب' : 'Contact us on WhatsApp' }}">
    <div class="wa-panel-inner">
      {{-- Header --}}
      <div class="wa-panel-header">
        <div class="wa-panel-header-content">
          <div class="wa-panel-avatar">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
          </div>
          <div class="wa-panel-info">
            <h3 class="wa-panel-heading">{{ $isAr ? 'تواصل معنا' : 'Contact Us' }}</h3>
            <span class="wa-panel-brand">{{ $isAr ? 'منصة إسمع' : 'Listen Studio' }}</span>
          </div>
        </div>
        <button id="waPanelClose" class="wa-panel-close" type="button" aria-label="{{ $isAr ? 'إغلاق' : 'Close' }}">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>
        </button>
      </div>

      {{-- Body --}}
      <div class="wa-panel-body">
        <p class="wa-panel-text">{{ $isAr ? 'فريق منصة إسمع جاهز لمساعدتك عبر واتساب' : 'The Listen Studio team is ready to help you on WhatsApp' }}</p>
      </div>

      {{-- CTA --}}
      <div class="wa-panel-footer">
        <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener noreferrer" class="wa-cta-btn">
          <span>{{ $isAr ? 'ابدأ المحادثة على واتساب' : 'Start WhatsApp Chat' }}</span>
        </a>
      </div>
    </div>
  </div>
</div>

<style id="wa-widget-styles">
/* ===== WHATSAPP WIDGET ===== */
.wa-widget {
  position: fixed;
  bottom: 1.5rem;
  right: 1.5rem;
  z-index: 90;
  font-family: var(--font-body);
}

html[dir="rtl"] .wa-widget {
  right: auto;
  left: 1.5rem;
  font-family: var(--font-body-ar);
}

/* Floating Action Button */
.wa-fab {
  width: 56px;
  height: 56px;
  border-radius: var(--radius-full);
  border: none;
  background: #25D366;
  color: #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 16px rgba(37, 211, 102, 0.4), 0 2px 8px rgba(0, 0, 0, 0.15);
  transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.3s ease;
  position: relative;
  z-index: 2;
}

.wa-fab:hover {
  transform: scale(1.08);
  box-shadow: 0 6px 24px rgba(37, 211, 102, 0.5), 0 4px 12px rgba(0, 0, 0, 0.2);
}

.wa-fab:active {
  transform: scale(0.95);
}

.wa-fab:focus-visible {
  outline: 2px solid var(--text);
  outline-offset: 3px;
}

.wa-fab-icon {
  width: 28px;
  height: 28px;
  transition: opacity 0.25s ease, transform 0.25s ease;
  position: absolute;
}

.wa-fab-icon.wa-icon-whatsapp {
  fill: #fff;
}

.wa-fab-icon.wa-icon-close {
  opacity: 0;
  transform: rotate(-90deg) scale(0.5);
}

.wa-widget.open .wa-fab-icon.wa-icon-whatsapp {
  opacity: 0;
  transform: rotate(90deg) scale(0.5);
}

.wa-widget.open .wa-fab-icon.wa-icon-close {
  opacity: 1;
  transform: rotate(0deg) scale(1);
}

.wa-widget.open .wa-fab {
  background: var(--surface);
  border: 1px solid var(--line);
  color: var(--muted);
  box-shadow: var(--shadow);
}

.wa-widget.open .wa-fab:hover {
  color: var(--text);
  box-shadow: var(--shadow);
}

/* Panel */
.wa-panel {
  position: absolute;
  bottom: calc(100% + 0.75rem);
  right: 0;
  width: 340px;
  max-width: calc(100vw - 2rem);
  background: var(--surface);
  border: 1px solid var(--line);
  border-radius: var(--radius-lg);
  box-shadow: 0 20px 48px rgba(0, 0, 0, 0.25), 0 0 0 1px color-mix(in oklab, var(--line) 30%, transparent);
  opacity: 0;
  visibility: hidden;
  transform: translateY(12px) scale(0.96);
  transform-origin: bottom right;
  transition: opacity 0.25s cubic-bezier(0.4, 0, 0.2, 1),
              visibility 0.25s ease,
              transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
  overflow: hidden;
  pointer-events: none;
}

html[dir="rtl"] .wa-panel {
  right: auto;
  left: 0;
  transform-origin: bottom left;
}

html[data-theme="light"] .wa-panel {
  box-shadow: 0 20px 48px rgba(0, 0, 0, 0.08), 0 0 0 1px color-mix(in oklab, var(--line) 50%, transparent);
}

.wa-widget.open .wa-panel {
  opacity: 1;
  visibility: visible;
  transform: translateY(0) scale(1);
  pointer-events: auto;
}

.wa-panel-inner {
  display: flex;
  flex-direction: column;
}

/* Panel Header */
.wa-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1.1rem 1.25rem;
  background: #25D366;
  color: #fff;
}

.wa-panel-header-content {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.wa-panel-avatar {
  width: 40px;
  height: 40px;
  min-width: 40px;
  border-radius: var(--radius-full);
  background: rgba(255, 255, 255, 0.2);
  display: flex;
  align-items: center;
  justify-content: center;
}

.wa-panel-avatar svg {
  width: 22px;
  height: 22px;
  fill: #fff;
}

.wa-panel-info {
  min-width: 0;
}

.wa-panel-heading {
  font-size: 1rem;
  font-weight: 700;
  margin: 0;
  line-height: 1.3;
  color: #fff;
  letter-spacing: 0;
}

.wa-panel-brand {
  font-size: 0.8rem;
  font-weight: 500;
  opacity: 0.85;
  line-height: 1.3;
}

.wa-panel-close {
  width: 30px;
  height: 30px;
  min-width: 30px;
  border-radius: var(--radius-full);
  border: none;
  background: rgba(255, 255, 255, 0.15);
  color: #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.2s ease;
}

.wa-panel-close:hover {
  background: rgba(255, 255, 255, 0.3);
}

.wa-panel-close:focus-visible {
  outline: 2px solid #fff;
  outline-offset: 2px;
}

.wa-panel-close svg {
  width: 16px;
  height: 16px;
}

/* Panel Body */
.wa-panel-body {
  padding: 1.25rem;
}

.wa-panel-text {
    font-size: 0.95rem;
    line-height: 1.6;
    color: var(--muted);
    margin: 0;
    font-weight: 450;
}

/* Panel Footer / CTA */
.wa-panel-footer {
  padding: 0 1.25rem 1.25rem;
}

.wa-cta-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.6rem;
  width: 100%;
  padding: 0.85rem 1.25rem;
  border-radius: var(--btn-radius);
  background: #25D366;
  color: #fff;
  font-family: inherit;
  font-size: 0.95rem;
  font-weight: 700;
  text-decoration: none;
  border: none;
  cursor: pointer;
  transition: background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
  box-shadow: 0 2px 8px rgba(37, 211, 102, 0.3);
}

.wa-cta-btn:hover {
  background: #22c55e;
  transform: translateY(-1px);
  box-shadow: 0 4px 16px rgba(37, 211, 102, 0.4);
}

.wa-cta-btn:active {
  transform: translateY(0);
}

.wa-cta-btn:focus-visible {
  outline: 2px solid var(--text);
  outline-offset: 3px;
}

.wa-cta-icon {
  width: 20px;
  height: 20px;
  fill: #fff;
  flex-shrink: 0;
}

/* ===== RESPONSIVE ===== */
@media (max-width: 640px) {
  .wa-widget {
    bottom: 1rem;
    right: 1rem;
  }

  html[dir="rtl"] .wa-widget {
    right: auto;
    left: 1rem;
  }

  .wa-fab {
    width: 52px;
    height: 52px;
  }

  .wa-fab-icon {
    width: 26px;
    height: 26px;
  }

  .wa-panel {
    position: fixed;
    bottom: calc(52px + 1rem + 0.75rem);
    right: 1rem;
    left: 1rem;
    width: auto;
    max-width: none;
    transform-origin: bottom right;
  }

  html[dir="rtl"] .wa-panel {
    right: 1rem;
    left: 1rem;
    transform-origin: bottom left;
  }

  .wa-panel-header {
    padding: 1rem;
  }

  .wa-panel-body {
    padding: 1rem;
  }

  .wa-panel-footer {
    padding: 0 1rem 1rem;
  }

  .wa-cta-btn {
    font-size: 0.9rem;
    padding: 0.8rem 1rem;
  }
}

/* ===== RTL adjustments ===== */
html[dir="rtl"] .wa-panel-header {
  direction: rtl;
}

html[dir="rtl"] .wa-panel-body {
  direction: rtl;
  text-align: right;
}

html[dir="rtl"] .wa-panel-footer {
  direction: rtl;
}

html[dir="rtl"] .wa-panel-heading {
  letter-spacing: 0;
  line-height: 1.4;
}
</style>

<script id="wa-widget-script">
(function() {
  if (window.__waWidgetBooted) return;
  window.__waWidgetBooted = true;

  function initWhatsAppWidget() {
    var widget = document.getElementById('whatsappWidget');
    var fab = document.getElementById('waFloatingBtn');
    var panel = document.getElementById('waPanel');
    var closeBtn = document.getElementById('waPanelClose');
    if (!widget || !fab || !panel) return;

    function openPanel() {
      widget.classList.add('open');
      fab.setAttribute('aria-expanded', 'true');
      panel.setAttribute('aria-hidden', 'false');
    }

    function closePanel() {
      widget.classList.remove('open');
      fab.setAttribute('aria-expanded', 'false');
      panel.setAttribute('aria-hidden', 'true');
    }

    function togglePanel() {
      if (widget.classList.contains('open')) {
        closePanel();
      } else {
        openPanel();
      }
    }

    fab.addEventListener('click', function(e) {
      e.stopPropagation();
      togglePanel();
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        closePanel();
        fab.focus();
      });
    }

    // Close on click outside
    document.addEventListener('click', function(e) {
      if (widget.classList.contains('open') && !widget.contains(e.target)) {
        closePanel();
      }
    });

    // Close on Escape
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && widget.classList.contains('open')) {
        closePanel();
        fab.focus();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWhatsAppWidget);
  } else {
    initWhatsAppWidget();
  }
})();
</script>
