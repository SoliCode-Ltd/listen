@php
  $currentRoute = Route::currentRouteName() ?? '';
  $navLinks = [
    ['route' => 'home', 'label' => 'Home', 'guest' => false],
    ['route' => 'demo.page', 'label' => 'Demo', 'guest' => true],
    ['route' => 'pricing', 'label' => 'Pricing', 'guest' => false],
    ['route' => 'browsers-extension.page', 'label' => 'Browsers Extension', 'guest' => false],
  ];
  $authNavLinks = [
    ['route' => 'dashboard', 'label' => 'Dashboard', 'guest' => false],
    ['route' => 'pricing', 'label' => 'Pricing', 'guest' => false],
    ['route' => 'browsers-extension.page', 'label' => 'Browsers Extension', 'guest' => false],
  ];
@endphp

<header class="app-header">
  <div class="header-inner">
    <a class="brand" href="{{ route('home') }}" aria-label="Listen home">
      <span class="brand-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" role="img"><path d="M12 3a8 8 0 0 0-8 8v6a3 3 0 0 0 3 3h1.5a2.5 2.5 0 0 0 2.5-2.5v-3A2.5 2.5 0 0 0 8.5 12H6a6 6 0 1 1 12 0h-2.5a2.5 2.5 0 0 0-2.5 2.5v3a2.5 2.5 0 0 0 2.5 2.5H17a3 3 0 0 0 3-3v-6a8 8 0 0 0-8-8Z"/></svg>
      </span>
      <span class="brand-text">Listen</span>
    </a>

    <nav class="desktop-nav" aria-label="Main navigation">
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
      <button type="button" class="btn-icon theme-toggle" id="themeToggle" aria-label="Toggle theme">
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
              Dashboard
            </a>
            <a href="{{ route('browsers-extension.page') }}" class="dropdown-item">
              <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
              Browsers Extension
            </a>
            <a href="{{ route('billing.page') }}" class="dropdown-item">
              <svg viewBox="0 0 24 24"><path d="M3 7h18v10H3zM3 10h18"/></svg>
              Billing
            </a>
            <a href="{{ route('manage-subscription.page') }}" class="dropdown-item">
              <svg viewBox="0 0 24 24"><path d="M12 2v6M6 8h12M4 12h16M7 16h10M9 20h6"/></svg>
              Manage Subscription
            </a>
            <a href="{{ route('pricing') }}" class="dropdown-item">
              <svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              Pricing
            </a>
            @if(auth()->user()->is_admin)
            <div class="dropdown-divider"></div>
            <a href="{{ url('/admin') }}" class="dropdown-item">
              <svg viewBox="0 0 24 24"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg>
              Admin Dashboard
            </a>
            @endif
            <div class="dropdown-divider"></div>
            <form method="POST" action="{{ route('logout') }}">
              @csrf
              <button type="submit" class="dropdown-item logout">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                Sign out
              </button>
            </form>
          </div>
        </div>
      @else
        <div class="auth-buttons">
          <a class="btn btn-ghost" href="{{ route('login') }}">Sign in</a>
          <a class="btn btn-primary" href="{{ route('register') }}">Get Started</a>
        </div>
      @endauth
    </div>

    <button type="button" class="mobile-toggle" id="mobileMenuToggle" aria-label="Toggle menu" aria-expanded="false">
      <span class="hamburger"></span>
    </button>
  </div>

  <div class="mobile-nav" id="mobileNav" aria-hidden="true">
    <nav class="mobile-nav-inner">
      @auth
        @foreach($authNavLinks as $link)
          <a href="{{ route($link['route']) }}" class="mobile-nav-link {{ $currentRoute === $link['route'] ? 'active' : '' }}">{{ $link['label'] }}</a>
        @endforeach
      @else
        @foreach($navLinks as $link)
          @if(!$link['guest'] || !auth()->check())
            <a href="{{ route($link['route']) }}" class="mobile-nav-link {{ $currentRoute === $link['route'] ? 'active' : '' }}">{{ $link['label'] }}</a>
          @endif
        @endforeach
      @endauth

      <div class="mobile-nav-divider"></div>

      @auth
        <div class="mobile-user">
          <div class="mobile-user-avatar">
            @if(auth()->user()->google_avatar)
              <img src="{{ auth()->user()->google_avatar }}" alt="{{ auth()->user()->name }}" />
            @else
              {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
            @endif
          </div>
          <div class="mobile-user-info">
            <strong>{{ auth()->user()->name }}</strong>
            <span>{{ auth()->user()->email }}</span>
          </div>
        </div>
        @if(auth()->user()->is_admin)
        <a href="{{ url('/admin') }}" class="mobile-nav-link">⚙️ Admin Dashboard</a>
        @endif
        <form method="POST" action="{{ route('logout') }}">
          @csrf
          <button type="submit" class="mobile-nav-link logout">Sign out</button>
        </form>
      @else
        <a href="{{ route('login') }}" class="mobile-nav-link">Sign in</a>
        <a href="{{ route('register') }}" class="btn btn-primary mobile-cta">Get Started Free</a>
      @endauth
    </nav>
  </div>
</header>
