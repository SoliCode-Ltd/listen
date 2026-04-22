@php
  use App\Models\ReferralCampaign;
  use App\Services\Referral\ReferralService;

  $isArabicLocale = str_starts_with(strtolower((string) app()->getLocale()), 'ar');
  $dir = $isArabicLocale ? 'rtl' : 'ltr';
  $brandLabel = $isArabicLocale ? 'منصة إسمع' : 'Listen Studio';
  $campaign = ReferralCampaign::active();
  $rewardAmount = (int) ($campaign?->reward_chars ?? 1000);

  $authUser = auth()->user();
  $initialLink = '';
  $initialCode = '';
  $initialStats = [
      'pending' => 0,
      'approved' => 0,
      'reward_chars_earned' => 0,
      'reward_chars_balance' => 0,
      'code' => '',
      'link_en' => '',
      'link_ar' => '',
  ];
  if ($authUser) {
      try {
          $referralService = app(ReferralService::class);
          $initialStats = $referralService->statsPayload($authUser);
          $initialLink = $isArabicLocale ? $initialStats['link_ar'] : $initialStats['link_en'];
          $initialCode = $initialStats['code'] ?? '';
      } catch (\Throwable $e) {
          // Fail silently — popup still renders, stats will hydrate via fetch.
      }
  }

  $shareTextRaw = str_replace(
      ['{brand}', '{reward_amount}', '{link}'],
      [$brandLabel, number_format($rewardAmount), $initialLink],
      (string) __('ui.referral.share_text_template')
  );
@endphp

{{-- Floating gift button: always-visible entry point to the referral popup --}}
<button type="button" id="referralGiftFab" class="ref-gift-fab" dir="{{ $dir }}"
        aria-label="{{ __('ui.referral.popup_badge') }}"
        title="{{ __('ui.referral.popup_badge') }}">
  <span class="ref-gift-fab__pulse" aria-hidden="true"></span>
  <span class="ref-gift-fab__icon" aria-hidden="true">
    <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">
      <path d="M20 12v9H4v-9"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>
    </svg>
  </span>
  <span class="ref-gift-fab__sparkle ref-gift-fab__sparkle--a" aria-hidden="true"></span>
  <span class="ref-gift-fab__sparkle ref-gift-fab__sparkle--b" aria-hidden="true"></span>
  <span class="ref-gift-fab__sparkle ref-gift-fab__sparkle--c" aria-hidden="true"></span>
</button>

{{-- Premium referral popup / modal --}}
<div id="referralModal" class="ref-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="refModalTitle" dir="{{ $dir }}" hidden>
  <div class="ref-modal__overlay" data-ref-dismiss></div>
  <div class="ref-modal__panel" role="document">
    <button type="button" class="ref-modal__close" data-ref-dismiss aria-label="{{ __('ui.referral.popup_close_aria') }}">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>

    <div class="ref-modal__accent" aria-hidden="true"></div>

    <header class="ref-modal__header">
      <div class="ref-modal__hero" aria-hidden="true">
        <span class="ref-modal__hero-burst"></span>
        <span class="ref-modal__hero-burst ref-modal__hero-burst--alt"></span>
        <div class="ref-modal__hero-gift">
          <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true">
            <path d="M20 12v9H4v-9"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>
          </svg>
        </div>
      </div>
      <h2 id="refModalTitle" class="ref-modal__title" data-ref-title>
        {{ str_replace('{reward_amount}', number_format($rewardAmount), __('ui.referral.popup_title')) }}
      </h2>
      <p class="ref-modal__desc" data-ref-desc>
        {{ str_replace('{reward_amount}', number_format($rewardAmount), __('ui.referral.popup_description')) }}
      </p>
      <ul class="ref-modal__chips" aria-hidden="false">
        <li class="ref-chip"><svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>{{ str_replace('{reward_amount}', number_format($rewardAmount), __('ui.referral.popup_benefit_1')) }}</li>
        <li class="ref-chip"><svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>{{ str_replace('{reward_amount}', number_format($rewardAmount), __('ui.referral.popup_benefit_2')) }}</li>
        <li class="ref-chip"><svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>{{ str_replace('{reward_amount}', number_format($rewardAmount), __('ui.referral.popup_benefit_3')) }}</li>
      </ul>
    </header>

    <div class="ref-modal__body">
      <label class="ref-modal__label" for="refLinkField">{{ __('ui.referral.your_link') }}</label>
      <div class="ref-link-row">
        <input type="text" id="refLinkField" class="ref-link-input" readonly dir="ltr" value="{{ $initialLink }}" aria-label="{{ __('ui.referral.your_link') }}">
        <button type="button" class="ref-link-copy" id="refLinkCopy">
          <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
          <span data-ref-copy-label>{{ __('ui.referral.copy_link') }}</span>
        </button>
      </div>

      <p class="ref-modal__share-label">{{ __('ui.referral.share_label') }}</p>
      <div class="ref-share-grid" role="group" aria-label="{{ __('ui.referral.share_label') }}">
        <button type="button" class="ref-share" data-platform="facebook" aria-label="Facebook">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
          <span>Facebook</span>
        </button>
        <button type="button" class="ref-share" data-platform="twitter" aria-label="X (Twitter)">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M18 3l-6.5 8.3L19 21h-5l-4.6-6L4 21H2l7-9L2 3h5l4.3 5.7L16 3z"/></svg>
          <span>X</span>
        </button>
        <button type="button" class="ref-share" data-platform="linkedin" aria-label="LinkedIn">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
          <span>LinkedIn</span>
        </button>
        <button type="button" class="ref-share" data-platform="whatsapp" aria-label="WhatsApp">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
            <path fill="currentColor" stroke="none" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.15-.174.2-.298.3-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.611-.916-2.207-.242-.579-.487-.5-.67-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347Zm-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.999-3.648-.235-.374A9.86 9.86 0 0 1 2.1 12.25c0-5.451 4.436-9.887 9.889-9.887 2.64 0 5.122 1.03 6.988 2.898 1.866 1.868 2.893 4.35 2.892 6.99 0 5.452-4.437 9.888-9.887 9.888ZM20.52 3.449C18.24 1.245 15.24 0 12.045 0 5.463 0 .104 5.335.101 11.892c0 2.096.549 4.14 1.595 5.945L0 24l6.335-1.652a12.062 12.062 0 0 0 5.71 1.447h.005c6.585 0 11.946-5.336 11.949-11.893 0-3.18-1.24-6.165-3.495-8.413Z"/>
          </svg>
          <span>WhatsApp</span>
        </button>
        <button type="button" class="ref-share" data-platform="telegram" aria-label="Telegram">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          <span>Telegram</span>
        </button>
        <button type="button" class="ref-share" data-platform="instagram" aria-label="Instagram">
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
          <span>Instagram</span>
        </button>
      </div>

      <div class="ref-modal__toast" id="refModalToast" role="status" aria-live="polite" aria-hidden="true"></div>
    </div>
  </div>
</div>

@php
  $referralBootPayload = [
      'locale' => $isArabicLocale ? 'ar' : 'en',
      'statsUrl' => route('referral.stats'),
      'shareUrl' => route('referral.share'),
      'csrfToken' => csrf_token(),
      'initial' => $initialStats,
      'shareText' => $shareTextRaw,
      'copy' => [
          'copy_success' => __('ui.referral.copy_success'),
          'instagram_hint' => __('ui.referral.instagram_hint'),
          'share_paste_hint' => __('ui.referral.share_paste_hint'),
          'copy_link' => __('ui.referral.copy_link'),
      ],
  ];
@endphp
<script type="application/json" id="referralBootData">
{!! json_encode($referralBootPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
<script>
(function () {
  const modal = document.getElementById('referralModal');
  const bootEl = document.getElementById('referralBootData');
  if (!modal || !bootEl) return;

  let boot = {};
  try { boot = JSON.parse(bootEl.textContent || '{}'); } catch (e) { boot = {}; }

  const DISMISS_KEY = 'listen_ref_popup_dismissed_v1';
  const locale = boot.locale === 'ar' ? 'ar' : 'en';
  const copyStrings = boot.copy || {};

  const csrfHeaders = {
    'X-CSRF-TOKEN': boot.csrfToken || '',
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  };

  const linkInput = document.getElementById('refLinkField');
  const copyBtn = document.getElementById('refLinkCopy');
  const copyLabelNode = modal.querySelector('[data-ref-copy-label]');
  const statsNodes = modal.querySelectorAll('[data-ref-stat]');
  const shareBtns = modal.querySelectorAll('.ref-share');

  let currentStats = boot.initial || null;
  let lastFocused = null;

  function isDismissed() {
    try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
  }
  function setDismissed(v) {
    try { localStorage.setItem(DISMISS_KEY, v ? '1' : '0'); } catch (e) {}
  }

  function formatNumber(n) {
    const num = Number(n || 0);
    try { return new Intl.NumberFormat(locale === 'ar' ? 'ar-EG' : 'en-US').format(num); }
    catch (e) { return String(num); }
  }

  function applyStats(stats) {
    if (!stats) return;
    currentStats = stats;
    const link = locale === 'ar' ? (stats.link_ar || '') : (stats.link_en || '');
    if (linkInput && link) linkInput.value = link;
    statsNodes.forEach((node) => {
      const key = node.getAttribute('data-ref-stat');
      if (!key) return;
      if (stats[key] === undefined || stats[key] === null) return;
      node.textContent = formatNumber(stats[key]);
    });
  }

  async function fetchStats() {
    if (!boot.statsUrl) return;
    try {
      const res = await fetch(boot.statsUrl, { headers: csrfHeaders, credentials: 'same-origin' });
      if (!res.ok) return;
      const data = await res.json();
      if (data && data.stats) applyStats(data.stats);
    } catch (e) {}
  }

  async function logShare(platform) {
    if (!boot.shareUrl) return;
    try {
      const res = await fetch(boot.shareUrl, {
        method: 'POST',
        headers: { ...csrfHeaders, 'Content-Type': 'application/json' },
        body: JSON.stringify({ platform }),
        credentials: 'same-origin',
      });
      if (!res.ok) return;
      const data = await res.json();
      if (data && data.stats) applyStats(data.stats);
    } catch (e) {}
  }

  function showModal() {
    lastFocused = document.activeElement;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('ref-modal-open');
    requestAnimationFrame(() => modal.classList.add('is-open'));
    setTimeout(() => {
      const firstFocus = modal.querySelector('.ref-modal__close');
      firstFocus && firstFocus.focus();
    }, 60);
    fetchStats();
  }

  function hideModal(dismiss) {
    modal.classList.remove('is-open');
    setTimeout(() => {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('ref-modal-open');
    }, 220);
    if (dismiss) setDismissed(true);
    if (lastFocused && typeof lastFocused.focus === 'function') {
      try { lastFocused.focus(); } catch (e) {}
    }
  }

  async function copyLink() {
    const link = (linkInput && linkInput.value) || '';
    if (!link) return;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(link);
      } else if (linkInput) {
        linkInput.removeAttribute('readonly');
        linkInput.select();
        document.execCommand('copy');
        linkInput.setAttribute('readonly', 'readonly');
        linkInput.blur();
      }
    } catch (e) {}
    const prev = copyLabelNode ? copyLabelNode.textContent : '';
    if (copyLabelNode) copyLabelNode.textContent = copyStrings.copy_success || 'Copied';
    setTimeout(() => { if (copyLabelNode) copyLabelNode.textContent = prev || copyStrings.copy_link || ''; }, 1600);
    logShare('copy_link');
  }

  function ensureLinkInText(text, link) {
    const base = String(text || '').trim();
    const url = String(link || '').trim();
    if (!url) return base;
    if (base.indexOf(url) !== -1) return base;
    return base ? (base + ' ' + url) : url;
  }

  function buildShareUrl(platform, link, text) {
    const enc = encodeURIComponent;
    const combined = ensureLinkInText(text, link);
    const t = enc(text || '');
    const tc = enc(combined);
    const l = enc(link || '');
    switch (platform) {
      // Facebook ignores `quote` unless app is whitelisted; link is the primary carrier.
      case 'facebook': return 'https://www.facebook.com/sharer/sharer.php?u=' + l + '&quote=' + t;
      case 'twitter': return 'https://twitter.com/intent/tweet?text=' + tc;
      case 'linkedin': return 'https://www.linkedin.com/sharing/share-offsite/?url=' + l;
      // WhatsApp: include both text and link in the single text payload for reliability.
      case 'whatsapp': return 'https://api.whatsapp.com/send?text=' + tc;
      case 'telegram': return 'https://t.me/share/url?url=' + l + '&text=' + t;
      // Instagram has no web composer — open the app/site; user pastes into story/bio/DM.
      case 'instagram': return 'https://www.instagram.com/';
      default: return null;
    }
  }

  async function copyTextSilently(text) {
    if (!text) return false;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch (e) {}
    try {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', 'readonly');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      return true;
    } catch (e) { return false; }
  }

  async function handleShare(btn) {
    const platform = btn.getAttribute('data-platform');
    if (!platform) return;
    const link = (linkInput && linkInput.value)
      || (currentStats ? (locale === 'ar' ? currentStats.link_ar : currentStats.link_en) : '');
    const text = boot.shareText || '';
    const combined = ensureLinkInText(text, link);

    // Facebook, LinkedIn and Instagram composers ignore prefill text by platform
    // policy. Strategy: copy the ready-made post, surface a banner telling the
    // user to paste it, and delay the new-tab open briefly so the hint registers.
    if (platform === 'facebook' || platform === 'linkedin' || platform === 'instagram') {
      const ok = await copyTextSilently(combined);
      if (ok) {
        showToast(platform === 'instagram'
          ? (copyStrings.instagram_hint || copyStrings.share_paste_hint || '')
          : (copyStrings.share_paste_hint || ''));
      }
      const url = buildShareUrl(platform, link, text);
      setTimeout(() => {
        if (url) window.open(url, '_blank', 'noopener,noreferrer,width=680,height=620');
      }, 450);
      logShare(platform);
      return;
    }

    const url = buildShareUrl(platform, link, text);
    if (url) {
      window.open(url, '_blank', 'noopener,noreferrer,width=680,height=560');
    }
    logShare(platform);
  }

  const toastEl = document.getElementById('refModalToast');
  let toastTimer = null;
  function showToast(message) {
    if (!toastEl || !message) return;
    toastEl.innerHTML = '';
    const icon = document.createElement('span');
    icon.className = 'ref-modal__toast-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16"><polyline points="20 6 9 17 4 12" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    const text = document.createElement('span');
    text.className = 'ref-modal__toast-text';
    text.textContent = message;
    toastEl.appendChild(icon);
    toastEl.appendChild(text);
    toastEl.classList.add('is-visible');
    toastEl.setAttribute('aria-hidden', 'false');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      toastEl.classList.remove('is-visible');
      toastEl.setAttribute('aria-hidden', 'true');
    }, 6000);
  }

  modal.addEventListener('click', (e) => {
    const t = e.target;
    if (!(t instanceof Element)) return;
    if (t.closest('[data-ref-dismiss]')) hideModal(true);
  });

  copyBtn && copyBtn.addEventListener('click', copyLink);
  shareBtns.forEach((btn) => btn.addEventListener('click', () => handleShare(btn)));

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.hidden) hideModal(true);
  });

  // Public API:
  //   openReferralPopup({ force: true }) — show even if previously dismissed
  //   openReferralPopup()                — show unless previously dismissed
  window.openReferralPopup = function (opts) {
    const force = !!(opts && opts.force);
    if (!force && isDismissed()) return false;
    showModal();
    return true;
  };
  window.isReferralPopupDismissed = function () { return isDismissed(); };

  // Floating gift button — always-on entry point.
  const giftFab = document.getElementById('referralGiftFab');
  if (giftFab) {
    giftFab.addEventListener('click', () => { window.openReferralPopup({ force: true }); });
  }
})();
</script>
