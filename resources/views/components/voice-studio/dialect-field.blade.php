{{--
  Dialect: pick a country, then one of its dialects. The select stays the
  form field; js/voice-studio.js builds the country list and the buttons.
--}}
@php
  $vsAr = app()->getLocale() === 'ar';
  $vsCountries = [];
  foreach (\App\Support\ArabicDialects::COUNTRIES as $vsCode => $vsNames) {
      $vsCountries[] = [$vsCode, $vsAr ? $vsNames['ar'] : $vsNames['en']];
  }
  $vsCountryOf = [];
  foreach (array_keys(\App\Support\ArabicDialects::ALL) as $vsDialect) {
      $vsCountryOf[$vsDialect] = \App\Support\ArabicDialects::countryOf($vsDialect);
  }
@endphp
<div class="vs-field">
  <div class="vs-field-label">
    <span>{{ __('ui.dashboard.dialect') }}</span>
    <span class="vs-dialect-current" data-vs-dialect-current></span>
  </div>
  <select name="dialect" id="dialect" class="vs-hidden-field" tabindex="-1" aria-hidden="true">
    <option value="">{{ __('ui.dashboard.dialect_auto') }}</option>
  </select>
  <div class="vs-country" data-vs-country-wrap>
    <label class="vs-sub-label" for="dialectCountry">{{ __('ui.dashboard.dialect_country') }}</label>
    <select id="dialectCountry" data-vs-country aria-label="{{ __('ui.dashboard.dialect_country') }}"></select>
  </div>
  <div class="vs-dialects" data-vs-dialects role="radiogroup" aria-label="{{ __('ui.dashboard.dialect') }}"
       data-countries="{{ json_encode($vsCountries, JSON_UNESCAPED_UNICODE) }}"
       data-country-of="{{ json_encode($vsCountryOf) }}"
       data-auto-label="{{ __('ui.dashboard.dialect_auto') }}"></div>
</div>
