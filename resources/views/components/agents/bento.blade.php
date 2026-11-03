{{-- Agents, shown working: a call that ends with something done, the month at a glance, and three
     things it does. On the Agents page and the homepage; it plays when it comes into view. --}}
@php
  $does = __('agents_landing.does');
  $bars = [38, 52, 44, 61, 57, 70, 48, 66, 74, 59, 81, 69, 88, 76];
  $icons = [
    0 => '<path d="M4 5h16v11H8l-4 4z"/><path d="M8 9h8M8 12h5"/>',
    2 => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M9 15l2 2 4-4"/>',
    3 => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18.5 14a6.5 6.5 0 0 1 3 6"/>',
  ];
@endphp
<div class="agl-bento" data-agl-bento>
  <article class="agl-bento-call" data-agl-rise>
    <span class="agl-bento-live"><i aria-hidden="true"></i>{{ __('agents_landing.bento_live') }} <b dir="ltr">0:42</b></span>
    <div class="agl-bento-lines" aria-hidden="true">
      @foreach(__('agents_landing.bento_lines') as $i => [$role, $text])
        <p class="agl-bento-line is-{{ $role }}" style="--d: {{ $i }}" dir="auto">{{ $text }}</p>
      @endforeach
      <p class="agl-bento-chip" style="--d: 4"><i></i>{{ __('agents_landing.bento_chip') }}</p>
    </div>
    <p class="agl-bento-caption">{{ __('agents_landing.bento_call_caption') }}</p>
  </article>
  <article class="agl-bento-stats" data-agl-rise style="--rise-d: .08s">
    <h3>{{ __('agents_landing.bento_stats_title') }}</h3>
    <div class="agl-bento-nums">
      @foreach(__('agents_landing.bento_stats') as [$number, $label])
        <div><b data-agl-count="{{ $number }}">{{ $number }}</b><span>{{ $label }}</span></div>
      @endforeach
    </div>
    <div class="agl-bento-bars" aria-hidden="true">
      @foreach($bars as $i => $h)<i style="--h: {{ $h }}%; --d: {{ $i }}"></i>@endforeach
    </div>
    <p class="agl-bento-caption">{{ __('agents_landing.bento_stats_caption') }}</p>
  </article>
  @foreach($icons as $i => $icon)
    <article class="agl-bento-small" data-agl-rise style="--rise-d: {{ .16 + $loop->index * .08 }}s">
      <span class="agl-does-icon" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $icon !!}</svg></span>
      <h3>{{ $does[$i][0] }}</h3>
      <p>{{ $does[$i][1] }}</p>
    </article>
  @endforeach
</div>
