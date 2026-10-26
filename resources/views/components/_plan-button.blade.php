{{-- A plan's button: the same link and words on its card and in the comparison table. --}}
@php
  $state = $planState($plan, $index);
  $trialLabel = __('ui.pricing.start_trial_cta', ['days' => (int) ($trialPromo['days'] ?? 7)]);
  $fallbackLabel = auth()->check() ? __('ui.pricing.upgrade_now') : __('ui.pricing.get_started');
  $fallbackUrl = static fn (string $cycle): string => auth()->check()
    ? route('billing.page', ['plan' => $plan['slug'], 'billing_cycle' => $cycle])
    : route('register');
  $monthlyUrl = $state['trial_monthly'] ? route('trial.start', ['plan' => $plan['slug']]) : $fallbackUrl('monthly');
  $yearlyUrl = $state['trial_yearly'] ? route('trial.start', ['plan' => $plan['slug'], 'billing_cycle' => 'yearly']) : $fallbackUrl('yearly');
  $monthlyLabel = $state['trial_monthly'] ? $trialLabel : $fallbackLabel;
  $yearlyLabel = $state['trial_yearly'] ? $trialLabel : $fallbackLabel;
  // A first-month offer on the plan's card: the button names its price ("Start your first month
  // for $19.80"); the bar on phones and the comparison table keep the short words.
  $shortLabel = null;
  if (!($small ?? false) && !$state['trial_monthly'] && !empty($plan['discount_first_month'])) {
    $shortLabel = $monthlyLabel;
    $firstMonthPrice = $wholePrice((string) ($plan['display_price_monthly_formatted'] ?? ''));
    $monthlyLabel = __('ui.pricing.start_first_month', ['price' => "\u{2066}".$firstMonthPrice."\u{2069}"]);
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
    @if($shortLabel) data-short-label="{{ $shortLabel }}" @endif
  >{{ $monthlyLabel }}</a>
@endif
