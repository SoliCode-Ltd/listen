{{--
  An option the member's plan does not include: shown dimmed and out of
  reach, with a small button to the billing page on the cheapest plan that
  has it. Without a lock the content shows as usual. Its fields are disabled
  by js/voice-studio.js so they are never sent. When no plan on sale has the
  option, it is left out.
--}}
@props(['lock' => null])
@if($lock['none'] ?? false)
@elseif($lock)
  <div {{ $attributes->merge(['class' => 'vs-locked']) }} data-vs-locked>
    <div class="vs-locked-body" inert aria-hidden="true">{{ $slot }}</div>
    <a class="vs-upgrade" href="{{ $lock['url'] }}">
      <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2.2"/><path d="M8.5 10.5V7.8a3.5 3.5 0 0 1 7 0v2.7"/></svg>
      <span>{{ $lock['plan'] ? __('ui.dashboard.upgrade_to_plan', ['plan' => $lock['plan']]) : __('ui.dashboard.upgrade_to_unlock') }}</span>
    </a>
  </div>
@else
  {{ $slot }}
@endif
