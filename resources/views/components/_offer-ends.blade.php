{{-- When an offer ends, as a clock counting down to the second: "Offer ends in" and the days,
     hours, minutes and seconds (the days only while there is more than one), each number with its
     unit under it. The page keeps it current; screen readers get the time left in words. --}}
@php
  $endsText = $offerEndsText($endsAt);
  $endsLeft = max(0, \Illuminate\Support\Carbon::parse($endsAt)->getTimestamp() - now()->getTimestamp());
  $endsUnits = explode('|', __('ui.pricing.countdown_units'));
  $endsParts = array_values(array_filter([
    [intdiv($endsLeft, 86400), $endsUnits[0] ?? '', $endsLeft >= 86400],
    [intdiv($endsLeft % 86400, 3600), $endsUnits[1] ?? '', true],
    [intdiv($endsLeft % 3600, 60), $endsUnits[2] ?? '', true],
    [$endsLeft % 60, $endsUnits[3] ?? '', true],
  ], fn ($part) => $part[2]));
@endphp
@if($endsText !== '')
  <span class="offer-ends" data-ends="{{ $endsAt }}">
    <span class="offer-ends-label" aria-hidden="true">{{ __('ui.pricing.discount_offer_ends') }}</span>
    <span class="offer-ends-clock" aria-hidden="true">@foreach($endsParts as [$count, $unit])<span class="offer-ends-unit"><b>{{ sprintf('%02d', $count) }}</b><small>{{ $unit }}</small></span>@endforeach</span>
    <span class="offer-ends-text">{{ $endsText }}</span>
  </span>
@endif
