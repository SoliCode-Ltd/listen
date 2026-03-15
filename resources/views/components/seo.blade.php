{{-- SEO Meta Tags - Include in <head> of every public page --}}
{{-- Usage: @include('components.seo', ['pageTitle' => 'Pricing', 'page' => 'pricing']) --}}
@php
    use App\Support\SeoHelper;
    use Illuminate\Support\Facades\Storage;

    $pageKey = $page ?? '';
    $resolvedPageTitle = isset($pageTitle) ? (string) $pageTitle : '';
    $seoTitle = SeoHelper::title($resolvedPageTitle, $pageKey);
    $seoDescription = SeoHelper::description($pageKey);
    $seoKeywords = SeoHelper::keywords($pageKey);
    $seoRobots = SeoHelper::robots($pageKey);
    $seoAuthor = SeoHelper::author();
    $canonicalUrl = SeoHelper::canonicalUrl(request()->path() === '/' ? '' : request()->path());
    $faviconPath = trim((string) \App\Models\SiteSetting::get('favicon_url', ''));

    if ($faviconPath !== '') {
        $faviconHref = Storage::disk('public')->url($faviconPath);
        $faviconFilePath = Storage::disk('public')->path($faviconPath);
    } else {
        $faviconHref = asset('icons/headphones.svg');
        $faviconFilePath = public_path('icons/headphones.svg');
    }

    $faviconVersion = file_exists($faviconFilePath) ? filemtime($faviconFilePath) : null;
    $faviconWithVersion = $faviconHref . ($faviconVersion ? ('?v=' . $faviconVersion) : '');
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

<link rel="icon" href="{{ $faviconWithVersion }}">
<link rel="shortcut icon" href="{{ $faviconWithVersion }}">

{!! SeoHelper::ogTags($resolvedPageTitle, $pageKey) !!}
{!! SeoHelper::twitterCard($resolvedPageTitle, $pageKey) !!}
{!! SeoHelper::verificationTags() !!}
{!! SeoHelper::structuredData() !!}
{!! SeoHelper::analyticsScript() !!}
{!! SeoHelper::facebookPixelScript() !!}
{!! SeoHelper::llmsDiscoveryTag() !!}

@if(SeoHelper::customCss())
<style>{!! SeoHelper::customCss() !!}</style>
@endif

