{{-- "Show more" under a voices grid on the samples page (data-vp-page): the next cards of
     the chosen region or mood. $left: cards still hidden at first; $label: the button's text key. --}}
<div class="vs-more-wrap">
  <button type="button" class="vs-more-btn" data-vp-more-cards @if($left <= 0) hidden @endif>{{ __($label ?? 'ui.samples.show_more') }}</button>
</div>
