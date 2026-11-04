{{-- One agent, every channel: where callers come from, the agent, a live call. Shown on the
     agents page and on the homepage (where `home` sizes its heading like the homepage's own). --}}
@props(['home' => false])
@php
  $channelIcons = [
    '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M3 8h18M15 15h3"/>',
    '<path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1"/><path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/>',
    '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3M21 14v.01M14 21h7v-4"/>',
    '<path d="M14 3h7v7M10 14L21 3M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>',
  ];
  $flowLogo = '<svg viewBox="0 0 128 128"><rect x="5" y="59" width="10" height="10" rx="5"/><rect x="23" y="42" width="10" height="44" rx="5"/><rect x="41" y="28" width="10" height="72" rx="5"/><rect x="59" y="14" width="10" height="100" rx="5"/><rect x="77" y="28" width="10" height="72" rx="5"/><rect x="95" y="42" width="10" height="44" rx="5"/><rect x="113" y="59" width="10" height="10" rx="5"/></svg>';
  $titleId = $home ? 'agentsHomeFlowTitle' : 'agl-flow-title';
@endphp
<section {{ $attributes->class(['agl-section', 'agl-channels', 'agl', 'agl-channels-home' => $home]) }} data-agl-reveal aria-labelledby="{{ $titleId }}">
  <div class="agl-head">
    <h2 id="{{ $titleId }}">{{ __('agents_landing.flow_title') }}</h2>
    <p>{{ __('agents_landing.flow_text') }}</p>
  </div>
  <div class="agl-flow" aria-hidden="true">
    <ul class="agl-flow-sources">
      @foreach(__('agents_landing.flow_sources') as $i => $label)
        <li><span class="agl-flow-icon"><svg viewBox="0 0 24 24">{!! $channelIcons[$i] ?? '' !!}</svg></span>{{ $label }}</li>
      @endforeach
    </ul>
    <svg class="agl-flow-lines" viewBox="0 0 120 320" preserveAspectRatio="none">
      @foreach([26, 115, 205, 294] as $y)
        <path d="M0 {{ $y }} C 62 {{ $y }}, 58 160, 120 160" />
      @endforeach
    </svg>
    <div class="agl-flow-agent">
      <span class="agl-flow-logo">{!! $flowLogo !!}</span>
      <span class="agl-flow-agent-text">
        <strong>{{ __('agents_landing.flow_agent') }}</strong>
        <span>{{ __('agents_landing.flow_agent_sub') }}</span>
      </span>
    </div>
    <svg class="agl-flow-out" viewBox="0 0 64 320" preserveAspectRatio="none"><path d="M0 160 H 64" /></svg>
    <div class="agl-flow-call">
      <p class="agl-flow-call-head"><span><i></i>{{ __('agents_landing.call_live') }}</span><b dir="ltr">00:12</b></p>
      @foreach(__('agents_landing.flow_lines') as $i => [$role, $text])
        <p class="agl-flow-line is-{{ $role }}" style="--d: {{ $i }}">{{ $text }}</p>
      @endforeach
    </div>
  </div>
  <p class="agl-builders">{{ __('agents_landing.flow_builders') }}</p>
</section>
