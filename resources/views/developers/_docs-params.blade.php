{{-- One group of an endpoint's parameters: name, type, whether it is needed, and what it does. --}}
<h4>{{ $title }}</h4>
<dl class="docs-params">
  @foreach($params as $param)
    <div class="docs-param">
      <dt>
        @if($param['name'] !== '*')<code dir="ltr">{{ $param['name'] }}</code>@endif
        @if($param['name'] !== '*')<span class="docs-type">{{ __('developers.docs.types.'.$param['type']) }}</span>@endif
        @if($param['required'])
          <span class="docs-flag req">{{ __('developers.docs.required') }}</span>
        @elseif($param['name'] !== '*')
          <span class="docs-flag">{{ __('developers.docs.optional') }}</span>
        @endif
        @if($param['gated'])<a class="docs-flag plan" href="#plan-features">{{ __('developers.docs.some_plans') }}</a>@endif
      </dt>
      <dd>{!! \App\Support\Api\ApiDocs::rich($param['description']) !!}</dd>
    </div>
  @endforeach
</dl>
