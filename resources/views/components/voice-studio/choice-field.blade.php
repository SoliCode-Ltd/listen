{{--
  Performance style or emotion for the whole script: a card that opens its
  picker (icons, categories, search). The select stays the form field.
--}}
@props(['name', 'label', 'options' => [], 'defaultLabel' => null, 'selected' => null])
@php($vsAr = app()->getLocale() === 'ar')
<div>
  <span class="vs-field-label" id="{{ $name }}PickLabel">{{ $label }}</span>
  <select name="{{ $name }}" id="{{ $name }}" class="vs-hidden-field" tabindex="-1" aria-hidden="true">
    <option value="">{{ $defaultLabel ?? __('ui.dashboard.vocal_default') }}</option>
    @foreach($options as $option)
      <option value="{{ $option['value'] ?? '' }}"{{ $selected !== null && $selected === ($option['value'] ?? null) ? ' selected' : '' }}>{{ $vsAr ? ($option['label_ar'] ?? $option['label'] ?? '') : ($option['label'] ?? ($option['value'] ?? '')) }}</option>
    @endforeach
  </select>
  @include('components.voice-studio.pick-button', ['kind' => $name, 'labelId' => $name.'PickLabel', 'value' => $defaultLabel ?? __('ui.dashboard.vocal_default')])
</div>
