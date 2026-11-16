{{--
  One group of an endpoint's parameters: each name with its type and whether
  it is needed, then what it does. A nested name (voice_settings.speed) shows
  its parent dimmed before it, so the field itself reads first.
--}}
<div class="docs-params-block">
  <h5 class="docs-params-title">{{ $title }}</h5>
  <dl class="docs-params">
    @foreach($params as $param)
      @php
        $name = (string) $param['name'];
        $dot = strrpos($name, '.');
      @endphp
      <div class="docs-param">
        <dt>
          @if($name !== '*')
            <code dir="ltr" class="docs-param-name">@if($dot !== false)<span class="docs-param-parent">{{ substr($name, 0, $dot + 1) }}</span>{{ substr($name, $dot + 1) }}@else{{ $name }}@endif</code>
            <span class="docs-type">{{ __('developers.docs.types.'.$param['type']) }}</span>
          @endif
          @if($param['required'])
            <span class="docs-flag req">{{ __('developers.docs.required') }}</span>
          @elseif($name !== '*')
            <span class="docs-flag">{{ __('developers.docs.optional') }}</span>
          @endif
          @if($param['gated'])<a class="docs-flag plan" href="#plan-features">{{ __('developers.docs.some_plans') }}</a>@endif
        </dt>
        <dd>{!! \App\Support\Api\ApiDocs::rich($param['description']) !!}</dd>
      </div>
    @endforeach
  </dl>
</div>
