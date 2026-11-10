package studio.listenai.app;

import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.net.Uri;
import androidx.browser.customtabs.CustomTabColorSchemeParams;
import androidx.browser.customtabs.CustomTabsIntent;

/**
 * Sign-in through the phone's browser (some sign-in providers refuse to work inside an
 * in-app WebView).
 *
 *   1. start(): make a random verifier, remember it, open the browser tab at
 *      {site}/app/signin/start?challenge=sha256(verifier)&locale=..
 *   2. The site signs the person in and sends the browser to studio.listenai.app://signin?code=..
 *   3. completeUrl(): the app swaps code + verifier for the address that signs the WebView in,
 *      and forgets the verifier. A code without a waiting verifier is ignored.
 */
final class SignIn {

    private static final String PREFS = "listen_signin";
    private static final String KEY_VERIFIER = "verifier";
    private static final String KEY_STARTED_AT = "started_at";
    /** How long a started sign-in stays valid. */
    private static final long MAX_AGE_MS = 15 * 60 * 1000L;
    private static final int MAX_CODE_LENGTH = 512;

    private SignIn() {}

    /** Opens the browser tab. Throws ActivityNotFoundException when the phone has no browser. */
    static void start(Activity activity, AppUrls urls, String locale, boolean dark) throws ActivityNotFoundException {
        String verifier = Pkce.newVerifier();
        prefs(activity).edit().putString(KEY_VERIFIER, verifier).putLong(KEY_STARTED_AT, System.currentTimeMillis()).apply();

        int toolbar = dark ? MainActivity.COLOR_DARK : MainActivity.COLOR_LIGHT;
        CustomTabColorSchemeParams colors = new CustomTabColorSchemeParams.Builder()
            .setToolbarColor(toolbar)
            .setNavigationBarColor(toolbar)
            .build();
        CustomTabsIntent tab = new CustomTabsIntent.Builder()
            .setShowTitle(true)
            .setShareState(CustomTabsIntent.SHARE_STATE_OFF)
            .setColorScheme(dark ? CustomTabsIntent.COLOR_SCHEME_DARK : CustomTabsIntent.COLOR_SCHEME_LIGHT)
            .setDefaultColorSchemeParams(colors)
            .build();
        // Falls back to the default browser when no browser on the phone offers in-app tabs.
        tab.launchUrl(activity, Uri.parse(urls.signInStartUrl(Pkce.challenge(verifier), locale)));
    }

    /**
     * Called with the code from the return link. Returns the address to load in the WebView,
     * or null when no sign-in is waiting (or it is too old, or the code is unusable).
     * The verifier is single-use: it is forgotten here whatever the outcome.
     */
    static String completeUrl(Context context, AppUrls urls, String code) {
        SharedPreferences prefs = prefs(context);
        String verifier = prefs.getString(KEY_VERIFIER, null);
        long startedAt = prefs.getLong(KEY_STARTED_AT, 0);
        if (verifier == null) return null;
        prefs.edit().remove(KEY_VERIFIER).remove(KEY_STARTED_AT).apply();

        long age = System.currentTimeMillis() - startedAt;
        if (age < 0 || age > MAX_AGE_MS) return null;
        if (code == null || code.isEmpty() || code.length() > MAX_CODE_LENGTH) return null;
        return urls.signInCompleteUrl(code, verifier);
    }

    static boolean isPending(Context context) {
        return prefs(context).getString(KEY_VERIFIER, null) != null;
    }

    private static SharedPreferences prefs(Context context) {
        return context.getApplicationContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }
}
