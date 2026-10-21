{{--
  Text to speech or Dub a video, above the studio in the demo and on the
  dashboard. It sets data-studio on the closest [data-studio-host]; what
  belongs to one mode carries data-studio-pane="tts" or "dub" (CSS in
  css/dub-studio.css, behaviour in js/dub-studio.js). #dub in the address
  opens the dubbing side.
--}}
<div class="studio-modes" role="tablist" aria-label="{{ __('ui.dub.modes_label') }}" data-studio-switch>
  <button type="button" role="tab" class="studio-mode" data-studio-tab="tts" aria-selected="true">
    <span class="studio-mode-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h10M4 18h7"/><path d="M17 14v6M20 15.5v3M14 16v2"/></svg></span>
    <span class="studio-mode-text">
      <strong>{{ __('ui.dub.mode_tts') }}</strong>
      <small>{{ __('ui.dub.mode_tts_hint') }}</small>
    </span>
  </button>
  <button type="button" role="tab" class="studio-mode" data-studio-tab="dub" aria-selected="false">
    <span class="studio-mode-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="6" width="13" height="12" rx="2.5"/><path d="M16 10.5l5-3v9l-5-3"/></svg></span>
    <span class="studio-mode-text">
      <strong>{{ __('ui.dub.mode_dub') }}</strong>
      <small>{{ __('ui.dub.mode_dub_hint') }}</small>
    </span>
  </button>
</div>
