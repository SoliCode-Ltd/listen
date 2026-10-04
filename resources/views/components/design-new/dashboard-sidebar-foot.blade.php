@php
  $ntIsFreePlan = strtolower(trim((string) ($activePlan?->slug ?? 'free'))) === 'free';
  $ntCharsLeft = max(0, (int) ($planChars ?? 0) - (int) ($usage->total_chars ?? 0));
  $ntCharsLeftPercent = max(0, 100 - (int) ($usageCharsPercent ?? 0));
@endphp
<div class="nt-side-extra">
  <p class="nt-side-heading">{{ __('design.dashboard.account') }}</p>
  <nav class="nt-side-links" aria-label="{{ __('design.dashboard.account') }}">
    <a class="nt-side-link" href="{{ route('billing.page') }}" data-nt-icon="billing"><span>{{ __('ui.nav.billing') }}</span></a>
    <a class="nt-side-link" href="{{ route('manage-subscription.page') }}" data-nt-icon="subscription"><span>{{ __('ui.nav.manage_subscription') }}</span></a>
    <a class="nt-side-link" href="{{ route('browsers-extension.page') }}" data-nt-icon="extension"><span>{{ __('ui.nav.extension') }}</span></a>
  </nav>

  <div class="nt-plan-card">
    <p class="nt-plan-label">{{ __('design.dashboard.plan_label') }}</p>
    <p class="nt-plan-name">{{ $activePlanDisplayName }}</p>
    <div class="nt-plan-meter" aria-hidden="true"><span id="ntPlanMeter" style="width: {{ $ntCharsLeftPercent }}%"></span></div>
    <p class="nt-plan-left" id="ntPlanLeft" data-template="{{ __('design.dashboard.chars_left', ['count' => '__COUNT__']) }}">{{ __('design.dashboard.chars_left', ['count' => number_format($ntCharsLeft)]) }}</p>
    @if($ntIsFreePlan)
      <a class="btn btn-primary nt-plan-btn" href="{{ route('pricing') }}">{{ __('design.dashboard.upgrade') }}</a>
    @else
      <a class="btn nt-plan-btn" href="{{ route('manage-subscription.page') }}">{{ __('design.dashboard.manage') }}</a>
    @endif
  </div>
</div>
<script>
(function () {
  // Mirror the live usage meter (updated by the dashboard script after each generation).
  // The meter is rendered after this sidebar, so wait for the document first.
  function start() {
  var source = document.getElementById('usageCharsMeter');
  var sourceText = document.getElementById('usageCharsText');
  var meter = document.getElementById('ntPlanMeter');
  var left = document.getElementById('ntPlanLeft');
  if (!source || !meter || !window.MutationObserver) return;
  function sync() {
    var width = parseFloat(source.style.width);
    if (!isNaN(width)) meter.style.width = Math.max(0, Math.min(100, width)) + '%';
    var match = sourceText ? (sourceText.textContent || '').match(/[0-9٠-٩][0-9٠-٩,٬]*/) : null;
    if (left && match) {
      left.textContent = left.getAttribute('data-template').replace('__COUNT__', match[0]);
    }
  }
  new MutationObserver(sync).observe(source, { attributes: true, attributeFilter: ['style'] });
  if (sourceText) new MutationObserver(sync).observe(sourceText, { childList: true, characterData: true, subtree: true });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
</script>
