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
      @if($studio['allowed'] && $studio['monthly'] > 0)
        <div class="vd-section-meta">
          <span class="vd-pill" data-vd-month-left>{{ trans_choice('voice_design.month_left', $studio['monthly'], ['left' => max(0, $studio['monthly'] - $studio['kept_month']), 'max' => $studio['monthly']]) }}</span>
        </div>
      @endif
    </header>
    <x-voice-design.studio :studio="$studio" />
  </article>
</section>
