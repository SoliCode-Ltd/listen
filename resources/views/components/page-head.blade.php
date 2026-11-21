@php
  $pageTitle = $pageTitle ?? null;
  $page = $page ?? null;
  $faqItems = $faqItems ?? [];
  $theme = $theme ?? \App\Support\SeoHelper::defaultTheme();
  $htmlLocale = \App\Support\SeoHelper::currentLocale();
@endphp
<!doctype html>
<html lang="{{ $htmlLocale }}" dir="{{ $htmlLocale === 'ar' ? 'rtl' : 'ltr' }}" data-theme="{{ $theme }}">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  {{-- The language this visitor last read the site in: the plain address sends them back to it
       (App\Support\HomeRedirect). A cookie the page sets itself, so the public pages Cloudflare
       keeps (App\Support\PublicPageCache) need no session for it. --}}
  <script>try{var ll='{{ $htmlLocale === 'ar' ? 'ar' : 'en' }}';if((';'+document.cookie).indexOf('; listen_locale='+ll)<0&&document.cookie.indexOf('listen_locale='+ll)!==0){document.cookie='listen_locale='+ll+';path=/;max-age=31536000;samesite=lax'+(location.protocol==='https:'?';secure':'');}}catch(e){}</script>
  {{-- Only for visitors not signed in, and never inside the Listen app (it declares no trackers to its stores). --}}
  @unless(\App\Support\InApp\InApp::active() || auth()->check())
  @include('components.landed')
  @endunless
  @include('components.seo', ['pageTitle' => $pageTitle, 'page' => $page, 'faqItems' => $faqItems])
  @include('components.site-fonts')
  @include('components.styles')
  @include('components.in-app-head')
