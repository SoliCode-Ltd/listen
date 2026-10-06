{{--
  Voice studio side panel: language settings, sound effects and delivery.
  Used by the dashboard generator and the demo page; behaviour in js/voice-studio.js.
  The slot is the language tab; the optional wholeScript slot (style, emotion,
  speed, pitch) opens the delivery tab. Each tag tab picks a category, then its tags.
--}}
@props(['showTags' => true])
@php
  $vsTagTabs = [
    'sounds' => ['groups' => \App\Support\GeminiVocalTags::GROUPS, 'groupKey' => 'vocal_tag_groups'],
    'delivery' => ['groups' => \App\Support\GeminiVocalTags::panelDeliveryGroups(), 'groupKey' => 'vocal_delivery_groups'],
  ];
@endphp
<section class="vs-panel" data-vs-panel aria-label="{{ __('ui.dashboard.voice_studio') }}">
  <header class="vs-panel-head">
    <span class="vs-panel-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 10v4M7.5 7v10M12 4v16M16.5 7v10M21 10v4"/></svg></span>
    <div>
      <strong>{{ __('ui.dashboard.voice_studio') }}</strong>
      <small>{{ __('ui.dashboard.vocal_tags_hint') }}</small>
    </div>
  </header>
  <div class="vs-tabs" role="tablist">
    <button type="button" class="vs-tab" role="tab" data-vs-tab="settings" aria-selected="true"><span class="vs-kind vs-kind-settings" aria-hidden="true"></span>{{ __('ui.dashboard.vocal_tab_settings') }}</button>
    @if($showTags)
      @foreach($vsTagTabs as $tabKey => $tab)
        <button type="button" class="vs-tab" role="tab" data-vs-tab="{{ $tabKey }}" aria-selected="false"><span class="vs-kind vs-kind-{{ $tabKey }}" aria-hidden="true"></span>{{ __('ui.dashboard.vocal_tab_'.$tabKey) }}</button>
      @endforeach
    @endif
  </div>
  @if($showTags)
    <label class="vs-search" data-vs-search hidden>
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      <input type="search" placeholder="{{ __('ui.dashboard.vocal_search') }}" autocomplete="off" aria-label="{{ __('ui.dashboard.vocal_search') }}">
    </label>
  @endif

  <div class="vs-pane" role="tabpanel" data-vs-pane="settings">
    {{ $slot }}
  </div>

  @if($showTags)
    @foreach($vsTagTabs as $tabKey => $tab)
      <div class="vs-pane" role="tabpanel" data-vs-pane="{{ $tabKey }}" hidden>
        <div class="vs-results-title" data-vs-results-title hidden><span class="vs-kind vs-kind-{{ $tabKey }}" aria-hidden="true"></span> {{ __('ui.dashboard.vocal_tab_'.$tabKey) }}</div>
        @if($tabKey === 'delivery' && isset($wholeScript))
          <div class="vs-whole" data-vs-whole>
            <div class="vs-section-title">{{ __('ui.dashboard.vocal_whole_script') }}</div>
            <p class="vs-pane-hint">{{ __('ui.dashboard.vocal_whole_script_hint') }}</p>
            {{ $wholeScript }}
            <div class="vs-divider"></div>
            <div class="vs-section-title">{{ __('ui.dashboard.vocal_inline_tags') }}</div>
          </div>
        @endif
        <p class="vs-pane-hint" data-vs-tag-hint><span class="vs-kind vs-kind-{{ $tabKey }}" aria-hidden="true"></span>{{ __('ui.dashboard.vocal_'.$tabKey.'_hint') }}</p>
        <div class="vs-field vs-category" data-vs-category-wrap>
          <label class="vs-sub-label" for="vsCategory-{{ $tabKey }}">{{ __('ui.dashboard.vocal_category') }}</label>
          <select id="vsCategory-{{ $tabKey }}" data-vs-category>
            @foreach($tab['groups'] as $group => $groupTags)
              <option value="{{ $group }}">{{ __('ui.dashboard.'.$tab['groupKey'].'.'.$group) }} ({{ count($groupTags) }})</option>
            @endforeach
          </select>
        </div>
        <div class="vs-groups">
          @foreach($tab['groups'] as $group => $groupTags)
            <div class="vs-group" data-vs-group="{{ $group }}">
              <div class="vs-group-label">{{ __('ui.dashboard.'.$tab['groupKey'].'.'.$group) }}</div>
              <div class="vs-chips">
                @foreach($groupTags as $vocalTag)
                  @php
                    $tagLabel = $tabKey === 'sounds' ? \App\Support\GeminiVocalTags::label($vocalTag) : \App\Support\GeminiVocalTags::deliveryLabel($vocalTag);
                    $tagOther = $tabKey === 'sounds' ? (\App\Support\GeminiVocalTags::ARABIC_NAMES[$vocalTag] ?? '') : (\App\Support\GeminiVocalTags::DELIVERY_ARABIC[$vocalTag] ?? '');
                  @endphp
                  <button type="button" class="vs-chip vs-chip-{{ $tabKey }}" data-vocal-tag="{{ $tagLabel }}" data-tag-kind="{{ $tabKey }}" data-search="{{ $tagLabel }} {{ $vocalTag }} {{ $tagOther }}" title="{{ $tabKey === 'sounds' ? '<'.$tagLabel.'>' : '['.$tagLabel.']' }}">{{ $tagLabel }}</button>
                @endforeach
              </div>
            </div>
          @endforeach
        </div>
        <p class="vs-empty" hidden>{{ __('ui.dashboard.vocal_no_results') }}</p>
      </div>
    @endforeach
    <details class="vs-guide">
      <summary>{{ __('ui.dashboard.vocal_tags_tips') }}</summary>
      <ul>
        @foreach(__('ui.dashboard.vocal_tags_tip_items') as $tip)
          <li>{{ $tip }}</li>
        @endforeach
      </ul>
    </details>
  @endif
</section>
