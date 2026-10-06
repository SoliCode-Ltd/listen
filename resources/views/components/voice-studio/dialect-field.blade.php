{{-- Dialect: the select stays the form field; js/voice-studio.js shows every option as a button, grouped by region. --}}
@php
  $vsRegionLabels = [];
  foreach (\App\Support\ArabicDialects::regions() as $vsRegionKey => $vsRegionNames) {
      $vsRegionLabels[$vsRegionKey] = app()->getLocale() === 'ar' ? $vsRegionNames['ar'] : $vsRegionNames['en'];
  }
  $vsRegionOf = array_map(fn ($row) => $row['region'], \App\Support\ArabicDialects::ALL);
@endphp
<div class="vs-field">
  <div class="vs-field-label">
    <span>{{ __('ui.dashboard.dialect') }}</span>
    <span class="vs-dialect-current" data-vs-dialect-current></span>
  </div>
  <select name="dialect" id="dialect" class="vs-hidden-field" tabindex="-1" aria-hidden="true">
    <option value="">{{ __('ui.dashboard.dialect_auto') }}</option>
  </select>
  <div class="vs-dialects" data-vs-dialects role="radiogroup" aria-label="{{ __('ui.dashboard.dialect') }}"
       data-regions="{{ json_encode($vsRegionLabels, JSON_UNESCAPED_UNICODE) }}"
       data-region-of="{{ json_encode($vsRegionOf) }}"></div>
</div>
