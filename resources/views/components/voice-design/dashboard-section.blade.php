{{--
  The dashboard's Voice Design section: the studio (components/voice-design/studio), with the
  member's library under it, or, on a plan without Voice Design, what it does and the way to a
  plan with it. $studio: App\Support\VoiceDesign::studio().
--}}
@props(['studio'])
<section id="voiceDesignSection" class="dashboard-panel" data-dashboard-section>
  <article class="page-card reveal vd-section">
    <header class="vd-section-head">
      <div>
        <h1><span class="grad-text">{{ __('voice_design.title') }}</span></h1>
        <p>{{ __('voice_design.subtitle') }}</p>
      </div>
      <div class="vd-section-meta">
        @if($studio['preview_only'])
          <span class="vd-pill is-preview">{{ __('voice_design.preview_badge') }}</span>
        @endif
        @if($studio['allowed'] && $studio['monthly'] > 0)
          <span class="vd-pill" data-vd-month-count>{{ trans_choice('voice_design.month_count', $studio['monthly'], ['count' => $studio['kept_month'], 'max' => $studio['monthly']]) }}</span>
        @endif
      </div>
    </header>
    <x-voice-design.studio :studio="$studio" />
  </article>
</section>
