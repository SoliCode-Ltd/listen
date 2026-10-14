{{--
  Dialect: a card showing the chosen dialect; it opens the dialect picker
  (search, regions, then each country's dialects). The select stays the form
  field; the page fills it per language and js/voice-studio.js draws the rest.
--}}
<div class="vs-field" data-vs-dialect-field>
  <div class="vs-field-label">
    <span id="dialectPickLabel">{{ __('ui.dashboard.dialect') }}</span>
  </div>
  <select name="dialect" id="dialect" class="vs-hidden-field" tabindex="-1" aria-hidden="true">
    <option value="">{{ __('ui.dashboard.dialect_auto') }}</option>
  </select>
  @include('components.voice-studio.pick-button', ['kind' => 'dialect', 'labelId' => 'dialectPickLabel', 'value' => __('ui.dashboard.dialect_auto')])
</div>
