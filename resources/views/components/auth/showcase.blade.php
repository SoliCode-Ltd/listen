{{-- Brand panel beside the sign in / register form. Real dialect samples and plan data only. --}}
@php
  $authMode = $authMode ?? 'login';
  $authSamples = \App\Support\AuthSamples::forLocale(null, 5);
  $authSampleArabic = str_starts_with(strtolower(app()->getLocale()), 'ar');
  $authFirst = $authSamples[0] ?? null;
  $authFreeChars = $authMode === 'register' ? \App\Support\AuthSamples::freePlanChars() : null;
  // Smooth, speech-like envelope for the waveform bars (6px to 32px).
  $authWave = [];
  for ($i = 0; $i < 56; $i++) {
    $envelope = 0.35 + 0.65 * abs(sin($i / 56 * M_PI * 1.6 + 0.4));
    $detail = 0.55 + 0.45 * abs(sin($i * 1.13) * cos($i * 0.37));
    $authWave[] = (int) round(6 + 26 * $envelope * $detail);
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
      <div class="auth-player" data-vp-try>
        @if(count($authSamples) > 1)
          <div class="auth-tabs" role="radiogroup" aria-label="{{ $authSampleArabic ? __('design.auth.try_dialect') : __('design.auth.sample_label') }}">
            @foreach($authSamples as $i => $sample)
              <button type="button" class="auth-tab" role="radio" aria-checked="{{ $i === 0 ? 'true' : 'false' }}"
                data-vp-chip
                data-src="{{ $sample['audio_url'] }}"
                data-text="{{ \Illuminate\Support\Str::limit($sample['text'], 240) }}"
                data-voice="{{ \App\Support\AuthSamples::dialectLabel($sample['dialect']) }}">{{ \App\Support\AuthSamples::dialectLabel($sample['dialect']) }}</button>
            @endforeach
          </div>
        @endif
        <p class="auth-player-text" dir="{{ $authSampleArabic ? 'rtl' : 'ltr' }}" lang="{{ $authSampleArabic ? 'ar' : 'en' }}" data-vp-try-text data-vp-words>{{ \Illuminate\Support\Str::limit($authFirst['text'], 240) }}</p>
        <div class="auth-player-bar" data-vp-track data-src="{{ $authFirst['audio_url'] }}">
          <button type="button" class="auth-play" data-vp-play aria-label="{{ __('design.auth.play') }}" data-label-play="{{ __('design.auth.play') }}" data-label-pause="{{ __('design.auth.pause') }}">
            <svg class="auth-icon-play" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14z"/></svg>
            <svg class="auth-icon-pause" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1.2"/><rect x="14" y="5" width="4" height="14" rx="1.2"/></svg>
          </button>
          <div class="vp-wave auth-wave" aria-hidden="true">
            <div class="auth-wave-bars">@foreach($authWave as $h)<i style="height: {{ $h }}px"></i>@endforeach</div>
            <div class="auth-wave-bars auth-wave-fill">@foreach($authWave as $h)<i style="height: {{ $h }}px"></i>@endforeach</div>
          </div>
          <span class="auth-time" dir="ltr" data-vp-time>0:00</span>
        </div>
      </div>
    @endif

  </div>
</aside>
@include('components.voice-player-script')
