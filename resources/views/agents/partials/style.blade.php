{{-- The Design tab: the owner styles the call button and sees it, live, on a pretend copy of their
     website (with a pretend call that moves as a real one, with no sound). Every change is saved and
     reaches their website on its next page load: the code they pasted never changes. --}}
@props(['agent', 'widget'])
@php
  $look = $widget->style();
  $rtl = $agent->language !== 'en';
  // The corner as the owner sees it: on a right-to-left website the start is the right.
  $corner = ($look['position'] === 'start') === $rtl ? 'right' : 'left';
  $palettes = \App\Models\AgentWidget::PALETTES;
  $accents = \App\Models\AgentWidget::ACCENTS;
  $customAccent = in_array($look['accent'], $accents, true) ? null : $look['accent'];
  $orbCustom = $look['orb_colors'] ?? [$palettes['listen'][0], $palettes['listen'][2]];
  $iconSvg = [
    'phone' => '<path d="M5.1 3.5h3.1l1.6 4.1-2 1.3a11.3 11.3 0 0 0 5.3 5.3l1.3-2 4.1 1.6v3.1a2 2 0 0 1-2.2 2A16.6 16.6 0 0 1 3.1 5.7a2 2 0 0 1 2-2.2z"/>',
    'mic' => '<path d="M12 3a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V6a3 3 0 0 0-3-3z"/><path d="M19 11a7 7 0 0 1-14 0M12 18v3"/>',
    'headset' => '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="13" width="4.5" height="7" rx="2"/><rect x="16.5" y="13" width="4.5" height="7" rx="2"/>',
    'waves' => '<path d="M3.2 10.5v3M7.4 7.5v9M12 3.5v17M16.6 7.5v9M20.8 10.5v3"/>',
    'orb' => '<circle cx="12" cy="12" r="8"/><path d="M8.5 9.2a4.6 4.6 0 0 1 4-2.4"/>',
  ];
@endphp
<div class="ag-design" data-ag-design>
  <div class="ag-design-controls">
    <div class="ag-design-intro">
      <h2>{{ __('agents.design.title') }}</h2>
      <p class="ag-muted">{{ __('agents.design.text') }}</p>
    </div>

    {{-- What the call shows. --}}
    <fieldset class="ag-design-group">
      <legend>{{ __('agents.design.group_look') }}</legend>
      <div class="ag-style-cards">
        @foreach(\App\Models\AgentWidget::STYLES as $style)
          @php [$styleName, $styleText] = __('agents.design.styles.'.$style); @endphp
          <label class="ag-choice ag-style-card">
            <input type="radio" name="style" value="{{ $style }}" @checked($look['style'] === $style)>
            <span>
              <i class="ag-style-art is-{{ $style }}" aria-hidden="true">
                @if($style === 'orb')<b></b>@elseif($style === 'waves')<b></b><b></b><b></b><b></b><b></b>@else<em></em><b></b><u></u>@endif
              </i>
              <strong>{{ $styleName }}</strong>
              <small>{{ $styleText }}</small>
            </span>
          </label>
        @endforeach
      </div>
    </fieldset>

    {{-- The button. --}}
    <fieldset class="ag-design-group">
      <legend>{{ __('agents.design.group_button') }}</legend>
      <div class="ag-design-field">
        <span class="ag-design-label">{{ __('agents.design.icon') }}</span>
        <div class="ag-choice-row">
          @foreach(\App\Models\AgentWidget::ICONS as $icon)
            <label class="ag-choice ag-icon-choice" title="{{ __('agents.design.icons.'.$icon) }}">
              <input type="radio" name="icon" value="{{ $icon }}" @checked($look['icon'] === $icon) aria-label="{{ __('agents.design.icons.'.$icon) }}">
              <span><svg viewBox="0 0 24 24" aria-hidden="true">{!! $iconSvg[$icon] !!}</svg></span>
            </label>
          @endforeach
        </div>
      </div>
      <div class="ag-design-field">
        <label class="ag-design-label" for="ag-design-label">{{ __('agents.design.label') }}</label>
        <input class="ag-input" id="ag-design-label" name="label" type="text" maxlength="40" dir="auto" value="{{ $look['label'] }}" placeholder="{{ trans('agents.widget_label', [], $rtl ? 'ar' : 'en') }}">
      </div>
      <div class="ag-design-pair">
        <div class="ag-design-field">
          <span class="ag-design-label">{{ __('agents.design.shape') }}</span>
          <div class="ag-choice-row">
            @foreach(['pill', 'round'] as $shape)
              <label class="ag-choice"><input type="radio" name="shape" value="{{ $shape }}" @checked($look['shape'] === $shape)><span>{{ __('agents.design.shapes.'.$shape) }}</span></label>
            @endforeach
          </div>
        </div>
        <div class="ag-design-field">
          <span class="ag-design-label">{{ __('agents.design.size') }}</span>
          <div class="ag-choice-row">
            @foreach(['s', 'm', 'l'] as $size)
              <label class="ag-choice"><input type="radio" name="size" value="{{ $size }}" @checked($look['size'] === $size)><span>{{ __('agents.design.sizes.'.$size) }}</span></label>
            @endforeach
          </div>
        </div>
      </div>
    </fieldset>

    {{-- Colours. --}}
    <fieldset class="ag-design-group">
      <legend>{{ __('agents.design.group_colors') }}</legend>
      <div class="ag-design-field">
        <span class="ag-design-label">{{ __('agents.design.accent') }}</span>
        <div class="ag-swatches">
          @foreach($accents as $hex)
            <label class="ag-choice ag-swatch"><input type="radio" name="accent" value="{{ $hex }}" @checked($look['accent'] === $hex) aria-label="{{ $hex }}"><span style="background:{{ $hex }}"></span></label>
          @endforeach
          <label class="ag-choice ag-swatch ag-swatch-custom" title="{{ __('agents.design.custom') }}">
            <input type="radio" name="accent" value="custom" @checked($customAccent !== null) aria-label="{{ __('agents.design.custom') }}">
            <span style="background:{{ $customAccent ?? '#7659FA' }}"><input type="color" value="{{ $customAccent ?? '#7659FA' }}" data-ag-design-color="accent" aria-label="{{ __('agents.design.custom') }}"></span>
          </label>
        </div>
      </div>
      <div class="ag-design-field" data-ag-design-orb>
        <span class="ag-design-label">{{ __('agents.design.palette') }}</span>
        <div class="ag-swatches">
          @foreach($palettes as $key => $c)
            <label class="ag-choice ag-swatch is-orb" title="{{ __('agents.design.palettes.'.$key) }}">
              <input type="radio" name="palette" value="{{ $key }}" @checked($look['palette'] === $key) aria-label="{{ __('agents.design.palettes.'.$key) }}">
              <span style="background:radial-gradient(120% 120% at 32% 24%, {{ $c[2] }} 0%, {{ $c[1] }} 46%, {{ $c[0] }} 100%)"></span>
            </label>
          @endforeach
          <label class="ag-choice ag-swatch is-orb ag-swatch-two" title="{{ __('agents.design.palettes.custom') }}">
            <input type="radio" name="palette" value="custom" @checked($look['palette'] === 'custom') aria-label="{{ __('agents.design.palettes.custom') }}">
            <span>
              <input type="color" value="{{ $orbCustom[0] }}" data-ag-design-color="orb0" aria-label="{{ __('agents.design.palettes.custom') }} 1">
              <input type="color" value="{{ $orbCustom[1] }}" data-ag-design-color="orb1" aria-label="{{ __('agents.design.palettes.custom') }} 2">
            </span>
          </label>
        </div>
      </div>
    </fieldset>

    {{-- The call card. --}}
    <fieldset class="ag-design-group">
      <legend>{{ __('agents.design.group_card') }}</legend>
      <div class="ag-design-pair">
        <div class="ag-design-field">
          <label class="ag-design-label" for="ag-design-title">{{ __('agents.design.card_title') }}</label>
          <input class="ag-input" id="ag-design-title" name="title" type="text" maxlength="60" dir="auto" value="{{ $look['title'] }}" placeholder="{{ $agent->business_name }}">
        </div>
        <div class="ag-design-field">
          <label class="ag-design-label" for="ag-design-subtitle">{{ __('agents.design.card_subtitle') }}</label>
          <input class="ag-input" id="ag-design-subtitle" name="subtitle" type="text" maxlength="80" dir="auto" value="{{ $look['subtitle'] }}" placeholder="{{ trans('agents.widget_subtitle', [], $rtl ? 'ar' : 'en') }}">
        </div>
      </div>
      <label class="ag-design-check"><input type="checkbox" name="captions" value="1" @checked($look['captions'])><span>{{ __('agents.design.captions') }}</span></label>
      <div class="ag-design-pair">
        <div class="ag-design-field">
          <span class="ag-design-label">{{ __('agents.design.radius') }}</span>
          <div class="ag-choice-row">
            @foreach(['square', 'soft', 'round'] as $r)
              <label class="ag-choice"><input type="radio" name="radius" value="{{ $r }}" @checked($look['radius'] === $r)><span>{{ __('agents.design.radii.'.$r) }}</span></label>
            @endforeach
          </div>
        </div>
        <div class="ag-design-field">
          <span class="ag-design-label">{{ __('agents.design.font') }}</span>
          <div class="ag-choice-row">
            @foreach(['system', 'rounded', 'site'] as $f)
              <label class="ag-choice"><input type="radio" name="font" value="{{ $f }}" @checked($look['font'] === $f)><span>{{ __('agents.design.fonts.'.$f) }}</span></label>
            @endforeach
          </div>
        </div>
      </div>
    </fieldset>

    {{-- Where it sits. --}}
    <fieldset class="ag-design-group">
      <legend>{{ __('agents.design.group_place') }}</legend>
      <div class="ag-design-pair">
        <div class="ag-design-field">
          <span class="ag-design-label">{{ __('agents.design.position') }}</span>
          <div class="ag-choice-row">
            @foreach(['right', 'left'] as $c)
              <label class="ag-choice"><input type="radio" name="corner" value="{{ $c }}" @checked($corner === $c)><span>{{ __('agents.design.positions.'.$c) }}</span></label>
            @endforeach
          </div>
        </div>
        <div class="ag-design-field">
          <span class="ag-design-label">{{ __('agents.design.theme') }}</span>
          <div class="ag-choice-row">
            @foreach(['auto', 'light', 'dark'] as $t)
              <label class="ag-choice"><input type="radio" name="theme" value="{{ $t }}" @checked($look['theme'] === $t)><span>{{ __('agents.design.themes.'.$t) }}</span></label>
            @endforeach
          </div>
        </div>
      </div>
    </fieldset>
    <button type="button" class="ag-btn ag-btn-ghost ag-btn-sm" data-ag-design-reset>{{ __('agents.design.reset') }}</button>
  </div>

  {{-- The preview: a pretend copy of the owner's website with the real button on it. --}}
  <div class="ag-design-preview">
    <div class="ag-design-tools">
      <strong>{{ __('agents.design.preview') }}</strong>
      <span class="ag-design-status" data-ag-design-status aria-live="polite"></span>
      <div class="ag-choice-row ag-design-sitetheme">
        <label class="ag-choice"><input type="radio" name="site" value="light" checked><span>{{ __('agents.design.themes.light') }}</span></label>
        <label class="ag-choice"><input type="radio" name="site" value="dark"><span>{{ __('agents.design.themes.dark') }}</span></label>
      </div>
    </div>
    <div class="ag-design-frame">
      <div class="ag-design-bar" aria-hidden="true"><i></i><i></i><i></i><span dir="ltr">{{ __('agents.design.site') }}</span></div>
      <div class="ag-design-site" data-ag-design-site dir="{{ $rtl ? 'rtl' : 'ltr' }}" aria-label="{{ __('agents.design.preview') }}">
        <div class="ag-fake" aria-hidden="true">
          <b class="ag-fake-logo"></b>
          <b class="ag-fake-h"></b><b class="ag-fake-h is-short"></b>
          <b class="ag-fake-p"></b><b class="ag-fake-p"></b><b class="ag-fake-p is-short"></b>
          <b class="ag-fake-btn"></b>
          <div class="ag-fake-cards"><b></b><b></b><b></b></div>
        </div>
      </div>
    </div>
    <button type="button" class="ag-btn ag-btn-primary" data-ag-design-play data-play="{{ __('agents.design.play') }}" data-stop="{{ __('agents.design.stop') }}">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg><span>{{ __('agents.design.play') }}</span>
    </button>
  </div>
</div>
