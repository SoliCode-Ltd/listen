package studio.listenai.app;

import android.graphics.Bitmap;
import android.net.Uri;
import android.webkit.RenderProcessGoneDetail;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;
import com.getcapacitor.Bridge;
import com.getcapacitor.BridgeWebViewClient;
import java.util.Locale;

/**
 * Decides, for every navigation, whether it stays in the app (the site, https) or leaves it
 * (everything else: other websites, mailto:, tel:, messaging and intent: links), and reports
 * page-load events to the activity.
 */
class ListenWebViewClient extends BridgeWebViewClient {

    /** The bundled "can't connect" page navigates here when "Try again" is pressed. */
    static final String RETRY_PATH = "/__retry";

    private final Bridge bridge;
    private final MainActivity activity;

    ListenWebViewClient(Bridge bridge, MainActivity activity) {
        super(bridge);
        this.bridge = bridge;
        this.activity = activity;
    }

    @Override
    public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
        Uri uri = request.getUrl();
        String scheme = uri.getScheme() == null ? "" : uri.getScheme().toLowerCase(Locale.ROOT);
        boolean web = scheme.equals("https") || scheme.equals("http");

        if (scheme.equals("data") || scheme.equals("blob") || scheme.equals("about")) {
            return false;
        }
        if (!request.isForMainFrame()) {
            // Frames inside a page: web content loads as usual; an app link is followed only
            // when the person actually tapped something.
            if (web) return false;
            if (request.hasGesture()) ExternalLinks.open(activity, uri);
            return true;
        }
        if (activity.isLocalUrl(uri)) {
            if (RETRY_PATH.equals(uri.getPath())) {
                activity.retry();
                return true;
            }
            return false;
        }
        if (BuildConfig.APP_SCHEME.equals(scheme)) {
            activity.handleAppSchemeLink(uri);
            return true;
        }
        if (activity.urls().isAppUrl(uri.toString())) {
            // The site itself: stays in the WebView (Capacitor's own checks still run).
            return bridge.launchIntent(uri);
        }
        // Anything else leaves the app: other hosts (and plain http) go to the browser,
        // mailto:/tel:/intent: and similar go to whichever app handles them.
        ExternalLinks.open(activity, uri);
        return true;
    }

    @Override
    public void onPageStarted(WebView view, String url, Bitmap favicon) {
        super.onPageStarted(view, url, favicon);
        activity.onPageStarted(url);
    }

    @Override
    public void onPageFinished(WebView view, String url) {
        super.onPageFinished(view, url);
        activity.onPageFinished(url);
    }

    @Override
    public void onPageCommitVisible(WebView view, String url) {
        super.onPageCommitVisible(view, url);
        activity.onPageVisible();
    }

    @Override
    public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
        if (request.isForMainFrame()) {
            activity.onMainFrameError(request.getUrl().toString());
        }
        // Capacitor shows the bundled error page (server.errorPath) for main-frame failures.
        super.onReceivedError(view, request, error);
    }

    /**
     * An HTTP error status (404, 419, 500, 503...) is still a page from the site, with the
     * site's own design. Capacitor's default would replace it with the offline page; this
     * deliberately does not call through.
     */
    @Override
    public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse errorResponse) {
        // Intentionally empty.
    }

    /** The WebView's renderer died (usually reclaimed for memory in the background). Start afresh instead of crashing. */
    @Override
    public boolean onRenderProcessGone(WebView view, RenderProcessGoneDetail detail) {
        activity.onRendererGone();
        return true;
    }
}
