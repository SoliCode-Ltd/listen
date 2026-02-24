{{-- SEO Meta Tags — Include in <head> of every public page --}}
{{-- Usage: @include('components.seo', ['pageTitle' => 'Pricing', 'page' => 'pricing']) --}}
@php
    use App\Support\SeoHelper;
    use Illuminate\Support\Facades\Storage;

    $seoTitle = isset($pageTitle) ? SeoHelper::title($pageTitle, $page ?? '') : SeoHelper::title();
    $seoDescription = SeoHelper::description($page ?? '');
    $canonicalUrl = SeoHelper::canonicalUrl(request()->path() === '/' ? '' : request()->path());
    $defaultTheme = SeoHelper::defaultTheme();
    $faviconPath = trim((string) \App\Models\SiteSetting::get('favicon_url', ''));
    $logoUrl = \App\Models\SiteSetting::get('logo_url', '');

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
<link rel="canonical" href="{{ $canonicalUrl }}">

<link rel="icon" href="{{ $faviconWithVersion }}">
<link rel="shortcut icon" href="{{ $faviconWithVersion }}">

{!! SeoHelper::ogTags($pageTitle ?? '', $page ?? '') !!}
{!! SeoHelper::twitterCard($pageTitle ?? '', $page ?? '') !!}
{!! SeoHelper::structuredData() !!}
{!! SeoHelper::analyticsScript() !!}

@if(SeoHelper::customCss())
<style>{!! SeoHelper::customCss() !!}</style>
@endif
