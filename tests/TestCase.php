<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests must never reach real services (Google, Gemini, Stripe, geo-IP):
        // any HTTP call that is not faked fails the test instead of leaving the machine.
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }

    /**
     * Generate a localized URL for the given route name.
     *
     * In test context, LaravelLocalization::setLocale() resolves to ''
     * (no prefix) during route registration, so routes are at '/', '/contact-us', etc.
     * The localization middleware then redirects bare URLs to /{locale}/...
     * which results in 404 (no route registered at /en/...).
     *
     * This helper simply returns the non-prefixed path for the given route name
     * and disables the localization redirect middleware for the request.
     */
    protected function localizedRoute(string $name, array $parameters = []): string
    {
        return parse_url(route($name, $parameters), PHP_URL_PATH) ?: '/';
    }

    /**
     * GET request to a localized route, skipping locale-redirect middleware
     * that would otherwise 302 to a route prefix that doesn't exist in tests.
     */
    protected function getLocalizedRoute(string $routeName, array $parameters = []): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->get($this->localizedRoute($routeName, $parameters));
    }

    /**
     * POST request to a localized route, skipping locale-redirect middleware.
     */
    protected function postLocalizedRoute(string $routeName, array $data = [], array $parameters = []): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            ValidateCsrfToken::class,
        ])->post($this->localizedRoute($routeName, $parameters), $data);
    }

    /**
     * POST JSON request to a localized route, skipping locale-redirect middleware.
     */
    protected function postJsonLocalizedRoute(string $routeName, array $data = [], array $parameters = []): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            ValidateCsrfToken::class,
        ])->postJson($this->localizedRoute($routeName, $parameters), $data);
    }
}
