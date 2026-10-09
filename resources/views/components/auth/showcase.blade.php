{{-- Brand panel beside the sign in / register form: the headline, and the Listen speakers in conversation. --}}
@php
  $authMode = $authMode ?? 'login';
  $authFreeChars = $authMode === 'register' ? \App\Support\FreePlan::monthlyChars() : null;
@endphp
<aside class="auth-showcase" aria-label="{{ __('design.auth.showcase_label') }}">
  <div class="auth-showcase-inner">
    @if($authMode === 'register')
      <h2 class="auth-headline">{{ __('design.auth.register_headline') }}</h2>
      <ul class="auth-perks">
        @if($authFreeChars)
          <li>{{ __('design.auth.perk_chars', ['chars' => number_format($authFreeChars)]) }}</li>
        @endif
        <li>{{ __('design.auth.perk_voices') }}</li>
        <li>{{ __('design.auth.perk_extension') }}</li>
      </ul>
    @else
      <h2 class="auth-headline">{{ __('design.auth.login_headline') }}</h2>
      <p class="auth-subline">{{ __('design.auth.login_body') }}</p>
    @endif

    <div class="auth-cast">
      @include('components.home.hero-art')
    </div>
  </div>
</aside>
