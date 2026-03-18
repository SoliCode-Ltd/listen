{{-- SEO Meta Tags - Include in <head> of every public page --}}
{{-- Usage: @include('components.seo', ['pageTitle' => 'Pricing', 'page' => 'pricing']) --}}
@php
    use App\Support\SeoHelper;

    $pageKey = $page ?? '';
    $resolvedPageTitle = isset($pageTitle) ? (string) $pageTitle : '';
    $seoTitle = SeoHelper::title($resolvedPageTitle, $pageKey);
    $seoDescription = SeoHelper::description($pageKey);
    $seoKeywords = SeoHelper::keywords($pageKey);
    $seoRobots = SeoHelper::robots($pageKey);
    $seoAuthor = SeoHelper::author();
    $canonicalUrl = SeoHelper::currentCanonicalUrl();
    $faqItems = is_array($faqItems ?? null) ? $faqItems : [];
@endphp

<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
@if($seoKeywords !== '')
<meta name="keywords" content="{{ $seoKeywords }}">
@endif
@if($seoAuthor !== '')
<meta name="author" content="{{ $seoAuthor }}">
@endif
@if($seoRobots !== '')
<meta name="robots" content="{{ $seoRobots }}">
@endif
<link rel="canonical" href="{{ $canonicalUrl }}">
{!! SeoHelper::hreflangTags($pageKey) !!}
{!! SeoHelper::faviconMetaTags() !!}

{!! SeoHelper::ogTags($resolvedPageTitle, $pageKey) !!}
{!! SeoHelper::twitterCard($resolvedPageTitle, $pageKey) !!}
{!! SeoHelper::verificationTags() !!}
{!! SeoHelper::structuredData($faqItems, $resolvedPageTitle, $pageKey) !!}
{!! SeoHelper::analyticsScript() !!}
{!! SeoHelper::facebookPixelScript() !!}
{!! SeoHelper::llmsDiscoveryTag() !!}

@if(SeoHelper::customCss())
<style>{!! SeoHelper::customCss() !!}</style>
@endif

