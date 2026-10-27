{{-- A plan's button: the same link and words on its card and in the comparison table. --}}
@php
  $state = $planState($plan, $index);
  $trialLabel = __('ui.pricing.start_trial_cta', ['days' => (int) ($trialPromo['days'] ?? 7)]);
  $fallbackLabel = auth()->check() ? __('ui.pricing.upgrade_now') : __('ui.pricing.get_started');
  // A visitor signs up first; the plan and cycle go along, so they land on its checkout after.
  $fallbackUrl = static fn (string $cycle): string => auth()->check()
    ? route('billing.page', ['plan' => $plan['slug'], 'billing_cycle' => $cycle])
    : route('register', ['plan' => $plan['slug'], 'billing_cycle' => $cycle]);
  $monthlyUrl = $state['trial_monthly'] ? route('trial.start', ['plan' => $plan['slug']]) : $fallbackUrl('monthly');
  $yearlyUrl = $state['trial_yearly'] ? route('trial.start', ['plan' => $plan['slug'], 'billing_cycle' => 'yearly']) : $fallbackUrl('yearly');
  $monthlyLabel = $state['trial_monthly'] ? $trialLabel : $fallbackLabel;
  $yearlyLabel = $state['trial_yearly'] ? $trialLabel : $fallbackLabel;
  // A plan on offer: its card's button says what is off ("Get 10% off"). An offer is for monthly
  // billing only, so on yearly, and without an offer, the button keeps its usual words.
  $offPercent = !empty($plan['has_discount']) ? (int) ($plan['discount_percentage'] ?? 0) : 0;
  if (!($small ?? false) && $offPercent > 0 && !$state['trial_monthly']) {
    $monthlyLabel = __('ui.pricing.get_offer', ['percent' => $offPercent]);
  }
  // On yearly, the card's button says what yearly billing saves, like an offer ("Get 20% off yearly");
  // the narrow bar on phones says it shorter ("Get 20% off").
  $yearlySavePercent = (int) ($yearlyDiscountPercent ?? 0);
  $yearlyBarLabel = null;
  if (!($small ?? false) && $yearlySavePercent > 0 && !$state['trial_yearly']) {
    $yearlyLabel = __('ui.pricing.get_yearly_offer', ['percent' => $yearlySavePercent]);
    $yearlyBarLabel = __('ui.pricing.get_offer', ['percent' => $yearlySavePercent]);
  }
  // The recommended plan gets the filled button; the others an outline.
  $buttonClass = 'plan-btn '.($small ?? false ? 'plan-btn-sm ' : '').($state['is_popular'] ? 'btn-primary' : 'btn-outline');
  $disabledClass = 'plan-btn '.($small ?? false ? 'plan-btn-sm ' : '').'btn-disabled';
@endphp
@if($state['is_pack'])
  @if(!auth()->check())
    <a class="{{ $buttonClass }}" href="{{ route('pack.start') }}">{{ $plan['button_text'] ?: __('ui.pack.buy') }}</a>
  @elseif(!empty($plan['can_buy_pack']))
    <a class="{{ $buttonClass }}" href="{{ route('billing.pack') }}">{{ !empty($plan['has_pack']) ? __('ui.pack.buy_again') : ($plan['button_text'] ?: __('ui.pack.buy')) }}</a>
  @else
    <span class="{{ $disabledClass }}">{{ __('ui.pack.for_free_members') }}</span>
  @endif
@elseif(auth()->check() && $state['is_current'])
  <span class="{{ $disabledClass }}">{{ __('ui.pricing.current_plan') }}</span>
@elseif(auth()->check() && ($state['is_lower'] || $plan['slug'] === 'free'))
  <span class="{{ $disabledClass }}">{{ __('ui.pricing.downgrade') }}</span>
@elseif($plan['slug'] === 'free')
  <a class="{{ $buttonClass }}" href="{{ route('register') }}">{{ __('ui.pricing.start_free') }}</a>
@else
  <a
    class="{{ $buttonClass }}"
    href="{{ $monthlyUrl }}"
    data-upgrade-link="1"
    data-monthly-href="{{ $monthlyUrl }}"
    data-yearly-href="{{ $yearlyUrl }}"
    data-monthly-label="{{ $monthlyLabel }}"
    data-yearly-label="{{ $yearlyLabel }}"
    @if($yearlyBarLabel) data-bar-yearly-label="{{ $yearlyBarLabel }}" @endif
  >{{ $monthlyLabel }}</a>
@endif
