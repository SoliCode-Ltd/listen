<?php

use App\Http\Controllers\AdminClonedVoiceAudioController;
use App\Http\Controllers\AdminManualPaymentProofController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ManualPaymentController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NewsletterUnsubscribeController;
use App\Http\Controllers\VoiceCloningController;
use App\Http\Controllers\VoiceDesignController;
use App\Models\SiteSetting;
use App\Support\AgentDiscovery;
use App\Support\SeoHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

// Audio files need no session, cookies or form token: playing one starts
// sooner, a player or the extension asking without cookies no longer makes
// a new session each time, and caches keep the file (a reply that sets a
// cookie is never cached).
Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \App\Http\Middleware\EnsureUserIsNotBlocked::class,
        \App\Http\Middleware\CaptureAdClickId::class,
    ])
    ->name('media.file');

// A member's own audio in a file type the browser cannot make (M4A, OGG, FLAC).
Route::middleware('auth')->get('/audio-download', \App\Http\Controllers\AudioDownloadController::class)
    ->name('audio.download');

Route::middleware('auth')->get('/manual-payment-proof/{email}/{proof_token}', AdminManualPaymentProofController::class)
    ->name('admin.manual-payments.proof');

Route::middleware('auth')->get('/admin-audio/cloned-voices/{voice}/{kind}', AdminClonedVoiceAudioController::class)
    ->whereNumber('voice')
    ->whereIn('kind', ['sample', 'consent'])
    ->name('admin.cloned-voices.audio');

Route::middleware('auth')->get('/admin-video/dubs/{dub}/{kind}', \App\Http\Controllers\AdminVideoDubFileController::class)
    ->whereNumber('dub')
    ->whereIn('kind', ['clean', 'preview'])
    ->name('admin.video-dubs.file');

// Video Dubbing → Settings: connect the site's Google Drive, where the dubbed videos are kept.
// The callback's address is the one added to the Google client's redirect addresses.
Route::middleware('auth')->get('/admin-drive/google/connect', [\App\Http\Controllers\AdminDriveController::class, 'connect'])
    ->name('admin.drive.connect');
Route::middleware('auth')->get('/admin-drive/google/callback', [\App\Http\Controllers\AdminDriveController::class, 'callback'])
    ->name('admin.drive.callback');

// SEO: Sitemap. Building it reads every public page (about 170 addresses) and takes seconds,
// longer than search engines and AI crawlers wait, so the finished file is kept for an hour
// (or until a setting changes).
Route::get('/sitemap.xml', function () {
    $xml = Cache::remember(SeoHelper::SITEMAP_CACHE, now()->addHour(), function (): string {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $xsl = $dom->createProcessingInstruction('xml-stylesheet', 'type="text/xsl" href="/sitemap.xsl"');
        $dom->appendChild($xsl);

        $urlset = $dom->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');
        $urlset->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
        $dom->appendChild($urlset);

        foreach (SeoHelper::publicSitemapEntries() as $entry) {
            $urlNode = $dom->createElement('url');
            $urlset->appendChild($urlNode);

            $locNode = $dom->createElement('loc');
            $locNode->appendChild($dom->createTextNode((string) $entry['url']));
            $urlNode->appendChild($locNode);

            foreach ((array) ($entry['alternates'] ?? []) as $locale => $alternateUrl) {
                $alternateNode = $dom->createElementNS('http://www.w3.org/1999/xhtml', 'xhtml:link');
                $alternateNode->setAttribute('rel', 'alternate');
                $alternateNode->setAttribute('hreflang', (string) $locale);
                $alternateNode->setAttribute('href', (string) $alternateUrl);
                $urlNode->appendChild($alternateNode);
            }

            $lastmodNode = $dom->createElement('lastmod');
            $lastmodValue = $entry['lastmod'] instanceof Carbon
                ? $entry['lastmod']->toAtomString()
                : Carbon::parse((string) $entry['lastmod'])->toAtomString();
            $lastmodNode->appendChild($dom->createTextNode($lastmodValue));
            $urlNode->appendChild($lastmodNode);

            $changeFreqNode = $dom->createElement('changefreq');
            $changeFreqNode->appendChild($dom->createTextNode((string) $entry['change_frequency']));
            $urlNode->appendChild($changeFreqNode);

            $priorityNode = $dom->createElement('priority');
            $priorityNode->appendChild($dom->createTextNode(number_format((float) $entry['priority'], 1, '.', '')));
            $urlNode->appendChild($priorityNode);
        }

        return (string) $dom->saveXML();
    });

    return response($xml, 200, [
        'Content-Type' => 'application/xml; charset=UTF-8',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->name('sitemap');

// SEO: robots.txt
Route::get('/robots.txt', function () {
    $content = (string) SiteSetting::get('robots_txt', SeoHelper::defaultRobotsTxt());
    return response($content, 200)->header('Content-Type', 'text/plain');
})->name('robots');

// SEO: IndexNow key file (Bing, Yandex, Naver, Seznam). The key is made by `php artisan seo:indexnow`.
Route::get('/indexnow-key.txt', function () {
    $key = trim((string) SiteSetting::get('indexnow_key', ''));
    abort_if($key === '', 404);

    return response($key, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
})->name('indexnow.key');

// SEO: llms.txt
Route::get('/llms.txt', function () {
    if (!SeoHelper::llmsTxtEnabled()) {
        abort(404);
    }

    return response(SeoHelper::llmsTxtContent() . "\n", 200)
        ->header('Content-Type', 'text/plain; charset=UTF-8');
})->name('llms');

// AI agents: the API catalog (RFC 9727), the agent skill for the developer API (listed in the
// skills index with its digest), the ARD catalog of all of these, and auth.md, which says how
// a person gets an agent its key (App\Support\AgentDiscovery).
Route::get('/.well-known/api-catalog', function () {
    return response()->json(AgentDiscovery::apiCatalog(), 200, [
        'Content-Type' => 'application/linkset+json; profile="'.AgentDiscovery::CATALOG_PROFILE.'"',
        'Link' => '<'.url('/.well-known/api-catalog').'>; rel="api-catalog"',
        'Access-Control-Allow-Origin' => '*',
        'Cache-Control' => 'public, max-age=3600',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
})->name('agents.api-catalog');

Route::get('/.well-known/agent-skills/index.json', function () {
    return response()->json(AgentDiscovery::skillsIndex(), 200, [
        'Access-Control-Allow-Origin' => '*',
        'Cache-Control' => 'public, max-age=3600',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
})->name('agents.skills');

Route::get('/.well-known/agent-skills/'.AgentDiscovery::SKILL.'/SKILL.md', function () {
    return response(AgentDiscovery::skillMarkdown(), 200, [
        'Content-Type' => 'text/markdown; charset=UTF-8',
        'Access-Control-Allow-Origin' => '*',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('agents.skill');

Route::get('/.well-known/ai-catalog.json', function () {
    return response()->json(AgentDiscovery::aiCatalog(), 200, [
        'Access-Control-Allow-Origin' => '*',
        'Cache-Control' => 'public, max-age=3600',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
})->name('agents.ai-catalog');

Route::get('/auth.md', function () {
    return response(AgentDiscovery::authMarkdown(), 200, [
        'Content-Type' => 'text/markdown; charset=UTF-8',
        'Access-Control-Allow-Origin' => '*',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('agents.auth');

Route::get('/site.webmanifest', function () {
    return response()->json(
        SeoHelper::siteWebmanifest(),
        200,
        ['Content-Type' => 'application/manifest+json; charset=UTF-8']
    );
})->name('site.webmanifest');

Route::get('/favicon.ico', function () {
    $target = SeoHelper::versionedPrimaryFaviconUrl();

    abort_if($target === '', 404);

    return redirect()->away($target, 301);
})->name('favicon');

// A signed link: the controller checks the signature itself, so the https links
// in emails still work when the proxy hands the request over as http.
Route::get('/newsletter/unsubscribe', NewsletterUnsubscribeController::class)
    ->name('newsletter.unsubscribe');

Route::group([
    'prefix' => LaravelLocalization::setLocale(),
    'middleware' => ['localize', 'localeSessionRedirect', 'localizationRedirect'],
], function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('/pricing', [HomeController::class, 'pricingPage'])->name('pricing');
    Route::get('/voice-samples', [HomeController::class, 'samplesPage'])->name('samples');
    Route::get('/free-trial', [HomeController::class, 'startFreeTrial'])->name('trial.start');
    Route::get('/premium-pack', [\App\Http\Controllers\CharPackController::class, 'start'])->name('pack.start');
    Route::get('/ai-text-to-speech', [HomeController::class, 'seoLandingPage'])->defaults('page', 'ai_text_to_speech')->name('seo.ai-text-to-speech');
    Route::get('/ai-voice-generator', [HomeController::class, 'seoLandingPage'])->defaults('page', 'ai_voice_generator')->name('seo.ai-voice-generator');
    Route::get('/text-to-speech-arabic', [HomeController::class, 'seoLandingPage'])->defaults('page', 'text_to_speech_arabic')->name('seo.text-to-speech-arabic');
    Route::get('/text-to-speech', [HomeController::class, 'seoLandingPage'])->defaults('page', 'text_to_speech')->name('seo.text-to-speech');
    Route::get('/arabic-dialects', [HomeController::class, 'dialectHubPage'])->name('seo.arabic-dialects');
    Route::get('/arabic-dialects/{dialect}', [HomeController::class, 'dialectPage'])
        ->whereIn('dialect', \App\Support\SeoDialectPages::slugs())
        ->name('seo.dialect');
    Route::get('/english-accents', [HomeController::class, 'accentHubPage'])->name('seo.english-accents');
    Route::get('/english-accents/{accent}', [HomeController::class, 'accentPage'])
        ->whereIn('accent', \App\Support\SeoEnglishPages::slugs())
        ->name('seo.accent');
    Route::view('/privacy-policy', 'legal.privacy')->name('legal.privacy');
    Route::view('/terms-of-service', 'legal.terms')->name('legal.terms');
    Route::view('/cookie-policy', 'legal.cookies')->name('legal.cookies');
    Route::get('/browsers-extension', [ExtensionController::class, 'show'])->name('browsers-extension.page');
    Route::get('/developers', [\App\Http\Controllers\DeveloperController::class, 'docs'])->name('developers');

    Route::get('/contact-us', [\App\Http\Controllers\ContactController::class, 'show'])->name('contact');
    Route::post('/contact-us', [\App\Http\Controllers\ContactController::class, 'submit'])->middleware('throttle:contact')->name('contact.submit');
    Route::get('/contact', fn () => redirect()->route('contact', status: 301));
    Route::post('/contact', [\App\Http\Controllers\ContactController::class, 'submit'])->middleware('throttle:contact');

    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware(['auth.method:email', 'throttle:login'])
            ->name('login.submit');

        Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware(['auth.method:email', 'throttle:register'])
            ->name('register.submit');

        Route::get('/auth/social/redirect', [AuthController::class, 'redirectToGoogle'])
            ->middleware('auth.method:google')
            ->name('auth.social.redirect');
        Route::get('/auth/social/callback', [AuthController::class, 'handleGoogleCallback'])
            ->middleware('auth.method:google')
            ->name('auth.social.callback');
        // Backward-compatible aliases for existing OAuth app callback URLs.
        Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle'])
            ->middleware('auth.method:google');
        Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])
            ->middleware('auth.method:google');

        Route::post('/auth/phone/verify', [AuthController::class, 'phoneAuthenticate'])
            ->middleware(['auth.method:phone', 'throttle:auth-bridge'])
            ->name('auth.phone.verify');

        Route::post('/auth/firebase-google/verify', [AuthController::class, 'firebaseGoogleAuthenticate'])
            ->middleware(['auth.method:firebase_google', 'throttle:auth-bridge'])
            ->name('auth.firebase-google.verify');
    });

    // Confirming the email of an account made with an email and a password. The
    // link works whether or not the member is signed in, and confirms only the
    // account it was made for; a new link is a few an hour.
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->whereNumber('id')
        ->where('hash', '[0-9a-f]{40}')
        ->middleware('throttle:10,1')
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware(['auth', 'throttle:verify-email'])
        ->name('verification.send');

    // Referral capture runs BEFORE auth so guests landing on the demo page
    // with ?ref=CODE still record the visit and get the referral cookie set
    // before the login redirect.
    Route::middleware(['referral.capture'])->group(function () {
        Route::middleware(['auth', 'subscription.sync', 'demo.free'])->group(function () {
            Route::get('/demo', [HomeController::class, 'demoPage'])->name('demo.page');
            Route::post('/demo/quota', [HomeController::class, 'demoQuota'])->name('demo.quota');
            // Spending the demo needs a confirmed email (Google and phone sign-ins have one).
            Route::post('/demo', [HomeController::class, 'demo'])->middleware('demo.verified')->name('demo.run');
            Route::post('/preview', [HomeController::class, 'preview'])->middleware('demo.verified')->name('demo.preview');
            // Voice Design's one take, beside the text studio (App\Support\VoiceDesign).
            Route::post('/demo/voice-design', [HomeController::class, 'designVoice'])->middleware(['demo.verified', 'throttle:6,1,demo-voice-design'])->name('demo.voice-design');
        });
    });

    // Dub my video: the page for everyone it is open to; the rest needs an account.
    Route::get('/dub-my-video', [\App\Http\Controllers\VideoDubController::class, 'show'])->name('dub.page');
    Route::middleware(['auth', 'subscription.sync'])->prefix('dub-my-video')->group(function (): void {
        Route::post('/', [\App\Http\Controllers\VideoDubController::class, 'store'])->middleware('throttle:10,1,dub-store')->name('dub.store');
        // The video itself, sent in pieces before the dub is asked for.
        Route::post('/upload', [\App\Http\Controllers\VideoDubController::class, 'startUpload'])->middleware('throttle:10,1,dub-upload')->name('dub.upload.start');
        Route::get('/{code}/status', [\App\Http\Controllers\VideoDubController::class, 'status'])->where('code', '[A-Za-z0-9]{32}')->name('dub.status');
        Route::get('/{code}/watch', [\App\Http\Controllers\VideoDubController::class, 'watch'])->where('code', '[A-Za-z0-9]{32}')->name('dub.watch');
        Route::get('/{code}/download', [\App\Http\Controllers\VideoDubController::class, 'download'])->where('code', '[A-Za-z0-9]{32}')->name('dub.download');
    });
    // Each piece of a video: kept light (no plan check), many a minute for a large one.
    // Each limit counts on its own (the third part names it): the pieces never use up the dub's.
    Route::middleware(['auth', 'throttle:600,1,dub-piece'])->post('/dub-my-video/upload/{id}', [\App\Http\Controllers\VideoDubController::class, 'uploadChunk'])
        ->where('id', '[A-Za-z0-9]{40}')->name('dub.upload.chunk');

    Route::middleware(['auth'])->group(function () {
        Route::get('/referral/stats', [\App\Http\Controllers\ReferralController::class, 'stats'])->name('referral.stats');
        Route::post('/referral/share', [\App\Http\Controllers\ReferralController::class, 'share'])->name('referral.share');
    });

    // What an open studio page asks while it works, every few seconds: these only
    // read, so they skip the billing sync the pages run.
    Route::middleware(['auth'])->group(function () {
        Route::get('/dashboard/jobs/latest', [DashboardController::class, 'latest'])->name('dashboard.jobs.latest');
        Route::get('/dashboard/jobs/older', [DashboardController::class, 'older'])->name('dashboard.jobs.older');
        Route::get('/dashboard/jobs/{job}', [DashboardController::class, 'show'])->whereNumber('job')->name('dashboard.jobs.show');
        Route::get('/dashboard/csrf-token', [DashboardController::class, 'csrfToken'])->name('dashboard.csrf-token');
    });

    Route::middleware(['auth', 'subscription.sync'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/dashboard/queue', [DashboardController::class, 'queue'])->middleware('throttle:studio-generate')->name('dashboard.queue');
        // The member's pronunciations, from the studio's panel.
        Route::post('/dashboard/pronunciations', [\App\Http\Controllers\PronunciationController::class, 'store'])->middleware('throttle:60,1')->name('dashboard.pronunciations.save');
        Route::delete('/dashboard/pronunciations/{pronunciation}', [\App\Http\Controllers\PronunciationController::class, 'destroy'])->whereNumber('pronunciation')->middleware('throttle:60,1')->name('dashboard.pronunciations.delete');
        Route::post('/dashboard/generate', [DashboardController::class, 'queue'])->middleware('throttle:studio-generate')->name('dashboard.generate');
        Route::delete('/dashboard/jobs/{job}', [DashboardController::class, 'destroy'])->name('dashboard.jobs.destroy');
        Route::delete('/dashboard/demo-jobs/{generation}', [DashboardController::class, 'destroyDemo'])->name('dashboard.demo-jobs.destroy');
        Route::put('/dashboard/favorite-voices', [\App\Http\Controllers\FavoriteVoicesController::class, 'update'])->name('dashboard.favorite-voices');

        Route::get('/billing', [BillingController::class, 'index'])->name('billing.page');
        Route::get('/billing/pack', [\App\Http\Controllers\CharPackController::class, 'show'])->name('billing.pack');
        Route::post('/billing/pack/card', [\App\Http\Controllers\CharPackController::class, 'payByCard'])->middleware('throttle:10,1')->name('billing.pack.card');
        Route::post('/billing/pack/card/confirm', [\App\Http\Controllers\CharPackController::class, 'confirmCard'])->middleware('throttle:20,1')->name('billing.pack.card.confirm');
        Route::get('/billing/manual', [ManualPaymentController::class, 'instructions'])->name('billing.manual.instructions');
        Route::post('/billing/manual/confirm', [ManualPaymentController::class, 'confirm'])->name('billing.manual.confirm');
        Route::get('/manage-subscription', [BillingController::class, 'manage'])->name('manage-subscription.page');
        Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
        Route::post('/billing/plan-change/preview', [BillingController::class, 'planChangePreview'])->middleware('throttle:30,1')->name('billing.plan-change.preview');
        Route::post('/billing/trial/setup', [\App\Http\Controllers\TrialBillingController::class, 'setup'])->name('billing.trial.setup');
        Route::post('/billing/trial/confirm', [\App\Http\Controllers\TrialBillingController::class, 'confirm'])->name('billing.trial.confirm');
        Route::post('/billing/payment-method/intent', [BillingController::class, 'paymentMethodIntent'])->name('billing.payment-method.intent');
        Route::post('/billing/payment-method', [BillingController::class, 'updatePaymentMethod'])->name('billing.payment-method');
        Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
        Route::post('/billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
        Route::post('/billing/sync', [BillingController::class, 'sync'])->name('billing.sync');
        Route::get('/billing/invoices/{invoiceId}/view', [BillingController::class, 'viewInvoice'])->name('billing.invoice.view');
        Route::get('/billing/invoices/{invoiceId}/download', [BillingController::class, 'downloadInvoice'])->name('billing.invoice.download');

        Route::post('/browsers-extension/api-key/regenerate', [ExtensionController::class, 'regenerateApiKey'])->name('browsers-extension.key.regenerate');
        Route::post('/browsers-extension/api-key/revoke', [ExtensionController::class, 'revokeApiKey'])->name('browsers-extension.key.revoke');
        Route::get('/browsers-extension/download', [ExtensionController::class, 'download'])->name('browsers-extension.download');

        // Developer API keys (the API itself is routes/v1.php).
        Route::get('/dashboard/api-keys', [\App\Http\Controllers\DeveloperController::class, 'keys'])->name('developers.keys');
        Route::post('/dashboard/api-keys', [\App\Http\Controllers\DeveloperController::class, 'store'])->middleware('throttle:20,1')->name('developers.keys.store');
        Route::put('/dashboard/api-keys/{apiKey}', [\App\Http\Controllers\DeveloperController::class, 'update'])->middleware('throttle:30,1')->name('developers.keys.update');
        Route::delete('/dashboard/api-keys/{apiKey}', [\App\Http\Controllers\DeveloperController::class, 'revoke'])->middleware('throttle:30,1')->name('developers.keys.revoke');

        // Voice Cloning lives under dashboard for signed-in users.
        Route::prefix('dashboard/voice-cloning')->group(function (): void {
            // Page accessible to all auth users (free users see upgrade prompt).
            Route::get('/', [VoiceCloningController::class, 'index'])->name('voice-cloning.index');
            // Actions require paid plan (enforced in controller).
            Route::post('/', [VoiceCloningController::class, 'store'])->name('voice-cloning.store');
            Route::get('/status/{id}', [VoiceCloningController::class, 'status'])->name('voice-cloning.status');
            Route::post('/{id}/retry', [VoiceCloningController::class, 'retry'])->name('voice-cloning.retry');
            Route::post('/{id}/consent', [VoiceCloningController::class, 'replaceConsent'])->name('voice-cloning.consent');
            Route::delete('/{id}', [VoiceCloningController::class, 'destroy'])->name('voice-cloning.destroy');
        });

        // Voice Design: its own section of the dashboard. Who sees it and who may design: App\Support\VoiceDesign.
        Route::prefix('dashboard/voice-design')->group(function (): void {
            Route::get('/', [VoiceDesignController::class, 'index'])->name('voice-design.index');
            // "Write it for me" is text only, so the demo's members may use it too.
            Route::post('/enhance', [VoiceDesignController::class, 'enhance'])->middleware('throttle:20,1,voice-design-enhance')->name('voice-design.enhance');
            Route::post('/', [VoiceDesignController::class, 'design'])->middleware('throttle:10,1,voice-design')->name('voice-design.design');
            Route::post('/{id}/keep', [VoiceDesignController::class, 'keep'])->whereNumber('id')->middleware('throttle:30,1')->name('voice-design.keep');
            Route::delete('/{id}', [VoiceDesignController::class, 'destroy'])->whereNumber('id')->middleware('throttle:30,1')->name('voice-design.destroy');
        });

        // Legacy user URL kept as redirect to dashboard voice-cloning section.
        Route::get('/voice-cloning', function () {
            $user = auth()->user();
            $plan = app(\App\Services\PlanAccessService::class)->resolveActivePlanForUserId((int) $user->id);

            if (! \App\Support\VoiceCloningAccess::allows($user, $plan) && ! \App\Support\VoiceCloningAccess::showsUpgrade($user, $plan)) {
                return redirect()->route('dashboard');
            }

            return redirect()->to(route('dashboard') . '#voiceCloningSection', 301);
        });
    });
});
