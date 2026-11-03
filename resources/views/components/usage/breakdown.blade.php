{{-- Where the credits went this period, inside the studio's overview and on the Agents page (the
     same credits pay for both): one bar of what used them, each kind with its credits, the calls by
     agent, and every item on request. Read from what was charged (App\Support\CreditUsage); Latin
     digits in both languages; styles in css/usage.css. --}}
@props(['usage', 'agentsOpen' => true, 'perMinute' => null])
@php
  $u = $usage;
  // Nothing used this period: nothing to show, and no link to it (CreditUsage::hasUse).
  $hasUse = \App\Support\CreditUsage::hasUse($u);
  $locale = app()->getLocale();
  $minutes = fn (int $s) => trans_choice('usage.minutes', intdiv($s, 60), ['count' => number_format(intdiv($s, 60))]);
  $length = fn (int $s) => $s > 0 ? sprintf('%d:%02d', intdiv($s, 60), $s % 60) : '—';
  $kinds = array_values(array_filter(['studio', 'agent', 'api', 'dub'], fn ($k) => ($k !== 'agent' || $agentsOpen || $u['parts']['agent']['count'] > 0) && ($k === 'studio' || $k === 'agent' || $u['parts'][$k]['count'] > 0)));
  $usedParts = max(1, array_sum(array_map(fn ($k) => $u['parts'][$k]['credits'], $kinds)));
  $scale = max(1, $u['total'], $usedParts + $u['left']);
  $icons = [
    'studio' => '<path d="M3 10v4M7.5 7v10M12 4v16M16.5 7v10M21 10v4"/>',
    'agent' => '<path d="M5.1 3.5h3.1l1.6 4.1-2 1.3a11.3 11.3 0 0 0 5.3 5.3l1.3-2 4.1 1.6v3.1a2 2 0 0 1-2.2 2A16.6 16.6 0 0 1 3.1 5.7a2 2 0 0 1 2-2.2z"/>',
    'api' => '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4M13.5 5l-3 14"/>',
    'dub' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M10 9.5v5l4.5-2.5z"/>',
  ];
@endphp
@if($hasUse)
{{-- Closed until "Credit usage" is chosen (the chip by the credits, or the menu): then it opens and comes into view. --}}
<section class="us us-embed" id="usage-breakdown" aria-labelledby="us-where" hidden data-usage-breakdown>
  <div class="us-card">
    <div class="us-card-head">
      <h2 id="us-where">{{ __('usage.where') }}</h2>
      <p>{{ __('usage.where_hint') }}</p>
    </div>
    <div class="us-bar" role="img" aria-label="{{ __('usage.where') }}">
      @foreach($kinds as $k)
        @if($u['parts'][$k]['credits'] > 0)<i class="is-{{ $k }}" style="--w: {{ round($u['parts'][$k]['credits'] / $scale * 100, 2) }}%"></i>@endif
      @endforeach
    </div>
    <ul class="us-legend">
      @foreach($kinds as $k)
        <li><i class="is-{{ $k }}" aria-hidden="true"></i>{{ __('usage.kinds.'.$k) }} <b>{{ number_format($u['parts'][$k]['credits']) }}</b></li>
      @endforeach
      <li><i class="is-left" aria-hidden="true"></i>{{ __('usage.left') }} <b>{{ number_format($u['left']) }}</b></li>
    </ul>
    <ul class="us-parts">
      @foreach($kinds as $k)
        @php $p = $u['parts'][$k]; $pct = $p['credits'] > 0 ? max(1, (int) round($p['credits'] / $usedParts * 100)) : 0; @endphp
        <li class="{{ $p['count'] ? '' : 'is-empty' }}">
          <span class="us-part-icon is-{{ $k }}" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $icons[$k] !!}</svg></span>
          <div class="us-part-text">
            <b>{{ __('usage.kinds.'.$k) }}</b>
            <span>{{ trans_choice('usage.kind_detail.'.$k, $p['count'], ['count' => number_format($p['count']), 'minutes' => $minutes($p['seconds'])]) }}</span>
          </div>
          <div class="us-part-num">
            <b>{{ number_format($p['credits']) }}</b>
            @if($pct)<span>{{ __('usage.share', ['percent' => $pct]) }}</span>@endif
          </div>
          {{-- The calls by agent, under the calls: each agent's credits and its share of them. --}}
          @if($k === 'agent' && $u['agents'])
            <ul class="us-agents" aria-label="{{ __('usage.by_agent') }}">
              @foreach($u['agents'] as $a)
                <li>
                  <span><bdi>{{ $a['name'] }}</bdi></span>
                  <small>{{ trans_choice('usage.kind_detail.agent', $a['count'], ['count' => number_format($a['count']), 'minutes' => $minutes($a['seconds'])]) }}</small>
                  <b>{{ number_format($a['credits']) }}</b>
                  <i style="--w: {{ round($a['credits'] / max(1, $p['credits']) * 100, 1) }}%" aria-hidden="true"></i>
                </li>
              @endforeach
            </ul>
          @endif
        </li>
      @endforeach
    </ul>
    @if($agentsOpen && $perMinute)
      <p class="us-note">{{ __('usage.rate', ['rate' => number_format($perMinute)]) }}</p>
    @endif

    @if($u['items'])
      {{-- Every item, newest first: one tap away, so the section stays short. --}}
      <details class="us-items">
        <summary><span>{{ __('usage.history') }}</span><small>{{ count($u['items']) }}</small><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5"/></svg></summary>
        <div class="us-table-wrap">
          <table class="us-table">
            <thead><tr><th scope="col">{{ __('usage.col_item') }}</th><th scope="col">{{ __('usage.col_when') }}</th><th scope="col">{{ __('usage.col_length') }}</th><th scope="col" class="is-num">{{ __('usage.col_credits') }}</th></tr></thead>
            <tbody>
              @foreach($u['items'] as $item)
                @php
                  $title = match ($item['kind']) {
                    'agent' => __('usage.call_with', ['name' => $item['title']]),
                    'api' => $item['title'] !== '' ? $item['title'] : __('usage.api_item'),
                    'dub' => $item['title'] !== '' ? $item['title'] : __('usage.dub_item'),
                    default => $item['title'] !== '' ? $item['title'] : __('usage.untitled'),
                  };
                @endphp
                <tr>
                  <td><span class="us-item"><i class="is-{{ $item['kind'] }}" aria-hidden="true"></i><span><b dir="auto">{{ \Illuminate\Support\Str::limit($title, 70) }}</b><small>{{ __('usage.kinds.'.$item['kind']) }}</small></span></span></td>
                  <td class="us-when">{{ $item['at']?->copy()->locale($locale)->translatedFormat($locale === 'ar' ? 'j M، H:i' : 'j M, H:i') }}</td>
                  <td class="us-len"><bdi dir="ltr">{{ $length($item['seconds']) }}</bdi></td>
                  <td class="is-num"><b>{{ number_format($item['credits']) }}</b></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </details>
    @endif
  </div>
</section>
<script>
  (function () {
    var box = document.getElementById('usage-breakdown');
    if (!box) return;
    function open(scroll) {
      if (box.hidden) { box.hidden = false; box.classList.add('is-opening'); }
      if (scroll) box.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    }
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a[href="#usage-breakdown"]');
      if (!a) return;
      e.preventDefault();
      open(true);
      if (history.replaceState) history.replaceState(null, '', '#usage-breakdown');
    });
    if (location.hash === '#usage-breakdown') open(true);
  })();
</script>
@endif
