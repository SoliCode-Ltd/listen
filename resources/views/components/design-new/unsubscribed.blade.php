@extends('components.design-new.system-layout', ['ntPage' => 'unsubscribed', 'ntTitle' => __('design.system.unsub_title', [], $locale ?? null), 'ntLocale' => ($locale ?? 'en') === 'ar' ? 'ar' : 'en'])
@section('nt-system-content')
  @php $ntUnsubLocale = ($locale ?? 'en') === 'ar' ? 'ar' : 'en'; @endphp
  <span class="nt-system-check" aria-hidden="true"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg></span>
  <h1 class="nt-h2 nt-system-title">{{ __('design.system.unsub_title', [], $ntUnsubLocale) }}</h1>
  <p class="nt-lead">{{ __('design.system.unsub_body', [], $ntUnsubLocale) }} <strong dir="ltr" class="nt-system-email">{{ $email }}</strong></p>
  <div class="nt-actions">
    <a class="btn btn-primary" href="{{ url('/'.$ntUnsubLocale) }}">{{ __('design.system.unsub_home', [], $ntUnsubLocale) }}</a>
  </div>
@endsection
