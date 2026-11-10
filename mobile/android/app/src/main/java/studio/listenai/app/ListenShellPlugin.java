package studio.listenai.app;

import android.Manifest;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.content.res.Configuration;
import android.net.Uri;
import android.os.Build;
import android.os.SystemClock;
import android.provider.Settings;
import android.view.View;
import androidx.annotation.Nullable;
import androidx.appcompat.app.AlertDialog;
import androidx.core.app.ActivityCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.getcapacitor.annotation.PermissionCallback;
import java.io.File;
import java.util.Locale;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * "ListenShell": everything the site's pages can ask the app to do.
 * The contract (methods, payloads, events) is written up in mobile/BRIDGE.md; keep the two in step.
 *
 * Events sent to the page:
 *   mediaAction { action: 'play'|'pause'|'stop'|'seek'|'forward'|'rewind', position?: seconds }
 *   backPressed {}
 */
@CapacitorPlugin(
    name = "ListenShell",
    permissions = {
        @Permission(alias = ListenShellPlugin.ALIAS_MIC, strings = { Manifest.permission.RECORD_AUDIO }),
        @Permission(alias = ListenShellPlugin.ALIAS_NOTIFICATIONS, strings = { "android.permission.POST_NOTIFICATIONS" }),
        @Permission(alias = ListenShellPlugin.ALIAS_STORAGE, strings = { Manifest.permission.WRITE_EXTERNAL_STORAGE })
    }
)
public class ListenShellPlugin extends Plugin implements MediaPlaybackService.ActionSink {

    static final String ALIAS_MIC = "microphone";
    static final String ALIAS_NOTIFICATIONS = "notifications";
    static final String ALIAS_STORAGE = "storage";

    /** PERMISSION_NOTIFICATIONS; spelled out because the constant only exists from Android 13. */
    private static final String PERMISSION_NOTIFICATIONS = "android.permission.POST_NOTIFICATIONS";

    private static final String PREFS = "listen_shell";
    private static final String KEY_MIC_BLOCKED = "mic_blocked";
    /** A permission answer that comes back faster than this was given by the system, not by a person. */
    private static final long AUTO_DENY_MS = 450;

    /** Downloads and file writes; one at a time so two saves cannot fight over one name. */
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private long permissionAskedAt;

    @Override
    public void load() {
        MediaPlaybackService.setSink(this);
    }

    @Override
    protected void handleOnDestroy() {
        MediaPlaybackService.setSink(null);
        MediaPlaybackService.stop(getContext());
        io.shutdown();
    }

    private MainActivity main() {
        return (MainActivity) getActivity();
    }

    private AppUrls urls() {
        return main().urls();
    }

    // ------------------------------------------------------------------ 1. getInfo

    @PluginMethod
    public void getInfo(PluginCall call) {
        Context context = getContext();
        JSObject info = new JSObject();
        info.put("version", BuildConfig.VERSION_NAME);
        info.put("build", BuildConfig.VERSION_CODE);
        info.put("platform", "android");
        info.put("pushAvailable", SafePushNotificationsPlugin.isPushConfigured(context));
        info.put("notificationsAllowed", notificationsAllowed());
        info.put("micPermission", micState());
        call.resolve(info);
    }

    private boolean notificationsAllowed() {
        return NotificationManagerCompat.from(getContext()).areNotificationsEnabled();
    }

    private boolean micGranted() {
        return ContextCompat.checkSelfPermission(getContext(), Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED;
    }

    /** 'granted', 'denied' (the system no longer shows its prompt: only Settings can change it) or 'prompt'. */
    private String micState() {
        if (micGranted()) {
            prefs().edit().remove(KEY_MIC_BLOCKED).apply();
            return "granted";
        }
        return prefs().getBoolean(KEY_MIC_BLOCKED, false) ? "denied" : "prompt";
    }

    // ------------------------------------------------------------------ 2. media session

    @PluginMethod
    public void mediaUpdate(PluginCall call) {
        boolean playing = Boolean.TRUE.equals(call.getBoolean("playing", false));
        String title = call.getString("title", "");
        String subtitle = call.getString("subtitle", "");
        double position = call.getData().optDouble("position", 0);
        double duration = call.getData().optDouble("duration", 0);
        boolean active = MediaPlaybackService.update(getContext(), playing, title, subtitle, position, duration);
        JSObject result = new JSObject();
        result.put("active", active);
        call.resolve(result);
    }

    @PluginMethod
    public void mediaStop(PluginCall call) {
        MediaPlaybackService.stop(getContext());
        call.resolve();
    }

    /** From the notification, lock screen, headset or Bluetooth: pass it on to the page. */
    @Override
    public void onMediaAction(String action, double positionSeconds) {
        JSObject data = new JSObject();
        data.put("action", action);
        if ("seek".equals(action) && positionSeconds >= 0) data.put("position", positionSeconds);
        notifyListeners("mediaAction", data);
    }

    // ------------------------------------------------------------------ 3. saveAudio

    @PluginMethod
    public void saveAudio(PluginCall call) {
        MediaRequest request = MediaRequest.from(call, urls());
        if (request == null) return; // already rejected

        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q && !hasLegacyStoragePermission()) {
            // Android 9 and older: the Downloads folder needs the storage permission.
            getActivity().runOnUiThread(() -> requestPermissionForAlias(ALIAS_STORAGE, call, "storagePermissionCallback"));
            return;
        }
        runSave(call, request);
    }

    @PermissionCallback
    private void storagePermissionCallback(PluginCall call) {
        if (!hasLegacyStoragePermission()) {
            call.reject("Saving needs the storage permission", "permission_denied");
            return;
        }
        MediaRequest request = MediaRequest.from(call, urls());
        if (request != null) runSave(call, request);
    }

    private boolean hasLegacyStoragePermission() {
        return ContextCompat.checkSelfPermission(getContext(), Manifest.permission.WRITE_EXTERNAL_STORAGE) == PackageManager.PERMISSION_GRANTED;
    }

    private void runSave(PluginCall call, MediaRequest request) {
        Context context = getContext().getApplicationContext();
        AppUrls urls = urls();
        io.execute(() -> {
            try {
                AudioFiles.Saved saved = AudioFiles.saveToDownloads(context, urls, request.source, request.fileName, request.mimeType);
                JSObject result = new JSObject();
                result.put("uri", saved.uri.toString());
                result.put("displayName", saved.displayName);
                call.resolve(result);
            } catch (AudioFiles.SaveException e) {
                call.reject(e.getMessage(), e.code);
            } catch (RuntimeException e) {
                call.reject(String.valueOf(e.getMessage()), "io_error");
            }
        });
    }

    // ------------------------------------------------------------------ 4. shareAudio

    @PluginMethod
    public void shareAudio(PluginCall call) {
        MediaRequest request = MediaRequest.from(call, urls());
        if (request == null) return;
        String title = call.getString("title", "");
        Context context = getContext().getApplicationContext();
        AppUrls urls = urls();
        io.execute(() -> {
            try {
                File file = AudioFiles.saveToShareCache(context, urls, request.source, request.fileName);
                Uri uri = FileProvider.getUriForFile(context, context.getPackageName() + ".fileprovider", file);

                Intent send = new Intent(Intent.ACTION_SEND);
                send.setType(request.mimeType);
                send.putExtra(Intent.EXTRA_STREAM, uri);
                if (title != null && !title.isEmpty()) {
                    send.putExtra(Intent.EXTRA_SUBJECT, title);
                    send.putExtra(Intent.EXTRA_TITLE, title);
                }
                // The receiving app gets read access to this one file only.
                send.setClipData(ClipData.newUri(context.getContentResolver(), request.fileName, uri));
                send.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);

                getActivity().runOnUiThread(() -> {
                    try {
                        Intent chooser = Intent.createChooser(send, title == null || title.isEmpty() ? null : title);
                        getActivity().startActivity(chooser);
                        call.resolve();
                    } catch (ActivityNotFoundException e) {
                        call.reject("Nothing on this phone can receive the file", "no_target");
                    }
                });
            } catch (AudioFiles.SaveException e) {
                call.reject(e.getMessage(), e.code);
            } catch (RuntimeException e) {
                call.reject(String.valueOf(e.getMessage()), "io_error");
            }
        });
    }

    /** The arguments saveAudio and shareAudio have in common, checked once. */
    private static final class MediaRequest {
        final AudioFiles.Source source;
        final String fileName;
        final String mimeType;

        private MediaRequest(AudioFiles.Source source, String fileName, String mimeType) {
            this.source = source;
            this.fileName = fileName;
            this.mimeType = mimeType;
        }

        /** Rejects the call and returns null when the arguments are not acceptable. */
        @Nullable
        static MediaRequest from(PluginCall call, AppUrls urls) {
            String url = call.getString("url");
            String base64 = call.getString("base64");
            String mimeType = FileNames.cleanMimeType(call.getString("mimeType"));
            boolean hasUrl = url != null && !url.isEmpty();
            boolean hasData = base64 != null && !base64.isEmpty();
            if (hasUrl == hasData) {
                call.reject("Pass exactly one of url or base64", "bad_request");
                return null;
            }
            if (!FileNames.isMediaType(mimeType)) {
                call.reject("mimeType must be an audio/* or video/* type", "bad_request");
                return null;
            }
            String resolved = null;
            if (hasUrl) {
                resolved = urls.resolve(url);
                if (resolved == null) {
                    call.reject("Only https URLs on " + urls.host() + " can be downloaded", "url_not_allowed");
                    return null;
                }
            }
            String fileName = FileNames.sanitize(call.getString("fileName"), mimeType);
            return new MediaRequest(new AudioFiles.Source(resolved, hasUrl ? null : base64), fileName, mimeType);
        }
    }

    // ------------------------------------------------------------------ 5. ensureMic

    @PluginMethod
    public void ensureMic(PluginCall call) {
        if (micGranted()) {
            resolveGranted(call, true);
            return;
        }
        String lang = AppUrls.normalizeLocale(call.getString("lang"));
        getActivity().runOnUiThread(() -> {
            if (ActivityCompat.shouldShowRequestPermissionRationale(getActivity(), Manifest.permission.RECORD_AUDIO)) {
                // Asked before and refused: explain first, then show the system prompt.
                showDialog(
                    lang,
                    R.string.mic_rationale_title,
                    R.string.mic_rationale_message,
                    R.string.action_continue,
                    () -> askSystem(ALIAS_MIC, call, "micPermissionCallback"),
                    () -> resolveGranted(call, false)
                );
            } else {
                askSystem(ALIAS_MIC, call, "micPermissionCallback");
            }
        });
    }

    @PermissionCallback
    private void micPermissionCallback(PluginCall call) {
        if (micGranted()) {
            prefs().edit().remove(KEY_MIC_BLOCKED).apply();
            resolveGranted(call, true);
            return;
        }
        boolean blocked = answeredBySystem(Manifest.permission.RECORD_AUDIO);
        prefs().edit().putBoolean(KEY_MIC_BLOCKED, blocked).apply();
        if (!blocked) {
            resolveGranted(call, false);
            return;
        }
        // The system did not even show its prompt: only the app's settings page can turn it on.
        String lang = AppUrls.normalizeLocale(call.getString("lang"));
        getActivity().runOnUiThread(() ->
            showDialog(
                lang,
                R.string.mic_settings_title,
                R.string.mic_settings_message,
                R.string.action_open_settings,
                () -> {
                    openAppSettings(false);
                    resolveGranted(call, false);
                },
                () -> resolveGranted(call, false)
            )
        );
    }

    // ------------------------------------------------------------------ 6. ensureNotifications

    @PluginMethod
    public void ensureNotifications(PluginCall call) {
        if (notificationsAllowed()) {
            resolveGranted(call, true);
            return;
        }
        String lang = AppUrls.normalizeLocale(call.getString("lang"));
        boolean runtimePermission = Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU;
        boolean permissionGranted = !runtimePermission
            || ContextCompat.checkSelfPermission(getContext(), PERMISSION_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED;
        getActivity().runOnUiThread(() -> {
            if (permissionGranted) {
                // Android 12 and older (or the permission is there but notifications were switched
                // off in Settings): there is no prompt to show, only the settings page.
                offerNotificationSettings(call, lang);
            } else if (ActivityCompat.shouldShowRequestPermissionRationale(getActivity(), PERMISSION_NOTIFICATIONS)) {
                showDialog(
                    lang,
                    R.string.notif_rationale_title,
                    R.string.notif_rationale_message,
                    R.string.action_continue,
                    () -> askSystem(ALIAS_NOTIFICATIONS, call, "notificationsPermissionCallback"),
                    () -> resolveGranted(call, false)
                );
            } else {
                askSystem(ALIAS_NOTIFICATIONS, call, "notificationsPermissionCallback");
            }
        });
    }

    @PermissionCallback
    private void notificationsPermissionCallback(PluginCall call) {
        if (notificationsAllowed()) {
            resolveGranted(call, true);
            return;
        }
        if (!answeredBySystem(PERMISSION_NOTIFICATIONS)) {
            resolveGranted(call, false);
            return;
        }
        String lang = AppUrls.normalizeLocale(call.getString("lang"));
        getActivity().runOnUiThread(() -> offerNotificationSettings(call, lang));
    }

    private void offerNotificationSettings(PluginCall call, String lang) {
        showDialog(
            lang,
            R.string.notif_settings_title,
            R.string.notif_settings_message,
            R.string.action_open_settings,
            () -> {
                openAppSettings(true);
                resolveGranted(call, false);
            },
            () -> resolveGranted(call, false)
        );
    }

    // ------------------------------------------------------------------ permission helpers

    private void askSystem(String alias, PluginCall call, String callbackName) {
        permissionAskedAt = SystemClock.elapsedRealtime();
        requestPermissionForAlias(alias, call, callbackName);
    }

    /**
     * After a refusal: true when Android answered by itself without showing its prompt
     * (the person chose "don't ask again", or refused twice). It answers within a few
     * milliseconds in that case, and offers no rationale.
     */
    private boolean answeredBySystem(String permission) {
        boolean instant = SystemClock.elapsedRealtime() - permissionAskedAt < AUTO_DENY_MS;
        return instant && !ActivityCompat.shouldShowRequestPermissionRationale(getActivity(), permission);
    }

    private void resolveGranted(PluginCall call, boolean granted) {
        JSObject result = new JSObject();
        result.put("granted", granted);
        call.resolve(result);
    }

    /** A two-button dialog in the page's language (not necessarily the phone's). Must run on the UI thread. */
    private void showDialog(String lang, int titleRes, int messageRes, int positiveRes, Runnable onPositive, Runnable onDismiss) {
        if (getActivity().isFinishing() || getActivity().isDestroyed()) {
            onDismiss.run();
            return;
        }
        Configuration configuration = new Configuration(getActivity().getResources().getConfiguration());
        configuration.setLocale(Locale.forLanguageTag(lang));
        Context localized = getActivity().createConfigurationContext(configuration);

        final boolean[] accepted = { false };
        AlertDialog dialog = new AlertDialog.Builder(getActivity())
            .setTitle(localized.getString(titleRes))
            .setMessage(localized.getString(messageRes))
            .setPositiveButton(localized.getString(positiveRes), (d, which) -> accepted[0] = true)
            .setNegativeButton(localized.getString(R.string.action_not_now), null)
            .create();
        dialog.setOnDismissListener(d -> {
            if (accepted[0]) onPositive.run();
            else onDismiss.run();
        });
        dialog.show();
        if (dialog.getWindow() != null) {
            dialog
                .getWindow()
                .getDecorView()
                .setLayoutDirection("ar".equals(lang) ? View.LAYOUT_DIRECTION_RTL : View.LAYOUT_DIRECTION_LTR);
        }
    }

    private void openAppSettings(boolean notifications) {
        Intent intent;
        if (notifications && Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            intent = new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, getContext().getPackageName());
        } else {
            intent = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", getContext().getPackageName(), null));
        }
        try {
            getActivity().startActivity(intent);
        } catch (ActivityNotFoundException e) {
            try {
                getActivity().startActivity(new Intent(Settings.ACTION_SETTINGS));
            } catch (ActivityNotFoundException ignored) {
                // No settings app: nothing more to offer.
            }
        }
    }

    private SharedPreferences prefs() {
        return getContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    // ------------------------------------------------------------------ 7. signIn

    @PluginMethod
    public void signIn(PluginCall call) {
        String locale = call.getString("locale", "en");
        getActivity().runOnUiThread(() -> {
            try {
                SignIn.start(getActivity(), urls(), locale, main().isDark());
                call.resolve();
            } catch (ActivityNotFoundException e) {
                call.reject("No browser is available to sign in", "no_browser");
            }
        });
    }

    // ------------------------------------------------------------------ 8. shell controls

    @PluginMethod
    public void setPullToRefresh(PluginCall call) {
        boolean enabled = Boolean.TRUE.equals(call.getBoolean("enabled", false));
        getActivity().runOnUiThread(() -> {
            main().setPullToRefresh(enabled);
            call.resolve();
        });
    }

    @PluginMethod
    public void setBackHandler(PluginCall call) {
        boolean enabled = Boolean.TRUE.equals(call.getBoolean("enabled", false));
        getActivity().runOnUiThread(() -> {
            main().setBackHandler(enabled);
            call.resolve();
        });
    }

    /** Called by the activity on a back press while a page has asked to handle it. */
    boolean emitBackPressed() {
        if (!hasListeners("backPressed")) return false; // nobody is listening: let the default happen
        notifyListeners("backPressed", new JSObject());
        return true;
    }

    @PluginMethod
    public void minimize(PluginCall call) {
        getActivity().runOnUiThread(() -> {
            getActivity().moveTaskToBack(true);
            call.resolve();
        });
    }

    @PluginMethod
    public void openExternal(PluginCall call) {
        String url = call.getString("url", "");
        Uri uri = url == null ? null : Uri.parse(url.trim());
        String scheme = uri == null || uri.getScheme() == null ? "" : uri.getScheme().toLowerCase(Locale.ROOT);
        if (!(scheme.equals("https") || scheme.equals("http") || scheme.equals("mailto") || scheme.equals("tel"))) {
            call.reject("Only https, http, mailto and tel links can be opened", "bad_request");
            return;
        }
        getActivity().runOnUiThread(() -> {
            if (ExternalLinks.open(getActivity(), uri)) call.resolve();
            else call.reject("Nothing on this phone can open that link", "no_target");
        });
    }

    @PluginMethod
    public void setTheme(PluginCall call) {
        boolean dark = Boolean.TRUE.equals(call.getBoolean("dark", false));
        getActivity().runOnUiThread(() -> {
            main().setSiteTheme(dark);
            call.resolve();
        });
    }
}
