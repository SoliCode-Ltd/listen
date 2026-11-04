{{-- What the studio's dialect panel needs to draw the agent's dialects (or accents): each one's
     country, region, flag and description, the names of the countries and regions, and its words.
     $english: an English agent (accents); null on the setup page (both languages). --}}
@php
  $smArabic = app()->getLocale() === 'ar';
  $smAll = \App\Support\StudioChoices::forPicker($smArabic);
  $smCodes = array_keys(match ($english ?? null) {
      true => \App\Support\EnglishAccents::ALL,
      false => \App\Support\ArabicDialects::ALL,
      default => \App\Support\ArabicDialects::ALL + \App\Support\EnglishAccents::ALL,
  });
  $smMeta = [
      'dialects' => array_intersect_key($smAll['dialects'], array_flip($smCodes)),
      'countries' => $smAll['countries'],
      'regions' => $smAll['regions'],
      'i18n' => __('ui.dashboard.picker'),
  ];
@endphp
<script type="application/json" data-ag-picker-meta>@json($smMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)</script>
