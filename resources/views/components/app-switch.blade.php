{{-- Studio | Agents: the two products of the app, chosen at the top of each one's side menu. The
     button shows the product in use; it opens the two, each with a line on what it is, and a tick
     on the one in use. Picking the other opens it at once (components/scripts: initAppSwitch). --}}
@props(['current' => 'studio'])
@php
  $products = [
    'studio' => [
      'url' => route('dashboard'),
      'name' => __('agents.switch_studio'),
      'text' => __('agents.switch_studio_text'),
      'icon' => '<path d="M4 10v4M8 7v10M12 4v16M16 7v10M20 10v4"/>',
    ],
    'agents' => [
      'url' => route('voice-agents.index'),
      'name' => __('agents.switch_agents'),
      'text' => __('agents.switch_agents_text'),
      'icon' => '<path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/>',
    ],
  ];
  $current = isset($products[$current]) ? $current : 'studio';
  $menuId = 'app-switch-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6));
@endphp
<div {{ $attributes->class(['app-switch']) }} data-app-switch>
  <button type="button" class="app-switch-btn" aria-expanded="false" aria-controls="{{ $menuId }}" aria-label="{{ __('agents.switch_label') }}: {{ $products[$current]['name'] }}">
    <span class="app-switch-mark" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $products[$current]['icon'] !!}</svg></span>
    <span class="app-switch-name">{{ $products[$current]['name'] }}</span>
    <svg class="app-switch-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 9.5l4-4 4 4M8 14.5l4 4 4-4"/></svg>
  </button>
  <nav class="app-switch-menu" id="{{ $menuId }}" aria-label="{{ __('agents.switch_label') }}">
    @foreach($products as $key => $product)
      <a class="app-switch-option{{ $key === $current ? ' is-current' : '' }}" href="{{ $product['url'] }}" @if($key === $current) aria-current="page" @endif>
        <span class="app-switch-mark" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $product['icon'] !!}</svg></span>
        <span class="app-switch-copy">
          <strong>{{ $product['name'] }}</strong>
          <span>{{ $product['text'] }}</span>
        </span>
        <svg class="app-switch-tick" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
      </a>
    @endforeach
  </nav>
</div>
