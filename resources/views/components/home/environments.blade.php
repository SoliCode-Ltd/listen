{{-- Sound environments: one voice line per place, heard placed in the environment (the
     studio's own engine) or clean, switched in place at the same moment. --}}
@php
  $vsEnvs = $environmentSamples ?? [];
  // "+20": the environments, rounded down to the ten, so the label stays true as more are added.
  $vsEnvCount = (int) (floor(count(array_filter(\App\Support\AudioEffects::ALL, fn ($row) => $row[1] !== 'clean')) / 10) * 10);
  // The button opens billing on the cheapest plan that includes them, or the pricing page.
  $vsEnvPlan = \App\Support\PlanFeatures::cheapestWith(\App\Support\PlanFeatures::EFFECTS);
  $vsEnvUrl = $vsEnvPlan ? route('billing.page', ['plan' => $vsEnvPlan->slug]) : route('pricing');
@endphp
@if(!empty($vsEnvs))
<section class="section vs-envs" id="sound-environments">
  <div class="vs-head">
    <div>
      <h2 class="vs-title">{{ __('ui.landing.env_title') }}</h2>
      <p class="vs-lead">{{ __('ui.landing.env_body') }}</p>
    </div>
  </div>
  <div class="vs-env-grid">
    @foreach($vsEnvs as $env)
      @include('components.home.env-card', ['env' => $env])
    @endforeach
  </div>
  <div class="vs-env-cta-wrap">
    <a class="vs-env-cta" href="{{ $vsEnvUrl }}">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 10v4M7.5 7v10M12 4v16M16.5 7v10M21 10v4"/></svg>
      <span><bdi dir="ltr">+{{ $vsEnvCount }}</bdi> {{ __('ui.landing.env_note') }}</span>
    </a>
  </div>
</section>
@endif
