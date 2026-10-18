{{-- A code sample with language tabs. $samples: [label => code]. Code is always left to right. --}}
@php($blockId = 'code-'.\Illuminate\Support\Str::random(8))
<div class="dev-code-wrap" data-code-block>
  <div class="dev-code-bar" role="tablist">
    @foreach($samples as $label => $code)
      <button type="button" class="dev-tab" role="tab" data-tab="{{ $blockId }}-{{ $loop->index }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ $label }}</button>
    @endforeach
    <button type="button" class="dev-copy" data-copy data-copied-label="{{ __('developers.keys.copied') }}">{{ __('developers.keys.copy') }}</button>
  </div>
  @foreach($samples as $label => $code)
<pre class="dev-code" id="{{ $blockId }}-{{ $loop->index }}" @unless($loop->first) hidden @endunless><code>{{ $code }}</code></pre>
  @endforeach
</div>
