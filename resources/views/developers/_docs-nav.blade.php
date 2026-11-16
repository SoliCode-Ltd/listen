{{--
  The documentation's contents, with a search that filters them. A link is
  [id, name] or [id, name, method]; the API reference's links come in its
  resources, ['id' =>, 'title' =>, 'links' => [...]], each resource's name
  leading to where it begins and its endpoints under it.
--}}
<div class="docs-search" role="search">
  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
  <input type="search" id="{{ $searchId }}" data-docs-search placeholder="{{ __('developers.docs.search') }}" aria-label="{{ __('developers.docs.search') }}" autocomplete="off">
</div>
<nav aria-label="{{ __('developers.docs.on_this_page') }}">
  @foreach($nav as $group => $links)
    <div class="docs-nav-group" data-nav-group>
      <span class="docs-nav-title">{{ __('developers.docs.groups.'.$group) }}</span>
      @foreach($links as $link)
        @if(isset($link['links']))
          <div class="docs-nav-resource" data-nav-resource>
            <a href="#{{ $link['id'] }}" class="docs-nav-resource-name" data-nav-link="{{ $link['id'] }}">{{ $link['title'] }}</a>
            @foreach($link['links'] as $endpoint)
              <a href="#{{ $endpoint[0] }}" class="docs-nav-endpoint" data-nav-link="{{ $endpoint[0] }}">
                <span class="docs-method mono m-{{ strtolower($endpoint[2]) }}" data-code>{{ $endpoint[2] }}</span>
                <span>{{ $endpoint[1] }}</span>
              </a>
            @endforeach
          </div>
        @else
          <a href="#{{ $link[0] }}" data-nav-link="{{ $link[0] }}"><span>{{ $link[1] }}</span></a>
        @endif
      @endforeach
    </div>
  @endforeach
  <p class="docs-nav-empty" data-nav-empty>{{ __('developers.docs.no_results') }}</p>
</nav>
