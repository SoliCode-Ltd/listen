{{--
  Text to speech, Voice Design or Dub a video, above the studio in the demo: whichever are open
  ($design, $dub). It sets data-studio on the closest [data-studio-host]; what belongs to modes
  carries data-studio-pane with their names (CSS in css/studio-modes.css, behaviour in
  js/studio-modes.js). #voice-design or #dub in the address opens that side.
--}}
@props(['design' => false, 'dub' => false])
<div class="studio-modes" role="tablist" aria-label="{{ __('ui.dub.modes_label') }}" data-studio-switch>
  <button type="button" role="tab" class="studio-mode" data-studio-tab="tts" aria-selected="true">
    <span class="studio-mode-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h10M4 18h7"/><path d="M17 14v6M20 15.5v3M14 16v2"/></svg></span>
    <span class="studio-mode-text">
      <strong>{{ __('ui.dub.mode_tts') }}</strong>
      <small>{{ __('ui.dub.mode_tts_hint') }}</small>
    </span>
  </button>
  @if($design)
  <button type="button" role="tab" class="studio-mode" data-studio-tab="design" aria-selected="false">
    <span class="studio-mode-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3.5l1.7 4.6 4.8 1.7-4.8 1.7L12 16.1l-1.7-4.6-4.8-1.7 4.8-1.7z"/><path d="M18.5 15.5l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7z"/></svg></span>
    <span class="studio-mode-text">
      <strong>{{ __('voice_design.demo_tab') }}</strong>
      <small>{{ __('voice_design.demo_tab_hint') }}</small>
    </span>
  </button>
  @endif
  @if($dub)
  <button type="button" role="tab" class="studio-mode" data-studio-tab="dub" aria-selected="false">
    <span class="studio-mode-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="6" width="13" height="12" rx="2.5"/><path d="M16 10.5l5-3v9l-5-3"/></svg></span>
    <span class="studio-mode-text">
      <strong>{{ __('ui.dub.mode_dub') }}</strong>
      <small>{{ __('ui.dub.mode_dub_hint') }}</small>
    </span>
  </button>
  @endif
</div>
