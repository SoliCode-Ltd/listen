{{--
  Settings on the text box: the language (and, on the demo, the voice
  quality) in the slot, then dialect, performance style and emotion, which
  only Premium voices use. Behaviour in js/voice-studio.js. A second studio
  on the same page (the dubbing studio) gives its fields' ids a prefix.
--}}
@props(['studio' => true, 'studioId' => null, 'styles' => [], 'emotions' => [], 'lock' => null, 'prefix' => '', 'styleDefault' => null, 'emotionDefault' => null, 'defaultDesc' => null])
<div class="vs-setbar" data-vs-setbar>
  <div class="vs-setbar-main">{{ $slot }}</div>
  @if($studio)
    <x-voice-studio.locked :lock="$lock" class="vs-setbar-lock">
      <div class="vs-setbar-studio" data-vs-hd-only @if($studioId) id="{{ $studioId }}" @endif>
        @include('components.voice-studio.dialect-field', ['prefix' => $prefix])
        <x-voice-studio.choice-field name="performance_style" :prefix="$prefix" :default-label="$styleDefault" :default-desc="$defaultDesc" :label="__('ui.dashboard.performance_style')" :options="$styles" />
        <x-voice-studio.choice-field name="emotion" :prefix="$prefix" :default-label="$emotionDefault" :default-desc="$defaultDesc" :label="__('ui.dashboard.emotion_tone')" :options="$emotions" />
      </div>
    </x-voice-studio.locked>
  @endif
</div>
