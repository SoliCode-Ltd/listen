{{--
  Speed and pitch, each with a short note on what it changes. Used by the
  dashboard generator and the demo page; the page scripts read #speed and #pitch.
--}}
@props(['speed' => '1.0', 'pitch' => '0'])
<div {{ $attributes->merge(['class' => 'vs-row-2 vs-field']) }}>
  <div>
    <div class="vs-field-label"><span>{{ __('ui.dashboard.speed') }}</span><span class="vs-value" dir="ltr"><span class="slider-value" id="speedVal" data-unit="×">{{ number_format((float) $speed, 1) }}</span></span></div>
    <p class="vs-slider-hint" id="speedHint">{{ __('ui.dashboard.speed_hint') }}</p>
    <div class="slider-wrap">
      <input type="range" name="speed" id="speed" min="0.5" max="2.0" step="0.1" value="{{ $speed }}" aria-label="{{ __('ui.dashboard.speed') }}" aria-describedby="speedHint">
    </div>
  </div>
  <div>
    <div class="vs-field-label"><span>{{ __('ui.dashboard.pitch') }}</span><span class="vs-value" dir="ltr"><span class="slider-value" id="pitchVal">{{ $pitch }}</span></span></div>
    <p class="vs-slider-hint" id="pitchHint">{{ __('ui.dashboard.pitch_hint') }}</p>
    <div class="slider-wrap">
      <input type="range" name="pitch" id="pitch" min="-10" max="10" step="1" value="{{ $pitch }}" aria-label="{{ __('ui.dashboard.pitch') }}" aria-describedby="pitchHint">
    </div>
  </div>
</div>
