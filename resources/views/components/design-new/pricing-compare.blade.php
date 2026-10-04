{{-- Comparison table for the new site design (Pricing board), built from the real plans. --}}
@php
  $ntPlans = array_values($pricingPlans ?? []);
  $ntLabels = [];
  foreach ($ntPlans as $ntPlan) {
    foreach (($ntPlan['features'] ?? []) as $ntFeature) {
      $ntLabel = (string) ($ntFeature['label'] ?? '');
      if ($ntLabel !== '' && !in_array($ntLabel, $ntLabels, true)) {
        $ntLabels[] = $ntLabel;
      }
    }
  }
  $ntValue = function (array $plan, string $label): string {
    foreach (($plan['features'] ?? []) as $feature) {
      if ((string) ($feature['label'] ?? '') === $label) {
        return (string) ($feature['value'] ?? '');
      }
    }
    return '—';
  };
@endphp
@if(count($ntPlans) > 1 && count($ntLabels) > 0)
<section class="nt-compare" aria-labelledby="ntCompareTitle">
  <h2 class="nt-h3" id="ntCompareTitle">{{ __('design.pricing.compare') }}</h2>
  <div class="nt-card nt-compare-scroll">
    <table class="nt-table">
      <thead>
        <tr>
          <th scope="col"><span class="nt-sr">{{ __('design.pricing.feature') }}</span></th>
          @foreach($ntPlans as $ntPlan)
            <th scope="col" class="{{ !empty($ntPlan['is_popular']) ? 'is-featured' : '' }}">{{ $ntPlan['name'] }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        <tr>
          <th scope="row">{{ __('ui.pricing.characters_per_month') }}</th>
          @foreach($ntPlans as $ntPlan)
            <td class="{{ !empty($ntPlan['is_popular']) ? 'is-featured' : '' }}">{{ number_format((int) ($ntPlan['monthly_chars'] ?? 0)) }}</td>
          @endforeach
        </tr>
        @foreach($ntLabels as $ntLabel)
          <tr>
            <th scope="row">{{ $ntLabel }}</th>
            @foreach($ntPlans as $ntPlan)
              <td class="{{ !empty($ntPlan['is_popular']) ? 'is-featured' : '' }}">{{ $ntValue($ntPlan, $ntLabel) }}</td>
            @endforeach
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</section>
@endif
