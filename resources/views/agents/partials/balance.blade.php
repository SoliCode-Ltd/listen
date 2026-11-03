{{-- Call time: what is left for calls, what was used, when it renews, and what happens when it
     runs out (App\Support\Agents\AgentBalance). Numbers in Latin digits in both languages. --}}
@props(['balance'])
@php
  $b = $balance;
  $state = $b['state'];
  $n = $b['minutes_left'];
  $big = fn (string $key, int $count) => str_replace('__N__', '<b dir="ltr">'.number_format($count).'</b>', e(trans_choice($key, $count, ['count' => '__N__'])));
  $date = $b['renews_at'] ? $b['renews_at']->locale(app()->getLocale())->translatedFormat('j F') : null;
  $upgrade = $b['upgrade'];
@endphp
<section class="ag-card ag-balance is-{{ $state }}" aria-labelledby="ag-balance-title" data-ag-balance>
  <div class="ag-balance-main">
    <div class="ag-balance-head">
      <span class="ag-balance-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg></span>
      <h2 id="ag-balance-title">{{ __('agents.balance.title') }}</h2>
      @if($b['included'])<span class="ag-balance-tag">{{ __('agents.balance.shared') }}</span>@endif
    </div>

    @if($state === 'plan')
      <p class="ag-balance-big is-plan">{{ $upgrade['plan'] ? __('agents.balance.state_plan', ['plan' => $upgrade['plan']]) : __('agents.balance.state_plan_none') }}</p>
      <p class="ag-balance-meta">{{ trans_choice('agents.balance.free_test', $b['free_test_seconds'], ['count' => number_format($b['free_test_seconds'])]) }}</p>
    @else
      <p class="ag-balance-big">{!! $big('agents.balance.left', $n) !!}</p>
      <div class="ag-balance-bar" role="meter" aria-valuemin="0" aria-valuemax="{{ max(1, $b['minutes_total']) }}" aria-valuenow="{{ $n }}" aria-label="{{ __('agents.balance.title') }}">
        <i style="--p: {{ $b['percent_left'] }}%"></i>
      </div>
      <p class="ag-balance-meta">
        <span>{{ trans_choice('agents.balance.of', $b['minutes_total'], ['count' => number_format($b['minutes_total'])]) }}</span>
        <span>{{ trans_choice('agents.balance.used', $b['used_minutes'], ['count' => number_format($b['used_minutes'])]) }}</span>
        @if($date)<span>{{ $b['pack'] ? __('agents.balance.pack_ends', ['date' => $date]) : __('agents.balance.renews', ['date' => $date]) }}</span>@endif
        <span dir="auto">{{ __('agents.balance.credits', ['credits' => number_format($b['credits_left']), 'rate' => number_format($b['rate'])]) }}</span>
      </p>
      @if($state === 'low')
        <p class="ag-balance-note" role="status">{{ $date ? __('agents.balance.state_low', ['date' => $date]) : __('agents.balance.state_low_nodate') }}</p>
      @elseif($state === 'out')
        <p class="ag-balance-note" role="alert">{{ $b['by_cap'] && $date ? __('agents.balance.state_out_cap', ['count' => number_format($b['cap']), 'date' => $date]) : __('agents.balance.state_out') }}</p>
      @endif
    @endif
  </div>

  @if($state !== 'ok' || ! $b['included'])
    <div class="ag-balance-actions">
      @if($state === 'plan' && ! $upgrade['none'])
        <a class="ag-btn ag-btn-primary" href="{{ $upgrade['url'] }}">{{ __('agents.balance.upgrade', ['plan' => $upgrade['plan']]) }}</a>
      @elseif($state === 'plan')
        <a class="ag-btn" href="{{ route('contact') }}">{{ __('ui.nav.contact') }}</a>
      @else
        <a class="ag-btn {{ $state === 'out' ? 'ag-btn-primary' : '' }}" href="{{ route('billing.page') }}">{{ __('agents.balance.more') }}</a>
      @endif
    </div>
  @endif
</section>
