@php
  use Illuminate\Support\Facades\Storage;

  $currentYear = date('Y');
  $siteName = \App\Models\SiteSetting::get('site_name', 'Listen');
  $currentLocale = app()->getLocale();
  $brandName = str_starts_with(strtolower((string) $currentLocale), 'ar') ? 'منصة إسمع' : $siteName;
  $logoPath = trim((string) \App\Models\SiteSetting::get('logo_url', ''));
  $logoUrl = null;
  $showSocial = (string) \App\Models\SiteSetting::get('footer_social_enabled', '1') === '1';
  $twitterUrl = trim((string) \App\Models\SiteSetting::get('footer_social_twitter_url', ''));
  $githubUrl = trim((string) \App\Models\SiteSetting::get('footer_social_github_url', ''));
  $facebookUrl = trim((string) \App\Models\SiteSetting::get('footer_social_facebook_url', ''));
  $linkedinUrl = trim((string) \App\Models\SiteSetting::get('footer_social_linkedin_url', ''));

  $socialLinks = [];
  if ($showSocial) {
    $rawSocialLinks = [
      ['label' => 'Twitter', 'url' => $twitterUrl, 'icon' => 'twitter'],
      ['label' => 'GitHub', 'url' => $githubUrl, 'icon' => 'github'],
      ['label' => 'Facebook', 'url' => $facebookUrl, 'icon' => 'facebook'],
      ['label' => 'LinkedIn', 'url' => $linkedinUrl, 'icon' => 'linkedin'],
    ];

    // Only the accounts set in the admin: an icon that goes nowhere looks unfinished.
    foreach ($rawSocialLinks as $item) {
      if ($item['url'] !== '' && filter_var($item['url'], FILTER_VALIDATE_URL)) {
        $socialLinks[] = $item;
      }
    }
  }

  if ($logoPath !== '') {
    if (filter_var($logoPath, FILTER_VALIDATE_URL)) {
      $logoUrl = $logoPath;
    } elseif (Storage::disk('public')->exists($logoPath)) {
      $logoUrl = Storage::disk('public')->url($logoPath);
    }
  }

  $showDemoFooterLink = true;
  if (auth()->check()) {
    $activePlan = app(\App\Services\PlanAccessService::class)->resolveActivePlanForUserId((int) auth()->id());
    $showDemoFooterLink = strtolower(trim((string) ($activePlan?->slug ?? 'free'))) === 'free';
  }
@endphp

<footer class="app-footer">
  @php
    $hasSocialLinks = !empty($socialLinks);
  @endphp
  <div class="footer-container">
    <div class="footer-grid">
      <div class="footer-brand">
        <a class="brand" href="{{ route('home') }}" aria-label="{{ __('ui.header.brand_home', ['name' => $brandName]) }}">
          @if($logoUrl)
            <img src="{{ $logoUrl }}" alt="{{ $brandName }}" style="max-height: 32px; width: auto;" />
          @else
            @if($currentLocale === 'ar')
              <img src="{{ \App\Support\VersionedAsset::url('images/logo-ar-dark.svg') }}" alt="منصة إسمع" class="site-logo logo-dark">
              <img src="{{ \App\Support\VersionedAsset::url('images/logo-ar-light.svg') }}" alt="منصة إسمع" class="site-logo logo-light">
            @else
              <img src="{{ \App\Support\VersionedAsset::url('images/logo-dark.svg') }}" alt="Listen" class="site-logo logo-dark">
              <img src="{{ \App\Support\VersionedAsset::url('images/logo-light.svg') }}" alt="Listen" class="site-logo logo-light">
            @endif
          @endif
        </a>
        <p class="footer-tagline">{{ __('ui.footer.tagline') }}</p>
      </div>

      <div class="footer-links">
        <div class="footer-column">
          <h4>{{ __('ui.footer.product') }}</h4>
          <ul>
            <li><a href="{{ route('samples') }}">{{ __('ui.nav.samples') }}</a></li>
            <li><a href="{{ route('pricing') }}">{{ __('ui.nav.pricing') }}</a></li>
            @if($showDemoFooterLink)
              <li><a href="{{ route('demo.page') }}">{{ __('ui.footer.demo') }}</a></li>
            @endif
            <li><a href="{{ route('browsers-extension.page') }}">{{ __('ui.nav.extension') }}</a></li>
            <li><a href="{{ route('developers') }}">{{ __('developers.footer_link') }}</a></li>
            {{-- The two SEO hubs: they link every dialect, accent and solution page, which are also in the sitemap. --}}
            <li><a href="{{ route('seo.arabic-dialects') }}">{{ __('ui.nav.arabic_dialects') }}</a></li>
            <li><a href="{{ route('seo.english-accents') }}">{{ __('ui.nav.english_accents') }}</a></li>
          </ul>
        </div>

        <div class="footer-column">
          <h4>{{ __('ui.footer.legal') }}</h4>
          <ul>
            <li><a href="{{ route('legal.privacy') }}">{{ __('ui.footer.privacy') }}</a></li>
            <li><a href="{{ route('legal.terms') }}">{{ __('ui.footer.terms') }}</a></li>
            <li><a href="{{ route('legal.cookies') }}">{{ __('ui.footer.cookies') }}</a></li>
          </ul>
        </div>
      </div>
    </div>

    <div class="footer-bottom {{ $hasSocialLinks ? '' : 'single' }}">
      <div class="footer-copyright">
        <p>{{ __('ui.footer.copyright', ['year' => $currentYear, 'name' => $brandName]) }}</p>
      </div>
      @if($hasSocialLinks)
        <div class="footer-social">
          @foreach($socialLinks as $social)
            <a href="{{ $social['url'] }}" aria-label="{{ $social['label'] }}" class="social-link" target="_blank" rel="noopener noreferrer">
              @if($social['icon'] === 'twitter')
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg>
              @elseif($social['icon'] === 'github')
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.3 3.44 9.8 8.2 11.38.6.11.82-.26.82-.58v-2.03c-3.34.73-4.04-1.42-4.04-1.42-.55-1.39-1.34-1.76-1.34-1.76-1.09-.75.08-.73.08-.73 1.2.08 1.84 1.24 1.84 1.24 1.07 1.83 2.8 1.3 3.49 1 .11-.78.42-1.3.76-1.6-2.67-.3-5.47-1.33-5.47-5.93 0-1.31.47-2.38 1.24-3.22-.13-.3-.54-1.52.12-3.18 0 0 1-.32 3.3 1.23a11.5 11.5 0 0 1 6 0c2.28-1.55 3.28-1.23 3.28-1.23.66 1.66.25 2.88.12 3.18a4.64 4.64 0 0 1 1.23 3.22c0 4.61-2.8 5.63-5.48 5.92.43.37.82 1.1.82 2.22v3.29c0 .32.21.7.82.58C20.57 21.8 24 17.3 24 12c0-6.63-5.37-12-12-12z"/></svg>
              @elseif($social['icon'] === 'facebook')
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.77-3.89 1.09 0 2.23.2 2.23.2v2.46h-1.25c-1.23 0-1.62.76-1.62 1.55V12h2.76l-.44 2.89h-2.32v6.99A10 10 0 0 0 22 12z"/></svg>
              @else
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.34V9h3.42v1.56h.05a3.74 3.74 0 0 1 3.37-1.85c3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.07 2.07 0 1 1 0-4.14 2.07 2.07 0 0 1 0 4.14zm1.78 13.02H3.56V9h3.56v11.45zM22.22 0H1.78C.8 0 0 .8 0 1.78v20.44C0 23.2.8 24 1.78 24h20.44c.98 0 1.78-.8 1.78-1.78V1.78C24 .8 23.2 0 22.22 0z"/></svg>
              @endif
            </a>
          @endforeach
        </div>
      @endif
    </div>
  </div>
</footer>
