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
  @include('components.seo', ['pageTitle' => $pageTitle, 'page' => $page, 'faqItems' => $faqItems])
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  @include('components.styles')
