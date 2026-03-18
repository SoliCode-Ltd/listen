@php
  use Illuminate\Support\Facades\Storage;

  $siteName = \App\Models\SiteSetting::get('site_name', 'Listen');
  $currentLocale = strtolower((string) app()->getLocale());
  $currentLocaleCode = str_starts_with($currentLocale, 'ar') ? 'ar' : 'en';
  $isArabicUi = $currentLocaleCode === 'ar';
  $brandName = $isArabicUi ? 'منصة إسمع' : $siteName;
  $logoPath = trim((string) \App\Models\SiteSetting::get('logo_url', ''));
  $logoUrl = null;

  if ($logoPath !== '') {
    if (filter_var($logoPath, FILTER_VALIDATE_URL)) {
      $logoUrl = $logoPath;
    } elseif (Storage::disk('public')->exists($logoPath)) {
      $logoUrl = Storage::disk('public')->url($logoPath);
    }
  }

  $currentRoute = Route::currentRouteName() ?? '';
  $languageLinks = [];
  foreach (['en', 'ar'] as $localeCode) {
    $supportedLocales = \Mcamara\LaravelLocalization\Facades\LaravelLocalization::getSupportedLocales();
    if (!isset($supportedLocales[$localeCode])) continue;
    $localeLabel = $localeCode === 'ar'
      ? ($isArabicUi ? 'العربية' : 'Arabic')
      : 'English';
    $languageLinks[] = [
      'code' => $localeCode,
      'label' => $localeLabel,
      'url' => \Mcamara\LaravelLocalization\Facades\LaravelLocalization::getLocalizedURL($localeCode, null, [], true),
      'active' => $currentLocaleCode === $localeCode,
    ];
  }
  $navLinks = [
    ['route' => 'home', 'label' => __('ui.nav.home'), 'guest' => false],
    ['route' => 'demo.page', 'label' => __('ui.nav.demo'), 'guest' => true],
    ['route' => 'pricing', 'label' => __('ui.nav.pricing'), 'guest' => false],
    ['route' => 'browsers-extension.page', 'label' => __('ui.nav.extension'), 'guest' => false],
    ['route' => 'contact', 'label' => __('ui.nav.contact'), 'guest' => false],
  ];
  $showDemoLinkForAuthenticated = false;
  if (auth()->check()) {
    $activePlan = app(\App\Services\PlanAccessService::class)->resolveActivePlanForUserId((int) auth()->id());
    $showDemoLinkForAuthenticated = strtolower(trim((string) ($activePlan?->slug ?? 'free'))) === 'free';
  }
  $authNavLinks = [
    ['route' => 'home', 'label' => __('ui.nav.home'), 'guest' => false],
    ['route' => 'dashboard', 'label' => __('ui.nav.dashboard'), 'guest' => false],
    ['route' => 'pricing', 'label' => __('ui.nav.pricing'), 'guest' => false],
    ['route' => 'browsers-extension.page', 'label' => __('ui.nav.extension'), 'guest' => false],
    ['route' => 'contact', 'label' => __('ui.nav.contact'), 'guest' => false],
  ];
  if ($showDemoLinkForAuthenticated) {
    array_splice($authNavLinks, 2, 0, [[
      'route' => 'demo.page',
      'label' => __('ui.nav.demo'),
      'guest' => false,
    ]]);
  }
@endphp

<header class="app-header">
  <div class="header-inner">
    <a class="brand" href="{{ route('home') }}" aria-label="{{ __('ui.header.brand_home', ['name' => $brandName]) }}">
      @if($logoUrl)
        <img src="{{ $logoUrl }}" alt="{{ $brandName }}" style="max-height: 40px; width: auto;" />
      @else
        @if($isArabicUi)
          <img src="{{ asset('images/logo-ar-dark.svg') }}" alt="منصة إسمع" class="site-logo logo-dark">
          <img src="{{ asset('images/logo-ar-light.svg') }}" alt="منصة إسمع" class="site-logo logo-light">
        @else
          <img src="{{ asset('images/logo-dark.svg') }}" alt="Listen" class="site-logo logo-dark">
          <img src="{{ asset('images/logo-light.svg') }}" alt="Listen" class="site-logo logo-light">
        @endif
      @endif
    </a>

    <nav class="desktop-nav" aria-label="{{ __('ui.header.main_nav') }}">
      @auth
        @foreach($authNavLinks as $link)
          <a href="{{ route($link['route']) }}" class="nav-link {{ $currentRoute === $link['route'] ? 'active' : '' }}">{{ $link['label'] }}</a>
        @endforeach
      @else
        @foreach($navLinks as $link)
          @if(!$link['guest'] || !auth()->check())
            <a href="{{ route($link['route']) }}" class="nav-link {{ $currentRoute === $link['route'] ? 'active' : '' }}">{{ $link['label'] }}</a>
          @endif
        @endforeach
      @endauth
    </nav>

    <div class="header-actions">
      <div class="locale-switch {{ $isArabicUi ? 'ar-active' : '' }}" aria-label="{{ __('ui.locale.switch') }}">
        <span class="locale-indicator" aria-hidden="true"></span>
        @foreach($languageLinks as $language)
          <a
            href="{{ $language['url'] }}"
            hreflang="{{ $language['code'] }}"
            class="locale-link {{ $language['active'] ? 'active' : '' }}"
          >{{ $language['label'] }}</a>
        @endforeach
      </div>

      <button type="button" class="btn-icon theme-toggle" id="themeToggle" data-theme-toggle aria-label="{{ __('ui.header.toggle_theme') }}">
        <svg class="icon sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg>
        <svg class="icon moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 1 0 9.8 9.8z"></path></svg>
      </button>

      @auth
        <div class="user-menu" id="userMenu">
          <button type="button" class="user-trigger" id="userMenuTrigger" aria-expanded="false" aria-haspopup="true">
            <div class="user-avatar">
              @if(auth()->user()->google_avatar)
                <img src="{{ auth()->user()->google_avatar }}" alt="{{ auth()->user()->name }}" />
              @else
                {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
              @endif
            </div>
            <span class="user-name">{{ auth()->user()->name }}</span>
            <svg class="chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div class="user-dropdown" id="userDropdown">
            <div class="dropdown-header">
              <div class="dropdown-avatar">
                @if(auth()->user()->google_avatar)
                  <img src="{{ auth()->user()->google_avatar }}" alt="{{ auth()->user()->name }}" />
                @else
                  {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
                @endif
              </div>
              <div class="dropdown-user-info">
                <strong>{{ auth()->user()->name }}</strong>
                <span>{{ auth()->user()->email }}</span>
              </div>
            </div>
            <div class="dropdown-divider"></div>
            <a href="{{ route('dashboard') }}" class="dropdown-item">
              <svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
              {{ __('ui.nav.dashboard') }}
            </a>
            <a href="{{ route('browsers-extension.page') }}" class="dropdown-item">
              <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
              {{ __('ui.nav.extension') }}
            </a>
            <a href="{{ route('billing.page') }}" class="dropdown-item">
              <svg viewBox="0 0 24 24"><path d="M3 7h18v10H3zM3 10h18"/></svg>
              {{ __('ui.nav.billing') }}
            </a>
            <a href="{{ route('manage-subscription.page') }}" class="dropdown-item">
              <svg viewBox="0 0 24 24"><path d="M12 2v6M6 8h12M4 12h16M7 16h10M9 20h6"/></svg>
              {{ __('ui.nav.manage_subscription') }}
            </a>
            <a href="{{ route('pricing') }}" class="dropdown-item">
              <svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              {{ __('ui.nav.pricing') }}
            </a>
            @if(auth()->user()->is_admin)
            <div class="dropdown-divider"></div>
            <a href="{{ url('/admin') }}" class="dropdown-item">
              <svg viewBox="0 0 24 24"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>
              {{ __('ui.nav.admin_dashboard') }}
            </a>
            @endif
            <div class="dropdown-divider"></div>
            <form method="POST" action="{{ route('logout') }}">
              @csrf
              <button type="submit" class="dropdown-item logout">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                {{ __('ui.nav.sign_out') }}
              </button>
            </form>
          </div>
        </div>
      @else
        <div class="auth-buttons">
          <a class="btn btn-ghost" href="{{ route('login') }}">{{ __('ui.nav.sign_in') }}</a>
          <a class="btn btn-primary" href="{{ route('register') }}">{{ __('ui.nav.get_started') }}</a>
        </div>
      @endauth
    </div>

    <button type="button" class="mobile-toggle" id="mobileMenuToggle" aria-label="{{ __('ui.header.toggle_menu') }}" aria-expanded="false">
      <span class="hamburger"></span>
    </button>
  </div>

</header>

<div class="mobile-nav" id="mobileNav" aria-hidden="true">
  <nav class="mobile-nav-inner">
    <div class="mobile-nav-top">
      <a class="mobile-nav-brand" href="{{ route('home') }}" aria-label="{{ __('ui.header.brand_home', ['name' => $brandName]) }}">
        @if($logoUrl)
          <img src="{{ $logoUrl }}" alt="{{ $brandName }}" />
        @else
          @if($isArabicUi)
            <img src="{{ asset('images/logo-ar-dark.svg') }}" alt="منصة إسمع" class="site-logo logo-dark">
            <img src="{{ asset('images/logo-ar-light.svg') }}" alt="منصة إسمع" class="site-logo logo-light">
          @else
            <img src="{{ asset('images/logo-dark.svg') }}" alt="Listen" class="site-logo logo-dark">
            <img src="{{ asset('images/logo-light.svg') }}" alt="Listen" class="site-logo logo-light">
          @endif
        @endif
      </a>
      <div class="mobile-nav-tools">
        <button type="button" class="btn-icon theme-toggle" data-theme-toggle data-mobile-menu-stay-open aria-label="{{ __('ui.header.toggle_theme') }}">
          <svg class="icon sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path></svg>
          <svg class="icon moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 1 0 9.8 9.8z"></path></svg>
        </button>
        <button type="button" class="btn-icon mobile-close" id="mobileMenuClose" aria-label="{{ __('ui.header.close_menu') }}">
          <svg class="close-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6l-12 12"/></svg>
        </button>
      </div>
    </div>
    <div class="mobile-nav-divider"></div>
    <div class="mobile-locale-switch {{ $isArabicUi ? 'ar-active' : '' }}" aria-label="{{ __('ui.locale.switch') }}">
      <span class="locale-indicator" aria-hidden="true"></span>
      @foreach($languageLinks as $language)
        <a
          href="{{ $language['url'] }}"
          hreflang="{{ $language['code'] }}"
          class="locale-link {{ $language['active'] ? 'active' : '' }}"
        >{{ $language['label'] }}</a>
      @endforeach
    </div>
    <div class="mobile-nav-divider"></div>

    @auth
      @foreach($authNavLinks as $link)
        <a href="{{ route($link['route']) }}" class="mobile-nav-link {{ $currentRoute === $link['route'] ? 'active' : '' }}">{{ $link['label'] }}</a>
      @endforeach
      @if(auth()->user()->is_admin)
      <a href="{{ url('/admin') }}" class="mobile-nav-link">{{ __('ui.nav.admin_dashboard') }}</a>
      @endif
      <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="mobile-nav-link logout">{{ __('ui.nav.sign_out') }}</button>
      </form>
    @else
      @foreach($navLinks as $link)
        @if(!$link['guest'] || !auth()->check())
          <a href="{{ route($link['route']) }}" class="mobile-nav-link {{ $currentRoute === $link['route'] ? 'active' : '' }}">{{ $link['label'] }}</a>
        @endif
      @endforeach
      <div class="mobile-nav-divider"></div>
      <a href="{{ route('login') }}" class="mobile-nav-link">{{ __('ui.nav.sign_in') }}</a>
      <a href="{{ route('register') }}" class="btn btn-primary mobile-cta">{{ __('ui.nav.get_started_free') }}</a>
    @endauth
  </nav>
</div>
