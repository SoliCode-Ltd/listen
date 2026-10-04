@php
  $ntDialects = __('ui.dialects');
  $ntDialects = is_array($ntDialects) ? $ntDialects : [];
  $ntFeatured = array_values(array_filter(
    array_map(fn ($key) => $ntDialects[$key] ?? null, ['msa', 'saudi', 'egyptian', 'emirati', 'lebanese', 'moroccan', 'american', 'british'])
  ));
  $ntMore = max(0, count($ntDialects) - count($ntFeatured));
@endphp
@if(count($ntFeatured) > 0)
<div class="nt-dialects">
  <p class="nt-dialects-label">{{ __('design.home.dialects_label') }}</p>
  <ul class="nt-dialects-list">
    @foreach($ntFeatured as $ntDialect)
      <li class="nt-chip">{{ $ntDialect }}</li>
    @endforeach
    @if($ntMore > 0)
      <li class="nt-chip nt-chip-more">{{ __('design.home.dialects_more', ['count' => $ntMore]) }}</li>
    @endif
  </ul>
</div>
@endif
