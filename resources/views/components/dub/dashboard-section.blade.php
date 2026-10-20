{{--
  The dashboard's own "Dub a video" section, apart from the text studio:
  this credit month's dubbing at a glance (videos against the plan's number,
  the voice time they used, what is left, the longest video), how a dub is
  counted, the dubbing studio, and every dub of the month with what it took.

  $dub: App\Support\VideoDubbing::studio(); $account: VideoDubbing::account().
--}}
@props(['dub', 'account'])
@php
  $dubTime = function (int $seconds): string {
      $h = intdiv($seconds, 3600);
      $m = intdiv($seconds % 3600, 60);
      $s = $seconds % 60;
      return match (true) {
          $h > 0 => __('ui.dub.js.hours_minutes', ['h' => $h, 'm' => $m]),
          $m > 0 && $s > 0 => __('ui.dub.js.minutes_seconds', ['m' => $m, 's' => $s]),
          $m > 0 => __('ui.dub.js.minutes', ['m' => $m]),
          default => __('ui.dub.js.seconds', ['s' => $seconds]),
      };
  };
  $perSecond = \App\Support\Credits::PER_SECOND;
  $videoShare = $account['videos_limit'] > 0 ? min(100, (int) round($account['videos_used'] / $account['videos_limit'] * 100)) : null;
@endphp
<section id="dubVideoSection" class="dashboard-panel" data-dashboard-section>
  <article class="page-card reveal dub-section">
    <h1><span class="grad-text">{{ __('ui.dub.section_title') }}</span></h1>
    <p class="dub-section-lead">{{ __('ui.dub.section_text') }}</p>

    @if($account['included'])
      <div class="dub-account">
        <div class="dub-stat">
          <div class="dub-stat-head">
            <span class="dub-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="6" width="13" height="12" rx="2.5"/><path d="M16 10.5l5-3v9l-5-3"/></svg></span>
            <span class="dub-stat-label">{{ __('ui.dub.stat_videos') }}</span>
          </div>
          <div class="dub-stat-value">{{ number_format($account['videos_used']) }}@if($account['videos_limit'] > 0)<small>/ {{ number_format($account['videos_limit']) }}</small>@endif</div>
          <div class="dub-stat-note">{{ $account['videos_limit'] > 0 ? __('ui.dub.stat_videos_of', ['limit' => number_format($account['videos_limit'])]) : __('ui.dub.stat_videos_open') }}</div>
          @if($videoShare !== null)<div class="dub-stat-bar" aria-hidden="true"><i style="width: {{ $videoShare }}%"></i></div>@endif
        </div>
        <div class="dub-stat">
          <div class="dub-stat-head">
            <span class="dub-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg></span>
            <span class="dub-stat-label">{{ __('ui.dub.stat_used') }}</span>
          </div>
          <div class="dub-stat-value">{{ $dubTime($account['seconds_used']) }}</div>
          <div class="dub-stat-note">{{ __('ui.dub.stat_used_note', ['credits' => number_format($account['credits_used'])]) }}</div>
        </div>
        <div class="dub-stat">
          <div class="dub-stat-head">
            <span class="dub-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 10v4M8 7v10M12 4v16M16 7v10M20 10v4"/></svg></span>
            <span class="dub-stat-label">{{ __('ui.dub.stat_left') }}</span>
          </div>
          <div class="dub-stat-value">{{ $dubTime($account['seconds_left']) }}</div>
          <div class="dub-stat-note">{{ __('ui.dub.stat_left_note', ['credits' => number_format($account['credits_left'])]) }}</div>
        </div>
        <div class="dub-stat">
          <div class="dub-stat-head">
            <span class="dub-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 12h16M4 12l3-3M4 12l3 3M20 12l-3-3M20 12l-3 3"/></svg></span>
            <span class="dub-stat-label">{{ __('ui.dub.stat_longest') }}</span>
          </div>
          <div class="dub-stat-value">{{ __('ui.dub.stat_longest_value', ['seconds' => $account['max_seconds']]) }}</div>
          <div class="dub-stat-note">{{ __('ui.dub.stat_longest_note') }}</div>
        </div>
      </div>
      <div class="dub-rule">
        <strong class="dub-rule-title">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
          {{ __('ui.dub.rule_title') }}
        </strong>
        <ul>
          <li><b>{{ __('ui.dub.rule_second') }}</b><span>{{ __('ui.dub.rule_second_value', ['credits' => $perSecond]) }}</span></li>
          <li><b>{{ __('ui.dub.rule_example') }}</b><span>{{ __('ui.dub.rule_example_value', ['credits' => number_format(30 * $perSecond)]) }}</span></li>
          <li><b>{{ __('ui.dub.rule_failed') }}</b><span>{{ __('ui.dub.rule_failed_value') }}</span></li>
        </ul>
      </div>
    @else
      <div class="dub-rule">
        <strong class="dub-rule-title">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/></svg>
          {{ __('ui.dub.upgrade_title') }}
        </strong>
        <p>{{ __('ui.dub.upgrade_text') }}</p>
      </div>
    @endif

    {{-- What a dub costs is above: the studio shows only what the chosen video will use. --}}
    <x-dub.studio :dub="$dub" :compact-meter="true" />

    @if($account['included'] || $account['rows'] !== [])
      <section class="dub-ledger" aria-labelledby="dubLedgerTitle">
        <h2 id="dubLedgerTitle">{{ __('ui.dub.ledger_title') }}</h2>
        <p>{{ __('ui.dub.ledger_text') }}</p>
        <div class="dub-ledger-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ __('ui.dub.ledger_date') }}</th>
                <th>{{ __('ui.dub.ledger_video') }}</th>
                <th>{{ __('ui.dub.ledger_dialect') }}</th>
                <th>{{ __('ui.dub.ledger_length') }}</th>
                <th>{{ __('ui.dub.ledger_credits') }}</th>
                <th>{{ __('ui.dub.ledger_status') }}</th>
                <th><span class="sr-only">{{ __('ui.dub.download') }}</span></th>
              </tr>
            </thead>
            <tbody>
              @forelse($account['rows'] as $row)
                <tr>
                  <td class="num">{{ $row['date'] }}</td>
                  <td>{{ $row['name'] !== '' ? \Illuminate\Support\Str::limit($row['name'], 40) : __('ui.dub.js.your_video') }}</td>
                  <td>{{ $row['dialect_label'] }}</td>
                  <td class="num" dir="ltr">{{ gmdate('i:s', (int) round($row['seconds'])) }}</td>
                  <td class="num">{{ $row['charged'] > 0 ? number_format($row['charged']) : '—' }}</td>
                  <td>
                    @if($row['status'] === 'failed')
                      <span class="dub-badge is-failed">{{ __('ui.dub.status_failed') }}</span>
                    @elseif($row['status'] === 'ready')
                      <span class="dub-badge {{ $row['free'] ? 'is-free' : 'is-ready' }}">{{ $row['free'] ? __('ui.dub.status_free') : __('ui.dub.status_ready') }}</span>
                    @else
                      <span class="dub-badge is-working">{{ __('ui.dub.status_working') }}</span>
                    @endif
                  </td>
                  <td>
                    @if($row['download_url'])
                      <a class="dub-link" href="{{ $row['download_url'] }}">{{ __('ui.dub.download') }}</a>
                    @elseif($row['status'] === 'ready')
                      <span class="dub-item-meta">{{ __('ui.dub.files_gone') }}</span>
                    @endif
                  </td>
                </tr>
              @empty
                <tr><td colspan="7" class="dub-ledger-empty">{{ __('ui.dub.ledger_empty') }}</td></tr>
              @endforelse
            </tbody>
            @if($account['credits_used'] > 0)
              <tfoot>
                <tr>
                  <td colspan="4">{{ __('ui.dub.ledger_total') }}</td>
                  <td class="num">{{ number_format($account['credits_used']) }}</td>
                  <td colspan="2">{{ $dubTime($account['seconds_used']) }}</td>
                </tr>
              </tfoot>
            @endif
          </table>
        </div>
      </section>
    @endif
  </article>
</section>
