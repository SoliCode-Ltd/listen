{{-- Slim top bar for the split sign in / register pages: logo, language, theme. --}}
@php
  $authArabic = str_starts_with(strtolower((string) app()->getLocale()), 'ar');
  $authOther = $authArabic ? 'en' : 'ar';
  $authOtherUrl = \Mcamara\LaravelLocalization\Facades\LaravelLocalization::getLocalizedURL($authOther, null, [], true);
@endphp
<div class="auth-topbar">
  <a class="auth-logo" href="{{ route('home') }}" aria-label="{{ __('design.auth.home') }}">
    @if($authArabic)
      <img src="{{ asset('images/logo-ar-dark.svg') }}" alt="منصة إسمع" class="site-logo logo-dark">
      <img src="{{ asset('images/logo-ar-light.svg') }}" alt="منصة إسمع" class="site-logo logo-light">
    @else
      <img src="{{ asset('images/logo-dark.svg') }}" alt="Listen" class="site-logo logo-dark">
      <img src="{{ asset('images/logo-light.svg') }}" alt="Listen" class="site-logo logo-light">
    @endif
  </a>
  <div class="auth-topbar-tools">
    <a class="auth-lang" href="{{ $authOtherUrl }}" hreflang="{{ $authOther }}" lang="{{ $authOther }}" aria-label="{{ __('design.auth.switch_language') }}">{{ $authArabic ? 'English' : 'العربية' }}</a>
    <button type="button" class="auth-icon-btn theme-toggle" data-theme-toggle aria-label="{{ __('ui.header.toggle_theme') }}">
      <svg class="icon sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg>
      <svg class="icon moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 1 0 9.8 9.8z"></path></svg>
    </button>
  </div>
</div>
