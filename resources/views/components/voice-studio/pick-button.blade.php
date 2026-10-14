{{-- The card that shows a studio choice and opens its picker (js/voice-studio.js). --}}
<button type="button" class="vs-pick" data-vs-pick="{{ $kind }}" aria-haspopup="dialog" aria-labelledby="{{ $labelId }} {{ $kind }}PickValue">
  <span class="vs-pick-ico" data-vs-pick-icon aria-hidden="true"></span>
  <span class="vs-pick-text">
    <span class="vs-pick-value" id="{{ $kind }}PickValue" data-vs-pick-value>{{ $value }}</span>
    <span class="vs-pick-desc" data-vs-pick-desc></span>
  </span>
  <svg class="vs-pick-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 10l4 4 4-4"/></svg>
</button>
