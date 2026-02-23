{{-- SEO Meta Tags — Include in <head> of every public page --}}
{{-- Usage: @include('components.seo', ['pageTitle' => 'Pricing', 'page' => 'pricing']) --}}
@php
    use App\Support\SeoHelper;

    $seoTitle = isset($pageTitle) ? SeoHelper::title($pageTitle, $page ?? '') : SeoHelper::title();
    $seoDescription = SeoHelper::description($page ?? '');
    $canonicalUrl = SeoHelper::canonicalUrl(request()->path() === '/' ? '' : request()->path());
    $defaultTheme = SeoHelper::defaultTheme();
    $faviconUrl = \App\Models\SiteSetting::get('favicon_url', '');
    $logoUrl = \App\Models\SiteSetting::get('logo_url', '');
@endphp

<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
<link rel="canonical" href="{{ $canonicalUrl }}">

@if(!empty($faviconUrl))
<link rel="icon" href="{{ $faviconUrl }}">
@endif

{!! SeoHelper::ogTags($pageTitle ?? '', $page ?? '') !!}
{!! SeoHelper::twitterCard($pageTitle ?? '', $page ?? '') !!}
{!! SeoHelper::structuredData() !!}
{!! SeoHelper::analyticsScript() !!}

@if(SeoHelper::customCss())
<style>{!! SeoHelper::customCss() !!}</style>
@endif
