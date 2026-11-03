{{-- Where the agent can be reached: its link, a QR code, one line for a website, website builders. --}}
@props(['agent', 'widget', 'embed'])
@php
  $link = route('agent-talk', ['slug' => $widget->slug]);
  // Website builders: WordPress has a plugin; the others take the one line in their own settings, step by step;
  // any other website takes the line itself.
  $platforms = [
    'wordpress' => ['WordPress', 'plugin'],
    'shopify' => ['Shopify', 'guide'],
    'wix' => ['Wix', 'guide'],
    'laravel' => ['Laravel', 'dev'],
    'html' => [__('agents.install.tabs.html'), 'code'],
    'salla' => ['Salla', 'soon'],
    'zid' => ['Zid', 'soon'],
  ];
  $seenHost = $widget->last_seen_origin && $widget->last_seen_at ? parse_url($widget->last_seen_origin, PHP_URL_HOST) : null;
@endphp
<div class="ag-live" data-ag-golive data-link="{{ $link }}">
  {{-- Where the button is now, and a check after adding it. --}}
  <div class="ag-card ag-live-status">
    <p class="ag-seen {{ $seenHost ? 'is-on' : '' }}" data-ag-seen>
      <i aria-hidden="true"></i>
      @if($seenHost)
        {{ __('agents.live_seen', ['site' => $seenHost, 'when' => $widget->seenAgo()]) }}
      @else
        {{ __('agents.live_not_seen') }}
      @endif
    </p>
    <button type="button" class="ag-btn ag-btn-sm" data-ag-install-check data-checking="{{ __('agents.install.checking') }}" data-label="{{ __('agents.install.check') }}">{{ __('agents.install.check') }}</button>
  </div>

  <section class="ag-live-section">
    <div class="ag-live-head">
      <h3>{{ __('agents.live_add_title') }}</h3>
      <p>{{ __('agents.live_add_text') }}</p>
    </div>
    <div class="ag-platforms">
      @foreach($platforms as $key => [$label, $kind])
        @if($kind === 'soon')
          <span class="ag-platform is-soon" aria-disabled="true">
            <img src="{{ asset('images/platforms/'.$key.'.svg') }}" alt="" width="28" height="28"><span class="ag-platform-name"><b>{{ $label }}</b><small>{{ __('agents.soon') }}</small></span>
          </span>
        @else
          <button type="button" class="ag-platform" data-ag-install-open="{{ $key }}">
            @if($key === 'html')
              <span class="ag-install-codeicon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 9l-4 3 4 3M16 9l4 3-4 3M14 5l-4 14"/></svg></span>
            @else
              <img src="{{ asset('images/platforms/'.$key.'.svg') }}" alt="" width="28" height="28">
            @endif
            <span class="ag-platform-name"><b>{{ $label }}</b><small>{{ __('agents.platform_kind.'.$kind) }}</small></span>
            <svg class="ag-platform-go" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
          </button>
        @endif
      @endforeach
    </div>
  </section>

  <section class="ag-live-section">
    <div class="ag-live-head">
      <h3>{{ __('agents.live_share_title') }}</h3>
      <p>{{ __('agents.live_share_text') }}</p>
    </div>
    <div class="ag-live-grid">
      <div class="ag-card ag-tile">
        <h4><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1"/><path d="M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/></svg>{{ __('agents.live_link_title') }}</h4>
        <p>{{ __('agents.live_link_text') }}</p>
        <div class="ag-copy">
          <input type="text" readonly value="{{ $link }}" aria-label="{{ __('agents.live_link_title') }}" dir="ltr" data-ag-link>
          <button type="button" class="ag-btn ag-btn-sm" data-ag-copy="{{ $link }}">{{ __('agents.live_copy') }}</button>
          <a class="ag-btn ag-btn-sm ag-btn-ghost" href="{{ $link }}" target="_blank" rel="noopener">{{ __('agents.live_open') }}</a>
        </div>
      </div>
      <div class="ag-card ag-tile">
        <h4><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3M21 14v.01M14 21h7v-4"/></svg>{{ __('agents.live_qr_title') }}</h4>
        <div class="ag-qr">
          <canvas width="264" height="264" data-ag-qr aria-label="{{ __('agents.live_qr_title') }}"></canvas>
          <div class="ag-qr-text">
            <p>{{ __('agents.live_qr_text') }}</p>
            <button type="button" class="ag-btn ag-btn-sm" data-ag-qr-download data-name="{{ \Illuminate\Support\Str::slug($agent->business_name) ?: 'agent' }}-qr.png">{{ __('agents.live_qr_download') }}</button>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section class="ag-live-section">
    <div class="ag-live-head">
      <h3>{{ __('agents.live_security_title') }}</h3>
    </div>
    <div class="ag-card ag-tile">
      <div class="ag-field">
        <label for="ag-origins">{{ __('agents.live_allowed_label') }}</label>
        <input class="ag-input" id="ag-origins" type="text" dir="ltr" placeholder="https://yourbusiness.com" value="{{ implode(', ', $widget->allowed_origins ?? []) }}" data-ag-origins>
        <span class="ag-hint">{{ __('agents.live_allowed_hint') }}</span>
      </div>
    </div>
  </section>

  {{-- The install panel: every website builder step by step, the line to paste, and a live check that
       the button is working on the owner's website. --}}
  @php
    $installTabs = ['wordpress' => 'plugin', 'shopify' => 'easy', 'wix' => 'easy', 'laravel' => 'dev', 'html' => 'easy'];
    $pasteAfter = ['shopify' => 1, 'wix' => 1, 'laravel' => 1, 'html' => 0];
  @endphp
  <dialog class="ag-install" id="ag-install" aria-labelledby="ag-install-title" data-ag-install data-ui="ar" data-status-url="{{ route('voice-agents.install-status', $agent) }}">
    <div class="ag-install-head">
      <div>
        <h3 id="ag-install-title">{{ __('agents.install.title') }}</h3>
        <p>{{ __('agents.install.subtitle') }}</p>
      </div>
      <button type="button" class="ag-install-x" data-ag-install-close aria-label="{{ __('agents.install.close') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
    </div>
    <div class="ag-install-tabs" role="tablist" aria-label="{{ __('agents.install.title') }}">
      @foreach($installTabs as $key => $kind)
        <button type="button" role="tab" class="ag-install-tab" id="ag-install-tab-{{ $key }}" aria-controls="ag-install-panel-{{ $key }}" aria-selected="false" tabindex="-1" data-ag-install-tab="{{ $key }}">
          @if($key === 'html')
            <span class="ag-install-codeicon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 9l-4 3 4 3M16 9l4 3-4 3M14 5l-4 14"/></svg></span>
          @else
            <img src="{{ asset('images/platforms/'.$key.'.svg') }}" alt="" width="22" height="22">
          @endif
          <span>{{ __('agents.install.tabs.'.$key) }}</span>
        </button>
      @endforeach
    </div>
    <div class="ag-install-body">
      @foreach($installTabs as $key => $kind)
        @php $code = \App\Http\Controllers\AgentController::embedCode($widget, $key === 'html' ? null : $key); @endphp
        <section class="ag-install-panel" role="tabpanel" id="ag-install-panel-{{ $key }}" aria-labelledby="ag-install-tab-{{ $key }}" data-ag-install-panel="{{ $key }}" hidden>
          <div class="ag-install-meta">
            <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>{{ __('agents.install.level.'.($kind === 'dev' ? 'dev' : 'easy')) }}</span>
            <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>{{ __('agents.install.time') }}</span>
          </div>
          @php
            // In Arabic, a platform whose dashboard can be in Arabic gets its menus' Arabic names too,
            // with a switch for owners whose dashboard is in English.
            $arUi = app()->getLocale() === 'ar' && \Illuminate\Support\Facades\Lang::has('agents.install.steps_ar_ui.'.$key) ? __('agents.install.steps_ar_ui.'.$key) : null;
          @endphp
          @if($arUi)
            <div class="ag-ui-switch" role="radiogroup" aria-label="{{ __('agents.install.ui_label') }}">
              <span>{{ __('agents.install.ui_label') }}</span>
              <div class="ag-seg">
                @foreach(['ar', 'en'] as $ui)
                  <label><input type="radio" name="ag-ui-{{ $key }}" value="{{ $ui }}" data-ag-ui @checked($ui === 'ar')><span>{{ __('agents.install.ui.'.$ui) }}</span></label>
                @endforeach
              </div>
            </div>
          @endif
          @if(app()->getLocale() === 'ar' && \Illuminate\Support\Facades\Lang::has('agents.install.ui_only_en.'.$key))
            <p class="ag-ui-note"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3.5 9h17M3.5 15h17M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>{{ __('agents.install.ui_only_en.'.$key) }}</p>
          @endif
          @if(\Illuminate\Support\Facades\Lang::has('agents.install.before.'.$key))
            <p class="ag-install-before"><i aria-hidden="true"></i><span>{{ __('agents.install.before.'.$key) }}</span></p>
          @endif
          <ol class="ag-stepper">
            @foreach(__('agents.install.steps.'.$key) as $i => [$title, $text])
              <li>
                @if($arUi && isset($arUi[$i]))
                  <h4><span data-ui-text="ar">{!! \App\Support\Agents\StepText::html($arUi[$i][0]) !!}</span><span data-ui-text="en">{!! \App\Support\Agents\StepText::html($title) !!}</span></h4>
                  <p><span data-ui-text="ar">{!! \App\Support\Agents\StepText::html($arUi[$i][1]) !!}</span><span data-ui-text="en">{!! \App\Support\Agents\StepText::html($text) !!}</span></p>
                @else
                  <h4>{!! \App\Support\Agents\StepText::html($title) !!}</h4>
                  <p>{!! \App\Support\Agents\StepText::html($text) !!}</p>
                @endif
                @if($kind === 'plugin' && $i === 0)
                  <div class="ag-install-dl">
                    <a class="ag-btn ag-btn-primary" href="{{ route('voice-agents.plugin', ['agent' => $agent, 'platform' => $key]) }}" download>
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>{{ __('agents.install.download') }}
                    </a>
                    <small>{!! str_replace(\App\Services\Agents\WordPressPlugin::SLUG.'.zip', '<bdi dir="ltr">'.\App\Services\Agents\WordPressPlugin::SLUG.'.zip</bdi>', e(__('agents.install.download_note'))) !!}</small>
                  </div>
                @endif
                @if(($pasteAfter[$key] ?? -1) === $i)
                  <div class="ag-guide-code">
                    <div class="ag-guide-code-bar"><span>{{ __('agents.install.code') }}</span><button type="button" class="ag-btn ag-btn-sm" data-ag-copy="{{ $code }}">{{ __('agents.live_copy') }}</button></div>
                    <pre dir="ltr"><code>{{ $code }}</code></pre>
                  </div>
                @endif
              </li>
            @endforeach
          </ol>
          <p class="ag-guide-done"><i aria-hidden="true"></i><span>{{ __('agents.install.done.'.$key) }}</span></p>
        </section>
      @endforeach
    </div>
    <div class="ag-install-foot">
      <p class="ag-install-status {{ $seenHost ? 'is-on' : '' }}" data-ag-install-status aria-live="polite">
        <i aria-hidden="true"></i>
        <span>{{ $seenHost ? __('agents.install.seen', ['site' => $seenHost, 'when' => $widget->seenAgo()]) : __('agents.install.not_seen') }}</span>
      </p>
      <div class="ag-install-actions">
        <button type="button" class="ag-btn ag-btn-sm" data-ag-share data-text="{{ __('agents.install.send_text', ['code' => $embed]) }}">{{ __('agents.install.send') }}</button>
        <button type="button" class="ag-btn ag-btn-sm ag-btn-primary" data-ag-install-check data-checking="{{ __('agents.install.checking') }}" data-label="{{ __('agents.install.check') }}">{{ __('agents.install.check') }}</button>
      </div>
    </div>
  </dialog>
</div>
