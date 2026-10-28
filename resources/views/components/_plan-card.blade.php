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
  // Lines the admin wrote for this card all show on it (except the minutes, shown above them):
  // the same Arabic wording on every plan must not empty the Arabic cards.
  $minutesKey = \App\Support\PlanPricingLines::labelKey(__('ui.pricing.lines.label_voice_minutes'));
  $cardLines = array_values(array_filter($plan['features'], fn ($line) => !empty($plan['own_lines'])
    ? \App\Support\PlanPricingLines::labelKey((string) ($line['label'] ?? '')) !== $minutesKey
    : !in_array($line['label'] ?? '', $pricingHiddenLabels ?? [], true)));
  $includedMark = __('ui.pricing.included_mark');
  $minutes = \App\Support\Credits::minutesFor((int) ($plan['monthly_chars'] ?? 0));
  // A price in another currency is a guide: the card also shows the US-dollar price
  // a card is really charged, for each cycle.
@endphp
<div class="plan-card {{ $isPopular ? 'popular' : '' }} {{ $hasDiscount ? 'has-discount' : '' }} {{ $isCurrent ? 'is-current' : '' }}" data-orb-host
     id="plan-{{ $plan['slug'] }}" tabindex="-1"
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
    {{-- Each plan's orb; the recommended plan's moves gently and its badge sits on the card's top edge. --}}
    <div class="plan-badges">
      <x-orb :name="\App\Support\Orbs::forPlan((string) $plan['slug'])" @class(['plan-orb', 'is-live' => $isPopular]) />
      @if($isPopular)
        <span class="popular-badge">{!! $crownSvg ?? '' !!}{{ __('ui.pricing.most_popular') }}</span>
      @endif
    </div>
    <h2 class="plan-name">{{ $planDisplayName($plan) }}</h2>
    <p class="plan-desc">{{ $planDescription($plan) }}</p>
  </div>

  {{-- 2. Price, and the offer when there is one: its label above the price with the day it ends,
       and the full price struck through beside the price. An offer is for monthly billing
       only, so on yearly all three go and the yearly price shows with the usual monthly price
       struck through beside it. --}}
  @php
    $terms = $hasDiscount && !$isFree && !$isPack ? ($plan['discount_terms'] ?? null) : null;
    // The offer's last day, e.g. "30 Oct" («30 أكتوبر»). One ending at midnight ends the day before.
    $offerUntil = $terms && $discountEndsAt
      ? \Illuminate\Support\Carbon::parse($discountEndsAt)->subSecond()->locale(app()->getLocale())->translatedFormat('j M')
      : null;
  @endphp
  {{-- Kept when there is no offer too, so every card's price starts at the same height. --}}
  @if($terms)
    <div class="plan-offer">
      <span class="offer-label">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.4"/></svg>
        <span class="offer-label-text">{{ $terms['offer_monthly'] }}</span>
        @if($offerUntil)
          <span class="offer-until">{{ __('ui.pricing.offer_until', ['date' => $offerUntil]) }}</span>
        @endif
      </span>
    </div>
  @else
    <div class="plan-offer is-empty" aria-hidden="true"></div>
  @endif
  @php
    // On yearly, the month's usual price struck through beside the yearly price per month, so the
    // yearly saving reads like an offer.
    $fullMonthlyFormatted = $originalFormatted ?? $displayMonthlyFormatted;
    $yearlySaves = !$isFree && !$isPack && $yearlyCents > 0 && $yearlyCents < (int) ($plan['original_price_monthly_cents'] ?? $monthlyCents) * 12;
  @endphp
  <div class="plan-pricing">
    <div class="price-line">
      @if($hasDiscount && !$isFree && $originalFormatted)
        <div class="price-original-row is-offer">
          <s class="price-original" dir="ltr">{{ $originalFormatted }}</s>
        </div>
      @endif
      @if($yearlySaves)
        <div class="price-original-row is-yearly">
          <s class="price-original" dir="ltr">{{ $fullMonthlyFormatted }}</s>
        </div>
      @endif
      <div class="price-row">
        <span class="price-main" dir="ltr">
          <span class="price-amount price-value">{{ $displayMonthlyFormatted }}</span>
        </span>
        <span class="price-period">{{ $isPack ? __('ui.pack.one_time') : __('ui.pricing.per_month_short') }}</span>
      </div>
    </div>
    <div class="price-note price-note-text"></div>
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

  {{-- 5. What sets it apart: every line on wider screens; on phones the first four and a
       button for the rest, when at least two more would wait behind it. --}}
  @php $foldLines = count($cardLines) > 5; @endphp
  <div class="plan-lines">
  <ul @class(['plan-features', 'is-long' => $foldLines]) id="plan-lines-{{ $plan['slug'] }}">
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
  @if($foldLines)
    <button type="button" class="plan-more" aria-expanded="false" aria-controls="plan-lines-{{ $plan['slug'] }}">
      {{ __('ui.pricing.show_all_lines') }}
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>
  @endif
  </div>
</div>
