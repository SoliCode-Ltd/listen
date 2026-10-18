{{-- The documentation's table of contents, with a search that filters it. --}}
<div class="docs-search">
  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
  <input type="search" id="{{ $searchId }}" data-docs-search placeholder="{{ __('developers.docs.search') }}" aria-label="{{ __('developers.docs.search') }}">
</div>
@foreach($nav as $group => $links)
  <div class="docs-nav-group" data-nav-group>
    <span>{{ __('developers.docs.groups.'.$group) }}</span>
    @foreach($links as $link)
      <a href="#{{ $link[0] }}" data-nav-link="{{ $link[0] }}">
        @if(isset($link[2]))<span class="docs-method mono m-{{ strtolower($link[2]) }}" data-code>{{ $link[2] }}</span>@endif
        <span>{{ $link[1] }}</span>
      </a>
    @endforeach
  </div>
@endforeach
<p class="docs-nav-empty" data-nav-empty>{{ __('developers.docs.no_results') }}</p>
