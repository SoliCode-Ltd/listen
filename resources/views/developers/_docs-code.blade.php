{{--
  A dark code panel. Either $samples (language => highlighted HTML, shown as
  tabs synced across the page) or $single (one highlighted block) with $label.
  Code is always left to right.
--}}
@php($panelId = 'code-'.\Illuminate\Support\Str::random(8))
<div class="code-panel" data-code-panel>
  <div class="code-head">
    @isset($samples)
      <div class="code-tabs" role="tablist">
        @foreach($samples as $lang => $html)
          <button type="button" class="code-tab" role="tab" data-lang="{{ $lang }}" aria-controls="{{ $panelId }}-{{ $lang }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ __('developers.docs.langs.'.$lang) }}</button>
        @endforeach
      </div>
    @else
      <span class="code-label">{{ $label ?? '' }}</span>
    @endisset
    <button type="button" class="code-copy" data-copy data-copy-label="{{ __('developers.docs.copy') }}" data-copied-label="{{ __('developers.docs.copied') }}">{!! '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>' !!}<span>{{ __('developers.docs.copy') }}</span></button>
  </div>
  @isset($samples)
    @foreach($samples as $lang => $html)
<pre class="code-body" id="{{ $panelId }}-{{ $lang }}" role="tabpanel" data-lang-panel="{{ $lang }}" @unless($loop->first) hidden @endunless><code>{!! $html !!}</code></pre>
    @endforeach
  @else
<pre class="code-body"><code>{!! $single !!}</code></pre>
  @endisset
</div>
