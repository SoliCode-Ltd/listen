package studio.listenai.app;

import java.net.URI;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.Locale;

/**
 * Everything the app knows about the site's addresses: which URLs belong to the site
 * (and so may be shown in the app's own WebView, receive its cookies, or be downloaded
 * natively) and how the fixed app routes are built.
 *
 * Pure Java on purpose (no Android classes) so it can be unit-tested on the JVM.
 */
public final class AppUrls {

    public static final String START_PATH = "/app/start";
    private static final String SIGN_IN_PREFIX = "/app/signin/";

    private final String origin;
    private final String host;

    /** @param origin the site, e.g. {@code https://listenai.studio} (https, no path). */
    public AppUrls(String origin) {
        String trimmed = origin.trim().replaceAll("/+$", "");
        URI uri = URI.create(trimmed);
        if (!"https".equalsIgnoreCase(uri.getScheme()) || uri.getHost() == null) {
            throw new IllegalArgumentException("The site URL must be https://host");
        }
        this.origin = trimmed;
        this.host = uri.getHost().toLowerCase(Locale.ROOT);
    }

    public String origin() {
        return origin;
    }

    public String host() {
        return host;
    }

    public String startUrl() {
        return origin + START_PATH;
    }

    /** True only for https URLs on exactly the configured host (default port, no user info). */
    public boolean isAppUrl(String url) {
        URI uri = parse(url);
        if (uri == null) return false;
        if (!"https".equalsIgnoreCase(uri.getScheme())) return false;
        if (uri.getHost() == null || !host.equals(uri.getHost().toLowerCase(Locale.ROOT))) return false;
        if (uri.getRawUserInfo() != null) return false;
        return uri.getPort() == -1 || uri.getPort() == 443;
    }

    /**
     * Turns what a page passed in (a full URL, or a path such as {@code /audio/5/download})
     * into a full site URL. Returns null when the result is not on the site.
     */
    public String resolve(String urlOrPath) {
        if (urlOrPath == null) return null;
        String value = urlOrPath.trim();
        if (value.isEmpty()) return null;
        if (value.startsWith("/") && !value.startsWith("//")) {
            value = origin + value;
        }
        return isAppUrl(value) ? value : null;
    }

    /** The part after the origin: path, query and fragment. Always starts with "/". */
    public String pathAndQuery(String appUrl) {
        URI uri = parse(appUrl);
        if (uri == null) return "/";
        StringBuilder out = new StringBuilder();
        String path = uri.getRawPath();
        out.append(path == null || path.isEmpty() ? "/" : path);
        if (uri.getRawQuery() != null) out.append('?').append(uri.getRawQuery());
        if (uri.getRawFragment() != null) out.append('#').append(uri.getRawFragment());
        return out.toString();
    }

    /** The browser leg of sign-in must never be shown inside the WebView. */
    public boolean isSignInUrl(String url) {
        URI uri = parse(url);
        return uri != null && uri.getRawPath() != null && uri.getRawPath().startsWith(SIGN_IN_PREFIX);
    }

    public String signInStartUrl(String challenge, String locale) {
        return origin + SIGN_IN_PREFIX + "start?challenge=" + encode(challenge) + "&locale=" + normalizeLocale(locale);
    }

    public String signInCompleteUrl(String code, String verifier) {
        return origin + SIGN_IN_PREFIX + "complete?code=" + encode(code) + "&verifier=" + encode(verifier);
    }

    /** The site has two languages; anything else falls back to English. */
    public static String normalizeLocale(String locale) {
        return locale != null && locale.toLowerCase(Locale.ROOT).startsWith("ar") ? "ar" : "en";
    }

    private static URI parse(String url) {
        if (url == null) return null;
        // Characters that browsers and java.net.URI disagree about are refused outright.
        for (int i = 0; i < url.length(); i++) {
            char c = url.charAt(i);
            if (c == '\\' || c <= 0x20 || c == 0x7f) return null;
        }
        try {
            return new URI(url);
        } catch (Exception e) {
            return null;
        }
    }

    private static String encode(String value) {
        try {
            return URLEncoder.encode(value == null ? "" : value, StandardCharsets.UTF_8.name());
        } catch (java.io.UnsupportedEncodingException e) {
            throw new IllegalStateException(e);
        }
    }
}
