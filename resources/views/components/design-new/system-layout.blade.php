{{-- Standalone page shell for the new design's system pages (404, newsletter unsubscribe). --}}
@php
  $ntLocale = $ntLocale ?? (str_starts_with(strtolower((string) app()->getLocale()), 'ar') ? 'ar' : 'en');
  $ntArabic = $ntLocale === 'ar';
  $ntTheme = \App\Support\SeoHelper::defaultTheme();
@endphp
<!doctype html>
<html lang="{{ $ntLocale }}" dir="{{ $ntArabic ? 'rtl' : 'ltr' }}" data-theme="{{ $ntTheme }}" class="nt" data-design="new" data-page="{{ $ntPage ?? 'system' }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>{{ $ntTitle ?? 'Listen' }}</title>
  <script>
    try { var t = localStorage.getItem('listen_theme'); if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t); } catch (e) {}
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
  @include('components.design-new.head')
  <style>
    html, body { margin: 0; min-height: 100%; }
    body { font-family: var(--font-body); background: var(--bg); color: var(--text); line-height: 1.6; }
    html[dir="rtl"] body { font-family: var(--font-body-ar); }
    .site-logo.logo-light { display: none; }
    html[data-theme="light"] .site-logo.logo-dark { display: none; }
    html[data-theme="light"] .site-logo.logo-light { display: block; }
    .site-logo { height: 2.1rem; width: auto; display: block; }
    .btn { display: inline-flex; align-items: center; justify-content: center; text-decoration: none; cursor: pointer; line-height: 1.2; }
    img { max-width: 100%; }
  </style>
</head>
<body>
  <main class="nt-system">
    <a class="nt-system-logo" href="{{ url('/'.$ntLocale) }}">
      @if($ntArabic)
        <img src="{{ asset('images/logo-ar-dark.svg') }}" alt="منصة إسمع" class="site-logo logo-dark">
        <img src="{{ asset('images/logo-ar-light.svg') }}" alt="منصة إسمع" class="site-logo logo-light">
      @else
        <img src="{{ asset('images/logo-dark.svg') }}" alt="Listen" class="site-logo logo-dark">
        <img src="{{ asset('images/logo-light.svg') }}" alt="Listen" class="site-logo logo-light">
      @endif
    </a>
    <div class="nt-system-body">
      @yield('nt-system-content')
    </div>
  </main>
</body>
</html>
