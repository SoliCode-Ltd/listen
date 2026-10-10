{{--
  Dialogue mode: two speakers, each a name used in the script, a premium voice and
  their own dialect or accent. Used by the dashboard generator and the demo page;
  behaviour in js/dialogue-mode.js. The speaker fields are only sent while it is on.
--}}
@props(['on' => false, 'preset' => []])
<div {{ $attributes->merge(['class' => 's-card']) }} data-dialogue
  data-guide="{{ __('ui.dashboard.dialogue_guide') }}"
  data-same-dialect="{{ __('ui.dashboard.dialogue_dialect_same') }}"
  data-example="{{ __('ui.dashboard.dialogue_example') }}">
  <label class="compact-toggle-row" for="dialogueToggle">
    <div class="txt">
      <div class="t-title">🗣️ {{ __('ui.dashboard.dialogue_mode') }}</div>
      <div class="t-desc" id="dialogueHint">{{ __('ui.dashboard.dialogue_hint') }}</div>
    </div>
    <div class="toggle-switch">
      <input type="checkbox" id="dialogueToggle" {{ $on ? 'checked' : '' }}>
      <span class="toggle-slider"></span>
    </div>
  </label>
  <input type="hidden" name="is_dialogue" id="dialogueSelect" value="{{ $on ? '1' : '0' }}">
  <div class="dialogue-speakers" id="dialogueSpeakers" @unless($on) hidden @endunless>
    @foreach([0, 1] as $si)
      <div class="dialogue-speaker-row">
        <div class="dialogue-speaker-label">{{ __('ui.dashboard.dialogue_speaker', ['n' => $si + 1]) }}</div>
        <label class="vs-field">
          <span class="vs-field-label">{{ __('ui.dashboard.dialogue_speaker_name') }}</span>
          <input type="text" name="dialogue_speakers[{{ $si }}][name]" data-dialogue-name="{{ $si }}" maxlength="50" autocomplete="off"
            value="{{ old('dialogue_speakers.'.$si.'.name', data_get($preset, 'dialogue_speakers.'.$si.'.name')) }}" @unless($on) disabled @endunless>
        </label>
        <label class="vs-field">
          <span class="vs-field-label">{{ __('ui.dashboard.dialogue_speaker_voice') }}</span>
          <select name="dialogue_speakers[{{ $si }}][voice]" data-dialogue-voice="{{ $si }}" data-speaker-avatar
            data-initial="{{ old('dialogue_speakers.'.$si.'.voice', data_get($preset, 'dialogue_speakers.'.$si.'.voice')) }}" @unless($on) disabled @endunless></select>
        </label>
        <label class="vs-field dialogue-speaker-dialect">
          <span class="vs-field-label">{{ __('ui.dashboard.dialogue_speaker_dialect') }}</span>
          <select name="dialogue_speakers[{{ $si }}][dialect]" data-dialogue-dialect="{{ $si }}"
            data-initial="{{ old('dialogue_speakers.'.$si.'.dialect', data_get($preset, 'dialogue_speakers.'.$si.'.dialect')) }}" @unless($on) disabled @endunless></select>
        </label>
      </div>
    @endforeach
    <p class="dialogue-guide" id="dialogueGuide"></p>
    <button type="button" class="btn btn-secondary dialogue-example-btn" id="dialogueExampleBtn">{{ __('ui.dashboard.dialogue_example_btn') }}</button>
  </div>
</div>
