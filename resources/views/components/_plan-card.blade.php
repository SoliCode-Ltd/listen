@php
  $isPack = !empty($plan['is_pack']);
  $isCurrent = $activePlanSlug === $plan['slug'];
  $isLower = auth()->check() && $index < $currentPlanIndex && $plan['slug'] !== 'free' && !$isPack;
  $monthlyCents = max(0, (int) ($plan['price_monthly_cents'] ?? 0));
  $yearlyCents = max(0, (int) ($plan['price_yearly_cents'] ?? 0));
  if ($yearlyCents <= 0 && $monthlyCents > 0) {
    $yearlyCents = (int) round($monthlyCents * 12 * \App\Models\SiteSetting::yearlyDiscountMultiplier());
  }
  $displayMonthlyFormatted = (string) ($plan['display_price_monthly_formatted'] ?? '$0.00');
  $displayYearlyMonthlyFormatted = (string) ($plan['display_price_yearly_monthly_formatted'] ?? $displayMonthlyFormatted);
  $displayYearlyTotalFormatted = (string) ($plan['display_price_yearly_total_formatted'] ?? $displayYearlyMonthlyFormatted);
  $displaySavingsFormatted = (string) ($plan['display_savings_formatted'] ?? '');
  $isFree = $monthlyCents <= 0;
  $savingsCents = $monthlyCents > 0 ? max(0, ($monthlyCents * 12) - $yearlyCents) : 0;
  $hasDiscount = !empty($plan['has_discount']);
  $discountPercent = (int) ($plan['discount_percentage'] ?? 0);
  $discountEndsAt = $plan['discount_ends_at'] ?? null;
  $originalFormatted = $plan['original_price_monthly_formatted'] ?? null;
@endphp
<div class="plan-card {{ $plan['is_popular'] && !$isCurrent ? 'popular' : '' }} {{ $hasDiscount ? 'has-discount' : '' }} reveal"
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

  @if($plan['is_popular'] && !$isCurrent)
    <span class="popular-badge">{{ __('ui.pricing.most_popular') }}</span>
  @endif

  {{-- Discount ribbon --}}
  @if($hasDiscount && !$isFree)
    <div class="discount-ribbon">
      <span class="discount-ribbon-inner discount-ribbon-text">{{ __('ui.pricing.discount_badge', ['percent' => $discountPercent]) }}</span>
    </div>
  @endif

  <div class="plan-header">
    <h2 class="plan-name">{{ $planDisplayName($plan) }}</h2>
    <p class="plan-desc">{{ $planDescription($plan) }}</p>
  </div>

  <div class="plan-pricing">
    @if($hasDiscount && !$isFree && $originalFormatted)
      <div class="price-original-row">
        <span class="price-original" dir="ltr">{{ $originalFormatted }}</span>
      </div>
    @endif
    <div class="price-row">
      <span class="price-main" dir="ltr">
        <span class="price-amount price-value">{{ $displayMonthlyFormatted }}</span>
      </span>
      <span class="price-period">{{ $isPack ? __('ui.pack.one_time') : __('ui.pricing.per_month_short') }}</span>
    </div>
    <div class="price-note price-note-text"></div>
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

  {{-- Countdown timer for discounted plans --}}
  @if($hasDiscount && !$isFree && $discountEndsAt)
    <div class="discount-countdown" data-ends="{{ $discountEndsAt }}">
      <div class="countdown-label">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
        <span>{{ __('ui.pricing.discount_offer_ends') }}</span>
      </div>
      <div class="countdown-digits">
        <div class="countdown-unit">
          <span class="countdown-num" data-unit="d">00</span>
          <span class="countdown-lbl">{{ __('ui.pricing.discount_days') }}</span>
        </div>
        <span class="countdown-sep">:</span>
        <div class="countdown-unit">
          <span class="countdown-num" data-unit="h">00</span>
          <span class="countdown-lbl">{{ __('ui.pricing.discount_hours') }}</span>
        </div>
        <span class="countdown-sep">:</span>
        <div class="countdown-unit">
          <span class="countdown-num" data-unit="m">00</span>
          <span class="countdown-lbl">{{ __('ui.pricing.discount_minutes') }}</span>
        </div>
        <span class="countdown-sep">:</span>
        <div class="countdown-unit">
          <span class="countdown-num" data-unit="s">00</span>
          <span class="countdown-lbl">{{ __('ui.pricing.discount_seconds') }}</span>
        </div>
      </div>
    </div>
  @endif

  <div class="plan-quota">
    @if($isPack)
      {{ __('ui.pack.headline', ['count' => number_format($plan['monthly_chars']), 'days' => (int) $plan['pack_days']]) }}
    @elseif(!empty($plan['free_try_chars']))
      {{ __('ui.pack.free_try_headline', ['count' => number_format((int) $plan['free_try_chars'])]) }}
    @else
      {{ number_format($plan['monthly_chars']) }} {{ __('ui.pricing.characters_per_month') }}
    @endif
  </div>

  <ul class="plan-features">
    @foreach($plan['features'] as $feature)
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
          default => '<circle cx="12" cy="12" r="12" style="fill:var(--brand-accent);stroke:none;" opacity="0.15"/><circle cx="12" cy="12" r="9" style="fill:var(--brand-accent);stroke:none;"/><path d="M8.5 12L10.5 14.5L16 9" style="stroke:white;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;fill:none;"/>',
        };
      @endphp
    <li>
      <span class="feature-label">
        <svg viewBox="0 0 24 24">{!! $iconSvg !!}</svg>
        {{ $feature['label'] ?? '' }}
      </span>
      <span class="feature-value">{{ $feature['value'] ?? __('ui.pricing.included_mark') }}</span>
    </li>
    @endforeach
  </ul>

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
  @endphp
  <div class="plan-action">
    @if($isPack)
      @if(!auth()->check())
        <a class="plan-btn btn-primary" href="{{ route('pack.start') }}">{{ $plan['button_text'] ?: __('ui.pack.buy') }}</a>
      @elseif(!empty($plan['can_buy_pack']))
        <a class="plan-btn btn-primary" href="{{ route('billing.pack') }}">{{ !empty($plan['has_pack']) ? __('ui.pack.buy_again') : ($plan['button_text'] ?: __('ui.pack.buy')) }}</a>
      @else
        <span class="plan-btn btn-disabled">{{ __('ui.pack.for_free_members') }}</span>
      @endif
    @elseif(auth()->check() && $isCurrent)
      <span class="plan-btn btn-disabled">{{ __('ui.pricing.current_plan') }}</span>
    @elseif(auth()->check() && ($isLower || $plan['slug'] === 'free'))
      <span class="plan-btn btn-disabled">{{ __('ui.pricing.downgrade') }}</span>
    @elseif($plan['slug'] === 'free')
      <a class="plan-btn btn-primary" href="{{ route('register') }}">{{ __('ui.pricing.start_free') }}</a>
    @else
      <a
        class="plan-btn btn-primary"
        href="{{ $monthlyUrl }}"
        data-upgrade-link="1"
        data-monthly-href="{{ $monthlyUrl }}"
        data-yearly-href="{{ $yearlyUrl }}"
        data-monthly-label="{{ $monthlyLabel }}"
        data-yearly-label="{{ $yearlyLabel }}"
      >{{ $monthlyLabel }}</a>
    @endif
  </div>
</div>
