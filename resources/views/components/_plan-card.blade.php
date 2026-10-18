@php
  $state = $planState($plan, $index);
  $isPack = $state['is_pack'];
  $isCurrent = $state['is_current'];
  $monthlyCents = max(0, (int) ($plan['price_monthly_cents'] ?? 0));
  $yearlyCents = max(0, (int) ($plan['price_yearly_cents'] ?? 0));
  if ($yearlyCents <= 0 && $monthlyCents > 0) {
    $yearlyCents = (int) round($monthlyCents * 12 * \App\Models\SiteSetting::yearlyDiscountMultiplier());
  }
  $displayMonthlyFormatted = $wholePrice((string) ($plan['display_price_monthly_formatted'] ?? '$0.00'));
  $displayYearlyMonthlyFormatted = $wholePrice((string) ($plan['display_price_yearly_monthly_formatted'] ?? $displayMonthlyFormatted));
  $displayYearlyTotalFormatted = (string) ($plan['display_price_yearly_total_formatted'] ?? $displayYearlyMonthlyFormatted);
  $displaySavingsFormatted = (string) ($plan['display_savings_formatted'] ?? '');
  $isFree = $state['is_free'];
  $savingsCents = $monthlyCents > 0 ? max(0, ($monthlyCents * 12) - $yearlyCents) : 0;
  $hasDiscount = !empty($plan['has_discount']);
  $discountPercent = (int) ($plan['discount_percentage'] ?? 0);
  $discountEndsAt = $plan['discount_ends_at'] ?? null;
  $originalFormatted = isset($plan['original_price_monthly_formatted']) ? $wholePrice((string) $plan['original_price_monthly_formatted']) : null;
  $isPopular = $state['is_popular'];
  // The card shows what sets this plan apart; lines every plan shares are listed once under the cards.
  $cardLines = array_values(array_filter($plan['features'], fn ($line) => !in_array($line['label'] ?? '', $pricingHiddenLabels ?? [], true)));
  $includedMark = __('ui.pricing.included_mark');
  $minutes = \App\Support\Credits::minutesFor((int) ($plan['monthly_chars'] ?? 0));
@endphp
<div class="plan-card {{ $isPopular ? 'popular' : '' }} {{ $hasDiscount ? 'has-discount' : '' }} {{ $isCurrent ? 'is-current' : '' }} reveal" data-orb-host
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
    {{-- Each plan's orb, and the badge on the recommended one; the popular orb moves gently. --}}
    <div class="plan-badges">
      <x-orb :name="\App\Support\Orbs::forPlan((string) $plan['slug'])" @class(['plan-orb', 'is-live' => $isPopular]) />
      @if($isPopular)
        <span class="popular-badge">{{ __('ui.pricing.most_popular') }}</span>
      @endif
    </div>
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
      {{-- The offer in one box: what it is, the price after it, and when it ends, in words. --}}
      <div class="discount-terms"
           data-offer-monthly="{{ $terms['offer_monthly'] }}" data-then-monthly="{{ $terms['then_monthly'] ?? '' }}"
           data-offer-yearly="{{ $terms['offer_yearly'] }}" data-then-yearly="{{ $terms['then_yearly'] ?? '' }}">
        <strong class="discount-terms-offer">{{ $terms['offer_monthly'] }}</strong>
        <span class="discount-terms-then" @if(empty($terms['then_monthly'])) hidden @endif>{{ $terms['then_monthly'] ?? '' }}</span>
        @if($discountEndsAt)
          @php $endsText = $offerEndsText($discountEndsAt); @endphp
          @if($endsText !== '')
            <span class="offer-ends" data-ends="{{ $discountEndsAt }}">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 1.5M9.5 2.5h5"/></svg>
              <span class="offer-ends-text">{{ $endsText }}</span>
            </span>
          @endif
        @endif
      </div>
    @endif
    @php
      $trialMonthly = $state['trial_monthly'];
      $trialYearly = $state['trial_yearly'];
      $trialOnlyAttr = $trialMonthly && !$trialYearly ? 'data-trial-monthly-only' : ($trialYearly && !$trialMonthly ? 'data-trial-yearly-only' : '');
    @endphp
    @if($trialMonthly || $trialYearly)
      <span class="plan-trial-chip" {{ $trialOnlyAttr }}>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
        {{ __('ui.pricing.trial_chip', ['days' => (int) ($trialPromo['days'] ?? 7)]) }}
      </span>
    @endif
  </div>

  {{-- 3. The button, at the same height on every card --}}
  <div class="plan-action">
    @include('components._plan-button')
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
        $iconSvg = $lineIcon($feature['icon'] ?? 'check');
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
