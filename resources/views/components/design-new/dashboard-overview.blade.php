{{-- Dashboard overview for the new site design (Listen 2.0, Overview board). Keeps every
     id the dashboard script updates (usage meters, KPIs, job stats). --}}
@php
  $ntName = trim((string) \Illuminate\Support\Str::of((string) (auth()->user()->name ?? ''))->explode(' ')->first());
  $ntCharsLeft = max(0, (int) $planChars - (int) $usage->total_chars);
  $ntRequestsLeft = $requestsUnlimited ? null : max(0, (int) $planRequests - (int) $usage->total_requests);
  $ntRecent = $jobs->take(5);
  $ntDuration = function ($seconds): string {
    $seconds = max(0, (int) $seconds);
    return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
  };
  $ntStatusPill = ['done' => 'nt-pill-ok', 'failed' => 'nt-pill-danger', 'processing' => 'nt-pill-warn', 'queued' => 'nt-pill-muted', 'pending' => 'nt-pill-muted'];
  $ntWeek = [];
  $ntWeekMax = 0;
  for ($i = 6; $i >= 0; $i--) {
    $day = now()->subDays($i);
    $count = $jobs->filter(fn ($job) => $job->created_at && $job->created_at->isSameDay($day))->count();
    $ntWeek[] = ['label' => $day->locale(app()->getLocale())->isoFormat('dd'), 'count' => $count, 'today' => $i === 0];
    $ntWeekMax = max($ntWeekMax, $count);
  }
  $ntWeekTotal = array_sum(array_column($ntWeek, 'count'));
@endphp
<div class="nt-overview" data-nt-overview>
  <div class="nt-overview-head">
    <div>
      <h1 class="nt-h2">{{ $ntName !== '' ? __('design.dashboard.hello', ['name' => $ntName]) : __('design.dashboard.hello_plain') }}</h1>
      <p class="nt-small nt-overview-sub">{{ __('design.dashboard.hello_sub') }}</p>
    </div>
    <a class="btn btn-primary" href="#generateVoiceSection">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
      {{ __('design.dashboard.new_generation') }}
    </a>
  </div>

  <div class="nt-stats">
    <div class="nt-card nt-stat">
      <div class="nt-stat-label">{{ __('design.dashboard.chars_card') }}</div>
      <div class="nt-stat-value"><span data-nt-chars-left>{{ number_format($ntCharsLeft) }}</span><span class="nt-stat-of">{{ __('design.dashboard.of_total', ['total' => number_format($planChars)]) }}</span></div>
      <div class="meter-track nt-stat-meter"><div class="meter-fill" id="usageCharsMeter" style="width:{{ max(0, 100 - $usageCharsPercent) }}%"></div></div>
      <div class="nt-stat-label" id="usageCharsText">{{ __('ui.dashboard.chars_remaining_of_total', ['remaining' => number_format($ntCharsLeft), 'total' => number_format($planChars)]) }}</div>
      @if (($giftChars ?? 0) > 0)
        <div class="nt-stat-label gift-chars-note" data-gift-chars="{{ (int) $giftChars }}">🎁 {{ __('ui.dashboard.gift_chars_note', ['count' => number_format((int) $giftChars)]) }}</div>
      @endif
    </div>
    <div class="nt-card nt-stat">
      <div class="nt-stat-label">{{ __('design.dashboard.requests_card') }}</div>
      <div class="nt-stat-value"><span id="kpiRequests">{{ $usage->total_requests }}</span><span class="nt-stat-of">{{ $requestsUnlimited ? __('design.dashboard.unlimited') : __('design.dashboard.of_total', ['total' => number_format($planRequests)]) }}</span></div>
      <div class="meter-track nt-stat-meter"><div class="meter-fill requests" id="usageRequestsMeter" style="width:{{ max(0, 100 - $usageRequestsPercent) }}%"></div></div>
      <div class="nt-stat-label" id="usageRequestsText">
        @if ($requestsUnlimited)
          {{ __('ui.dashboard.used_requests_of_unlimited', ['used' => number_format($usage->total_requests)]) }}
        @else
          {{ __('ui.dashboard.requests_remaining_of_total', ['remaining' => number_format($ntRequestsLeft), 'total' => number_format($planRequests)]) }}
        @endif
      </div>
    </div>
    <div class="nt-card nt-stat">
      <div class="nt-stat-label">{{ __('design.dashboard.generations_card') }}</div>
      <div class="nt-stat-value"><span id="kpiTotal">{{ $stats['total'] }}</span></div>
      <div class="nt-stat-breakdown">
        <span><i class="nt-dot nt-dot-ok"></i>{{ __('ui.dashboard.done') }} <b id="statDone">{{ $stats['done'] }}</b></span>
        <span><i class="nt-dot nt-dot-warn"></i>{{ __('ui.dashboard.processing') }} <b id="statProcessing">{{ $stats['processing'] }}</b></span>
        <span><i class="nt-dot nt-dot-danger"></i>{{ __('ui.dashboard.failed') }} <b id="statFailed">{{ $stats['failed'] }}</b></span>
      </div>
      <div class="nt-stat-label">{{ __('design.dashboard.used_chars', ['count' => '']) }}<span id="kpiChars">{{ number_format($usage->total_chars) }}</span></div>
    </div>
  </div>

  <div class="nt-overview-grid">
    <section class="nt-card nt-recent" aria-labelledby="ntRecentTitle">
      <div class="nt-card-head">
        <h2 class="nt-h4" id="ntRecentTitle">{{ __('design.dashboard.recent') }}</h2>
        <a class="nt-link" href="#recentVoicesSection">{{ __('design.dashboard.see_all') }}</a>
      </div>
      @forelse($ntRecent as $job)
        @php
          $ntAudio = $job->audio_path ? \App\Support\AudioUrl::fromPath($job->audio_path) : null;
          $ntTitle = $job->textItem?->title ?: \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $job->input_text)), 48);
        @endphp
        <div class="nt-recent-row" @if($ntAudio) data-nt-track data-src="{{ $ntAudio }}" @endif>
          @if($ntAudio)
            <button type="button" class="nt-play nt-play-soft nt-play-sm" data-nt-play aria-label="{{ __('design.home.play') }}: {{ $ntTitle }}" data-label-play="{{ __('design.home.play') }}" data-label-pause="{{ __('design.home.pause') }}">
              <svg class="nt-icon-play nt-flip" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 4l13 8-13 8z"/></svg>
              <svg class="nt-icon-pause" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z"/></svg>
            </button>
          @else
            <span class="nt-play nt-play-soft nt-play-sm nt-play-off" aria-hidden="true"></span>
          @endif
          <span class="nt-recent-text">
            <span class="nt-recent-title" dir="auto">{{ $ntTitle }}</span>
            <span class="nt-recent-meta"><bdi>{{ $speakerDisplayLabel($job->voice_name) }}</bdi> · {{ $job->created_at?->diffForHumans() }}</span>
          </span>
          <span class="nt-mono nt-recent-len">{{ $job->duration_seconds ? $ntDuration($job->duration_seconds) : '' }}</span>
          <span class="nt-pill {{ $ntStatusPill[$job->status] ?? 'nt-pill-muted' }}">{{ $statusLabelByKey($job->status) }}</span>
          @if($job->hasReusablePremiumSettings())
            <a class="nt-recent-reuse" href="{{ route('dashboard', ['reuse' => $job->displayNumber()]) }}#generateVoiceSection">{{ __('ui.dashboard.reuse_settings') }}</a>
          @endif
        </div>
      @empty
        <p class="nt-small nt-recent-empty">{{ __('design.dashboard.empty') }}</p>
      @endforelse
    </section>

    <div class="nt-overview-side">
      <section class="nt-card nt-panel nt-quick" aria-labelledby="ntQuickTitle">
        <h2 class="nt-h4" id="ntQuickTitle">{{ __('design.dashboard.quick') }}</h2>
        <label class="nt-small" for="ntQuickText">{{ __('design.dashboard.quick_hint') }}</label>
        <textarea id="ntQuickText" rows="3" dir="auto" placeholder="{{ __('design.dashboard.quick_placeholder') }}"></textarea>
        <div class="nt-quick-foot">
          <button type="button" class="btn btn-primary" data-nt-quick-open>{{ __('design.dashboard.quick_btn') }}</button>
        </div>
      </section>
      <section class="nt-card nt-panel" aria-labelledby="ntWeekTitle">
        <div class="nt-card-head nt-card-head-flat">
          <h2 class="nt-h4" id="ntWeekTitle">{{ __('design.dashboard.week') }}</h2>
          <span class="nt-mono">{{ __('design.dashboard.week_total', ['count' => $ntWeekTotal]) }}</span>
        </div>
        <div class="nt-week" role="img" aria-label="{{ __('design.dashboard.week_aria') }}">
          @foreach($ntWeek as $day)
            <div class="nt-week-day">
              <span class="nt-week-count">{{ $day['count'] ?: '' }}</span>
              <div class="nt-week-bar {{ $day['today'] ? 'is-today' : '' }} {{ $day['count'] > 0 ? 'has-count' : '' }}" style="height: {{ $ntWeekMax > 0 ? max(6, (int) round($day['count'] / $ntWeekMax * 80)) : 6 }}px"></div>
              <span class="nt-week-label">{{ $day['label'] }}</span>
            </div>
          @endforeach
        </div>
      </section>
    </div>
  </div>
</div>
<script>
(function () {
  var open = document.querySelector('[data-nt-quick-open]');
  var quick = document.getElementById('ntQuickText');
  if (!open || !quick) return;
  open.addEventListener('click', function () {
    var target = document.getElementById('contentTextarea');
    if (target && quick.value.trim() !== '') {
      target.value = quick.value;
      target.dispatchEvent(new Event('input', { bubbles: true }));
    }
    window.location.hash = 'generateVoiceSection';
    setTimeout(function () { if (target) target.focus(); }, 60);
  });
})();
</script>
