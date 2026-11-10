{{-- Call time, as the studio shows its credits: one ring of how much of the month is used, the
     minutes left in large type with the month under it, the plain facts as chips, and a clear way to
     get more time (App\Support\Agents\AgentBalance). Brand violet in every state: when time runs out
     one line says so. Latin digits in both languages.
     On a phone the same card reads top to bottom from the start edge: the minutes left, a bar of the
     month used in place of the ring, then the facts as rows, each with its name and its value
     (public/css/agents.css). The spans below name the two halves of a fact for those rows. --}}
@props(['balance'])
@php
  $b = $balance;
  $state = $b['state'];
  $n = $b['minutes_left'];
  $date = $b['renews_at'] ? $b['renews_at']->locale(app()->getLocale())->translatedFormat('j F') : null;
  $upgrade = $b['upgrade'];
  $total = max(0, (int) $b['minutes_total']);
  $usedPct = $total > 0 ? (int) max(0, min(100, round(($total - $n) / $total * 100))) : 0;
@endphp
<section class="ag-balance is-{{ $state }}" id="call-time" aria-labelledby="ag-balance-title" data-ag-balance>
  @if($state === 'plan')
    <span class="ag-balance-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg></span>
    <div class="ag-balance-main">
      <h2 id="ag-balance-title">{{ __('agents.balance.title') }}</h2>
      <p class="ag-balance-plan">{{ $upgrade['plan'] ? __('agents.balance.state_plan', ['plan' => $upgrade['plan']]) : __('agents.balance.state_plan_none') }}</p>
      <p class="ag-balance-sub">{{ trans_choice('agents.balance.free_test', $b['free_test_seconds'], ['count' => number_format($b['free_test_seconds'])]) }}</p>
    </div>
    @if(! $upgrade['none'])
      <a class="ag-balance-cta" href="{{ $upgrade['url'] }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"/></svg>{{ __('agents.balance.upgrade', ['plan' => $upgrade['plan']]) }}</a>
    @else
      <a class="ag-balance-cta" href="{{ route('contact') }}">{{ __('ui.nav.contact') }}</a>
    @endif
  @else
    <div class="ag-balance-ring" style="--p: {{ $usedPct }}" role="img" aria-label="{{ __('agents.balance.ring_label', ['percent' => $usedPct]) }}">
      <svg viewBox="0 0 120 120" aria-hidden="true">
        <defs><linearGradient id="agCallRing" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#A28FFF"/><stop offset="1" stop-color="#5B3FE6"/></linearGradient></defs>
        <circle class="ag-balance-ring-track" cx="60" cy="60" r="52"/>
        <circle class="ag-balance-ring-arc" cx="60" cy="60" r="52" pathLength="100"/>
      </svg>
      <div class="ag-balance-ring-inner"><b>{{ $usedPct }}%</b><span>{{ __('agents.balance.used_short') }}</span></div>
    </div>
    <div class="ag-balance-main">
      <div class="ag-balance-head">
        <h2 id="ag-balance-title">{{ __('agents.balance.left_label') }}</h2>
        @if($b['included'])<span class="ag-balance-tag">{{ __('agents.balance.shared') }}</span>@endif
      </div>
      <p class="ag-balance-value"><strong>{{ number_format($n) }}</strong><span>{{ trans_choice('agents.balance.unit', $n) }}</span></p>
      <div class="ag-balance-bar" aria-hidden="true"><i style="width: {{ $usedPct }}%"></i></div>
      <p class="ag-balance-sub"><span>{{ trans_choice('agents.balance.of_month', $total, ['count' => number_format($total)]) }}</span><span class="ag-balance-dot" aria-hidden="true"> · </span><span>{{ trans_choice('agents.balance.used', $b['used_minutes'], ['count' => number_format($b['used_minutes'])]) }}</span></p>
      @if($state === 'out')
        <p class="ag-balance-out" role="alert">{{ $b['by_cap'] && $date ? __('agents.balance.state_out_cap', ['count' => number_format($b['cap']), 'date' => $date]) : __('agents.balance.state_out') }}</p>
      @endif
      <div class="ag-balance-chips">
        <span class="ag-fact"><x-credit-icon /><span class="ag-fact-t"><b class="ag-fact-v">{{ number_format($b['credits_left']) }}</b> <span class="ag-fact-k">{{ __('ui.dashboard.credits_left_suffix') }}</span></span></span>
        <span class="ag-fact"><span class="ag-fact-k">{{ __('agents.balance.stat_rate') }}</span> <b class="ag-fact-v">{{ __('agents.balance.stat_rate_value', ['rate' => number_format($b['rate'])]) }}</b></span>
        @if($date)<span class="ag-fact">{!! str_replace('@@', '<span class="ag-fact-v ag-fact-date">'.e($date).'</span>', e($b['pack'] ? __('agents.balance.pack_ends', ['date' => '@@']) : __('agents.balance.renews', ['date' => '@@']))) !!}</span>@endif
        @if($hasUse ?? false)
        <a class="ag-fact ag-fact-link" href="#usage-breakdown">{{ __('usage.where') }}<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></a>
        @endif
      </div>
    </div>
    @unless(\App\Support\InApp\InApp::hidesPlans())
    <a class="ag-balance-cta" href="{{ route('billing.page') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 8v8M8 12h8"/></svg>{{ __('agents.balance.more') }}</a>
    @endunless
  @endif
</section>
