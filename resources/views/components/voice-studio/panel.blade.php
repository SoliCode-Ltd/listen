{{--
  Voice studio side panel: voice settings, sound effects and delivery tags.
  Used by the dashboard generator and the demo page; behaviour in js/voice-studio.js.
  The slot is the voice tab (each page passes its own fields).
--}}
@props(['showTags' => true])
@php
  $vsTagTabs = [
    'sounds' => ['groups' => \App\Support\GeminiVocalTags::GROUPS, 'groupKey' => 'vocal_tag_groups'],
    'delivery' => ['groups' => \App\Support\GeminiVocalTags::DELIVERY_GROUPS, 'groupKey' => 'vocal_delivery_groups'],
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
        <p class="vs-pane-hint"><span class="vs-kind vs-kind-{{ $tabKey }}" aria-hidden="true"></span>{{ __('ui.dashboard.vocal_'.$tabKey.'_hint') }}</p>
        <div class="vs-groups">
          @foreach($tab['groups'] as $group => $groupTags)
            <div class="vs-group" data-vs-group>
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
