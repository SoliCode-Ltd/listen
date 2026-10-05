{{-- Brand panel beside the sign in / register form. Real dialect samples and plan data only. --}}
@php
  $authMode = $authMode ?? 'login';
  $authSamples = \App\Support\NewDesignSamples::forLocale(null, 5);
  $authSampleArabic = str_starts_with(strtolower(app()->getLocale()), 'ar');
  $authFirst = $authSamples[0] ?? null;
  $authFreeChars = $authMode === 'register' ? \App\Support\NewDesignSamples::freePlanChars() : null;
  // Smooth, speech-like envelope for the waveform bars (4px to 30px).
  $authWave = [];
  for ($i = 0; $i < 46; $i++) {
    $envelope = 0.35 + 0.65 * abs(sin($i / 46 * M_PI * 1.6 + 0.4));
    $detail = 0.55 + 0.45 * abs(sin($i * 1.13) * cos($i * 0.37));
    $authWave[] = (int) round(4 + 26 * $envelope * $detail);
  }
@endphp
<aside class="auth-showcase" aria-label="{{ __('design.auth.sample_label') }}">
  <div class="auth-showcase-inner">
    @if($authMode === 'register')
      <h2 class="auth-headline">{{ __('design.auth.register_headline') }}</h2>
      <ul class="auth-perks">
        @if($authFreeChars)
          <li>{{ __('design.auth.perk_chars', ['chars' => number_format($authFreeChars)]) }}</li>
        @endif
        <li>{{ __('design.auth.perk_voices') }}</li>
        <li>{{ __('design.auth.perk_extension') }}</li>
      </ul>
    @else
      <h2 class="auth-headline">{{ __('design.auth.login_headline') }}</h2>
      <p class="auth-subline">{{ __('design.auth.login_body') }}</p>
    @endif

    @if($authFirst)
      <div class="auth-player" data-nt-try>
        <div class="auth-player-top">
          <span class="auth-player-avatar" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M3 10v4M7.5 7v10M12 4v16M16.5 7v10M21 10v4"/></svg>
          </span>
          <span class="auth-player-meta">
            <span class="auth-player-title">{{ __('design.auth.sample_label') }}</span>
            <span class="auth-player-voice" data-nt-try-voice>{{ \App\Support\NewDesignSamples::dialectLabel($authFirst['dialect']) }}</span>
          </span>
        </div>
        <p class="auth-player-text" dir="{{ $authSampleArabic ? 'rtl' : 'ltr' }}" lang="{{ $authSampleArabic ? 'ar' : 'en' }}" data-nt-try-text>{{ \Illuminate\Support\Str::limit($authFirst['text'], 120) }}</p>
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
          <div class="auth-chips" role="radiogroup" aria-label="{{ $authSampleArabic ? __('design.home.try_dialect') : __('design.auth.sample_label') }}">
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

  </div>
</aside>
@include('components.design-new.player-script')
