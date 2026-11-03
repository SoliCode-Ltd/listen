{{-- The dialect (or English accent) an agent speaks, chosen in the studio's own dialect panel
     (js/voice-studio.js: search, regions, each country's dialects, recent picks). The select
     stays the field; the card opens the panel. Under it, a note: any voice speaks any dialect.
     Needs: $id, $labelId, $english, $value; optional $name (a form field), $faces (avatar URLs). --}}
@php
  $spArabic = app()->getLocale() === 'ar';
  $spEnglish = (bool) ($english ?? false);
  $spRows = $spEnglish ? \App\Support\EnglishAccents::ALL : \App\Support\ArabicDialects::ALL;
  $spValue = isset($spRows[$value ?? '']) ? $value : ($spEnglish ? 'en-US' : 'ar-XA-WH');
  $spFlags = array_values(array_filter(array_map(fn ($c) => \App\Support\VoiceFlag::url($c), $spEnglish ? ['en-US', 'en-GB', 'en-AU'] : ['ar-EG', 'ar-SA', 'ar-LB'])));
  $spFaces = array_slice(array_values(array_filter((array) ($faces ?? []))), 0, 3);
@endphp
<select id="{{ $id }}" @isset($name) name="{{ $name }}" @endisset class="vs-hidden-field" tabindex="-1" aria-hidden="true" data-ag-speaks-select>
  @foreach($spRows as $code => $d)
    <option value="{{ $code }}" @selected($spValue === $code)>{{ $d[$spArabic ? 'ar' : 'en'] }}</option>
  @endforeach
</select>
@include('components.voice-studio.pick-button', ['kind' => 'dialect', 'idPrefix' => $id.'-', 'labelId' => $labelId, 'value' => $spRows[$spValue][$spArabic ? 'ar' : 'en'] ?? ''])
<div class="ag-speaks-note">
  <span class="ag-speaks-art" aria-hidden="true">
    @if($spFaces)
      <span class="ag-speaks-stack">@foreach($spFaces as $f)<img src="{{ $f }}" alt="" width="26" height="26" loading="lazy">@endforeach</span>
      <svg class="ag-speaks-link" viewBox="0 0 24 24"><path d="M7 8h10l-3-3M17 16H7l3 3"/></svg>
    @endif
    <span class="ag-speaks-stack is-flags">@foreach($spFlags as $f)<img src="{{ $f }}" alt="" width="26" height="26" loading="lazy">@endforeach</span>
  </span>
  <p>
    <b>{{ $spEnglish ? __('agents.speaks_note_title_en') : __('agents.speaks_note_title') }}</b>
    <span>{{ $spEnglish ? __('agents.speaks_note_text_en') : __('agents.speaks_note_text') }}</span>
  </p>
</div>
