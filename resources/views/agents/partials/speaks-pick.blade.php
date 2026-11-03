{{-- The dialect (or English accent) an agent speaks, chosen in the studio's own dialect panel
     (js/voice-studio.js: search, regions, each country's dialects, recent picks). The select
     stays the field; the card opens the panel. Under it, a note: any voice speaks any dialect.
     Needs: $id, $labelId, $english, $value; optional $name (a form field), $faces (avatar URLs), and
     $note = false to leave the note out (the setup prints it under the whole row instead). --}}
@php
  $spArabic = app()->getLocale() === 'ar';
  $spEnglish = (bool) ($english ?? false);
  $spRows = $spEnglish ? \App\Support\EnglishAccents::ALL : \App\Support\ArabicDialects::ALL;
  $spValue = isset($spRows[$value ?? '']) ? $value : ($spEnglish ? 'en-US' : 'ar-XA-WH');
@endphp
<select id="{{ $id }}" @isset($name) name="{{ $name }}" @endisset class="vs-hidden-field" tabindex="-1" aria-hidden="true" data-ag-speaks-select>
  @foreach($spRows as $code => $d)
    <option value="{{ $code }}" @selected($spValue === $code)>{{ $d[$spArabic ? 'ar' : 'en'] }}</option>
  @endforeach
</select>
@include('components.voice-studio.pick-button', ['kind' => 'dialect', 'idPrefix' => $id.'-', 'labelId' => $labelId, 'value' => $spRows[$spValue][$spArabic ? 'ar' : 'en'] ?? ''])
@if(($note ?? true) !== false)
  @include('agents.partials.speaks-note', ['english' => $spEnglish, 'faces' => $faces ?? []])
@endif
