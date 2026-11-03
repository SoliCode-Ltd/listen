{{-- Under the dialect (or accent) choice: any voice speaks it, with three of the voices and three flags.
     Needs: $english; optional $faces (avatar URLs). --}}
@php
  $snEnglish = (bool) ($english ?? false);
  $snFlags = array_values(array_filter(array_map(fn ($c) => \App\Support\VoiceFlag::url($c), $snEnglish ? ['en-US', 'en-GB', 'en-AU'] : ['ar-EG', 'ar-SA', 'ar-LB'])));
  $snFaces = array_slice(array_values(array_filter((array) ($faces ?? []))), 0, 3);
@endphp
<div class="ag-speaks-note">
  <span class="ag-speaks-art" aria-hidden="true">
    @if($snFaces)
      <span class="ag-speaks-stack">@foreach($snFaces as $f)<img src="{{ $f }}" alt="" width="26" height="26" loading="lazy">@endforeach</span>
      <svg class="ag-speaks-link" viewBox="0 0 24 24"><path d="M7 8h10l-3-3M17 16H7l3 3"/></svg>
    @endif
    <span class="ag-speaks-stack is-flags">@foreach($snFlags as $f)<img src="{{ $f }}" alt="" width="26" height="26" loading="lazy">@endforeach</span>
  </span>
  <p>
    <b>{{ $snEnglish ? __('agents.speaks_note_title_en') : __('agents.speaks_note_title') }}</b>
    <span>{{ $snEnglish ? __('agents.speaks_note_text_en') : __('agents.speaks_note_text') }}</span>
  </p>
</div>
