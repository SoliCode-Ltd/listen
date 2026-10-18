{{-- Dashboard: the member's developer API at a glance (paid plans with the API). --}}
@php
  $d = fn (string $key, array $replace = []) => __('developers.dashboard.'.$key, $replace);
  $api = $developerApi;
  $isOk = $api['status'] === \App\Support\Api\ApiPlanAccess::OK;
@endphp
<style>
  .dapi-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .75rem; margin: 1.1rem 0; }
  .dapi-stat { padding: .9rem 1rem; border-radius: 14px; border: 1px solid var(--line); background: color-mix(in oklab, var(--text) 3%, transparent); }
  .dapi-stat small { display: block; color: var(--muted); font-size: .8rem; margin-bottom: .3rem; }
  .dapi-stat b { font-size: 1.35rem; font-weight: 650; color: var(--text); }
  .dapi-stat span { display: block; color: var(--muted); font-size: .76rem; margin-top: .2rem; }
  .dapi-keys { display: grid; gap: .5rem; margin: .5rem 0 1rem; }
  .dapi-key { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1rem; padding: .7rem .9rem; border-radius: 12px; border: 1px solid var(--line); }
  .dapi-key strong { color: var(--text); font-weight: 600; }
  .dapi-key code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: .82rem; color: var(--muted); direction: ltr; unicode-bidi: isolate; }
  .dapi-key small { color: var(--muted); }
  .dapi-limits { display: flex; flex-wrap: wrap; gap: .5rem; margin: 0 0 1rem; }
  .dapi-limits span { padding: .3rem .65rem; border-radius: var(--radius-full); border: 1px solid var(--line); font-size: .8rem; color: var(--muted); }
  @media (max-width: 900px) { .dapi-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
<section id="developerApiSection" class="dashboard-panel" data-dashboard-section>
  <article class="page-card reveal sidebar-info">
    <h1><span class="grad-text">{{ $d('title') }}</span></h1>
    <p>{{ $d('subtitle') }}</p>

    @if(! $isOk)
      <div class="note">{{ __('developers.keys.trial_body') }}</div>
    @else
      <div class="dapi-stats">
        <div class="dapi-stat"><small>{{ $d('active_keys') }}</small><b>{{ number_format($api['active_keys']->count()) }}</b></div>
        <div class="dapi-stat"><small>{{ $d('api_requests') }}</small><b>{{ number_format($api['api_requests']) }}</b></div>
        <div class="dapi-stat"><small>{{ $d('api_credits') }}</small><b>{{ number_format($api['api_credits']) }}</b><span>{{ $d('share', ['percent' => $api['api_share_percent']]) }}</span></div>
        @if($api['app_credits'] !== null)
          <div class="dapi-stat"><small>{{ $d('app_credits') }}</small><b>{{ number_format($api['app_credits']) }}</b></div>
        @endif
      </div>

      <div class="dapi-limits">
        <span>{{ $d('rpm') }}: {{ number_format($api['limits']['rpm']) }}</span>
        <span>{{ $d('concurrency') }}: {{ number_format($api['limits']['concurrency']) }}</span>
        <span>{{ $d('max_chars') }}: {{ number_format($api['limits']['max_chars']) }}</span>
      </div>

      <h3 style="margin: 0 0 .4rem; font-size: 1rem;">{{ $d('keys_title') }}</h3>
      @if($api['active_keys']->isEmpty())
        <p>{{ $d('no_keys') }}</p>
      @else
        <div class="dapi-keys">
          @foreach($api['active_keys']->take(5) as $apiKey)
            @php($keyCredits = (int) ($api['month_usage']->get($apiKey->id)?->credits ?? 0))
            <div class="dapi-key">
              <div>
                <strong>{{ $apiKey->name }}</strong>
                <code data-code>{{ $apiKey->maskedKey() }}</code>
              </div>
              <small>
                {{ $apiKey->monthly_credit_limit
                  ? __('developers.keys.credits_of_limit', ['used' => number_format($keyCredits), 'limit' => number_format($apiKey->monthly_credit_limit)])
                  : __('developers.keys.credits_used', ['used' => number_format($keyCredits)]) }}
                ·
                {{ $apiKey->last_used_at ? $d('last_used', ['time' => $apiKey->last_used_at->locale(app()->getLocale())->diffForHumans()]) : $d('never_used') }}
              </small>
            </div>
          @endforeach
        </div>
      @endif
      <p class="mini-message" style="color: var(--muted);">{{ __('developers.keys.usage_shared') }} {{ __('developers.keys.usage_hard_cap') }}</p>
    @endif

    <div class="sidebar-actions">
      <a class="btn btn-primary" href="{{ route('developers.keys') }}">{{ $api['active_keys']->isEmpty() && $isOk ? $d('create') : $d('manage') }}</a>
      <a class="btn" href="{{ route('developers') }}">{{ $d('docs') }}</a>
    </div>
  </article>
</section>
