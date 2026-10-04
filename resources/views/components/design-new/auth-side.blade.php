{{-- Side panel for sign in / register in the new site design (SignIn and Register boards). --}}
@php
  $ntRows = \App\Support\NewDesignSamples::dialects(4);
  $ntFreeChars = ($ntMode ?? 'login') === 'register' ? \App\Support\NewDesignSamples::freePlanChars() : null;
@endphp
<aside class="nt-auth-side" aria-label="{{ ($ntMode ?? 'login') === 'register' ? __('design.auth.free_title') : __('design.auth.side_title') }}">
  @if(($ntMode ?? 'login') === 'register')
    <span class="nt-pill nt-pill-ok">{{ __('design.auth.free_badge') }}</span>
    <h2 class="nt-h2">{{ __('design.auth.free_title') }}</h2>
    <ul class="nt-perks">
      @if($ntFreeChars)
        <li>{{ __('design.auth.perk_chars', ['chars' => number_format($ntFreeChars)]) }}</li>
      @endif
      <li>{{ __('design.auth.perk_voices') }}</li>
      <li>{{ __('design.auth.perk_extension') }}</li>
      <li>{{ __('design.auth.perk_card') }}</li>
    </ul>
  @else
    <span class="nt-eyebrow">{{ __('design.auth.side_eyebrow') }}</span>
    <h2 class="nt-h2">{{ __('design.auth.side_title') }}</h2>
  @endif
  @if(!empty($ntRows))
    <div class="nt-card nt-strip-list">
      @foreach($ntRows as $ntRow)
        <div class="nt-strip-row" data-nt-track data-src="{{ $ntRow['audio_url'] }}">
          <span class="nt-strip-tag">{{ \App\Support\NewDesignSamples::dialectLabel($ntRow['dialect']) }}</span>
          <span class="nt-strip-line" dir="rtl" lang="ar">{{ \Illuminate\Support\Str::limit($ntRow['text'], 48) }}</span>
          <button type="button" class="nt-play nt-play-soft" data-nt-play aria-label="{{ __('design.home.play') }}: {{ \App\Support\NewDesignSamples::dialectLabel($ntRow['dialect']) }}" data-label-play="{{ __('design.home.play') }}" data-label-pause="{{ __('design.home.pause') }}">
            <svg class="nt-icon-play" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
            <svg class="nt-icon-pause" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
          </button>
        </div>
      @endforeach
    </div>
  @endif
  @if(($ntMode ?? 'login') !== 'register')
    <p class="nt-small">{{ __('design.auth.side_note') }}</p>
  @endif
</aside>
@include('components.design-new.player-script')
