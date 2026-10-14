{{--
  Settings on the text box: the language (and, on the demo, the voice
  quality) in the slot, then dialect, performance style and emotion, which
  only Premium voices use. Behaviour in js/voice-studio.js.
--}}
@props(['studio' => true, 'studioId' => null, 'styles' => [], 'emotions' => []])
<div class="vs-setbar" data-vs-setbar>
  <div class="vs-setbar-main">{{ $slot }}</div>
  @if($studio)
    <div class="vs-setbar-studio" data-vs-hd-only @if($studioId) id="{{ $studioId }}" @endif>
      @include('components.voice-studio.dialect-field')
      <x-voice-studio.choice-field name="performance_style" :label="__('ui.dashboard.performance_style')" :options="$styles" />
      <x-voice-studio.choice-field name="emotion" :label="__('ui.dashboard.emotion_tone')" :options="$emotions" />
    </div>
  @endif
</div>
