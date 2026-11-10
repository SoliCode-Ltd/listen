package studio.listenai.app;

/** Small pieces of state shared between the activity, the plugin and background work. */
final class ListenRuntime {

    /** The WebView's full user agent (ends with "ListenApp/x.y.z (Android)"). Set on the UI thread at start. */
    static volatile String userAgent;

    private ListenRuntime() {}

    /** The token the site looks for to know a request comes from the app. */
    static String userAgentToken() {
        // A version name is free text; keep only what is safe inside a user-agent product token.
        String version = BuildConfig.VERSION_NAME.replaceAll("[^A-Za-z0-9._-]", "");
        if (version.isEmpty()) version = "0";
        return "ListenApp/" + version + " (Android)";
    }
}
