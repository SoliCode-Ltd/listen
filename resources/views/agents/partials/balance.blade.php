{{-- Call time: what is left for calls, what was used, when it renews, and what happens when it
     runs out (App\Support\Agents\AgentBalance). A big figure with its month, three plain facts,
     and the bar with what was used. Numbers in Latin digits in both languages. --}}
@props(['balance'])
@php
  $b = $balance;
  $state = $b['state'];
  $n = $b['minutes_left'];
  $date = $b['renews_at'] ? $b['renews_at']->locale(app()->getLocale())->translatedFormat('j F') : null;
  $upgrade = $b['upgrade'];
@endphp
<section class="ag-card ag-balance is-{{ $state }}" aria-labelledby="ag-balance-title" data-ag-balance>
  <header class="ag-balance-head">
    <span class="ag-balance-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg></span>
    <h2 id="ag-balance-title">{{ __('agents.balance.title') }}</h2>
    @if($b['included'])<span class="ag-balance-tag">{{ __('agents.balance.shared') }}</span>@endif
    @if($state !== 'ok' || ! $b['included'])
      <div class="ag-balance-actions">
        @if($state === 'plan' && ! $upgrade['none'])
          <a class="ag-btn ag-btn-sm ag-btn-primary" href="{{ $upgrade['url'] }}">{{ __('agents.balance.upgrade', ['plan' => $upgrade['plan']]) }}</a>
        @elseif($state === 'plan')
          <a class="ag-btn ag-btn-sm" href="{{ route('contact') }}">{{ __('ui.nav.contact') }}</a>
        @else
          <a class="ag-btn ag-btn-sm {{ $state === 'out' ? 'ag-btn-primary' : '' }}" href="{{ route('billing.page') }}">{{ __('agents.balance.more') }}</a>
        @endif
      </div>
    @endif
  </header>

  @if($state === 'plan')
    <p class="ag-balance-plan">{{ $upgrade['plan'] ? __('agents.balance.state_plan', ['plan' => $upgrade['plan']]) : __('agents.balance.state_plan_none') }}</p>
    <p class="ag-balance-sub">{{ trans_choice('agents.balance.free_test', $b['free_test_seconds'], ['count' => number_format($b['free_test_seconds'])]) }}</p>
  @else
    <div class="ag-balance-body">
      <div class="ag-balance-figure">
        <p><b>{{ number_format($n) }}</b><span>{{ trans_choice('agents.balance.unit', $n) }}</span></p>
        <p class="ag-balance-sub">{{ trans_choice('agents.balance.of_month', $b['minutes_total'], ['count' => number_format($b['minutes_total'])]) }}</p>
      </div>
      <dl class="ag-balance-stats">
        <div><dt>{{ __('agents.balance.stat_credits') }}</dt><dd>{{ number_format($b['credits_left']) }}</dd></div>
        <div><dt>{{ __('agents.balance.stat_rate') }}</dt><dd>{{ __('agents.balance.stat_rate_value', ['rate' => number_format($b['rate'])]) }}</dd></div>
        @if($date)<div><dt>{{ $b['pack'] ? __('agents.balance.stat_ends') : __('agents.balance.stat_renews') }}</dt><dd>{{ $date }}</dd></div>@endif
      </dl>
    </div>
    <div class="ag-balance-bar" role="meter" aria-valuemin="0" aria-valuemax="{{ max(1, $b['minutes_total']) }}" aria-valuenow="{{ $n }}" aria-label="{{ __('agents.balance.title') }}">
      <i style="--p: {{ $b['percent_left'] }}%"></i>
    </div>
    <p class="ag-balance-used">{{ trans_choice('agents.balance.used', $b['used_minutes'], ['count' => number_format($b['used_minutes'])]) }}<a href="#usage-breakdown">{{ __('usage.where') }}<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></a></p>
    @if($state === 'low')
      <p class="ag-balance-note" role="status">{{ $date ? __('agents.balance.state_low', ['date' => $date]) : __('agents.balance.state_low_nodate') }}</p>
    @elseif($state === 'out')
      <p class="ag-balance-note" role="alert">{{ $b['by_cap'] && $date ? __('agents.balance.state_out_cap', ['count' => number_format($b['cap']), 'date' => $date]) : __('agents.balance.state_out') }}</p>
    @endif
  @endif
</section>
