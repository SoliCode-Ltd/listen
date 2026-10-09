{{-- The Listen 404 page: a standalone page with its own styles, in the language of the URL. --}}
@php
  // Missing pages skip the locale middleware: take the language from the URL, else the site's main language.
  $notFoundLocale = request()->segment(1);
  if (! in_array($notFoundLocale, ['ar', 'en'], true)) {
    $notFoundLocale = (string) \App\Models\SiteSetting::get('site_main_language', config('app.locale', 'ar'));
    $notFoundLocale = $notFoundLocale === 'ar' ? 'ar' : 'en';
  }
  app()->setLocale($notFoundLocale);
  $notFoundArabic = $notFoundLocale === 'ar';
  $notFoundTheme = \App\Support\SeoHelper::defaultTheme();
  $notFoundHelp = trim((string) \App\Models\SiteSetting::get('contact_email', '')) ?: 'help@listenai.studio';
  $notFoundBars = [];
  for ($i = 0; $i < 40; $i++) {
    $d = abs($i - 19.5) / 19.5;
    $notFoundBars[] = ['h' => $d < 0.35 ? 4 : (int) round(8 + 70 * ($d - 0.35) * abs(sin($i * 1.7))), 'flat' => $d < 0.35];
  }
@endphp
<!doctype html>
<html lang="{{ $notFoundLocale }}" dir="{{ $notFoundArabic ? 'rtl' : 'ltr' }}" data-theme="{{ $notFoundTheme }}" data-page="not_found">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>{{ __('design.system.h404') }}</title>
  <script>
    try { var t = localStorage.getItem('listen_theme'); if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t); } catch (e) {}
  </script>
  @include('components.site-fonts')
  <style>
    :root {
      --bg: #0A0A0F; --text: #F5F4F8; --muted: #A9A7B8; --line: #2A2936; --raised: #1C1B25;
      --accent: #6A45F2; --accent-hover: #7A57F5; --accent-text: #A08AFF; --soft: rgba(123, 92, 255, .16); --wave: #353348;
    }
    html[data-theme="light"] {
      --bg: #F6F6F9; --text: #15141B; --muted: #585667; --line: #E1DFEA; --raised: #EFEEF5;
      --accent: #5B36E8; --accent-hover: #4B2CC7; --accent-text: #5B36E8; --soft: rgba(91, 54, 232, .09); --wave: #D9D6E6;
    }
    html, body { margin: 0; min-height: 100%; }
    body { font-family: var(--site-font, sans-serif); background: var(--bg); color: var(--text); line-height: 1.6; -webkit-font-smoothing: antialiased; }
    html[dir="rtl"] body { font-family: var(--site-font, sans-serif); }
    .site-logo { height: 2.1rem; width: auto; display: block; }
    .site-logo.logo-light { display: none; }
    html[data-theme="light"] .site-logo.logo-dark { display: none; }
    html[data-theme="light"] .site-logo.logo-light { display: block; }
    .nf { min-height: 100vh; box-sizing: border-box; max-width: 720px; margin: 0 auto; padding: 40px clamp(1rem, 4vw, 2.5rem); display: flex; flex-direction: column; gap: 20px; }
    .nf-logo { align-self: flex-start; }
    .nf-body { flex: 1; display: flex; flex-direction: column; justify-content: center; gap: 18px; }
    .nf-flatline { display: flex; align-items: center; gap: 4px; height: 90px; max-width: 100%; overflow: hidden; }
    .nf-flatline span { flex: none; width: 6px; border-radius: 3px; background: var(--accent-text); }
    .nf-flatline span.is-flat { background: var(--wave); }
    .nf-pill { align-self: flex-start; padding: 4px 10px; border-radius: 999px; background: var(--soft); color: var(--accent-text); font-size: .8125rem; font-weight: 600; }
    .nf-title { margin: 0; font-family: var(--site-font, sans-serif); font-weight: 600; font-size: clamp(2rem, 4vw, 2.25rem); line-height: 1.18; letter-spacing: -0.015em; }
    html[dir="rtl"] .nf-title { font-family: var(--site-font, sans-serif); letter-spacing: 0; line-height: 1.3; }
    .nf-lead { margin: 0; font-size: clamp(1.05rem, 1.4vw, 1.19rem); line-height: 1.65; color: var(--muted); }
    .nf-actions { display: flex; flex-wrap: wrap; gap: .75rem; }
    .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 1.4rem; border-radius: 12px; border: 1px solid var(--line); color: var(--text); font: inherit; font-weight: 600; text-decoration: none; transition: background-color .15s ease; }
    .btn:hover { background: var(--raised); }
    .btn-primary, .btn-primary:hover { background: var(--accent); border-color: var(--accent); color: #FFFFFF; }
    .btn-primary:hover { background: var(--accent-hover); }
    .nf-small { margin: 0; font-size: .875rem; color: var(--muted); }
    .nf-small a { color: var(--accent-text); font-weight: 600; text-decoration: none; }
    .nf-small a:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <main class="nf">
    <a class="nf-logo" href="{{ url('/'.$notFoundLocale) }}">
      @if($notFoundArabic)
        <img src="{{ asset('images/logo-ar-dark.svg') }}" alt="منصة إسمع" class="site-logo logo-dark">
        <img src="{{ asset('images/logo-ar-light.svg') }}" alt="منصة إسمع" class="site-logo logo-light">
      @else
        <img src="{{ asset('images/logo-dark.svg') }}" alt="Listen" class="site-logo logo-dark">
        <img src="{{ asset('images/logo-light.svg') }}" alt="Listen" class="site-logo logo-light">
      @endif
    </a>
    <div class="nf-body">
      <div class="nf-flatline" aria-hidden="true">
        @foreach($notFoundBars as $bar)<span class="{{ $bar['flat'] ? 'is-flat' : '' }}" style="height: {{ $bar['h'] }}px"></span>@endforeach
      </div>
      <span class="nf-pill">404</span>
      <h1 class="nf-title">{{ __('design.system.h404') }}</h1>
      <p class="nf-lead">{{ __('design.system.b404') }}</p>
      <div class="nf-actions">
        <a class="btn btn-primary" href="{{ url('/'.$notFoundLocale) }}">{{ __('design.system.home') }}</a>
        <a class="btn" href="{{ url('/'.$notFoundLocale.'/demo') }}">{{ __('design.system.demo') }}</a>
      </div>
      <p class="nf-small">{{ __('design.system.help') }} <a href="mailto:{{ $notFoundHelp }}">{{ $notFoundHelp }}</a></p>
    </div>
  </main>
</body>
</html>
