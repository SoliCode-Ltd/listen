{{-- Where the agent can be reached: its link, a QR code, one line for a website, website builders. --}}
@props(['agent', 'widget', 'embed'])
@php
  $link = route('agent-talk', ['slug' => $widget->slug]);
  // Website builders: WordPress has a plugin; the others take the one line in their own settings, step by step.
  $platforms = [
    'wordpress' => ['WordPress', 'plugin'],
    'shopify' => ['Shopify', 'guide'],
    'wix' => ['Wix', 'guide'],
    'laravel' => ['Laravel', 'guide'],
    'salla' => ['Salla', 'soon'],
    'zid' => ['Zid', 'soon'],
  ];
  $seenHost = $widget->last_seen_origin ? parse_url($widget->last_seen_origin, PHP_URL_HOST) : null;
@endphp
<div class="ag-live-grid" data-ag-golive data-link="{{ $link }}">
  <section class="ag-tile">
    <h3><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1"/><path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/></svg>{{ __('agents.live_link_title') }}</h3>
    <p>{{ __('agents.live_link_text') }}</p>
    <div class="ag-copy">
      <input type="text" readonly value="{{ $link }}" aria-label="{{ __('agents.live_link_title') }}" dir="ltr" data-ag-link>
      <button type="button" class="ag-btn ag-btn-sm" data-ag-copy="{{ $link }}">{{ __('agents.live_copy') }}</button>
      <a class="ag-btn ag-btn-sm ag-btn-ghost" href="{{ $link }}" target="_blank" rel="noopener">{{ __('agents.live_open') }}</a>
    </div>
  </section>

  <section class="ag-tile">
    <h3><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3M21 14v.01M14 21h7v-4"/></svg>{{ __('agents.live_qr_title') }}</h3>
    <div class="ag-qr">
      <canvas width="264" height="264" data-ag-qr aria-label="{{ __('agents.live_qr_title') }}"></canvas>
      <div style="display:grid;gap:.6rem">
        <p>{{ __('agents.live_qr_text') }}</p>
        <button type="button" class="ag-btn ag-btn-sm" data-ag-qr-download data-name="{{ \Illuminate\Support\Str::slug($agent->business_name) ?: 'agent' }}-qr.png">{{ __('agents.live_qr_download') }}</button>
      </div>
    </div>
  </section>

  <section class="ag-tile ag-tile-wide">
    <h3><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 9h18M8 4v5"/></svg>{{ __('agents.live_platforms_title') }}</h3>
    <p>{{ __('agents.live_platforms_text') }}</p>
    <div class="ag-platforms">
      @foreach($platforms as $key => [$label, $kind])
        @if($kind === 'soon')
          <span class="ag-platform" aria-disabled="true" style="opacity:.62">
            <img src="{{ asset('images/platforms/'.$key.'.svg') }}" alt="" width="26" height="26">{{ $label }}<small>{{ __('agents.soon') }}</small>
          </span>
        @else
          <button type="button" class="ag-platform" data-ag-guide-open="ag-guide-{{ $key }}">
            <img src="{{ asset('images/platforms/'.$key.'.svg') }}" alt="" width="26" height="26">{{ $label }}<small>{{ __('agents.platform_kind.'.$kind) }}</small>
          </button>
        @endif
      @endforeach
    </div>
    <p class="ag-seen {{ $seenHost ? 'is-on' : '' }}" data-ag-seen>
      <i aria-hidden="true"></i>
      @if($seenHost)
        {{ __('agents.live_seen', ['site' => $seenHost, 'when' => $widget->last_seen_at->diffForHumans()]) }}
      @else
        {{ __('agents.live_not_seen') }}
      @endif
    </p>
  </section>

  <section class="ag-tile ag-tile-wide">
    <h3><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 9l-4 3 4 3M16 9l4 3-4 3M14 5l-4 14"/></svg>{{ __('agents.live_site_title') }}</h3>
    <p>{{ __('agents.live_site_text') }}</p>
    <div class="ag-copy">
      <code data-ag-embed dir="ltr">{{ $embed }}</code>
      <button type="button" class="ag-btn ag-btn-sm" data-ag-copy="{{ $embed }}">{{ __('agents.live_copy') }}</button>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem">
      <button type="button" class="ag-btn ag-btn-sm ag-btn-ghost" data-ag-share data-text="{{ __('agents.live_send_dev_text', ['code' => $embed]) }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7M16 6l-4-4-4 4M12 2v13"/></svg>{{ __('agents.live_send_dev') }}
      </button>
    </div>
    <div class="ag-field">
      <label for="ag-origins">{{ __('agents.live_allowed_label') }}</label>
      <input class="ag-input" id="ag-origins" type="text" dir="ltr" placeholder="https://yourbusiness.com" value="{{ implode(', ', $widget->allowed_origins ?? []) }}" data-ag-origins>
      <span class="ag-hint">{{ __('agents.live_allowed_hint') }}</span>
    </div>
  </section>

  {{-- The guides, one per website builder. --}}
  @foreach($platforms as $key => [$label, $kind])
    @continue($kind === 'soon')
    @php $code = \App\Http\Controllers\AgentController::embedCode($widget, $key); @endphp
    <dialog class="ag-dialog" id="ag-guide-{{ $key }}" aria-labelledby="ag-guide-{{ $key }}-title">
      <div class="ag-dialog-head">
        <img src="{{ asset('images/platforms/'.$key.'.svg') }}" alt="" width="34" height="34">
        <h3 id="ag-guide-{{ $key }}-title">{{ __('agents.guide_title', ['platform' => $label]) }}</h3>
        <button type="button" class="ag-btn ag-btn-sm ag-btn-ghost ag-dialog-x" data-ag-guide-close aria-label="{{ __('agents.widget_close') }}">✕</button>
      </div>
      <ol class="ag-steps">
        @foreach(__('agents.guides.'.$key.'.steps') as $step)
          {{-- `code` in a step shows as code, always left to right inside Arabic sentences. --}}
          <li>{!! preg_replace('/`([^`]+)`/', '<code dir="ltr">$1</code>', e($step)) !!}</li>
        @endforeach
      </ol>
      @if($kind === 'plugin')
        <a class="ag-btn ag-btn-primary" href="{{ route('voice-agents.plugin', ['agent' => $agent, 'platform' => $key]) }}" download>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>{{ __('agents.guide_download') }}
        </a>
      @else
        <div class="ag-copy">
          <code dir="ltr">{{ $code }}</code>
          <button type="button" class="ag-btn ag-btn-sm" data-ag-copy="{{ $code }}">{{ __('agents.live_copy') }}</button>
        </div>
      @endif
      <p class="ag-hint">{{ __('agents.guides.'.$key.'.done') }}</p>
    </dialog>
  @endforeach
</div>
