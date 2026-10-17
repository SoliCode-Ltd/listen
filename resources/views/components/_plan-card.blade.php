@php
  $isPack = !empty($plan['is_pack']);
  $isCurrent = $activePlanSlug === $plan['slug'];
  $isLower = auth()->check() && $index < $currentPlanIndex && $plan['slug'] !== 'free' && !$isPack;
  $monthlyCents = max(0, (int) ($plan['price_monthly_cents'] ?? 0));
  $yearlyCents = max(0, (int) ($plan['price_yearly_cents'] ?? 0));
  if ($yearlyCents <= 0 && $monthlyCents > 0) {
    $yearlyCents = (int) round($monthlyCents * 12 * \App\Models\SiteSetting::yearlyDiscountMultiplier());
  }
  // "$9.00" reads as "$9" on the card; "$86.40" keeps its cents.
  $wholePrice = static fn (string $price): string => (string) preg_replace('/[.,]00(?=\D*$)/u', '', $price);
  $displayMonthlyFormatted = $wholePrice((string) ($plan['display_price_monthly_formatted'] ?? '$0.00'));
  $displayYearlyMonthlyFormatted = $wholePrice((string) ($plan['display_price_yearly_monthly_formatted'] ?? $displayMonthlyFormatted));
  $displayYearlyTotalFormatted = (string) ($plan['display_price_yearly_total_formatted'] ?? $displayYearlyMonthlyFormatted);
  $displaySavingsFormatted = (string) ($plan['display_savings_formatted'] ?? '');
  $isFree = $monthlyCents <= 0;
  $savingsCents = $monthlyCents > 0 ? max(0, ($monthlyCents * 12) - $yearlyCents) : 0;
  $hasDiscount = !empty($plan['has_discount']);
  $discountPercent = (int) ($plan['discount_percentage'] ?? 0);
  $discountEndsAt = $plan['discount_ends_at'] ?? null;
  $originalFormatted = isset($plan['original_price_monthly_formatted']) ? $wholePrice((string) $plan['original_price_monthly_formatted']) : null;
  $isPopular = $plan['is_popular'] && !$isCurrent;
  // The card shows what sets this plan apart; lines every plan shares are listed once under the cards.
  $cardLines = array_values(array_filter($plan['features'], fn ($line) => !in_array($line['label'] ?? '', $pricingHiddenLabels ?? [], true)));
  $includedMark = __('ui.pricing.included_mark');
  $minutes = \App\Support\Credits::minutesFor((int) ($plan['monthly_chars'] ?? 0));
@endphp
<div class="plan-card {{ $isPopular ? 'popular' : '' }} {{ $hasDiscount ? 'has-discount' : '' }} {{ $isCurrent ? 'is-current' : '' }} reveal"
     data-plan-slug="{{ $plan['slug'] }}"
     data-is-free="{{ $isFree ? '1' : '0' }}"
     data-is-pack="{{ $isPack ? '1' : '0' }}"
     data-monthly-cents="{{ $monthlyCents }}"
     data-yearly-cents="{{ $yearlyCents }}"
     data-savings-cents="{{ $savingsCents }}"
     data-display-monthly="{{ e($displayMonthlyFormatted) }}"
     data-display-yearly-monthly="{{ e($displayYearlyMonthlyFormatted) }}"
     data-display-yearly-total="{{ e($displayYearlyTotalFormatted) }}"
     data-display-savings="{{ e($displaySavingsFormatted) }}"
     data-display-manual="{{ !empty($plan['display_is_manual']) ? '1' : '0' }}"
     @if($hasDiscount)
     data-discount-percent="{{ $discountPercent }}"
     data-discount-ends="{{ $discountEndsAt }}"
     data-original-monthly="{{ e($originalFormatted) }}"
     @endif>

  {{-- 1. Name and who it is for --}}
  <div class="plan-top">
    @if($isPopular || ($hasDiscount && !$isFree && $discountEndsAt))
      <div class="plan-badges">
        @if($isPopular)
          <span class="popular-badge"><svg class="popular-crown" viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 8.2l4.6 3.6L12 5l3.9 6.8 4.6-3.6-1.7 9.3H5.2z"/><path d="M5.4 19.6h13.2"/></svg>{{ __('ui.pricing.most_popular') }}</span>
        @endif
        {{-- When the offer ends: a small chip that counts down. --}}
        @if($hasDiscount && !$isFree && $discountEndsAt)
          <span class="discount-countdown" data-ends="{{ $discountEndsAt }}" title="{{ __('ui.pricing.discount_offer_ends') }}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            <span class="countdown-label">{{ __('ui.pricing.ends_in') }}</span>
            <span class="countdown-digits">
              <span class="countdown-unit"><span class="countdown-num" data-unit="d">00</span><span class="countdown-lbl">{{ __('ui.pricing.unit_days_short') }}</span></span>
              <span class="countdown-unit"><span class="countdown-num" data-unit="h">00</span><span class="countdown-lbl">{{ __('ui.pricing.unit_hours_short') }}</span></span>
              <span class="countdown-unit"><span class="countdown-num" data-unit="m">00</span><span class="countdown-lbl">{{ __('ui.pricing.unit_minutes_short') }}</span></span>
            </span>
          </span>
        @endif
      </div>
    @endif
    <h2 class="plan-name">{{ $planDisplayName($plan) }}</h2>
    <p class="plan-desc">{{ $planDescription($plan) }}</p>
  </div>

  {{-- 2. Price, and the offer when there is one --}}
  <div class="plan-pricing">
    @if($hasDiscount && !$isFree && $originalFormatted)
      <div class="price-original-row">
        <span class="price-original" dir="ltr">{{ $originalFormatted }}</span>
        <span class="price-off discount-ribbon-text">{{ __('ui.pricing.discount_badge', ['percent' => $discountPercent]) }}</span>
      </div>
    @endif
    <div class="price-row">
      <span class="price-main" dir="ltr">
        <span class="price-amount price-value">{{ $displayMonthlyFormatted }}</span>
      </span>
      <span class="price-period">{{ $isPack ? __('ui.pack.one_time') : __('ui.pricing.per_month_short') }}</span>
    </div>
    <div class="price-note price-note-text"></div>
    @if($hasDiscount && !$isFree && !$isPack && !empty($plan['discount_terms']))
      @php
        $terms = $plan['discount_terms'];
      @endphp
      {{-- How long the discount lasts and the regular price after it, for the cycle shown. --}}
      <div class="discount-terms"
           data-offer-monthly="{{ $terms['offer_monthly'] }}" data-then-monthly="{{ $terms['then_monthly'] ?? '' }}"
           data-offer-yearly="{{ $terms['offer_yearly'] }}" data-then-yearly="{{ $terms['then_yearly'] ?? '' }}">
        <span class="discount-terms-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg></span>
        <span class="discount-terms-body">
          <strong class="discount-terms-offer">{{ $terms['offer_monthly'] }}</strong>
          <span class="discount-terms-then" @if(empty($terms['then_monthly'])) hidden @endif>{{ $terms['then_monthly'] ?? '' }}</span>
        </span>
      </div>
    @endif
    @php
      $trialCycles = !empty($trialPromo['show']) && !$isFree && !$isCurrent && !$isLower
        ? ($trialPromo['plan_cycles'][$plan['slug']] ?? [])
        : [];
      $trialMonthly = in_array('monthly', $trialCycles, true);
      $trialYearly = in_array('yearly', $trialCycles, true);
      $trialOnlyAttr = $trialMonthly && !$trialYearly ? 'data-trial-monthly-only' : ($trialYearly && !$trialMonthly ? 'data-trial-yearly-only' : '');
    @endphp
    @if($trialCycles !== [])
      <span class="plan-trial-chip" {{ $trialOnlyAttr }}>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
        {{ __('ui.pricing.trial_chip', ['days' => (int) ($trialPromo['days'] ?? 7)]) }}
      </span>
    @endif
  </div>

  {{-- 3. The button, at the same height on every card --}}
  @php
    $trialLabel = __('ui.pricing.start_trial_cta', ['days' => (int) ($trialPromo['days'] ?? 7)]);
    $fallbackLabel = auth()->check() ? __('ui.pricing.upgrade_now') : __('ui.pricing.get_started');
    $fallbackUrl = static fn (string $cycle): string => auth()->check()
      ? route('billing.page', ['plan' => $plan['slug'], 'billing_cycle' => $cycle])
      : route('register');
    $monthlyUrl = $trialMonthly ? route('trial.start', ['plan' => $plan['slug']]) : $fallbackUrl('monthly');
    $yearlyUrl = $trialYearly ? route('trial.start', ['plan' => $plan['slug'], 'billing_cycle' => 'yearly']) : $fallbackUrl('yearly');
    $monthlyLabel = $trialMonthly ? $trialLabel : $fallbackLabel;
    $yearlyLabel = $trialYearly ? $trialLabel : $fallbackLabel;
    // The recommended plan gets the filled button; the others an outline.
    $buttonStyle = $isPopular ? 'btn-primary' : 'btn-outline';
  @endphp
  <div class="plan-action">
    @if($isPack)
      @if(!auth()->check())
        <a class="plan-btn {{ $buttonStyle }}" href="{{ route('pack.start') }}">{{ $plan['button_text'] ?: __('ui.pack.buy') }}</a>
      @elseif(!empty($plan['can_buy_pack']))
        <a class="plan-btn {{ $buttonStyle }}" href="{{ route('billing.pack') }}">{{ !empty($plan['has_pack']) ? __('ui.pack.buy_again') : ($plan['button_text'] ?: __('ui.pack.buy')) }}</a>
      @else
        <span class="plan-btn btn-disabled">{{ __('ui.pack.for_free_members') }}</span>
      @endif
    @elseif(auth()->check() && $isCurrent)
      <span class="plan-btn btn-disabled">{{ __('ui.pricing.current_plan') }}</span>
    @elseif(auth()->check() && ($isLower || $plan['slug'] === 'free'))
      <span class="plan-btn btn-disabled">{{ __('ui.pricing.downgrade') }}</span>
    @elseif($plan['slug'] === 'free')
      <a class="plan-btn {{ $buttonStyle }}" href="{{ route('register') }}">{{ __('ui.pricing.start_free') }}</a>
    @else
      <a
        class="plan-btn {{ $buttonStyle }}"
        href="{{ $monthlyUrl }}"
        data-upgrade-link="1"
        data-monthly-href="{{ $monthlyUrl }}"
        data-yearly-href="{{ $yearlyUrl }}"
        data-monthly-label="{{ $monthlyLabel }}"
        data-yearly-label="{{ $yearlyLabel }}"
      >{{ $monthlyLabel }}</a>
    @endif
  </div>

  {{-- 4. How much voice it gives: minutes first, the credits behind them second --}}
  @php
    $creditsQuota = ! $isPack && empty($plan['free_try_chars']);
  @endphp
  <div class="plan-quota {{ $creditsQuota ? 'has-credits' : '' }}">
    @if($isPack)
      {{ __('ui.pack.headline', ['count' => number_format($plan['monthly_chars']), 'days' => (int) $plan['pack_days']]) }}
    @elseif(!empty($plan['free_try_chars']))
      {{ __('ui.pack.free_try_headline', ['count' => number_format((int) $plan['free_try_chars'])]) }}
    @else
      @if($minutes > 0)
        <span class="plan-minutes">
          <strong class="plan-minutes-number">{{ number_format($minutes) }}</strong>
          <span class="plan-minutes-unit">{{ __('ui.pricing.minutes_per_month') }}</span>
        </span>
      @endif
      <span class="plan-quota-credits" title="{{ __('ui.pricing.credits_hint') }}">
        <x-credit-icon class="plan-quota-coin" />
        <span class="plan-quota-amount">
          <strong class="plan-quota-number">{{ number_format($plan['monthly_chars']) }}</strong>
          <span class="plan-quota-unit">{{ __('ui.pricing.characters_per_month') }}</span>
        </span>
      </span>
    @endif
  </div>

  {{-- 5. What sets it apart --}}
  <ul class="plan-features">
    @foreach($cardLines as $feature)
      @php
        $iconSvg = match($feature['icon'] ?? 'check') {
          'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
          'type' => '<polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/>',
          'lock' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
          'bolt' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
          'mic' => '<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>',
          'globe' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
          'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/>',
          'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
          'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
          'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
          'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
          'heart' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
          'sliders' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
          default => '<path d="M20 6 9 17l-5-5"/>',
        };
        $value = (string) ($feature['value'] ?? $includedMark);
        $isTick = $value === '' || $value === $includedMark;
      @endphp
    <li class="{{ $isTick ? 'is-tick' : '' }}">
      <span class="feature-label">
        <svg viewBox="0 0 24 24" aria-hidden="true">{!! $isTick ? '<path d="M20 6 9 17l-5-5"/>' : $iconSvg !!}</svg>
        {{ $feature['label'] ?? '' }}
      </span>
      @unless($isTick)
        <span class="feature-value">{{ $value }}</span>
      @endunless
    </li>
    @endforeach
  </ul>
</div>
