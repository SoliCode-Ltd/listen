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
  @include('components.site-fonts')
  @include('components.styles')
