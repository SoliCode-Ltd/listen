package studio.listenai.app;

import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.net.Uri;
import java.net.URISyntaxException;
import java.util.Arrays;
import java.util.List;
import java.util.Locale;

/**
 * Opens links that do not belong in the app's own WebView: other websites (system browser),
 * mailto:, tel:, messaging links, and intent: links. Only activities that declare themselves
 * openable from a browser (CATEGORY_BROWSABLE) can be reached this way.
 */
final class ExternalLinks {

    /** Schemes that must never be handed to another app. */
    private static final List<String> BLOCKED = Arrays.asList("file", "content", "javascript", "data", "blob", "about", "chrome");

    private ExternalLinks() {}

    /** @return true when something was opened. */
    static boolean open(Activity activity, Uri uri) {
        if (uri == null || uri.getScheme() == null) return false;
        String scheme = uri.getScheme().toLowerCase(Locale.ROOT);
        if (BLOCKED.contains(scheme)) return false;

        if ("intent".equals(scheme)) {
            return openIntentUri(activity, uri.toString());
        }
        Intent intent = new Intent(Intent.ACTION_VIEW, uri);
        intent.addCategory(Intent.CATEGORY_BROWSABLE);
        return start(activity, intent);
    }

    private static boolean openIntentUri(Activity activity, String intentUri) {
        Intent intent;
        try {
            intent = Intent.parseUri(intentUri, Intent.URI_INTENT_SCHEME);
        } catch (URISyntaxException e) {
            return false;
        }
        // A web page must not be able to pick an exact component or smuggle a second intent.
        intent.addCategory(Intent.CATEGORY_BROWSABLE);
        intent.setComponent(null);
        intent.setSelector(null);
        intent.setFlags(0);
        if (intent.getData() != null && intent.getData().getScheme() != null
            && BLOCKED.contains(intent.getData().getScheme().toLowerCase(Locale.ROOT))) {
            return false;
        }
        if (start(activity, intent)) return true;

        String fallback = intent.getStringExtra("browser_fallback_url");
        if (fallback != null) {
            Uri fallbackUri = Uri.parse(fallback);
            if ("https".equalsIgnoreCase(fallbackUri.getScheme()) || "http".equalsIgnoreCase(fallbackUri.getScheme())) {
                Intent view = new Intent(Intent.ACTION_VIEW, fallbackUri);
                view.addCategory(Intent.CATEGORY_BROWSABLE);
                return start(activity, view);
            }
        }
        return false;
    }

    private static boolean start(Activity activity, Intent intent) {
        try {
            activity.startActivity(intent);
            return true;
        } catch (ActivityNotFoundException | SecurityException e) {
            return false;
        }
    }
}
