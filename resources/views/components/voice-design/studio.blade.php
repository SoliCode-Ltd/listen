{{--
  Voice Design's studio (App\Support\VoiceDesign::studio()), in the dashboard's own section and
  as a tab of the demo. On one side the description and what shapes it; on the other the stage:
  an orb among characters before a design, then the takes to compare and the one to keep. A plan
  without Voice Design gets what it does and the way to a plan with it in the form's place, while
  the stage still plays the voices the admin chose to show. CSS: css/voice-design.css; behaviour:
  js/voice-design.js (which also needs components.orb-script on the page).
--}}
@props(['studio'])
@php
  $vd = $studio;
  $arabic = app()->getLocale() === 'ar';
  $examples = $vd['examples'];
  $showcase = $vd['showcase'];
  $canDesign = $vd['allowed'] || $vd['demo'];
  // The characters floating around the orb: the voices it plays first, then examples, eight in all.
  $cloud = array_slice(collect($showcase)->map(fn (array $v): array => ['name' => $v['name'], 'orb' => $v['orb']])
      ->concat(collect($examples)->map(fn (array $e): array => ['name' => $e['name'], 'orb' => $e['orb']]))
      ->unique('name')->values()->all(), 0, 8);
  $config = [
      'studio' => $vd,
      'csrf' => csrf_token(),
      'csrfUrl' => route('dashboard.csrf-token'),
      'palettes' => \App\Support\Orbs::PALETTES,
      'i18n' => [
          'take' => __('voice_design.take_label', ['n' => ':n']),
          'take_sample' => __('voice_design.take_sample'),
          'play' => __('voice_design.play'),
          'pause' => __('voice_design.pause'),
          'keep' => __('voice_design.keep'),
          'save' => __('voice_design.save'),
          'saving' => __('voice_design.saving'),
          'designing' => __('voice_design.designing'),
          'design' => __('voice_design.design_button'),
          'writing' => __('voice_design.writing'),
          'write_for_me' => __('voice_design.write_for_me'),
          'cost' => __('voice_design.cost', ['credits' => ':credits']),
          'takes_count' => [1 => trans_choice('voice_design.takes_count', 1), 2 => trans_choice('voice_design.takes_count', 2), 3 => trans_choice('voice_design.takes_count', 3)],
          'now_speaking' => __('voice_design.now_speaking', ['name' => ':name']),
          'kept' => __('voice_design.kept', ['name' => ':name']),
          'deleted' => __('voice_design.deleted'),
          'delete' => __('voice_design.delete'),
          'delete_confirm' => __('voice_design.delete_confirm'),
          'use_in_studio' => __('voice_design.use_in_studio'),
          'library_count' => __('voice_design.library_count', ['count' => ':count', 'max' => ':max']),
          'voice_meta' => __('voice_design.voice_meta', ['speaks' => ':speaks', 'date' => ':date']),
          'language_ar' => __('voice_design.language_ar'),
          'language_en' => __('voice_design.language_en'),
          'demo_cost_note' => __('voice_design.demo_cost_note', ['count' => ':count']),
          'network' => __('voice_design.errors.network'),
          'our_side' => __('voice_design.errors.our_side'),
          'line_needed' => __('voice_design.errors.line_needed'),
          'description_short' => __('validation.min.string', ['attribute' => mb_strtolower(__('voice_design.prompt_label')), 'min' => $vd['limits']['description_min']]),
          'line_short' => __('validation.min.string', ['attribute' => mb_strtolower(__('voice_design.line_label')), 'min' => $vd['limits']['line_min']]),
          'stop' => __('voice_design.stage_stop'),
          'listen' => __('voice_design.stage_listen'),
      ],
  ];
  $icon = [
      'spark' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.7 4.6 4.8 1.7-4.8 1.7L12 16.1l-1.7-4.6-4.8-1.7 4.8-1.7z"/><path d="M18.5 15.5l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/></svg>',
      'shuffle' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h3.5c2.6 0 4 1.4 5.2 3.6l1.6 2.8C15.5 15.6 17 17 19.5 17H21M17.5 4.5 21 7l-3.5 2.5M4 17h3.5c1.3 0 2.3-.4 3.1-1M17.5 14.5 21 17l-3.5 2.5M14 8.5c1-1 2.6-1.5 4-1.5"/></svg>',
      'play' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg>',
      'check' => '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="currentColor" opacity=".14"/><path d="M6 10.2 8.6 12.8 14.2 7.2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
      'sliders' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/></svg>',
      'shield' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l7 2.6v5.2c0 4.4-3 7.9-7 9.2-4-1.3-7-4.8-7-9.2V6.1z"/><path d="M9 12l2 2 4-4"/></svg>',
  ];
@endphp
<div class="vd" data-vd @class(['is-demo' => $vd['demo'], 'is-locked' => ! $canDesign])>
  <script type="application/json" data-vd-config>@json($config)</script>

  <div class="vd-grid">
    @if($canDesign)
      <form class="vd-card vd-form" data-vd-form novalidate>
        <div class="vd-alert" data-vd-alert role="alert" hidden></div>

        {{-- The description, the characters to start from and "Write it for me". --}}
        <div class="vd-field">
          <div class="vd-label-row">
            <label class="vd-label" for="vdDescription{{ $vd['demo'] ? 'Demo' : '' }}">{{ __('voice_design.prompt_label') }}</label>
            <details class="vd-tips">
              <summary>{{ __('voice_design.tips_link') }}</summary>
              <div class="vd-tips-pop">
                <strong>{{ __('voice_design.tips_title') }}</strong>
                <ul>
                  @foreach((array) __('voice_design.tips') as $tip)
                    <li>{{ $tip }}</li>
                  @endforeach
                </ul>
              </div>
            </details>
          </div>
          <div class="vd-prompt">
            <textarea id="vdDescription{{ $vd['demo'] ? 'Demo' : '' }}" name="description" dir="auto" rows="5" maxlength="{{ $vd['limits']['description_max'] }}" placeholder="{{ __('voice_design.prompt_placeholder') }}" data-vd-description required></textarea>
            <div class="vd-prompt-foot">
              <span class="vd-count" data-vd-description-count dir="ltr">0 / {{ number_format($vd['limits']['description_max']) }}</span>
              <button type="button" class="vd-ghost-btn" data-vd-enhance>{!! $icon['spark'] !!}<span>{{ __('voice_design.write_for_me') }}</span></button>
            </div>
            <div class="vd-chips" role="group" aria-label="{{ __('voice_design.examples_label') }}">
              <button type="button" class="vd-chip vd-chip-icon" data-vd-shuffle title="{{ __('voice_design.shuffle') }}" aria-label="{{ __('voice_design.shuffle') }}">{!! $icon['shuffle'] !!}</button>
              @foreach($examples as $example)
                @php $c = \App\Support\Orbs::colors($example['orb']); @endphp
                <button type="button" class="vd-chip" data-vd-example="{{ $example['key'] }}" style="--c0: {{ $c[0] }}; --c1: {{ $c[1] }}; --c2: {{ $c[2] }};">
                  <span class="vd-dot" aria-hidden="true"></span>{{ $example['name'] }}
                </button>
              @endforeach
            </div>
          </div>
        </div>

        {{-- Language and voice. --}}
        <div class="vd-row">
          <fieldset class="vd-field vd-seg-field">
            <legend class="vd-label">{{ __('voice_design.language_label') }}</legend>
            <div class="vd-seg">
              @foreach(['ar' => __('voice_design.language_ar'), 'en' => __('voice_design.language_en')] as $value => $label)
                <label><input type="radio" name="language" value="{{ $value }}" @checked($vd['language'] === $value)><span>{{ $label }}</span></label>
              @endforeach
            </div>
          </fieldset>
          <fieldset class="vd-field vd-seg-field">
            <legend class="vd-label">{{ __('voice_design.gender_label') }}</legend>
            <div class="vd-seg">
              @foreach(['' => __('voice_design.gender_any'), 'female' => __('voice_design.gender_female'), 'male' => __('voice_design.gender_male')] as $value => $label)
                <label><input type="radio" name="gender" value="{{ $value }}" @checked($value === '')><span>{{ $label }}</span></label>
              @endforeach
            </div>
          </fieldset>
        </div>

        {{-- The line every take speaks: written for the character, or the member's. --}}
        <div class="vd-field vd-line">
          <label class="vd-switch">
            <input type="checkbox" name="auto_line" value="1" checked data-vd-auto-line>
            <span class="vd-switch-track" aria-hidden="true"><span></span></span>
            <span class="vd-switch-text">
              <strong>{{ __('voice_design.line_toggle') }}</strong>
              <small>{{ __('voice_design.line_toggle_hint') }}</small>
            </span>
          </label>
          <div class="vd-line-box" data-vd-line-box hidden>
            <label class="vd-label" for="vdLine{{ $vd['demo'] ? 'Demo' : '' }}">{{ __('voice_design.line_label') }}</label>
            <textarea id="vdLine{{ $vd['demo'] ? 'Demo' : '' }}" name="line" dir="auto" rows="3" maxlength="{{ $vd['limits']['line_max'] }}" placeholder="{{ __('voice_design.line_placeholder') }}" data-vd-line></textarea>
            <span class="vd-count" data-vd-line-count dir="ltr">0 / {{ number_format($vd['limits']['line_max']) }}</span>
          </div>
        </div>

        {{-- Shape the voice: projection, pace, and how far the takes may wander from the words. --}}
        <details class="vd-shape">
          <summary>
            <span class="vd-shape-icon">{!! $icon['sliders'] !!}</span>
            <span><strong>{{ __('voice_design.settings') }}</strong><small>{{ __('voice_design.settings_hint') }}</small></span>
          </summary>
          <div class="vd-sliders">
            @foreach([
                'projection' => [__('voice_design.projection'), __('voice_design.projection_low'), __('voice_design.projection_high')],
                'pace' => [__('voice_design.pace'), __('voice_design.pace_low'), __('voice_design.pace_high')],
                'variety' => [__('voice_design.variety'), __('voice_design.variety_low'), __('voice_design.variety_high')],
            ] as $name => [$label, $low, $high])
              @continue($name === 'variety' && $vd['takes'] < 2)
              <div class="vd-slider">
                <label for="vd{{ ucfirst($name) }}{{ $vd['demo'] ? 'Demo' : '' }}">{{ $label }}</label>
                <input type="range" id="vd{{ ucfirst($name) }}{{ $vd['demo'] ? 'Demo' : '' }}" name="{{ $name }}" min="0" max="100" step="1" value="{{ \App\Support\VoiceDesign::SLIDER_MIDDLE }}">
                <div class="vd-slider-ends" aria-hidden="true"><span>{{ $low }}</span><span>{{ $high }}</span></div>
              </div>
            @endforeach
          </div>
        </details>

        <div class="vd-actions">
          <button type="submit" class="btn btn-primary vd-design-btn" data-vd-submit>
            <span data-vd-submit-label>{{ __('voice_design.design_button') }}</span>
            <span class="vd-cost" data-vd-cost @if($vd['demo']) hidden @endif><x-credit-icon /><b data-vd-cost-value>—</b></span>
          </button>
          <p class="vd-cost-hint">
            @if($vd['demo'])
              {{ __('voice_design.demo_one_take') }}
            @else
              {{ __('voice_design.cost_hint', ['takes' => trans_choice('voice_design.takes_count', $vd['takes'])]) }}
            @endif
          </p>
        </div>
        <p class="vd-safety">{!! $icon['shield'] !!}<span>{{ __('voice_design.safety_note') }}</span></p>
      </form>
    @else
      {{-- A plan without Voice Design: what it does, and the way to a plan with it. --}}
      <section class="vd-card vd-pitch" aria-labelledby="vdPitchTitle">
        <span class="vd-pitch-mark">{!! $icon['spark'] !!}</span>
        <h2 id="vdPitchTitle">{{ __('voice_design.upgrade_title') }}</h2>
        <p>{{ __('voice_design.upgrade_text') }}</p>
        <ul class="vd-pitch-points">
          @foreach((array) __('voice_design.upgrade_points') as $point)
            <li>{!! $icon['check'] !!}<span>{{ $point }}</span></li>
          @endforeach
        </ul>
        <div class="vd-pitch-examples" aria-hidden="true">
          @foreach(array_slice($examples, 0, 3) as $example)
            <figure><figcaption>{{ $example['name'] }}</figcaption><blockquote>{{ \Illuminate\Support\Str::limit($example['description'], 120) }}</blockquote></figure>
          @endforeach
        </div>
        <a class="btn btn-primary vd-pitch-cta" href="{{ $vd['upgrade_url'] }}">
          {{ $vd['upgrade_plan'] ? __('voice_design.upgrade_cta', ['plan' => $vd['upgrade_plan']]) : __('voice_design.upgrade_cta_any') }}
        </a>
      </section>
    @endif

    {{-- The stage. --}}
    <aside class="vd-card vd-stage" data-vd-stage data-vs-cast data-state="idle" aria-live="polite">
      <div class="vd-stage-idle" data-vd-idle>
        <div class="vd-stage-art">
          <ul class="vd-cloud" aria-hidden="true">
            @foreach($cloud as $i => $item)
              @php $c = \App\Support\Orbs::colors($item['orb']); @endphp
              <li class="vd-cloud-chip" style="--i: {{ $i }}; --c1: {{ $c[1] }};" data-vd-cloud="{{ $item['name'] }}">{{ $item['name'] }}</li>
            @endforeach
          </ul>
          <div class="vd-orb-wrap" data-orb-host>
            <x-orb name="listen" class="vd-orb is-live" />
            @if($showcase !== [])
              <button type="button" class="vd-orb-play" data-vd-showcase aria-label="{{ __('voice_design.stage_listen') }}">
                <span class="vd-play-icon">{!! $icon['play'] !!}</span>
                <span class="vd-pause-icon" aria-hidden="true"><i></i><i></i></span>
              </button>
            @endif
          </div>
        </div>
        <p class="vd-stage-hint" data-vd-now>{{ $showcase !== [] ? __('voice_design.stage_hint') : __('voice_design.stage_idle') }}</p>
      </div>

      <div class="vd-stage-work" data-vd-work hidden>
        <div class="vd-orb-wrap is-working"><x-orb name="dialogue" class="vd-orb is-live" /></div>
        <ol class="vd-steps">
          <li data-vd-step="0">{{ __('voice_design.step_reading') }}</li>
          <li data-vd-step="1">{{ __('voice_design.step_shaping') }}</li>
          <li data-vd-step="2">{{ __('voice_design.step_recording') }}</li>
        </ol>
        <div class="vd-progress" aria-hidden="true"><i data-vd-progress></i></div>
      </div>

      <div class="vd-results" data-vd-results hidden>
        <header class="vd-results-head">
          <h3>{{ $vd['takes'] > 1 ? __('voice_design.takes_title') : __('voice_design.demo_voice_label') }}</h3>
          @if($vd['takes'] > 1)<p>{{ __('voice_design.takes_subtitle') }}</p>@endif
          <blockquote class="vd-line-quote" dir="auto" data-vd-line-quote></blockquote>
        </header>
        <ol class="vd-takes" data-vd-takes></ol>

        <form class="vd-keep" data-vd-keep hidden novalidate>
          <label class="vd-label" for="vdName{{ $vd['demo'] ? 'Demo' : '' }}">{{ __('voice_design.name_label') }}</label>
          <div class="vd-keep-row">
            <input id="vdName{{ $vd['demo'] ? 'Demo' : '' }}" name="name" type="text" dir="auto" minlength="2" maxlength="50" placeholder="{{ __('voice_design.name_placeholder') }}" data-vd-name required>
            <button type="submit" class="btn btn-primary" data-vd-save>{{ __('voice_design.save') }}</button>
            <button type="button" class="btn btn-ghost" data-vd-keep-cancel>{{ __('voice_design.cancel') }}</button>
          </div>
        </form>

        @if($vd['demo'])
          <div class="vd-upsell" data-vd-upsell hidden>
            <strong>{{ __('voice_design.demo_keep_title') }}</strong>
            <p>{{ __('voice_design.demo_keep_text') }}</p>
            <a class="btn btn-primary" href="{{ $vd['upgrade_url'] }}">{{ __('voice_design.demo_keep_cta') }}</a>
          </div>
          <p class="vd-note" data-vd-demo-note></p>
        @else
          <p class="vd-note">{{ __('voice_design.discard_note') }}</p>
        @endif
        <button type="button" class="vd-ghost-btn vd-again" data-vd-again>{!! $icon['shuffle'] !!}<span>{{ __('voice_design.design_again') }}</span></button>
      </div>
    </aside>
  </div>

  @if(! $vd['demo'] && $vd['allowed'])
    {{-- The voices kept: each ready in the studio's voice list. --}}
    <section class="vd-library" aria-labelledby="vdLibraryTitle" data-vd-library>
      <header class="vd-library-head">
        <h2 id="vdLibraryTitle">{{ __('voice_design.library_title') }}</h2>
        <span class="vd-pill" data-vd-library-count>{{ __('voice_design.library_count', ['count' => count($vd['voices']), 'max' => $vd['max_voices']]) }}</span>
      </header>
      <p class="vd-library-empty" data-vd-library-empty @if($vd['voices'] !== []) hidden @endif>{{ __('voice_design.library_empty') }}</p>
      <div class="vd-library-grid" data-vd-library-grid></div>
    </section>
  @endif
</div>
