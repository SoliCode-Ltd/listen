{{--
  Voice studio side panel, shared by the dashboard generator and the demo page:
  the voice library (every speaker with avatar, gender, accent, a preview and
  favourites) above the fine-tuning settings in the slot. Language, dialect,
  style and emotion sit on the text box (voice-studio.setbar), and the tags
  in the toolbar above the text. Behaviour in js/voice-studio.js.
--}}
@props(['showTags' => true, 'tagsLock' => null, 'prefix' => '', 'pronunciations' => null])
@php
  $vsAr = app()->getLocale() === 'ar';
  // Locked tags still show (dimmed, with an upgrade button) unless no plan on sale has them.
  $showTags = $showTags && ! ($tagsLock['none'] ?? false);
  $tagsLock = $showTags ? $tagsLock : null;
  $vsTagMenus = [];
  if ($showTags) {
      foreach (\App\Support\GeminiVocalTags::GROUPS as $vsGroup => $vsTags) {
          foreach ($vsTags as $vsTag) {
              $vsTagMenus[] = ['k' => 'sounds', 't' => $vsTag, 'l' => \App\Support\GeminiVocalTags::label($vsTag), 'g' => $vsGroup,
                  'gl' => __('ui.dashboard.vocal_tag_groups.'.$vsGroup), 'o' => \App\Support\GeminiVocalTags::ARABIC_NAMES[$vsTag] ?? ''];
          }
      }
      foreach (\App\Support\GeminiVocalTags::panelDeliveryGroups() as $vsGroup => $vsTags) {
          foreach ($vsTags as $vsTag) {
              $vsTagMenus[] = ['k' => 'delivery', 't' => $vsTag, 'l' => \App\Support\GeminiVocalTags::deliveryLabel($vsTag), 'g' => $vsGroup,
                  'gl' => __('ui.dashboard.vocal_delivery_groups.'.$vsGroup), 'o' => \App\Support\GeminiVocalTags::DELIVERY_ARABIC[$vsTag] ?? ''];
          }
      }
  }
  $vsMeta = \App\Support\StudioChoices::forPicker($vsAr) + [
      'voices' => \App\Support\VoiceCatalog::libraryEntries($vsAr),
      'favorites' => \App\Http\Controllers\FavoriteVoicesController::forUser(auth()->user()),
      'favoritesUrl' => auth()->check() ? route('dashboard.favorite-voices') : null,
      'tagMenus' => $vsTagMenus,
      // The tags need the premium voices: without them the toolbar shows, locked.
      'tagsLock' => $tagsLock,
      // The member's pronunciations (dashboard only): ['items' => [...], 'url' => ..., 'limit' => ...].
      'pron' => $pronunciations ? $pronunciations + ['i18n' => __('ui.dashboard.pron')] : null,
      'i18n' => __('ui.dashboard.picker') + __('ui.dashboard.library') + [
          'upgrade_to_plan' => __('ui.dashboard.upgrade_to_plan'),
          'upgrade_to_unlock' => __('ui.dashboard.upgrade_to_unlock'),
      ],
  ];
@endphp
<section class="vs-panel" data-vs-panel aria-label="{{ __('ui.dashboard.voice_studio') }}">
  <header class="vs-panel-head">
    <span class="vs-panel-icon" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 10v4M7.5 7v10M12 4v16M16.5 7v10M21 10v4"/></svg></span>
    <div>
      <strong>{{ __('ui.dashboard.library.title') }}</strong>
      <small>{{ __('ui.dashboard.library.hint') }}</small>
    </div>
  </header>

  <div class="vs-lib" data-vs-library>
    <select name="voice_key" id="{{ $prefix }}voice_key" class="vs-hidden-field" tabindex="-1" aria-hidden="true" data-speaker-avatar></select>
    <div class="vs-lib-tabs" role="tablist" aria-label="{{ __('ui.dashboard.library.title') }}">
      <button type="button" class="vs-lib-tab" role="tab" data-vs-lib-tab="all" aria-selected="true">{{ __('ui.dashboard.library.all') }} <small data-vs-lib-count="all"></small></button>
      <button type="button" class="vs-lib-tab" role="tab" data-vs-lib-tab="favorites" aria-selected="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5s-7.5-4.4-7.5-10A4.3 4.3 0 0 1 12 7.8a4.3 4.3 0 0 1 7.5 2.7c0 5.6-7.5 10-7.5 10z"/></svg>{{ __('ui.dashboard.library.favorites') }} <small data-vs-lib-count="favorites"></small></button>
      <button type="button" class="vs-lib-tab" role="tab" data-vs-lib-tab="mine" aria-selected="false" hidden>{{ __('ui.dashboard.library.mine') }} <small data-vs-lib-count="mine"></small></button>
    </div>
    <div class="vs-lib-tools">
      <label class="vs-lib-search">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
        <input type="search" autocomplete="off" placeholder="{{ __('ui.dashboard.library.search') }}" aria-label="{{ __('ui.dashboard.library.search') }}" data-vs-lib-search>
      </label>
      <div class="vs-lib-gender" role="group" aria-label="{{ __('ui.dashboard.library.gender') }}">
        <button type="button" data-vs-lib-gender="" aria-pressed="true">{{ __('ui.dashboard.library.any') }}</button>
        <button type="button" data-vs-lib-gender="female" aria-pressed="false">{{ __('ui.dashboard.library.female') }}</button>
        <button type="button" data-vs-lib-gender="male" aria-pressed="false">{{ __('ui.dashboard.library.male') }}</button>
      </div>
    </div>
    {{-- Age, with the gender: an age shows only when the language has speakers of that age. --}}
    <div class="vs-lib-age" role="group" aria-label="{{ __('ui.dashboard.library.age') }}">
      @foreach (['any', ...\App\Support\SpeakerVoices::AGES] as $vsAge)
        <button type="button" data-vs-lib-age="{{ $vsAge === 'any' ? '' : $vsAge }}" aria-pressed="{{ $vsAge === 'any' ? 'true' : 'false' }}" aria-label="{{ __('ui.dashboard.library.age_'.$vsAge) }}" @if($vsAge !== 'any') hidden @endif><span class="vs-age-long">{{ __('ui.dashboard.library.age_'.$vsAge) }}</span><span class="vs-age-short" aria-hidden="true">{{ __('ui.dashboard.library.age_short.'.$vsAge) }}</span></button>
      @endforeach
    </div>
    <div class="vs-lib-grid" data-vs-lib-grid role="listbox" aria-label="{{ __('ui.dashboard.speaker') }}"></div>
    <p class="vs-lib-empty" data-vs-lib-empty hidden></p>
  </div>

  {{-- Speed, pitch, sound and the rest: how the chosen voice delivers the text. None in the dubbing studio:
       an empty pane drew a bordered empty strip under the voices. --}}
  @if(trim((string) $slot) !== '')
    <div class="vs-pane">
      <h3 class="vs-pane-title">{{ __('ui.dashboard.delivery_title') }}</h3>
      {{ $slot }}
    </div>
  @endif

  @if($showTags && ! $tagsLock)
    <details class="vs-guide">
      <summary>{{ __('ui.dashboard.vocal_tags_tips') }}</summary>
      <ul>
        @foreach(__('ui.dashboard.vocal_tags_tip_items') as $tip)
          <li>{{ $tip }}</li>
        @endforeach
      </ul>
    </details>
  @endif
  {{-- Icons, categories, voices and tag menus for the pickers, the library and the toolbar. --}}
  <script type="application/json" data-vs-meta>@json($vsMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)</script>
</section>
