{{-- Sound environments: one voice line per place, heard placed in the environment (the
     studio's own engine) or clean, switched in place at the same moment. --}}
@php
  $vsEnvs = $environmentSamples ?? [];
  $vsEnvCount = count(array_filter(\App\Support\AudioEffects::ALL, fn ($row) => $row[1] !== 'clean'));
  $vsEnvIcons = [
    'stadium' => '<ellipse cx="12" cy="10" rx="9" ry="4.5"/><ellipse cx="12" cy="10" rx="4.5" ry="1.8"/><path d="M3 10v4c0 2.5 4 4.5 9 4.5s9-2 9-4.5v-4"/>',
    'announcement' => '<path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"/>',
    'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
    'radio' => '<path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"/><path d="M19.1 4.9C23 8.8 23 15.1 19.1 19"/>',
    'cave' => '<path d="M2 20 9 6l4 6 2-3 7 11z"/><path d="M10 20c0-2.2 .9-3.6 2-3.6s2 1.4 2 3.6"/>',
    'hall' => '<path d="M3 21h18M5 21V10M9 21V10M15 21V10M19 21V10"/><path d="M2 10l10-6 10 6z"/>',
    'church' => '<path d="M12 2v4M10 4h4"/><path d="M6 21V11l6-5 6 5v10"/><path d="M3 21h18"/><path d="M10 21v-4a2 2 0 0 1 4 0v4"/>',
  ];
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
      <article class="vs-env vs-env-{{ $env['effect'] }}" data-vp-track data-vp-env
        data-src="{{ $env['place_url'] }}" data-src-place="{{ $env['place_url'] }}" data-src-clean="{{ $env['clean_url'] }}">
        <header class="vs-env-head">
          <span class="vs-env-icon" aria-hidden="true">
            <span class="vs-env-ring"></span><span class="vs-env-ring"></span>
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $vsEnvIcons[$env['effect']] ?? $vsEnvIcons['hall'] !!}</svg>
          </span>
          <span class="vs-env-id">
            <span class="vs-env-place">{{ $env['place'] }}</span>
            <span class="vs-env-scene">{{ $env['scene'] }} · {{ $env['speaker'] }}</span>
          </span>
        </header>
        <p class="vs-env-text" dir="{{ $env['language'] === 'ar' ? 'rtl' : 'ltr' }}" lang="{{ $env['language'] }}">{{ $env['text'] }}</p>
        <div class="vs-env-switch" role="group" aria-label="{{ __('ui.landing.env_switch') }}">
          <button type="button" data-vp-mode="place" aria-pressed="true">{{ __('ui.landing.env_place') }}</button>
          <button type="button" data-vp-mode="clean" aria-pressed="false">{{ __('ui.landing.env_clean') }}</button>
        </div>
        <footer class="vs-card-foot">
          <button type="button" class="vs-play" data-vp-play aria-label="{{ __('ui.landing.showcase_play') }}: {{ $env['place'] }}" data-label-play="{{ __('ui.landing.showcase_play') }}" data-label-pause="{{ __('ui.landing.showcase_pause') }}">
            <svg class="vs-icon-play vs-flip" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l11.1-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14z"/></svg>
            <svg class="vs-icon-pause" width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1.2"/><rect x="14" y="5" width="4" height="14" rx="1.2"/></svg>
          </button>
          <x-home.mini-wave :seed="crc32($env['id']) % 997" />
        </footer>
      </article>
    @endforeach
  </div>
  <p class="vs-env-note">{{ __('ui.landing.env_note', ['count' => $vsEnvCount]) }}</p>
</section>
@endif
