package studio.listenai.app;

import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.res.Configuration;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.webkit.WebBackForwardList;
import android.webkit.WebView;
import android.widget.Toast;
import androidx.activity.EdgeToEdge;
import androidx.activity.OnBackPressedCallback;
import androidx.activity.SystemBarStyle;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import androidx.core.content.ContextCompat;
import androidx.core.graphics.Insets;
import androidx.core.splashscreen.SplashScreen;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;
import com.capacitorjs.plugins.pushnotifications.PushNotificationsPlugin;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.CapConfig;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginHandle;
import com.getcapacitor.PluginLoadException;
import com.getcapacitor.PluginManager;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

/**
 * The one screen of the app: a WebView showing the live site, plus the native shell around it
 * (system bars, pull-to-refresh, back button, offline page, deep links, sign-in return).
 *
 * The site address comes from the build (BuildConfig.APP_URL, see app/build.gradle), not from
 * capacitor.config.json, so one code base builds both the live and a staging app.
 */
public class MainActivity extends BridgeActivity {

    static final int COLOR_LIGHT = 0xFFFFFFFF;
    static final int COLOR_DARK = 0xFF0B0B0F;
    private static final int COLOR_BRAND = 0xFF7659FA;

    private static final String ERROR_PAGE = "offline.html";
    private static final String PREFS = "listen_shell";
    private static final String KEY_SITE_THEME = "site_theme";
    /** The opening screen never stays longer than this, whatever the network does. */
    private static final long SPLASH_MAX_MS = 6000;
    /** Nor shorter than this, so the logo is seen to move and does not just blink. */
    private static final long SPLASH_MIN_MS = 700;
    private static final long SPLASH_FADE_MS = 240;
    private static final long REFRESH_SPINNER_MAX_MS = 12_000;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final ExecutorService downloads = Executors.newSingleThreadExecutor();
    private AppUrls urls;
    private WebView webView;
    private View root;
    private SwipeRefreshLayout swipe;
    private boolean ready;

    private long createdAt;
    private boolean firstPageVisible;
    /** The moving logo over the first page while it loads (null once it has gone). */
    @Nullable
    private LaunchWaveView launchView;
    private boolean pullToRefresh;
    private boolean backHandler;
    private boolean showingError;
    @Nullable
    private String lastFailedUrl;
    @Nullable
    private ConnectivityManager.NetworkCallback networkCallback;

    // ------------------------------------------------------------------ start-up

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        // The system's launch screen shows the still logo on white for the first instant; the
        // moving logo (LaunchWaveView) takes over from the first frame and stays until the page is there.
        SplashScreen.installSplashScreen(this);
        createdAt = SystemClock.elapsedRealtime();

        urls = new AppUrls(BuildConfig.APP_URL);
        // Opened from a link or a notification: start on that page instead of the default one.
        String launchUrl = savedInstanceState == null ? resolveIntentUrl(getIntent()) : null;
        config = buildCapacitorConfig(launchUrl != null ? urls.pathAndQuery(launchUrl) : AppUrls.START_PATH);

        super.onCreate(savedInstanceState);
        if (bridge == null) return; // The phone has no WebView at all; Capacitor shows its notice.

        webView = bridge.getWebView();
        root = findViewById(R.id.listen_root);
        swipe = findViewById(R.id.listen_swipe);
        ListenRuntime.userAgent = webView.getSettings().getUserAgentString();

        // The sign-in session lives in cookies: keep them, first-party only.
        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        cookies.setAcceptThirdPartyCookies(webView, false);

        webView.getSettings().setSupportMultipleWindows(false); // target=_blank / window.open navigate here and are then routed
        bridge.setWebViewClient(new ListenWebViewClient(bridge, this));
        webView.setWebChromeClient(new ListenWebChromeClient(bridge, urls));
        webView.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) ->
            onDownloadRequested(url, contentDisposition, mimeType)
        );

        if (savedInstanceState == null) showLaunchView();
        setUpInsets();
        setUpPullToRefresh();
        setUpBackButton();
        setUpAutoRetry();
        applyTheme(isDark());
        // Capacitor's SystemBars plugin sets the bar icons once more right after start-up; go last.
        handler.post(() -> applyTheme(isDark()));

        ready = true;
    }

    /**
     * Capacitor's settings, built in code so the site address and version come from the build.
     * (mobile/capacitor.config.json carries the same values for the Capacitor command line.)
     */
    private CapConfig buildCapacitorConfig(String startPath) {
        JSONObject plugins = new JSONObject();
        try {
            // The back button is handled here (see setUpBackButton), not by the App plugin.
            plugins.put("App", new JSONObject().put("disableBackButtonHandler", true));
            // System-bar and keyboard insets are handled here (see setUpInsets).
            plugins.put("SystemBars", new JSONObject().put("insetsHandling", "disable"));
            // A push that arrives while the app is open is still shown as a notification.
            plugins.put("PushNotifications", new JSONObject().put("presentationOptions", new JSONArray().put("alert").put("sound")));
        } catch (JSONException e) {
            throw new IllegalStateException(e);
        }
        return new CapConfig.Builder(this)
            .setServerUrl(BuildConfig.APP_URL)
            .setStartPath(startPath)
            .setErrorPath(ERROR_PAGE)
            .setAndroidScheme("https")
            .setHostname("localhost")
            .setAppendedUserAgentString(ListenRuntime.userAgentToken())
            .setWebContentsDebuggingEnabled(BuildConfig.DEBUG)
            .setLoggingEnabled(BuildConfig.DEBUG)
            .setInitialFocus(true)
            .setPluginsConfiguration(plugins)
            .create();
    }

    /**
     * Registers the plugins: the ones Capacitor found in package.json, with the push plugin
     * swapped for the safe version, plus the app's own ListenShell.
     */
    @Override
    protected void load() {
        List<Class<? extends Plugin>> plugins = new ArrayList<>();
        try {
            for (Class<? extends Plugin> plugin : new PluginManager(getAssets()).loadPluginClasses()) {
                if (plugin != PushNotificationsPlugin.class) plugins.add(plugin);
            }
        } catch (PluginLoadException e) {
            // Without the generated plugin list only the two below are available.
        }
        plugins.add(SafePushNotificationsPlugin.class);
        plugins.add(ListenShellPlugin.class);
        bridgeBuilder.setPlugins(plugins);
        super.load();
    }

    AppUrls urls() {
        return urls;
    }

    @Nullable
    private ListenShellPlugin shell() {
        PluginHandle handle = bridge == null ? null : bridge.getPlugin("ListenShell");
        return handle == null ? null : (ListenShellPlugin) handle.getInstance();
    }

    // ------------------------------------------------------------------ links into the app

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent); // plugins: appUrlOpen, pushNotificationActionPerformed
        if (!ready) return; // start-up: the launch link was already used as the first page
        String url = resolveIntentUrl(intent);
        if (url != null) webView.loadUrl(url);
    }

    /**
     * What an incoming intent asks the WebView to show, or null:
     *   - studio.listenai.app://signin?code=..  : the address that completes a waiting sign-in
     *   - https://{site}/...                      : an App Link (the manifest limits which paths)
     *   - a tapped push notification with data.url : that URL, if it is https on the site
     */
    @Nullable
    private String resolveIntentUrl(@Nullable Intent intent) {
        if (intent == null) return null;
        // Reopened from the recents list: the old link must not be replayed.
        if ((intent.getFlags() & Intent.FLAG_ACTIVITY_LAUNCHED_FROM_HISTORY) != 0) return null;

        Uri data = intent.getData();
        if (Intent.ACTION_VIEW.equals(intent.getAction()) && data != null) {
            if (BuildConfig.APP_SCHEME.equalsIgnoreCase(data.getScheme())) {
                return signInReturnUrl(data);
            }
            String url = data.toString();
            // The browser leg of sign-in must stay in the browser even if a browser hands it back.
            return urls.isAppUrl(url) && !urls.isSignInUrl(url) ? url : null;
        }

        Bundle extras = intent.getExtras();
        if (extras != null && extras.containsKey("google.message_id")) {
            String url = extras.getString("url");
            return url != null && urls.isAppUrl(url) && !urls.isSignInUrl(url) ? url : null;
        }
        return null;
    }

    @Nullable
    private String signInReturnUrl(Uri link) {
        if (!link.isHierarchical() || !"signin".equals(link.getHost())) return null;
        return SignIn.completeUrl(this, urls, link.getQueryParameter("code"));
    }

    /** The return link was followed inside the WebView itself (rare): treat it the same way. */
    void handleAppSchemeLink(Uri link) {
        String url = signInReturnUrl(link);
        if (url != null) webView.loadUrl(url);
    }

    // ------------------------------------------------------------------ page events (from ListenWebViewClient)

    boolean isLocalUrl(Uri uri) {
        return "https".equals(uri.getScheme()) && "localhost".equals(uri.getHost());
    }

    private boolean isErrorPage(@Nullable String url) {
        return url != null && url.equals(bridge.getErrorUrl());
    }

    void onPageStarted(String url) {
        showingError = isErrorPage(url);
        // Everything a page switched on belongs to that page.
        pullToRefresh = false;
        backHandler = false;
        // A new document means the old page's audio element is gone.
        MediaPlaybackService.stop(this);
    }

    void onPageFinished(String url) {
        swipe.setRefreshing(false);
        CookieManager.getInstance().flush();
        if (isErrorPage(url)) {
            String siteTheme = prefs().getString(KEY_SITE_THEME, null);
            if (siteTheme != null) {
                webView.evaluateJavascript("window.__listenTheme&&window.__listenTheme(" + "dark".equals(siteTheme) + ")", null);
            }
        }
    }

    void onPageVisible() {
        firstPageVisible = true;
        swipe.setRefreshing(false);
        long shown = SystemClock.elapsedRealtime() - createdAt;
        handler.postDelayed(this::hideLaunchView, Math.max(0, SPLASH_MIN_MS - shown));
    }

    // ------------------------------------------------------------------ opening screen

    /** Covers the whole window, bars included, with the moving logo on white. */
    private void showLaunchView() {
        launchView = new LaunchWaveView(this);
        addContentView(launchView, new android.view.ViewGroup.LayoutParams(
            android.view.ViewGroup.LayoutParams.MATCH_PARENT, android.view.ViewGroup.LayoutParams.MATCH_PARENT));
        handler.postDelayed(this::hideLaunchView, SPLASH_MAX_MS);
    }

    /** Fades the opening screen away over the page (or over the "can't connect" page). */
    private void hideLaunchView() {
        final LaunchWaveView view = launchView;
        if (view == null) return;
        launchView = null;
        // The bars take the page's own colours again as the white leaves.
        if (ready) applyTheme(isDark());
        view.animate().alpha(0f).setDuration(SPLASH_FADE_MS).withEndAction(() -> {
            if (view.getParent() instanceof android.view.ViewGroup) ((android.view.ViewGroup) view.getParent()).removeView(view);
        }).start();
    }

    void onMainFrameError(String failedUrl) {
        if (urls.isAppUrl(failedUrl)) lastFailedUrl = failedUrl;
    }

    void onRendererGone() {
        MediaPlaybackService.stop(this);
        if (!isFinishing() && !isDestroyed()) recreate();
    }

    // ------------------------------------------------------------------ offline page: retry

    /** Reloads the page that failed (or the start page). Called by the offline page and when the network returns. */
    void retry() {
        if (!showingError) return;
        showingError = false;
        String target = lastFailedUrl != null ? lastFailedUrl : urls.startUrl();
        WebBackForwardList history = webView.copyBackForwardList();
        int current = history.getCurrentIndex();
        if (current > 0 && target.equals(history.getItemAtIndex(current - 1).getUrl())) {
            // The failed page is the previous history entry: going back retries it and
            // leaves no "can't connect" page behind in the history.
            webView.goBack();
        } else {
            webView.loadUrl(target);
        }
    }

    private void setUpAutoRetry() {
        ConnectivityManager manager = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
        if (manager == null) return;
        networkCallback = new ConnectivityManager.NetworkCallback() {
            @Override
            public void onAvailable(@NonNull Network network) {
                // Give the connection a moment to become usable.
                handler.postDelayed(MainActivity.this::retry, 800);
            }
        };
        try {
            manager.registerDefaultNetworkCallback(networkCallback);
        } catch (RuntimeException e) {
            networkCallback = null;
        }
    }

    // ------------------------------------------------------------------ system bars, keyboard, theme

    /**
     * The window is edge-to-edge (Android 15+ enforces it). The site does not know about
     * system bars, so the frame around the WebView is padded by the status bar, navigation /
     * gesture bar, display cut-out and the keyboard: content never sits under any of them and
     * the keyboard never covers an input.
     */
    private void setUpInsets() {
        ViewCompat.setOnApplyWindowInsetsListener(root, (view, insets) -> {
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars() | WindowInsetsCompat.Type.displayCutout());
            Insets keyboard = insets.getInsets(WindowInsetsCompat.Type.ime());
            view.setPadding(bars.left, bars.top, bars.right, Math.max(bars.bottom, keyboard.bottom));
            return WindowInsetsCompat.CONSUMED;
        });
        ViewCompat.requestApplyInsets(root);
    }

    /** Dark or light: what the site last told us, else the phone's setting. */
    boolean isDark() {
        String siteTheme = prefs().getString(KEY_SITE_THEME, null);
        if (siteTheme != null) return "dark".equals(siteTheme);
        int night = getResources().getConfiguration().uiMode & Configuration.UI_MODE_NIGHT_MASK;
        return night == Configuration.UI_MODE_NIGHT_YES;
    }

    /** From the page's theme toggle (ListenShell.setTheme). Remembered so the next start has no flash. */
    void setSiteTheme(boolean dark) {
        prefs().edit().putString(KEY_SITE_THEME, dark ? "dark" : "light").apply();
        applyTheme(dark);
    }

    private void applyTheme(boolean dark) {
        if (root == null) return;
        int background = dark ? COLOR_DARK : COLOR_LIGHT;
        // The bars are transparent; what shows behind them is this background.
        getWindow().setBackgroundDrawable(new ColorDrawable(background));
        root.setBackgroundColor(background);
        webView.setBackgroundColor(background);
        // Icon contrast: light icons on dark, dark icons on light. On Android 7 the navigation
        // bar cannot have dark icons, so it gets a dark scrim there instead. The opening screen
        // is white whatever the theme, so the icons are dark while it shows.
        SystemBarStyle style = dark && launchView == null
            ? SystemBarStyle.dark(Color.TRANSPARENT)
            : SystemBarStyle.light(Color.TRANSPARENT, Color.argb(0x80, 0x1b, 0x1b, 0x1b));
        EdgeToEdge.enable(this, style, style);
        swipe.setProgressBackgroundColorSchemeColor(dark ? 0xFF1C1C24 : COLOR_LIGHT);
        swipe.setColorSchemeColors(COLOR_BRAND);
    }

    @Override
    public void onConfigurationChanged(@NonNull Configuration newConfig) {
        super.onConfigurationChanged(newConfig); // Capacitor re-applies its own bar style here
        if (ready) applyTheme(isDark());
    }

    private SharedPreferences prefs() {
        return getSharedPreferences(PREFS, MODE_PRIVATE);
    }

    // ------------------------------------------------------------------ pull to refresh

    private void setUpPullToRefresh() {
        // Off unless the current page asked for it, and only when the page is scrolled to the top.
        swipe.setOnChildScrollUpCallback((parent, child) -> !pullToRefresh || webView.getScrollY() > 0);
        swipe.setOnRefreshListener(() -> {
            webView.reload();
            handler.postDelayed(() -> swipe.setRefreshing(false), REFRESH_SPINNER_MAX_MS);
        });
    }

    void setPullToRefresh(boolean enabled) {
        pullToRefresh = enabled;
    }

    // ------------------------------------------------------------------ back button

    void setBackHandler(boolean enabled) {
        backHandler = enabled;
    }

    private void setUpBackButton() {
        getOnBackPressedDispatcher().addCallback(
            this,
            new OnBackPressedCallback(true) {
                @Override
                public void handleOnBackPressed() {
                    onBack();
                }
            }
        );
    }

    private void onBack() {
        if (showingError) {
            leaveErrorPage();
            return;
        }
        if (backHandler) {
            // The page asked to decide (close a dialog, leave a step...).
            ListenShellPlugin shell = shell();
            if (shell != null && shell.emitBackPressed()) return;
        }
        if (webView.canGoBack()) {
            webView.goBack();
        } else {
            // First page: go to the home screen but keep the app (and any playback) alive.
            moveTaskToBack(true);
        }
    }

    /** Back from the "can't connect" page: skip the page that failed, or it would just fail again. */
    private void leaveErrorPage() {
        WebBackForwardList history = webView.copyBackForwardList();
        int current = history.getCurrentIndex();
        for (int i = current - 1; i >= 0; i--) {
            String url = history.getItemAtIndex(i).getUrl();
            if (!isErrorPage(url) && !url.equals(lastFailedUrl)) {
                webView.goBackOrForward(i - current);
                return;
            }
        }
        moveTaskToBack(true);
    }

    // ------------------------------------------------------------------ plain download links

    /**
     * A link the WebView cannot show (a file download). Pages should use ListenShell.saveAudio;
     * this is the safety net for ordinary download links on the site.
     */
    private void onDownloadRequested(String url, String contentDisposition, String mimeType) {
        String resolved = urls.resolve(url);
        if (resolved == null) {
            if (URLUtil.isNetworkUrl(url)) ExternalLinks.open(this, Uri.parse(url));
            return;
        }
        boolean needsPermission = Build.VERSION.SDK_INT < Build.VERSION_CODES.Q
            && ContextCompat.checkSelfPermission(this, android.Manifest.permission.WRITE_EXTERNAL_STORAGE)
                != android.content.pm.PackageManager.PERMISSION_GRANTED;
        if (needsPermission) {
            Toast.makeText(this, R.string.download_failed, Toast.LENGTH_LONG).show();
            return;
        }
        String type = FileNames.cleanMimeType(mimeType);
        String finalType = type.isEmpty() ? "application/octet-stream" : type;
        String fileName = FileNames.sanitize(URLUtil.guessFileName(resolved, contentDisposition, finalType), finalType);
        Context context = getApplicationContext();
        downloads.execute(() -> {
            boolean saved;
            try {
                AudioFiles.saveToDownloads(context, urls, new AudioFiles.Source(resolved, null), fileName, finalType);
                saved = true;
            } catch (AudioFiles.SaveException | RuntimeException e) {
                saved = false;
            }
            int message = saved ? R.string.download_saved : R.string.download_failed;
            handler.post(() -> Toast.makeText(context, message, Toast.LENGTH_LONG).show());
        });
    }

    // ------------------------------------------------------------------ lifecycle

    @Override
    public void onPause() {
        super.onPause();
        // Write the session cookies to disk now; the process may be killed in the background.
        CookieManager.getInstance().flush();
    }

    @Override
    public void onStop() {
        super.onStop();
        CookieManager.getInstance().flush();
    }

    @Override
    public void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        if (networkCallback != null) {
            ConnectivityManager manager = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
            if (manager != null) {
                try {
                    manager.unregisterNetworkCallback(networkCallback);
                } catch (RuntimeException ignored) {
                    // Already gone.
                }
            }
            networkCallback = null;
        }
        downloads.shutdown();
        // The WebView (and its audio) is destroyed with the activity.
        MediaPlaybackService.stop(this);
        super.onDestroy();
    }
}
