{{-- Brand panel beside the sign in / register form. Real dialect samples and plan data only. --}}
@php
  $authMode = $authMode ?? 'login';
  $authSamples = \App\Support\NewDesignSamples::dialects(5);
  $authFirst = $authSamples[0] ?? null;
  $authDialects = __('ui.dialects');
  $authDialects = is_array($authDialects) ? $authDialects : [];
  $authEnglishKeys = ['american', 'british', 'australian', 'indian', 'south_african'];
  $authArabicCount = count(array_diff(array_keys($authDialects), $authEnglishKeys));
  $authEnglishCount = count(array_intersect(array_keys($authDialects), $authEnglishKeys));
  $authFreeChars = $authMode === 'register' ? \App\Support\NewDesignSamples::freePlanChars() : null;
  $authWave = [];
  for ($i = 0; $i < 56; $i++) {
    $authWave[] = (int) round(6 + abs(sin($i * 1.37) * cos($i * 0.41)) * 30);
  }
@endphp
<aside class="auth-showcase" aria-label="{{ __('design.auth.sample_label') }}">
  <div class="auth-showcase-inner">
    @if($authMode === 'register')
      <span class="auth-badge">{{ __('design.auth.free_badge') }}</span>
      <h2 class="auth-headline">{{ __('design.auth.register_headline') }}</h2>
      <ul class="auth-perks">
        @if($authFreeChars)
          <li>{{ __('design.auth.perk_chars', ['chars' => number_format($authFreeChars)]) }}</li>
        @endif
        <li>{{ __('design.auth.perk_voices') }}</li>
        <li>{{ __('design.auth.perk_extension') }}</li>
        <li>{{ __('design.auth.perk_card') }}</li>
      </ul>
    @else
      <h2 class="auth-headline">{{ __('design.auth.login_headline') }}</h2>
      <p class="auth-subline">{{ __('design.auth.login_body') }}</p>
    @endif

    @if($authFirst)
      <div class="auth-player" data-nt-try>
        <div class="auth-player-head">
          <span>{{ __('design.auth.sample_label') }}</span>
          <span class="auth-player-voice" data-nt-try-voice>{{ \App\Support\NewDesignSamples::dialectLabel($authFirst['dialect']) }}</span>
        </div>
        <p class="auth-player-text" dir="rtl" lang="ar" data-nt-try-text>{{ \Illuminate\Support\Str::limit($authFirst['text'], 120) }}</p>
        <div class="auth-player-bar" data-nt-track data-src="{{ $authFirst['audio_url'] }}">
          <button type="button" class="auth-play" data-nt-play aria-label="{{ __('design.home.play') }}" data-label-play="{{ __('design.home.play') }}" data-label-pause="{{ __('design.home.pause') }}">
            <svg class="auth-icon-play" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
            <svg class="auth-icon-pause" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
          </button>
          <div class="nt-wave auth-wave" aria-hidden="true">
            @foreach($authWave as $h)<span style="height: {{ $h }}px"></span>@endforeach
          </div>
          <span class="auth-time" data-nt-time>0:00</span>
        </div>
        @if(count($authSamples) > 1)
          <div class="auth-chips" role="radiogroup" aria-label="{{ __('design.home.try_dialect') }}">
            @foreach($authSamples as $i => $sample)
              <button type="button" class="auth-chip" role="radio" aria-checked="{{ $i === 0 ? 'true' : 'false' }}"
                data-nt-chip
                data-src="{{ $sample['audio_url'] }}"
                data-text="{{ \Illuminate\Support\Str::limit($sample['text'], 120) }}"
                data-voice="{{ \App\Support\NewDesignSamples::dialectLabel($sample['dialect']) }}">{{ \App\Support\NewDesignSamples::dialectLabel($sample['dialect']) }}</button>
            @endforeach
          </div>
        @endif
      </div>
    @endif

    <ul class="auth-facts">
      @if($authArabicCount > 0)<li>{{ __('design.auth.fact_dialects', ['count' => $authArabicCount]) }}</li>@endif
      @if($authEnglishCount > 0)<li>{{ __('design.auth.fact_accents', ['count' => $authEnglishCount]) }}</li>@endif
      <li>{{ __('design.auth.fact_cloning') }}</li>
    </ul>
  </div>
</aside>
@include('components.design-new.player-script')
