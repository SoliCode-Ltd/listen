@php
  $ntSideArabic = str_starts_with(strtolower((string) app()->getLocale()), 'ar');
@endphp
<a class="nt-side-brand" href="{{ route('home') }}" aria-label="{{ __('design.dashboard.back_to_site') }}">
  @if($ntSideArabic)
    <img src="{{ asset('images/logo-ar-dark.svg') }}" alt="منصة إسمع" class="site-logo logo-dark">
    <img src="{{ asset('images/logo-ar-light.svg') }}" alt="منصة إسمع" class="site-logo logo-light">
  @else
    <img src="{{ asset('images/logo-dark.svg') }}" alt="Listen" class="site-logo logo-dark">
    <img src="{{ asset('images/logo-light.svg') }}" alt="Listen" class="site-logo logo-light">
  @endif
</a>
<p class="nt-side-heading">{{ __('design.dashboard.workspace') }}</p>
