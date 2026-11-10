package studio.listenai.app;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertThrows;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

/** Which addresses the app treats as "the site" (WebView, cookies, native downloads). */
public class AppUrlsTest {

    private final AppUrls urls = new AppUrls("https://listenai.studio");

    @Test
    public void acceptsHttpsOnTheConfiguredHostOnly() {
        assertTrue(urls.isAppUrl("https://listenai.studio"));
        assertTrue(urls.isAppUrl("https://listenai.studio/"));
        assertTrue(urls.isAppUrl("https://listenai.studio/ar/dashboard?tab=1#x"));
        assertTrue(urls.isAppUrl("HTTPS://LISTENAI.STUDIO/en/demo"));
        assertTrue(urls.isAppUrl("https://listenai.studio:443/app/start"));
    }

    @Test
    public void refusesEverythingElse() {
        assertFalse(urls.isAppUrl(null));
        assertFalse(urls.isAppUrl(""));
        assertFalse(urls.isAppUrl("http://listenai.studio/ar/dashboard")); // not https
        assertFalse(urls.isAppUrl("https://www.listenai.studio/")); // other host
        assertFalse(urls.isAppUrl("https://staging.listenai.studio/"));
        assertFalse(urls.isAppUrl("https://listenai.studio.evil.example/"));
        assertFalse(urls.isAppUrl("https://evil.example/listenai.studio"));
        assertFalse(urls.isAppUrl("https://listenai.studio@evil.example/")); // user-info trick
        assertFalse(urls.isAppUrl("https://user:pw@listenai.studio/"));
        assertFalse(urls.isAppUrl("https://evil.example\\@listenai.studio/")); // backslash trick
        assertFalse(urls.isAppUrl("https://listenai.studio:8443/")); // other port
        assertFalse(urls.isAppUrl("https://listenai.studio/a b")); // raw space
        assertFalse(urls.isAppUrl("javascript:alert(1)"));
        assertFalse(urls.isAppUrl("file:///data/data/x"));
        assertFalse(urls.isAppUrl("studio.listenai.app://signin?code=1"));
        assertFalse(urls.isAppUrl("//listenai.studio/x"));
    }

    @Test
    public void resolvesPathsAgainstTheSite() {
        assertEquals("https://listenai.studio/audio/5/download", urls.resolve("/audio/5/download"));
        assertEquals("https://listenai.studio/x?y=1", urls.resolve(" https://listenai.studio/x?y=1 "));
        assertNull(urls.resolve("//evil.example/x")); // protocol-relative
        assertNull(urls.resolve("https://evil.example/x"));
        assertNull(urls.resolve("audio/5"));
        assertNull(urls.resolve(""));
        assertNull(urls.resolve(null));
    }

    @Test
    public void extractsPathAndQuery() {
        assertEquals("/", urls.pathAndQuery("https://listenai.studio"));
        assertEquals("/ar/dashboard?tab=voices#top", urls.pathAndQuery("https://listenai.studio/ar/dashboard?tab=voices#top"));
        assertEquals("/app/signin/complete?code=a%2Bb&verifier=v", urls.pathAndQuery("https://listenai.studio/app/signin/complete?code=a%2Bb&verifier=v"));
    }

    @Test
    public void buildsTheFixedAppRoutes() {
        assertEquals("https://listenai.studio/app/start", urls.startUrl());
        assertEquals("https://listenai.studio/app/signin/start?challenge=abc-_1&locale=ar", urls.signInStartUrl("abc-_1", "ar"));
        assertEquals("https://listenai.studio/app/signin/start?challenge=c&locale=en", urls.signInStartUrl("c", "fr"));
        // The code comes from outside the app: it must not be able to add parameters.
        assertEquals(
            "https://listenai.studio/app/signin/complete?code=a%26verifier%3Dx%23&verifier=v1",
            urls.signInCompleteUrl("a&verifier=x#", "v1")
        );
    }

    @Test
    public void knowsTheBrowserLegOfSignIn() {
        assertTrue(urls.isSignInUrl("https://listenai.studio/app/signin/start?challenge=x"));
        assertTrue(urls.isSignInUrl("https://listenai.studio/app/signin/complete?code=x"));
        assertFalse(urls.isSignInUrl("https://listenai.studio/app/start"));
        assertFalse(urls.isSignInUrl("https://listenai.studio/ar/login"));
    }

    @Test
    public void worksForAStagingHost() {
        AppUrls staging = new AppUrls("https://staging.example.test/");
        assertEquals("staging.example.test", staging.host());
        assertEquals("https://staging.example.test/app/start", staging.startUrl());
        assertTrue(staging.isAppUrl("https://staging.example.test/en/dashboard"));
        assertFalse(staging.isAppUrl("https://listenai.studio/en/dashboard"));
    }

    @Test
    public void refusesANonHttpsSite() {
        assertThrows(IllegalArgumentException.class, () -> new AppUrls("http://listenai.studio"));
    }

    @Test
    public void normalisesLocales() {
        assertEquals("ar", AppUrls.normalizeLocale("ar"));
        assertEquals("ar", AppUrls.normalizeLocale("AR-sa"));
        assertEquals("en", AppUrls.normalizeLocale("en"));
        assertEquals("en", AppUrls.normalizeLocale(null));
        assertEquals("en", AppUrls.normalizeLocale("de"));
    }
}
